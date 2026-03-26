<?php

namespace App\Http\Controllers;

use App\Models\UserUpload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class MyFileController extends Controller
{
    public function index()
    {
        $files = UserUpload::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('MyFiles', [
            'files' => $files,
        ]);
    }

    public function store(Request $request)
    {
        $file = $request->file('file');
        $path = $file->store('user-uploads/' . auth()->id(), 'public');

        UserUpload::create([
            'user_id'   => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
        ]);

        return back();
    }

    public function destroy($id)
    {
        $upload = UserUpload::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (Storage::disk('public')->exists($upload->file_path)) {
            Storage::disk('public')->delete($upload->file_path);
        }

        $upload->delete();

        return back()->with('message', 'Đã xóa file thành công!');
    }

    public function download($id)
    {
        $upload = UserUpload::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (Storage::disk('public')->exists($upload->file_path)) {
            return Storage::disk('public')->download($upload->file_path, $upload->file_name);
        }

        return back()->with('error', 'File không tồn tại.');
    }

    public function preview($id)
    {
        $upload = UserUpload::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (Storage::disk('public')->exists($upload->file_path)) {
            $mimeType = Storage::disk('public')->mimeType($upload->file_path);
            return response()->file(Storage::disk('public')->path($upload->file_path), [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $upload->file_name . '"',
            ]);
        }

        abort(404, 'File không tồn tại.');
    }
}
