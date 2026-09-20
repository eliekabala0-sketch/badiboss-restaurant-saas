<?php
declare(strict_types=1);

// Integration checks use connection-local TEMPORARY tables only.
// No account, setting, amount or audit row in the application database is changed.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Support/helpers.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});
$config = require BASE_PATH . '/config/database.php';
foreach ($config['candidates'] ?? [$config] as $candidate) {
    if (!in_array($candidate['host'], ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Run these checks against a local MySQL database only.');
    }
}
$db = new App\Core\Database($config);
$pdo = $db->pdo();
foreach (['roles', 'users', 'settings', 'restaurants', 'restaurant_branding', 'subscription_plans',
          'restaurant_modules', 'menu_categories', 'menu_items', 'audit_logs', 'permissions', 'role_permissions'] as $table) {
    $schema = file_get_contents(BASE_PATH . '/database/schema.sql');
    if (!preg_match('/CREATE TABLE ' . preg_quote($table, '/') . ' \([\s\S]*?\);/', $schema, $match)) {
        throw new RuntimeException('Missing fixture definition: ' . $table);
    }
    $definition = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $match[0]);
    if ($table === 'restaurants') {
        $legacy = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'restaurants' AND column_name = 'currency'")->fetchColumn();
        if ($legacy) {
            $definition = str_replace('CREATE TEMPORARY TABLE restaurants (', "CREATE TEMPORARY TABLE restaurants (\n    currency CHAR(3) NOT NULL DEFAULT 'USD',", $definition);
        }
    }
    $definition = preg_replace('/^\s*CONSTRAINT[^\n]*\n/m', '', $definition);
    $definition = preg_replace('/,\n\)/', "\n)", $definition);
    $pdo->exec($definition);
}
$container = App\Core\Container::getInstance();
$container->set('db', $db);
$container->set('audit', new App\Services\AuditService($db));
$container->set('roleAdmin', new App\Services\RoleAdminService($db));
$container->set('restaurantAdmin', new App\Services\RestaurantAdminService($db));
$container->set('subscriptionService', new class {
    public function canUseOperationalFeatures(array $user): bool { return true; }
});
$codes = [1 => 'owner', 2 => 'manager', 3 => 'kitchen', 4 => 'cashier_accountant', 5 => 'stock_manager', 6 => 'cashier_server', 7 => 'super_admin'];
$insert = $pdo->prepare('INSERT INTO roles (id, name, code, scope, status, created_at, updated_at) VALUES (?, ?, ?, "system", "active", NOW(), NOW())');
foreach ($codes as $id => $code) {
    $insert->execute([$id, $code, $code]);
}
$pdo->exec('INSERT INTO restaurants (id, name, restaurant_code, slug, status, currency_code, created_at, updated_at)
           VALUES (900001, "Functions fixture", "FUNCTIONS_FIXTURE", "functions-fixture", "active", "USD", NOW(), NOW()),
                  (900002, "Other fixture", "OTHER_FIXTURE", "other-fixture", "active", "USD", NOW(), NOW())');
$pdo->exec('INSERT INTO users (id, restaurant_id, role_id, full_name, email, password_hash, status, created_at, updated_at)
           VALUES (900011, 900001, 3, "Cook fixture", "cook-fixture@example.test", "", "active", NOW(), NOW()),
                  (900012, 900001, 3, "Other cook", "other-cook@example.test", "", "active", NOW(), NOW()),
                  (900021, 900002, 3, "Other tenant", "other-tenant@example.test", "", "active", NOW(), NOW())');
$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $count++;
};
$reject = static function (callable $action, string $message) use ($check): void {
    try { $action(); } catch (RuntimeException $e) { $check(true, $message); return; }
    $check(false, $message);
};
$owner = ['id' => 900099, 'restaurant_id' => 900001, 'role_code' => 'owner', 'full_name' => 'Owner fixture'];
$manager = array_replace($owner, ['role_code' => 'manager']);
$functions = new App\Services\UserFunctionService($db);
$base = ['id' => 900011, 'restaurant_id' => 900001, 'role_id' => 3, 'role_code' => 'kitchen', 'scope' => 'tenant'];
$functions->saveAdditionalRoles(900011, 900001, [3, 4, 5, 5], $owner);
$check(array_column($functions->rolesForUser(900011, 900001), 'id') === [3, 4, 5], 'kitchen + cashier + stock persisted and deduplicated');
$check(array_column($functions->rolesForUser(900012, 900001), 'id') === [3], 'other cook unchanged');
$cashService = new App\Services\CashService($db);
$recipientMethod = new ReflectionMethod($cashService, 'resolveCashierRecipient');
$check($recipientMethod->invoke($cashService, 900001, 900011) === 900011, 'cook with cashier function can receive remittances');
$reject(fn () => $recipientMethod->invoke($cashService, 900002, 900011), 'cashier recipient remains tenant-scoped');
$listMethod = new ReflectionMethod($cashService, 'listUsersByRoleCodes');
$check(count($listMethod->invoke($cashService, 900001, ['cashier_accountant', 'stock_manager'])) === 1, 'cashier and stock functions yield a single recipient');
$check($functions->rolesForUser(900011, 900002) === [], 'tenant isolation on read');
$reject(fn () => $functions->saveAdditionalRoles(900021, 900001, [4], $owner), 'foreign user denied');
$reject(fn () => $functions->saveAdditionalRoles(900011, 900001, [7], $owner), 'platform role denied');
$reject(fn () => $functions->saveAdditionalRoles(900011, 900001, [1], $owner), 'owner not a supplementary operational function');
$reject(fn () => $functions->saveAdditionalRoles(900011, 900001, [4], $base), 'cook cannot assign functions');
$reject(fn () => $functions->selectRole($base, 2), 'cannot switch to unassigned manager');
$authz = new App\Services\AuthorizationService($db);
foreach ([3 => 'kitchen.production.create', 4 => 'cash.checkout.create', 5 => 'stock.entry.create'] as $roleId => $ability) {
    $selected = $functions->selectRole($base, $roleId);
    $refreshed = (new App\Services\AuthService($db))->refreshSessionUser($selected);
    $check((int) $refreshed['role_id'] === $roleId, 'selected function survives real session refresh');
    $check($authz->can($refreshed, $ability), 'selected function receives operational permission');
    $check(!$authz->can($refreshed, 'tenant.access.manage'), 'operational role cannot manage personnel');
}
$selected = $functions->selectRole($base, 4);
$functions->saveAdditionalRoles(900011, 900001, [5], $manager);
$refreshed = (new App\Services\AuthService($db))->refreshSessionUser($selected);
$check((int) $refreshed['role_id'] === 3, 'revoking selected function falls back to primary on next request');
$reject(fn () => $functions->selectRole($base, 4), 'revoked role denied immediately');
$check((int) $pdo->query('SELECT role_id FROM users WHERE id = 900011')->fetchColumn() === 3, 'primary role unchanged');
$selected = $functions->selectRole($base, 5);
$pdo->exec('UPDATE roles SET status = "inactive" WHERE id = 5');
$check((int) $functions->applySessionRole($base, $selected)['role_id'] === 3, 'inactive supplementary function discarded');
$pdo->exec('UPDATE roles SET status = "active" WHERE id = 5');
$pdo->exec('UPDATE users SET role_id = 6 WHERE id = 900011');
$changedPrimary = (new App\Services\AuthService($db))->refreshSessionUser($selected);
$check((int) $changedPrimary['role_id'] === 6, 'primary job change resets old selection');
$pdo->exec('UPDATE users SET role_id = 3 WHERE id = 900011');
$container->set('audit', new class {
    public function log(array $entry): void { throw new RuntimeException('fixture audit failure'); }
});
$reject(fn () => $functions->saveAdditionalRoles(900011, 900001, [4], $owner), 'audit failure aborts assignment');
$check(array_column($functions->rolesForUser(900011, 900001), 'id') === [3, 5], 'failed save rolled back');
$container->set('audit', new App\Services\AuditService($db));
$functions->saveAdditionalRoles(900011, 900001, [], $manager);
$check(array_column($functions->rolesForUser(900011, 900001), 'id') === [3], 'manager can remove all supplementary functions');

$check(restaurant_currency(['currency_code' => 'CDF', 'currency' => 'USD']) === 'CDF', 'admin currency takes precedence over stale legacy column');
$check(format_money(1250, ['currency_code' => 'CDF', 'currency' => 'USD']) === '1 250 FC', 'CDF displayed by shared formatter');
$check(format_money(12.50, ['currency_code' => 'USD', 'currency' => 'CDF']) === '$12.50', 'USD displayed by shared formatter');
$restaurantAdmin = $container->get('restaurantAdmin');
$before = $restaurantAdmin->findRestaurant(900001);
$payload = array_intersect_key($before, array_flip(['name', 'slug', 'restaurant_code', 'legal_name', 'support_email', 'phone', 'country', 'city', 'address_line', 'timezone', 'currency_code', 'subscription_plan_id']));
$payload['currency_code'] = 'CDF';
$restaurantAdmin->updateRestaurant(900001, $payload, $owner);
$after = $restaurantAdmin->findRestaurant(900001);
$check($after['currency_code'] === 'CDF' && (!isset($after['currency']) || $after['currency'] === 'CDF'), 'admin update synchronizes currency columns');
$check(restaurant_currency(900001) === 'CDF', 'fresh restaurant context uses admin currency');
$restaurantAdmin->updateRestaurantCurrency(900001, 'USD', $manager);
$after = $restaurantAdmin->findRestaurant(900001);
$check($after['currency_code'] === 'USD' && (!isset($after['currency']) || $after['currency'] === 'USD'), 'manager update synchronizes currency columns');
$check(restaurant_currency(900002) === 'USD', 'other restaurant currency unchanged');
$payload['currency_code'] = 'EUR';
$reject(fn () => $restaurantAdmin->updateRestaurant(900001, $payload, $owner), 'unsupported admin currency rejected');

// Render the actual personnel screen and verify the assignment controls are discoverable.
$roles = $container->get('roleAdmin')->listAssignableRoles(900001);
$user_page = $container->get('roleAdmin')->listUsersForRestaurantPage(900001);
foreach ($user_page['items'] as &$agent) {
    $agent['assigned_functions'] = $functions->rolesForUser((int) $agent['id'], 900001);
}
unset($agent);
$users = $user_page['items'];
$preset_roles = $roles;
$access_editor = false;
$_SESSION['_functions_csrf'] = 'fixture-token';
ob_start();
require BASE_PATH . '/app/Views/owner/access.php';
$html = ob_get_clean();
$check(str_contains($html, '/owner/users/900011/functions') && str_contains($html, 'Enregistrer les fonctions'), 'per-person functions form rendered');
$check(str_contains($html, 'name="additional_role_ids[]"'), 'multiple function checkboxes rendered');
echo "OK RestaurantAccessSettings: {$count} integration checks (temporary tables only)\n";
