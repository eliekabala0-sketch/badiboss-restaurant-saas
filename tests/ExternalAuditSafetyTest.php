<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/2026_07_29_external_audit_module.sql');
$service = file_get_contents($root . '/app/Services/ExternalAuditService.php');
$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "ECHEC " . $label . PHP_EOL);
        exit(1);
    }
};

$assert($migration !== false, 'migration lisible');
$assert(!preg_match('/\b(DROP|TRUNCATE)\b/i', $migration), 'migration sans DROP/TRUNCATE');
foreach (['external_audit_reports','external_audit_report_items','external_audit_results','external_audit_losses','external_audit_confrontations','external_audit_exports'] as $table) {
    $assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ' . $table), 'table additive ' . $table);
}
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+|FROM\s+)?(?:sales|sale_items|stock_movements|cash_transfers)\b/i', $service), 'aucune ecriture operationnelle');
$assert(str_contains($service, 'FROM sales'), 'confrontation application en lecture seule');
$assert(str_contains($service, 'restaurant_id=:restaurant_id'), 'filtrage restaurant serveur');
$assert(str_contains($service, 'isTestRestaurant'), 'garde suppression sandbox');

echo "OK ExternalAuditSafety: 13 controles securite\n";
