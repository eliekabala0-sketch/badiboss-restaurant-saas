<?php
declare(strict_types=1);

$charset = env('DB_CHARSET', 'utf8mb4');
$candidates = [];

$appendCandidate = static function (array &$list, array $candidate): void {
    if (trim((string) ($candidate['host'] ?? '')) === '') {
        return;
    }

    if ((int) ($candidate['port'] ?? 0) <= 0) {
        $candidate['port'] = 3306;
    }

    if (trim((string) ($candidate['database'] ?? '')) === '') {
        $candidate['database'] = 'badiboss_restaurant_saas';
    }

    $list[] = $candidate;
};

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

    $appendCandidate($candidates, [
        'source' => $source,
        'host' => (string) ($parts['host'] ?? ''),
        'port' => (int) ($parts['port'] ?? 3306),
        'database' => ltrim((string) ($parts['path'] ?? '/badiboss_restaurant_saas'), '/'),
        'username' => rawurldecode((string) ($parts['user'] ?? 'root')),
        'password' => rawurldecode((string) ($parts['pass'] ?? 'root')),
        'charset' => $charset,
    ]);
}

$mysqlHost = trim((string) env('MYSQLHOST', ''));
if ($mysqlHost !== '') {
    $appendCandidate($candidates, [
        'source' => 'MYSQLHOST',
        'host' => $mysqlHost,
        'port' => (int) env('MYSQLPORT', '3306'),
        'database' => env('MYSQLDATABASE', 'badiboss_restaurant_saas'),
        'username' => env('MYSQLUSER', 'root'),
        'password' => env('MYSQLPASSWORD', 'root'),
        'charset' => $charset,
    ]);
}

$dbHost = trim((string) env('DB_HOST', ''));
if ($dbHost !== '') {
    $appendCandidate($candidates, [
        'source' => 'DB_HOST',
        'host' => $dbHost,
        'port' => (int) env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', env('DB_NAME', 'badiboss_restaurant_saas')),
        'username' => env('DB_USERNAME', env('DB_USER', 'root')),
        'password' => env('DB_PASSWORD', env('DB_PASS', 'root')),
        'charset' => $charset,
    ]);
}

$appendCandidate($candidates, [
    'source' => 'local_fallback',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'badiboss_restaurant_saas',
    'username' => 'root',
    'password' => 'root',
    'charset' => $charset,
]);

return [
    'host' => (string) ($candidates[0]['host'] ?? '127.0.0.1'),
    'port' => (int) ($candidates[0]['port'] ?? 3306),
    'database' => (string) ($candidates[0]['database'] ?? 'badiboss_restaurant_saas'),
    'username' => (string) ($candidates[0]['username'] ?? 'root'),
    'password' => (string) ($candidates[0]['password'] ?? 'root'),
    'charset' => $charset,
    'source' => (string) ($candidates[0]['source'] ?? 'local_fallback'),
    'candidates' => $candidates,
];
