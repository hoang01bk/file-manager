<?php

namespace App\Http\Controllers;

use App\Models\FileFolder;
use App\Models\Upload;
use App\Models\UserUpload;
use App\Services\FileFolders;
use App\Services\UploadQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FileFolderController extends Controller
{
    public function availableFiles(Request $request, string $scope, int $id, FileFolders $folders)
    {
        $userId = $request->user()?->id;
        $folders->find($scope, $id, $userId);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $model = $scope === 'personal' ? UserUpload::class : Upload::class;
        $table = (new $model)->getTable();
        $search = trim($data['q'] ?? '');

        return response()->json($model::query()
            ->where(function ($query) use ($scope, $userId, $table) {
                $query->where($table.'.user_id', $userId);
                if ($scope === 'shared' && $userId !== null) {
                    $query->orWhereNull($table.'.user_id');
                }
            })
            ->where(fn ($query) => $query->whereNull($table.'.folder_id')->orWhere($table.'.folder_id', '!=', $id))
            ->when($search !== '', fn ($query) => $query->whereLike($table.'.file_name', '%'.$search.'%'))
            ->leftJoin('file_folders as source_folder', 'source_folder.id', '=', $table.'.folder_id')
            ->orderByDesc($table.'.created_at')->orderByDesc($table.'.id')
            ->paginate(10, [$table.'.id', $table.'.file_name', $table.'.file_size', $table.'.created_at', 'source_folder.name as folder_name']));
    }

    public function addFiles(Request $request, string $scope, int $id, FileFolders $folders, UploadQuota $quota)
    {
        $data = $request->validate([
            'file_ids' => ['required', 'array', 'min:1', 'max:100'],
            'file_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $userId = $request->user()?->id;
        DB::transaction(function () use ($scope, $id, $folders, $quota, $userId, $data) {
            $quota->lock($userId);
            $folders->destination($scope, $userId, $id);
            $model = $scope === 'personal' ? UserUpload::class : Upload::class;
            $files = $model::whereIn('id', $data['file_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($files->count() === count($data['file_ids']), 403, 'Một số file không còn tồn tại hoặc bạn không có quyền chuyển.');
            foreach ($files as $file) {
                $owned = $userId !== null && (int) $file->user_id === (int) $userId;
                abort_unless($owned || ($scope === 'shared' && $file->user_id === null), 403, 'Chỉ có thể chuyển file do bạn tải lên hoặc file dùng chung của khách.');
            }
            // Moving changes only the hierarchy, preserving content, timestamps and permissions.
            $model::whereIn('id', $data['file_ids'])->toBase()->update(['folder_id' => $id]);
        });

        return response()->json(['moved' => count($data['file_ids'])]);
    }

    public function store(Request $request, string $scope, FileFolders $folders, UploadQuota $quota)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer', 'min:1']]);
        $name = $folders->validateName($data['name']);
        $userId = $request->user()?->id;
        $folder = DB::transaction(function () use ($folders, $quota, $scope, $userId, $data, $name) {
            $quota->lock($userId);
            $parentId = $folders->destination($scope, $userId, $data['parent_id'] ?? null);
            $query = FileFolder::where('scope', $scope)->where('parent_id', $parentId)->where('name', $name)
                ->when($scope === 'personal', fn ($query) => $query->where('user_id', $userId));
            if ($query->exists()) {
                throw ValidationException::withMessages(['name' => 'Thư mục này đã tồn tại.']);
            }

            return FileFolder::create(['scope' => $scope, 'user_id' => $userId, 'parent_id' => $parentId, 'name' => $name]);
        });

        return $request->expectsJson() ? response()->json($folder, 201) : back();
    }

    public function destroy(Request $request, string $scope, int $id, FileFolders $folders, UploadQuota $quota)
    {
        DB::transaction(function () use ($request, $scope, $id, $folders, $quota) {
            $userId = $request->user()?->id;
            $quota->lock($userId);
            $folder = $folders->find($scope, $id, $userId);
            abort_unless(($scope === 'shared' && $folder->user_id === null) || ($userId !== null && (int) $folder->user_id === (int) $userId), 403);
            $folders->destination($scope, $userId, $id);
            $model = $scope === 'personal' ? UserUpload::class : Upload::class;
            if (FileFolder::where('parent_id', $id)->exists() || $model::where('folder_id', $id)->exists()) {
                throw ValidationException::withMessages(['folder' => 'Chỉ có thể xóa thư mục trống. Hãy xóa nội dung bên trong trước.']);
            }
            $folder->delete();
        });

        return back();
    }
}
