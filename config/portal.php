<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo tools
    |--------------------------------------------------------------------------
    |
    | Generating and purging demo attendance history (#115): the `portal:demo-history` console
    | command and the Admin-only Settings → Demo data page. Off by default — when false the
    | command refuses to run and the page's routes answer 404, so a production portal never
    | offers to fabricate history.
    |
    */

    'demo_tools' => (bool) env('DEMO_TOOLS', false),

    /*
    |--------------------------------------------------------------------------
    | Docs
    |--------------------------------------------------------------------------
    |
    | The in-portal guides at `/docs` (#407). Off by default: when false every docs route answers
    | 404 and the shell shows no Docs entry, so half-written guides never reach the regiment while
    | testing runs. Switched on for testers first, then for everyone at rollout (#419).
    |
    */

    'docs' => (bool) env('DOCS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs/CIDRs of the load balancer or reverse proxy in front of the app, so the
    | sign-in throttle sees each client's own IP and HSTS sees HTTPS. Empty trusts nothing. Never
    | `*` on an internet-facing host. Read per request by App\Http\Middleware\TrustProxies.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | Week floor
    |--------------------------------------------------------------------------
    |
    | How many Cav weeks back from the current one a rollcall or weekly report may still be
    | written. An older week is refused (the screens answer 404 or 422), so no practice or report
    | row is created for a week long before the portal. The default, 20, matches the roster's
    | attendance matrix, the longest history any screen shows.
    |
    */

    'week_floor_weeks' => (int) env('PORTAL_WEEK_FLOOR_WEEKS', 20),

    /*
    |--------------------------------------------------------------------------
    | Joint Service Achievement Medal candidates
    |--------------------------------------------------------------------------
    |
    | The roster's "Guests at our practices" panel marks a guest as a JSAM candidate when, over the
    | last `window_weeks` Cav weeks (the current one included), they attended at least `min_visits`
    | practices of the viewed unit's sections while not assigned to them, and their own SP % over the
    | same window (the one KPI definition, AD-21, so LOA weeks leave it as everywhere else) is at
    | least `min_own_sp_percent`. Agreed with the product owner 2026-10-23; S1 may change them. The
    | portal never awards: the commander of the benefited unit recommends.
    |
    */

    'jsam' => [
        'min_visits' => (int) env('JSAM_MIN_VISITS', 3),
        'min_own_sp_percent' => (int) env('JSAM_MIN_OWN_SP_PERCENT', 75),
        'window_weeks' => (int) env('JSAM_WINDOW_WEEKS', 12),
    ],

];
