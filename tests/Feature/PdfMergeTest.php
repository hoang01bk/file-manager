<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\MergeUploadedPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class PdfMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_owner_can_create_a_shared_pdf_with_pages_in_order_and_original_unchanged(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner, private: true);
        $source->editors()->attach($editor);
        $original = Storage::disk('local')->get($source->file_path);

        $response = $this->actingAs($owner)->postJson('/pdf/shared/'.$source->id.'/merge', [
            'name' => 'Kết quả', 'pdfs' => [$this->addition(200), $this->addition(300)],
        ])->assertCreated();
        $copy = Upload::findOrFail($response->json('id'));
        $this->assertNotSame($source->id, $copy->id);
        $this->assertNotSame($source->file_path, $copy->file_path);
        $this->assertSame('Kết quả.pdf', $copy->file_name);
        $this->assertSame($owner->id, $copy->user_id);
        $this->assertTrue($copy->view_only);
        $this->assertTrue($copy->editors()->whereKey($editor->id)->exists());
        $this->assertTrue($copy->expired_at->equalTo($source->expired_at));
        $this->assertSame($original, Storage::disk('local')->get($source->file_path));
        $this->assertSame(strlen($original), (int) $source->fresh()->file_size);
        $this->assertSame(Storage::disk('local')->size($copy->file_path), (int) $copy->file_size);
        $this->assertSame([100, 200, 300], $this->widths(Storage::disk('local')->path($copy->file_path)));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_editor_creates_personal_copy_in_their_own_quota_and_preserves_original_access(): void
    {
        $owner = $this->user();
        $editor = $this->user();
        $source = $this->source($owner, personal: true);
        $source->editors()->attach($editor);
        config(['upload_limits.user_quota' => 2000]);
        // The original owner's remaining quota must not prevent a new file owned by the editor.
        $source->update(['file_size' => 1900]);

        $response = $this->actingAs($editor)->postJson('/pdf/personal/'.$source->id.'/merge', [
            'name' => 'Merged.pdf', 'pdfs' => [$this->addition(200)],
        ])->assertCreated();
        $copy = UserUpload::findOrFail($response->json('id'));
        $this->assertSame($editor->id, $copy->user_id);
        $this->assertStringStartsWith('user-uploads/'.$editor->id.'/', $copy->file_path);
        $this->assertTrue($copy->canEditOffice($owner->id));
        $this->assertTrue($copy->canEditOffice($editor->id));
        $this->assertFalse($copy->canEditOffice($this->user()->id));
        $this->assertNull($copy->expired_at);
        $this->assertSame(1900, (int) $source->fresh()->file_size);
        $this->assertSame([100, 200], $this->widths(Storage::disk('public')->path($copy->file_path)));
    }

    public function test_guest_can_merge_an_anonymous_shared_pdf_and_all_users_flag_is_preserved(): void
    {
        $source = $this->source();
        $response = $this->postJson('/pdf/shared/'.$source->id.'/merge', [
            'name' => 'Guest.pdf', 'pdfs' => [$this->addition()],
        ])->assertCreated();
        $copy = Upload::findOrFail($response->json('id'));
        $this->assertNull($copy->user_id);
        $this->assertTrue($copy->canEditOffice(null));

        $owner = $this->user();
        $personal = $this->source($owner, personal: true);
        $personal->update(['all_users_can_edit' => true]);
        $response = $this->actingAs($owner)->postJson('/pdf/personal/'.$personal->id.'/merge', [
            'name' => 'Everyone.pdf', 'pdfs' => [$this->addition()],
        ])->assertCreated();
        $this->assertTrue(UserUpload::findOrFail($response->json('id'))->all_users_can_edit);
    }

    public function test_other_users_and_guests_cannot_merge_restricted_files(): void
    {
        $owner = $this->user();
        $shared = $this->source($owner);
        $personal = $this->source($owner, personal: true);
        foreach (['shared' => $shared, 'personal' => $personal] as $scope => $file) {
            $this->postJson('/pdf/'.$scope.'/'.$file->id.'/merge', ['name' => 'Denied', 'pdfs' => [$this->addition()]])->assertForbidden();
        }
        $this->actingAs($this->user());
        foreach (['shared' => $shared, 'personal' => $personal] as $scope => $file) {
            $this->postJson('/pdf/'.$scope.'/'.$file->id.'/merge', ['name' => 'Denied', 'pdfs' => [$this->addition()]])->assertForbidden();
        }
        $this->assertDatabaseCount('uploads', 1);
        $this->assertDatabaseCount('user_uploads', 1);
    }

    public function test_expired_missing_and_non_pdf_sources_are_rejected(): void
    {
        $source = $this->source();
        $source->update(['expired_at' => now()->subMinute()]);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', ['name' => 'New', 'pdfs' => [$this->addition()]])->assertGone();
        $source->update(['expired_at' => now()->addHour(), 'file_name' => 'image.png']);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', ['name' => 'New', 'pdfs' => [$this->addition()]])->assertStatus(415);
        $source->update(['file_name' => 'source.pdf']);
        Storage::disk('public')->delete($source->file_path);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', ['name' => 'New', 'pdfs' => [$this->addition()]])->assertNotFound();
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_invalid_inputs_and_broken_pdf_do_not_change_the_original(): void
    {
        $source = $this->source();
        foreach ([
            ['name' => '../unsafe.pdf', 'pdfs' => [$this->addition()]],
            ['name' => '   ', 'pdfs' => [$this->addition()]],
            ['name' => 'New', 'pdfs' => []],
            ['name' => 'New', 'pdfs' => array_map(fn () => $this->addition(), range(1, 6))],
            ['name' => 'New', 'pdfs' => [UploadedFile::fake()->createWithContent('wrong.txt', 'not a pdf')]],
            ['name' => 'New', 'pdfs' => [UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\ngarbage")]],
        ] as $input) {
            $this->postJson('/pdf/shared/'.$source->id.'/merge', $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
        $this->assertSame(PdfFixture::page(), Storage::disk('public')->get($source->file_path));
    }

    public function test_encrypted_pdf_is_rejected_without_creating_a_copy(): void
    {
        $source = $this->source();
        $input = Storage::disk('public')->path($source->file_path);
        $encrypted = $input.'.encrypted';
        try {
            (new Process(['qpdf', '--encrypt', 'secret', 'owner-secret', '256', '--', $input, $encrypted]))->mustRun();
            $this->postJson('/pdf/shared/'.$source->id.'/merge', [
                'name' => 'Protected', 'pdfs' => [UploadedFile::fake()->createWithContent('protected.pdf', file_get_contents($encrypted))],
            ])->assertUnprocessable()->assertJsonValidationErrors('pdfs');
            $this->assertDatabaseCount('uploads', 1);
            $this->assertSame(PdfFixture::page(), file_get_contents($input));
        } finally {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }
    }

    public function test_quota_and_output_size_limits_reject_the_copy_without_leaving_files(): void
    {
        $source = $this->source();
        config(['upload_limits.guest_quota' => strlen(PdfFixture::page())]);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', [
            'name' => 'Quota', 'pdfs' => [$this->addition()],
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        // A legacy source can already exceed a newly lowered limit; additions still fit.
        $largeSource = PdfFixture::page(100, implode("\n", array_map(fn ($index) => '%'.hash('sha256', (string) $index), range(1, 100))));
        Storage::disk('public')->put($source->file_path, $largeSource);
        $source->update(['file_size' => strlen($largeSource)]);
        config(['upload_limits.guest_quota' => 1024 * 1024, 'upload_limits.max_file_size' => 1024]);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', [
            'name' => 'Too large', 'pdfs' => [$this->addition(200), $this->addition(300)],
        ])->assertUnprocessable()->assertJsonPath('errors.pdfs.0', 'PDF sau khi ghép không được vượt quá 100 MB.');
        $this->postJson('/pdf/shared/'.$source->id.'/merge', [
            'name' => 'Too many bytes', 'pdfs' => [$this->addition(200), $this->addition(300), $this->addition(400)],
        ])->assertUnprocessable()->assertJsonPath('errors.pdfs.0', 'Tổng dung lượng các PDF tải thêm không được vượt quá 100 MB.');
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
    }

    public function test_database_failure_removes_the_new_file_and_keeps_original(): void
    {
        $source = $this->source();
        $merger = app(MergeUploadedPdf::class);
        // Fail after the generated file and new database row have been written.
        Upload::created(function () {
            throw new \RuntimeException('Simulated database failure.');
        });
        try {
            $merger->create('shared', $source->id, null, 'Failure', [$this->addition()]);
            $this->fail('Expected the database failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database failure.', $exception->getMessage());
        } finally {
            Upload::flushEventListeners();
        }
        $this->assertDatabaseCount('uploads', 1);
        $this->assertSame([$source->file_path], Storage::disk('public')->allFiles());
        $this->assertSame(PdfFixture::page(), Storage::disk('public')->get($source->file_path));
    }

    public function test_missing_pdf_tool_returns_a_service_error_without_creating_a_copy(): void
    {
        $source = $this->source();
        config(['pdf_merge.binary' => 'missing-qpdf-tool-for-test']);
        $this->postJson('/pdf/shared/'.$source->id.'/merge', ['name' => 'New', 'pdfs' => [$this->addition()]])->assertStatus(503);
        $this->assertDatabaseCount('uploads', 1);
    }

    private function user(): User
    {
        return User::factory()->create(['employee_code' => 'PDF-'.Str::uuid()]);
    }

    private function source(?User $owner = null, bool $personal = false, bool $private = false): Upload|UserUpload
    {
        $path = 'uploads/'.Str::uuid().'.pdf';
        Storage::disk($private ? 'local' : 'public')->put($path, PdfFixture::page());
        $attributes = ['user_id' => $owner?->id, 'file_name' => 'source.pdf', 'file_path' => $path, 'file_size' => strlen(PdfFixture::page())];

        return $personal ? UserUpload::create($attributes) : Upload::create([...$attributes, 'view_only' => $private, 'expired_at' => now()->addHour()]);
    }

    private function addition(int $width = 200): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('extra.pdf', PdfFixture::page($width));
    }

    private function widths(string $path): array
    {
        $output = (new Process(['qpdf', '--json', $path]))->mustRun()->getOutput();
        $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn ($page) => $json['qpdf'][1]['obj:'.$page['object']]['value']['/MediaBox'][2], $json['pages']);
    }
}
