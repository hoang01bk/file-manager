<?php

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\OnlyOfficeToken;
use App\Services\UploadQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class CreateOfficeFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        config(['onlyoffice.jwt_secret' => str_repeat('office-create-test-', 3)]);
        Http::preventStrayRequests();
    }

    public function test_each_office_type_is_created_in_the_current_folder_with_a_valid_template(): void
    {
        $this->freezeTime();
        $owner = $this->createUser();
        $this->actingAs($owner);
        $expectedUsage = 0;
        foreach (['shared', 'personal'] as $scope) {
            $folder = FileFolder::create(['scope' => $scope, 'name' => 'Documents', 'user_id' => $owner->id]);
            foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'] as $type => $part) {
                $response = $this->postJson('/office/'.$scope.'/create', [
                    'type' => $type, 'name' => 'Báo cáo.'.$type, 'folder_id' => $folder->id,
                    ...($scope === 'shared' ? ['ttl_minutes' => 60] : []),
                ])->assertCreated();
                $model = $scope === 'shared' ? Upload::class : UserUpload::class;
                $file = $model::findOrFail($response->json('id'));
                $this->assertSame('Báo cáo.'.$type, $file->file_name);
                $this->assertEquals($folder->id, $file->folder_id);
                $this->assertEquals($owner->id, $file->user_id);
                $this->assertTrue($file->canEditOffice($owner->id));
                $this->assertEquals($scope === 'shared' ? now()->addHour()->timestamp : null, $file->expired_at?->timestamp);
                $response->assertJsonPath('edit_url', '/office/'.$scope.'/'.$file->id.'?edit=1');
                $template = file_get_contents(resource_path('office-templates/blank.'.$type));
                $this->assertSame($template, Storage::disk('public')->get($file->file_path));
                $this->assertSame(strlen($template), (int) $file->file_size);
                $zip = new ZipArchive;
                $this->assertTrue($zip->open(Storage::disk('public')->path($file->file_path)));
                $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
                $this->assertNotFalse($zip->getFromName($part));
                $zip->close();
                $expectedUsage += strlen($template);
                $this->assertSame($expectedUsage, app(UploadQuota::class)->used($owner->id));
                $this->get($response->json('edit_url'))->assertOk()->assertInertia(fn (Assert $page) => $page
                    ->where('editing', true)->where('editorConfig.document.fileType', $type)
                    ->where('editorConfig.documentType', ['docx' => 'word', 'xlsx' => 'cell', 'pptx' => 'slide'][$type])
                    ->where('backUrl', ($scope === 'personal' ? '/my-files' : '/').'?folder='.$folder->id)->etc());
            }
        }
        Http::assertNothingSent();
    }

    public function test_creation_requires_login_valid_names_and_expiry_for_shared_documents(): void
    {
        $this->postJson('/office/shared/create', ['type' => 'docx', 'name' => 'Guest', 'ttl_minutes' => 60])->assertUnauthorized();
        $this->actingAs($this->createUser());
        $this->postJson('/office/shared/create', ['type' => 'docx', 'name' => 'No expiry'])->assertUnprocessable()->assertJsonValidationErrors('ttl_minutes');
        foreach (['../outside', 'bad/name', 'bad\\name', '..', '.docx'] as $name) {
            $this->postJson('/office/personal/create', ['type' => 'docx', 'name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->postJson('/office/personal/create', ['type' => 'php', 'name' => 'No'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('uploads', 0);
        $this->assertDatabaseCount('user_uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_quota_and_personal_folder_ownership_are_enforced_before_writing_a_file(): void
    {
        $owner = $this->createUser();
        $folder = FileFolder::create(['scope' => 'personal', 'name' => 'Private', 'user_id' => $owner->id]);
        $this->actingAs($this->createUser())->postJson('/office/personal/create', ['type' => 'pptx', 'name' => 'No access', 'folder_id' => $folder->id])->assertNotFound();
        config(['upload_limits.user_quota' => 1]);
        $this->actingAs($owner)->postJson('/office/personal/create', ['type' => 'xlsx', 'name' => 'Over quota', 'folder_id' => $folder->id])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('user_uploads', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_new_powerpoint_documents_support_activity_and_authenticated_save_callbacks(): void
    {
        $owner = $this->createUser();
        $response = $this->actingAs($owner)->postJson('/office/personal/create', ['type' => 'pptx', 'name' => 'Presentation'])->assertCreated();
        $this->get($response->json('edit_url'))->assertOk();
        $file = UserUpload::findOrFail($response->json('id'));
        $this->getJson('/office/personal/'.$file->id.'/activities')->assertOk();
        $url = URL::temporarySignedRoute('office.callback', now()->addHour(), [
            'scope' => 'personal', 'id' => $file->id, 'key' => $file->office_key, 'editor' => $owner->id,
        ], absolute: false);
        $template = file_get_contents(resource_path('office-templates/blank.pptx'));
        $temporary = tempnam(sys_get_temp_dir(), 'pptx-save-');
        try {
            file_put_contents($temporary, $template);
            $zip = new ZipArchive;
            $zip->open($temporary);
            $zip->addFromString('ppt/presentation.xml', $zip->getFromName('ppt/presentation.xml')."\n<!-- saved -->");
            $zip->close();
            $template = file_get_contents($temporary);
        } finally {
            unlink($temporary);
        }
        Http::fake(function ($request, $options) use ($template) {
            file_put_contents($options['sink'], $template);

            return Http::response($template);
        });
        $token = app(OnlyOfficeToken::class)->encode([
            'key' => $file->office_key, 'status' => 6, 'users' => [(string) $owner->id],
            'url' => 'http://onlyoffice/cache/files/session/output.pptx',
        ]);
        $this->postJson($url, ['token' => $token])->assertOk()->assertJsonPath('error', 0);
        $this->assertSame($template, Storage::disk('public')->get($file->file_path));
        $this->assertDatabaseHas('file_activities', ['scope' => 'personal', 'file_id' => $file->id]);
    }

    private function createUser(): User
    {
        return User::factory()->create(['employee_code' => uniqid('CREATE-OFFICE-')]);
    }
}
