<?php

declare(strict_types=1);

/**
 * Smoke test sans base : chargement des classes et règles actorCanResolve.
 * Usage : php scripts/railway_manager_resolution_smoke.php
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Support/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = BASE_PATH . '/app/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Services\ManagerResolutionService;

$managerOk = ManagerResolutionService::actorCanResolve(['role_code' => 'manager', 'scope' => 'tenant', 'id' => 1]);
$ownerOk = ManagerResolutionService::actorCanResolve(['role_code' => 'owner', 'scope' => 'tenant', 'id' => 1]);
$superOk = ManagerResolutionService::actorCanResolve(['role_code' => 'super_admin', 'scope' => 'super_admin', 'id' => 1]);
$serverNo = ManagerResolutionService::actorCanResolve(['role_code' => 'cashier_server', 'scope' => 'tenant', 'id' => 1]);

if ($managerOk && $ownerOk && $superOk && !$serverNo) {
    fwrite(STDOUT, "railway_manager_resolution_smoke OK\n");
    exit(0);
}

fwrite(STDERR, "railway_manager_resolution_smoke FAIL\n");
exit(1);
