<?php

declare(strict_types=1);

/**
 * E2E sandbox-only : flux boisson → REMIS_SERVEUR → backdate → runner minuit (CLI, sans session web).
 *
 * Usage : php scripts/sandbox_midnight_sales_e2e.php
 *
 * Prérequis : .env avec DB valide, SANDBOX_RESTAURANT_CODES contenant test-ventes-minuit.
 */

use App\Core\Container;
use App\Core\Database;
use App\Support\SandboxMidnightSalesE2eRunner;
use App\Services\AuditService;
use App\Services\AuditQueryService;
use App\Services\AuthorizationService;
use App\Services\AuthService;
use App\Services\CashService;
use App\Services\CorrectionRequestService;
use App\Services\IncidentService;
use App\Services\KitchenService;
use App\Services\ManagerResolutionService;
use App\Services\MenuAdminService;
use App\Services\OperationalResetService;
use App\Services\PlatformSettingsService;
use App\Services\RegularizationGateService;
use App\Services\ReportService;
use App\Services\RestaurantAdminService;
use App\Services\RoleAdminService;
use App\Services\SalesService;
use App\Services\StaffDisciplineService;
use App\Services\StockControlReportService;
use App\Services\StockResetService;
use App\Services\StockService;
use App\Services\SubscriptionService;
use App\Services\SuperAdminOperationsService;
use App\Services\TenantProvisioningService;
use App\Services\TenantResolverService;
use App\Services\UploadService;
use App\Services\UserAdminService;

function sandbox_e2e_register_container(array $config): void
{
    $container = Container::getInstance();
    $container->set('config', $config);
    $database = new Database($config['database']);
    $container->set('db', $database);
    $container->set('auth', new AuthService($database));
    $container->set('authz', new AuthorizationService($database));
    $container->set('audit', new AuditService($database));
    $container->set('tenantProvisioning', new TenantProvisioningService($database));
    $container->set('subscriptionService', new SubscriptionService($database));
    $container->set('uploadService', new UploadService());
    $container->set('restaurantAdmin', new RestaurantAdminService($database));
    $container->set('roleAdmin', new RoleAdminService($database));
    $container->set('userAdmin', new UserAdminService($database));
    $container->set('menuAdmin', new MenuAdminService($database));
    $container->set('auditQuery', new AuditQueryService($database));
    $container->set('tenantResolver', new TenantResolverService($database));
    $container->set('platformSettings', new PlatformSettingsService($database));
    $container->set('incidentService', new IncidentService($database));
    $stockService = new StockService($database);
    $container->set('stockService', $stockService);
    $container->set('correctionService', new CorrectionRequestService($database));
    $container->set('kitchenService', new KitchenService($database));
    $container->set('salesService', new SalesService($database));
    $container->set('cashService', new CashService($database));
    $reportService = new ReportService($database);
    $container->set('reportService', $reportService);
    $container->set('stockControlReport', new StockControlReportService($database, $reportService));
    $container->set('staffDiscipline', new StaffDisciplineService($database));
    $container->set('managerResolution', new ManagerResolutionService($database));
    $container->set('regularizationGate', new RegularizationGateService($database));
    $container->set('operationalResetService', new OperationalResetService($database));
    $container->set('stockResetService', new StockResetService($database));
    $container->set('superAdminOperationsService', new SuperAdminOperationsService($database));
}

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

$config = [
    'app' => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
];

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

sandbox_e2e_register_container($config);

$result = SandboxMidnightSalesE2eRunner::execute('CLI');
foreach ($result['report_lines'] as $line) {
    echo $line . "\n";
}

exit($result['exit_code']);
