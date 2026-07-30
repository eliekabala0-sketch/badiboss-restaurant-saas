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

use App\Core\Container;
use App\Core\Database;
use App\Services\ExternalAuditEngine;
use App\Services\ExternalAuditExportService;
use App\Services\ExternalAuditService;
use App\Services\RestaurantAdminService;
use App\Services\UiNotificationService;

$db = new Database(require BASE_PATH . '/config/database.php');
$pdo = $db->pdo();
$container = Container::getInstance();
$restaurantAdmin = new RestaurantAdminService($db);
$container->set('db', $db);
$container->set('restaurantAdmin', $restaurantAdmin);
$container->set('uiNotifications', new UiNotificationService($db));

$restaurantStatement = $pdo->query(
    'SELECT * FROM restaurants WHERE LOWER(restaurant_code) IN ("test-audit-externe","test-ventes-minuit") ORDER BY restaurant_code="test-audit-externe" DESC LIMIT 1'
);
$restaurant = $restaurantStatement->fetch(PDO::FETCH_ASSOC);
if (!$restaurant || !$restaurantAdmin->isTestRestaurant($restaurant)) {
    throw new RuntimeException('Aucun restaurant sandbox autorise.');
}
$restaurantId = (int) $restaurant['id'];
$usersStatement = $pdo->prepare(
    'SELECT u.*,r.code AS role_code,"tenant" AS scope FROM users u INNER JOIN roles r ON r.id=u.role_id
     WHERE u.restaurant_id=:restaurant_id AND u.status="active" ORDER BY FIELD(r.code,"manager","owner","stock_manager","kitchen","cashier_server"),u.id'
);
$usersStatement->execute(['restaurant_id' => $restaurantId]);
$users = $usersStatement->fetchAll(PDO::FETCH_ASSOC);
if (count($users) < 4) {
    throw new RuntimeException('Le sandbox doit avoir au moins quatre comptes actifs.');
}
$manager = $users[0];
$engine = new ExternalAuditEngine();
$service = new ExternalAuditService($db, $engine);
$export = new ExternalAuditExportService();
$date = (new DateTimeImmutable('today'))->format('Y-m-d');
$checks = [];
$reportIds = [];
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks[$label] = $ok ? 'OK' : 'ECHEC';
    if (!$ok) {
        throw new RuntimeException('E2E: ' . $label);
    }
};

