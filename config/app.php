<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Badiboss Restaurant SaaS'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Kinshasa'),
    'session_name' => env('SESSION_NAME', 'badiboss_session'),
    'api_token_ttl_hours' => (int) env('API_TOKEN_TTL_HOURS', '24'),
];
