<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Réinitialisation stock ciblée super-admin (un restaurant, pas de DROP, pas d'autres modules).
 */
final class StockResetService
{
    private const KITCHEN_MOVEMENT_TYPES = ['SORTIE_CUISINE', 'CONSOMMATION_CUISINE'];

    public function __construct(private readonly Database $database)
    {
    }

    public function preview(array $payload): array
    {
        $filters = $this->normalizePayload($payload);
        $pdo = $this->database->pdo();
        $rid = $filters['restaurant_id'];

        $options = $filters['options'];

        $magSplit = ['deletable' => [], 'blocked_count' => 0];
        if ($options['mouvements_magasin']) {
            $magSplit = $this->magasinMovementsSplit($filters);
        }
        $cuisineMoveIds = $options['mouvements_cuisine']
            ? $this->cuisineMovementIds($filters)
            : [];

        $ksrIds = $options['demandes_cuisine_stock']
            ? $this->idsKitchenStockRequestsInPeriod($filters)
            : [];
        $ksriIds = ($ksrIds !== [] && $this->tableExists('kitchen_stock_request_items'))
            ? $this->idsByForeignKey('kitchen_stock_request_items', 'request_id', $ksrIds)
            : [];

        $lossIds = $options['pertes']
            ? $this->idsInPeriod('losses', $filters)
            : [];

        $correctionIds = $options['corrections_stock']
            ? $this->idsStockCorrectionsInPeriod($filters)
            : [];

        $kiDeleteIds = $options['inventaire_cuisine_lignes']
            ? $this->idsKitchenInventoryInPeriod($filters)
            : [];

        $cMag = $pdo->prepare('SELECT COUNT(*) FROM stock_items WHERE restaurant_id = :rid');
        $cMag->execute(['rid' => $rid]);
        $stockArticlesCount = (int) $cMag->fetchColumn();

        $cKi = $pdo->prepare('SELECT COUNT(*) FROM kitchen_inventory WHERE restaurant_id = :rid');
        if ($this->tableExists('kitchen_inventory')) {
            $cKi->execute(['rid' => $rid]);
            $cuisineInventoryRows = (int) $cKi->fetchColumn();
        } else {
            $cuisineInventoryRows = 0;
        }

        return [
            'filters' => $filters,
            'restaurant' => $this->findRestaurant($rid),
            'period' => [
                'start_at' => $filters['start_at'],
                'end_at' => $filters['end_at'],
                'label' => $filters['period_label'],
            ],
            'options_labels' => $this->optionLabels(),
            'counts' => [
                'articles_magasin_pour_zero' => $options['qty_magasin_zero'] ? $stockArticlesCount : 0,
                'lignes_cuisine_pour_zero' => $options['qty_cuisine_zero'] ? $cuisineInventoryRows : 0,
                'mouvements_magasin' => count($magSplit['deletable']),
                'mouvements_magasin_exclus_production' => (int) ($magSplit['blocked_count'] ?? 0),
                'mouvements_cuisine' => count($cuisineMoveIds),
                'pertes' => count($lossIds),
                'corrections_stock' => count($correctionIds),
                'demandes_cuisine_stock' => count($ksrIds),
                'demandes_cuisine_stock_lignes' => count($ksriIds),
                'inventaire_cuisine_lignes' => count($kiDeleteIds),
            ],
            'ids' => [
                'stock_movements_magasin' => $magSplit['deletable'],
                'stock_movements_cuisine' => $cuisineMoveIds,
                'kitchen_stock_requests' => $ksrIds,
                'kitchen_stock_request_items' => $ksriIds,
                'losses' => $lossIds,
                'correction_requests' => $correctionIds,
                'kitchen_inventory_period' => $kiDeleteIds,
            ],
        ];
    }

    public function execute(array $payload, array $actor): array
    {
        $preview = $this->preview($payload);
        $filters = $preview['filters'];
        $rid = $filters['restaurant_id'];

        $confirmation = trim((string) ($payload['confirmation_text'] ?? ''));
        $expected = 'REINITIALISER STOCK ' . $rid;
        if (!hash_equals($expected, $confirmation)) {
            throw new \RuntimeException('Confirmation invalide. Saisissez exactement : ' . $expected);
        }

        $reason = trim((string) ($payload['reset_reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('Motif obligatoire.');
        }

        $opts = $filters['options'];
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        $done = [
            'kitchen_stock_request_items' => 0,
            'kitchen_stock_requests' => 0,
            'correction_requests' => 0,
            'losses' => 0,
            'stock_movements_cuisine' => 0,
            'stock_movements_magasin' => 0,
            'kitchen_inventory_period' => 0,
            'stock_items_qty_zeroed' => 0,
            'kitchen_inventory_qty_zeroed' => 0,
        ];

        try {
            if ($preview['ids']['kitchen_stock_request_items'] !== []) {
                $done['kitchen_stock_request_items'] = $this->deleteByIds('kitchen_stock_request_items', $preview['ids']['kitchen_stock_request_items']);
            }
            if ($preview['ids']['kitchen_stock_requests'] !== []) {
                $done['kitchen_stock_requests'] = $this->deleteByIds('kitchen_stock_requests', $preview['ids']['kitchen_stock_requests']);
            }

            if ($preview['ids']['correction_requests'] !== []) {
                $done['correction_requests'] = $this->deleteByIds('correction_requests', $preview['ids']['correction_requests']);
            }
            if ($preview['ids']['losses'] !== []) {
                $done['losses'] = $this->deleteByIds('losses', $preview['ids']['losses']);
            }

            if ($preview['ids']['stock_movements_cuisine'] !== []) {
                $done['stock_movements_cuisine'] = $this->deleteByIds('stock_movements', $preview['ids']['stock_movements_cuisine']);
            }
            if ($preview['ids']['stock_movements_magasin'] !== []) {
                $done['stock_movements_magasin'] = $this->deleteByIds('stock_movements', $preview['ids']['stock_movements_magasin']);
            }

            if ($preview['ids']['kitchen_inventory_period'] !== [] && $this->tableExists('kitchen_inventory')) {
                $done['kitchen_inventory_period'] = $this->deleteByIds('kitchen_inventory', $preview['ids']['kitchen_inventory_period']);
            }

            if ($opts['qty_magasin_zero']) {
                $u = $pdo->prepare(
                    'UPDATE stock_items SET quantity_in_stock = 0, updated_at = NOW() WHERE restaurant_id = :rid'
                );
                $u->execute(['rid' => $rid]);
                $done['stock_items_qty_zeroed'] = $u->rowCount();
            }

            if ($opts['qty_cuisine_zero'] && $this->tableExists('kitchen_inventory')) {
                $u = $pdo->prepare(
                    'UPDATE kitchen_inventory SET quantity_available = 0, updated_at = NOW() WHERE restaurant_id = :rid'
                );
                $u->execute(['rid' => $rid]);
                $done['kitchen_inventory_qty_zeroed'] = $u->rowCount();
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $auditPayload = [
            'restaurant_id' => $rid,
            'period' => $preview['period'],
            'options' => $opts,
            'counts_preview' => $preview['counts'],
            'done' => $done,
            'reason' => $reason,
            'confirmation' => $expected,
        ];

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $rid,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? 'super_admin',
            'actor_role_code' => $actor['role_code'] ?? 'super_admin',
            'module_name' => 'platform',
            'action_name' => 'super_admin_stock_reset',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $rid,
            'new_values' => $auditPayload,
            'justification' => $reason,
        ]);

        return [
            'preview' => $preview,
            'done' => $done,
            'reason' => $reason,
        ];
    }

    public function optionLabels(): array
    {
        return [
            'qty_magasin_zero' => 'Quantités stock magasin à zéro (articles conservés)',
            'mouvements_magasin' => 'Supprimer les mouvements stock magasin (période)',
            'qty_cuisine_zero' => 'Quantités stock cuisine à zéro (lignes conservées)',
            'mouvements_cuisine' => 'Supprimer les mouvements liés à la cuisine (période)',
            'inventaire_cuisine_lignes' => 'Supprimer les lignes d’inventaire cuisine (période)',
            'pertes' => 'Supprimer les pertes matière (période)',
            'corrections_stock' => 'Supprimer les demandes de correction stock (période)',
            'demandes_cuisine_stock' => 'Supprimer les demandes cuisine → stock (période)',
        ];
    }

    private function normalizePayload(array $payload): array
    {
        $restaurantId = (int) ($payload['restaurant_id'] ?? 0);
        if ($restaurantId <= 0) {
            throw new \RuntimeException('Choisissez un restaurant.');
        }
        $this->findRestaurant($restaurantId);

        $periodPreset = (string) ($payload['stock_period_preset'] ?? 'today');
        $timezone = new DateTimeZone((string) ($this->findRestaurant($restaurantId)['timezone'] ?? 'UTC'));
        [$startAt, $endAt, $label] = $this->resolveStockPeriod($payload, $periodPreset, $timezone);

        $raw = $payload['stock_options'] ?? [];
        $keys = array_keys($this->optionLabels());
        $options = [];
        foreach ($keys as $k) {
            $options[$k] = is_array($raw) && in_array($k, $raw, true);
        }

        if (!array_reduce($options, static fn (bool $a, bool $b): bool => $a || $b, false)) {
            throw new \RuntimeException('Cochez au moins une action.');
        }

        return [
            'restaurant_id' => $restaurantId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'period_label' => $label,
            'stock_period_preset' => $periodPreset,
            'stock_week_value' => (string) ($payload['stock_week_value'] ?? ''),
            'stock_month_value' => (string) ($payload['stock_month_value'] ?? ''),
            'stock_date_from' => (string) ($payload['stock_date_from'] ?? ''),
            'stock_date_to' => (string) ($payload['stock_date_to'] ?? ''),
            'stock_options' => array_values(array_filter($keys, static fn (string $k): bool => $options[$k])),
            'options' => $options,
        ];
    }

    /**
     * @return array{0:string,1:string,2:string} start, end, label
     */
    private function resolveStockPeriod(array $payload, string $preset, DateTimeZone $timezone): array
    {
        return match ($preset) {
            'yesterday' => $this->yesterdayRange($timezone),
            'week' => $this->weekRange((string) ($payload['stock_week_value'] ?? ''), $timezone),
            'month' => $this->monthRange((string) ($payload['stock_month_value'] ?? ''), $timezone),
            'custom' => $this->customRange((string) ($payload['stock_date_from'] ?? ''), (string) ($payload['stock_date_to'] ?? ''), $timezone),
            default => $this->todayRange($timezone),
        };
    }

    private function todayRange(DateTimeZone $timezone): array
    {
        $start = (new DateTimeImmutable('today', $timezone))->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Aujourd’hui'];
    }

    private function yesterdayRange(DateTimeZone $timezone): array
    {
        $start = (new DateTimeImmutable('today', $timezone))->modify('-1 day')->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Hier'];
    }

    private function weekRange(string $value, DateTimeZone $timezone): array
    {
        $date = $value !== '' ? new DateTimeImmutable($value, $timezone) : new DateTimeImmutable('monday this week', $timezone);
        $start = $date->modify('monday this week')->setTime(0, 0, 0);
        $end = $start->modify('+7 days');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), 'Semaine du ' . $start->format('d/m/Y')];
    }

    private function monthRange(string $value, DateTimeZone $timezone): array
    {
        $date = $value !== '' ? new DateTimeImmutable($value . '-01', $timezone) : new DateTimeImmutable('first day of this month', $timezone);
        $start = $date->modify('first day of this month')->setTime(0, 0, 0);
        $end = $start->modify('first day of next month');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $start->format('m/Y')];
    }

    private function customRange(string $from, string $to, DateTimeZone $timezone): array
    {
        if ($from === '' || $to === '') {
            throw new \RuntimeException('Indiquez les deux dates de la plage.');
        }
        $start = (new DateTimeImmutable($from, $timezone))->setTime(0, 0, 0);
        $end = (new DateTimeImmutable($to, $timezone))->setTime(0, 0, 0)->modify('+1 day');
        if ($end <= $start) {
            throw new \RuntimeException('Plage de dates invalide.');
        }
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $start->format('d/m/Y') . ' → ' . $to];
    }

    private function idsInPeriod(string $table, array $filters): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM ' . $table . '
             WHERE restaurant_id = :rid AND created_at >= :s AND created_at < :e'
        );
        $statement->execute([
            'rid' => $filters['restaurant_id'],
            's' => $filters['start_at'],
            'e' => $filters['end_at'],
        ]);
        return array_map(static fn ($v): int => (int) $v, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idsKitchenStockRequestsInPeriod(array $filters): array
    {
        return $this->idsInPeriod('kitchen_stock_requests', $filters);
    }

    private function idsStockCorrectionsInPeriod(array $filters): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM correction_requests
             WHERE restaurant_id = :rid
               AND module_name = "stock"
               AND created_at >= :s AND created_at < :e'
        );
        $statement->execute([
            'rid' => $filters['restaurant_id'],
            's' => $filters['start_at'],
            'e' => $filters['end_at'],
        ]);
        return array_map(static fn ($v): int => (int) $v, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idsKitchenInventoryInPeriod(array $filters): array
    {
        if (!$this->tableExists('kitchen_inventory')) {
            return [];
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT ki.id FROM kitchen_inventory ki
             WHERE ki.restaurant_id = :rid
               AND GREATEST(ki.created_at, ki.updated_at) >= :s
               AND GREATEST(ki.created_at, ki.updated_at) < :e'
        );
        $statement->execute([
            'rid' => $filters['restaurant_id'],
            's' => $filters['start_at'],
            'e' => $filters['end_at'],
        ]);
        return array_map(static fn ($v): int => (int) $v, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function magasinMovementsSplit(array $filters): array
    {
        $types = implode('","', self::KITCHEN_MOVEMENT_TYPES);
        $params = [
            'rid' => $filters['restaurant_id'],
            's' => $filters['start_at'],
            'e' => $filters['end_at'],
        ];
        $typeExclude = 'sm.movement_type NOT IN ("' . $types . '")';
        $base = 'FROM stock_movements sm WHERE sm.restaurant_id = :rid
               AND sm.created_at >= :s AND sm.created_at < :e
               AND ' . $typeExclude;

        $allStmt = $this->database->pdo()->prepare('SELECT sm.id ' . $base);
        $allStmt->execute($params);
        $all = array_map(static fn ($v): int => (int) $v, $allStmt->fetchAll(PDO::FETCH_COLUMN));

        $safeStmt = $this->database->pdo()->prepare(
            'SELECT sm.id ' . $base . '
               AND NOT EXISTS (
                 SELECT 1 FROM kitchen_production kp
                 WHERE kp.stock_movement_id = sm.id AND kp.restaurant_id = sm.restaurant_id
               )'
        );
        $safeStmt->execute($params);
        $safe = array_map(static fn ($v): int => (int) $v, $safeStmt->fetchAll(PDO::FETCH_COLUMN));

        return [
            'deletable' => $safe,
            'blocked_count' => max(0, count($all) - count($safe)),
        ];
    }

    private function cuisineMovementIds(array $filters): array
    {
        $types = implode('","', self::KITCHEN_MOVEMENT_TYPES);
        $params = [
            'rid' => $filters['restaurant_id'],
            's' => $filters['start_at'],
            'e' => $filters['end_at'],
        ];
        $base = 'FROM stock_movements sm WHERE sm.restaurant_id = :rid
               AND sm.created_at >= :s AND sm.created_at < :e
               AND sm.movement_type IN ("' . $types . '")
               AND NOT EXISTS (
                 SELECT 1 FROM kitchen_production kp
                 WHERE kp.stock_movement_id = sm.id AND kp.restaurant_id = sm.restaurant_id
               )';

        $stmt = $this->database->pdo()->prepare('SELECT sm.id ' . $base);
        $stmt->execute($params);
        return array_map(static fn ($v): int => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idsByForeignKey(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $list = implode(',', array_map('intval', $ids));
        return array_map(
            static fn ($v): int => (int) $v,
            $this->database->pdo()->query('SELECT id FROM ' . $table . ' WHERE ' . $column . ' IN (' . $list . ')')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function deleteByIds(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $list = implode(',', array_map('intval', $ids));
        return $this->database->pdo()->exec('DELETE FROM ' . $table . ' WHERE id IN (' . $list . ')');
    }

    private function findRestaurant(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name, slug, timezone FROM restaurants WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Restaurant introuvable.');
        }
        return $row;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $statement->execute(['t' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }
}
