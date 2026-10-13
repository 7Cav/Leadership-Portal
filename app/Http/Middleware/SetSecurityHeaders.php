<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (NFR5).
 *
 * - `X-Frame-Options: DENY` and `Content-Security-Policy: frame-ancestors 'none'` stop another
 *   site framing the portal to clickjack a write (a grant, a delete, a sent report). The legacy
 *   header covers old browsers, the CSP directive covers current ones.
 * - `X-Content-Type-Options: nosniff` stops a browser guessing a response is script or HTML.
 * - `Referrer-Policy: strict-origin-when-cross-origin` keeps paths and query strings (unit ids,
 *   `?week=`) out of the Referer sent to other sites.
 * - `Strict-Transport-Security` only in production and only on a request that arrived over
 *   HTTPS. Sent over plain HTTP the header is ignored anyway, and sent from a dev box on
 *   localhost it would pin that host to HTTPS in the developer's browser for a year. Behind a
 *   TLS-terminating proxy, `secure()` is only true once the proxy is trusted (TrustProxies).
 *
 * The CSP carries `frame-ancestors` alone on purpose. A full policy would have to allow the
 * inline pre-paint theme script in resources/views/app.blade.php (by hash or nonce) and Vite's
 * dev server, and that is its own piece of work. `frame-ancestors` restricts nothing a page
 * loads, so it cannot break one.
 *
 * Any header a response already carries is left as it is: a route that needs a different
 * policy (say, an embeddable page) sets its own and this does not undo it.
 *
 * Registered as global middleware in bootstrap/app.php rather than in the `web` group, so it
 * also covers responses the group never sees: a 404 for an unknown path, a 419 or 409 produced
 * by middleware further out, and the `/up` health check.
 */
class SetSecurityHeaders
{
    /** One year, the usual HSTS floor for a host that is HTTPS-only for good. */
    private const HSTS_MAX_AGE = 31536000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if (app()->isProduction() && $request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age='.self::HSTS_MAX_AGE;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
