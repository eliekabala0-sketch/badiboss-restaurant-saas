<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Support/helpers.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use App\Core\Database;

$db = new Database(require BASE_PATH . '/config/database.php');
$pdo = $db->pdo();
$exists = static function (string $table) use ($pdo): bool {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() === 1;
};
$tables = [
    'restaurants','users','sales','sale_items','stock_movements','cash_movements','cash_transfers',
    'audit_logs','external_audit_reports','external_audit_report_items','external_audit_results',
    'external_audit_losses','external_audit_confrontations','external_audit_exports',
];
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = $exists($table) ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : null;
}
$sandbox = $pdo->query(
    'SELECT id,name,restaurant_code,status FROM restaurants
     WHERE LOWER(restaurant_code) IN ("test-audit-externe","test-ventes-minuit")
        OR LOWER(name) LIKE "%test-audit-externe%" ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$sandboxUsers = [];
foreach ($sandbox as $sandboxRestaurant) {
    $statement = $pdo->prepare(
        'SELECT u.id,u.email,u.status,r.code AS role_code FROM users u
         INNER JOIN roles r ON r.id=u.role_id WHERE u.restaurant_id=:restaurant_id ORDER BY u.id'
    );
    $statement->execute(['restaurant_id' => (int) $sandboxRestaurant['id']]);
    $sandboxUsers[(string) $sandboxRestaurant['restaurant_code']] = $statement->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'database_source' => $db->config()['source'] ?? 'unknown',
    'counts' => $counts,
    'sandbox_restaurants' => $sandbox,
    'sandbox_users' => $sandboxUsers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
