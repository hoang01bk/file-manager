<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadRequest;
use App\Models\UserPost;
use App\Models\UserUpload;
use App\Services\UploadQuota;
use App\Services\FileFolders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class MyFileController extends Controller
{
    public function index(UploadQuota $quota, FileFolders $folders)
    {
        $query = request()->validate(['q' => ['nullable', 'string', 'max:200'], 'folder' => ['nullable', 'integer', 'min:1']]);
        $search = trim($query['q'] ?? '');
        $parent = $folders->find('personal', isset($query['folder']) ? (int) $query['folder'] : null, auth()->id());
        $files = UserUpload::where(fn ($query) => $query->where('user_id', auth()->id())
            ->orWhere('all_users_can_edit', true)
            ->orWhereHas('editors', fn ($editors) => $editors->where('users.id', auth()->id())))
            ->where(function ($query) use ($parent) {
                $query->where('folder_id', $parent?->id);
                if ($parent === null) {
                    // Individually shared files remain discoverable without exposing the owner's folders.
                    $query->orWhere('user_id', '!=', auth()->id());
                }
            })
            ->when($search !== '', fn ($query) => $query->whereLike('file_name', '%'.$search.'%'))
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $userPosts = UserPost::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('MyFiles', [
            'files' => $files,
            'userPosts' => $userPosts,
            'storageQuota' => $quota->status(auth()->id()),
            'filters' => ['q' => $search],
            ...$folders->listing('personal', $parent, auth()->id(), $search),
        ]);
    }

    public function store(StoreUploadRequest $request, UploadQuota $quota, FileFolders $folders)
    {
        $validated = $request->validated();
        $file = $request->file('file');
        $ownerId = $request->user()->id;
        $path = null;

        try {
            DB::transaction(function () use ($file, $validated, $request, $quota, $folders, $ownerId, &$path) {
                $quota->lock($ownerId);
                $quota->assertFits($ownerId, $file->getSize());
                $folderId = $folders->destination('personal', $ownerId, $validated['folder_id'] ?? null, $validated['relative_path'] ?? null);

                $path = $file->store('user-uploads/'.$ownerId, 'public');
                if (! $path) {
                    throw new RuntimeException('Không thể lưu file đã tải lên.');
                }
                $upload = UserUpload::create([
                    'user_id' => $ownerId,
                    'folder_id' => $folderId,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'all_users_can_edit' => $request->boolean('all_users_can_edit'),
                ]);
                $upload->editors()->sync($upload->all_users_can_edit ? [] : array_diff($validated['editor_ids'] ?? [], [$ownerId]));
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['uploaded' => true], 201) : back();
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $upload = UserUpload::where('id', $id)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->firstOrFail();

            if (Storage::disk('public')->exists($upload->file_path)) {
                Storage::disk('public')->delete($upload->file_path);
            }

            $upload->delete();
        });

        return back()->with('message', 'Đã xóa file thành công!');
    }

    public function download($id)
    {
        $upload = $this->accessibleFile($id);

        if (Storage::disk('public')->exists($upload->file_path)) {
            return Storage::disk('public')->download($upload->file_path, $upload->file_name);
        }

        return back()->with('error', 'File không tồn tại.');
    }

    public function preview($id)
    {
        $upload = $this->accessibleFile($id);

        if (Storage::disk('public')->exists($upload->file_path)) {
            // Redirect to direct URL so nginx serves the file directly
            return redirect('/storage/'.$upload->file_path);
        }

        abort(404, 'File không tồn tại.');
    }

    private function accessibleFile(int $id): UserUpload
    {
        $file = UserUpload::findOrFail($id);
        abort_unless($file->canEditOffice(auth()->id()), 403);

        return $file;
    }
}
