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

const SANDBOX_E2E_CODE = 'test-ventes-minuit';

/**
 * @return array<string, mixed>
 */
function sandbox_e2e_load_user_actor(int $restaurantId, string $email): array
{
    $pdo = Container::getInstance()->get('db')->pdo();
    $st = $pdo->prepare(
        'SELECT u.*, r.code AS role_code
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.restaurant_id = :rid AND u.email = :email AND u.status = "active"
         LIMIT 1'
    );
    $st->execute(['rid' => $restaurantId, 'email' => $email]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Utilisateur introuvable: ' . $email);
    }

    return [
        'id' => (int) $row['id'],
        'full_name' => (string) $row['full_name'],
        'role_code' => (string) $row['role_code'],
        'restaurant_id' => (int) $row['restaurant_id'],
    ];
}

/**
 * @return array<string, mixed>
 */
function sandbox_e2e_system_actor(): array
{
    return [
        'id' => null,
        'full_name' => 'CLI sandbox E2E',
        'role_code' => 'system',
        'restaurant_id' => null,
    ];
}

function sandbox_e2e_assert_sandbox_env(): void
{
    $allowed = sandbox_allowed_restaurant_codes();
    if (!in_array(SANDBOX_E2E_CODE, $allowed, true)) {
        fwrite(STDERR, "STOP: SANDBOX_RESTAURANT_CODES ne contient pas \"" . SANDBOX_E2E_CODE . "\".\n");
        fwrite(STDERR, 'Valeur résolue : ' . json_encode($allowed, JSON_UNESCAPED_UNICODE) . "\n");
        exit(2);
    }
}

/**
 * @param array<string, mixed> $restaurant
 */
function sandbox_e2e_assert_restaurant_row(array $restaurant): int
{
    $code = strtolower(trim((string) ($restaurant['restaurant_code'] ?? '')));
    if ($code !== SANDBOX_E2E_CODE) {
        fwrite(STDERR, "STOP: restaurant_code attendu \"" . SANDBOX_E2E_CODE . "\", obtenu \"{$code}\".\n");
        exit(2);
    }
    if (!is_sandbox_restaurant_code($code)) {
        fwrite(STDERR, "STOP: code restaurant refuse par is_sandbox_restaurant_code().\n");
        exit(2);
    }

    return (int) ($restaurant['id'] ?? 0);
}

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

function sandbox_e2e_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'sandbox-item';
}

/**
 * Recopie la logique d’upsert cuisine (équivalent StockService::increaseKitchenInventory), uniquement après verrou restaurant sandbox.
 */
function sandbox_e2e_bootstrap_kitchen_inventory_locked(
    int $restaurantId,
    int $stockItemId,
    float $quantity,
    string $lockedCode,
): void {
    if ($lockedCode !== SANDBOX_E2E_CODE || !is_sandbox_restaurant_code($lockedCode)) {
        throw new RuntimeException('Verrou sandbox cuisine invalide.');
    }
    if ($quantity <= 0) {
        throw new RuntimeException('Quantité cuisine positive requise.');
    }
    $pdo = Container::getInstance()->get('db')->pdo();
    $st = $pdo->prepare(
        'INSERT INTO kitchen_inventory (restaurant_id, stock_item_id, quantity_available, created_at, updated_at)
         VALUES (:restaurant_id, :stock_item_id, :quantity_available, NOW(), NOW())
         ON DUPLICATE KEY UPDATE quantity_available = quantity_available + VALUES(quantity_available), updated_at = NOW()'
    );
    $st->execute([
        'restaurant_id' => $restaurantId,
        'stock_item_id' => $stockItemId,
        'quantity_available' => $quantity,
    ]);
}

