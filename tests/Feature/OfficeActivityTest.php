<?php

namespace Tests\Feature;

use App\Events\OfficeFileSaved;
use App\Models\FileActivity;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\OnlyOfficeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeActivityTest extends TestCase
{
    use RefreshDatabase;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['onlyoffice.jwt_secret' => str_repeat('secret-', 10)]);
        $this->dispatcher = Event::getFacadeRoot();
        Event::fake([OfficeFileSaved::class]);
        Http::preventStrayRequests();
    }

    public function test_save_records_signed_actor_and_broadcasts_once_even_after_retries(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $file = $this->file($owner);
        $file->editors()->attach($editor);
        $callback = $this->saveCallback($file, ['users' => [(string) $editor->id]]);
        $this->download('updated');
        $this->postJson($callback[0], $callback[1])->assertOk()->assertExactJson(['error' => 0]);
        $this->assertDatabaseHas('file_activities', ['scope' => 'shared', 'file_id' => $file->id, 'actor_name' => $editor->name, 'actor_key' => (string) $editor->id]);
        $this->postJson($callback[0], $callback[1])->assertOk();
        $this->assertDatabaseCount('file_activities', 1);
        Event::assertDispatchedTimes(OfficeFileSaved::class, 1);
        Event::assertDispatched(OfficeFileSaved::class, fn ($event) => $event->fileId === $file->id && $event->broadcastWith() === ['id' => FileActivity::first()->id]);
        $this->actingAs($editor)->getJson('/office/shared/'.$file->id.'/activities')->assertOk()
            ->assertJsonPath('activities.0.is_self', true)->assertJsonMissingPath('activities.0.actor_key');
    }

    public function test_unchanged_content_is_not_logged_and_failed_save_does_not_notify(): void
    {
        $file = $this->file($this->user());
        [$url, $body] = $this->saveCallback($file);
        $this->download('original');
        $this->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('file_activities', 0);
        config(['upload_limits.user_quota' => 1]);
        $this->download('too large');
        $this->postJson($url, $body)->assertStatus(500);
        $this->assertDatabaseCount('file_activities', 0);
        Event::assertNotDispatched(OfficeFileSaved::class);
        $this->assertSame('original', Storage::disk('public')->get($file->file_path));
    }

    public function test_delayed_duplicate_callback_cannot_overwrite_a_more_recent_save(): void
    {
        $file = $this->file($this->user());
        [$url, $first] = $this->saveCallback($file, ['url' => 'http://onlyoffice/cache/files/session/first.docx']);
        [, $second] = $this->saveCallback($file, ['url' => 'http://onlyoffice/cache/files/session/second.docx']);
        $this->download('first');
        $this->postJson($url, $first)->assertOk();
        $this->download('second');
        $this->postJson($url, $second)->assertOk();
        $this->download('first');
        $this->postJson($url, $first)->assertOk();
        $this->assertSame('second', Storage::disk('public')->get($file->file_path));
        $this->assertDatabaseCount('file_activities', 2);
        Event::assertDispatchedTimes(OfficeFileSaved::class, 2);
    }

    public function test_guest_can_subscribe_to_shared_file_but_cannot_choose_an_arbitrary_channel(): void
    {
        $file = $this->file();
        $this->postJson('/office/broadcast/auth', ['socket_id' => '123.456', 'channel_name' => 'private-office.shared.'.$file->id])
            ->assertOk()->assertJsonStructure(['auth']);
        foreach (['office.shared.'.$file->id, 'private-office.invalid.1', 'private-office.shared.0', 'private-anything'] as $channel) {
            $this->postJson('/office/broadcast/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertUnprocessable();
        }
        $this->postJson('/office/broadcast/auth', ['socket_id' => 'bad', 'channel_name' => 'private-office.shared.'.$file->id])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_personal_history_and_channel_authorization_recheck_permissions_and_expiry(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $file = $this->file($owner, true);
        $url = '/office/personal/'.$file->id.'/activities';
        $input = ['socket_id' => '123.456', 'channel_name' => 'private-office.personal.'.$file->id];
        $this->getJson($url)->assertForbidden();
        $this->postJson('/office/broadcast/auth', $input)->assertForbidden();
        $file->editors()->attach($editor);
        $this->actingAs($editor)->getJson($url)->assertOk();
        $this->postJson('/office/broadcast/auth', $input)->assertOk();
        $file->editors()->detach($editor);
        $this->getJson($url)->assertForbidden();
        $this->postJson('/office/broadcast/auth', $input)->assertForbidden();
        $this->actingAs($owner);
        $file->update(['expired_at' => now()->subMinute()]);
        $this->getJson($url)->assertGone();
        $this->postJson('/office/broadcast/auth', $input)->assertGone();
    }

    public function test_history_catchup_is_ordered_scoped_and_does_not_expose_tokens_or_actor_ids(): void
    {
        $file = $this->file($this->user());
        $other = $this->file();
        $this->download('changed');
        [$url, $body] = $this->saveCallback($file);
        $this->postJson($url, $body)->assertOk();
        $id = FileActivity::first()->id;
        $this->getJson('/office/shared/'.$file->id.'/activities?after=0')->assertOk()
            ->assertJsonPath('activities.0.id', $id)->assertJsonPath('activities.0.actor_name', 'Không xác định')
            ->assertJsonMissingPath('activities.0.event_key')->assertJsonMissingPath('activities.0.content_hash');
        $this->getJson('/office/shared/'.$file->id.'/activities?after='.$id)->assertJsonCount(0, 'activities');
        $this->getJson('/office/shared/'.$other->id.'/activities')->assertJsonCount(0, 'activities');
    }

    public function test_websocket_outage_does_not_fail_a_successful_save(): void
    {
        Event::swap($this->dispatcher);
        // A throwing listener exercises the callback's isolated post-commit notification path.
        Event::listen(OfficeFileSaved::class, function () {
            throw new \RuntimeException('offline');
        });
        $file = $this->file($this->user());
        [$url, $body] = $this->saveCallback($file);
        $this->download('saved');
        $this->postJson($url, $body)->assertOk()->assertExactJson(['error' => 0]);
        $this->assertDatabaseCount('file_activities', 1);
        $this->assertSame('saved', Storage::disk('public')->get($file->file_path));
    }

    private function user(): User
    {
        return User::factory()->create(['employee_code' => 'ACT-'.Str::uuid()]);
    }

    private function file(?User $user = null, bool $personal = false): Upload|UserUpload
    {
        $attributes = ['user_id' => $user?->id, 'file_name' => 'test.docx', 'file_path' => 'uploads/'.Str::uuid().'.docx', 'file_size' => 8, 'expired_at' => now()->addHour()];
        $file = $personal ? UserUpload::create($attributes) : Upload::create($attributes);
        $file->office_key = (string) Str::uuid();
        $file->save();
        Storage::disk('public')->put($file->file_path, 'original');

        return $file;
    }

    private function saveCallback(Upload|UserUpload $file, array $extra = []): array
    {
        $url = URL::temporarySignedRoute('office.callback', now()->addHour(), [
            'scope' => $file instanceof UserUpload ? 'personal' : 'shared', 'id' => $file->id,
            'key' => $file->office_key, 'editor' => $file->user_id ?? 'guest',
        ], absolute: false);
        $claims = array_replace(['key' => $file->office_key, 'status' => 6, 'url' => 'http://onlyoffice/cache/files/session/output.docx'], $extra);

        return [$url, ['token' => app(OnlyOfficeToken::class)->encode($claims)]];
    }

    private function download(string $content): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($content) {
            file_put_contents($options['sink'], $content);

            return Http::response($content);
        });
    }
}
