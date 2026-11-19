<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = $this->newUser();
        $this->member = $this->newUser();
        $this->outsider = $this->newUser();
    }

    public function test_guest_requires_login_and_an_empty_workspace_can_be_opened(): void
    {
        $this->get('/work')->assertRedirect('/login');
        $this->postJson('/work/projects', ['name' => 'No'])->assertUnauthorized();
        $this->actingAs($this->owner)->get('/work')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Work/Index')->has('projects', 0)->where('project', null)->where('stats.total', 0)->etc());
    }

    public function test_project_creator_is_always_a_member_and_only_members_see_projects(): void
    {
        $project = $this->project();
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->member->id], $project->members()->pluck('users.id')->all());
        $this->actingAs($this->member)->get('/work?project='.$project->id)->assertInertia(fn (Assert $page) => $page
            ->has('projects', 1)->has('project.members', 2)->where('project.id', $project->id)->etc());
        $this->actingAs($this->outsider)->get('/work')->assertInertia(fn (Assert $page) => $page->has('projects', 0)->where('project', null)->etc());
        $this->get('/work?project='.$project->id)->assertNotFound();
    }

    public function test_members_can_create_assign_update_and_complete_tasks_with_history(): void
    {
        $project = $this->project();
        $item = $this->item($project, $this->member);
        $this->assertEquals($this->member->id, $item->creator_id);
        $this->actingAs($this->owner)->patchJson('/work/items/'.$item->id, ['status' => 'done', 'priority' => 'high', 'version' => 1])->assertOk();
        $this->assertSame('done', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->completed_at);
        $this->assertSame(2, $item->fresh()->version);
        $this->getJson('/work/items/'.$item->id)->assertOk()->assertJsonPath('updates.total', 2)
            ->assertJsonPath('updates.data.0.changes.status.from', 'todo')->assertJsonPath('updates.data.0.changes.status.to', 'done');
        $this->patchJson('/work/items/'.$item->id, ['status' => 'doing', 'version' => 2])->assertOk();
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_stale_edits_are_rejected_without_overwriting_other_members_changes(): void
    {
        $item = $this->item($this->project(), $this->owner);
        $this->patchJson('/work/items/'.$item->id, ['title' => 'Đã sửa', 'version' => 1])->assertOk();
        $this->actingAs($this->member)->patchJson('/work/items/'.$item->id, ['title' => 'Ghi đè', 'version' => 1])->assertConflict();
        $this->assertSame('Đã sửa', $item->fresh()->title);
        $this->assertSame(2, $item->updates()->count());
    }

    public function test_people_outside_the_project_cannot_read_change_comment_or_delete_tasks(): void
    {
        $project = $this->project();
        $item = $this->item($project, $this->owner);
        $this->actingAs($this->outsider)->getJson('/work/items/'.$item->id)->assertNotFound();
        $this->patchJson('/work/items/'.$item->id, ['status' => 'done', 'version' => 1])->assertNotFound();
        $this->postJson('/work/items/'.$item->id.'/comments', ['body' => 'Không được xem'])->assertNotFound();
        $this->deleteJson('/work/items/'.$item->id)->assertNotFound();
        $this->postJson('/work/projects/'.$project->id.'/items', ['title' => 'No'])->assertNotFound();
        $this->assertSame(1, $item->updates()->count());
        $this->assertSame('todo', $item->fresh()->status);
    }

    public function test_assignment_is_limited_to_members_and_cannot_change_the_project_or_creator(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner)->postJson('/work/projects/'.$project->id.'/items', [
            'title' => 'Invalid assignment', 'status' => 'todo', 'priority' => 'normal', 'assignee_id' => $this->outsider->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $item = $this->item($project, $this->owner);
        $this->patchJson('/work/items/'.$item->id, ['assignee_id' => $this->outsider->id, 'version' => 1])->assertUnprocessable();
        $this->patchJson('/work/items/'.$item->id, ['title' => 'Safe', 'version' => 1, 'work_project_id' => 999, 'creator_id' => $this->outsider->id])->assertOk();
        $this->assertEquals($project->id, $item->fresh()->work_project_id);
        $this->assertEquals($this->owner->id, $item->fresh()->creator_id);
    }

    public function test_only_project_owner_changes_members_and_removal_revokes_access_and_unassigns_tasks(): void
    {
        $project = $this->project();
        $item = $this->item($project, $this->owner);
        $this->actingAs($this->member)->patchJson('/work/projects/'.$project->id, ['name' => 'Hijack', 'member_ids' => []])->assertForbidden();
        $this->actingAs($this->owner)->patchJson('/work/projects/'.$project->id, ['name' => 'Updated', 'member_ids' => []])->assertOk();
        $this->assertEquals([$this->owner->id], $project->members()->pluck('users.id')->all());
        $this->assertNull($item->fresh()->assignee_id);
        $this->assertSame(2, $item->fresh()->version);
        $this->assertSame(2, $item->updates()->count());
        $this->actingAs($this->member)->getJson('/work/items/'.$item->id)->assertNotFound();
        $this->patchJson('/work/items/'.$item->id, ['status' => 'done', 'version' => 2])->assertNotFound();
    }

    public function test_filters_and_overdue_counts_use_the_active_project_and_exclude_completed_items(): void
    {
        $this->freezeTime();
        $project = $this->project();
        $this->item($project, $this->owner, ['title' => 'Quá hạn', 'due_date' => now()->subDay()->toDateString(), 'priority' => 'urgent']);
        $this->item($project, $this->owner, ['title' => 'Đã xong', 'status' => 'done', 'due_date' => now()->subDay()->toDateString()]);
        $this->item($project, $this->owner, ['title' => 'Tương lai', 'status' => 'doing', 'assignee_id' => null]);
        $other = $this->project('Other');
        $this->item($other, $this->owner, ['title' => 'Không thuộc dự án', 'due_date' => now()->subDay()->toDateString()]);
        $this->get('/work?project='.$project->id.'&overdue=1')->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 3)->where('stats.overdue', 1)->where('stats.done', 1)->where('items.total', 1)->where('items.data.0.title', 'Quá hạn')->etc());
        $this->get('/work?project='.$project->id.'&assignee=unassigned')->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('items.data.0.title', 'Tương lai')->etc());
        $this->get('/work?project='.$project->id.'&q=Quá&priority=urgent&view=board')->assertInertia(fn (Assert $page) => $page->where('items.total', 1)->where('items.per_page', 100)->etc());
    }

    public function test_comments_are_saved_as_text_paginated_and_do_not_invalidate_a_task_edit(): void
    {
        $item = $this->item($this->project(), $this->owner);
        for ($index = 1; $index <= 21; $index++) $this->actingAs($this->member)->postJson('/work/items/'.$item->id.'/comments', ['body' => '<script>text</script> '.$index])->assertCreated();
        $this->assertSame(1, $item->fresh()->version);
        $this->getJson('/work/items/'.$item->id)->assertJsonPath('updates.total', 22)->assertJsonCount(20, 'updates.data')->assertJsonPath('updates.data.0.body', '<script>text</script> 21');
        $this->getJson('/work/items/'.$item->id.'?history_page=2')->assertJsonCount(2, 'updates.data');
        $this->postJson('/work/items/'.$item->id.'/comments', ['body' => '   '])->assertUnprocessable();
    }

    public function test_only_creator_or_project_owner_can_delete_a_task_with_its_history(): void
    {
        $project = $this->project();
        $item = $this->item($project, $this->owner);
        $this->actingAs($this->member)->deleteJson('/work/items/'.$item->id)->assertForbidden();
        $this->actingAs($this->owner)->deleteJson('/work/items/'.$item->id)->assertNoContent();
        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
        $this->assertDatabaseCount('work_item_updates', 0);
        $createdByMember = $this->item($project, $this->member);
        $this->deleteJson('/work/items/'.$createdByMember->id)->assertNoContent();
    }

    public function test_invalid_dates_states_and_blank_names_are_rejected(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner)->postJson('/work/projects', ['name' => '   ', 'member_ids' => []])->assertUnprocessable();
        $item = $this->item($project, $this->owner);
        foreach ([['title' => '  '], ['status' => 'invalid'], ['priority' => 'invalid'], ['due_date' => '2026-02-30']] as $values) {
            $this->patchJson('/work/items/'.$item->id, $values + ['version' => 1])->assertUnprocessable();
        }
        $this->assertSame(1, $item->fresh()->version);
    }

    public function test_people_search_returns_only_basic_directory_fields(): void
    {
        $this->actingAs($this->owner)->getJson('/work/people?q='.$this->member->employee_code)->assertOk()->assertJsonPath('0.id', $this->member->id)
            ->assertJsonMissingPath('0.password')->assertJsonMissingPath('0.email')->assertJsonMissingPath('0.remember_token');
    }

    private function newUser(): User { return User::factory()->create(['employee_code' => uniqid('WORK-')]); }

    private function project(string $name = 'Dự án mẫu'): WorkProject
    {
        $response = $this->actingAs($this->owner)->postJson('/work/projects', ['name' => $name, 'description' => 'Test project', 'member_ids' => [$this->member->id]])->assertCreated();
        return WorkProject::findOrFail($response->json('id'));
    }

    private function item(WorkProject $project, User $creator, array $overrides = []): WorkItem
    {
        $response = $this->actingAs($creator)->postJson('/work/projects/'.$project->id.'/items', array_replace([
            'title' => 'Chuẩn bị báo cáo', 'description' => 'Nội dung công việc', 'status' => 'todo', 'priority' => 'normal',
            'assignee_id' => $this->member->id, 'due_date' => now()->addDays(2)->toDateString(),
        ], $overrides))->assertCreated();
        return WorkItem::findOrFail($response->json('id'));
    }
}
