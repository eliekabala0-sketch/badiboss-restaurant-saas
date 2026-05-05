<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class StockService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listItems(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT si.*,
                    COALESCE(SUM(CASE WHEN sm.movement_type = "SORTIE_CUISINE" AND sm.status = "PROVISOIRE" THEN sm.quantity ELSE 0 END), 0) AS quantity_out_provisional
             FROM stock_items si
             LEFT JOIN stock_movements sm ON sm.stock_item_id = si.id
             WHERE si.restaurant_id = :restaurant_id
             GROUP BY si.id
             ORDER BY si.name ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listMovements(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sm.*, si.name AS stock_item_name, u.full_name AS user_name, v.full_name AS validated_by_name
             FROM stock_movements sm
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN users v ON v.id = sm.validated_by
             WHERE sm.restaurant_id = :restaurant_id
             ORDER BY sm.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historique magasin : soldes physiques cumulés (ENTREE, PERTE, RETOUR_STOCK, SORTIE, CORRECTION_INVENTAIRE validés).
     * Les sorties cuisine PROVISOIRE et CONSOMMATION_CUISINE ne modifient pas quantity_in_stock : variation physique = 0 pour le calcul.
     *
     * @return list<array<string, mixed>>
     */
    public function listMovementHistoryRows(int $restaurantId): array
    {
        $this->ensureStockMovementEnum();
        $statement = $this->database->pdo()->prepare(
            'SELECT sm.*, si.name AS stock_item_name, si.unit_name,
                    u.full_name AS user_name,
                    ur.code AS user_role_code,
                    v.full_name AS validated_by_name,
                    vr.code AS validated_by_role_code
             FROM stock_movements sm
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             INNER JOIN users u ON u.id = sm.user_id
             LEFT JOIN roles ur ON ur.id = u.role_id
             LEFT JOIN users v ON v.id = sm.validated_by
             LEFT JOIN roles vr ON vr.id = v.role_id
             WHERE sm.restaurant_id = :restaurant_id
             ORDER BY sm.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $runningByItem = [];
        $enriched = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['stock_item_id'];
            $runningByItem[$itemId] ??= 0.0;
            $before = (float) $runningByItem[$itemId];
            $deltaPhysical = $this->physicalStockLedgerDelta($row);
            $after = $before + $deltaPhysical;
            $row['quantity_before_physical'] = $before;
            $row['quantity_delta_physical'] = $deltaPhysical;
            $row['quantity_after_physical'] = $after;
            $runningByItem[$itemId] = $after;
            $enriched[] = $row;
        }

        return array_reverse($enriched);
    }

    /**
     * Réception stock→cuisine (demandes clôturées) et consommations liées aux productions.
     *
     * @return list<array<string, mixed>>
     */
    public function listKitchenEvolution(int $restaurantId, int $limit = 200): array
    {
        $this->ensureKitchenInventoryTables();
        $limit = max(1, min($limit, 400));

        $receptionStatement = $this->database->pdo()->prepare(
            'SELECT "reception" AS ev_kind,
                    ksri.received_at AS occurred_at,
                    si.id AS stock_item_id,
                    si.name AS stock_item_name,
                    si.unit_name,
                    ksri.quantity_supplied AS quantity,
                    ksri.received_by AS actor_user_id,
                    ru.full_name AS actor_name,
                    rr.code AS actor_role_code,
                    ksr.id AS request_id,
                    NULL AS kitchen_production_id,
                    NULL AS menu_item_name,
                    NULL AS dish_type,
                    ksri.note AS line_note
             FROM kitchen_stock_request_items ksri
             INNER JOIN kitchen_stock_requests ksr ON ksr.id = ksri.request_id
             INNER JOIN stock_items si ON si.id = ksri.stock_item_id
             LEFT JOIN users ru ON ru.id = ksri.received_by
             LEFT JOIN roles rr ON rr.id = ru.role_id
             WHERE ksri.restaurant_id = :restaurant_id
               AND ksri.received_at IS NOT NULL
               AND ksri.quantity_supplied > 0'
        );
        $receptionStatement->execute(['restaurant_id' => $restaurantId]);
        $receptions = $receptionStatement->fetchAll(PDO::FETCH_ASSOC);

        $useStatement = $this->database->pdo()->prepare(
            'SELECT "utilisation" AS ev_kind,
                    kpm.created_at AS occurred_at,
                    si.id AS stock_item_id,
                    si.name AS stock_item_name,
                    si.unit_name,
                    kpm.quantity_used AS quantity,
                    kp.created_by AS actor_user_id,
                    u.full_name AS actor_name,
                    ur.code AS actor_role_code,
                    NULL AS request_id,
                    kp.id AS kitchen_production_id,
                    mi.name AS menu_item_name,
                    kp.dish_type,
                    kpm.note AS line_note
             FROM kitchen_production_materials kpm
             INNER JOIN kitchen_production kp ON kp.id = kpm.kitchen_production_id
             INNER JOIN stock_items si ON si.id = kpm.stock_item_id
             INNER JOIN users u ON u.id = kp.created_by
             LEFT JOIN roles ur ON ur.id = u.role_id
             LEFT JOIN menu_items mi ON mi.id = kp.menu_item_id
             WHERE kpm.restaurant_id = :restaurant_id'
        );
        $useStatement->execute(['restaurant_id' => $restaurantId]);
        $uses = $useStatement->fetchAll(PDO::FETCH_ASSOC);

        $merged = array_merge($receptions, $uses);
        usort(
            $merged,
            static fn (array $left, array $right): int => strcmp((string) ($right['occurred_at'] ?? ''), (string) ($left['occurred_at'] ?? ''))
        );

        return array_slice($merged, 0, $limit);
    }

    public function recordFreeStockMovement(int $restaurantId, array $payload, array $actor): void
    {
        $this->ensureStockMovementEnum();
        $kind = strtoupper(trim((string) ($payload['movement_kind'] ?? '')));
        $note = trim((string) ($payload['note'] ?? '')) ?: null;

        if ($kind === 'PERTE') {
            $itemId = $this->resolveStockItemForFreeInput($restaurantId, $payload, $actor);
            $this->declareLoss($restaurantId, [
                'stock_item_id' => $itemId,
                'quantity' => $payload['quantity'],
                'amount' => $payload['amount'] ?? 0,
                'description' => $note ?? 'Perte (saisie libre)',
            ], $actor);

            return;
        }

        $itemId = $this->resolveStockItemForFreeInput($restaurantId, $payload, $actor);
        $item = $this->findStockItemInRestaurant($itemId, $restaurantId);
        $unitCost = (float) ($item['estimated_unit_cost'] ?? 0);

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            if ($kind === 'ENTREE') {
                $quantity = (float) ($payload['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new \RuntimeException('Quantite d entree invalide.');
                }
                $entryCost = (float) ($payload['unit_cost'] ?? $unitCost);
                $movementId = $this->insertMovement($restaurantId, [
                    'stock_item_id' => $itemId,
                    'movement_type' => 'ENTREE',
                    'quantity' => $quantity,
                    'unit_cost_snapshot' => $entryCost,
                    'total_cost_snapshot' => $quantity * $entryCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'manual_stock',
                    'reference_id' => null,
                    'note' => $note,
                ]);
                $this->adjustStockItem($itemId, $restaurantId, $quantity);
                $this->updateEstimatedUnitCost($itemId, $restaurantId, $entryCost);
                $pdo->commit();
                Container::getInstance()->get('audit')->log([
                    'restaurant_id' => $restaurantId,
                    'user_id' => $actor['id'],
                    'actor_name' => $actor['full_name'],
                    'actor_role_code' => $actor['role_code'],
                    'module_name' => 'stock',
                    'action_name' => 'stock_free_movement_entry',
                    'entity_type' => 'stock_movements',
                    'entity_id' => (string) $movementId,
                    'new_values' => $payload,
                    'justification' => 'Entrée stock (saisie libre)',
                ]);

                return;
            }

            if ($kind === 'SORTIE') {
                $quantity = (float) ($payload['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new \RuntimeException('Quantite de sortie invalide.');
                }
                if ($quantity > (float) $item['quantity_in_stock']) {
                    throw new \RuntimeException('Sortie impossible: stock insuffisant.');
                }
                $movementId = $this->insertMovement($restaurantId, [
                    'stock_item_id' => $itemId,
                    'movement_type' => 'SORTIE',
                    'quantity' => $quantity,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => $quantity * $unitCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'manual_stock',
                    'reference_id' => null,
                    'note' => $note,
                ]);
                $this->adjustStockItem($itemId, $restaurantId, -1 * $quantity);
                $pdo->commit();
                Container::getInstance()->get('audit')->log([
                    'restaurant_id' => $restaurantId,
                    'user_id' => $actor['id'],
                    'actor_name' => $actor['full_name'],
                    'actor_role_code' => $actor['role_code'],
                    'module_name' => 'stock',
                    'action_name' => 'stock_free_movement_out',
                    'entity_type' => 'stock_movements',
                    'entity_id' => (string) $movementId,
                    'new_values' => $payload,
                    'justification' => 'Sortie stock hors cuisine (saisie libre)',
                ]);

                return;
            }

            if ($kind === 'RETOUR') {
                $quantity = (float) ($payload['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new \RuntimeException('Quantite de retour invalide.');
                }
                $movementId = $this->insertMovement($restaurantId, [
                    'stock_item_id' => $itemId,
                    'movement_type' => 'RETOUR_STOCK',
                    'quantity' => $quantity,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => $quantity * $unitCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'manuel_magasin',
                    'reference_id' => null,
                    'note' => $note,
                ]);
                $this->adjustStockItem($itemId, $restaurantId, $quantity);
                $pdo->commit();
                Container::getInstance()->get('audit')->log([
                    'restaurant_id' => $restaurantId,
                    'user_id' => $actor['id'],
                    'actor_name' => $actor['full_name'],
                    'actor_role_code' => $actor['role_code'],
                    'module_name' => 'stock',
                    'action_name' => 'stock_free_movement_return',
                    'entity_type' => 'stock_movements',
                    'entity_id' => (string) $movementId,
                    'new_values' => $payload,
                    'justification' => 'Retour / réintégration stock (saisie libre)',
                ]);

                return;
            }

            if ($kind === 'CORRECTION') {
                $signed = (float) ($payload['signed_adjustment'] ?? 0);
                if (abs($signed) < 0.00001) {
                    throw new \RuntimeException('Correction inventaire: variation nulle.');
                }
                $current = (float) $item['quantity_in_stock'];
                if ($current + $signed < -0.00001) {
                    throw new \RuntimeException('Correction impossible: le stock deviendrait négatif.');
                }
                $movementId = $this->insertMovement($restaurantId, [
                    'stock_item_id' => $itemId,
                    'movement_type' => 'CORRECTION_INVENTAIRE',
                    'quantity' => $signed,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => abs($signed) * $unitCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'inventaire_manuel',
                    'reference_id' => null,
                    'note' => $note,
                ]);
                $this->adjustStockItem($itemId, $restaurantId, $signed);
                $pdo->commit();
                Container::getInstance()->get('audit')->log([
                    'restaurant_id' => $restaurantId,
                    'user_id' => $actor['id'],
                    'actor_name' => $actor['full_name'],
                    'actor_role_code' => $actor['role_code'],
                    'module_name' => 'stock',
                    'action_name' => 'stock_inventory_correction',
                    'entity_type' => 'stock_movements',
                    'entity_id' => (string) $movementId,
                    'new_values' => $payload,
                    'justification' => 'Correction inventaire contrôlée',
                ]);

                return;
            }

            throw new \RuntimeException('Type de mouvement libre inconnu.');
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    public function createItem(int $restaurantId, array $payload, array $actor): void
    {
        $optionalColumns = $this->stockItemOptionalColumns();
        $columns = ['restaurant_id', 'name', 'unit_name', 'quantity_in_stock', 'alert_threshold', 'estimated_unit_cost'];
        $placeholders = [':restaurant_id', ':name', ':unit_name', ':quantity_in_stock', ':alert_threshold', ':estimated_unit_cost'];
        $params = [
            'restaurant_id' => $restaurantId,
            'name' => trim((string) $payload['name']),
            'unit_name' => trim((string) $payload['unit_name']),
            'quantity_in_stock' => (float) $payload['quantity_in_stock'],
            'alert_threshold' => (float) $payload['alert_threshold'],
            'estimated_unit_cost' => (float) ($payload['estimated_unit_cost'] ?? 0),
        ];

        if ($optionalColumns['category_label']) {
            $columns[] = 'category_label';
            $placeholders[] = ':category_label';
            $params['category_label'] = trim((string) ($payload['category_label'] ?? '')) ?: null;
        }
        if ($optionalColumns['item_note']) {
            $columns[] = 'item_note';
            $placeholders[] = ':item_note';
            $params['item_note'] = trim((string) ($payload['item_note'] ?? '')) ?: null;
        }
        if ($optionalColumns['updated_at']) {
            $columns[] = 'updated_at';
            $placeholders[] = 'NOW()';
        }
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO stock_items (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($params);

        $stockItemId = (int) $this->database->pdo()->lastInsertId();
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'stock_item_created',
            'entity_type' => 'stock_items',
            'entity_id' => (string) $stockItemId,
            'new_values' => $payload,
            'justification' => 'Création article de stock',
        ]);
    }

    public function updateItem(int $restaurantId, int $stockItemId, array $payload, array $actor): void
    {
        $current = $this->findStockItemInRestaurant($stockItemId, $restaurantId);
        $optionalColumns = $this->stockItemOptionalColumns();
        $assignments = [
            'name = :name',
            'unit_name = :unit_name',
            'alert_threshold = :alert_threshold',
            'estimated_unit_cost = :estimated_unit_cost',
        ];
        $params = [
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
            'name' => trim((string) $payload['name']),
            'unit_name' => trim((string) $payload['unit_name']),
            'alert_threshold' => (float) ($payload['alert_threshold'] ?? 0),
            'estimated_unit_cost' => (float) ($payload['estimated_unit_cost'] ?? 0),
        ];

        if ($optionalColumns['category_label']) {
            $assignments[] = 'category_label = :category_label';
            $params['category_label'] = trim((string) ($payload['category_label'] ?? '')) ?: null;
        }
        if ($optionalColumns['item_note']) {
            $assignments[] = 'item_note = :item_note';
            $params['item_note'] = trim((string) ($payload['item_note'] ?? '')) ?: null;
        }
        if ($optionalColumns['updated_at']) {
            $assignments[] = 'updated_at = NOW()';
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE stock_items
             SET ' . implode(', ', $assignments) . '
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute($params);

        $oldCost = (float) ($current['estimated_unit_cost'] ?? 0);
        $newCost = (float) $params['estimated_unit_cost'];
        $actionName = abs($oldCost - $newCost) > 0.00001 ? 'stock_item_price_updated' : 'stock_item_updated';
        $justification = abs($oldCost - $newCost) > 0.00001
            ? 'Cout unitaire corrige sans recalcul des mouvements historiques'
            : 'Mise a jour controlee de l article de stock';

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => $actionName,
            'entity_type' => 'stock_items',
            'entity_id' => (string) $stockItemId,
            'old_values' => $current,
            'new_values' => [
                'name' => $params['name'],
                'unit_name' => $params['unit_name'],
                'alert_threshold' => $params['alert_threshold'],
                'estimated_unit_cost' => $params['estimated_unit_cost'],
                'category_label' => $params['category_label'] ?? ($current['category_label'] ?? null),
                'item_note' => $params['item_note'] ?? ($current['item_note'] ?? null),
            ],
            'justification' => $justification,
        ]);
    }

    public function addEntry(int $restaurantId, array $payload, array $actor): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $item = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
            $unitCost = (float) ($payload['unit_cost'] ?? $item['estimated_unit_cost']);
            $quantity = (float) $payload['quantity'];
            if ($quantity <= 0) {
                throw new \RuntimeException('Quantite d entree invalide.');
            }

            $movementId = $this->insertMovement($restaurantId, [
                'stock_item_id' => (int) $payload['stock_item_id'],
                'movement_type' => 'ENTREE',
                'quantity' => $quantity,
                'unit_cost_snapshot' => $unitCost,
                'total_cost_snapshot' => $quantity * $unitCost,
                'status' => 'VALIDE',
                'user_id' => $actor['id'],
                'validated_by' => $actor['id'],
                'reference_type' => 'stock_entry',
                'reference_id' => null,
                'note' => $payload['note'] ?? null,
            ]);

            $this->adjustStockItem((int) $payload['stock_item_id'], $restaurantId, $quantity);
            $this->updateEstimatedUnitCost((int) $payload['stock_item_id'], $restaurantId, $unitCost);
            $pdo->commit();

            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'stock',
                'action_name' => 'stock_entry_validated',
                'entity_type' => 'stock_movements',
                'entity_id' => (string) $movementId,
                'new_values' => $payload,
                'justification' => 'Entrée de stock validée',
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function sendToKitchen(int $restaurantId, array $payload, array $actor): int
    {
        $item = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
        $quantity = (float) $payload['quantity'];
        if ($quantity <= 0) {
            throw new \RuntimeException('Quantite de sortie invalide.');
        }
        if ($quantity > (float) $item['quantity_in_stock']) {
            throw new \RuntimeException('Sortie cuisine impossible: stock insuffisant.');
        }

        $movementId = $this->insertMovement($restaurantId, [
            'stock_item_id' => (int) $payload['stock_item_id'],
            'movement_type' => 'SORTIE_CUISINE',
            'quantity' => $quantity,
            'unit_cost_snapshot' => (float) $item['estimated_unit_cost'],
            'total_cost_snapshot' => $quantity * (float) $item['estimated_unit_cost'],
            'status' => 'PROVISOIRE',
            'user_id' => $actor['id'],
            'validated_by' => null,
            'reference_type' => 'kitchen_production',
            'reference_id' => null,
            'note' => $payload['note'] ?? null,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'stock_sent_to_kitchen',
            'entity_type' => 'stock_movements',
            'entity_id' => (string) $movementId,
            'new_values' => $payload,
            'justification' => 'Sortie provisoire vers cuisine',
        ]);

        return $movementId;
    }

    public function validateReturn(int $restaurantId, array $payload, array $actor): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
            $sourceMovement = $this->findMovementInRestaurant((int) $payload['source_movement_id'], $restaurantId);
            $production = $this->findProductionInRestaurant((int) $payload['kitchen_production_id'], $restaurantId);
            $quantity = (float) $payload['quantity'];

            if ($quantity <= 0) {
                throw new \RuntimeException('Quantite de retour obligatoire.');
            }

            if ($sourceMovement['movement_type'] !== 'SORTIE_CUISINE') {
                throw new \RuntimeException('Le mouvement source du retour stock est invalide.');
            }

            if ((int) $sourceMovement['stock_item_id'] !== (int) $payload['stock_item_id']) {
                throw new \RuntimeException('Le retour stock doit cibler le meme article que la sortie cuisine.');
            }

            if ((int) ($sourceMovement['reference_id'] ?? 0) !== (int) $production['id']) {
                throw new \RuntimeException('Le mouvement source ne correspond pas a la production cuisine selectionnee.');
            }
            if ($quantity > (float) $sourceMovement['quantity']) {
                throw new \RuntimeException('Retour impossible: quantite superieure a la sortie cuisine.');
            }
            if ($quantity > (float) $production['quantity_remaining']) {
                throw new \RuntimeException('Retour impossible: quantite superieure au reste cuisine.');
            }

            $movementId = $this->insertMovement($restaurantId, [
                'stock_item_id' => (int) $payload['stock_item_id'],
                'movement_type' => 'RETOUR_STOCK',
                'quantity' => $quantity,
                'unit_cost_snapshot' => (float) $sourceMovement['unit_cost_snapshot'],
                'total_cost_snapshot' => $quantity * (float) $sourceMovement['unit_cost_snapshot'],
                'status' => 'VALIDE',
                'user_id' => (int) $sourceMovement['user_id'],
                'validated_by' => $actor['id'],
                'reference_type' => 'kitchen_production',
                'reference_id' => (int) $payload['kitchen_production_id'],
                'note' => $payload['note'] ?? null,
            ]);

            $this->adjustStockItem((int) $payload['stock_item_id'], $restaurantId, $quantity);

            $updateMovement = $pdo->prepare(
                'UPDATE stock_movements SET status = "VALIDE", validated_by = :validated_by, validated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $updateMovement->execute([
                'validated_by' => $actor['id'],
                'id' => (int) $payload['source_movement_id'],
                'restaurant_id' => $restaurantId,
            ]);

            $updateProduction = $pdo->prepare(
                'UPDATE kitchen_production
                 SET quantity_remaining = GREATEST(quantity_remaining - :quantity, 0),
                     status = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN "TERMINE" ELSE status END,
                     closed_at = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN NOW() ELSE closed_at END
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $updateProduction->execute([
                'quantity' => $quantity,
                'id' => (int) $payload['kitchen_production_id'],
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'stock',
                'action_name' => 'stock_return_validated',
                'entity_type' => 'stock_movements',
                'entity_id' => (string) $movementId,
                'new_values' => $payload,
                'justification' => 'Retour stock validé',
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function declareLoss(int $restaurantId, array $payload, array $actor): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $item = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
            $quantity = (float) $payload['quantity'];
            if ($quantity <= 0) {
                throw new \RuntimeException('Quantite de perte obligatoire.');
            }
            if ($quantity > (float) $item['quantity_in_stock']) {
                throw new \RuntimeException('Perte impossible: quantite superieure au stock disponible.');
            }

            $movementId = $this->insertMovement($restaurantId, [
                'stock_item_id' => (int) $payload['stock_item_id'],
                'movement_type' => 'PERTE',
                'quantity' => $quantity,
                'unit_cost_snapshot' => (float) $item['estimated_unit_cost'],
                'total_cost_snapshot' => $quantity * (float) $item['estimated_unit_cost'],
                'status' => 'VALIDE',
                'user_id' => $actor['id'],
                'validated_by' => $actor['id'],
                'reference_type' => 'loss',
                'reference_id' => null,
                'note' => $payload['description'],
            ]);

            $lossStatement = $pdo->prepare(
                'INSERT INTO losses (restaurant_id, loss_type, reference_id, description, amount, validated_by, created_by, created_at, validated_at)
                 VALUES (:restaurant_id, "MATIERE_PREMIERE", :reference_id, :description, :amount, :validated_by, :created_by, NOW(), NOW())'
            );
            $lossStatement->execute([
                'restaurant_id' => $restaurantId,
                'reference_id' => $movementId,
                'description' => $payload['description'],
                'amount' => (float) $payload['amount'],
                'validated_by' => $actor['id'],
                'created_by' => $actor['id'],
            ]);

            $this->adjustStockItem((int) $payload['stock_item_id'], $restaurantId, -1 * $quantity);
            $lossId = (int) $pdo->lastInsertId();
            $pdo->commit();

            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'losses',
                'action_name' => 'raw_material_loss_created',
                'entity_type' => 'losses',
                'entity_id' => (string) $lossId,
                'new_values' => $payload,
                'justification' => 'Déclaration perte matière première',
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function declareCashLoss(int $restaurantId, array $payload, array $actor): void
    {
        if ($payload['reference_id'] !== '') {
            $this->findSaleInRestaurant((int) $payload['reference_id'], $restaurantId);
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO losses (restaurant_id, loss_type, reference_id, description, amount, validated_by, created_by, created_at, validated_at)
             VALUES (:restaurant_id, "ARGENT", :reference_id, :description, :amount, :validated_by, :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'reference_id' => $payload['reference_id'] !== '' ? (int) $payload['reference_id'] : null,
            'description' => $payload['description'],
            'amount' => (float) $payload['amount'],
            'validated_by' => $actor['id'],
            'created_by' => $actor['id'],
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'losses',
            'action_name' => 'cash_loss_created',
            'entity_type' => 'losses',
            'entity_id' => (string) $this->database->pdo()->lastInsertId(),
            'new_values' => $payload,
            'justification' => 'Déclaration perte d’argent',
        ]);
    }

    public function reconcileOverdueKitchenIssues(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT kp.id AS production_id, kp.menu_item_id, kp.quantity_remaining, kp.stock_movement_id
             FROM kitchen_production kp
             INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             WHERE kp.restaurant_id = :restaurant_id
               AND kp.quantity_remaining > 0
               AND sm.status = "PROVISOIRE"
               AND sm.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listKitchenStockRequests(int $restaurantId): array
    {
        $this->ensureKitchenStockRequestItemsTable();
        $statement = $this->database->pdo()->prepare(
            'SELECT ksr.*,
                    si.name AS stock_item_name,
                    si.unit_name,
                    rq.full_name AS requested_by_name,
                    rp.full_name AS responded_by_name,
                    ru.full_name AS received_by_name
             FROM kitchen_stock_requests ksr
             INNER JOIN stock_items si ON si.id = ksr.stock_item_id
             INNER JOIN users rq ON rq.id = ksr.requested_by
             LEFT JOIN users rp ON rp.id = ksr.responded_by
             LEFT JOIN users ru ON ru.id = ksr.received_by
             WHERE ksr.restaurant_id = :restaurant_id
             ORDER BY ksr.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listKitchenStockRequestBlocks(int $restaurantId): array
    {
        $headers = $this->listKitchenStockRequests($restaurantId);
        $requestIds = array_map(static fn (array $row): int => (int) $row['id'], $headers);
        $itemsByRequest = $this->kitchenStockRequestItemsByRequest($restaurantId, $requestIds);

        foreach ($headers as &$header) {
            $requestId = (int) $header['id'];
            $items = $itemsByRequest[$requestId] ?? [];
            if ($items === []) {
                $items = [$this->legacyKitchenStockRequestAsItem($header)];
                $itemsByRequest[$requestId] = $items;
            }

            $header['item_count'] = count($items);
            $header['products_summary'] = count($items) . ' produit(s) demande(s)';
            $header['quantity_requested_total'] = array_sum(array_map(static fn (array $item): float => (float) ($item['quantity_requested'] ?? 0), $items));
            $header['quantity_supplied_total'] = array_sum(array_map(static fn (array $item): float => (float) ($item['quantity_supplied'] ?? 0), $items));
            $header['unavailable_quantity_total'] = array_sum(array_map(static fn (array $item): float => (float) ($item['unavailable_quantity'] ?? 0), $items));
            $header['status'] = $this->resolveKitchenStockRequestStatus($items, (string) ($header['status'] ?? 'DEMANDE'));
        }
        unset($header);

        return [
            'requests' => $headers,
            'items_by_request' => $itemsByRequest,
        ];
    }

    public function listKitchenInventory(int $restaurantId): array
    {
        $this->ensureKitchenInventoryTables();
        $statement = $this->database->pdo()->prepare(
            'SELECT ki.*, si.name AS stock_item_name, si.unit_name
             FROM kitchen_inventory ki
             INNER JOIN stock_items si ON si.id = ki.stock_item_id
             WHERE ki.restaurant_id = :restaurant_id
             ORDER BY si.name ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function consumeKitchenMaterials(int $restaurantId, array $materials, array $actor, ?int $menuItemId = null): array
    {
        $this->ensureKitchenInventoryTables();
        if ($materials === []) {
            throw new \RuntimeException('Aucune matiere premiere selectionnee.');
        }

        $pdo = $this->database->pdo();
        $movementIds = [];
        $normalized = [];
        $pdo->beginTransaction();

        try {
            foreach ($materials as $material) {
                $stockItemId = (int) ($material['stock_item_id'] ?? 0);
                $quantityUsed = (float) ($material['quantity_used'] ?? 0);
                if ($stockItemId <= 0 || $quantityUsed <= 0) {
                    continue;
                }

                $inventory = $this->findKitchenInventoryItem($restaurantId, $stockItemId);
                if ($inventory === null || (float) ($inventory['quantity_available'] ?? 0) < $quantityUsed) {
                    throw new \RuntimeException('Matiere premiere indisponible ou insuffisante en cuisine.');
                }

                $statement = $pdo->prepare(
                    'UPDATE kitchen_inventory
                     SET quantity_available = quantity_available - :quantity_used,
                         updated_at = NOW()
                     WHERE restaurant_id = :restaurant_id AND stock_item_id = :stock_item_id'
                );
                $statement->execute([
                    'quantity_used' => $quantityUsed,
                    'restaurant_id' => $restaurantId,
                    'stock_item_id' => $stockItemId,
                ]);

                $stockItem = $this->findStockItemInRestaurant($stockItemId, $restaurantId);
                $movementIds[] = $this->insertMovement($restaurantId, [
                    'stock_item_id' => $stockItemId,
                    'movement_type' => 'CONSOMMATION_CUISINE',
                    'quantity' => $quantityUsed,
                    'unit_cost_snapshot' => (float) ($stockItem['estimated_unit_cost'] ?? 0),
                    'total_cost_snapshot' => $quantityUsed * (float) ($stockItem['estimated_unit_cost'] ?? 0),
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'kitchen_production',
                    'reference_id' => $menuItemId,
                    'note' => trim((string) ($material['note'] ?? 'Consommation cuisine.')) ?: null,
                ]);

                $normalized[] = [
                    'stock_item_id' => $stockItemId,
                    'quantity_used' => $quantityUsed,
                    'note' => trim((string) ($material['note'] ?? '')),
                ];
            }

            if ($normalized === []) {
                throw new \RuntimeException('Aucune matiere premiere valide n a ete fournie.');
            }

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }

        return ['movement_ids' => $movementIds, 'materials' => $normalized];
    }

    public function createKitchenStockRequest(int $restaurantId, array $payload, array $actor): void
    {
        $this->ensureKitchenStockRequestItemsTable();
        $items = $this->normalizeKitchenStockRequestItems($restaurantId, $payload);
        $priorityLevel = $this->resolveKitchenStockPriority($items, (string) ($payload['priority_level'] ?? 'normale'));
        $requestNote = trim((string) ($payload['note'] ?? 'Demande cuisine vers stock.'));
        $totalRequested = array_sum(array_map(static fn (array $item): float => (float) $item['quantity_requested'], $items));
        $primaryItem = $items[0];

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'INSERT INTO kitchen_stock_requests
                (restaurant_id, requested_by, stock_item_id, quantity_requested, quantity_supplied, unavailable_quantity, status, priority_level, planning_status, note, response_note, responded_by, received_by, created_at, responded_at, received_at, updated_at)
                 VALUES
                (:restaurant_id, :requested_by, :stock_item_id, :quantity_requested, 0, :quantity_requested, "DEMANDE", :priority_level, NULL, :note, NULL, NULL, NULL, NOW(), NULL, NULL, NOW())'
            );
            $statement->execute([
                'restaurant_id' => $restaurantId,
                'requested_by' => $actor['id'],
                'stock_item_id' => $primaryItem['stock_item_id'],
                'quantity_requested' => $totalRequested,
                'priority_level' => $priorityLevel,
                'note' => $requestNote,
            ]);

            $requestId = (int) $pdo->lastInsertId();
            $itemStatement = $pdo->prepare(
                'INSERT INTO kitchen_stock_request_items
                (request_id, restaurant_id, stock_item_id, quantity_requested, quantity_supplied, unavailable_quantity, status, priority_level, planning_status, note, response_note, responded_by, received_by, created_at, responded_at, received_at, updated_at)
                 VALUES
                (:request_id, :restaurant_id, :stock_item_id, :quantity_requested, 0, :unavailable_quantity, "DEMANDE", :priority_level, NULL, :note, NULL, NULL, NULL, NOW(), NULL, NULL, NOW())'
            );

            foreach ($items as $item) {
                $itemStatement->execute([
                    'request_id' => $requestId,
                    'restaurant_id' => $restaurantId,
                    'stock_item_id' => $item['stock_item_id'],
                    'quantity_requested' => $item['quantity_requested'],
                    'unavailable_quantity' => $item['quantity_requested'],
                    'priority_level' => $item['priority_level'],
                    'note' => $item['note'] !== '' ? $item['note'] : null,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_created',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'items' => $items,
                'quantity_requested_total' => $totalRequested,
                'priority_level' => $priorityLevel,
                'note' => $requestNote,
            ],
            'justification' => 'Demande cuisine vers stock',
        ]);
    }

    public function respondKitchenStockRequest(int $restaurantId, int $requestId, array $payload, array $actor): void
    {
        $request = $this->findKitchenStockRequestInRestaurant($requestId, $restaurantId);
        $requestItems = $this->kitchenStockRequestItemsForRequest($restaurantId, $requestId);
        if ($requestItems !== []) {
            $this->respondKitchenStockRequestBlock($restaurantId, $request, $requestItems, $payload, $actor);
            return;
        }

        $item = $this->findStockItemInRestaurant((int) $request['stock_item_id'], $restaurantId);
        $legacyLine = is_array(($payload['items'] ?? null)) ? reset($payload['items']) : null;
        $quantitySupplied = max(0.0, (float) ($payload['quantity_supplied'] ?? ($legacyLine['quantity_supplied'] ?? 0)));
        $workflowStage = (string) ($payload['workflow_stage'] ?? 'FINALISER');
        $status = (string) ($payload['status'] ?? 'FOURNI_TOTAL');
        $planningStatus = (string) ($payload['planning_status'] ?? ($legacyLine['planning_status'] ?? ''));

        if (!in_array($workflowStage, ['EN_COURS_TRAITEMENT', 'FINALISER'], true)) {
            throw new \RuntimeException('Etape de traitement stock invalide.');
        }
        if (!in_array($status, ['FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI', 'DISPONIBLE', 'PARTIELLEMENT_DISPONIBLE', 'INDISPONIBLE'], true)) {
            throw new \RuntimeException('Statut de reponse stock invalide.');
        }
        if (in_array($status, ['INDISPONIBLE', 'NON_FOURNI'], true) && $planningStatus === '') {
            throw new \RuntimeException('Classer la demande indisponible en urgence ou a prevoir.');
        }
        if ($quantitySupplied > (float) $request['quantity_requested']) {
            throw new \RuntimeException('La quantite fournie ne peut pas depasser la demande cuisine.');
        }
        if ($quantitySupplied > (float) $item['quantity_in_stock']) {
            throw new \RuntimeException('Quantite fournie superieure au stock disponible.');
        }

        if ($workflowStage === 'EN_COURS_TRAITEMENT') {
            $statement = $this->database->pdo()->prepare(
                'UPDATE kitchen_stock_requests
                 SET status = "EN_COURS_TRAITEMENT",
                     planning_status = :planning_status,
                     note = :note,
                     responded_by = :responded_by,
                     responded_at = COALESCE(responded_at, NOW()),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $statement->execute([
                'planning_status' => $planningStatus !== '' ? $planningStatus : $request['planning_status'],
                'note' => trim((string) ($payload['note'] ?? $request['note'])),
                'responded_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'stock',
                'action_name' => 'kitchen_stock_request_processing_started',
                'entity_type' => 'kitchen_stock_requests',
                'entity_id' => (string) $requestId,
                'new_values' => ['workflow_stage' => 'EN_COURS_TRAITEMENT'],
                'justification' => 'Demande cuisine prise en charge par le stock',
            ]);

            return;
        }

        $normalizedStatus = match ($status) {
            'DISPONIBLE' => 'FOURNI_TOTAL',
            'PARTIELLEMENT_DISPONIBLE' => 'FOURNI_PARTIEL',
            'INDISPONIBLE' => 'NON_FOURNI',
            default => $status,
        };
        if ($normalizedStatus === 'FOURNI_TOTAL' && abs($quantitySupplied - (float) $request['quantity_requested']) > 0.0001) {
            throw new \RuntimeException('Le fourni total doit couvrir toute la demande cuisine.');
        }
        if ($normalizedStatus === 'FOURNI_PARTIEL' && ($quantitySupplied <= 0 || $quantitySupplied >= (float) $request['quantity_requested'])) {
            throw new \RuntimeException('Le fourni partiel doit rester strictement entre zero et la demande.');
        }
        if ($normalizedStatus === 'NON_FOURNI' && $quantitySupplied > 0) {
            throw new \RuntimeException('Une demande non fournie doit rester a zero.');
        }
        $unavailableQuantity = max((float) $request['quantity_requested'] - $quantitySupplied, 0);
        $statement = $this->database->pdo()->prepare(
            'UPDATE kitchen_stock_requests
             SET quantity_supplied = :quantity_supplied,
                 unavailable_quantity = :unavailable_quantity,
                 status = :status,
                 planning_status = :planning_status,
                 note = :note,
                 response_note = :response_note,
                 responded_by = :responded_by,
                 responded_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $responseNote = trim((string) ($payload['response_note'] ?? ($legacyLine['response_note'] ?? null) ?? $payload['note'] ?? $request['response_note'] ?? $request['note']));
        $statement->execute([
            'quantity_supplied' => $quantitySupplied,
            'unavailable_quantity' => $unavailableQuantity,
            'status' => $normalizedStatus,
            'planning_status' => $planningStatus !== '' ? $planningStatus : null,
            'note' => trim((string) ($payload['note'] ?? $request['note'])),
            'response_note' => $responseNote !== '' ? $responseNote : null,
            'responded_by' => $actor['id'],
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_responded',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'status' => $normalizedStatus,
                'quantity_supplied' => $quantitySupplied,
                'unavailable_quantity' => $unavailableQuantity,
            ],
            'justification' => 'Reponse stock a la demande cuisine',
        ]);
    }

    public function confirmKitchenStockReceipt(int $restaurantId, int $requestId, array $actor): void
    {
        $request = $this->findKitchenStockRequestInRestaurant($requestId, $restaurantId);
        $requestItems = $this->kitchenStockRequestItemsForRequest($restaurantId, $requestId);

        if ($requestItems !== []) {
            $this->confirmKitchenStockReceiptBlock($restaurantId, $request, $requestItems, $actor);
            return;
        }

        if ((int) $request['requested_by'] !== (int) $actor['id'] && ($actor['role_code'] ?? null) !== 'manager') {
            throw new \RuntimeException('Cette reception ne peut pas etre confirmee par cet utilisateur.');
        }

        if (!in_array((string) $request['status'], ['FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI'], true)) {
            throw new \RuntimeException('La demande stock n est pas encore prete a etre receptionnee.');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE kitchen_stock_requests
             SET status = "CLOTURE",
                 received_by = :received_by,
                 received_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'received_by' => $actor['id'],
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);

        if ((float) ($request['quantity_supplied'] ?? 0) > 0) {
            $this->increaseKitchenInventory($restaurantId, (int) $request['stock_item_id'], (float) $request['quantity_supplied']);
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_received',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $requestId,
            'new_values' => ['status' => 'CLOTURE'],
            'justification' => 'Reception stock confirmee par la cuisine',
        ]);
    }

    public function recentAudits(int $restaurantId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM audit_logs
             WHERE restaurant_id = :restaurant_id
               AND module_name = "stock"
               AND action_name IN (
                   "stock_item_created",
                   "stock_item_updated",
                   "stock_item_price_updated",
                   "stock_quantity_correction_requested",
                   "stock_quantity_correction_approved",
                   "stock_quantity_correction_rejected",
                   "sensitive_operation_correction_requested"
               )
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['old_values'] = $this->decodeAuditJson($row['old_values_json'] ?? null);
            $row['new_values'] = $this->decodeAuditJson($row['new_values_json'] ?? null);

            return $row;
        }, $rows);
    }

    public function findMovementWithItem(int $movementId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sm.*, si.name AS stock_item_name, si.unit_name
             FROM stock_movements sm
             INNER JOIN stock_items si ON si.id = sm.stock_item_id
             WHERE sm.id = :id AND sm.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $movementId,
            'restaurant_id' => $restaurantId,
        ]);
        $movement = $statement->fetch(PDO::FETCH_ASSOC);

        if ($movement === false) {
            throw new \RuntimeException('Mouvement de stock hors perimetre restaurant.');
        }

        return $movement;
    }

    public function applyValidatedQuantityCorrection(
        int $restaurantId,
        int $movementId,
        float $newQuantity,
        string $justification,
        array $actor,
        int $correctionRequestId
    ): void {
        $movement = $this->findMovementWithItem($movementId, $restaurantId);

        if ($movement['status'] !== 'VALIDE') {
            throw new \RuntimeException('Seuls les mouvements valides peuvent etre corriges.');
        }
        if ($movement['movement_type'] !== 'ENTREE') {
            throw new \RuntimeException('Seules les entrees de stock validees recoivent une correction automatique.');
        }

        $oldQuantity = (float) $movement['quantity'];
        if (abs($oldQuantity - $newQuantity) < 0.00001) {
            return;
        }

        $delta = $newQuantity - $oldQuantity;
        $unitCost = (float) ($movement['unit_cost_snapshot'] ?? 0);
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            if ($delta > 0) {
                $this->insertMovement($restaurantId, [
                    'stock_item_id' => (int) $movement['stock_item_id'],
                    'movement_type' => 'ENTREE',
                    'quantity' => $delta,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => $delta * $unitCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'correction_request',
                    'reference_id' => $correctionRequestId,
                    'note' => 'Correction approuvee de l entree #' . $movementId . ' - ' . $justification,
                ]);
                $this->adjustStockItem((int) $movement['stock_item_id'], $restaurantId, $delta);
            } else {
                $quantityToRemove = abs($delta);
                $item = $this->findStockItemInRestaurant((int) $movement['stock_item_id'], $restaurantId);
                if ($quantityToRemove > (float) ($item['quantity_in_stock'] ?? 0)) {
                    throw new \RuntimeException('Correction impossible: stock actuel insuffisant pour diminuer cette entree.');
                }

                $this->insertMovement($restaurantId, [
                    'stock_item_id' => (int) $movement['stock_item_id'],
                    'movement_type' => 'PERTE',
                    'quantity' => $quantityToRemove,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => $quantityToRemove * $unitCost,
                    'status' => 'VALIDE',
                    'user_id' => $actor['id'],
                    'validated_by' => $actor['id'],
                    'reference_type' => 'correction_request',
                    'reference_id' => $correctionRequestId,
                    'note' => 'Correction approuvee de l entree #' . $movementId . ' - ' . $justification,
                ]);
                $this->adjustStockItem((int) $movement['stock_item_id'], $restaurantId, -1 * $quantityToRemove);
            }

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function physicalStockLedgerDelta(array $movement): float
    {
        $type = (string) ($movement['movement_type'] ?? '');
        $status = (string) ($movement['status'] ?? '');
        $qty = (float) ($movement['quantity'] ?? 0);
        if ($status !== 'VALIDE') {
            return 0.0;
        }

        return match ($type) {
            'ENTREE', 'RETOUR_STOCK' => $qty,
            'PERTE', 'SORTIE' => -abs($qty),
            'CORRECTION_INVENTAIRE' => $qty,
            default => 0.0,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveStockItemForFreeInput(int $restaurantId, array $payload, array $actor): int
    {
        $freeName = trim((string) ($payload['free_item_name'] ?? ''));
        if ($freeName !== '') {
            $unit = trim((string) ($payload['free_unit_name'] ?? '')) ?: 'unité';

            return $this->findOrCreateStockItemByName($restaurantId, $freeName, $unit, $actor);
        }

        $id = (int) ($payload['stock_item_id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Choisissez un article ou saisissez un nom de produit libre.');
        }
        $this->findStockItemInRestaurant($id, $restaurantId);

        return $id;
    }

    private function findOrCreateStockItemByName(int $restaurantId, string $name, string $unitName, array $actor): int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM stock_items WHERE restaurant_id = :restaurant_id AND LOWER(name) = LOWER(:name) LIMIT 1'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'name' => $name,
        ]);
        $existing = $statement->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $this->createItem($restaurantId, [
            'name' => $name,
            'unit_name' => $unitName,
            'quantity_in_stock' => 0,
            'alert_threshold' => 0,
            'estimated_unit_cost' => 0,
            'category_label' => '',
            'item_note' => 'Article créé depuis la saisie libre des mouvements.',
        ], $actor);

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function ensureStockMovementEnum(): void
    {
        $statement = $this->database->pdo()->query(
            "SHOW COLUMNS FROM stock_movements WHERE Field = 'movement_type'"
        );
        $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : false;
        $type = (string) ($row['Type'] ?? '');
        if ($type !== '' && str_contains($type, 'CONSOMMATION_CUISINE')
            && str_contains($type, 'SORTIE') && str_contains($type, 'CORRECTION_INVENTAIRE')) {
            return;
        }

        $this->database->pdo()->exec(
            "ALTER TABLE stock_movements MODIFY COLUMN movement_type
            ENUM(
                'ENTREE',
                'SORTIE_CUISINE',
                'RETOUR_STOCK',
                'PERTE',
                'CONSOMMATION_CUISINE',
                'SORTIE',
                'CORRECTION_INVENTAIRE'
            ) NOT NULL"
        );
    }

    private function insertMovement(int $restaurantId, array $payload): int
    {
        $this->ensureStockMovementEnum();
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO stock_movements
            (restaurant_id, stock_item_id, movement_type, quantity, unit_cost_snapshot, total_cost_snapshot, status, user_id, validated_by, reference_type, reference_id, note, created_at, validated_at)
             VALUES
            (:restaurant_id, :stock_item_id, :movement_type, :quantity, :unit_cost_snapshot, :total_cost_snapshot, :status, :user_id, :validated_by, :reference_type, :reference_id, :note, NOW(),
             CASE WHEN :status = "VALIDE" THEN NOW() ELSE NULL END)'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'stock_item_id' => $payload['stock_item_id'],
            'movement_type' => $payload['movement_type'],
            'quantity' => $payload['quantity'],
            'unit_cost_snapshot' => $payload['unit_cost_snapshot'] ?? 0,
            'total_cost_snapshot' => $payload['total_cost_snapshot'] ?? 0,
            'status' => $payload['status'],
            'user_id' => $payload['user_id'],
            'validated_by' => $payload['validated_by'],
            'reference_type' => $payload['reference_type'],
            'reference_id' => $payload['reference_id'],
            'note' => $payload['note'],
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function adjustStockItem(int $stockItemId, int $restaurantId, float $delta): void
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE stock_items
             SET quantity_in_stock = quantity_in_stock + :delta
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'delta' => $delta,
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function updateEstimatedUnitCost(int $stockItemId, int $restaurantId, float $unitCost): void
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE stock_items
             SET estimated_unit_cost = :estimated_unit_cost
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'estimated_unit_cost' => $unitCost,
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    private function findStockItemInRestaurant(int $stockItemId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM stock_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $stockItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);

        if ($item === false) {
            throw new \RuntimeException('Article de stock hors perimetre restaurant.');
        }

        return $item;
    }

    private function findMovementInRestaurant(int $movementId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM stock_movements
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $movementId,
            'restaurant_id' => $restaurantId,
        ]);
        $movement = $statement->fetch(PDO::FETCH_ASSOC);

        if ($movement === false) {
            throw new \RuntimeException('Mouvement de stock hors perimetre restaurant.');
        }

        return $movement;
    }

    private function findProductionInRestaurant(int $productionId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM kitchen_production
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $productionId,
            'restaurant_id' => $restaurantId,
        ]);
        $production = $statement->fetch(PDO::FETCH_ASSOC);

        if ($production === false) {
            throw new \RuntimeException('Production cuisine hors perimetre restaurant.');
        }

        return $production;
    }

    private function findSaleInRestaurant(int $saleId, int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM sales
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $saleId,
            'restaurant_id' => $restaurantId,
        ]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new \RuntimeException('Vente hors perimetre restaurant.');
        }
    }

    private function findKitchenStockRequestInRestaurant(int $requestId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM kitchen_stock_requests
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);

        if ($request === false) {
            throw new \RuntimeException('Demande cuisine vers stock introuvable.');
        }

        return $request;
    }

    private function respondKitchenStockRequestBlock(int $restaurantId, array $request, array $requestItems, array $payload, array $actor): void
    {
        $workflowStage = (string) ($payload['workflow_stage'] ?? 'FINALISER');
        if (!in_array($workflowStage, ['EN_COURS_TRAITEMENT', 'FINALISER'], true)) {
            throw new \RuntimeException('Etape de traitement stock invalide.');
        }

        if ($workflowStage === 'EN_COURS_TRAITEMENT') {
            $this->markKitchenStockRequestProcessing($restaurantId, (int) $request['id'], $requestItems, $payload, $actor);
            return;
        }

        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            throw new \RuntimeException('Aucune ligne de reponse stock fournie.');
        }

        $normalizedById = [];
        foreach ($requestItems as $requestItem) {
            $itemId = (int) $requestItem['id'];
            $line = $rawItems[$itemId] ?? null;
            if (!is_array($line)) {
                throw new \RuntimeException('Toutes les lignes de la demande doivent recevoir une reponse.');
            }

            $stockItem = $this->findStockItemInRestaurant((int) $requestItem['stock_item_id'], $restaurantId);
            $quantityRequested = (float) $requestItem['quantity_requested'];
            $quantitySupplied = max(0.0, (float) ($line['quantity_supplied'] ?? 0));
            if ($quantitySupplied > $quantityRequested) {
                throw new \RuntimeException('La quantite fournie ne peut pas depasser la demande cuisine.');
            }
            if ($quantitySupplied > (float) $stockItem['quantity_in_stock']) {
                throw new \RuntimeException('Quantite fournie superieure au stock disponible.');
            }

            $unavailableQuantity = max($quantityRequested - $quantitySupplied, 0);
            $status = match (true) {
                $quantitySupplied <= 0 => 'NON_FOURNI',
                abs($quantitySupplied - $quantityRequested) < 0.0001 => 'FOURNI_TOTAL',
                default => 'FOURNI_PARTIEL',
            };

            $normalizedById[$itemId] = [
                'quantity_supplied' => $quantitySupplied,
                'unavailable_quantity' => $unavailableQuantity,
                'status' => $status,
                'planning_status' => trim((string) ($line['planning_status'] ?? '')),
                'response_note' => trim((string) ($line['response_note'] ?? $line['note'] ?? '')),
                'note' => trim((string) ($line['note'] ?? $requestItem['note'] ?? '')),
            ];

            if ($status === 'NON_FOURNI' && $normalizedById[$itemId]['planning_status'] === '') {
                throw new \RuntimeException('Classer toute ligne non fournie en urgence ou a prevoir.');
            }
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $itemStatement = $pdo->prepare(
                'UPDATE kitchen_stock_request_items
                 SET quantity_supplied = :quantity_supplied,
                     unavailable_quantity = :unavailable_quantity,
                     status = :status,
                     planning_status = :planning_status,
                     note = :note,
                     response_note = :response_note,
                     responded_by = :responded_by,
                     responded_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );

            $totalSupplied = 0.0;
            $totalUnavailable = 0.0;
            $lineStatuses = [];

            foreach ($requestItems as $requestItem) {
                $itemId = (int) $requestItem['id'];
                $line = $normalizedById[$itemId];
                $itemStatement->execute([
                    'quantity_supplied' => $line['quantity_supplied'],
                    'unavailable_quantity' => $line['unavailable_quantity'],
                    'status' => $line['status'],
                    'planning_status' => $line['planning_status'] !== '' ? $line['planning_status'] : null,
                    'note' => $line['note'] !== '' ? $line['note'] : null,
                    'response_note' => $line['response_note'] !== '' ? $line['response_note'] : null,
                    'responded_by' => $actor['id'],
                    'id' => $itemId,
                    'restaurant_id' => $restaurantId,
                ]);
                $totalSupplied += $line['quantity_supplied'];
                $totalUnavailable += $line['unavailable_quantity'];
                $lineStatuses[] = $line['status'];
            }

            $headerStatus = $this->resolveKitchenStockHeaderStatus($lineStatuses);
            $responseNote = trim((string) ($payload['response_note'] ?? $payload['note'] ?? $request['response_note'] ?? $request['note']));
            $headerNote = trim((string) ($payload['note'] ?? $request['note']));
            $headerPlanning = trim((string) ($payload['planning_status'] ?? ''));

            $headerStatement = $pdo->prepare(
                'UPDATE kitchen_stock_requests
                 SET quantity_supplied = :quantity_supplied,
                     unavailable_quantity = :unavailable_quantity,
                     status = :status,
                     planning_status = :planning_status,
                     note = :note,
                     response_note = :response_note,
                     responded_by = :responded_by,
                     responded_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $headerStatement->execute([
                'quantity_supplied' => $totalSupplied,
                'unavailable_quantity' => $totalUnavailable,
                'status' => $headerStatus,
                'planning_status' => $headerPlanning !== '' ? $headerPlanning : null,
                'note' => $headerNote !== '' ? $headerNote : null,
                'response_note' => $responseNote !== '' ? $responseNote : null,
                'responded_by' => $actor['id'],
                'id' => (int) $request['id'],
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_responded',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $request['id'],
            'new_values' => [
                'items' => $normalizedById,
                'status' => $this->resolveKitchenStockHeaderStatus(array_column($normalizedById, 'status')),
            ],
            'justification' => 'Reponse stock globale a la demande cuisine multi-produits',
        ]);
    }

    private function confirmKitchenStockReceiptBlock(int $restaurantId, array $request, array $requestItems, array $actor): void
    {
        if ((int) $request['requested_by'] !== (int) $actor['id'] && ($actor['role_code'] ?? null) !== 'manager') {
            throw new \RuntimeException('Cette reception ne peut pas etre confirmee par cet utilisateur.');
        }

        foreach ($requestItems as $requestItem) {
            if (!in_array((string) ($requestItem['status'] ?? ''), ['FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI'], true)) {
                throw new \RuntimeException('La demande stock n est pas encore prete a etre receptionnee.');
            }
        }

        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $itemStatement = $pdo->prepare(
                'UPDATE kitchen_stock_request_items
                 SET received_by = :received_by,
                     received_at = NOW(),
                     updated_at = NOW()
                 WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
            );
            $itemStatement->execute([
                'received_by' => $actor['id'],
                'request_id' => (int) $request['id'],
                'restaurant_id' => $restaurantId,
            ]);

            $headerStatement = $pdo->prepare(
                'UPDATE kitchen_stock_requests
                 SET status = "CLOTURE",
                     received_by = :received_by,
                     received_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $headerStatement->execute([
                'received_by' => $actor['id'],
                'id' => (int) $request['id'],
                'restaurant_id' => $restaurantId,
            ]);

            foreach ($requestItems as $requestItem) {
                if ((float) ($requestItem['quantity_supplied'] ?? 0) > 0) {
                    $this->increaseKitchenInventory($restaurantId, (int) $requestItem['stock_item_id'], (float) $requestItem['quantity_supplied']);
                }
            }

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_received',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $request['id'],
            'new_values' => ['status' => 'CLOTURE', 'item_count' => count($requestItems)],
            'justification' => 'Reception globale du stock confirmee par la cuisine',
        ]);
    }

    private function markKitchenStockRequestProcessing(int $restaurantId, int $requestId, array $requestItems, array $payload, array $actor): void
    {
        $planningStatus = trim((string) ($payload['planning_status'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $itemStatement = $pdo->prepare(
                'UPDATE kitchen_stock_request_items
                 SET status = "EN_COURS_TRAITEMENT",
                     planning_status = COALESCE(:planning_status, planning_status),
                     note = COALESCE(:note, note),
                     responded_by = :responded_by,
                     responded_at = COALESCE(responded_at, NOW()),
                     updated_at = NOW()
                 WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
            );
            $itemStatement->execute([
                'planning_status' => $planningStatus !== '' ? $planningStatus : null,
                'note' => $note !== '' ? $note : null,
                'responded_by' => $actor['id'],
                'request_id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $headerStatement = $pdo->prepare(
                'UPDATE kitchen_stock_requests
                 SET status = "EN_COURS_TRAITEMENT",
                     planning_status = COALESCE(:planning_status, planning_status),
                     note = COALESCE(:note, note),
                     responded_by = :responded_by,
                     responded_at = COALESCE(responded_at, NOW()),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $headerStatement->execute([
                'planning_status' => $planningStatus !== '' ? $planningStatus : null,
                'note' => $note !== '' ? $note : null,
                'responded_by' => $actor['id'],
                'id' => $requestId,
                'restaurant_id' => $restaurantId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_processing_started',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $requestId,
            'new_values' => ['workflow_stage' => 'EN_COURS_TRAITEMENT', 'item_count' => count($requestItems)],
            'justification' => 'Demande cuisine multi-produits prise en charge par le stock',
        ]);
    }

    private function normalizeKitchenStockRequestItems(int $restaurantId, array $payload): array
    {
        $rawItems = $payload['items'] ?? [];
        $normalized = [];

        if (is_array($rawItems) && $rawItems !== []) {
            foreach ($rawItems as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $stockItemId = (int) ($line['stock_item_id'] ?? 0);
                $quantityRequested = (float) ($line['quantity_requested'] ?? 0);
                if ($stockItemId <= 0 || $quantityRequested <= 0) {
                    continue;
                }

                $item = $this->findStockItemInRestaurant($stockItemId, $restaurantId);
                $normalized[] = [
                    'stock_item_id' => (int) $item['id'],
                    'stock_item_name' => (string) $item['name'],
                    'unit_name' => (string) $item['unit_name'],
                    'quantity_requested' => $quantityRequested,
                    'priority_level' => (($line['priority_level'] ?? 'normale') === 'urgente') ? 'urgente' : 'normale',
                    'note' => trim((string) ($line['note'] ?? '')),
                ];
            }
        }

        if ($normalized !== []) {
            return $normalized;
        }

        $item = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
        $quantityRequested = (float) ($payload['quantity_requested'] ?? 0);
        if ($quantityRequested <= 0) {
            throw new \RuntimeException('Quantite demandee au stock obligatoire.');
        }

        return [[
            'stock_item_id' => (int) $item['id'],
            'stock_item_name' => (string) $item['name'],
            'unit_name' => (string) $item['unit_name'],
            'quantity_requested' => $quantityRequested,
            'priority_level' => (($payload['priority_level'] ?? 'normale') === 'urgente') ? 'urgente' : 'normale',
            'note' => trim((string) ($payload['line_note'] ?? $payload['note'] ?? '')),
        ]];
    }

    private function resolveKitchenStockPriority(array $items, string $fallback): string
    {
        foreach ($items as $item) {
            if (($item['priority_level'] ?? 'normale') === 'urgente') {
                return 'urgente';
            }
        }

        return $fallback === 'urgente' ? 'urgente' : 'normale';
    }

    private function kitchenStockRequestItemsByRequest(int $restaurantId, array $requestIds): array
    {
        $this->ensureKitchenStockRequestItemsTable();
        if ($requestIds === []) {
            return [];
        }

        $idList = implode(',', array_map('intval', $requestIds));
        $statement = $this->database->pdo()->query(
            'SELECT ksri.*,
                    si.name AS stock_item_name,
                    si.unit_name,
                    rp.full_name AS responded_by_name,
                    ru.full_name AS received_by_name
             FROM kitchen_stock_request_items ksri
             INNER JOIN stock_items si ON si.id = ksri.stock_item_id
             LEFT JOIN users rp ON rp.id = ksri.responded_by
             LEFT JOIN users ru ON ru.id = ksri.received_by
             WHERE ksri.restaurant_id = ' . (int) $restaurantId . '
               AND ksri.request_id IN (' . $idList . ')
             ORDER BY ksri.id ASC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['request_id']][] = $row;
        }

        return $grouped;
    }

    private function kitchenStockRequestItemsForRequest(int $restaurantId, int $requestId): array
    {
        $grouped = $this->kitchenStockRequestItemsByRequest($restaurantId, [$requestId]);
        return $grouped[$requestId] ?? [];
    }

    private function legacyKitchenStockRequestAsItem(array $request): array
    {
        return [
            'id' => 0,
            'request_id' => (int) $request['id'],
            'stock_item_id' => (int) $request['stock_item_id'],
            'stock_item_name' => (string) ($request['stock_item_name'] ?? 'Article'),
            'unit_name' => (string) ($request['unit_name'] ?? ''),
            'quantity_requested' => (float) ($request['quantity_requested'] ?? 0),
            'quantity_supplied' => (float) ($request['quantity_supplied'] ?? 0),
            'unavailable_quantity' => (float) ($request['unavailable_quantity'] ?? 0),
            'priority_level' => (string) ($request['priority_level'] ?? 'normale'),
            'planning_status' => (string) ($request['planning_status'] ?? ''),
            'note' => (string) ($request['note'] ?? ''),
            'response_note' => (string) ($request['response_note'] ?? ''),
            'status' => (string) ($request['status'] ?? 'DEMANDE'),
            'responded_by_name' => (string) ($request['responded_by_name'] ?? ''),
            'received_by_name' => (string) ($request['received_by_name'] ?? ''),
            'responded_at' => $request['responded_at'] ?? null,
            'received_at' => $request['received_at'] ?? null,
        ];
    }

    private function resolveKitchenStockRequestStatus(array $items, string $fallback): string
    {
        if ($items === []) {
            return $fallback;
        }

        $statuses = array_values(array_unique(array_map(static fn (array $item): string => (string) ($item['status'] ?? 'DEMANDE'), $items)));
        if ($statuses === ['CLOTURE']) {
            return 'CLOTURE';
        }
        if (count(array_diff($statuses, ['FOURNI_TOTAL'])) === 0) {
            return 'FOURNI_TOTAL';
        }
        if (count(array_diff($statuses, ['NON_FOURNI'])) === 0) {
            return 'NON_FOURNI';
        }
        if (in_array('EN_COURS_TRAITEMENT', $statuses, true)) {
            return 'EN_COURS_TRAITEMENT';
        }
        if (in_array('FOURNI_PARTIEL', $statuses, true) || in_array('FOURNI_TOTAL', $statuses, true) || in_array('NON_FOURNI', $statuses, true)) {
            return 'FOURNI_PARTIEL';
        }

        return $fallback;
    }

    private function resolveKitchenStockHeaderStatus(array $lineStatuses): string
    {
        if ($lineStatuses === []) {
            return 'DEMANDE';
        }
        if (count(array_diff($lineStatuses, ['FOURNI_TOTAL'])) === 0) {
            return 'FOURNI_TOTAL';
        }
        if (count(array_diff($lineStatuses, ['NON_FOURNI'])) === 0) {
            return 'NON_FOURNI';
        }

        return 'FOURNI_PARTIEL';
    }

    private function increaseKitchenInventory(int $restaurantId, int $stockItemId, float $quantity): void
    {
        $this->ensureKitchenInventoryTables();
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO kitchen_inventory (restaurant_id, stock_item_id, quantity_available, created_at, updated_at)
             VALUES (:restaurant_id, :stock_item_id, :quantity_available, NOW(), NOW())
             ON DUPLICATE KEY UPDATE quantity_available = quantity_available + VALUES(quantity_available), updated_at = NOW()'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'stock_item_id' => $stockItemId,
            'quantity_available' => $quantity,
        ]);
    }

    private function findKitchenInventoryItem(int $restaurantId, int $stockItemId): ?array
    {
        $this->ensureKitchenInventoryTables();
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM kitchen_inventory
             WHERE restaurant_id = :restaurant_id AND stock_item_id = :stock_item_id
             LIMIT 1'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'stock_item_id' => $stockItemId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function ensureKitchenStockRequestItemsTable(): void
    {
        if ($this->tableExists('kitchen_stock_request_items')) {
            return;
        }

        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS kitchen_stock_request_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id BIGINT UNSIGNED NOT NULL,
                restaurant_id BIGINT UNSIGNED NOT NULL,
                stock_item_id BIGINT UNSIGNED NOT NULL,
                quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_supplied DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                unavailable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(80) NOT NULL DEFAULT "DEMANDE",
                priority_level VARCHAR(40) NOT NULL DEFAULT "normale",
                planning_status VARCHAR(40) NULL,
                note TEXT NULL,
                response_note TEXT NULL,
                responded_by BIGINT UNSIGNED NULL,
                received_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                responded_at DATETIME NULL,
                received_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_kitchen_stock_request_items_request (request_id),
                INDEX idx_kitchen_stock_request_items_restaurant_date (restaurant_id, created_at),
                CONSTRAINT fk_kitchen_stock_request_items_request FOREIGN KEY (request_id) REFERENCES kitchen_stock_requests(id),
                CONSTRAINT fk_kitchen_stock_request_items_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
                CONSTRAINT fk_kitchen_stock_request_items_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
                CONSTRAINT fk_kitchen_stock_request_items_responded_by FOREIGN KEY (responded_by) REFERENCES users(id),
                CONSTRAINT fk_kitchen_stock_request_items_received_by FOREIGN KEY (received_by) REFERENCES users(id)
            )'
        );
    }

    private function ensureKitchenInventoryTables(): void
    {
        if (!$this->tableExists('kitchen_inventory')) {
            $this->database->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS kitchen_inventory (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    restaurant_id BIGINT UNSIGNED NOT NULL,
                    stock_item_id BIGINT UNSIGNED NOT NULL,
                    quantity_available DECIMAL(12,3) NOT NULL DEFAULT 0.000,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_kitchen_inventory_restaurant_item (restaurant_id, stock_item_id),
                    INDEX idx_kitchen_inventory_restaurant (restaurant_id),
                    INDEX idx_kitchen_inventory_stock_item (stock_item_id)
                )'
            );
        }

        if (!$this->tableExists('kitchen_production_materials')) {
            $this->database->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS kitchen_production_materials (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    kitchen_production_id BIGINT UNSIGNED NOT NULL,
                    restaurant_id BIGINT UNSIGNED NOT NULL,
                    stock_item_id BIGINT UNSIGNED NOT NULL,
                    quantity_used DECIMAL(12,3) NOT NULL DEFAULT 0.000,
                    note TEXT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_kitchen_production_materials_production (kitchen_production_id),
                    INDEX idx_kitchen_production_materials_restaurant (restaurant_id),
                    INDEX idx_kitchen_production_materials_stock_item (stock_item_id)
                )'
            );
        }
    }

    private function stockItemOptionalColumns(): array
    {
        return [
            'category_label' => $this->tableColumnExists('stock_items', 'category_label'),
            'item_note' => $this->tableColumnExists('stock_items', 'item_note'),
            'updated_at' => $this->tableColumnExists('stock_items', 'updated_at'),
        ];
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute([
            'table_name' => $table,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function decodeAuditJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
