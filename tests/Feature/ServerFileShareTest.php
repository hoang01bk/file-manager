<?php

namespace Tests\Feature;

use App\Services\ServerFileShare;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

class ServerFileShareTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/server-files-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->directory.'/shared/folder', 0755, true);
        File::put($this->directory.'/shared/folder/test file %.zip', '0123456789');
        File::put($this->directory.'/outside.txt', 'must not be shared');
        config([
            'server_files.root' => $this->directory.'/shared',
            'server_files.url' => 'https://download.pms-uvi.site',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_command_creates_an_expiring_link_and_php_only_hands_the_file_to_nginx(): void
    {
        $this->assertSame(0, Artisan::call('files:share', ['file' => 'folder/test file %.zip', '--hours' => 2]));
        $url = trim(Artisan::output());
        $this->assertStringStartsWith('https://download.pms-uvi.site/server-files/download?', $url);
        $this->assertStringNotContainsString('test%20file', $url);
        $response = $this->get($url)->assertOk()
            ->assertHeader('X-Accel-Redirect', '/_server-files/folder/test%20file%20%25.zip')
            ->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString("filename*=utf-8''test%20file%20%25.zip", $response->headers->get('Content-Disposition'));
        $this->assertSame('', $response->getContent());
        $this->head($url)->assertOk()->assertHeader('X-Accel-Redirect');
        $this->assertFileExists($this->directory.'/shared/folder/test file %.zip');
    }

    public function test_expiry_and_signature_tampering_deny_download_without_deleting_the_original(): void
    {
        $url = app(ServerFileShare::class)->url('folder/test file %.zip', now()->addHour());
        $this->get($url.'&extra=1')->assertForbidden();
        $this->travel(2)->hours();
        $this->get($url)->assertForbidden();
        $this->assertSame('0123456789', File::get($this->directory.'/shared/folder/test file %.zip'));
    }

    public function test_link_cannot_be_served_through_the_website_origin(): void
    {
        $url = app(ServerFileShare::class)->url('folder/test file %.zip', now()->addHour());
        $this->get(str_replace('https://download.pms-uvi.site', 'https://pms-uvi.site', $url))->assertNotFound();
        $this->get(str_replace('https:', 'http:', $url))->assertNotFound();
    }

    public function test_missing_or_changed_file_invalidates_the_link(): void
    {
        $url = app(ServerFileShare::class)->url('folder/test file %.zip', now()->addHour());
        File::put($this->directory.'/shared/folder/test file %.zip', 'replacement version');
        $this->get($url)->assertGone();
        File::delete($this->directory.'/shared/folder/test file %.zip');
        $this->get($url)->assertNotFound();
    }

    public function test_traversal_absolute_paths_and_directories_cannot_be_shared(): void
    {
        foreach (['../outside.txt', 'folder/../../outside.txt', '/outside.txt', 'D:\\outside.txt', 'folder', "folder/\0test"] as $path) {
            try {
                app(ServerFileShare::class)->resolve($path);
                $this->fail('Unsafe path was accepted: '.$path);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $url = $this->signed(['path' => '../outside.txt', 'size' => 18, 'modified' => time()]);
        $this->get($url)->assertNotFound();
    }

    public function test_invalid_encrypted_payloads_are_rejected(): void
    {
        foreach ([null, [], ['path' => []]] as $payload) {
            $this->get($this->signed($payload))->assertNotFound();
        }
        $url = config('server_files.url').URL::temporarySignedRoute(
            'server-files.download', now()->addHour(), ['file' => 'broken-token'], absolute: false,
        );
        $this->get($url)->assertNotFound();
    }

    public function test_command_rejects_invalid_expiration_and_missing_files(): void
    {
        foreach (['0', '-1', '1.5', '721', 'invalid'] as $hours) {
            $this->artisan('files:share', ['file' => 'folder/test file %.zip', '--hours' => $hours])->assertFailed();
        }
        $this->artisan('files:share', ['file' => 'missing.zip'])->assertFailed();
    }

    public function test_symlinks_cannot_escape_the_shared_directory(): void
    {
        if (! @symlink($this->directory.'/outside.txt', $this->directory.'/shared/link.txt')) {
            $this->markTestSkipped('Creating symlinks requires privileges on this platform.');
        }
        $this->expectException(InvalidArgumentException::class);
        app(ServerFileShare::class)->resolve('link.txt');
    }

    private function signed(mixed $payload): string
    {
        return config('server_files.url').URL::temporarySignedRoute(
            'server-files.download', now()->addHour(),
            ['file' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))], absolute: false,
        );
    }
}
