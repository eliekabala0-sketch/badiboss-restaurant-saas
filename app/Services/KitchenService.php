<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class KitchenService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listProductions(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT kp.*, mi.name AS menu_item_name, mi.image_url AS menu_item_image_url, si.name AS stock_item_name, si.unit_name AS stock_unit_name, u.full_name AS created_by_name
             FROM kitchen_production kp
             LEFT JOIN stock_movements sm ON sm.id = kp.stock_movement_id
             LEFT JOIN stock_items si ON si.id = sm.stock_item_id
             LEFT JOIN menu_items mi ON mi.id = kp.menu_item_id
             INNER JOIN users u ON u.id = kp.created_by
             WHERE kp.restaurant_id = :restaurant_id
             ORDER BY kp.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createProduction(int $restaurantId, array $payload, array $actor): void
    {
        if (
            ($payload['menu_item_id'] ?? '') === ''
            && !empty($payload['publish_to_menu'])
            && (int) ($payload['menu_category_id'] ?? 0) > 0
        ) {
            $payload['menu_item_id'] = (string) $this->createMenuItemFromProduction($restaurantId, $payload, $actor);
        }

        $menuItem = null;
        if ($payload['menu_item_id'] !== '') {
            $this->assertMenuItemBelongsToRestaurant((int) $payload['menu_item_id'], $restaurantId);
            $menuItem = $this->findMenuItemInRestaurant((int) $payload['menu_item_id'], $restaurantId);
        }

        $stockService = Container::getInstance()->get('stockService');
        $materials = $this->normalizeProductionMaterials($payload['materials'] ?? []);
        if ($materials !== []) {
            $consumption = $stockService->consumeKitchenMaterials($restaurantId, $materials, $actor, $payload['menu_item_id'] !== '' ? (int) $payload['menu_item_id'] : null);
            $movementId = (int) ($consumption['movement_ids'][0] ?? 0);
            $movement = $movementId > 0 ? $this->findStockMovementInRestaurant($movementId, $restaurantId) : [
                'total_cost_snapshot' => 0,
            ];
        } else {
            $movementId = $stockService->sendToKitchen($restaurantId, $payload, $actor);
            $movement = $this->findStockMovementInRestaurant($movementId, $restaurantId);
            $consumption = ['materials' => []];
        }
        $quantityProduced = (float) $payload['quantity_produced'];
        if ($quantityProduced <= 0) {
            throw new \RuntimeException('Quantite produite obligatoire.');
        }
        $totalRealCost = (float) ($movement['total_cost_snapshot'] ?? 0);
        $unitRealCost = $quantityProduced > 0 ? $totalRealCost / $quantityProduced : 0.0;

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO kitchen_production
            (restaurant_id, stock_movement_id, menu_item_id, dish_type, quantity_produced, quantity_remaining, unit_real_cost_snapshot, total_real_cost_snapshot, unit_sale_value_snapshot, total_sale_value_snapshot, status, created_by, created_at)
             VALUES
            (:restaurant_id, :stock_movement_id, :menu_item_id, :dish_type, :quantity_produced, :quantity_remaining, :unit_real_cost_snapshot, :total_real_cost_snapshot, :unit_sale_value_snapshot, :total_sale_value_snapshot, "EN_COURS", :created_by, NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'stock_movement_id' => $movementId,
            'menu_item_id' => $payload['menu_item_id'] !== '' ? (int) $payload['menu_item_id'] : null,
            'dish_type' => trim((string) $payload['dish_type']),
            'quantity_produced' => $quantityProduced,
            'quantity_remaining' => $quantityProduced,
            'unit_real_cost_snapshot' => $unitRealCost,
            'total_real_cost_snapshot' => $totalRealCost,
            'unit_sale_value_snapshot' => (float) ($menuItem['price'] ?? 0),
            'total_sale_value_snapshot' => $quantityProduced * (float) ($menuItem['price'] ?? 0),
            'created_by' => $actor['id'],
        ]);

        $productionId = (int) $this->database->pdo()->lastInsertId();
        $linkStatement = $this->database->pdo()->prepare(
            'UPDATE stock_movements SET reference_id = :reference_id WHERE id = :id'
        );
        $linkStatement->execute([
            'reference_id' => $productionId,
            'id' => $movementId,
        ]);

        if (($consumption['materials'] ?? []) !== []) {
            $materialStatement = $this->database->pdo()->prepare(
                'INSERT INTO kitchen_production_materials
                (kitchen_production_id, restaurant_id, stock_item_id, quantity_used, note, created_at)
                 VALUES
                (:kitchen_production_id, :restaurant_id, :stock_item_id, :quantity_used, :note, NOW())'
            );
            foreach ($consumption['materials'] as $material) {
                $materialStatement->execute([
                    'kitchen_production_id' => $productionId,
                    'restaurant_id' => $restaurantId,
                    'stock_item_id' => (int) $material['stock_item_id'],
                    'quantity_used' => (float) $material['quantity_used'],
                    'note' => trim((string) ($material['note'] ?? '')) ?: null,
                ]);
            }
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'kitchen',
            'action_name' => 'kitchen_production_created',
            'entity_type' => 'kitchen_production',
            'entity_id' => (string) $productionId,
            'new_values' => $payload,
            'justification' => 'Transformation cuisine',
        ]);
    }

    public function listPendingServerRequestItems(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sri.*,
                    sr.server_id,
                    sr.requested_by,
                    sr.technical_confirmed_by AS request_technical_confirmed_by,
                    sr.ready_by,
                    sr.status AS request_status,
                    sr.note AS request_note,
                    sr.service_reference,
                    sr.created_at AS request_created_at,
                    sr.ready_at AS request_ready_at,
                    sr.received_at AS request_received_at,
                    u.full_name AS server_name,
                    mi.name AS menu_item_name,
                    mi.image_url AS menu_item_image_url,
                    mc.name AS menu_category_name,
                    requested_user.full_name AS requested_by_name,
                    prepared_user.full_name AS prepared_by_name,
                    ready_user.full_name AS ready_by_name,
                    received_user.full_name AS received_by_name
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             INNER JOIN users u ON u.id = sr.server_id
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             LEFT JOIN menu_categories mc ON mc.id = mi.category_id
             LEFT JOIN users requested_user ON requested_user.id = sr.requested_by
             LEFT JOIN users prepared_user ON prepared_user.id = sri.technical_confirmed_by
             LEFT JOIN users ready_user ON ready_user.id = sr.ready_by
             LEFT JOIN users received_user ON received_user.id = sri.received_by
             WHERE sr.restaurant_id = :restaurant_id
               AND sr.status IN ("DEMANDE", "EN_PREPARATION", "PRET_A_SERVIR", "FOURNI_PARTIEL", "FOURNI_TOTAL")
             ORDER BY COALESCE(sr.created_at, sri.created_at) DESC, sri.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fulfillServerRequestItem(int $restaurantId, int $requestItemId, array $payload, array $actor): void
    {
        $item = $this->findServerRequestItemInRestaurant($requestItemId, $restaurantId);
        $suppliedQuantity = (float) ($payload['supplied_quantity'] ?? 0);
        $workflowStage = (string) ($payload['workflow_stage'] ?? 'PRET_A_SERVIR');

        if ($suppliedQuantity < 0) {
            throw new \RuntimeException('Quantite fournie invalide.');
        }
        if ($suppliedQuantity > (float) $item['requested_quantity']) {
            throw new \RuntimeException('Quantite fournie superieure a la demande.');
        }
        if (!in_array($workflowStage, ['EN_PREPARATION', 'PRET_A_SERVIR'], true)) {
            throw new \RuntimeException('Etape de preparation invalide.');
        }

        $requestedQuantity = (float) $item['requested_quantity'];
        $isBeverage = mb_strtolower(trim((string) ($item['menu_category_name'] ?? ''))) === 'boisson';
        if (!$isBeverage && $workflowStage === 'PRET_A_SERVIR') {
            $this->reservePreparedQuantity((int) $item['menu_item_id'], $restaurantId, $suppliedQuantity);
        }
        $unavailableQuantity = max($requestedQuantity - $suppliedQuantity, 0);
        $suppliedTotal = $suppliedQuantity * (float) $item['unit_price'];
        $itemStatus = match (true) {
            $suppliedQuantity <= 0 && $workflowStage === 'PRET_A_SERVIR' => 'NON_FOURNI',
            default => $workflowStage,
        };
        $supplyStatus = match (true) {
            $suppliedQuantity <= 0 => 'NON_FOURNI',
            abs($suppliedQuantity - $requestedQuantity) < 0.0001 => $workflowStage === 'PRET_A_SERVIR' ? 'FOURNI_TOTAL' : 'EN_PREPARATION',
            default => $workflowStage === 'PRET_A_SERVIR' ? 'FOURNI_PARTIEL' : 'EN_PREPARATION',
        };

        $statement = $this->database->pdo()->prepare(
            'UPDATE server_request_items
             SET supplied_quantity = :supplied_quantity,
                 unavailable_quantity = :unavailable_quantity,
                 supplied_total = :supplied_total,
                 total_supplied_amount = :total_supplied_amount,
                 technical_confirmed_by = :technical_confirmed_by,
                 prepared_at = CASE WHEN :workflow_stage = "PRET_A_SERVIR" THEN NOW() ELSE prepared_at END,
                 status = :status,
                 supply_status = :supply_status,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'supplied_quantity' => $suppliedQuantity,
            'unavailable_quantity' => $unavailableQuantity,
            'supplied_total' => $suppliedTotal,
            'total_supplied_amount' => $suppliedTotal,
            'technical_confirmed_by' => $actor['id'],
            'workflow_stage' => $workflowStage,
            'status' => $itemStatus,
            'supply_status' => $supplyStatus,
            'id' => $requestItemId,
        ]);

        $this->refreshServerRequestTotals((int) $item['request_id'], (int) $actor['id']);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'kitchen',
            'action_name' => 'server_request_item_fulfilled',
            'entity_type' => 'server_request_items',
            'entity_id' => (string) $requestItemId,
            'new_values' => [
                'workflow_stage' => $workflowStage,
                'supplied_quantity' => $suppliedQuantity,
                'unavailable_quantity' => $unavailableQuantity,
            ],
            'justification' => $workflowStage === 'PRET_A_SERVIR'
                ? 'Demande marquee prete a servir par la cuisine'
                : 'Demande prise en preparation par la cuisine',
        ]);
    }

    public function validateReturnRequest(int $restaurantId, array $payload, array $actor): void
    {
        $saleItem = $this->findKitchenSaleItem((int) $payload['sale_item_id'], $restaurantId);
        if ($saleItem === null) {
            throw new \RuntimeException('Article de vente cuisine introuvable.');
        }

        if ($saleItem['status'] === 'RETOUR') {
            throw new \RuntimeException('Ce retour a deja ete confirme.');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE sale_items
             SET return_reason = :return_reason,
                 return_validated_by_kitchen = :validated_by,
                 returned_at = COALESCE(returned_at, NOW())
             WHERE id = :id'
        );
        $statement->execute([
            'return_reason' => trim((string) $payload['return_reason']),
            'validated_by' => $actor['id'],
            'id' => (int) $payload['sale_item_id'],
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'kitchen',
            'action_name' => 'sale_return_validated_by_kitchen',
            'entity_type' => 'sale_items',
            'entity_id' => (string) $payload['sale_item_id'],
            'new_values' => $payload,
            'justification' => 'Validation cuisine du retour',
        ]);
    }

    private function createMenuItemFromProduction(int $restaurantId, array $payload, array $actor): int
    {
        $categoryId = (int) ($payload['menu_category_id'] ?? 0);
        $categoryStatement = $this->database->pdo()->prepare(
            'SELECT id
             FROM menu_categories
             WHERE id = :id
               AND restaurant_id = :restaurant_id
               AND status = "active"
             LIMIT 1'
        );
        $categoryStatement->execute([
            'id' => $categoryId,
            'restaurant_id' => $restaurantId,
        ]);

        if ($categoryStatement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new \RuntimeException('Categorie de menu invalide pour publier ce plat.');
        }

        $dishType = trim((string) ($payload['dish_type'] ?? ''));
        if ($dishType === '') {
            throw new \RuntimeException('Nom du plat obligatoire pour publier au menu.');
        }

        $existingStatement = $this->database->pdo()->prepare(
            'SELECT id
             FROM menu_items
             WHERE restaurant_id = :restaurant_id
               AND category_id = :category_id
               AND name = :name
               AND status = "active"
               AND is_available = 1
             ORDER BY id DESC
             LIMIT 1'
        );
        $existingStatement->execute([
            'restaurant_id' => $restaurantId,
            'category_id' => $categoryId,
            'name' => $dishType,
        ]);
        $existingItemId = $existingStatement->fetchColumn();
        if ($existingItemId !== false) {
            return (int) $existingItemId;
        }

        $slug = $this->slugify($dishType);
        $slugStatement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM menu_items WHERE restaurant_id = :restaurant_id AND slug = :slug'
        );
        $slugStatement->execute([
            'restaurant_id' => $restaurantId,
            'slug' => $slug,
        ]);

        if ((int) $slugStatement->fetchColumn() > 0) {
            $slug .= '-' . date('His');
        }

        Container::getInstance()->get('menuAdmin')->createItem($restaurantId, [
            'category_id' => $categoryId,
            'name' => $dishType,
            'slug' => $slug,
            'description' => (string) ($payload['menu_description'] ?? $payload['note'] ?? ''),
            'image_url' => '',
            'price' => (string) ($payload['menu_price'] ?? '0'),
            'status' => 'active',
            'is_available' => 1,
            'display_order' => 0,
            'available_dine_in' => 1,
            'available_takeaway' => 1,
            'available_delivery' => 1,
        ], $actor);

        $itemStatement = $this->database->pdo()->prepare(
            'SELECT id
             FROM menu_items
             WHERE restaurant_id = :restaurant_id
               AND slug = :slug
             ORDER BY id DESC
             LIMIT 1'
        );
        $itemStatement->execute([
            'restaurant_id' => $restaurantId,
            'slug' => $slug,
        ]);
        $itemId = $itemStatement->fetchColumn();

        if ($itemId === false) {
            throw new \RuntimeException('Creation du plat menu impossible depuis la cuisine.');
        }

        return (int) $itemId;
    }

    private function assertMenuItemBelongsToRestaurant(int $menuItemId, int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM menu_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $menuItemId,
            'restaurant_id' => $restaurantId,
        ]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }
    }

    private function findKitchenSaleItem(int $saleItemId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT si.*
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             WHERE si.id = :id
               AND s.restaurant_id = :restaurant_id
               AND si.kitchen_production_id IS NOT NULL
             LIMIT 1'
        );
        $statement->execute([
            'id' => $saleItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findMenuItemInRestaurant(int $menuItemId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM menu_items
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $menuItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }

        return $row;
    }

    private function reservePreparedQuantity(int $menuItemId, int $restaurantId, float $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT id, quantity_remaining
             FROM kitchen_production
             WHERE restaurant_id = :restaurant_id
               AND menu_item_id = :menu_item_id
               AND quantity_remaining > 0
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'menu_item_id' => $menuItemId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $remaining = $quantity;
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) ($row['quantity_remaining'] ?? 0);
            if ($available <= 0) {
                continue;
            }

            $deduct = min($available, $remaining);
            $update = $this->database->pdo()->prepare(
                'UPDATE kitchen_production
                 SET quantity_remaining = GREATEST(quantity_remaining - :quantity, 0),
                     status = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN "TERMINE" ELSE status END,
                     closed_at = CASE WHEN GREATEST(quantity_remaining - :quantity, 0) = 0 THEN NOW() ELSE closed_at END
                 WHERE id = :id AND restaurant_id = :restaurant_id'
            );
            $update->execute([
                'quantity' => $deduct,
                'id' => (int) $row['id'],
                'restaurant_id' => $restaurantId,
            ]);
            $remaining -= $deduct;
        }

        if ($remaining > 0.0001) {
            throw new \RuntimeException('Plat non prepare ou quantite insuffisante.');
        }
    }

    private function normalizeProductionMaterials(array $rawMaterials): array
    {
        $normalized = [];
        foreach ($rawMaterials as $material) {
            if (!is_array($material)) {
                continue;
            }

            $stockItemId = (int) ($material['stock_item_id'] ?? 0);
            $quantityUsed = (float) ($material['quantity_used'] ?? 0);
            if ($stockItemId <= 0 || $quantityUsed <= 0) {
                continue;
            }

            $normalized[] = [
                'stock_item_id' => $stockItemId,
                'quantity_used' => $quantityUsed,
                'note' => trim((string) ($material['note'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function findStockMovementInRestaurant(int $movementId, int $restaurantId): array
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
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Mouvement stock introuvable pour la production.');
        }

        return $row;
    }

    private function findServerRequestItemInRestaurant(int $requestItemId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT sri.*, sr.restaurant_id
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sri.id = :id
               AND sr.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $requestItemId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Ligne de demande serveur introuvable.');
        }

        return $row;
    }

    private function refreshServerRequestTotals(int $requestId, int $actorId): void
    {
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(supplied_total), 0) AS total_supplied,
                    COUNT(*) AS total_items,
                    SUM(CASE WHEN status = "DEMANDE" THEN 1 ELSE 0 END) AS waiting_items,
                    SUM(CASE WHEN status = "EN_PREPARATION" THEN 1 ELSE 0 END) AS preparing_items,
                    SUM(CASE WHEN status = "PRET_A_SERVIR" THEN 1 ELSE 0 END) AS ready_items,
                    SUM(CASE WHEN status = "REMIS_SERVEUR" THEN 1 ELSE 0 END) AS delivered_items,
                    SUM(CASE WHEN status = "CLOTURE" THEN 1 ELSE 0 END) AS closed_items,
                    SUM(CASE WHEN status = "NON_FOURNI" THEN 1 ELSE 0 END) AS unavailable_items
             FROM server_request_items
             WHERE request_id = :request_id'
        );
        $statement->execute(['request_id' => $requestId]);
        $totals = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $status = 'DEMANDE';
        $totalItems = (int) ($totals['total_items'] ?? 0);
        $waitingItems = (int) ($totals['waiting_items'] ?? 0);
        $preparingItems = (int) ($totals['preparing_items'] ?? 0);
        $readyItems = (int) ($totals['ready_items'] ?? 0);
        $deliveredItems = (int) ($totals['delivered_items'] ?? 0);
        $closedItems = (int) ($totals['closed_items'] ?? 0);
        $unavailableItems = (int) ($totals['unavailable_items'] ?? 0);

        if ($totalItems > 0 && $closedItems === $totalItems) {
            $status = 'CLOTURE';
        } elseif ($totalItems > 0 && ($deliveredItems + $unavailableItems) === $totalItems && $deliveredItems > 0) {
            $status = 'REMIS_SERVEUR';
        } elseif ($totalItems > 0 && ($readyItems + $unavailableItems) === $totalItems && $readyItems > 0) {
            $status = 'PRET_A_SERVIR';
        } elseif ($preparingItems > 0 || $readyItems > 0 || $deliveredItems > 0) {
            $status = 'EN_PREPARATION';
        }

        $update = $pdo->prepare(
            'UPDATE server_requests
             SET status = :status,
                 technical_confirmed_by = CASE WHEN :status IN ("EN_PREPARATION", "PRET_A_SERVIR", "REMIS_SERVEUR") THEN COALESCE(technical_confirmed_by, :technical_confirmed_by) ELSE technical_confirmed_by END,
                 ready_by = CASE WHEN :status = "PRET_A_SERVIR" THEN :technical_confirmed_by ELSE ready_by END,
                 requested_by = COALESCE(requested_by, server_id),
                 total_supplied_amount = :total_supplied_amount,
                 updated_at = NOW(),
                 supplied_at = CASE WHEN :status IN ("EN_PREPARATION", "PRET_A_SERVIR", "REMIS_SERVEUR") THEN COALESCE(supplied_at, NOW()) ELSE supplied_at END,
                 ready_at = CASE WHEN :status = "PRET_A_SERVIR" THEN NOW() ELSE ready_at END
             WHERE id = :id'
        );
        $update->execute([
            'status' => $status,
            'technical_confirmed_by' => $actorId,
            'total_supplied_amount' => (float) ($totals['total_supplied'] ?? 0),
            'id' => $requestId,
        ]);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'plat-cuisine';
    }
}