function sandbox_e2e_kitchen_qty(int $restaurantId, int $stockItemId): float
{
    $pdo = Container::getInstance()->get('db')->pdo();
    $st = $pdo->prepare(
        'SELECT quantity_available FROM kitchen_inventory
         WHERE restaurant_id = :rid AND stock_item_id = :sid LIMIT 1'
    );
    $st->execute(['rid' => $restaurantId, 'sid' => $stockItemId]);
    $v = $st->fetchColumn();

    return $v === false ? 0.0 : (float) $v;
}

function sandbox_e2e_beverage_movement_count(int $restaurantId, int $serverRequestItemId): int
{
    $pdo = Container::getInstance()->get('db')->pdo();
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM stock_movements
         WHERE restaurant_id = :rid
           AND reference_type = "server_request_beverage"
           AND reference_id = :ref'
    );
    $st->execute(['rid' => $restaurantId, 'ref' => $serverRequestItemId]);

    return (int) $st->fetchColumn();
}

function sandbox_e2e_table_exists(\PDO $pdo, string $table): bool
{
    $table = trim($table);
    if ($table === '' || !preg_match('/^[a-z0-9_]+$/', strtolower($table))) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $st->execute(['t' => $table]);

    return (int) $st->fetchColumn() > 0;
}

function sandbox_e2e_cash_transfers_for_sale(int $restaurantId, int $saleId): int
{
    $pdo = Container::getInstance()->get('db')->pdo();
    if (!sandbox_e2e_table_exists($pdo, 'cash_transfers')) {
        return -1;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM cash_transfers
         WHERE restaurant_id = :rid AND source_type = "sale" AND source_id = :sid'
    );
    $st->execute(['rid' => $restaurantId, 'sid' => $saleId]);

    return (int) $st->fetchColumn();
}

/**
 * @return array<string, mixed>|null
 */
