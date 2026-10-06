<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index()
    {
        return Inertia::render('FileDashboard', [
            'files' => fn () => $this->paginateDashboard(
                Upload::orderByDesc('created_at')->orderByDesc('id'),
                4,
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
                'expiring_files' => Upload::where('expired_at', '<', now()->addDay())->count(),
            ],
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'ttl_minutes' => ['required', 'integer', 'min:1'],
            'view_only' => ['sometimes', 'boolean'],
        ]);

        $file = $validated['file'];
        $viewOnly = auth()->check() && $request->boolean('view_only');
        $path = $file->store('uploads', $viewOnly ? 'local' : 'public');

        Upload::create([
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'expired_at' => Carbon::now()->addMinutes((int) $validated['ttl_minutes']),
            'view_only' => $viewOnly,
        ]);

        return back();
    }

    public function destroy($id)
    {
        $upload = Upload::findOrFail($id);

        // Files uploaded by a logged-in user can only be deleted by that user.
        // Legacy anonymous uploads keep the previous behaviour because they
        // do not have an owner to authorize against.
        abort_unless($upload->user_id === null || $this->isOwner($upload), 403);

        $disk = Storage::disk($this->storageDisk($upload));

        if ($disk->exists($upload->file_path)) {
            $disk->delete($upload->file_path);
        }

        $upload->delete();

        return back()->with('message', 'Đã xóa file thành công!');
    }

    public function download($id)
    {
        $upload = Upload::findOrFail($id);

        if ($upload->view_only && ! $this->isOwner($upload)) {
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
