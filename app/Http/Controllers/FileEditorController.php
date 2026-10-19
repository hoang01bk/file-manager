<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class FileEditorController extends Controller
{
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
