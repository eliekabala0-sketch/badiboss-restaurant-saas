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

    public function createItem(int $restaurantId, array $payload, array $actor): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO stock_items (restaurant_id, name, unit_name, quantity_in_stock, alert_threshold, estimated_unit_cost, created_at)
             VALUES (:restaurant_id, :name, :unit_name, :quantity_in_stock, :alert_threshold, :estimated_unit_cost, NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'name' => trim((string) $payload['name']),
            'unit_name' => trim((string) $payload['unit_name']),
            'quantity_in_stock' => (float) $payload['quantity_in_stock'],
            'alert_threshold' => (float) $payload['alert_threshold'],
            'estimated_unit_cost' => (float) ($payload['estimated_unit_cost'] ?? 0),
        ]);

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

    public function createKitchenStockRequest(int $restaurantId, array $payload, array $actor): void
    {
        $item = $this->findStockItemInRestaurant((int) $payload['stock_item_id'], $restaurantId);
        $quantityRequested = (float) $payload['quantity_requested'];
        if ($quantityRequested <= 0) {
            throw new \RuntimeException('Quantite demandee au stock obligatoire.');
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO kitchen_stock_requests
            (restaurant_id, requested_by, stock_item_id, quantity_requested, quantity_supplied, unavailable_quantity, status, priority_level, planning_status, note, response_note, responded_by, received_by, created_at, responded_at, received_at, updated_at)
             VALUES
            (:restaurant_id, :requested_by, :stock_item_id, :quantity_requested, 0, :quantity_requested, "DEMANDE", :priority_level, NULL, :note, NULL, NULL, NULL, NOW(), NULL, NULL, NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'requested_by' => $actor['id'],
            'stock_item_id' => $item['id'],
            'quantity_requested' => $quantityRequested,
            'priority_level' => ($payload['priority_level'] ?? 'normale') === 'urgente' ? 'urgente' : 'normale',
            'note' => trim((string) ($payload['note'] ?? 'Demande cuisine vers stock.')),
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'kitchen_stock_request_created',
            'entity_type' => 'kitchen_stock_requests',
            'entity_id' => (string) $this->database->pdo()->lastInsertId(),
            'new_values' => [
                'stock_item_id' => $item['id'],
                'stock_item_name' => $item['name'],
                'quantity_requested' => $quantityRequested,
                'note' => $payload['note'] ?? null,
            ],
            'justification' => 'Demande cuisine vers stock',
        ]);
    }

    public function respondKitchenStockRequest(int $restaurantId, int $requestId, array $payload, array $actor): void
    {
        $request = $this->findKitchenStockRequestInRestaurant($requestId, $restaurantId);
        $item = $this->findStockItemInRestaurant((int) $request['stock_item_id'], $restaurantId);
        $quantitySupplied = max(0.0, (float) ($payload['quantity_supplied'] ?? 0));
        $workflowStage = (string) ($payload['workflow_stage'] ?? 'FINALISER');
        $status = (string) ($payload['status'] ?? 'FOURNI_TOTAL');
        $planningStatus = (string) ($payload['planning_status'] ?? '');

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
        $responseNote = trim((string) ($payload['response_note'] ?? $payload['note'] ?? $request['response_note'] ?? $request['note']));
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

    private function insertMovement(int $restaurantId, array $payload): int
    {
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
}
