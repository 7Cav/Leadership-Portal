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

];
