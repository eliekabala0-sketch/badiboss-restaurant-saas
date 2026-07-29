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

$sql = file_get_contents(BASE_PATH . '/database/migrations/2026_07_29_external_audit_module.sql');
if ($sql === false) {
    throw new RuntimeException('Migration Audit externe introuvable.');
}
$lines = preg_split('/\R/', $sql) ?: [];
$kept = [];
foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }
    $kept[] = $line;
}
$statements = preg_split('/;\s*(?:\R|$)/', implode("\n", $kept)) ?: [];
$db = new Database(require BASE_PATH . '/config/database.php');
$pdo = $db->pdo();
$executed = [];
foreach (array_filter(array_map('trim', $statements)) as $statement) {
    if (
        preg_match('/^CREATE TABLE IF NOT EXISTS external_audit_[a-z0-9_]+\s*\(/i', $statement) !== 1
        && preg_match('/^INSERT IGNORE INTO permissions\s*\(/i', $statement) !== 1
    ) {
        throw new RuntimeException('Instruction hors perimetre refusee: ' . substr($statement, 0, 80));
    }
    $pdo->exec($statement);
    $executed[] = preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/i', $statement, $match) === 1
        ? $match[1]
        : 'permissions';
}
echo json_encode(['status' => 'OK', 'executed' => $executed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
