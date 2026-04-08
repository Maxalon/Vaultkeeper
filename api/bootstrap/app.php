<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:       __DIR__.'/../routes/web.php',
        api:       __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands:  __DIR__.'/../routes/console.php',
        health:    '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API-only app: never try to redirect unauthenticated requests to a
        // "login" named route (it doesn't exist). Returning null tells the
        // Authenticate middleware to skip the redirect path; the exception
        // handler then renders a plain 401.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ensure /api/* always gets JSON responses, even when the caller
        // forgot the Accept header.
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
