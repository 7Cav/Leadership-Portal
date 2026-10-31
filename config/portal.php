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

    // Random per Playwright run. Even if an e2e route table reaches a public server, the
    // browser-test sign-in cannot be used without the local test browser's scoped cookie.
    'e2e_login_token' => env('E2E_LOGIN_TOKEN', ''),

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

    // Where "Was this helpful? No" sends a reader: a forum thread or a mailto: link. Empty hides the link and the
    // page only thanks them; nothing is stored either way (#410).
    'docs_feedback_url' => env('DOCS_FEEDBACK_URL', ''),

    // The folder the guides are read from. Empty means resources/docs; the browser tests point it at a fixed set of
    // guides so they do not change whenever a real guide is edited.
    'docs_path' => env('DOCS_PATH', ''),

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
    | Roll call opens on the week whose report is still due
    |--------------------------------------------------------------------------
    |
    | On the Saturday after a week ends, until its report is due at 2359Z, a bare roll call URL
    | opens that closing week for a section that has not sent its report. Off only for the browser
    | tests (APP_ENV=e2e): their server and browser run on the real clock, which cannot be set, so
    | on a Saturday every spec that opens a roll call would land on last week. Pest covers the
    | redirect itself at a fixed time.
    |
    */

    'rollcall_opens_reporting_week' => env('APP_ENV') !== 'e2e',

    /*
    |--------------------------------------------------------------------------
    | Admin billet groups
    |--------------------------------------------------------------------------
    |
    | The Cav position groups whose billets may make their holder an Admin. The org import lists every billet in these groups on Settings → Staff grants as
    | pending, and an existing Admin approves each one. A billet moved out of these groups stops
    | making anyone an Admin. Exact Cav group titles; a change here is a change to who may be an
    | Admin, so it ships through review like any other code.
    |
    */

    'admin_billet_groups' => ['Regimental HQ'],

    /*
    | Single billets that may make their holder an Admin although the rest of their Cav group may not, by exact
    | trimmed title. The S6 leadership sits in Cav's "Support Attachment" group with S1, S2 and others. A billet
    | renamed in Cav to a title not listed here stops making anyone an Admin, like a group move.
    */

    'admin_billet_titles' => ['S6 1IC', 'S6 2IC'],

    /*
    |--------------------------------------------------------------------------
    | Joint Service Achievement Medal candidates
    |--------------------------------------------------------------------------
    |
    | The roster's "Guests at our practices" panel marks a guest as a JSAM candidate when, over the
    | last `window_weeks` Cav weeks (the current one included), they attended at least `min_visits`
    | practices of the viewed unit's sections while not assigned to them, and their own SP % over the
    | same window (the one KPI definition, AD-21, so LOA weeks leave it as everywhere else) is at
    | least `min_own_sp_percent`. S1 may change them. The
    | portal never awards: the commander of the benefited unit recommends.
    |
    */

    'jsam' => [
        'min_visits' => (int) env('JSAM_MIN_VISITS', 3),
        'min_own_sp_percent' => (int) env('JSAM_MIN_OWN_SP_PERCENT', 75),
        'window_weeks' => (int) env('JSAM_WINDOW_WEEKS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Changelog
    |--------------------------------------------------------------------------
    |
    | The usernames that may write the changelog at /changelog, compared without case. Only they add, edit, delete and
    | import entries and dismiss the "Changelog outdated" bar, and only while signed in as themselves.
    |
    | `changelog_deploy_id` overrides the deploy id the outdated bar compares (the asset build version Inertia uses):
    | null uses that version, a string is used as the id, and false means there is none, so the bar never shows.
    |
    */

    'changelog_editors' => ['Grayson.J', 'Laui.M'],

    'changelog_deploy_id' => null,

];
