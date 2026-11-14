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

    // Where the hands-on tutorial keeps each person's practice copy (one SQLite file per user) and the migrated template
    // they are copied from (App\Tutorial\TutorialSandbox). Not an env key: it is part of the app's own storage.
    'tutorial_path' => storage_path('app/tutorial'),

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
    | Roll call flow cutover
    |--------------------------------------------------------------------------
    |
    | The first Cav week whose roll call follows the explicit cycle (#703): create it with its training date and topic,
    | post it, sign and mark, then close it, which sends the weekly report. Weeks before it keep the three steps. By
    | default (null) it is the one the release recorded: the first Saturday after its migration ran, so the week
    | running at release finishes as it started. A Saturday (`Y-m-d`) here overrides it. False keeps every week on the
    | three steps: so for the browser tests, whose specs walk the three steps on the real clock. A week's flow is fixed
    | when its practice row is made (its first mark, post or create), so a week with a row keeps its flow whatever this
    | says later (`Repositories\RollcallFlow`).
    |
    */

    'rollcall_flow_v2_from' => env('APP_ENV') === 'e2e' ? false : null,

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
    | Practice presence minimum
    |--------------------------------------------------------------------------
    |
    | How many minutes a trooper must be on a practice's server inside its one-hour window to count as
    | attending it (#558): present at their own section's practice, or a make-up at another section's.
    | A late joiner can still count, so it is under the hour. Battalion HQ staff can set their own
    | battalion's minimum on its roster page; a battalion without one uses this.
    |
    */

    'presence_minimum_minutes' => 40,

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
    | `changelog_deploy_marker` is the file whose modified time is shown to Admins as "Last deployed": the Vite manifest,
    | written by every front-end build a deploy runs.
    |
    */

    'changelog_editors' => ['Grayson.J', 'Laui.M'],

    'changelog_deploy_id' => null,

    'changelog_deploy_marker' => public_path('build/manifest.json'),

    /*
    |--------------------------------------------------------------------------
    | Who may add units
    |--------------------------------------------------------------------------
    |
    | 'admin' (the default for now): only Admins add units on Org structure. 'leaders': the scoped rule as well, so
    | leaders and overseers two echelons above the new unit and Regimental HQ staff may add too, and the leader who
    | added a unit may undo it within the hour (ScopeResolver::mayAddUnit, mayUndoAddUnit). Moving stays Admin only.
    |
    */

    'unit_add_scope' => 'admin',

];