try {
    $service->createCategory($restaurantId, 'Boissons E2E', 'stock', $manager);
    $categories = $service->categories($restaurantId);
    $category = array_values(array_filter($categories, static fn (array $row): bool => $row['name'] === 'Boissons E2E'))[0] ?? null;
    $assert(is_array($category), 'categorie');
    $service->createProduct($restaurantId, [
        'category_id' => $category['id'], 'name' => 'Produit E2E ' . date('His'), 'unit' => 'bouteille',
        'sale_price' => 4000, 'usual_purchase_price' => 2500, 'units_per_case' => 12, 'units_per_half_case' => 6,
    ], $manager);
    $product = array_values(array_filter($service->products($restaurantId), static fn (array $row): bool => str_starts_with($row['name'], 'Produit E2E')))[0] ?? null;
    $assert(is_array($product), 'produit');

    $types = ['boissons','cuisine','serveur','serveur'];
    foreach ($types as $index => $type) {
        $actor = $users[$index];
        $declared = $type === 'serveur' ? ($index === 2 ? 80000 : 12000) : ($type === 'boissons' ? 76000 : 0);
        $reportId = $service->saveDraft($restaurantId, [
            'report_type' => $type, 'activity_date' => $date, 'operational_author_id' => $actor['id'],
            'declared_sales' => $declared, 'presented_cash' => max(0, $declared - 1000),
            'observations' => 'E2E sandbox ' . $type, 'is_test' => 1,
            'items' => [
                $product['id'] => [
                    'previous_stock' => $type === 'boissons' ? 10 : 0,
                    'purchased_quantity' => $type === 'boissons' ? 20 : 0,
                    'purchase_total' => $type === 'boissons' ? 50000 : 0,
                    'remaining_stock' => $type === 'boissons' ? 8 : 0,
                    'sold_quantity_declared' => $type === 'serveur' ? ($index === 2 ? 20 : 3) : 0,
                    'credit_amount' => $type === 'serveur' ? 1000 : 0,
                    'expense_amount' => $type === 'cuisine' ? 2000 : 0,
                    'incident_note' => $index === 0 ? 'Incident E2E explique' : '',
                ],
            ],
        ], $actor);
        $reportIds[] = $reportId;
        $service->submit($restaurantId, $reportId, 'e2e-' . bin2hex(random_bytes(10)), $actor);
    }
    $assert(count($reportIds) === 4, 'brouillons et soumissions');
    $firstResult = $service->result($restaurantId, $reportIds[0]);
    $assert((float) $firstResult['calculated_sales'] === 88000.0, 'formule vente');
    $assert((float) $firstResult['missing_amount'] === 12000.0, 'formule manquant');

    $internal = $service->internalConfrontation($restaurantId, $date, $date);
    $assert(count($internal['rows']) >= 1, 'confrontation interne');
    $application = $service->applicationConfrontation($restaurantId, $date, $date);
    $assert($application['read_only'] === true, 'confrontation application lecture seule');

    $lossId = $service->createLoss($restaurantId, [
        'report_id' => $reportIds[0], 'product_id' => $product['id'], 'category_id' => $category['id'],
        'activity_date' => $date, 'quantity' => 1, 'value_amount' => 4000,
        'involved_people' => $users[0]['full_name'] . ',' . $users[2]['full_name'],
        'cause' => 'Casse expliquee E2E', 'status' => 'EXPLIQUE',
    ], $manager);
    $service->decideLoss($restaurantId, $lossId, 'RESOLU', 'Decision E2E motivee', $manager);
    $losses = $service->lossAnalysis($restaurantId, $date, $date);
    $assert((float) $losses['summary']['total'] === 4000.0, 'analyse pertes');
    $assert(($losses['rows'][0]['status'] ?? null) === 'RESOLU' && ($losses['rows'][0]['manager_decision'] ?? null) === 'Decision E2E motivee', 'decision perte historisee');

    $service->attachReportEvidence($restaurantId, $reportIds[0], 'preuve-e2e.png', '/uploads/test/preuve-e2e.png', 'image/png', 128, $manager);
    $assert(count($service->attachments($restaurantId, $reportIds[0])) === 1, 'piece jointe rapport');

    $service->requestCorrection($restaurantId, $reportIds[0], 'Correction E2E rejetee', $users[0]);
    $requests = $service->correctionRequests($restaurantId, $reportIds[0]);
    $service->decideCorrection($restaurantId, (int) $requests[0]['id'], false, 'Rejet E2E', $manager);
    $assert($service->correctionRequests($restaurantId, $reportIds[0])[0]['status'] === 'REJECTED', 'correction rejetee');

    $service->requestCorrection($restaurantId, $reportIds[0], 'Correction E2E acceptee', $users[0]);
    $requests = $service->correctionRequests($restaurantId, $reportIds[0]);
    $service->decideCorrection($restaurantId, (int) $requests[0]['id'], true, 'Acceptation E2E', $manager);
    $assert($service->findReport($restaurantId, $reportIds[0])['status'] === 'BROUILLON', 'correction acceptee');
    $assert(count($service->revisions($restaurantId, $reportIds[0])) >= 1, 'version archivee correction');

    $service->reset($restaurantId, $reportIds[1], 'Reinitialisation E2E', $manager);
    $assert($service->findReport($restaurantId, $reportIds[1])['status'] === 'BROUILLON', 'reinitialisation brouillon');
    $assert($service->items($restaurantId, $reportIds[1]) === [], 'reinitialisation vide');
    $assert(count($service->revisions($restaurantId, $reportIds[1])) >= 1, 'reinitialisation archive');

    $period = $service->periodData($restaurantId, $date, $date);
    $excel = $export->excel($period, $restaurant);
    $pdf = $export->pdf($period, $restaurant);
    $assert(substr_count($excel, '<Worksheet ') === 21, 'excel 21 feuilles');
    $assert(str_contains($excel, 'Correction E2E') && str_contains($excel, 'REPORT_RESET'), 'excel corrections versions journal');
    $assert(str_starts_with($pdf, '%PDF-1.4'), 'pdf valide');
    $assert(substr_count($pdf, '/Type /Page') >= 2, 'pdf multipage detaille');
    $assert(str_contains($excel, (string) $period['totals']['missing_amount']), 'totaux export');
    $notificationEvents = $pdo->prepare(
        'SELECT COUNT(DISTINCT event_code) FROM ui_notifications
         WHERE restaurant_id=:restaurant_id AND event_key LIKE "ea:%"'
    );
    $notificationEvents->execute(['restaurant_id' => $restaurantId]);
    $assert((int) $notificationEvents->fetchColumn() >= 3, 'notifications evenements sans repetition');
} finally {
    foreach ($reportIds as $reportId) {
        try {
            $service->deleteTestReport($restaurantId, $reportId, 'Nettoyage E2E automatique', $manager, $restaurant);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[cleanup report ' . $reportId . '] ' . $exception->getMessage() . PHP_EOL);
        }
    }
    $pdo->prepare('DELETE FROM ui_notifications WHERE restaurant_id=:restaurant_id AND event_key LIKE "ea:%"')
        ->execute(['restaurant_id' => $restaurantId]);
    $pdo->prepare('DELETE FROM external_audit_products WHERE restaurant_id=:restaurant_id AND name LIKE "Produit E2E %"')
        ->execute(['restaurant_id' => $restaurantId]);
    $pdo->prepare('DELETE FROM external_audit_categories WHERE restaurant_id=:restaurant_id AND name="Boissons E2E"')
        ->execute(['restaurant_id' => $restaurantId]);
}

$assert((int) $pdo->query('SELECT COUNT(*) FROM external_audit_reports WHERE restaurant_id=' . $restaurantId . ' AND is_test=1')->fetchColumn() === 0, 'nettoyage rapports tests');
echo json_encode([
    'status' => 'OK',
    'restaurant' => $restaurant['restaurant_code'],
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
