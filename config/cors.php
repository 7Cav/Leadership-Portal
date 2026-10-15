<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The portal is a same-origin Inertia app with no API for other sites, so no
    | path answers cross-origin requests. Without this file the framework default
    | gives `api/*` `Access-Control-Allow-Origin: *`, which any future api route
    | would inherit. List a path here only when a cross-origin caller needs it.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
