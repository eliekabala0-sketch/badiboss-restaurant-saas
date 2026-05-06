<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AuditService;
use App\Services\AuditQueryService;
use App\Services\AuthorizationService;
use App\Services\CashService;
use App\Services\CorrectionRequestService;
use App\Services\AuthService;
use App\Services\IncidentService;
use App\Services\KitchenService;
use App\Services\MenuAdminService;
use App\Services\OperationalResetService;
use App\Services\StockResetService;
use App\Services\PlatformSettingsService;
use App\Services\ReportService;
use App\Services\RestaurantAdminService;
use App\Services\RoleAdminService;
use App\Services\SalesService;
use App\Services\StockService;
use App\Services\SuperAdminOperationsService;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioningService;
use App\Services\TenantResolverService;
use App\Services\UploadService;
use App\Services\UserAdminService;

final class App
{
    public function __construct(
        private readonly array $config,
        private readonly Router $router
    ) {
    }

    public function run(): void
    {
        date_default_timezone_set($this->config['app']['timezone']);

        $request = Request::capture();
        if ($request->method === 'GET' && $request->uri === '/health') {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'OK';
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/health/db') {
            $this->respondDatabaseHealth();
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/health/db/debug') {
            $this->respondDatabaseHealthDebug();
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/admin/install-db-now') {
            $this->respondRailwayInstallDb($request);
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/admin/seed-minimal-now') {
            $this->respondRailwaySeedMinimal($request);
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/admin/runtime-repair-now') {
            $this->respondRailwayRuntimeRepair($request);
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/admin/cleanup-test-request') {
            $this->respondRailwayCleanupTestRequest($request);
            return;
        }
        if ($request->method === 'GET' && $request->uri === '/admin/inspect-test-request') {
            $this->respondRailwayInspectTestRequest($request);
            return;
        }

        $container = Container::getInstance();
        $container->set('config', $this->config);
        $container->set('router', $this->router);

        session_name($this->config['app']['session_name']);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = \base_path('storage/sessions');
            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0775, true);
            }

            if (is_dir($sessionPath) && is_writable($sessionPath)) {
                session_save_path($sessionPath);
            }

            session_start();
        }

        $database = new Database($this->config['database']);
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
        $container->set('stockService', new StockService($database));
        $container->set('correctionService', new CorrectionRequestService($database));
        $container->set('kitchenService', new KitchenService($database));
        $container->set('salesService', new SalesService($database));
        $container->set('cashService', new CashService($database));
        $container->set('reportService', new ReportService($database));
        $container->set('operationalResetService', new OperationalResetService($database));
        $container->set('stockResetService', new StockResetService($database));
        $container->set('superAdminOperationsService', new SuperAdminOperationsService($database));

        try {
            $this->router->dispatch($request);
        } catch (\RuntimeException $exception) {
            $this->handleApplicationError($request, ui_safe_message($exception->getMessage()), 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            $message = (bool) ($this->config['app']['debug'] ?? false)
                ? ui_safe_message($exception->getMessage())
                : 'Action impossible pour le moment. Veuillez reessayer ou contacter l administrateur.';

            $this->handleApplicationError($request, $message, 500);
        }
    }

    private function handleApplicationError(Request $request, string $message, int $statusCode): void
    {
        if (!headers_sent() && $request->method === 'POST') {
            $_SESSION['_flash']['error'] = $message;
            $redirectTo = $request->server['HTTP_REFERER'] ?? null;

            if (is_string($redirectTo) && $redirectTo !== '') {
                header('Location: ' . $redirectTo);
                exit;
            }
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo $message;
    }

    private function respondDatabaseHealth(): void
    {
        try {
            $database = new Database($this->config['database']);
            $database->pdo()->query('SELECT 1');

            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'DB OK';
            return;
        } catch (\Throwable $exception) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'DB ERROR: ' . $this->shortDatabaseError($exception->getMessage());
            return;
        }
    }

    private function shortDatabaseError(string $message): string
    {
        $message = preg_replace('/password\s*=\s*[^;\s]+/i', 'password=***', $message) ?? $message;
        $message = preg_replace('/\/\/([^:@\/]+):([^@\/]+)@/', '//***:***@', $message) ?? $message;

        return trim($message) !== '' ? $message : 'Connexion impossible';
    }

    private function respondDatabaseHealthDebug(): void
    {
        $config = $this->config['database'];

        try {
            $database = new Database($this->config['database']);
            $config = $database->config();
        } catch (\Throwable) {
            $candidates = $this->config['database']['candidates'] ?? [];
            if ($candidates !== []) {
                $config = end($candidates);
                if ($config === false) {
                    $config = $this->config['database'];
                }
            }
        }

        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'source=' . (string) ($config['source'] ?? 'unknown') . PHP_EOL;
        echo 'host=' . (string) ($config['host'] ?? 'unknown') . PHP_EOL;
        echo 'port=' . (string) ($config['port'] ?? 'unknown') . PHP_EOL;
        echo 'database=' . (string) ($config['database'] ?? 'unknown') . PHP_EOL;
        echo 'user=' . (string) ($config['username'] ?? 'unknown');
    }

    private function respondRailwayInstallDb(Request $request): void
    {
        if (!$this->canRunRailwayAdminTask($request)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Acces refuse';
            return;
        }

        require_once BASE_PATH . '/scripts/railway_install_db.php';
        $report = railway_install_db_report($this->config);

        http_response_code($report['blocking_errors'] === [] ? 200 : 500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo railway_install_db_render_report($report);
    }

    private function respondRailwaySeedMinimal(Request $request): void
    {
        if (!$this->canRunRailwayAdminTask($request)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Acces refuse';
            return;
        }

        require_once BASE_PATH . '/scripts/railway_seed_minimal.php';
        $report = railway_seed_minimal_report($this->config);

        http_response_code($report['errors'] === [] ? 200 : 500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo railway_seed_minimal_render_report($report);
    }

    private function respondRailwayRuntimeRepair(Request $request): void
    {
        if (!$this->canRunRailwayAdminTask($request)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Acces refuse';
            return;
        }

        require_once BASE_PATH . '/scripts/railway_runtime_repair.php';
        $report = railway_runtime_repair_report($this->config);

        http_response_code($report['errors'] === [] ? 200 : 500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo railway_runtime_repair_render_report($report);
    }

    private function respondRailwayCleanupTestRequest(Request $request): void
    {
        if (!$this->canRunRailwayAdminTask($request)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Acces refuse';
            return;
        }

        $serviceReference = trim((string) ($request->query['service_reference'] ?? ''));
        if ($serviceReference === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'service_reference requis';
            return;
        }

        require_once BASE_PATH . '/scripts/railway_cleanup_test_request.php';
        $report = railway_cleanup_test_request_report($this->config, $serviceReference);

        http_response_code(($report['status'] ?? 'error') === 'error' ? 500 : 200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo railway_cleanup_test_request_render_report($report);
    }

    private function respondRailwayInspectTestRequest(Request $request): void
    {
        if (!$this->canRunRailwayAdminTask($request)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Acces refuse';
            return;
        }

        $serviceReference = trim((string) ($request->query['service_reference'] ?? ''));
        if ($serviceReference === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'service_reference requis';
            return;
        }

        require_once BASE_PATH . '/scripts/railway_inspect_test_request.php';
        $report = railway_inspect_test_request_report($this->config, $serviceReference);

        http_response_code(($report['status'] ?? 'error') === 'error' ? 500 : 200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo railway_inspect_test_request_render_report($report);
    }

    private function canRunRailwayAdminTask(Request $request): bool
    {
        $appEnv = strtolower((string) ($this->config['app']['env'] ?? 'production'));
        $providedToken = (string) ($request->query['token'] ?? '');
        $expectedToken = (string) env('RAILWAY_INSTALL_TOKEN', 'BADIBOSS_INSTALL_2026');

        return $appEnv === 'production'
            && $providedToken !== ''
            && hash_equals($expectedToken, $providedToken);
    }
}
