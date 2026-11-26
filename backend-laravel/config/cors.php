<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('ALLOWED_ORIGINS', 'http://localhost:5174,http://localhost:5173,http://127.0.0.1:5174,http://127.0.0.1:5173,https://tableza.vercel.app'))
    ))),

    'allowed_origins_patterns' => [
        '/^https:\/\/[a-z0-9-]+\.tableza\.vercel\.app$/',
        '/^https:\/\/[a-z0-9-]+\.tableza\.app$/',
        '/^http:\/\/[a-z0-9-]+\.localhost(:[0-9]+)?$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
