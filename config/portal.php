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

];
