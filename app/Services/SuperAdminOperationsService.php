<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

/**
 * Outils de dépannage : forcer un statut métier avec audit renforcé (sans DROP/TRUNCATE).
 */
final class SuperAdminOperationsService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public function allowedStatusesByKind(): array
    {
        return [
            'sale' => ['EN_COURS', 'VALIDE', 'CLOTURE', 'ANNULE'],
            'cash_remittance' => [
                'REMIS_A_CAISSE',
                'SOUMIS_GERANT',
                'RECU_CAISSE',
                'ECART_SIGNALE',
                'REMISE_REJETEE_CAISSE',
                'REMISE_REJETEE_GERANT',
            ],
            'server_request' => ['DEMANDE', 'ANNULE', 'REFUSE_CUISINE', 'REMIS_SERVEUR', 'CLOTURE'],
            'kitchen_stock_request' => ['DEMANDE', 'ANNULE', 'REFUSE_STOCK', 'EN_COURS_TRAITEMENT', 'CLOTURE'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(int $restaurantId, string $kind, int $id): ?array
    {
        if ($id <= 0 || !isset($this->allowedStatusesByKind()[$kind])) {
            return null;
        }

        $pdo = $this->database->pdo();

        return match ($kind) {
            'sale' => $this->lookupSale($pdo, $restaurantId, $id),
            'cash_remittance' => $this->lookupCashRemittance($pdo, $restaurantId, $id),
            'server_request' => $this->lookupServerRequest($pdo, $restaurantId, $id),
            'kitchen_stock_request' => $this->lookupKitchenStockRequest($pdo, $restaurantId, $id),
            default => null,
        };
    }

    public function forceSetStatus(
        int $restaurantId,
        string $kind,
        int $id,
        string $targetStatus,
        string $reason,
        bool $confirmAck,
        array $actor,
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif obligatoire.');
        }
        if (!$confirmAck) {
            throw new \RuntimeException('Cochez la case de confirmation pour appliquer le depannage.');
        }

        $allowed = $this->allowedStatusesByKind()[$kind] ?? null;
        if ($allowed === null || !in_array($targetStatus, $allowed, true)) {
            throw new \RuntimeException('Statut cible non autorise pour ce type d operation.');
        }

        $before = $this->lookup($restaurantId, $kind, $id);
        if ($before === null) {
            throw new \RuntimeException('Entite introuvable pour ce restaurant.');
        }

        $oldStatus = (string) ($before['status'] ?? '');
        if ($oldStatus === $targetStatus) {
            throw new \RuntimeException('Statut deja identique, aucune modification.');
        }

        match ($kind) {
            'sale' => $this->applySale($restaurantId, $id, $targetStatus),
            'cash_remittance' => $this->applyCashRemittance($restaurantId, $id, $targetStatus),
            'server_request' => $this->applyServerRequest($restaurantId, $id, $targetStatus, $reason),
            'kitchen_stock_request' => $this->applyKitchenStockRequest($restaurantId, $id, $targetStatus, $reason),
            default => throw new \RuntimeException('Type inconnu.'),
        };

        $after = $this->lookup($restaurantId, $kind, $id);
        $isValidateAudit = $this->isValidateFamily($kind, $targetStatus);
        $payload = [
            'kind' => $kind,
            'entity_id' => $id,
            'old_status' => $oldStatus,
            'new_status' => $targetStatus,
            'reason' => $reason,
            'snapshot_before' => $before,
            'snapshot_after' => $after,
        ];

        $this->audit(
            $restaurantId,
            $actor,
            $isValidateAudit ? 'super_admin_force_validate' : 'super_admin_force_status_change',
            $this->entityTypeForKind($kind),
            (string) $id,
            $payload,
            $isValidateAudit ? 'Super admin — validation forcee' : 'Super admin — changement de statut force'
        );
    }

    private function isValidateFamily(string $kind, string $target): bool
    {
        return match ($kind) {
            'sale' => $target === 'VALIDE',
            'cash_remittance' => $target === 'RECU_CAISSE',
            'server_request' => $target === 'CLOTURE',
            'kitchen_stock_request' => $target === 'CLOTURE',
            default => false,
        };
    }

    private function entityTypeForKind(string $kind): string
    {
        return match ($kind) {
            'sale' => 'sales',
            'cash_remittance' => 'cash_transfers',
            'server_request' => 'server_requests',
            'kitchen_stock_request' => 'kitchen_stock_requests',
            default => 'unknown',
        };
    }

    private function audit(int $restaurantId, array $actor, string $action, string $entityType, string $entityId, array $newValues, string $justification): void
    {
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? null,
            'actor_role_code' => $actor['role_code'] ?? null,
            'module_name' => 'super_admin',
            'action_name' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'new_values' => $newValues,
            'justification' => $justification,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupSale(PDO $pdo, int $restaurantId, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM sales WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $st->execute(['id' => $id, 'restaurant_id' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $items = $pdo->prepare(
            'SELECT si.*, mi.name AS menu_item_name
             FROM sale_items si
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $items->execute(['sale_id' => $id]);

        return [
            'kind' => 'sale',
            'status' => $row['status'] ?? null,
            'row' => $row,
            'lines' => $items->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupCashRemittance(PDO $pdo, int $restaurantId, int $id): ?array
    {
        $st = $pdo->prepare(
            'SELECT * FROM cash_transfers
             WHERE id = :id AND restaurant_id = :restaurant_id AND source_type = "sale"
             LIMIT 1'
        );
        $st->execute(['id' => $id, 'restaurant_id' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'kind' => 'cash_remittance',
            'status' => $row['status'] ?? null,
            'row' => $row,
            'lines' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupServerRequest(PDO $pdo, int $restaurantId, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM server_requests WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $st->execute(['id' => $id, 'restaurant_id' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $items = $pdo->prepare(
            'SELECT sri.*, mi.name AS menu_item_name
             FROM server_request_items sri
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             WHERE sri.request_id = :request_id AND sri.restaurant_id = :restaurant_id
             ORDER BY sri.id ASC'
        );
        $items->execute(['request_id' => $id, 'restaurant_id' => $restaurantId]);

        return [
            'kind' => 'server_request',
            'status' => $row['status'] ?? null,
            'row' => $row,
            'lines' => $items->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupKitchenStockRequest(PDO $pdo, int $restaurantId, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM kitchen_stock_requests WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $st->execute(['id' => $id, 'restaurant_id' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $lines = [];
        $check = $pdo->query("SHOW TABLES LIKE 'kitchen_stock_request_items'");
        if ($check && $check->fetchColumn()) {
            $items = $pdo->prepare(
                'SELECT ksri.*, si.name AS stock_item_name
                 FROM kitchen_stock_request_items ksri
                 INNER JOIN stock_items si ON si.id = ksri.stock_item_id
                 WHERE ksri.request_id = :request_id AND ksri.restaurant_id = :restaurant_id
                 ORDER BY ksri.id ASC'
            );
            $items->execute(['request_id' => $id, 'restaurant_id' => $restaurantId]);
            $lines = $items->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'kind' => 'kitchen_stock_request',
            'status' => $row['status'] ?? null,
            'row' => $row,
            'lines' => $lines,
        ];
    }

    private function applySale(int $restaurantId, int $saleId, string $target): void
    {
        $pdo = $this->database->pdo();
        $validatedAt = $target === 'VALIDE' ? ', validated_at = NOW()' : '';
        $st = $pdo->prepare(
            "UPDATE sales SET status = :status{$validatedAt}
             WHERE id = :id AND restaurant_id = :restaurant_id"
        );
        $st->execute([
            'status' => $target,
            'id' => $saleId,
            'restaurant_id' => $restaurantId,
        ]);
        if ($st->rowCount() < 1) {
            throw new \RuntimeException('Mise a jour vente impossible.');
        }
    }

    private function applyCashRemittance(int $restaurantId, int $transferId, string $target): void
    {
        $pdo = $this->database->pdo();
        if ($target === 'RECU_CAISSE') {
            $st = $pdo->prepare(
                'UPDATE cash_transfers
                 SET status = "RECU_CAISSE",
                     amount_received = amount,
                     received_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id AND source_type = "sale"'
            );
        } elseif ($target === 'ECART_SIGNALE') {
            $st = $pdo->prepare(
                'UPDATE cash_transfers
                 SET status = "ECART_SIGNALE",
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id AND source_type = "sale"'
            );
        } else {
            $st = $pdo->prepare(
                'UPDATE cash_transfers
                 SET status = :status,
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id AND source_type = "sale"'
            );
            $st->execute([
                'status' => $target,
                'id' => $transferId,
                'restaurant_id' => $restaurantId,
            ]);
            if ($st->rowCount() < 1) {
                throw new \RuntimeException('Mise a jour remise caisse impossible.');
            }

            return;
        }

        $st->execute(['id' => $transferId, 'restaurant_id' => $restaurantId]);
        if ($st->rowCount() < 1) {
            throw new \RuntimeException('Mise a jour remise caisse impossible.');
        }
    }

    private function applyServerRequest(int $restaurantId, int $requestId, string $target, string $reason): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            if ($target === 'ANNULE') {
                $pdo->prepare(
                    'UPDATE server_request_items
                     SET status = "ANNULE",
                         supply_status = "NON_FOURNI",
                         supplied_quantity = 0,
                         supplied_total = 0,
                         total_supplied_amount = 0,
                         unavailable_quantity = requested_quantity,
                         updated_at = NOW()
                     WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
                )->execute(['request_id' => $requestId, 'restaurant_id' => $restaurantId]);

                $pdo->prepare(
                    'UPDATE server_requests
                     SET status = "ANNULE",
                         total_supplied_amount = 0,
                         resolution_note = :resolution_note,
                         resolution_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'resolution_note' => '[Super admin] ' . $reason,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            } elseif ($target === 'REFUSE_CUISINE') {
                $pdo->prepare(
                    'UPDATE server_request_items
                     SET status = "REFUSE_CUISINE",
                         supply_status = "NON_FOURNI",
                         supplied_quantity = 0,
                         supplied_total = 0,
                         total_supplied_amount = 0,
                         unavailable_quantity = requested_quantity,
                         updated_at = NOW()
                     WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
                )->execute(['request_id' => $requestId, 'restaurant_id' => $restaurantId]);

                $pdo->prepare(
                    'UPDATE server_requests
                     SET status = "REFUSE_CUISINE",
                         total_supplied_amount = 0,
                         resolution_note = :resolution_note,
                         resolution_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'resolution_note' => '[Super admin] ' . $reason,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            } elseif ($target === 'DEMANDE') {
                $pdo->prepare(
                    'UPDATE server_request_items
                     SET status = "DEMANDE",
                         supply_status = "DEMANDE",
                         supplied_quantity = 0,
                         supplied_total = 0,
                         total_supplied_amount = 0,
                         unavailable_quantity = requested_quantity,
                         updated_at = NOW()
                     WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
                )->execute(['request_id' => $requestId, 'restaurant_id' => $restaurantId]);

                $pdo->prepare(
                    'UPDATE server_requests
                     SET status = "DEMANDE",
                         resolution_note = NULL,
                         resolution_at = NULL,
                         resolution_by = NULL,
                         total_supplied_amount = 0,
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute(['id' => $requestId, 'restaurant_id' => $restaurantId]);
            } else {
                $pdo->prepare(
                    'UPDATE server_requests
                     SET status = :status,
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'status' => $target,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function applyKitchenStockRequest(int $restaurantId, int $requestId, string $target, string $reason): void
    {
        $pdo = $this->database->pdo();
        $hasItems = $pdo->query("SHOW TABLES LIKE 'kitchen_stock_request_items'")?->fetchColumn();

        $pdo->beginTransaction();

        try {
            if ($hasItems) {
                if ($target === 'ANNULE' || $target === 'REFUSE_STOCK') {
                    $itemStatus = $target === 'ANNULE' ? 'ANNULE' : 'REFUSE_STOCK';
                    $pdo->prepare(
                        "UPDATE kitchen_stock_request_items
                         SET status = :istatus,
                             quantity_supplied = 0,
                             unavailable_quantity = quantity_requested,
                             updated_at = NOW()
                         WHERE request_id = :request_id AND restaurant_id = :restaurant_id"
                    )->execute([
                        'istatus' => $itemStatus,
                        'request_id' => $requestId,
                        'restaurant_id' => $restaurantId,
                    ]);
                } elseif ($target === 'DEMANDE') {
                    $pdo->prepare(
                        'UPDATE kitchen_stock_request_items
                         SET status = "DEMANDE",
                             quantity_supplied = 0,
                             unavailable_quantity = quantity_requested,
                             updated_at = NOW()
                         WHERE request_id = :request_id AND restaurant_id = :restaurant_id'
                    )->execute(['request_id' => $requestId, 'restaurant_id' => $restaurantId]);
                }
            }

            if ($target === 'ANNULE') {
                $pdo->prepare(
                    'UPDATE kitchen_stock_requests
                     SET status = "ANNULE",
                         quantity_supplied = 0,
                         unavailable_quantity = quantity_requested,
                         resolution_note = :resolution_note,
                         resolution_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'resolution_note' => '[Super admin] ' . $reason,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            } elseif ($target === 'REFUSE_STOCK') {
                $pdo->prepare(
                    'UPDATE kitchen_stock_requests
                     SET status = "REFUSE_STOCK",
                         quantity_supplied = 0,
                         resolution_note = :resolution_note,
                         resolution_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'resolution_note' => '[Super admin] ' . $reason,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            } elseif ($target === 'DEMANDE') {
                $pdo->prepare(
                    'UPDATE kitchen_stock_requests
                     SET status = "DEMANDE",
                         quantity_supplied = 0,
                         resolution_note = NULL,
                         resolution_at = NULL,
                         resolution_by = NULL,
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute(['id' => $requestId, 'restaurant_id' => $restaurantId]);
            } else {
                $pdo->prepare(
                    'UPDATE kitchen_stock_requests
                     SET status = :status,
                         updated_at = NOW()
                     WHERE id = :id AND restaurant_id = :restaurant_id'
                )->execute([
                    'status' => $target,
                    'id' => $requestId,
                    'restaurant_id' => $restaurantId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
