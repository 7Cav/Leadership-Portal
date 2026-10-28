<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
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
 * - `Cross-Origin-Resource-Policy: same-origin` stops another site loading a portal response (a
 *   counseling PDF, a JSON endpoint) as an image, script or other subresource.
 * - `Strict-Transport-Security` only in production and only on a request that arrived over
 *   HTTPS. Sent over plain HTTP the header is ignored anyway, and sent from a dev box on
 *   localhost it would pin that host to HTTPS in the developer's browser for a year. Behind a
 *   TLS-terminating proxy, `secure()` is only true once the proxy is trusted (TrustProxies).
 *
 * The content security policy (#485) lists what a page may load, from config/csp.php, so a script
 * someone managed to inject would not run. Scripts come from this host or carry the request's
 * nonce: Vite prints it on the tags @vite writes, and app.blade.php on its inline theme script. Two
 * exceptions:
 * - On a local machine while `npm run dev` runs (Vite's hot file exists), the dev server's origin
 *   and its websocket are allowed too, since the page loads its scripts, styles and updates there.
 * - A debug error page (APP_DEBUG and a 5xx) gets `frame-ancestors 'none'` alone, as before: the
 *   framework's error page draws itself with inline scripts.
 * With `CSP_REPORT_ONLY` the policy goes out as Content-Security-Policy-Report-Only, and the
 * enforced header keeps `frame-ancestors 'none'`, which a report-only policy ignores.
 *
 * Any header a response already carries is left as it is: a route that needs a different
 * policy (say, an embeddable page) sets its own and this does not undo it.
 *
 * Registered in bootstrap/app.php as the first global middleware, not in the `web` group, so it
 * wraps everything else and also covers responses the group never sees: a 404 for an unknown
 * path, a 419 or 409 from the group's own middleware, the `/up` health check, and the responses
 * other global middleware produce (the maintenance 503, a 413 for a body over the size limit, a
 * CORS preflight). A page pre-rendered with `artisan down --render` is served before the app
 * starts, so it is the one response without them.
 */
class SetSecurityHeaders
{
    /** One year, the usual HSTS floor for a host that is HTTPS-only for good. */
    private const HSTS_MAX_AGE = 31536000;

    private const FRAME_ANCESTORS = "frame-ancestors 'none'";

    public function __construct(private readonly Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Before the page renders, so the tags @vite prints and the theme script carry it.
        $nonce = $this->vite->useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => self::FRAME_ANCESTORS,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        $debugErrorPage = config('app.debug') && $response->getStatusCode() >= 500;
        if (! $debugErrorPage && ! $response->headers->has('Content-Security-Policy')) {
            $headers[config('csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $this->policy($nonce);
        }

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

    private function policy(string $nonce): string
    {
        $directives = config('csp.directives', []);
        $directives['script-src'][] = "'nonce-{$nonce}'";

        [$devServer, $devSocket] = $this->devServer() ?? [null, null];
        if ($devServer !== null) {
            foreach (['script-src', 'style-src', 'img-src', 'font-src', 'connect-src'] as $name) {
                $directives[$name][] = $devServer;
            }
            $directives['connect-src'][] = $devSocket;
        }

        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = trim($name.' '.implode(' ', array_unique($sources)));
        }

        return implode('; ', $parts);
    }

    /**
     * The Vite dev server's origin and its websocket's, only on a local machine while `npm run dev`
     * runs: null in every other environment, even with a hot file left behind.
     *
     * @return array{string, string}|null
     */
    private function devServer(): ?array
    {
        if (! app()->isLocal() || ! $this->vite->isRunningHot()) {
            return null;
        }

        $url = parse_url(trim((string) file_get_contents($this->vite->hotFile())));
        if (! is_array($url) || ! isset($url['scheme'], $url['host'])) {
            return null;
        }

        $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        return [
            "{$url['scheme']}://{$authority}",
            ($url['scheme'] === 'https' ? 'wss' : 'ws')."://{$authority}",
        ];
    }
}
