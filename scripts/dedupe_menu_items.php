<?php

declare(strict_types=1);

use App\Core\Database;

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

$apply = in_array('--apply', $argv, true);

$database = new Database(require BASE_PATH . '/config/database.php');
$pdo = $database->pdo();

$duplicateGroups = loadDuplicateGroups($pdo);

if ($duplicateGroups === []) {
    fwrite(STDOUT, json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        'summary' => [
            'duplicate_groups_found' => 0,
            'duplicate_rows_found' => 0,
            'groups_processed' => 0,
            'rows_deactivated' => 0,
            'rows_activated' => 0,
        ],
        'groups' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);

    exit(0);
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'summary' => [
        'duplicate_groups_found' => count($duplicateGroups),
        'duplicate_rows_found' => 0,
        'groups_processed' => 0,
        'rows_deactivated' => 0,
        'rows_activated' => 0,
    ],
    'groups' => [],
];

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

try {
    if ($apply) {
        $pdo->beginTransaction();
    }

    $activateStatement = $pdo->prepare(
        'UPDATE menu_items
         SET status = "active",
             is_available = 1,
             updated_at = :updated_at
         WHERE id = :id'
    );

    $deactivateStatement = $pdo->prepare(
        'UPDATE menu_items
         SET status = "inactive",
             is_available = 0,
             updated_at = :updated_at
         WHERE id = :id'
    );

    foreach ($duplicateGroups as $group) {
        $items = loadGroupItems($pdo, (int) $group['restaurant_id'], (int) $group['category_id'], (string) $group['normalized_name']);
        if (count($items) < 2) {
            continue;
        }

        $keeper = chooseKeeper($items);
        $groupReport = [
            'restaurant_id' => (int) $group['restaurant_id'],
            'restaurant_name' => $group['restaurant_name'],
            'category_id' => (int) $group['category_id'],
            'category_name' => $group['category_name'],
            'name_key' => $group['normalized_name'],
            'duplicate_count' => count($items),
            'keeper_id' => (int) $keeper['id'],
            'keeper_name' => $keeper['name'],
            'actions' => [],
        ];

        $report['summary']['duplicate_rows_found'] += count($items);
        $report['summary']['groups_processed']++;

        foreach ($items as $item) {
            $before = [
                'status' => $item['status'],
                'is_available' => (int) $item['is_available'],
            ];

            $after = $before;
            $action = 'unchanged';

            if ((int) $item['id'] === (int) $keeper['id']) {
                $after = [
                    'status' => 'active',
                    'is_available' => 1,
                ];

                if ($before !== $after) {
                    $action = 'activated_keeper';
                    $report['summary']['rows_activated']++;

                    if ($apply) {
                        $activateStatement->execute([
                            'id' => (int) $item['id'],
                            'updated_at' => $now,
                        ]);
                    }
                } else {
                    $action = 'kept_active_keeper';
                }
            } else {
                $after = [
                    'status' => 'inactive',
                    'is_available' => 0,
                ];

                if ($before !== $after) {
                    $action = 'deactivated_duplicate';
                    $report['summary']['rows_deactivated']++;

                    if ($apply) {
                        $deactivateStatement->execute([
                            'id' => (int) $item['id'],
                            'updated_at' => $now,
                        ]);
                    }
                } else {
                    $action = 'already_inactive_duplicate';
                }
            }

            $groupReport['actions'][] = [
                'menu_item_id' => (int) $item['id'],
                'name' => $item['name'],
                'slug' => $item['slug'],
                'price' => (float) $item['price'],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at'],
                'sales_references' => (int) $item['sales_references'],
                'server_request_references' => (int) $item['server_request_references'],
                'total_references' => (int) $item['total_references'],
                'before' => $before,
                'after' => $after,
                'action' => $action,
            ];
        }

        $report['groups'][] = $groupReport;
    }

    if ($apply) {
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, json_encode([
        'mode' => $apply ? 'apply' : 'dry-run',
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);

    exit(1);
}

$reportDir = BASE_PATH . '/storage/reports';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$reportPath = sprintf(
    '%s/menu-item-dedup-%s.json',
    $reportDir,
    (new DateTimeImmutable('now'))->format('Ymd-His')
);

$report['report_path'] = $reportPath;
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);

/**
 * @return array<int, array<string, mixed>>
 */
function loadDuplicateGroups(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT
            mi.restaurant_id,
            r.name AS restaurant_name,
            mi.category_id,
            mc.name AS category_name,
            LOWER(TRIM(mi.name)) AS normalized_name,
            COUNT(*) AS duplicate_count
         FROM menu_items mi
         INNER JOIN restaurants r ON r.id = mi.restaurant_id
         INNER JOIN menu_categories mc ON mc.id = mi.category_id
         GROUP BY mi.restaurant_id, r.name, mi.category_id, mc.name, LOWER(TRIM(mi.name))
         HAVING COUNT(*) > 1
         ORDER BY mi.restaurant_id ASC, mc.name ASC, normalized_name ASC'
    );

    return $statement->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function loadGroupItems(PDO $pdo, int $restaurantId, int $categoryId, string $normalizedName): array
{
    $statement = $pdo->prepare(
        'SELECT
            mi.*,
            COALESCE(sales_ref.sales_references, 0) AS sales_references,
            COALESCE(request_ref.server_request_references, 0) AS server_request_references,
            COALESCE(sales_ref.sales_references, 0) + COALESCE(request_ref.server_request_references, 0) AS total_references
         FROM menu_items mi
         LEFT JOIN (
             SELECT menu_item_id, COUNT(*) AS sales_references
             FROM sale_items
             GROUP BY menu_item_id
         ) sales_ref ON sales_ref.menu_item_id = mi.id
         LEFT JOIN (
             SELECT menu_item_id, COUNT(*) AS server_request_references
             FROM server_request_items
             GROUP BY menu_item_id
         ) request_ref ON request_ref.menu_item_id = mi.id
         WHERE mi.restaurant_id = :restaurant_id
           AND mi.category_id = :category_id
           AND LOWER(TRIM(mi.name)) = :normalized_name
         ORDER BY mi.id ASC'
    );

    $statement->execute([
        'restaurant_id' => $restaurantId,
        'category_id' => $categoryId,
        'normalized_name' => $normalizedName,
    ]);

    return $statement->fetchAll();
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<string, mixed>
 */
function chooseKeeper(array $items): array
{
    usort($items, static function (array $left, array $right): int {
        $comparison = (int) $right['total_references'] <=> (int) $left['total_references'];
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = activeRank($right) <=> activeRank($left);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp((string) $left['created_at'], (string) $right['created_at']);
        if ($comparison !== 0) {
            return $comparison;
        }

        return (int) $left['id'] <=> (int) $right['id'];
    });

    return $items[0];
}

function activeRank(array $item): int
{
    return ((string) $item['status'] === 'active' && (int) $item['is_available'] === 1) ? 1 : 0;
}
