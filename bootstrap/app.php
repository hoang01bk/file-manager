<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            // Expected editor access errors should render a user-facing page, even in debug mode.
            // Keep signed content/callback endpoints and JSON requests on their normal API responses.
            if (! $request->routeIs('office.show') || $request->expectsJson()) {
                return null;
            }
            $status = $exception->getStatusCode();
            if (! in_array($status, [403, 404, 410, 413, 415, 503], true)) {
                return null;
            }

            return Inertia::render('FileUnavailable', [
                'status' => $status,
                'backUrl' => $request->route('scope') === 'personal' ? '/my-files' : '/',
            ])->toResponse($request)->setStatusCode($status);
        });

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
