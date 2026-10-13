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

];
