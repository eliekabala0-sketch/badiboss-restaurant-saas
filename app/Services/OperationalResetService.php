<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class OperationalResetService
{
    private const DATASET_LABELS = [
        'commandes_serveur' => 'Commandes serveur',
        'demandes_cuisine' => 'Demandes cuisine',
        'demandes_stock' => 'Demandes stock',
        'ventes' => 'Ventes',
        'pertes' => 'Pertes',
        'incidents' => 'Incidents',
        'retours' => 'Retours',
        'rapports_operationnels' => 'Rapports operationnels',
        'audit_operationnel' => 'Audit operationnel lie',
        'images_test' => 'Images de test',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function preview(array $payload): array
    {
        $filters = $this->normalizeFilters($payload);
        $pdo = $this->database->pdo();

        $serverRequestIds = in_array('commandes_serveur', $filters['data_types'], true)
            ? $this->idsForScopedTable('server_requests', $filters, ['server_id', 'requested_by', 'technical_confirmed_by', 'ready_by', 'received_by', 'decided_by'])
            : [];
        $kitchenProductionIds = in_array('demandes_cuisine', $filters['data_types'], true)
            ? $this->idsForScopedTable('kitchen_production', $filters, ['created_by'])
            : [];
        $kitchenStockRequestIds = in_array('demandes_stock', $filters['data_types'], true)
            ? $this->idsForScopedTable('kitchen_stock_requests', $filters, ['requested_by', 'responded_by', 'received_by'])
            : [];
        $saleIds = in_array('ventes', $filters['data_types'], true)
            ? $this->idsForScopedTable('sales', $filters, ['server_id'])
            : [];
        $lossIds = in_array('pertes', $filters['data_types'], true)
            ? $this->idsForScopedTable('losses', $filters, ['created_by', 'validated_by'])
            : [];
        $incidentIds = in_array('incidents', $filters['data_types'], true)
            ? $this->idsForScopedTable('operation_cases', $filters, ['created_by', 'signaled_by', 'validated_by', 'technical_confirmed_by', 'resolved_by', 'decided_by'])
            : [];
        $correctionIds = in_array('rapports_operationnels', $filters['data_types'], true)
            ? $this->idsForScopedTable('correction_requests', $filters, ['requested_by', 'reviewed_by'])
            : [];
        $returnSaleItemIds = in_array('retours', $filters['data_types'], true)
            ? $this->returnSaleItemIds($filters)
            : [];

        $kitchenStockItemIds = $kitchenStockRequestIds !== [] && $this->tableExists('kitchen_stock_request_items')
            ? $this->idsByForeignKey('kitchen_stock_request_items', 'request_id', $kitchenStockRequestIds)
            : [];
        $serverRequestItemIds = $serverRequestIds !== []
            ? $this->idsByForeignKey('server_request_items', 'request_id', $serverRequestIds)
            : [];
        $saleItemIds = $saleIds !== []
            ? $this->idsByForeignKey('sale_items', 'sale_id', $saleIds)
            : [];

        $amountTotal = 0.0;
        if ($saleIds !== []) {
            $amountTotal += $this->sumByIds('sales', $saleIds, 'total_amount');
        }
        if ($lossIds !== []) {
            $amountTotal += $this->sumByIds('losses', $lossIds, 'amount');
        }
        if ($serverRequestIds !== []) {
            $amountTotal += $this->sumByIds('server_requests', $serverRequestIds, 'total_requested_amount');
        }

        $auditIds = in_array('audit_operationnel', $filters['data_types'], true)
            ? $this->auditIdsForScope($filters)
            : [];

        return [
            'filters' => $filters,
            'restaurant' => $this->findRestaurant($filters['restaurant_id']),
            'scoped_user' => $filters['user_id'] > 0 ? $this->findUserInRestaurant($filters['user_id'], $filters['restaurant_id']) : null,
            'counts' => [
                'commandes_serveur' => count($serverRequestIds),
                'commandes_serveur_lignes' => count($serverRequestItemIds),
                'demandes_cuisine' => count($kitchenProductionIds),
                'demandes_stock' => count($kitchenStockRequestIds),
                'demandes_stock_lignes' => count($kitchenStockItemIds),
                'ventes' => count($saleIds),
                'ventes_lignes' => count($saleItemIds),
                'pertes' => count($lossIds),
                'incidents' => count($incidentIds),
                'retours' => count($returnSaleItemIds),
                'corrections' => count($correctionIds),
                'audit_operationnel' => count($auditIds),
                'images_test' => 0,
            ],
            'ids' => [
                'server_requests' => $serverRequestIds,
                'server_request_items' => $serverRequestItemIds,
                'kitchen_production' => $kitchenProductionIds,
                'kitchen_stock_requests' => $kitchenStockRequestIds,
                'kitchen_stock_request_items' => $kitchenStockItemIds,
                'sales' => $saleIds,
                'sale_items' => $saleItemIds,
                'losses' => $lossIds,
                'operation_cases' => $incidentIds,
                'correction_requests' => $correctionIds,
                'return_sale_items' => $returnSaleItemIds,
                'audit_logs' => $auditIds,
            ],
            'period' => [
                'start_at' => $filters['start_at'],
                'end_at' => $filters['end_at'],
                'label' => $filters['period_label'],
            ],
            'users_concerned' => $this->usersConcerned($filters, $serverRequestIds, $kitchenProductionIds, $kitchenStockRequestIds, $saleIds, $lossIds, $incidentIds),
            'amount_total' => $amountTotal,
            'dataset_labels' => self::DATASET_LABELS,
        ];
    }

    public function execute(array $payload, array $actor): array
    {
        $preview = $this->preview($payload);
        $filters = $preview['filters'];
        $confirmation = trim((string) ($payload['confirmation_text'] ?? ''));
        $allowedConfirmations = [
            'REINITIALISER',
            'REINITIALISER RESTAURANT ' . $filters['restaurant_id'],
        ];
        if (!in_array($confirmation, $allowedConfirmations, true)) {
            throw new \RuntimeException('Confirmation forte invalide.');
        }

        $reason = trim((string) ($payload['reset_reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('Motif de reinitialisation obligatoire.');
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $deleted = [
                'server_request_items' => 0,
                'server_requests' => 0,
                'kitchen_stock_request_items' => 0,
                'kitchen_stock_requests' => 0,
                'sale_items' => 0,
                'sales' => 0,
                'kitchen_production' => 0,
                'losses' => 0,
                'operation_cases' => 0,
                'correction_requests' => 0,
                'audit_logs' => 0,
            ];

            if ($preview['ids']['server_request_items'] !== []) {
                $deleted['server_request_items'] = $this->deleteByIds('server_request_items', $preview['ids']['server_request_items']);
            }
            if ($preview['ids']['server_requests'] !== []) {
                $deleted['server_requests'] = $this->deleteByIds('server_requests', $preview['ids']['server_requests']);
            }

            if ($preview['ids']['kitchen_stock_request_items'] !== []) {
                $deleted['kitchen_stock_request_items'] = $this->deleteByIds('kitchen_stock_request_items', $preview['ids']['kitchen_stock_request_items']);
            }
            if ($preview['ids']['kitchen_stock_requests'] !== []) {
                $deleted['kitchen_stock_requests'] = $this->deleteByIds('kitchen_stock_requests', $preview['ids']['kitchen_stock_requests']);
            }

            if ($preview['ids']['return_sale_items'] !== [] && !in_array('ventes', $filters['data_types'], true)) {
                $deleted['sale_items'] += $this->deleteByIds('sale_items', $preview['ids']['return_sale_items']);
            }
            if ($preview['ids']['sale_items'] !== []) {
                $deleted['sale_items'] += $this->deleteByIds('sale_items', $preview['ids']['sale_items']);
            }
            if ($preview['ids']['sales'] !== []) {
                $deleted['sales'] = $this->deleteByIds('sales', $preview['ids']['sales']);
            }

            if ($preview['ids']['kitchen_production'] !== []) {
                $deleted['kitchen_production'] = $this->deleteByIds('kitchen_production', $preview['ids']['kitchen_production']);
            }
            if ($preview['ids']['losses'] !== []) {
                $deleted['losses'] = $this->deleteByIds('losses', $preview['ids']['losses']);
            }
            if ($preview['ids']['operation_cases'] !== []) {
                $deleted['operation_cases'] = $this->deleteByIds('operation_cases', $preview['ids']['operation_cases']);
            }
            if ($preview['ids']['correction_requests'] !== []) {
                $deleted['correction_requests'] = $this->deleteByIds('correction_requests', $preview['ids']['correction_requests']);
            }
            if ($preview['ids']['audit_logs'] !== []) {
                $deleted['audit_logs'] = $this->deleteByIds('audit_logs', $preview['ids']['audit_logs']);
            }

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $filters['restaurant_id'],
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? 'super_admin',
            'actor_role_code' => $actor['role_code'] ?? 'super_admin',
            'module_name' => 'platform',
            'action_name' => 'super_admin_data_reset',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $filters['restaurant_id'],
            'new_values' => [
                'filters' => $filters,
                'deleted' => $deleted,
                'confirmation_text' => $confirmation,
                'reason' => $reason,
            ],
            'justification' => $reason,
        ]);

        return [
            'preview' => $preview,
            'deleted' => $deleted,
            'confirmation_text' => $confirmation,
            'reason' => $reason,
        ];
    }

    private function normalizeFilters(array $payload): array
    {
        $restaurantId = (int) ($payload['restaurant_id'] ?? 0);
        if ($restaurantId <= 0) {
            throw new \RuntimeException('Restaurant obligatoire.');
        }

        $scope = (string) ($payload['scope'] ?? 'restaurant');
        $userId = $scope === 'user' ? (int) ($payload['user_id'] ?? 0) : 0;
        if ($scope === 'user' && $userId <= 0) {
            throw new \RuntimeException('Utilisateur cible obligatoire.');
        }
        if ($userId > 0) {
            $this->findUserInRestaurant($userId, $restaurantId);
        }

        $periodType = (string) ($payload['period_type'] ?? 'day');
        $timezone = new DateTimeZone((string) ($this->findRestaurant($restaurantId)['timezone'] ?? 'UTC'));
        [$startAt, $endAt, $label] = $this->resolvePeriodRange($payload, $periodType, $timezone);

        $rawDataTypes = $payload['data_types'] ?? [];
        $dataTypes = is_array($rawDataTypes) ? array_values(array_intersect(array_keys(self::DATASET_LABELS), array_map('strval', $rawDataTypes))) : [];
        if ($dataTypes === []) {
            throw new \RuntimeException('Choisir au moins une categorie de donnees.');
        }

        return [
            'restaurant_id' => $restaurantId,
            'scope' => $scope,
            'user_id' => $userId,
            'period_type' => $periodType,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'period_label' => $label,
            'data_types' => $dataTypes,
        ];
    }

    private function resolvePeriodRange(array $payload, string $periodType, DateTimeZone $timezone): array
    {
        return match ($periodType) {
            'week' => $this->weekRange((string) ($payload['week_value'] ?? ''), $timezone),
            'month' => $this->monthRange((string) ($payload['month_value'] ?? ''), $timezone),
            'custom' => $this->customRange((string) ($payload['date_from'] ?? ''), (string) ($payload['date_to'] ?? ''), $timezone),
            default => $this->dayRange((string) ($payload['day_value'] ?? ''), $timezone),
        };
    }

    private function dayRange(string $value, DateTimeZone $timezone): array
    {
        $date = $value !== '' ? new DateTimeImmutable($value, $timezone) : new DateTimeImmutable('today', $timezone);
        $start = $date->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $start->format('d/m/Y')];
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
            throw new \RuntimeException('Plage personnalisee incomplete.');
        }
        $start = (new DateTimeImmutable($from, $timezone))->setTime(0, 0, 0);
        $end = (new DateTimeImmutable($to, $timezone))->setTime(0, 0, 0)->modify('+1 day');
        if ($end <= $start) {
            throw new \RuntimeException('Periode personnalisee invalide.');
        }
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $start->format('d/m/Y') . ' - ' . $end->modify('-1 second')->format('d/m/Y')];
    }

    private function idsForScopedTable(string $table, array $filters, array $userColumns): array
    {
        $params = [
            'restaurant_id' => $filters['restaurant_id'],
            'start_at' => $filters['start_at'],
            'end_at' => $filters['end_at'],
        ];
        $sql = 'SELECT id FROM ' . $table . ' WHERE restaurant_id = :restaurant_id AND created_at >= :start_at AND created_at < :end_at';
        if ($filters['user_id'] > 0) {
            $clauses = [];
            foreach ($userColumns as $index => $column) {
                $param = 'user_filter_' . $index;
                $clauses[] = $column . ' = :' . $param;
                $params[$param] = $filters['user_id'];
            }
            $sql .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idsByForeignKey(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $list = implode(',', array_map('intval', $ids));
        return array_map(
            static fn ($value): int => (int) $value,
            $this->database->pdo()->query('SELECT id FROM ' . $table . ' WHERE ' . $column . ' IN (' . $list . ')')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function sumByIds(string $table, array $ids, string $column): float
    {
        if ($ids === []) {
            return 0.0;
        }

        $list = implode(',', array_map('intval', $ids));
        return (float) ($this->database->pdo()->query('SELECT COALESCE(SUM(' . $column . '), 0) FROM ' . $table . ' WHERE id IN (' . $list . ')')->fetchColumn() ?: 0);
    }

    private function returnSaleItemIds(array $filters): array
    {
        $sql = 'SELECT si.id
                FROM sale_items si
                INNER JOIN sales s ON s.id = si.sale_id
                WHERE s.restaurant_id = :restaurant_id
                  AND si.status = "RETOUR"
                  AND COALESCE(si.returned_at, si.created_at) >= :start_at
                  AND COALESCE(si.returned_at, si.created_at) < :end_at';
        $params = [
            'restaurant_id' => $filters['restaurant_id'],
            'start_at' => $filters['start_at'],
            'end_at' => $filters['end_at'],
        ];
        if ($filters['user_id'] > 0) {
            $sql .= ' AND s.server_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function auditIdsForScope(array $filters): array
    {
        $modules = ['stock', 'sales', 'kitchen', 'reports', 'incidents', 'dashboard'];
        $placeholders = implode(',', array_fill(0, count($modules), '?'));
        $sql = 'SELECT id FROM audit_logs
                WHERE restaurant_id = ?
                  AND created_at >= ?
                  AND created_at < ?
                  AND module_name IN (' . $placeholders . ')';
        $params = [$filters['restaurant_id'], $filters['start_at'], $filters['end_at'], ...$modules];
        if ($filters['user_id'] > 0) {
            $sql .= ' AND user_id = ?';
            $params[] = $filters['user_id'];
        }
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return array_map(static fn ($value): int => (int) $value, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function usersConcerned(array $filters, array $serverRequestIds, array $kitchenProductionIds, array $kitchenStockRequestIds, array $saleIds, array $lossIds, array $incidentIds): array
    {
        if ($filters['user_id'] > 0) {
            $user = $this->findUserInRestaurant($filters['user_id'], $filters['restaurant_id']);
            return [$user];
        }

        $userIds = [];
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT requested_by FROM server_requests WHERE id IN (' . $this->idListOrZero($serverRequestIds) . ')'));
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT created_by FROM kitchen_production WHERE id IN (' . $this->idListOrZero($kitchenProductionIds) . ')'));
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT requested_by FROM kitchen_stock_requests WHERE id IN (' . $this->idListOrZero($kitchenStockRequestIds) . ')'));
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT server_id FROM sales WHERE id IN (' . $this->idListOrZero($saleIds) . ')'));
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT created_by FROM losses WHERE id IN (' . $this->idListOrZero($lossIds) . ')'));
        $userIds = array_merge($userIds, $this->scalarIds('SELECT DISTINCT signaled_by FROM operation_cases WHERE id IN (' . $this->idListOrZero($incidentIds) . ')'));
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));

        if ($userIds === []) {
            return [];
        }

        $statement = $this->database->pdo()->query(
            'SELECT id, full_name, email
             FROM users
             WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')
             ORDER BY full_name ASC'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalarIds(string $sql): array
    {
        return array_map(static fn ($value): int => (int) $value, $this->database->pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function idListOrZero(array $ids): string
    {
        return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
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
        $restaurant = $statement->fetch(PDO::FETCH_ASSOC);
        if ($restaurant === false) {
            throw new \RuntimeException('Restaurant cible introuvable.');
        }
        return $restaurant;
    }

    private function findUserInRestaurant(int $userId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, full_name, email
             FROM users
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $userId,
            'restaurant_id' => $restaurantId,
        ]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new \RuntimeException('Utilisateur hors perimetre du restaurant cible.');
        }
        return $user;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
    }
}
