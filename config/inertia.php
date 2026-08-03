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
    */

    'pages' => [

        'ensure_pages_exist' => false,

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

];
