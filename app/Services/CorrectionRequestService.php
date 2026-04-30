<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class CorrectionRequestService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listPendingForRestaurant(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT cr.*,
                    requester.full_name AS requested_by_name,
                    reviewer.full_name AS reviewed_by_name
             FROM correction_requests cr
             INNER JOIN users requester ON requester.id = cr.requested_by
             LEFT JOIN users reviewer ON reviewer.id = cr.reviewed_by
             WHERE cr.restaurant_id = :restaurant_id
               AND cr.status = "PENDING"
             ORDER BY cr.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $this->decodeRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listRecentForRestaurant(int $restaurantId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $this->database->pdo()->prepare(
            'SELECT cr.*,
                    requester.full_name AS requested_by_name,
                    reviewer.full_name AS reviewed_by_name
             FROM correction_requests cr
             INNER JOIN users requester ON requester.id = cr.requested_by
             LEFT JOIN users reviewer ON reviewer.id = cr.reviewed_by
             WHERE cr.restaurant_id = :restaurant_id
             ORDER BY cr.id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $this->decodeRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function requestStockMovementQuantityCorrection(int $restaurantId, int $movementId, array $payload, array $actor): void
    {
        $movement = Container::getInstance()->get('stockService')->findMovementWithItem($movementId, $restaurantId);
        $newQuantity = (float) ($payload['new_quantity'] ?? 0);
        $justification = trim((string) ($payload['justification'] ?? ''));

        if ($movement['status'] !== 'VALIDE') {
            throw new \RuntimeException('Seuls les mouvements déjà validés passent par une demande de correction.');
        }

        if ($newQuantity <= 0) {
            throw new \RuntimeException('La nouvelle quantité demandée doit être supérieure à zéro.');
        }

        if ($justification === '') {
            throw new \RuntimeException('Une justification est obligatoire pour demander la correction.');
        }

        $oldQuantity = (float) $movement['quantity'];
        if (abs($oldQuantity - $newQuantity) < 0.00001) {
            throw new \RuntimeException('La nouvelle quantité est identique à la quantité actuelle.');
        }

        $requestId = $this->insertRequest([
            'restaurant_id' => $restaurantId,
            'module_name' => 'stock',
            'entity_type' => 'stock_movements',
            'entity_id' => $movementId,
            'request_type' => 'stock_quantity_correction',
            'requested_by' => (int) $actor['id'],
            'requested_role_code' => (string) ($actor['role_code'] ?? ''),
            'old_values_json' => [
                'movement_type' => $movement['movement_type'],
                'stock_item_id' => (int) $movement['stock_item_id'],
                'stock_item_name' => $movement['stock_item_name'],
                'quantity' => $oldQuantity,
                'status' => $movement['status'],
                'unit_cost_snapshot' => (float) ($movement['unit_cost_snapshot'] ?? 0),
            ],
            'proposed_values_json' => [
                'new_quantity' => $newQuantity,
            ],
            'justification' => $justification,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'stock',
            'action_name' => 'stock_quantity_correction_requested',
            'entity_type' => 'correction_requests',
            'entity_id' => (string) $requestId,
            'old_values' => ['quantity' => $oldQuantity],
            'new_values' => ['quantity' => $newQuantity, 'movement_id' => $movementId],
            'justification' => $justification,
        ]);
    }

    public function requestSensitiveOperationCorrection(int $restaurantId, array $payload, array $actor): void
    {
        $moduleName = trim((string) ($payload['module_name'] ?? ''));
        $entityType = trim((string) ($payload['entity_type'] ?? ''));
        $entityId = (int) ($payload['entity_id'] ?? 0);
        $requestType = trim((string) ($payload['request_type'] ?? 'sensitive_operation_correction'));
        $justification = trim((string) ($payload['justification'] ?? ''));
        $summary = trim((string) ($payload['summary'] ?? 'Correction sensible demandée'));

        if ($moduleName === '' || $entityType === '' || $entityId <= 0) {
            throw new \RuntimeException('La cible de correction sensible est invalide.');
        }
        if ($justification === '') {
            throw new \RuntimeException('Une justification est obligatoire pour la correction sensible.');
        }

        $requestId = $this->insertRequest([
            'restaurant_id' => $restaurantId,
            'module_name' => $moduleName,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_type' => $requestType,
            'requested_by' => (int) $actor['id'],
            'requested_role_code' => (string) ($actor['role_code'] ?? ''),
            'old_values_json' => ['summary' => $summary],
            'proposed_values_json' => ['summary' => $summary],
            'justification' => $justification,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => $moduleName,
            'action_name' => 'sensitive_operation_correction_requested',
            'entity_type' => 'correction_requests',
            'entity_id' => (string) $requestId,
            'new_values' => [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'request_type' => $requestType,
            ],
            'justification' => $justification,
        ]);
    }

    public function decide(int $restaurantId, int $requestId, array $payload, array $actor): void
    {
        $request = $this->findInRestaurant($requestId, $restaurantId);
        $decision = strtoupper(trim((string) ($payload['decision'] ?? '')));
        $reviewNotes = trim((string) ($payload['review_notes'] ?? ''));

        if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw new \RuntimeException('Décision de correction invalide.');
        }

        if ($reviewNotes === '') {
            throw new \RuntimeException('Une justification du gérant ou propriétaire est obligatoire.');
        }

        if ($request['status'] !== 'PENDING') {
            throw new \RuntimeException('Cette demande de correction a déjà été traitée.');
        }

        if ($decision === 'APPROVED' && $request['request_type'] === 'stock_quantity_correction') {
            $proposed = (float) ($request['proposed_values']['new_quantity'] ?? 0);
            Container::getInstance()->get('stockService')->applyValidatedQuantityCorrection(
                $restaurantId,
                (int) $request['entity_id'],
                $proposed,
                $reviewNotes,
                $actor,
                $requestId
            );
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE correction_requests
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 review_notes = :review_notes,
                 reviewed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'status' => $decision,
            'reviewed_by' => (int) $actor['id'],
            'review_notes' => $reviewNotes,
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => (string) $request['module_name'],
            'action_name' => $decision === 'APPROVED'
                ? ($request['request_type'] === 'stock_quantity_correction' ? 'stock_quantity_correction_approved' : 'sensitive_operation_correction_approved')
                : ($request['request_type'] === 'stock_quantity_correction' ? 'stock_quantity_correction_rejected' : 'sensitive_operation_correction_rejected'),
            'entity_type' => 'correction_requests',
            'entity_id' => (string) $requestId,
            'old_values' => ['status' => 'PENDING'],
            'new_values' => ['status' => $decision],
            'justification' => $reviewNotes,
        ]);
    }

    private function insertRequest(array $payload): int
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO correction_requests
            (restaurant_id, module_name, entity_type, entity_id, request_type, requested_by, requested_role_code,
             status, old_values_json, proposed_values_json, justification, review_notes, reviewed_by, reviewed_at, created_at, updated_at)
             VALUES
            (:restaurant_id, :module_name, :entity_type, :entity_id, :request_type, :requested_by, :requested_role_code,
             "PENDING", :old_values_json, :proposed_values_json, :justification, NULL, NULL, NULL, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $payload['restaurant_id'],
            'module_name' => $payload['module_name'],
            'entity_type' => $payload['entity_type'],
            'entity_id' => $payload['entity_id'],
            'request_type' => $payload['request_type'],
            'requested_by' => $payload['requested_by'],
            'requested_role_code' => $payload['requested_role_code'],
            'old_values_json' => json_encode($payload['old_values_json'], JSON_UNESCAPED_UNICODE),
            'proposed_values_json' => json_encode($payload['proposed_values_json'], JSON_UNESCAPED_UNICODE),
            'justification' => $payload['justification'],
        ]);

        return (int) $this->database->pdo()->lastInsertId();
    }

    private function findInRestaurant(int $requestId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM correction_requests
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $requestId,
            'restaurant_id' => $restaurantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('Demande de correction introuvable dans ce restaurant.');
        }

        return $this->decodeRow($row);
    }

    private function decodeRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->decodeRow($row), $rows);
    }

    private function decodeRow(array $row): array
    {
        $row['old_values'] = $this->decodeJson($row['old_values_json'] ?? null);
        $row['proposed_values'] = $this->decodeJson($row['proposed_values_json'] ?? null);

        return $row;
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
