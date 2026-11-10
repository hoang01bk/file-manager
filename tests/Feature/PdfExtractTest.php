<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\ExtractPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class PdfExtractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_local_pdf_can_be_inspected_without_storing_the_original(): void
    {
        $this->postJson('/pdf/shared/inspect', ['file' => $this->local()])->assertOk()
            ->assertJsonPath('page_count', 4)->assertJsonPath('name', 'local.pdf');
        $this->assertDatabaseCount('uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_preview_serves_original_pdf_only_to_authorized_users_without_caching(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner, private: true);
        $url = '/pdf/shared/'.$source->id.'/preview';
        $this->get($url)->assertForbidden();
        $source->editors()->attach($editor);
        $response = $this->actingAs($editor)->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->path($source->file_path), $response->baseResponse->getFile()->getPathname());
        $source->editors()->detach($editor);
        $this->get($url)->assertForbidden();
        $source->update(['all_users_can_edit' => true, 'expired_at' => now()->subMinute()]);
        $this->get($url)->assertGone();
        $source->update(['expired_at' => now()->addHour()]);
        Storage::disk('local')->delete($source->file_path);
        $this->get($url)->assertNotFound();
    }

    public function test_preview_requires_login_for_shared_files_and_checks_personal_scope(): void
    {
        $shared = $this->source();
        $this->get('/pdf/shared/'.$shared->id.'/preview')->assertForbidden();
        $owner = $this->user();
        $personal = $this->source($owner, personal: true);
        $url = '/pdf/personal/'.$personal->id.'/preview';
        $this->get($url)->assertForbidden();
        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->get('/pdf/shared/'.$shared->id.'/preview')->assertOk();
        config(['upload_limits.max_file_size' => 1]);
        $this->get($url)->assertStatus(413);
    }

    public function test_guest_can_extract_local_pages_in_requested_order_without_duplicates(): void
    {
        $input = $this->local();
        $response = $this->postJson('/pdf/shared/extract', [
            'file' => $input, 'name' => 'Selected', 'pages' => ' 4, 2-3, 2 ', 'ttl_minutes' => 60,
        ])->assertCreated();
        $copy = Upload::findOrFail($response->json('id'));
        $this->assertSame('Selected.pdf', $copy->file_name);
        $this->assertNull($copy->user_id);
        $this->assertFalse($copy->view_only);
        $this->assertFalse($copy->all_users_can_edit);
        $this->assertTrue($copy->expired_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame([400, 200, 300], $this->widths(Storage::disk('public')->path($copy->file_path)));
        $this->assertSame(PdfFixture::pages([100, 200, 300, 400]), file_get_contents($input->getRealPath()));
        $this->assertSame(Storage::disk('public')->size($copy->file_path), (int) $copy->file_size);
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$copy->file_path], Storage::disk('public')->allFiles());
    }

    public function test_local_personal_pdf_requires_login_and_belongs_to_its_creator(): void
    {
        $this->postJson('/pdf/personal/inspect', ['file' => $this->local()])->assertForbidden();
        $this->getJson('/pdf/personal/sources')->assertForbidden();
        $this->postJson('/pdf/personal/extract', ['file' => $this->local(), 'name' => 'Mine', 'pages' => '1'])->assertForbidden();
        $owner = $this->user();
        $response = $this->actingAs($owner)->postJson('/pdf/personal/extract', [
            'file' => $this->local(), 'name' => 'Mine.pdf', 'pages' => '1,3',
        ])->assertCreated();
        $copy = UserUpload::findOrFail($response->json('id'));
        $this->assertSame($owner->id, $copy->user_id);
        $this->assertNull($copy->expired_at);
        $this->assertFalse($copy->all_users_can_edit);
        $this->assertSame(0, $copy->editors()->count());
        $this->assertStringStartsWith('user-uploads/'.$owner->id.'/', $copy->file_path);
        $this->assertSame([100, 300], $this->widths(Storage::disk('public')->path($copy->file_path)));
    }

    public function test_stored_private_pdf_preserves_original_permissions_and_expiry(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner, private: true);
        $source->editors()->attach($editor);
        $this->actingAs($owner)->getJson('/pdf/shared/'.$source->id.'/info')->assertOk()->assertJsonPath('page_count', 4);
        $response = $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'Private', 'pages' => '4-2'])->assertCreated();
        $copy = Upload::findOrFail($response->json('id'));
        $this->assertNotSame($source->file_path, $copy->file_path);
        $this->assertTrue($copy->view_only);
        $this->assertTrue($copy->canEditOffice($editor->id));
        $this->assertFalse($copy->canEditOffice(null));
        $this->assertTrue($copy->expired_at->equalTo($source->expired_at));
        $this->assertSame([400, 300, 200], $this->widths(Storage::disk('local')->path($copy->file_path)));
        $this->assertSame(PdfFixture::pages([100, 200, 300, 400]), Storage::disk('local')->get($source->file_path));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_personal_editor_owns_new_copy_and_original_owner_keeps_access(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner, personal: true);
        $source->editors()->attach($editor);
        config(['upload_limits.user_quota' => 3000]);
        $source->update(['file_size' => 2900]);
        $response = $this->actingAs($editor)->postJson('/pdf/personal/extract', [
            'source_id' => $source->id, 'name' => 'Copy', 'pages' => '2',
        ])->assertCreated();
        $copy = UserUpload::findOrFail($response->json('id'));
        $this->assertSame($editor->id, $copy->user_id);
        $this->assertTrue($copy->canEditOffice($owner->id));
        $this->assertFalse($copy->canEditOffice($this->user()->id));
        $this->assertSame(2900, (int) $source->fresh()->file_size);
    }

    public function test_source_search_lists_only_available_pdfs_the_user_can_edit(): void
    {
        $owner = $this->user();
        $own = $this->source($owner);
        $anonymous = $this->source();
        $public = $this->source($this->user());
        $public->update(['all_users_can_edit' => true]);
        $hidden = $this->source($this->user());
        $expired = $this->source($owner);
        $expired->update(['expired_at' => now()->subMinute()]);
        $notPdf = $this->source($owner);
        $notPdf->update(['file_name' => 'notes.txt']);
        $this->actingAs($owner)->getJson('/pdf/shared/sources')->assertOk()
            ->assertJsonCount(3, 'files');
        $ids = $this->getJson('/pdf/shared/sources')->json('files.*.id');
        $this->assertEqualsCanonicalizing([$own->id, $anonymous->id, $public->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
        $own->update(['file_name' => 'Report.PDF']);
        $this->getJson('/pdf/shared/sources?q=REPORT')->assertOk()->assertJsonCount(1, 'files')->assertJsonPath('files.0.id', $own->id);

        $personal = $this->source($owner, personal: true);
        $otherPersonal = $this->source($this->user(), personal: true);
        $otherPersonal->editors()->attach($owner);
        $this->source($this->user(), personal: true);
        $this->getJson('/pdf/personal/sources')->assertOk()->assertJsonCount(2, 'files');
    }

    public function test_unauthorized_access_is_blocked_and_permissions_are_rechecked_after_inspection(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertForbidden();
        $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'No', 'pages' => '1'])->assertForbidden();
        $source->editors()->attach($editor);
        $this->actingAs($editor)->getJson('/pdf/shared/'.$source->id.'/info')->assertOk();
        $source->editors()->detach($editor);
        $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'No', 'pages' => '1'])->assertForbidden();
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_expired_missing_and_non_pdf_sources_are_rejected(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        $source->update(['expired_at' => now()->subMinute()]);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertGone();
        $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'No', 'pages' => '1'])->assertGone();
        $source->update(['expired_at' => now()->addHour(), 'file_name' => 'image.png']);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertStatus(415);
        $source->update(['file_name' => 'source.pdf']);
        Storage::disk('public')->delete($source->file_path);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertNotFound();
    }

    public function test_invalid_page_selections_are_rejected_without_creating_files(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        foreach (['0', '5', '1-9', '-1', '1,,2', '--replace-input', '1 2', '99999999999999999999999', '1;touch /tmp/bad'] as $pages) {
            $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'Invalid', 'pages' => $pages])
                ->assertUnprocessable()->assertJsonValidationErrors('pages');
        }
        config(['pdf_merge.max_selected_pages' => 2]);
        $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'Too many', 'pages' => '1-3'])
            ->assertUnprocessable()->assertJsonValidationErrors('pages');
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
    }

    public function test_missing_ambiguous_or_invalid_inputs_are_rejected(): void
    {
        $source = $this->source();
        foreach ([
            ['name' => 'No source', 'pages' => '1', 'ttl_minutes' => 60],
            ['source_id' => $source->id, 'file' => $this->local(), 'name' => 'Two sources', 'pages' => '1'],
            ['source_id' => $source->id, 'name' => '../bad', 'pages' => '1'],
            ['source_id' => $source->id, 'name' => '.pdf', 'pages' => '1'],
            ['source_id' => $source->id, 'name' => 'None', 'pages' => ''],
            ['file' => $this->local(), 'name' => 'No TTL', 'pages' => '1'],
            ['file' => UploadedFile::fake()->createWithContent('wrong.txt', 'text'), 'name' => 'Wrong', 'pages' => '1', 'ttl_minutes' => 60],
        ] as $input) {
            $this->postJson('/pdf/shared/extract', $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_encrypted_and_broken_pdfs_are_rejected(): void
    {
        $source = $this->source();
        $path = Storage::disk('public')->path($source->file_path);
        $encrypted = $path.'.encrypted';
        try {
            (new Process(['qpdf', '--encrypt', 'secret', 'owner-secret', '256', '--', $path, $encrypted]))->mustRun();
            $input = UploadedFile::fake()->createWithContent('encrypted.pdf', file_get_contents($encrypted));
            $this->postJson('/pdf/shared/inspect', ['file' => $input])->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->postJson('/pdf/shared/extract', ['file' => $input, 'name' => 'Encrypted', 'pages' => '1', 'ttl_minutes' => 60])->assertUnprocessable();
        } finally {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }
        $this->postJson('/pdf/shared/inspect', ['file' => UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\ngarbage")])->assertUnprocessable();
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_quota_failure_keeps_original_and_does_not_leave_a_new_file(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        config(['upload_limits.user_quota' => 0]);
        $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'Quota', 'pages' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
        $this->assertSame(PdfFixture::pages([100, 200, 300, 400]), Storage::disk('public')->get($source->file_path));
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_database_failure_removes_output_and_keeps_original(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        Upload::created(function () {
            throw new \RuntimeException('Simulated write failure.');
        });
        try {
            app(ExtractPdf::class)->create('shared', auth()->id(), ['source_id' => $source->id, 'name' => 'Failure', 'pages' => '2']);
            $this->fail('Expected write failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated write failure.', $exception->getMessage());
        } finally {
            Upload::flushEventListeners();
        }
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
    }

    public function test_missing_tool_and_excessive_file_size_are_reported(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        config(['upload_limits.max_file_size' => 1]);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertStatus(413);
        config(['upload_limits.max_file_size' => 100 * 1024 * 1024, 'pdf_merge.binary' => 'missing-qpdf-for-test']);
        $this->getJson('/pdf/shared/'.$source->id.'/info')->assertStatus(503);
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_local_pdf_rotation_follows_source_pages_when_output_is_reordered(): void
    {
        $input = $this->local();
        $original = file_get_contents($input->getRealPath());
        $response = $this->postJson('/pdf/shared/extract', [
            'file' => $input, 'name' => 'Rotated', 'pages' => '3,1,4,3', 'ttl_minutes' => 60,
            'rotations' => json_encode([1 => 270, 3 => 90, 4 => 180]),
        ])->assertCreated();
        $copy = Upload::findOrFail($response->json('id'));
        $path = Storage::disk('public')->path($copy->file_path);
        $this->assertSame([300, 100, 400], $this->widths($path));
        $this->assertSame([90, 270, 180], $this->rotations($path));
        $this->assertSame($original, file_get_contents($input->getRealPath()));
    }

    public function test_stored_pdf_rotation_is_relative_to_existing_orientation_and_keeps_original(): void
    {
        $owner = $this->user();
        $source = $this->source($owner, personal: true);
        $path = Storage::disk('public')->path($source->file_path);
        (new Process(['qpdf', $path, '--rotate=+90:2', '--rotate=+270:4', '--replace-input']))->mustRun();
        $original = file_get_contents($path);
        $response = $this->actingAs($owner)->postJson('/pdf/personal/extract', [
            'source_id' => $source->id, 'name' => 'Rotated', 'pages' => '4,2,1',
            'rotations' => [4 => 90, 2 => 270, 1 => 0, 3 => 180],
        ])->assertCreated();
        $copy = UserUpload::findOrFail($response->json('id'));
        $newPath = Storage::disk('public')->path($copy->file_path);
        $this->assertSame([400, 200, 100], $this->widths($newPath));
        $this->assertSame([0, 0, 0], $this->rotations($newPath));
        $this->assertSame([0, 90, 0, 270], $this->rotations($path));
        $this->assertSame($original, file_get_contents($path));
    }

    public function test_invalid_rotation_angles_and_page_keys_are_rejected(): void
    {
        $this->actingAs($this->user());
        $source = $this->source();
        foreach ([[1 => 45], [1 => -90], [1 => 360], [1 => 'bad'], [1 => [90]], [0 => 90], [5 => 90], ['bad' => 90], ['01' => 90], '{broken', '90', 'null'] as $rotations) {
            $this->postJson('/pdf/shared/extract', [
                'source_id' => $source->id, 'name' => 'Invalid', 'pages' => '1', 'rotations' => $rotations,
            ])->assertUnprocessable();
        }
        config(['pdf_merge.max_selected_pages' => 2]);
        $this->postJson('/pdf/shared/extract', [
            'source_id' => $source->id, 'name' => 'Invalid', 'pages' => '1', 'rotations' => [1 => 90, 2 => 180, 3 => 270],
        ])->assertUnprocessable()->assertJsonValidationErrors('rotations');
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
    }

    public function test_guests_cannot_select_or_extract_uploaded_shared_pdfs_even_with_all_users_permission(): void
    {
        $source = $this->source();
        foreach ([false, true] as $allUsers) {
            $source->update(['all_users_can_edit' => $allUsers]);
            $this->getJson('/pdf/shared/sources')->assertOk()->assertJsonCount(0, 'files');
            $this->getJson('/pdf/shared/'.$source->id.'/info')->assertForbidden();
            $this->postJson('/pdf/shared/extract', ['source_id' => $source->id, 'name' => 'Denied', 'pages' => '1'])->assertForbidden();
        }
        $this->assertDatabaseCount('uploads', 1);
    }

    private function rotations(string $path): array
    {
        $json = json_decode((new Process(['qpdf', '--json', $path]))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($page) => $json['qpdf'][1]['obj:'.$page['object']]['value']['/Rotate'] ?? 0, $json['pages']);
    }

    private function user(): User
    {
        return User::factory()->create(['employee_code' => 'SPLIT-'.Str::uuid()]);
    }

    private function source(?User $owner = null, bool $personal = false, bool $private = false): Upload|UserUpload
    {
        $contents = PdfFixture::pages([100, 200, 300, 400]);
        $path = 'uploads/'.Str::uuid().'.pdf';
        Storage::disk($private ? 'local' : 'public')->put($path, $contents);
        $attributes = ['user_id' => $owner?->id, 'file_name' => 'source.pdf', 'file_path' => $path, 'file_size' => strlen($contents)];

        return $personal ? UserUpload::create($attributes) : Upload::create([...$attributes, 'view_only' => $private, 'expired_at' => now()->addHour()]);
    }

    private function local(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('local.pdf', PdfFixture::pages([100, 200, 300, 400]));
    }

    private function widths(string $path): array
    {
        $json = json_decode((new Process(['qpdf', '--json', $path]))->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($page) => $json['qpdf'][1]['obj:'.$page['object']]['value']['/MediaBox'][2], $json['pages']);
    }
}
