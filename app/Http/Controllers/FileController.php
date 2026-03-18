<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index()
    {
        return Inertia::render('FileDashboard', [
            'files' => Upload::orderBy('created_at', 'desc')->get()
        ]);
    }

    public function store(Request $request)
    {
        $file = $request->file('file');
        $path = $file->store('uploads', 'public');

        Upload::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'expired_at' => Carbon::now()->addMinutes((int)$request->ttl_minutes),
        ]);

        return back();
    }

    public function destroy($id)
    {
        $upload = Upload::findOrFail($id);

        if (Storage::disk('public')->exists($upload->file_path)) {
            Storage::disk('public')->delete($upload->file_path);
        }

        $upload->delete();

        return back()->with('message', 'Đã xóa file thành công!');
    }

    public function download($id)
    {
        $upload = Upload::findOrFail($id);
        
        if (Storage::disk('public')->exists($upload->file_path)) {
            return Storage::disk('public')->download($upload->file_path, $upload->file_name);
        }

        return back()->with('error', 'File không tồn tại trên hệ thống.');
    }
}
