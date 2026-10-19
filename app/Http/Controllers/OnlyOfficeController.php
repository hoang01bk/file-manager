<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\UserUpload;
use App\Services\OnlyOfficeToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class OnlyOfficeController extends Controller
{
    public function show(Request $request, string $scope, int $id, OnlyOfficeToken $tokens)
    {
        $file = $this->file($scope, $id);
        $canEdit = $file->canEditOffice($request->user()?->id);
        if ($scope === 'personal') {
            abort_unless($canEdit, 403);
        }
        $this->assertAvailable($file);
        $editing = $request->boolean('edit');
        abort_if($editing && ! $canEdit, 403, 'File này không cho phép bạn chỉnh sửa.');
        $extension = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['docx', 'xlsx'], true), 415, 'Chỉ hỗ trợ DOCX và XLSX.');
        abort_if(Storage::disk($this->disk($file))->size($file->file_path) > config('onlyoffice.max_file_size'), 413, 'File vượt quá giới hạn 100 MB.');

        // A stable key shares one editing session; a final save rotates it.
        $key = DB::transaction(function () use ($scope, $id) {
            $file = $this->file($scope, $id, true);
            if (! $file->office_key) {
                $file->office_key = (string) Str::uuid();
                $file->save();
            }

            return $file->office_key;
        });
        $expires = now()->addHours(config('onlyoffice.session_hours'));
        if ($file->expired_at && $file->expired_at->lt($expires)) {
            $expires = $file->expired_at;
        }
        $parameters = ['scope' => $scope, 'id' => $id, 'key' => $key];
        $download = $canEdit || ! $file->view_only;
        $config = [
            'documentType' => $extension === 'xlsx' ? 'cell' : 'word',
            'type' => 'desktop',
            'width' => '100%',
            'height' => '100%',
            'document' => [
                'fileType' => $extension,
                'key' => $key,
                'title' => $file->file_name,
                'url' => $this->storageUrl('office.content', $parameters + ($scope === 'personal' ? ['viewer' => $request->user()?->id ?? 'guest'] : []), $expires),
                'permissions' => ['edit' => $editing, 'download' => $download, 'print' => $download, 'copy' => $download],
            ],
            'editorConfig' => [
                'mode' => $editing ? 'edit' : 'view',
                'lang' => 'vi',
                'user' => ['id' => $request->user() ? (string) $request->user()->id : 'guest-'.hash_hmac('sha256', $request->session()->getId(), config('app.key')), 'name' => $request->user()?->name ?? 'Khách'],
                'callbackUrl' => $this->storageUrl('office.callback', $parameters + ['editor' => $editing ? ($request->user()?->id ?? 'guest') : 0], $expires),
                'customization' => ['forcesave' => true, 'autosave' => true],
            ],
        ];
        $config['token'] = $tokens->encode($config);

        return Inertia::render('OfficeEditor', [
            'fileName' => $file->file_name,
            'editorConfig' => $config,
            'scriptUrl' => rtrim(config('onlyoffice.public_url'), '/').'/web-apps/apps/api/documents/api.js',
            'backUrl' => $scope === 'personal' && $request->user() ? '/my-files' : '/',
            'editing' => $editing,
            'editUrl' => $canEdit && ! $editing ? route('office.show', ['scope' => $scope, 'id' => $id, 'edit' => 1], absolute: false) : null,
            'expiresAt' => $expires->toIso8601String(),
        ]);
    }

    public function content(Request $request, string $scope, int $id)
    {
        $file = $this->file($scope, $id);
        $this->assertAvailable($file);
        abort_unless($file->office_key && hash_equals($file->office_key, (string) $request->query('key')), 403);
        if ($scope === 'personal') {
            $viewer = (string) $request->query('viewer');
            $allowed = $viewer === 'guest'
                ? $file->canEditOffice(null)
                : ctype_digit($viewer) && (int) $viewer > 0 && $file->canEditOffice((int) $viewer);
            abort_unless($allowed, 403);
        }

        return response()->file(Storage::disk($this->disk($file))->path($file->file_path), ['Cache-Control' => 'no-store'])->setPrivate();
    }

    public function callback(Request $request, string $scope, int $id, OnlyOfficeToken $tokens)
    {
        // Use only signed JWT claims, never the unauthenticated request fields.
        $claims = $tokens->decode((string) ($request->input('token') ?: $request->bearerToken()));
        $data = $claims['payload'] ?? $claims;
        abort_unless(is_array($data) && isset($data['key'], $data['status']), 403);
        abort_unless(is_string($data['key']) && hash_equals((string) $request->query('key'), $data['key']), 403);
        if (! in_array($data['status'], [2, 6], true)) {
            if (in_array($data['status'], [3, 7], true)) {
                logger()->warning('ONLYOFFICE reported a save error.', ['scope' => $scope, 'id' => $id, 'status' => $data['status']]);
            }

            return response()->json(['error' => 0]);
        }
        $file = $this->file($scope, $id);
        $this->assertAvailable($file);
        $this->assertEditor($request, $file);
        if ($file->office_key !== $data['key']) {
            return response()->json(['error' => 0]); // A retried callback from an already finalized session.
        }

        $temporary = null;
        try {
            $url = $this->downloadUrl($data['url'] ?? '');
            $temporary = tempnam(storage_path('app'), 'office-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot create temporary document.');
            }
            $response = Http::timeout(90)->connectTimeout(10)->withOptions([
                'allow_redirects' => false,
                'sink' => $temporary,
                'progress' => function ($total, $downloaded) {
                    if (max($total, $downloaded) > config('onlyoffice.max_file_size')) {
                        throw new RuntimeException('Document exceeds the configured size limit.');
                    }
                },
            ])->get($url)->throw();
            if (! $response->successful()) {
                throw new RuntimeException('Document download did not succeed.');
            }
            if (! filesize($temporary) || filesize($temporary) > config('onlyoffice.max_file_size')) {
                throw new RuntimeException('Invalid document size.');
            }

            DB::transaction(function () use ($request, $scope, $id, $data, $temporary) {
                $file = $this->file($scope, $id, true);
                $this->assertAvailable($file);
                $this->assertEditor($request, $file);
                if ($file->office_key !== $data['key']) {
                    return;
                }
                // Filesystem::replace writes to a sibling temporary file and renames atomically.
                app('files')->replace(Storage::disk($this->disk($file))->path($file->file_path), file_get_contents($temporary));
                if ($file instanceof UserUpload) {
                    $file->file_size = filesize($temporary);
                }
                if ($data['status'] === 2) {
                    $file->office_key = (string) Str::uuid();
                }
                $file->save();
            });

            return response()->json(['error' => 0]);
        } catch (Throwable $exception) {
            // Do not log URLs/tokens, which grant access to document contents.
            logger()->error('ONLYOFFICE save failed.', ['scope' => $scope, 'id' => $id, 'exception' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine()]);

            return response()->json(['error' => 1], 500);
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function file(string $scope, int $id, bool $lock = false): Upload|UserUpload
    {
        abort_unless(in_array($scope, ['shared', 'personal'], true), 404);
        $query = $scope === 'shared' ? Upload::query() : UserUpload::query();

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    private function assertAvailable(Upload|UserUpload $file): void
    {
        abort_if($file->expired_at && $file->expired_at->isPast(), 410, 'File đã hết hạn.');
        abort_unless(Storage::disk($this->disk($file))->exists($file->file_path), 404);
    }

    private function assertEditor(Request $request, Upload|UserUpload $file): void
    {
        // The signed callback URL carries the permission granted when the editor opened.
        $editor = (string) $request->query('editor');
        $allowed = $editor === 'guest'
            ? $file->canEditOffice(null)
            : ctype_digit($editor) && (int) $editor > 0 && $file->canEditOffice((int) $editor);
        abort_unless($allowed, 403);
    }

    private function disk(Upload|UserUpload $file): string
    {
        return $file instanceof Upload && $file->view_only ? 'local' : 'public';
    }

    private function storageUrl(string $route, array $parameters, $expires): string
    {
        return rtrim(config('onlyoffice.storage_url'), '/').URL::temporarySignedRoute($route, $expires, $parameters, absolute: false);
    }

    private function downloadUrl(mixed $url): string
    {
        abort_unless(is_string($url) && filter_var($url, FILTER_VALIDATE_URL), 403);
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        $publicPath = parse_url(config('onlyoffice.public_url'), PHP_URL_PATH) ?: '';
        if ($publicPath && str_starts_with($path, rtrim($publicPath, '/').'/')) {
            $path = substr($path, strlen(rtrim($publicPath, '/')));
        }
        // Fetch from the configured server only. Never follow callback URLs to arbitrary hosts.
        abort_unless(str_starts_with($path, '/cache/files/') && ! preg_match('/[\\\\]|\.\.|%2e|%2f|%5c/i', $path), 403);

        return rtrim(config('onlyoffice.internal_url'), '/').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
