<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UploadQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
        config([
            'upload_limits.max_file_size' => 2 * 1024,
            'upload_limits.user_quota' => 4 * 1024,
            'upload_limits.guest_quota' => 4 * 1024,
        ]);
    }

    public function test_uploads_over_the_file_limit_are_rejected_in_both_areas(): void
    {
        $this->post('/upload', ['file' => UploadedFile::fake()->create('too-big.txt', 3), 'ttl_minutes' => 60])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->createUser())->post('/my-files/upload', [
            'file' => UploadedFile::fake()->create('too-big.txt', 3),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('uploads', 0);
        $this->assertDatabaseCount('user_uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_user_quota_counts_owned_shared_and_personal_files_and_delete_frees_space(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->actingAs($owner)->post('/upload', [
            'file' => UploadedFile::fake()->create('shared.txt', 2), 'ttl_minutes' => 60,
        ])->assertSessionHasNoErrors();
        $shared = Upload::firstOrFail();
        $this->post('/my-files/upload', ['file' => UploadedFile::fake()->create('personal.txt', 2)])
            ->assertSessionHasNoErrors();
        $personal = UserUpload::firstOrFail();
        $this->assertSame(2048, $shared->file_size);
        $this->assertSame(2048, $personal->file_size);

        $this->get('/my-files')->assertInertia(fn (Assert $page) => $page
            ->where('storageQuota.used', 4096)->where('storageQuota.limit', 4096)->etc());
        $this->post('/upload', [
            'file' => UploadedFile::fake()->create('overflow.txt', 1), 'ttl_minutes' => 60,
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('uploads', 1);

        // Viewing another person's file does not charge that person's account.
        $this->actingAs($other)->get('/my-files')->assertInertia(fn (Assert $page) => $page
            ->where('storageQuota.used', 0)->etc());
        $this->actingAs($owner)->delete("/files/{$shared->id}")->assertRedirect();
        $this->post('/upload', [
            'file' => UploadedFile::fake()->create('replacement.txt', 2), 'ttl_minutes' => 60,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('uploads', 1);
    }

    public function test_guest_uploads_share_a_separate_pool_and_expiry_cleanup_frees_it(): void
    {
        $this->post('/upload', ['file' => UploadedFile::fake()->create('guest.txt', 2), 'ttl_minutes' => 60])
            ->assertSessionHasNoErrors();
        $guestFile = Upload::firstOrFail();
        $this->post('/upload', ['file' => UploadedFile::fake()->create('guest-2.txt', 2), 'ttl_minutes' => 60])
            ->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('storageQuota.used', 4096)->where('storageQuota.guest', true)->etc());
        $this->post('/upload', ['file' => UploadedFile::fake()->create('blocked.txt', 1), 'ttl_minutes' => 60])
            ->assertSessionHasErrors('file');

        $guestFile->update(['expired_at' => now()->subMinute()]);
        $this->artisan('app:clean-expired-files')->assertSuccessful();
        $this->assertDatabaseMissing('uploads', ['id' => $guestFile->id]);
        $this->post('/upload', ['file' => UploadedFile::fake()->create('after-cleanup.txt', 2), 'ttl_minutes' => 60])
            ->assertSessionHasNoErrors();
    }

    private function createUser(): User
    {
        return User::factory()->create(['employee_code' => 'QUOTA-'.uniqid()]);
    }
}
