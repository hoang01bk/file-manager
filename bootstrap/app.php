<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Server-to-server callbacks use a signed URL and JWT instead of browser CSRF.
        $middleware->validateCsrfTokens(except: ['office/*/*/callback']);
        // Ngrok terminates HTTPS before forwarding the request to nginx.
        // Trust its forwarded scheme so Laravel generates HTTPS asset URLs.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 419 && $request->header('X-Inertia')) {
                // Return a validation error so the form keeps its input and
                // cannot mistake this failed request for a successful upload.
                return back(303)->withErrors([
                    'session' => 'Phiên làm việc đã thay đổi hoặc hết hạn. Thao tác chưa được thực hiện, vui lòng thử lại. Nếu đã bị đăng xuất, hãy đăng nhập lại.',
                ]);
            }

            return $response;
        });
    })->create();