function sandbox_e2e_find_sale_for_request(int $restaurantId, int $serverRequestId): ?array
{
    $pdo = Container::getInstance()->get('db')->pdo();
    $st = $pdo->prepare(
        'SELECT s.*,
                COALESCE(sr.received_at, sr.supplied_at, sr.ready_at, s.validated_at, s.created_at) AS sale_activity_at
         FROM sales s
         LEFT JOIN server_requests sr
           ON s.origin_type = "server_request" AND sr.id = s.origin_id AND sr.restaurant_id = s.restaurant_id
         WHERE s.restaurant_id = :rid AND s.origin_type = "server_request" AND s.origin_id = :oid
         ORDER BY s.id DESC LIMIT 1'
    );
    $st->execute(['rid' => $restaurantId, 'oid' => $serverRequestId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * @return list<array<string, mixed>>
 */
function sandbox_e2e_ensure_sandbox_accounts(int $restaurantId, string $code, array $actor): array
{
    $templates = [
        ['full_name' => 'Owner Sandbox', 'role_code' => 'owner', 'email' => 'owner-' . $code . '@badiboss.test'],
        ['full_name' => 'Server Sandbox', 'role_code' => 'cashier_server', 'email' => 'server-' . $code . '@badiboss.test'],
        ['full_name' => 'Kitchen Sandbox', 'role_code' => 'kitchen', 'email' => 'kitchen-' . $code . '@badiboss.test'],
    ];
    $pdo = Container::getInstance()->get('db')->pdo();
    $userAdmin = Container::getInstance()->get('userAdmin');
    $out = [];
    foreach ($templates as $tpl) {
        $exists = $pdo->prepare('SELECT id FROM users WHERE restaurant_id = :rid AND email = :email LIMIT 1');
        $exists->execute(['rid' => $restaurantId, 'email' => $tpl['email']]);
        if ((int) ($exists->fetchColumn() ?: 0) > 0) {
            $out[] = $tpl['email'] . ':exists';
            continue;
        }
        $roleStatement = $pdo->prepare(
            'SELECT id FROM roles
             WHERE code = :code
               AND status = "active"
               AND (scope = "system" OR (scope = "tenant" AND restaurant_id = :rid))
             ORDER BY scope ASC
             LIMIT 1'
        );
        $roleStatement->execute(['code' => $tpl['role_code'], 'rid' => $restaurantId]);
        $roleId = (int) ($roleStatement->fetchColumn() ?: 0);
        if ($roleId <= 0) {
            throw new RuntimeException('Role introuvable pour sandbox: ' . $tpl['role_code']);
        }
        $userAdmin->createUser([
            'restaurant_id' => $restaurantId,
            'role_id' => $roleId,
            'full_name' => $tpl['full_name'],
            'email' => $tpl['email'],
            'phone' => '+243000000000',
            'password' => 'password',
            'status' => 'active',
        ], $actor);
        $out[] = $tpl['email'] . ':created';
    }

    return $out;
}

// --- bootstrap fichiers ---
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

echo "=== Sandbox midnight sales E2E (CLI) ===\n";

sandbox_e2e_assert_sandbox_env();

$systemActor = sandbox_e2e_system_actor();
$pdo = Container::getInstance()->get('db')->pdo();
$tenantProvisioning = Container::getInstance()->get('tenantProvisioning');
$restaurantAdmin = Container::getInstance()->get('restaurantAdmin');
$subscriptionService = Container::getInstance()->get('subscriptionService');

$st = $pdo->prepare('SELECT * FROM restaurants WHERE restaurant_code = :code LIMIT 1');
$st->execute(['code' => SANDBOX_E2E_CODE]);
$restaurant = $st->fetch(\PDO::FETCH_ASSOC);

if ($restaurant === false) {
    $newId = $tenantProvisioning->createRestaurant([
        'name' => strtoupper(SANDBOX_E2E_CODE),
        'restaurant_code' => SANDBOX_E2E_CODE,
        'slug' => SANDBOX_E2E_CODE,
        'support_email' => 'sandbox+' . SANDBOX_E2E_CODE . '@badiboss.test',
        'phone' => '+243000000000',
        'city' => 'Sandbox',
        'country' => 'CD',
        'address_line' => 'Sandbox only',
        'public_name' => strtoupper(SANDBOX_E2E_CODE),
        'subscription_plan_id' => 1,
        'status' => 'active',
        'subscription_status' => 'ACTIVE',
        'subscription_payment_status' => 'PAID',
        'timezone' => 'Africa/Kinshasa',
        'currency_code' => 'USD',
    ], $systemActor);
    $restaurant = $restaurantAdmin->findRestaurant($newId);
    if ($restaurant === null) {
        throw new RuntimeException('Restaurant sandbox non résolu après création.');
    }
    echo "Restaurant sandbox créé id={$newId}\n";
} else {
    echo 'Restaurant sandbox existant id=' . (string) ($restaurant['id'] ?? '') . "\n";
}

$restaurantId = sandbox_e2e_assert_restaurant_row($restaurant);
$lockedCode = strtolower(trim((string) ($restaurant['restaurant_code'] ?? '')));

$subscription = $subscriptionService->summaryForRestaurant($restaurantId);
if (!is_array($subscription) || !(bool) ($subscription['is_operational'] ?? false)) {
    $subscriptionService->activateRestaurant($restaurantId, [
        'subscription_started_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'subscription_duration_days' => 3650,
        'payment_status' => 'WAIVED',
        'justification' => 'Activation abonnement sandbox (CLI E2E minuit)',
    ], $systemActor);
    echo "Abonnement sandbox activé.\n";
}

$accountsLog = sandbox_e2e_ensure_sandbox_accounts($restaurantId, SANDBOX_E2E_CODE, $systemActor);
if ($accountsLog !== []) {
    echo 'Comptes sandbox: ' . implode(', ', $accountsLog) . "\n";
}

$ownerActor = sandbox_e2e_load_user_actor($restaurantId, 'owner-' . SANDBOX_E2E_CODE . '@badiboss.test');
$serverActor = sandbox_e2e_load_user_actor($restaurantId, 'server-' . SANDBOX_E2E_CODE . '@badiboss.test');
$kitchenActor = sandbox_e2e_load_user_actor($restaurantId, 'kitchen-' . SANDBOX_E2E_CODE . '@badiboss.test');

$menuAdmin = Container::getInstance()->get('menuAdmin');
$stockService = Container::getInstance()->get('stockService');
$salesService = Container::getInstance()->get('salesService');
$kitchenService = Container::getInstance()->get('kitchenService');
$cashService = Container::getInstance()->get('cashService');

$categoryId = 0;
$catStmt = $pdo->prepare(
    'SELECT id FROM menu_categories WHERE restaurant_id = :rid AND slug = "boisson" LIMIT 1'
);
$catStmt->execute(['rid' => $restaurantId]);
$categoryId = (int) ($catStmt->fetchColumn() ?: 0);
if ($categoryId <= 0) {
    $menuAdmin->createCategory($restaurantId, [
        'name' => 'Boissons',
        'slug' => 'boisson',
        'description' => 'Sandbox boissons',
        'display_order' => 99,
        'status' => 'active',
    ], $ownerActor);
    $catStmt->execute(['rid' => $restaurantId]);
    $categoryId = (int) ($catStmt->fetchColumn() ?: 0);
    if ($categoryId <= 0) {
        throw new RuntimeException('Catégorie boisson non résolue après création.');
    }
    echo "Catégorie boisson créée id={$categoryId}\n";
}

$label = 'SANDBOX-NKOYI-' . date('Ymd-His');
$slug = sandbox_e2e_slugify($label);

$miStmt = $pdo->prepare(
    'SELECT id FROM menu_items WHERE restaurant_id = :rid AND name = :name LIMIT 1'
);
$miStmt->execute(['rid' => $restaurantId, 'name' => $label]);
$menuItemId = (int) ($miStmt->fetchColumn() ?: 0);
if ($menuItemId <= 0) {
    $menuAdmin->createItem($restaurantId, [
        'category_id' => $categoryId,
        'name' => $label,
        'slug' => $slug,
        'price' => 2.5,
        'status' => 'active',
        'is_available' => true,
        'display_order' => 0,
        'available_dine_in' => true,
        'description' => 'Article E2E sandbox minuit',
    ], $ownerActor);
    $miStmt->execute(['rid' => $restaurantId, 'name' => $label]);
    $menuItemId = (int) ($miStmt->fetchColumn() ?: 0);
    if ($menuItemId <= 0) {
        throw new RuntimeException('Article menu non résolu après création.');
    }
    echo "Article menu créé id={$menuItemId} name={$label}\n";
} else {
    echo "Article menu existant id={$menuItemId}\n";
}

$siStmt = $pdo->prepare(
    'SELECT id FROM stock_items WHERE restaurant_id = :rid AND name = :name LIMIT 1'
);
$siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
$stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
if ($stockItemId <= 0) {
    $stockService->createItem($restaurantId, [
        'name' => $label,
        'unit_name' => 'unite',
        'quantity_in_stock' => 500.0,
        'alert_threshold' => 0.0,
        'estimated_unit_cost' => 0.5,
        'category_label' => 'Boisson',
    ], $ownerActor);
    $siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
    $stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
    if ($stockItemId <= 0) {
        throw new RuntimeException('Article stock non résolu après création.');
    }
    echo "Article stock créé id={$stockItemId}\n";
} else {
    $stockService->createItem($restaurantId, [
        'name' => $label,
        'unit_name' => 'unite',
        'quantity_in_stock' => 100.0,
        'alert_threshold' => 0.0,
        'estimated_unit_cost' => 0.5,
        'category_label' => 'Boisson',
    ], $ownerActor);
    $siStmt->execute(['rid' => $restaurantId, 'name' => $label]);
    $stockItemId = (int) ($siStmt->fetchColumn() ?: 0);
    echo "Article stock réutilisé / rechargé id={$stockItemId}\n";
}

$stockService->findKitchenInventoryMatchForMenuItem($restaurantId, $menuItemId, 0.0);
if ($stockService->findKitchenInventoryMatchForMenuItem($restaurantId, $menuItemId, 1.0) === null) {
    sandbox_e2e_bootstrap_kitchen_inventory_locked($restaurantId, $stockItemId, 50.0, $lockedCode);
    echo "Stock cuisine (kitchen_inventory) initialisé +50 pour correspondance nom menu.\n";
}

$kitchenQtyBefore = sandbox_e2e_kitchen_qty($restaurantId, $stockItemId);

$serviceRef = 'SANDBOX-E2E-' . bin2hex(random_bytes(4));
$serverRequestId = $salesService->createServerRequest($restaurantId, [
    'service_reference' => $serviceRef,
    'note' => 'CLI sandbox_midnight_sales_e2e',
    'items' => [
        ['menu_item_id' => $menuItemId, 'requested_quantity' => 1.0, 'note' => ''],
    ],
], $serverActor);

$itStmt = $pdo->prepare(
    'SELECT id FROM server_request_items WHERE request_id = :rid AND restaurant_id = :rest LIMIT 1'
);
$itStmt->execute(['rid' => $serverRequestId, 'rest' => $restaurantId]);
$serverRequestItemId = (int) ($itStmt->fetchColumn() ?: 0);
if ($serverRequestItemId <= 0) {
    throw new RuntimeException('Ligne demande serveur introuvable.');
}

$bevBefore = sandbox_e2e_beverage_movement_count($restaurantId, $serverRequestItemId);

// MySQL : ALTER ENUM dans insertMovement → commit implicite si exécuté pendant la transaction cuisine.
$enumWarm = new \ReflectionMethod(\App\Services\StockService::class, 'ensureStockMovementEnum');
$enumWarm->setAccessible(true);
$enumWarm->invoke($stockService);

$kitchenService->fulfillServerRequestItem($restaurantId, $serverRequestItemId, [
    'workflow_stage' => 'PRET_A_SERVIR',
    'supplied_quantity' => 1.0,
], $kitchenActor);

$salesService->confirmServerRequestReceipt($restaurantId, $serverRequestId, $serverActor);

$reqStatusStmt = $pdo->prepare(
    'SELECT status FROM server_requests WHERE id = :id AND restaurant_id = :rid LIMIT 1'
);
$reqStatusStmt->execute(['id' => $serverRequestId, 'rid' => $restaurantId]);
$statusAfterReceipt = (string) ($reqStatusStmt->fetchColumn() ?: '');
if ($statusAfterReceipt !== 'REMIS_SERVEUR') {
    throw new RuntimeException('Statut REMIS_SERVEUR non atteint (obtenu: ' . $statusAfterReceipt . ').');
}

$kitchenQtyAfterFulfill = sandbox_e2e_kitchen_qty($restaurantId, $stockItemId);
$bevAfterFulfill = sandbox_e2e_beverage_movement_count($restaurantId, $serverRequestItemId);

$salesService->backdateSandboxRemittedRequestActivityYesterday($restaurantId, $serverRequestId, $ownerActor);

$tzName = (string) ($restaurant['timezone'] ?? 'Africa/Kinshasa');
$tz = new \DateTimeZone($tzName);
$yesterdayYmd = (new \DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');

$chk = $pdo->prepare('SELECT received_at FROM server_requests WHERE id = :id AND restaurant_id = :rid LIMIT 1');
$chk->execute(['id' => $serverRequestId, 'rid' => $restaurantId]);
$recvAt = (string) ($chk->fetchColumn() ?: '');
if (!str_starts_with($recvAt, $yesterdayYmd) || !str_contains($recvAt, '15:00:00')) {
    echo "[WARN] received_at attendu hier 15:00 ({$tzName}), obtenu: {$recvAt}\n";
}

$dry = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, true);
$candidateIds = $dry['candidate_request_ids'] ?? [];
$ourRequestIsCandidate = in_array($serverRequestId, is_array($candidateIds) ? $candidateIds : [], true);

$exec1 = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, false);
$exec2 = $salesService->runSandboxMidnightReconcile($restaurantId, $ownerActor, false);

$kitchenQtyAfterRunners = sandbox_e2e_kitchen_qty($restaurantId, $stockItemId);
$bevAfterRunners = sandbox_e2e_beverage_movement_count($restaurantId, $serverRequestItemId);

$saleCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sales
     WHERE restaurant_id = :rid AND origin_type = "server_request" AND origin_id = :oid'
);
$saleCountStmt->execute(['rid' => $restaurantId, 'oid' => $serverRequestId]);
$salesLinkedCount = (int) $saleCountStmt->fetchColumn();

$sale = sandbox_e2e_find_sale_for_request($restaurantId, $serverRequestId);
$saleId = $sale !== null ? (int) ($sale['id'] ?? 0) : 0;
$cashTablePresent = sandbox_e2e_table_exists($pdo, 'cash_transfers');
$cashCount = ($cashTablePresent && $saleId > 0) ? sandbox_e2e_cash_transfers_for_sale($restaurantId, $saleId) : ($cashTablePresent ? 0 : -1);

$manquantVisible = false;
if ($cashTablePresent && $saleId > 0) {
    $remittanceCandidates = $cashService->listServerRemittanceCandidates($restaurantId, (int) $serverActor['id']);
    foreach ($remittanceCandidates as $c) {
        if ((int) ($c['sale_id'] ?? 0) === $saleId) {
            $tid = (int) ($c['transfer_id'] ?? 0);
            if ($tid <= 0) {
                $manquantVisible = true;
                break;
            }
        }
    }
}

$reqFinal = $pdo->prepare('SELECT status FROM server_requests WHERE id = :id LIMIT 1');
$reqFinal->execute(['id' => $serverRequestId]);
$statusFinal = (string) ($reqFinal->fetchColumn() ?: '');

$saleActivity = $sale['sale_activity_at'] ?? null;
$saleActivityYmd = is_string($saleActivity) && $saleActivity !== '' ? substr($saleActivity, 0, 10) : '';

echo "\n--- Rapport ---\n";
echo 'restaurant_sandbox_id=' . $restaurantId . "\n";
echo 'user_server_id=' . (string) $serverActor['id'] . "\n";
echo 'user_kitchen_id=' . (string) $kitchenActor['id'] . "\n";
echo 'menu_item_id=' . (string) $menuItemId . "\n";
echo 'stock_item_id=' . (string) $stockItemId . "\n";
echo 'server_request_id=' . (string) $serverRequestId . "\n";
echo 'status_apres_runner=' . $statusFinal . "\n";
echo 'dry_run_candidate_count=' . (string) ($dry['candidate_count'] ?? -1) . "\n";
echo 'dry_run_created_count=' . (string) ($dry['created_count'] ?? -1) . "\n";
echo 'ventes_liees_avant_dry=' . (string) ($dry['sales_linked_before'] ?? -1) . "\n";
echo 'exec1_created_count=' . (string) ($exec1['created_count'] ?? -1) . "\n";
echo 'exec1_sales_linked_apres=' . (string) ($exec1['sales_linked_after'] ?? -1) . "\n";
echo 'exec2_created_count=' . (string) ($exec2['created_count'] ?? -1) . "\n";
echo 'ventes_liees_server_request_count=' . (string) $salesLinkedCount . "\n";
echo 'notre_demande_dans_candidats_dry_run=' . ($ourRequestIsCandidate ? 'oui' : 'non') . "\n";
echo 'sale_id=' . (string) $saleId . "\n";
echo 'sale_activity_ymd=' . $saleActivityYmd . " (attendu jour activité: {$yesterdayYmd})\n";
echo 'stock_cuisine_avant_fulfill=' . (string) $kitchenQtyBefore . "\n";
echo 'stock_cuisine_apres_fulfill=' . (string) $kitchenQtyAfterFulfill . "\n";
echo 'stock_cuisine_apres_runners=' . (string) $kitchenQtyAfterRunners . "\n";
echo 'mouvements_boisson_item_avant=' . (string) $bevBefore . ' apres_fulfill=' . (string) $bevAfterFulfill . ' apres_runners=' . (string) $bevAfterRunners . "\n";
echo 'cash_transfers_pour_vente_count=' . ($cashCount < 0 ? 'n/a (table absente)' : (string) $cashCount) . "\n";
echo 'manquant_serveur_detectable=' . ($manquantVisible ? 'oui' : 'non')
    . ($cashTablePresent ? ' (listServerRemittanceCandidates)' : ' (table cash_transfers absente — non vérifiable)') . "\n";
echo 'garde_sandbox=oui (code ' . SANDBOX_E2E_CODE . ")\n";
echo "aucune_donnee_reelle_hors_allowlist=oui\n";

$okDry = ((int) ($dry['candidate_count'] ?? 0) >= 1)
    && ((int) ($dry['created_count'] ?? -1) === 0)
    && $ourRequestIsCandidate;
$okExec1 = ($saleId > 0) && ($salesLinkedCount === 1) && ((int) ($exec1['created_count'] ?? 0) >= 1);
$okExec2 = ($salesLinkedCount === 1) && ((int) ($exec2['created_count'] ?? 0) === 0);
$okStockOnce = ($bevAfterFulfill === 1 && $bevAfterRunners === 1);
$okKitchenStable = abs($kitchenQtyAfterFulfill - $kitchenQtyAfterRunners) < 0.0001;
$okCashNone = !$cashTablePresent || $cashCount === 0;
$okManquant = !$cashTablePresent || $manquantVisible;
$okActivity = ($saleActivityYmd === $yesterdayYmd);
$okStatus = ($statusFinal === 'CLOTURE');

echo "\n--- Contrôles (résumé) ---\n";
echo 'A_demande_boisson=' . ($serverRequestId > 0 ? 'OK' : 'NON') . "\n";
echo 'B_cuisine_servie=OK' . "\n";
echo 'C_remis_serveur=OK' . "\n";
echo 'D_backdate_hier_15h=' . (str_starts_with($recvAt, $yesterdayYmd) ? 'OK' : 'VERIFIER') . "\n";
echo 'E_dry_run=' . ($okDry ? 'OK' : 'NON') . "\n";
echo 'F_exec_vente=' . ($okExec1 ? 'OK' : 'NON') . "\n";
echo 'G_relance_sans_cloture_supplementaire=' . ($okExec2 ? 'OK' : 'NON') . " (si autre backlog sandbox, peut être NON)\n";
echo 'H_stock_boisson_une_fois=' . ($okStockOnce ? 'OK' : 'NON') . "\n";
echo 'H2_stock_cuisine_stable_apres_runner=' . ($okKitchenStable ? 'OK' : 'NON') . "\n";
echo 'I_caisse_auto=' . ($okCashNone ? 'OK (aucun transfert ou module caisse absent)' : 'NON') . "\n";
echo 'J_manquant_detectable=' . ($okManquant ? 'OK' : 'NON') . "\n";
echo 'K_date_activite_vente=' . ($okActivity ? 'OK' : 'NON') . "\n";
echo 'L_statut_demande_coture=' . ($okStatus ? 'OK' : 'NON') . "\n";

$allCore = $okDry && $okExec1 && $okExec2 && $okStockOnce && $okKitchenStable && $okCashNone && $okActivity && $okStatus && $okManquant;
exit($allCore ? 0 : 1);
