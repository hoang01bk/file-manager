<?php

namespace App\Http\Controllers;

use App\Models\UserPost;
use Illuminate\Http\Request;

class UserPostController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'content' => 'required|string|max:2000',
        ]);

        UserPost::create([
            'user_id' => auth()->id(),
            'content' => $request->input('content'),
        ]);

        return back();
    }

    public function destroy($id)
    {
        $post = UserPost::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $post->delete();

        return back();
    }
}
