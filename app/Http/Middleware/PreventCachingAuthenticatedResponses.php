<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stop the browser storing a response rendered for a signed-in user (Story 1.5 AC #2).
 *
 * Logout genuinely kills the session, but that only governs what the *server* will answer.
 * Laravel's default `no-cache, private` still lets the browser keep the page in its history
 * cache, so "back" after logout re-paints the last authenticated screen — roster names,
 * rollcall state — on what may be a shared machine (NFR5). `no-store` forces a fresh
 * request, which the `auth` middleware then bounces to login.
 *
 * Guest responses are deliberately untouched: the login screen holds nothing private and
 * there is no reason to make it uncacheable.
 */
class PreventCachingAuthenticatedResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            // Belt-and-braces for HTTP/1.0 intermediaries that ignore Cache-Control.
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
