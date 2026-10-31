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
        // Reading leave threads (Story 4.1) uses a forum API key, a machine credential created in the
        // forum's admin panel, not a user's OAuth token. Env only (AD-23); empty means the forum
        // cannot be read for leave.
        'api_key' => env('SEVENCAV_FORUM_API_KEY'),
        // The forum sections (node ids) that hold leave threads, as `id:type` pairs, comma separated,
        // e.g. `180:loa,341:eloa`. The type is loa or eloa. The 7Cav forum keeps one LOA
        // section per battalion (plus HHQ and non-battalion) and one section for ELOA. Only these are
        // ever read; none configured means no leave section is read. A malformed pair throws, so a typo
        // fails at boot instead of silently leaving a section unread.
        'loa_nodes' => collect(explode(',', (string) env('SEVENCAV_FORUM_LOA_NODES', '')))
            ->map(fn (string $pair) => trim($pair))
            ->filter(fn (string $pair) => $pair !== '')
            ->mapWithKeys(function (string $pair) {
                [$id, $type] = array_pad(array_map('trim', explode(':', $pair)), 2, null);

                if (! ctype_digit((string) $id) || (int) $id < 1 || ! in_array($type, ['loa', 'eloa'], true)) {
                    throw new InvalidArgumentException("SEVENCAV_FORUM_LOA_NODES: bad entry '{$pair}', expected id:loa|eloa");
                }

                return [(int) $id => $type];
            })
            ->all(),
        // The forum section where personnel action forms (PAFs) are filed. Only the creation date of a thread
        // there is read, to date an ELOA, never its contents. Unset means PAFs are not followed and an ELOA
        // starts on the day its report was posted.
        'paf_node' => filter_var(env('SEVENCAV_FORUM_PAF_NODE'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
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
        // One CRCON per game server. CRCON_SERVERS lists their base URLs, comma separated; an entry may carry its own
        // key after a `|`. Without it, CRCON_BASE_URL is the one instance. `base_url` stays filled either way, so
        // "is CRCON configured" keeps one answer.
        'base_url' => env('CRCON_BASE_URL') ?: (trim(explode('|', explode(',', (string) env('CRCON_SERVERS', ''))[0])[0]) ?: null),
        'servers' => env('CRCON_SERVERS'),
        'api_key' => env('CRCON_API_KEY'),
    ],

    /*
    | BattleMetrics — the read-only Arma Reforger presence seam, and HLL fallback (Story 7.4, AD-3).
    | Machine credential read from env only (AD-23). The base URL defaults to the public API.
    | `server_id` is the legacy single server, read only for a game with no server selected on the
    | Servers page (#389); a selected server carries its own id in the portal.
    */
    'battlemetrics' => [
        'base_url' => env('BATTLEMETRICS_BASE_URL', 'https://api.battlemetrics.com'),
        'api_key' => env('BATTLEMETRICS_API_KEY'),
        'server_id' => env('BATTLEMETRICS_SERVER_ID'),
    ],

    /*
    | Nightwatch — the read-only WARDOGS presence seam (Story 7.10, AD-3). Machine credential
    | read from env only (AD-23). `server_ids` is a comma list of the workspace's server ids: the
    | legacy list, read only for a game with no server selected on the Servers page (#389).
    */
    'nightwatch' => [
        'base_url' => env('NIGHTWATCH_BASE_URL', 'https://nightwatch.to/api/public/stats'),
        'api_key' => env('NIGHTWATCH_API_KEY'),
        'server_ids' => env('NIGHTWATCH_SERVER_IDS'),
    ],

    /*
    | Google Drive — where a copy of every counseling document (DCS, LOR) goes (#381, AD-3). A service
    | account's machine credential, read from env only (AD-23): its email and private key from the
    | account's JSON key file, and the id of the folder (in a shared drive) it uploads into. Any of the
    | three unset means documents stay in the portal only and the upload is skipped.
    */
    'google_drive' => [
        'service_account_email' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL'),
        'private_key' => env('GOOGLE_DRIVE_PRIVATE_KEY'),
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
    ],

    // The reports Google Sheet a sent weekly staff report is copied to, through the same service account. With no
    // spreadsheet id nothing is pushed.
    'google_sheets' => [
        'spreadsheet_id' => env('GOOGLE_SHEETS_REPORTS_SPREADSHEET_ID'),
        'staff_tab' => env('GOOGLE_SHEETS_STAFF_TAB', 'Weekly Staff Report 2O26'),
        'battalion_tab' => env('GOOGLE_SHEETS_BATTALION_TAB', 'Weekly Battalion Report 2O26'),
    ],

];
