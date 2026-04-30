<?php
declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', env('DB_NAME', 'badiboss_restaurant_saas')),
    'username' => env('DB_USERNAME', env('DB_USER', 'root')),
    'password' => env('DB_PASSWORD', env('DB_PASS', '')),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
];
