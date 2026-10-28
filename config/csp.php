<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content security policy
    |--------------------------------------------------------------------------
    |
    | The sources a portal page may load (#485), sent by SetSecurityHeaders on every response. Each
    | directive lists its sources as the browser reads them, keywords in single quotes. The middleware
    | adds the per-request nonce to `script-src`, and the Vite dev server's origin while `npm run dev`
    | runs on a local machine, so neither belongs here. To allow another host, add it to the directive
    | that needs it and run `php artisan config:cache`.
    |
    | - script-src: the built bundles from this host, plus the inline theme script and Vite's tags by
    |   nonce. Nothing is evaluated from strings (the Vue build is the runtime-only one).
    | - style-src: 'unsafe-inline' because <style> elements are added at runtime (Inertia's progress
    |   bar) and the framework's plain error pages carry their own. Vue's :style bindings (bar widths,
    |   chart colors, the floating tips) go through the CSSOM, which the policy does not cover. A
    |   style cannot run code.
    | - img-src: data: for the guidon drawn as an SVG data URL (and its PNG download); 7cav.us for the
    |   rank insignia and the images a BBCode preview may show (bbcodeRender.js allows 7cav.us and its
    |   subdomains only).
    | - font-src: the Geist fonts, bundled by Vite.
    | - form-action: every form in the portal posts to the portal. Sign-in is a link to /auth/redirect,
    |   which redirects to 7cav.us, and sign-out posts to /logout and lands on /login.
    |
    */

    'directives' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'https://7cav.us', 'https://*.7cav.us'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ],

    // Send the policy as Content-Security-Policy-Report-Only: the browser logs what it would block and
    // blocks nothing. `frame-ancestors 'none'` is still enforced, since a report-only header ignores it.
    'report_only' => (bool) env('CSP_REPORT_ONLY', false),

];
