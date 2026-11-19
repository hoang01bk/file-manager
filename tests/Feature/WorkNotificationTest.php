<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemUpdate;
use App\Models\WorkNotification;
use App\Models\WorkProject;
use App\Services\WorkNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class WorkNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $assignee;
    private User $member;
    private User $outsider;
    private WorkProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->user(); $this->assignee = $this->user(); $this->member = $this->user(); $this->outsider = $this->user();
        $this->project = WorkProject::create(['name' => 'Private project', 'owner_id' => $this->owner->id]);
        $this->project->members()->sync([$this->owner->id, $this->assignee->id, $this->member->id]);
    }

    public function test_creation_notifies_only_the_assignee_and_never_the_actor_or_unrelated_members(): void
    {
        $item = $this->item();
        $this->assertSame([$this->assignee->id], WorkNotification::pluck('recipient_id')->all());
        $this->actingAs($this->assignee)->getJson('/work-notifications')->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.data.0.item.id', $item->id)->assertJsonPath('notifications.data.0.kind', 'created')
            ->assertJsonPath('notifications.data.0.url', '/work?project='.$this->project->id.'&item='.$item->id);
        $this->actingAs($this->owner)->getJson('/work-notifications/count')->assertJsonPath('unread_count', 0);
        $this->item($this->owner->id);
        $this->item(null);
        $this->assertDatabaseCount('work_notifications', 1);
    }

    public function test_one_update_with_multiple_fields_creates_at_most_one_notice_per_recipient(): void
    {
        $item = $this->item();
        $this->actingAs($this->member)->patchJson('/work/items/'.$item->id, ['version' => 1, 'status' => 'doing', 'priority' => 'urgent', 'due_date' => '2030-01-20'])->assertOk();
        $activity = $item->updates()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->assignee->id], WorkNotification::where('work_item_update_id', $activity->id)->pluck('recipient_id')->all());
        app(WorkNotifications::class)->record($activity);
        $this->assertSame(2, WorkNotification::where('work_item_update_id', $activity->id)->count());
        $this->actingAs($this->assignee)->getJson('/work-notifications')->assertJsonPath('notifications.data.0.kind', 'updated')
            ->assertJsonPath('notifications.data.0.summary', 'Trạng thái: Mới → Đang làm · Ưu tiên: Bình thường → Khẩn cấp · Hạn hoàn thành: Chưa đặt hạn → 20/01/2030');
    }

    public function test_reassignment_notifies_creator_and_new_assignee_but_not_old_assignee_or_actor(): void
    {
        $item = $this->item();
        $this->actingAs($this->assignee)->patchJson('/work/items/'.$item->id, ['version' => 1, 'assignee_id' => $this->member->id])->assertOk();
        $update = $item->updates()->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->member->id], WorkNotification::where('work_item_update_id', $update->id)->pluck('recipient_id')->all());
    }

    public function test_comments_notify_creator_and_assignee_without_duplicate_self_notifications(): void
    {
        $item = $this->item();
        $this->actingAs($this->assignee)->postJson('/work/items/'.$item->id.'/comments', ['body' => '<b>Bình luận mới</b>'])->assertCreated();
        $activity = $item->updates()->latest('id')->firstOrFail();
        $this->assertSame([$this->owner->id], WorkNotification::where('work_item_update_id', $activity->id)->pluck('recipient_id')->all());
        $this->actingAs($this->owner)->getJson('/work-notifications')->assertJsonPath('notifications.data.0.kind', 'comment')->assertJsonPath('notifications.data.0.summary', '<b>Bình luận mới</b>');
        $selfAssigned = $this->item($this->owner->id);
        $this->actingAs($this->member)->postJson('/work/items/'.$selfAssigned->id.'/comments', ['body' => 'One recipient'])->assertCreated();
        $last = $selfAssigned->updates()->latest('id')->firstOrFail();
        $this->assertSame(1, WorkNotification::where('work_item_update_id', $last->id)->count());
    }

    public function test_no_op_conflict_and_rolled_back_operations_do_not_create_notifications(): void
    {
        $item = $this->item();
        $this->actingAs($this->owner)->patchJson('/work/items/'.$item->id, ['status' => 'todo', 'version' => 1])->assertOk();
        $this->patchJson('/work/items/'.$item->id, ['status' => 'done', 'version' => 999])->assertConflict();
        $this->assertDatabaseCount('work_notifications', 1);
        try {
            DB::transaction(function () use ($item) {
                $item->updates()->create(['author_id' => $this->member->id, 'body' => 'Will roll back']);
                throw new RuntimeException('test rollback');
            });
        } catch (RuntimeException $error) { $this->assertSame('test rollback', $error->getMessage()); }
        $this->assertDatabaseCount('work_notifications', 1);
        $this->assertDatabaseCount('work_item_updates', 1);
    }

    public function test_read_and_read_all_are_personal_idempotent_and_filter_unread_correctly(): void
    {
        $item = $this->item();
        $this->actingAs($this->owner)->patchJson('/work/items/'.$item->id, ['status' => 'doing', 'version' => 1])->assertOk();
        $notice = WorkNotification::where('recipient_id', $this->assignee->id)->oldest('id')->firstOrFail();
        $this->actingAs($this->owner)->patchJson('/work-notifications/'.$notice->id.'/read')->assertNotFound();
        $this->actingAs($this->assignee)->getJson('/work-notifications/count')->assertJsonPath('unread_count', 2);
        $this->patchJson('/work-notifications/'.$notice->id.'/read')->assertOk()->assertJsonPath('unread_count', 1);
        $readAt = $notice->fresh()->read_at->toIso8601String();
        $this->patchJson('/work-notifications/'.$notice->id.'/read')->assertOk()->assertJsonPath('read_at', $readAt)->assertJsonPath('unread_count', 1);
        $this->getJson('/work-notifications?unread=1')->assertJsonPath('notifications.total', 1);
        $this->patchJson('/work-notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/work-notifications')->assertJsonPath('notifications.total', 2);
        $this->getJson('/work-notifications?unread=1')->assertJsonPath('notifications.total', 0);
    }

    public function test_removed_members_cannot_list_count_read_or_open_old_notifications(): void
    {
        $item = $this->item();
        $notice = WorkNotification::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/work/projects/'.$this->project->id, ['name' => 'Private project', 'member_ids' => [$this->member->id]])->assertOk();
        $this->actingAs($this->assignee)->getJson('/work-notifications')->assertJsonPath('notifications.total', 0)->assertJsonPath('unread_count', 0);
        $this->getJson('/work-notifications/count')->assertJsonPath('unread_count', 0);
        $this->patchJson('/work-notifications/'.$notice->id.'/read')->assertNotFound();
        $this->patchJson('/work-notifications/read-all')->assertJsonPath('unread_count', 0);
        $this->assertNull($notice->fresh()->read_at);
        $this->get('/work?project='.$this->project->id.'&item='.$item->id)->assertNotFound();
        $this->get('/?tab=notifications')->assertInertia(fn (Assert $page) => $page->where('workNotificationCount', 0)->etc());
    }

    public function test_deep_links_open_the_correct_item_and_reject_wrong_or_private_projects(): void
    {
        $item = $this->item();
        $this->actingAs($this->assignee)->get('/work?item='.$item->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('project.id', $this->project->id)->where('selectedItemId', $item->id)->where('workNotificationCount', 1)->etc());
        $other = WorkProject::create(['name' => 'Other project', 'owner_id' => $this->assignee->id]);
        $other->members()->sync([$this->assignee->id]);
        $this->get('/work?project='.$other->id.'&item='.$item->id)->assertNotFound();
        $this->actingAs($this->outsider)->get('/work?item='.$item->id)->assertNotFound();
        $this->getJson('/work-notifications')->assertJsonPath('notifications.total', 0);
    }

    public function test_deleting_an_item_removes_its_notifications_and_guest_endpoints_require_login(): void
    {
        $item = $this->item();
        $this->actingAs($this->owner)->deleteJson('/work/items/'.$item->id)->assertNoContent();
        $this->assertDatabaseCount('work_notifications', 0);
        auth()->logout();
        $this->getJson('/work-notifications')->assertUnauthorized();
        $this->getJson('/work-notifications/count')->assertUnauthorized();
        $this->patchJson('/work-notifications/read-all')->assertUnauthorized();
        $this->patchJson('/work-notifications/1/read')->assertUnauthorized();
    }

    private function user(): User { return User::factory()->create(['employee_code' => uniqid('NOTICE-')]); }

    private function item(?int $assigneeId = -1): WorkItem
    {
        $response = $this->actingAs($this->owner)->postJson('/work/projects/'.$this->project->id.'/items', [
            'title' => 'Notification test', 'status' => 'todo', 'priority' => 'normal',
            'assignee_id' => $assigneeId === -1 ? $this->assignee->id : $assigneeId,
        ])->assertCreated();
        return WorkItem::findOrFail($response->json('id'));
    }
}
