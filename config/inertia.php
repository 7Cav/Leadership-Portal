<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Inertia defaults `paths` to `resources/js/pages` (lowercase). This repo puts
    | pages in `resources/js/Pages` — see CONTRIBUTING.md §4 — so the default only
    | ever resolved by accident on case-insensitive macOS filesystems and broke on
    | case-sensitive Linux CI, where `assertInertia()->component()` could not find
    | the file. Pin the path explicitly.
    |
    | The whole block is repeated because Laravel merges package config shallowly:
    | overriding `pages` replaces the key outright, so omitting `extensions` or
    | `ensure_pages_exist` here would silently drop them.
    |
    | `ensure_pages_exist` makes `Inertia::render()` throw when the named page has no
    | file under `paths`. It is on in the test suite (phpunit.xml), so a renamed or
    | misspelled page fails every test that renders it, not only the ones that call
    | `assertInertia()->component()`. Off by default: a server running built assets
    | need not ship `resources/js`.
    |
    */

    'pages' => [

        'ensure_pages_exist' => (bool) env('INERTIA_ENSURE_PAGES_EXIST', false),

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | On, unconditionally, and not left to `INERTIA_ENCRYPT_HISTORY` (the vendor
    | default reads that key and falls back to off). Story 1.5 AC #2 says "back"
    | after sign-out must not repaint the last authenticated page. Inertia keeps
    | every visited page object in `history.state` and re-renders it on popstate
    | without a request. `Inertia::clearHistory()` in the logout action only
    | deletes the key that encrypts that state, so with encryption off there is no
    | key to lose and the plaintext page object survives logout.
    |
    | With this on, each page is encrypted under a key held in sessionStorage.
    | Logout drops that key, the client can no longer decrypt the old entries, and
    | a popstate onto one falls back to a real request, which the `auth`
    | middleware bounces to login.
    |
    | Set app-wide rather than with the `inertia.encrypt` middleware on the `auth`
    | group: then no authenticated page can miss it by being registered outside
    | that group. Encrypting the guest-only login screen costs nothing.
    |
    | Web Crypto only exists in a secure context, so production must be served
    | over HTTPS (localhost counts as secure). Over plain HTTP the client logs a
    | warning and stores the page unencrypted.
    |
    */

    'history' => [

        'encrypt' => true,

    ],

];
