<?php

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FileFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_shared_folders_can_be_created_browsed_and_uploaded_into(): void
    {
        $parent = $this->postJson('/folders/shared', ['name' => 'Tài liệu'])->assertCreated()->json('id');
        $child = $this->postJson('/folders/shared', ['name' => 'Hợp đồng', 'parent_id' => $parent])->assertCreated()->json('id');
        $this->postJson('/upload', ['file' => UploadedFile::fake()->create('contract.txt', 1), 'ttl_minutes' => 60, 'folder_id' => $child])->assertCreated();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('folders', 1)->where('files.total', 0)->etc());
        $this->get('/?folder='.$parent)->assertInertia(fn (Assert $page) => $page->where('folders.0.id', $child)->where('files.total', 0)->etc());
        $this->get('/?folder='.$child)->assertInertia(fn (Assert $page) => $page
            ->where('currentFolder.id', $child)->where('files.total', 1)->has('folderBreadcrumbs', 2)
            ->where('folderBreadcrumbs.0.name', 'Tài liệu')->where('files.data.0.file_name', 'contract.txt')->etc());
        Storage::disk('public')->assertExists(Upload::firstOrFail()->file_path);
    }

    public function test_directory_upload_reuses_the_hierarchy_and_keeps_file_names(): void
    {
        $parent = $this->postJson('/folders/shared', ['name' => 'Destination'])->json('id');
        foreach (['Batch/a.txt', 'Batch/Sub/b.txt', 'Batch/Sub/c.txt'] as $path) {
            $this->postJson('/upload', [
                'file' => UploadedFile::fake()->create(basename($path), 1), 'ttl_minutes' => 60,
                'folder_id' => $parent, 'relative_path' => $path,
            ])->assertCreated();
        }
        $this->assertDatabaseCount('file_folders', 3);
        $batch = FileFolder::where('name', 'Batch')->firstOrFail();
        $sub = FileFolder::where('name', 'Sub')->firstOrFail();
        $this->assertEquals($parent, $batch->parent_id);
        $this->assertEquals($batch->id, $sub->parent_id);
        $this->assertDatabaseHas('uploads', ['file_name' => 'a.txt', 'folder_id' => $batch->id]);
        $this->assertDatabaseHas('uploads', ['file_name' => 'b.txt', 'folder_id' => $sub->id]);
        $this->assertDatabaseHas('uploads', ['file_name' => 'c.txt', 'folder_id' => $sub->id]);
    }

    public function test_personal_folders_are_private_but_individually_shared_files_remain_accessible(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $parent = $this->actingAs($owner)->postJson('/folders/personal', ['name' => 'Private'])->assertCreated()->json('id');
        $this->postJson('/my-files/upload', [
            'file' => UploadedFile::fake()->create('shared.txt', 1), 'folder_id' => $parent,
            'relative_path' => 'Inner/shared.txt', 'editor_ids' => [$viewer->id],
        ])->assertCreated();
        $file = UserUpload::firstOrFail();
        $this->get('/my-files')->assertInertia(fn (Assert $page) => $page->has('files', 0)->has('folders', 1)->etc());
        $this->get('/my-files?folder='.$file->folder_id)->assertInertia(fn (Assert $page) => $page->has('files', 1)->has('folderBreadcrumbs', 2)->etc());
        $this->actingAs($viewer)->get('/my-files?folder='.$parent)->assertNotFound();
        $this->postJson('/my-files/upload', ['file' => UploadedFile::fake()->create('intruder.txt', 1), 'folder_id' => $parent])->assertNotFound();
        $this->postJson('/folders/personal', ['name' => 'Intruder', 'parent_id' => $parent])->assertNotFound();
        $this->get('/my-files')->assertInertia(fn (Assert $page) => $page->has('folders', 0)->has('files', 1)->where('files.0.id', $file->id)->etc());
        $this->get('/my-files/'.$file->id.'/preview')->assertRedirect();
    }

    public function test_invalid_relative_paths_are_rejected_without_creating_folders_or_files(): void
    {
        foreach (['Batch/../bad.txt', '/Batch/bad.txt', 'Batch//bad.txt', 'Batch/..\\bad.txt', 'bad.txt', str_repeat('a/', 21).'bad.txt'] as $path) {
            $this->postJson('/upload', ['file' => UploadedFile::fake()->create('bad.txt', 1), 'ttl_minutes' => 60, 'relative_path' => $path])->assertUnprocessable();
        }
        $this->assertDatabaseCount('file_folders', 0);
        $this->assertDatabaseCount('uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_duplicate_or_invalid_folder_names_and_cross_scope_parents_are_rejected(): void
    {
        $id = $this->postJson('/folders/shared', ['name' => 'Reports'])->assertCreated()->json('id');
        $this->postJson('/folders/shared', ['name' => 'Reports'])->assertUnprocessable()->assertJsonValidationErrors('name');
        foreach (['..', '.', 'a/b', 'a\\b', '   '] as $name) {
            $this->postJson('/folders/shared', ['name' => $name])->assertUnprocessable();
        }
        $this->actingAs($this->createUser())->postJson('/folders/personal', ['name' => 'Bad', 'parent_id' => $id])->assertNotFound();
        $this->postJson('/my-files/upload', ['file' => UploadedFile::fake()->create('bad.txt', 1), 'folder_id' => $id])->assertNotFound();
    }

    public function test_folder_upload_still_enforces_quota_and_file_permissions(): void
    {
        config(['upload_limits.user_quota' => 1024]);
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $this->actingAs($owner)->postJson('/upload', [
            'file' => UploadedFile::fake()->create('one.txt', 1), 'ttl_minutes' => 60,
            'relative_path' => 'Batch/one.txt', 'view_only' => true, 'editor_ids' => [$viewer->id],
        ])->assertCreated();
        $this->postJson('/upload', [
            'file' => UploadedFile::fake()->create('two.txt', 1), 'ttl_minutes' => 60, 'relative_path' => 'Other/two.txt',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('file_folders', 1);
        $this->assertDatabaseCount('uploads', 1);
        $file = Upload::firstOrFail();
        $this->assertTrue($file->view_only);
        $this->assertTrue($file->canEditOffice($viewer->id));
        Storage::disk('local')->assertExists($file->file_path);
    }

    public function test_only_owners_can_delete_empty_folders_and_nonempty_folders_are_preserved(): void
    {
        $owner = $this->createUser();
        $id = $this->actingAs($owner)->postJson('/folders/shared', ['name' => 'Keep'])->json('id');
        $child = $this->postJson('/folders/shared', ['name' => 'Child', 'parent_id' => $id])->json('id');
        $this->deleteJson('/folders/shared/'.$id)->assertUnprocessable()->assertJsonValidationErrors('folder');
        $this->actingAs($this->createUser())->deleteJson('/folders/shared/'.$child)->assertForbidden();
        $this->actingAs($owner)->delete('/folders/shared/'.$child)->assertRedirect();
        $this->postJson('/upload', ['file' => UploadedFile::fake()->create('keep.txt', 1), 'ttl_minutes' => 60, 'folder_id' => $id])->assertCreated();
        $this->deleteJson('/folders/shared/'.$id)->assertUnprocessable();
        $this->delete('/files/'.Upload::firstOrFail()->id)->assertRedirect();
        $this->delete('/folders/shared/'.$id)->assertRedirect();
        $this->assertDatabaseCount('file_folders', 0);
    }

    public function test_personal_folder_routes_require_an_authenticated_user(): void
    {
        $this->postJson('/folders/personal', ['name' => 'Private'])->assertForbidden();
        $this->get('/my-files?folder=1')->assertRedirect('/login');
        $this->assertDatabaseCount('file_folders', 0);
    }

    public function test_existing_shared_files_can_be_moved_without_changing_content_permissions_or_quota(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $destination = $this->actingAs($owner)->postJson('/folders/shared', ['name' => 'Destination'])->json('id');
        $this->postJson('/upload', [
            'file' => UploadedFile::fake()->create('move.txt', 1), 'ttl_minutes' => 60, 'view_only' => true, 'editor_ids' => [$viewer->id],
        ])->assertCreated();
        $file = Upload::firstOrFail();
        $before = $file->getAttributes();
        $this->getJson("/folders/shared/{$destination}/files?q=move")->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $file->id);
        $this->postJson("/folders/shared/{$destination}/files", ['file_ids' => [$file->id]])->assertOk()->assertJsonPath('moved', 1);
        $after = $file->fresh()->getAttributes();
        unset($before['folder_id'], $after['folder_id']);
        $this->assertSame($before, $after);
        $this->assertEquals($destination, $file->fresh()->folder_id);
        $this->assertTrue($file->fresh()->canEditOffice($viewer->id));
        Storage::disk('local')->assertExists($file->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->getJson("/folders/shared/{$destination}/files")->assertJsonPath('total', 0);
        $this->get('/?folder='.$destination)->assertInertia(fn (Assert $page) => $page->where('files.total', 1)->where('storageQuota.used', 1024)->etc());
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('files.total', 0)->etc());
    }

    public function test_available_files_support_search_pagination_and_hide_other_users_files(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $destination = $this->actingAs($owner)->postJson('/folders/shared', ['name' => 'Destination'])->json('id');
        for ($index = 1; $index <= 13; $index++) {
            Upload::create(['user_id' => $owner->id, 'file_name' => "report-{$index}.txt", 'file_path' => "uploads/report-{$index}.txt", 'expired_at' => now()->addHour()]);
        }
        Upload::create(['user_id' => $other->id, 'file_name' => 'private-owner.txt', 'file_path' => 'uploads/other.txt', 'expired_at' => now()->addHour(), 'all_users_can_edit' => true]);
        $anonymous = Upload::create(['file_name' => 'anonymous.txt', 'file_path' => 'uploads/anonymous.txt', 'expired_at' => now()->subHour()]);
        $this->getJson("/folders/shared/{$destination}/files?q=report")->assertOk()->assertJsonPath('total', 13)->assertJsonCount(10, 'data');
        $this->getJson("/folders/shared/{$destination}/files?q=report&page=2")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/folders/shared/{$destination}/files?q=private-owner")->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/folders/shared/{$destination}/files?q=anonymous")->assertOk()->assertJsonPath('data.0.id', $anonymous->id);
    }

    public function test_moving_an_unauthorized_or_missing_file_rolls_back_the_whole_selection(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $destination = $this->actingAs($owner)->postJson('/folders/shared', ['name' => 'Destination'])->json('id');
        $own = Upload::create(['user_id' => $owner->id, 'file_name' => 'own.txt', 'file_path' => 'uploads/own.txt', 'expired_at' => now()->addHour()]);
        $foreign = Upload::create(['user_id' => $other->id, 'file_name' => 'other.txt', 'file_path' => 'uploads/other.txt', 'expired_at' => now()->addHour(), 'all_users_can_edit' => true]);
        $this->postJson("/folders/shared/{$destination}/files", ['file_ids' => [$own->id, $foreign->id]])->assertForbidden();
        $this->postJson("/folders/shared/{$destination}/files", ['file_ids' => [$own->id, 999999]])->assertForbidden();
        $this->postJson("/folders/shared/{$destination}/files", ['file_ids' => [$own->id, $own->id]])->assertUnprocessable();
        $this->postJson("/folders/shared/{$destination}/files", ['file_ids' => []])->assertUnprocessable();
        $this->assertNull($own->fresh()->folder_id);
        $this->assertNull($foreign->fresh()->folder_id);
    }

    public function test_personal_file_picker_and_moves_require_ownership_of_both_files_and_destination(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $destination = $this->actingAs($owner)->postJson('/folders/personal', ['name' => 'Owner folder'])->json('id');
        $this->postJson('/my-files/upload', ['file' => UploadedFile::fake()->create('owned.txt', 1), 'editor_ids' => [$viewer->id]])->assertCreated();
        $owned = UserUpload::firstOrFail();
        $this->getJson("/folders/personal/{$destination}/files")->assertOk()->assertJsonPath('data.0.id', $owned->id);
        $viewerDestination = $this->actingAs($viewer)->postJson('/folders/personal', ['name' => 'Viewer folder'])->json('id');
        $this->getJson("/folders/personal/{$viewerDestination}/files")->assertOk()->assertJsonPath('total', 0);
        $this->postJson("/folders/personal/{$viewerDestination}/files", ['file_ids' => [$owned->id]])->assertForbidden();
        $this->getJson("/folders/personal/{$destination}/files")->assertNotFound();
        $this->postJson("/folders/personal/{$destination}/files", ['file_ids' => [$owned->id]])->assertNotFound();
        $this->actingAs($owner)->postJson("/folders/personal/{$destination}/files", ['file_ids' => [$owned->id]])->assertOk();
        $this->assertEquals($destination, $owned->fresh()->folder_id);
    }

    private function createUser(): User
    {
        return User::factory()->create(['employee_code' => uniqid('FOLDER-')]);
    }
}
