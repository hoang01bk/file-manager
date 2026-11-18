<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\UserUpload;
use App\Services\FileFolders;
use App\Services\UploadQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class CreateOfficeFileController extends Controller
{
    public function __invoke(Request $request, string $scope, FileFolders $folders, UploadQuota $quota)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['docx', 'xlsx', 'pptx'])],
            'name' => ['required', 'string', 'max:250'],
            'folder_id' => ['nullable', 'integer', 'min:1'],
            'ttl_minutes' => [Rule::requiredIf($scope === 'shared'), 'integer', 'min:1'],
        ]);
        $extension = $data['type'];
        $name = trim($data['name']);
        if (str_ends_with(strtolower($name), '.'.$extension)) {
            $name = substr($name, 0, -strlen($extension) - 1);
        }
        $name = $folders->validateName($name).'.'.$extension;
        $template = resource_path('office-templates/blank.'.$extension);
        abort_unless(is_file($template) && is_readable($template), 503, 'Chưa có mẫu tài liệu cho định dạng này.');
        $content = file_get_contents($template);
        abort_unless(is_string($content) && $content !== '', 503, 'Không đọc được mẫu tài liệu.');
        $ownerId = $request->user()->id;
        $path = null;

        try {
            $file = DB::transaction(function () use ($data, $extension, $name, $content, $ownerId, $scope, $folders, $quota, &$path) {
                $quota->lock($ownerId);
                $quota->assertFits($ownerId, strlen($content));
                $folderId = $folders->destination($scope, $ownerId, $data['folder_id'] ?? null);
                $directory = $scope === 'personal' ? 'user-uploads/'.$ownerId : 'uploads';
                $path = $directory.'/'.Str::uuid().'.'.$extension;
                if (! Storage::disk('public')->put($path, $content)) {
                    throw new RuntimeException('Không thể lưu tài liệu mới.');
                }
                $attributes = [
                    'user_id' => $ownerId, 'folder_id' => $folderId, 'file_name' => $name,
                    'file_path' => $path, 'file_size' => strlen($content), 'all_users_can_edit' => false,
                    'expired_at' => $scope === 'shared' ? now()->addMinutes((int) $data['ttl_minutes']) : null,
                ];

                return $scope === 'personal' ? UserUpload::create($attributes) : Upload::create($attributes + ['view_only' => false]);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        return response()->json([
            'id' => $file->id,
            'edit_url' => route('office.show', ['scope' => $scope, 'id' => $file->id, 'edit' => 1], absolute: false),
        ], 201);
    }
}
