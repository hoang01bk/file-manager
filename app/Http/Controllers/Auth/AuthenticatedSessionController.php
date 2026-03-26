<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    /**
     * Check credentials and return user info without logging in.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'employee_code' => ['required', 'string'],
            'password'      => ['required', 'string'],
        ]);

        $user = User::where('employee_code', $request->employee_code)->first();

        if (! $user || $user->password !== $request->password) {
            return response()->json(['message' => 'Sai mã nhân viên hoặc mật khẩu'], 422);
        }

        return response()->json([
            'name'          => $user->name,
            'employee_code' => $user->employee_code,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        if ($request->filled('display_name')) {
            session(['display_name' => trim($request->display_name)]);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
