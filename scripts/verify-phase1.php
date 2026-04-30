<?php

declare(strict_types=1);

$requiredFiles = [
    'public/index.php',
    'public/.htaccess',
    'bootstrap/app.php',
    'config/app.php',
    'config/database.php',
    'routes/web.php',
    'routes/api.php',
    'database/schema.sql',
    'database/seed.sql',
    'docs/architecture.md',
    'docs/whitelabel-strategy.md',
    'docs/deployment-hostinger.md',
];

$basePath = dirname(__DIR__);
$missing = [];

foreach ($requiredFiles as $file) {
    if (!is_file($basePath . DIRECTORY_SEPARATOR . $file)) {
        $missing[] = $file;
    }
}

echo "Phase 1 verification\n";
echo 'Missing files: ' . count($missing) . "\n";

if ($missing !== []) {
    foreach ($missing as $file) {
        echo '- ' . $file . "\n";
    }
    exit(1);
}

echo "Structure OK\n";
