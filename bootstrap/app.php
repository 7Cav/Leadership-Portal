<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCachingAuthenticatedResponses;
use App\Http\Middleware\ReadOnlyWhileViewingAs;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global rather than `web`-only, so error pages outside the group carry the headers too
        // (see the class docblock).
        $middleware->append(SetSecurityHeaders::class);

        // Behind a load balancer or reverse proxy `$request->ip()` is the proxy's address, which
        // would put every user in one sign-in throttle bucket and leave HSTS unable to see HTTPS.
        // The subclass reads `portal.trusted_proxies` per request: this callback runs before `.env`
        // and the config are loaded, so neither `env()` nor `config()` can be read here (see its
        // docblock).
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);

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
        // A background `fetch` (the rollcall autosave, the capture retry, the trooper Sheet) asks for
        // JSON, and must get its 401/403/419 as JSON rather than an HTML error page or a 302 to the
        // login screen it cannot follow. An Inertia visit also sends `X-Requested-With`, but wants
        // Inertia's own handling (a redirect it follows, an error page it renders), so it is left out.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );
    })->create();
