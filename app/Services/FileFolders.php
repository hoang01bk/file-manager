<?php

namespace App\Services;

use App\Models\FileFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FileFolders
{
    public function find(string $scope, ?int $id, ?int $userId): ?FileFolder
    {
        abort_unless(in_array($scope, ['shared', 'personal'], true), 404);
        abort_if($scope === 'personal' && $userId === null, 403);
        if ($id === null) {
            return null;
        }

        return FileFolder::where('scope', $scope)
            ->when($scope === 'personal', fn ($query) => $query->where('user_id', $userId))
            ->findOrFail($id);
    }

    public function listing(string $scope, ?FileFolder $parent, ?int $userId, string $search = ''): array
    {
        $breadcrumbs = [];
        for ($folder = $parent; $folder !== null; $folder = $this->find($scope, $folder->parent_id, $userId)) {
            array_unshift($breadcrumbs, ['id' => $folder->id, 'name' => $folder->name]);
        }

        return [
            'folders' => FileFolder::where('scope', $scope)->where('parent_id', $parent?->id)
                ->when($scope === 'personal', fn ($query) => $query->where('user_id', $userId))
                ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
                ->orderBy('name')->get(['id', 'name', 'user_id', 'parent_id', 'created_at']),
            'currentFolder' => $parent ? ['id' => $parent->id, 'name' => $parent->name] : null,
            'folderBreadcrumbs' => $breadcrumbs,
        ];
    }

    public function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true) || mb_strlen($name) > 255 || preg_match('/[\\\\\/\x00-\x1f\x7f]/u', $name)) {
            throw ValidationException::withMessages(['name' => 'Tên thư mục không hợp lệ. Không dùng dấu /, \\ hoặc tên . và ..']);
        }

        return $name;
    }

    // Call inside the upload transaction, after locking the uploader's quota.
    public function destination(string $scope, ?int $userId, ?int $parentId, ?string $relativePath = null): ?int
    {
        $parent = $this->find($scope, $parentId, $userId);
        $segments = [];
        if ($relativePath !== null) {
            $parts = explode('/', $relativePath);
            if (count($parts) < 2 || count($parts) > 21) {
                throw ValidationException::withMessages(['relative_path' => 'Đường dẫn thư mục không hợp lệ hoặc sâu quá 20 cấp.']);
            }
            // Validate every segment, including the file name, before creating any folders.
            $parts = array_map(fn ($part) => $this->validateName($part), $parts);
            array_pop($parts);
            $segments = $parts;
        }
        if ($scope === 'shared') {
            // Serialize shared hierarchy changes even when uploaders have different owners.
            DB::table('upload_quota_locks')->where('subject', 'guest')->lockForUpdate()->first();
        }
        if ($parent) {
            $parent = FileFolder::lockForUpdate()->findOrFail($parent->id);
        }
        foreach ($segments as $name) {
            $parent = FileFolder::firstOrCreate([
                'scope' => $scope,
                'parent_id' => $parent?->id,
                'name' => $name,
                ...($scope === 'personal' ? ['user_id' => $userId] : []),
            ], ['user_id' => $userId]);
        }

        return $parent?->id;
    }
}
