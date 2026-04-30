<?php
declare(strict_types=1);

$charset = env('DB_CHARSET', 'utf8mb4');

$resolved = [
    'source' => 'local_fallback',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'badiboss_restaurant_saas',
    'username' => 'root',
    'password' => 'root',
    'charset' => $charset,
];

$urlCandidates = [
    'MYSQL_URL' => env('MYSQL_URL'),
    'MYSQL_PUBLIC_URL' => env('MYSQL_PUBLIC_URL'),
];

foreach ($urlCandidates as $source => $value) {
    $value = trim((string) $value);
    if ($value === '') {
        continue;
    }

    $parts = parse_url($value);
    if ($parts === false || (($parts['scheme'] ?? '') !== 'mysql')) {
        continue;
    }

    $resolved = [
        'source' => $source,
        'host' => (string) ($parts['host'] ?? '127.0.0.1'),
        'port' => (int) ($parts['port'] ?? 3306),
        'database' => ltrim((string) ($parts['path'] ?? '/badiboss_restaurant_saas'), '/'),
        'username' => rawurldecode((string) ($parts['user'] ?? 'root')),
        'password' => rawurldecode((string) ($parts['pass'] ?? 'root')),
        'charset' => $charset,
    ];

    if ($resolved['database'] === '') {
        $resolved['database'] = 'badiboss_restaurant_saas';
    }

    return $resolved;
}

$mysqlHost = trim((string) env('MYSQLHOST', ''));
if ($mysqlHost !== '') {
    return [
        'source' => 'MYSQLHOST',
        'host' => $mysqlHost,
        'port' => (int) env('MYSQLPORT', '3306'),
        'database' => env('MYSQLDATABASE', 'badiboss_restaurant_saas'),
        'username' => env('MYSQLUSER', 'root'),
        'password' => env('MYSQLPASSWORD', 'root'),
        'charset' => $charset,
    ];
}

$dbHost = trim((string) env('DB_HOST', ''));
if ($dbHost !== '') {
    return [
        'source' => 'DB_HOST',
        'host' => $dbHost,
        'port' => (int) env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', env('DB_NAME', 'badiboss_restaurant_saas')),
        'username' => env('DB_USERNAME', env('DB_USER', 'root')),
        'password' => env('DB_PASSWORD', env('DB_PASS', 'root')),
        'charset' => $charset,
    ];
}

return $resolved;
