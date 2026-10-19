<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\OnlyOfficeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnlyOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['onlyoffice.jwt_secret' => str_repeat('test-secret-', 4)]);
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_owner_can_edit_personal_document_and_config_is_signed(): void
    {
        $user = $this->createUser();
        $file = $this->document($user, personal: true);

        $this->actingAs($user)->get("/office/personal/{$file->id}?edit=1")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('OfficeEditor')
            ->where('editing', true)
            ->where('editorConfig.documentType', 'word')
            ->where('editorConfig.document.permissions.edit', true)
            ->where('editorConfig.editorConfig.mode', 'edit')
            ->where('editorConfig.token', function ($token) use ($file) {
                $claims = app(OnlyOfficeToken::class)->decode($token);

                return $claims['document']['key'] === $file->fresh()->office_key;
            })
            ->etc());
    }

    public function test_personal_document_is_inaccessible_to_guests_and_other_users(): void
    {
        $file = $this->document($this->createUser(), personal: true);
        $this->get("/office/personal/{$file->id}")->assertForbidden();
        $this->actingAs($this->createUser())->get("/office/personal/{$file->id}?edit=1")->assertForbidden();
    }

    public function test_shared_file_is_viewable_but_only_its_owner_can_edit(): void
    {
        $owner = $this->createUser();
        $file = $this->document($owner);
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editorConfig.document.permissions.edit', false)->where('editing', false)->etc());
        $this->get("/office/shared/{$file->id}?edit=1")->assertForbidden();
        $this->actingAs($this->createUser())->get("/office/shared/{$file->id}?edit=1")->assertForbidden();
        $this->actingAs($owner)->get("/office/shared/{$file->id}?edit=1")->assertOk();
    }

    public function test_view_only_storage_remains_view_only_for_other_users(): void
    {
        $owner = $this->createUser();
        $file = $this->document($owner, viewOnly: true);
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editorConfig.document.permissions.download', false)
            ->where('editorConfig.document.permissions.print', false)
            ->where('editorConfig.document.permissions.copy', false)->etc());
        $this->actingAs($owner)->get("/office/shared/{$file->id}?edit=1")->assertOk();
    }

    public function test_anonymous_upload_can_be_edited_without_login_or_uploader_session(): void
    {
        $file = $this->document();
        $this->get("/office/shared/{$file->id}?edit=1")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editing', true)->where('editorConfig.document.permissions.edit', true)->etc());
        $this->actingAs($this->createUser())->get("/office/shared/{$file->id}?edit=1")->assertOk();
    }

    public function test_guest_can_edit_their_upload_and_sees_the_edit_action_when_viewing(): void
    {
        $this->post('/upload', [
            'file' => UploadedFile::fake()->create('guest.docx', 1),
            'ttl_minutes' => 60,
        ])->assertRedirect();
        $file = Upload::latest('id')->firstOrFail();
        $this->assertNull($file->user_id);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('files.data.0.id', $file->id)->etc());
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editing', false)->where('editUrl', "/office/shared/{$file->id}?edit=1")->etc());
        $this->get("/office/shared/{$file->id}?edit=1")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editing', true)->where('editorConfig.document.permissions.edit', true)
            ->where('editorConfig.editorConfig.user.id', fn ($id) => str_starts_with($id, 'guest-') && strlen($id) === 70)->etc());
        $this->assertGuest();
    }

    public function test_anonymous_file_stays_editable_on_a_different_host_and_with_no_session_claims(): void
    {
        $file = $this->document();
        $registeredFile = $this->document($this->createUser());
        $this->get("http://localhost/office/shared/{$file->id}")->assertOk();
        $this->get("https://office-test.ngrok.app/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editUrl', "/office/shared/{$file->id}?edit=1")->etc());
        $this->get("https://office-test.ngrok.app/office/shared/{$file->id}?edit=1")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editing', true)->where('editorConfig.document.permissions.edit', true)->etc());
        $this->get("/office/shared/{$registeredFile->id}?edit=1")->assertForbidden();
        $this->assertGuest();
    }

    public function test_anonymous_view_only_file_does_not_allow_public_editing_or_guest_saves(): void
    {
        $file = $this->document(viewOnly: true);
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editUrl', null)->where('editorConfig.document.permissions.edit', false)->etc());
        $this->get("/office/shared/{$file->id}?edit=1")->assertForbidden();
        $url = $this->signedUrl($file, 'callback', editor: 'guest');
        $this->postJson($url, $this->callbackBody($file, 2))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_guest_can_save_using_the_issued_callback_without_browser_cookies(): void
    {
        $file = $this->document();
        $callbackUrl = null;
        $this->get("/office/shared/{$file->id}?edit=1")->assertOk()
            ->assertInertia(function (Assert $page) use (&$callbackUrl) {
                return $page->where('editorConfig.editorConfig.callbackUrl', function ($url) use (&$callbackUrl) {
                    $callbackUrl = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

                    return true;
                })->etc();
            });
        $file->refresh();
        $body = $this->callbackBody($file, 6);
        $this->fakeDownload('guest force-saved document');
        $this->postJson($callbackUrl, $body)->assertOk()->assertExactJson(['error' => 0]);
        $this->assertSame('guest force-saved document', Storage::disk('public')->get($file->file_path));
        $this->postJson($callbackUrl, $this->callbackBody($file, 2))->assertOk()->assertExactJson(['error' => 0]);
        $this->assertNotSame($file->office_key, $file->fresh()->office_key);
    }

    public function test_guest_callback_cannot_modify_a_file_that_was_assigned_to_an_account(): void
    {
        $file = $this->document();
        $url = $this->signedUrl($file, 'callback', editor: 'guest');
        $file->update(['user_id' => $this->createUser()->id]);
        $this->postJson($url, $this->callbackBody($file, 2))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_viewer_callback_cannot_be_changed_into_a_guest_editor_callback(): void
    {
        $file = $this->document();
        $url = $this->signedUrl($file, 'callback', editor: 0);
        $this->postJson(str_replace('editor=0', 'editor=guest', $url), $this->callbackBody($file, 2))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_spreadsheet_uses_cell_editor_and_unsupported_formats_are_rejected(): void
    {
        $file = $this->document(name: 'report.XLSX');
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editorConfig.documentType', 'cell')->where('editorConfig.document.fileType', 'xlsx')->etc());
        $file->update(['file_name' => 'legacy.xls']);
        $this->get("/office/shared/{$file->id}")->assertStatus(415);
    }

    public function test_content_requires_a_valid_signature_and_current_key_without_a_login(): void
    {
        $file = $this->document($this->createUser(), personal: true);
        $url = $this->signedUrl($file, 'content', personal: true);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get("/office/personal/{$file->id}/content?key={$file->office_key}")->assertForbidden();
        $this->get($url.'&editor=123')->assertForbidden();
        $file->office_key = 'new-session';
        $file->save();
        $this->get($url)->assertForbidden();
    }

    public function test_expired_document_is_denied_even_if_the_signed_url_is_still_valid(): void
    {
        config(['app.debug' => true]);
        $file = $this->document();
        $url = $this->signedUrl($file, 'content');
        $file->update(['expired_at' => now()->subMinute()]);
        $this->get("/office/shared/{$file->id}")->assertStatus(410)
            ->assertDontSee('Symfony\\Component\\HttpKernel\\Exception')
            ->assertInertia(fn (Assert $page) => $page->component('FileUnavailable')
                ->where('status', 410)->where('backUrl', '/')->missing('editorConfig')->etc());
        $this->get($url)->assertStatus(410);
    }

    public function test_deleted_document_shows_a_friendly_page_for_direct_and_inertia_visits(): void
    {
        config(['app.debug' => true]);
        $file = $this->document();
        $file->delete();
        $response = $this->get("/office/shared/{$file->id}");
        $response->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('FileUnavailable')
                ->where('status', 404)->where('backUrl', '/')->etc());
        $this->get("/office/shared/{$file->id}", [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $response->viewData('page')['version'],
            'Accept' => 'text/html',
        ])
            ->assertNotFound()->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'FileUnavailable')->assertJsonPath('props.status', 404);
    }

    public function test_expired_personal_document_returns_to_the_personal_file_list(): void
    {
        $owner = $this->createUser();
        $file = $this->document($owner, personal: true);
        $file->update(['expired_at' => now()->subMinute()]);
        $this->actingAs($owner)->get("/office/personal/{$file->id}?edit=1")->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('FileUnavailable')
                ->where('status', 410)->where('backUrl', '/my-files')->etc());
        $file->delete();
        $this->get("/office/personal/{$file->id}")->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('FileUnavailable')->where('status', 404)->etc());
    }

    public function test_signed_content_and_callback_errors_do_not_render_a_frontend_page(): void
    {
        $file = $this->document($this->createUser());
        $contentUrl = $this->signedUrl($file, 'content');
        $callbackUrl = $this->signedUrl($file, 'callback');
        $file->update(['expired_at' => now()->subMinute()]);
        $this->getJson($contentUrl)->assertStatus(410)->assertJsonMissingPath('component');
        $this->postJson($callbackUrl, $this->callbackBody($file, 2))->assertStatus(410)->assertJsonMissingPath('component');
        $this->getJson("/office/shared/{$file->id}")->assertStatus(410)->assertJsonMissingPath('component');
        Http::assertNothingSent();
    }

    public function test_editor_and_content_urls_share_a_key_and_expire_with_the_file(): void
    {
        $file = $this->document();
        $this->get("/office/shared/{$file->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editorConfig.document.url', function ($url) use ($file) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query['expires'] === (string) $file->expired_at->timestamp
                    && $query['key'] === $file->fresh()->office_key;
            })->etc());
        $firstKey = $file->fresh()->office_key;
        $this->get("/office/shared/{$file->id}")->assertOk();
        $this->assertSame($firstKey, $file->fresh()->office_key);
    }

    public function test_force_save_persists_content_without_changing_the_session_key(): void
    {
        $file = $this->document($this->createUser(), personal: true);
        $url = $this->signedUrl($file, 'callback', personal: true);
        $this->fakeDownload('updated document');
        $this->postJson($url, $this->callbackBody($file, 6))->assertOk()->assertExactJson(['error' => 0]);
        Storage::disk('public')->assertExists($file->file_path);
        $this->assertSame('updated document', Storage::disk('public')->get($file->file_path));
        $this->assertSame($file->office_key, $file->fresh()->office_key);
        $this->assertSame(strlen('updated document'), $file->fresh()->file_size);
        Http::assertSent(fn ($request) => $request->url() === 'http://onlyoffice/cache/files/session/output.docx?signature=test');
    }

    public function test_final_save_rotates_key_and_a_retried_callback_cannot_overwrite_it(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        $body = $this->callbackBody($file, 2);
        $this->fakeDownload('final version');
        $this->postJson($url, $body)->assertOk()->assertExactJson(['error' => 0]);
        $this->assertNotSame($file->office_key, $file->fresh()->office_key);
        $this->postJson($url, $body)->assertOk()->assertExactJson(['error' => 0]);
        Http::assertSentCount(1);
        $this->assertSame('final version', Storage::disk('public')->get($file->file_path));
    }

    public function test_save_of_a_view_only_file_writes_to_the_private_disk(): void
    {
        $file = $this->document($this->createUser(), viewOnly: true);
        $url = $this->signedUrl($file, 'callback');
        $this->fakeDownload('private version');
        $this->postJson($url, $this->callbackBody($file, 2))->assertOk();
        $this->assertSame('private version', Storage::disk('local')->get($file->file_path));
        Storage::disk('public')->assertMissing($file->file_path);
    }

    public function test_callback_rejects_missing_forged_or_wrong_document_tokens(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        $this->postJson($url, ['status' => 2, 'url' => 'http://attacker/file'])->assertForbidden();
        $this->postJson($url, ['token' => 'fake.token.signature'])->assertForbidden();
        $body = $this->callbackBody($file, 2, ['key' => 'another-file']);
        $this->postJson($url, $body)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_callback_uses_signed_payload_instead_of_unsigned_body_fields(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        $body = $this->callbackBody($file, 4);
        $body['status'] = 2;
        $body['url'] = 'http://attacker/file';
        $this->postJson($url, $body)->assertOk()->assertExactJson(['error' => 0]);
        Http::assertNothingSent();
    }

    public function test_viewer_callback_has_no_write_permission(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback', editor: 0);
        $this->postJson($url, $this->callbackBody($file, 2))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_failed_download_keeps_the_original_document_and_key(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        Http::fake(['*' => Http::response('failure', 500)]);
        $this->postJson($url, $this->callbackBody($file, 2))->assertStatus(500)->assertExactJson(['error' => 1]);
        $this->assertSame('original document', Storage::disk('public')->get($file->file_path));
        $this->assertSame($file->office_key, $file->fresh()->office_key);
    }

    public function test_expired_deleted_and_reassigned_files_cannot_be_saved(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        $body = $this->callbackBody($file, 2);
        $file->update(['expired_at' => now()->subMinute()]);
        $this->postJson($url, $body)->assertStatus(410);
        $file->update(['expired_at' => now()->addHour(), 'user_id' => $this->createUser()->id]);
        $this->postJson($url, $body)->assertForbidden();
        $file->delete();
        $this->postJson($url, $body)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_callback_refuses_non_cache_paths(): void
    {
        $file = $this->document($this->createUser());
        $url = $this->signedUrl($file, 'callback');
        $this->postJson($url, $this->callbackBody($file, 2, ['url' => 'http://onlyoffice/admin']))
            ->assertStatus(500)->assertExactJson(['error' => 1]);
        Http::assertNothingSent();
    }

    public function test_authenticated_upload_grants_selected_users_edit_access(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        $other = $this->createUser();
        $this->actingAs($owner)->post('/upload', [
            'file' => UploadedFile::fake()->create('shared.docx', 1),
            'ttl_minutes' => 60,
            'view_only' => true,
            'editor_ids' => [$editor->id, $owner->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $file = Upload::latest('id')->firstOrFail();
        $this->assertSame([$editor->id], $file->editors()->pluck('users.id')->all());
        $this->actingAs($editor)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('files.data.0.is_editor', true)->etc());
        $this->get("/office/shared/{$file->id}?edit=1")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('editing', true)->where('editorConfig.document.permissions.download', true)->etc());
        $this->get("/files/{$file->id}/download")->assertOk();
        $this->delete("/files/{$file->id}")->assertForbidden();
        $this->actingAs($other)->get("/office/shared/{$file->id}?edit=1")->assertForbidden();
        $this->get("/files/{$file->id}/download")->assertForbidden();
    }

    public function test_personal_file_is_visible_and_editable_to_selected_users_only(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        $other = $this->createUser();
        $this->actingAs($owner)->post('/my-files/upload', [
            'file' => UploadedFile::fake()->create('shared.xlsx', 1),
            'editor_ids' => [$editor->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $file = UserUpload::latest('id')->firstOrFail();
        $this->assertSame([$editor->id], $file->editors()->pluck('users.id')->all());
        $this->actingAs($editor)->get('/my-files')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('files', 1)->where('files.0.id', $file->id)->where('files.0.user.name', $owner->name)->etc());
        $this->get("/office/personal/{$file->id}?edit=1")->assertOk();
        $this->get("/my-files/{$file->id}/preview")->assertRedirect();
        $this->get("/my-files/{$file->id}/download")->assertOk();
        $this->delete("/my-files/{$file->id}")->assertNotFound();
        $this->actingAs($other)->get('/my-files')->assertInertia(fn (Assert $page) => $page->has('files', 0)->etc());
        $this->get("/office/personal/{$file->id}")->assertForbidden();
        $this->get("/my-files/{$file->id}/preview")->assertForbidden();
        $this->get("/my-files/{$file->id}/download")->assertForbidden();
    }

    public function test_unselected_users_cannot_edit_and_permissions_are_not_shared_between_scopes(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        $shared = $this->document($owner);
        $personal = $this->document($owner, personal: true);
        $shared->editors()->attach($editor);
        $this->actingAs($editor)->get("/office/shared/{$shared->id}?edit=1")->assertOk();
        $this->get("/office/personal/{$personal->id}?edit=1")->assertForbidden();
        $shared->editors()->detach($editor);
        $this->get("/office/shared/{$shared->id}?edit=1")->assertForbidden();
    }

    public function test_selected_editor_callback_uses_the_editor_identity_and_rechecks_permissions(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        foreach (['shared', 'personal'] as $scope) {
            $file = $this->document($owner, personal: $scope === 'personal');
            $file->editors()->attach($editor);
            $callbackUrl = null;
            $this->actingAs($editor)->get("/office/{$scope}/{$file->id}?edit=1")->assertOk()
                ->assertInertia(function (Assert $page) use (&$callbackUrl, $editor) {
                    return $page->where('editorConfig.editorConfig.callbackUrl', function ($url) use (&$callbackUrl, $editor) {
                        $callbackUrl = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
                        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

                        return $parameters['editor'] === (string) $editor->id;
                    })->etc();
                });
            $file->refresh();
            $body = $this->callbackBody($file, 6);
            $this->fakeDownload('collaborator changes');
            $this->postJson($callbackUrl, $body)->assertOk()->assertExactJson(['error' => 0]);
            $this->assertSame('collaborator changes', Storage::disk('public')->get($file->file_path));
            $file->editors()->detach($editor);
            $this->postJson($callbackUrl, $body)->assertForbidden();
        }
    }

    public function test_user_search_requires_login_and_returns_only_names_codes_and_ids(): void
    {
        $this->getJson('/file-editors?search=Office')->assertUnauthorized();
        $owner = $this->createUser();
        $editor = User::factory()->create(['name' => 'Office Recipient', 'employee_code' => 'SEARCH-XYZ']);
        $this->actingAs($owner)->getJson('/file-editors?search=recipient')->assertOk()
            ->assertExactJson([['id' => $editor->id, 'name' => $editor->name, 'employee_code' => $editor->employee_code]]);
        $this->getJson('/file-editors?search=search-xyz')->assertOk()->assertJsonPath('0.id', $editor->id);
        $this->getJson('/file-editors?search='.urlencode($owner->employee_code))->assertExactJson([]);
        $this->getJson('/file-editors?search=a')->assertExactJson([]);
    }

    public function test_anonymous_upload_cannot_assign_editors(): void
    {
        $editor = $this->createUser();
        $this->post('/upload', [
            'file' => UploadedFile::fake()->create('guest.docx', 1),
            'ttl_minutes' => 60,
            'editor_ids' => [$editor->id],
        ])->assertSessionHasErrors('editor_ids');
        $this->assertDatabaseCount('uploads', 0);
        $this->assertDatabaseCount('upload_editors', 0);
    }

    public function test_invalid_or_duplicate_editor_ids_are_rejected_before_storing_files(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        $this->actingAs($owner)->post('/upload', [
            'file' => UploadedFile::fake()->create('invalid.docx', 1),
            'ttl_minutes' => 60,
            'editor_ids' => [99999999],
        ])->assertSessionHasErrors('editor_ids.0');
        $this->post('/my-files/upload', [
            'file' => UploadedFile::fake()->create('duplicate.docx', 1),
            'editor_ids' => [$editor->id, $editor->id],
        ])->assertSessionHasErrors('editor_ids.0');
        $this->assertDatabaseCount('uploads', 0);
        $this->assertDatabaseCount('user_uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_deleting_a_shared_personal_file_cleans_up_its_permissions(): void
    {
        $owner = $this->createUser();
        $editor = $this->createUser();
        $file = $this->document($owner, personal: true);
        $file->editors()->attach($editor);
        $this->actingAs($owner)->delete("/my-files/{$file->id}")->assertRedirect();
        $this->assertDatabaseCount('user_upload_editors', 0);
        $this->actingAs($editor)->get('/my-files')->assertInertia(fn (Assert $page) => $page->has('files', 0)->etc());
        $this->get("/office/personal/{$file->id}?edit=1")->assertNotFound();
    }

    private function createUser(): User
    {
        return User::factory()->create(['employee_code' => 'OFFICE-'.uniqid()]);
    }

    private function document(?User $user = null, bool $personal = false, bool $viewOnly = false, string $name = 'report.docx'): Upload|UserUpload
    {
        $attributes = [
            'user_id' => $user?->id,
            'file_name' => $name,
            'file_path' => 'uploads/'.uniqid().'.docx',
            'expired_at' => $personal ? null : now()->addHour()->startOfSecond(),
        ];
        $file = $personal ? UserUpload::create($attributes) : Upload::create($attributes + ['view_only' => $viewOnly]);
        Storage::disk($viewOnly ? 'local' : 'public')->put($file->file_path, 'original document');

        return $file;
    }

    private function signedUrl(Upload|UserUpload $file, string $action, bool $personal = false, int|string|null $editor = null): string
    {
        if (! $file->office_key) {
            $file->office_key = (string) Str::uuid();
            $file->save();
        }
        $parameters = ['scope' => $personal ? 'personal' : 'shared', 'id' => $file->id, 'key' => $file->office_key];
        if ($action === 'callback') {
            $parameters['editor'] = $editor ?? $file->user_id;
        }

        return URL::temporarySignedRoute('office.'.$action, now()->addHours(12), $parameters, absolute: false);
    }

    private function callbackBody(Upload|UserUpload $file, int $status, array $overrides = []): array
    {
        return ['token' => app(OnlyOfficeToken::class)->encode(array_replace([
            'key' => $file->office_key,
            'status' => $status,
            'url' => 'http://localhost:8111/onlyoffice/cache/files/session/output.docx?signature=test',
        ], $overrides))];
    }

    private function fakeDownload(string $content): void
    {
        Http::fake(function ($request, $options) use ($content) {
            file_put_contents($options['sink'], $content);

            return Http::response($content);
        });
    }
}
