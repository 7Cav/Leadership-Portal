<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCachingAuthenticatedResponses;
use App\Http\Middleware\ReadOnlyWhileViewingAs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended, so it wraps the whole group: appended it would be the innermost pipe and
        // any response produced further out — a 419 CSRF rejection, Inertia's 409 version
        // bounce — would slip past uncached-unmarked. With APP_DEBUG on, that 419 is a debug
        // page rendering the session payload (Story 1.5).
        $middleware->web(prepend: [
            PreventCachingAuthenticatedResponses::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            // Refuses writes while a view-as preview is active (Story 1.13). Runs after routing,
            // so it can let the view-as exit and logout routes through by name.
            ReadOnlyWhileViewingAs::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
