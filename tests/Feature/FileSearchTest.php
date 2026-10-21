<?php

namespace Tests\Feature;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FileSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_search_finds_files_outside_the_current_page_and_keeps_pagination_usable(): void
    {
        $this->withoutVite();
        foreach (['Monthly REPORT.docx', 'notes.txt', 'photo.png', 'data.xlsx', 'manual.pdf', 'meeting.docx'] as $name) {
            Upload::create(['file_name' => $name, 'file_path' => 'uploads/'.$name, 'expired_at' => now()->addHour()]);
        }
        $this->get('/?q=report&files_page=8')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'report')->where('files.total', 1)->where('files.current_page', 1)
            ->where('files.data.0.file_name', 'Monthly REPORT.docx')->etc());
        $this->get('/?q=no-match')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('files.total', 0)->has('files.data', 0)->etc());
    }

    public function test_personal_search_preserves_file_permissions_and_owner_quota(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['employee_code' => 'SEARCH-OWNER']);
        $viewer = User::factory()->create(['employee_code' => 'SEARCH-VIEWER']);
        foreach (['private report.docx', 'shared report.docx', 'unrelated.txt'] as $name) {
            $file = UserUpload::create(['user_id' => $owner->id, 'file_name' => $name, 'file_path' => 'user-uploads/'.$name, 'file_size' => 100]);
            if ($name === 'shared report.docx') {
                $file->editors()->attach($viewer);
            }
        }
        $this->actingAs($viewer)->get('/my-files?q=REPORT')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('files', 1)->where('files.0.file_name', 'shared report.docx')->where('storageQuota.used', 0)->etc());
        $this->actingAs($owner)->get('/my-files?q=REPORT')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('files', 2)->where('storageQuota.used', 300)->etc());
    }
}
