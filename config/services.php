<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | 7cav.us forum OAuth2 (XenForo 2.3) — the SSO-only identity provider (AD-6).
    | The *client* secret is read here from env only (AD-23) — never the DB or repo,
    | and no user password is ever stored (AD-6). Endpoints verified live on 7cav.us:
    |   authorize  https://7cav.us/oauth2/authorize   (browser redirect)
    |   token      POST {base}/api/oauth2/token
    |   profile    GET  {base}/api/me                 (Bearer access token)
    | api.7cav.us is a *different* service (the Cav roster API, Story 1.6) — not used here.
    */
    'sevencav' => [
        'client_id' => env('SEVENCAV_OAUTH_CLIENT_ID'),
        'client_secret' => env('SEVENCAV_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('SEVENCAV_OAUTH_REDIRECT_URI'),
        'base_url' => env('SEVENCAV_FORUM_BASE_URL', 'https://7cav.us'),
        'authorize_url' => env('SEVENCAV_OAUTH_AUTHORIZE_URL', 'https://7cav.us/oauth2/authorize'),
        // Read-only scopes only (proposal §10.2). XenForo default is `user:read`;
        // confirm the exact identifier granted to the client in the 7cav.us admin.
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SEVENCAV_OAUTH_SCOPES', 'user:read')),
        ))),
    ],

    /*
    | api.7cav.us — the Cav roster/milpac API (Story 1.6, AD-3's second seam). A *different*
    | service from the forum above: read-only JSON authenticated with a static `cav7_…` bearer
    | key rather than OAuth, so it carries no user context and can never write. The key is a
    | machine credential read from env only (AD-23, NFR2).
    */
    'cav' => [
        'base_url' => env('CAV_API_BASE_URL', 'https://api.7cav.us'),
        'api_key' => env('CAV_API_KEY'),
        // Wall-clock ceiling for a first-login roster sweep, which makes up to three calls on the
        // synchronous login path. Configurable so the safety valve can be exercised in a test.
        'sweep_budget_seconds' => env('CAV_API_SWEEP_BUDGET_SECONDS', 12.0),
    ],

    /*
    | CRCON (Hell Let Loose community RCON) — the read-only HLL presence seam (Story 7.3, AD-3).
    | Machine credential read from env only (AD-23). No default base URL: it is the unit's own
    | server, so an unset value is a misconfiguration the seam reports as such.
    */
    'crcon' => [
        'base_url' => env('CRCON_BASE_URL'),
        'api_key' => env('CRCON_API_KEY'),
    ],

    /*
    | BattleMetrics — the read-only Arma Reforger presence seam, and HLL fallback (Story 7.4, AD-3).
    | Machine credential read from env only (AD-23). The base URL defaults to the public API;
    | the server id names the unit's own game server, so unset values are reported as misconfigured.
    */
    'battlemetrics' => [
        'base_url' => env('BATTLEMETRICS_BASE_URL', 'https://api.battlemetrics.com'),
        'api_key' => env('BATTLEMETRICS_API_KEY'),
        'server_id' => env('BATTLEMETRICS_SERVER_ID'),
    ],

];
