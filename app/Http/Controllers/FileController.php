<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadRequest;
use App\Models\Post;
use App\Models\Upload;
use App\Services\UploadQuota;
use App\Services\FileFolders;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

class FileController extends Controller
{
    public function index(UploadQuota $quota, FileFolders $folders)
    {
        $query = request()->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
            'folder' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($query['q'] ?? '');
        $perPage = (int) ($query['per_page'] ?? 20);
        $parent = $folders->find('shared', isset($query['folder']) ? (int) $query['folder'] : null, auth()->id());

        return Inertia::render('FileDashboard', [
            'files' => fn () => $this->paginateDashboard(
                Upload::withEditorAccess(auth()->id())
                    ->where('folder_id', $parent?->id)
                    ->when($search !== '', fn (Builder $query) => $query->whereLike('file_name', '%'.$search.'%'))
                    ->orderByDesc('created_at')->orderByDesc('id'),
                $perPage,
                'files_page',
            ),
            'posts' => fn () => $this->paginateDashboard(
                Post::where(fn (Builder $query) => $query
                    ->where('expires_at', '>', now())
                    ->orWhereNull('expires_at'))
                    ->orderByDesc('created_at')->orderByDesc('id'),
                10,
                'posts_page',
            ),
            'stats' => fn () => [
                'expiring_files' => Upload::where('folder_id', $parent?->id)->whereBetween('expired_at', [now(), now()->addDay()])->count(),
            ],
            'storageQuota' => fn () => $quota->status(auth()->id()),
            'filters' => ['q' => $search, 'per_page' => $perPage],
            ...$folders->listing('shared', $parent, auth()->id(), $search),
        ]);
    }

    private function paginateDashboard(Builder $query, int $perPage, string $pageName): LengthAwarePaginator
    {
        $total = (clone $query)->toBase()->getCountForPagination();
        // Keep the last page usable after its final item has been deleted or expired.
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(Paginator::resolveCurrentPage($pageName), $lastPage);

        return $query->paginate($perPage, ['*'], $pageName, $page, $total)->withQueryString();
    }

    public function store(StoreUploadRequest $request, UploadQuota $quota, FileFolders $folders)
    {
        $validated = $request->validated();

        $file = $validated['file'];
        $viewOnly = auth()->check() && $request->boolean('view_only');
        $disk = $viewOnly ? 'local' : 'public';
        $ownerId = $request->user()?->id;
        $path = null;

        try {
            DB::transaction(function () use ($file, $validated, $viewOnly, $request, $quota, $folders, $ownerId, $disk, &$path) {
                $quota->lock($ownerId);
                $quota->assertFits($ownerId, $file->getSize());
                $folderId = $folders->destination('shared', $ownerId, $validated['folder_id'] ?? null, $validated['relative_path'] ?? null);

                $path = $file->store('uploads', $disk);
                if (! $path) {
                    throw new RuntimeException('Không thể lưu file đã tải lên.');
                }
                $upload = Upload::create([
                    'user_id' => $ownerId,
                    'folder_id' => $folderId,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'expired_at' => Carbon::now()->addMinutes((int) $validated['ttl_minutes']),
                    'view_only' => $viewOnly,
                    'all_users_can_edit' => $request->user() && $request->boolean('all_users_can_edit'),
                ]);
                if ($request->user()) {
                    $upload->editors()->sync($upload->all_users_can_edit ? [] : array_diff($validated['editor_ids'] ?? [], [$request->user()->id]));
                }
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['uploaded' => true], 201) : back();
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $upload = Upload::lockForUpdate()->findOrFail($id);

            // Files uploaded by a logged-in user can only be deleted by that user.
            // Legacy anonymous uploads keep the previous behaviour because they
            // do not have an owner to authorize against.
            abort_unless($upload->user_id === null || $this->isOwner($upload), 403);

            $disk = Storage::disk($this->storageDisk($upload));

            if ($disk->exists($upload->file_path)) {
                $disk->delete($upload->file_path);
            }

            $upload->delete();
        });

        return back()->with('message', 'Đã xóa file thành công!');
    }

    public function download($id)
    {
        $upload = Upload::findOrFail($id);

        if ($upload->view_only && ! $upload->canEditOffice(auth()->id())) {
            abort(403, 'File này chỉ cho phép xem.');
        }

        $disk = Storage::disk($this->storageDisk($upload));

        if ($disk->exists($upload->file_path)) {
            return $disk->download($upload->file_path, $upload->file_name);
        }

        return back()->with('error', 'File không tồn tại trên hệ thống.');
    }

    public function preview($id)
    {
        $upload = Upload::findOrFail($id);

        $disk = Storage::disk($this->storageDisk($upload));

        if ($disk->exists($upload->file_path)) {
            // BinaryFileResponse supports HTTP Range requests, which lets
            // the browser seek through videos while keeping the response
            // inline instead of forcing a download.
            return response()->file($disk->path($upload->file_path));
        }

        abort(404, 'File không tồn tại.');
    }

    private function isOwner(Upload $upload): bool
    {
        return auth()->check()
            && $upload->user_id !== null
            && (int) $upload->user_id === (int) auth()->id();
    }

    private function storageDisk(Upload $upload): string
    {
        return $upload->view_only ? 'local' : 'public';
    }
}
