<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\User;
use App\Models\UserUpload;
use App\Services\FilePermissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FileEditorController extends Controller
{
    public function show(Request $request, string $scope, int $id)
    {
        $file = $this->ownedFile($request, $scope, $id);

        return response()->json([
            'editors' => $file->editors()->orderBy('name')->get(['users.id', 'name', 'employee_code'])->makeHidden('pivot'),
            'view_only' => $file instanceof Upload ? $file->view_only : null,
            'all_users_can_edit' => $file->all_users_can_edit,
        ]);
    }

    public function update(Request $request, string $scope, int $id, FilePermissionService $permissions)
    {
        $file = $this->ownedFile($request, $scope, $id);
        $validated = $request->validate([
            'editor_ids' => ['present', 'array', 'max:50'],
            'editor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
            'view_only' => [$scope === 'shared' ? 'required' : 'prohibited', 'boolean'],
            'all_users_can_edit' => ['sometimes', 'boolean'],
        ]);
        $permissions->update($file, $request->user()->id, $validated['editor_ids'], $validated['view_only'] ?? null, $request->boolean('all_users_can_edit'));

        return response()->json(['message' => 'Đã cập nhật quyền truy cập.']);
    }

    private function ownedFile(Request $request, string $scope, int $id): Upload|UserUpload
    {
        $file = $scope === 'shared' ? Upload::findOrFail($id) : UserUpload::findOrFail($id);
        abort_unless($file->user_id !== null && (int) $file->user_id === (int) $request->user()->id, 403);
        abort_if($file->expired_at && $file->expired_at->isPast(), 410, 'File đã hết hạn.');

        return $file;
    }

    public function index(Request $request)
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['search'] ?? '');
        if (mb_strlen($search) < 2) {
            return response()->json([]);
        }

        return response()->json(User::query()
            ->whereKeyNot($request->user()->id)
            ->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')
                ->orWhereLike('employee_code', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->limit(20)
            ->get(['id', 'name', 'employee_code']));
    }
}
