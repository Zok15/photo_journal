<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
