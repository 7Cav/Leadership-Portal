<?php

use App\Http\Middleware\ActAsUser;
use App\Http\Middleware\BlockedWhilePreviewing;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventCachingAuthenticatedResponses;
use App\Http\Middleware\ReadOnlyWhileViewingAs;
use App\Http\Middleware\RecordPageVisit;
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
        // Global rather than `web`-only, and prepended so it is the outermost global middleware: the
        // responses the others produce (maintenance, a body too large, a CORS preflight) carry the
        // headers too (see the class docblock).
        $middleware->prepend(SetSecurityHeaders::class);

        // Behind a load balancer or reverse proxy `$request->ip()` is the proxy's address, which
        // would put every user in one sign-in throttle bucket and leave HSTS unable to see HTTPS.
        // The subclass reads `portal.trusted_proxies` per request: this callback runs before `.env`
        // and the config are loaded, so neither `env()` nor `config()` can be read here (see its
        // docblock).
        // X-Forwarded-Host stays trusted from those proxies until we know whether the production proxy sets it or
        // passes the client's value through; the trusted-hosts check below applies to it either way.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);

        // In production the app answers only for the APP_URL host. Otherwise redirects are built from whatever Host
        // the request carried, so `Host: evil.example` got `Location: http://evil.example/login`. An unknown host is
        // refused before routing. Other environments stay open (local tools, e2e on 127.0.0.1); the framework also
        // skips the check under local and testing.
        $middleware->trustHosts(at: function () {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return app()->isProduction() && is_string($host) && $host !== '' ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);

        // Prepended, so it wraps the whole group: appended it would be the innermost pipe and
        // any response produced further out — a 419 CSRF rejection, Inertia's 409 version
        // bounce — would slip past uncached-unmarked. With APP_DEBUG on, that 419 is a debug
        // page rendering the session payload (Story 1.5).
        $middleware->web(prepend: [
            PreventCachingAuthenticatedResponses::class,
        ]);

        $middleware->web(append: [
            // Runs the request as the person an Admin is acting as (#379), before anything reads the user.
            ActAsUser::class,
            HandleInertiaRequests::class,
            // While view as or act as is on, a page the identity may not open renders as the shell with a blocked
            // state rather than an error page, so the banner stays. Inside HandleInertiaRequests, for its shared props.
            BlockedWhilePreviewing::class,
            // Refuses writes while a view-as preview is active (Story 1.13). Runs after routing,
            // so it can let the view-as exit and logout routes through by name.
            ReadOnlyWhileViewingAs::class,
            // Logs the page for the Admin-only activity log (#382). It records in terminate(), after the response,
            // and reads who acted as whom from the attributes ActAsUser set.
            RecordPageVisit::class,
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
