<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadRequest;
use App\Models\UserPost;
use App\Models\UserUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class MyFileController extends Controller
{
    public function index()
    {
        $files = UserUpload::where(fn ($query) => $query->where('user_id', auth()->id())
            ->orWhereHas('editors', fn ($editors) => $editors->where('users.id', auth()->id())))
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        $userPosts = UserPost::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('MyFiles', [
            'files' => $files,
            'userPosts' => $userPosts,
        ]);
    }

    public function store(StoreUploadRequest $request)
    {
        $validated = $request->validated();
        $file = $request->file('file');
        $path = $file->store('user-uploads/'.auth()->id(), 'public');

        DB::transaction(function () use ($file, $path, $validated, $request) {
            $upload = UserUpload::create([
                'user_id' => auth()->id(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
            ]);
            $upload->editors()->sync(array_diff($validated['editor_ids'] ?? [], [$request->user()->id]));
        });

        return back();
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
