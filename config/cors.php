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

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    // Mendukung multi-origin dipisah koma (misal: "https://jourweb.vercel.app,https://yourdomain.com,https://www.yourdomain.com")
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:3000')))),

    // Izinkan semua preview deployment dan domain vercel
    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['*'],

    // Cache preflight OPTIONS selama 24 jam agar browser tidak spam request preflight ke Hostinger
    'max_age' => 86400,

    'supports_credentials' => true,

];
