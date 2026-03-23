<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
use Carbon\Carbon;

class PostController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'ttl_minutes' => 'nullable|integer|min:1',
        ]);

        $post = new Post();
        $post->content = $validated['content'];
        $post->expires_at = isset($validated['ttl_minutes'])
            ? Carbon::now()->addMinutes($validated['ttl_minutes'])
            : null;
        $post->save();

        return redirect()->back();
    }
}