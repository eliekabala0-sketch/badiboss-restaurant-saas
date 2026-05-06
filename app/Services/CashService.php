<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CashService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function dashboard(int $restaurantId, array $filters = []): array
    {
        $this->ensureSchema();

        return [
            'transfers' => $this->listTransfers($restaurantId, $filters),
            'movements' => $this->listMovements($restaurantId, $filters),
            'summary' => $this->summary($restaurantId, $filters),
            'cashiers' => $this->listUsersByRoleCodes($restaurantId, ['cashier_accountant', 'stock_manager']),
            'servers' => $this->listUsersByRoleCodes($restaurantId, ['cashier_server']),
            'managers' => $this->listUsersByRoleCodes($restaurantId, ['manager']),
            'owners' => $this->listUsersByRoleCodes($restaurantId, ['owner']),
            'pending_server_sales' => $this->listServerRemittanceCandidates($restaurantId),
        ];
    }

    /**
     * Synthèse des flux caisse sur une plage de dates (inclus) : remises vente, réceptions, chaîne gérant/propriétaire, écarts.
     * Les montants suivent les enregistrements réels (entrées +, sorties - au niveau métier dans l’affichage).
     */
    public function periodCashClarity(int $restaurantId, string $dateFromYmd, string $dateToYmd): array
    {
        $this->ensureSchema();
        $statement = $this->database->pdo()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN ct.source_type = "sale" THEN ct.amount ELSE 0 END), 0) AS server_remittance_total,
                COALESCE(SUM(CASE WHEN ct.source_type = "sale" AND ct.status IN ("RECU_CAISSE", "ECART_SIGNALE") THEN COALESCE(ct.amount_received, ct.amount) ELSE 0 END), 0) AS cashier_received_sales,
                COALESCE(SUM(CASE WHEN ct.source_type = "REMISE_GERANT" THEN ct.amount ELSE 0 END), 0) AS declared_to_manager,
                COALESCE(SUM(CASE WHEN ct.status = "RECU_GERANT" THEN COALESCE(ct.amount_received, ct.amount) ELSE 0 END), 0) AS manager_received,
                COALESCE(SUM(CASE WHEN ct.source_type = "REMISE_PROPRIETAIRE" THEN ct.amount ELSE 0 END), 0) AS declared_to_owner,
                COALESCE(SUM(CASE WHEN ct.status = "RECU_PROPRIETAIRE" THEN COALESCE(ct.amount_received, ct.amount) ELSE 0 END), 0) AS owner_received,
                COALESCE(SUM(ABS(ct.discrepancy_amount)), 0) AS discrepancy_total
             FROM cash_transfers ct
             WHERE ct.restaurant_id = :restaurant_id
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :start_at
               AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) <= :end_at'
                );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'start_at' => $dateFromYmd . ' 00:00:00',
            'end_at' => $dateToYmd . ' 23:59:59',
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $filters = ['date_from' => $dateFromYmd, 'date_to' => $dateToYmd];
        $summary = $this->summary($restaurantId, $filters);
        $managerNet = (float) ($row['manager_received'] ?? 0) - (float) ($row['declared_to_owner'] ?? 0);

        return [
            'period_from' => $dateFromYmd,
            'period_to' => $dateToYmd,
            'server_remittance_total' => (float) ($row['server_remittance_total'] ?? 0),
            'cashier_received_sales' => (float) ($row['cashier_received_sales'] ?? 0),
            'declared_to_manager' => (float) ($row['declared_to_manager'] ?? 0),
            'manager_received' => (float) ($row['manager_received'] ?? 0),
            'declared_to_owner' => (float) ($row['declared_to_owner'] ?? 0),
            'owner_received' => (float) ($row['owner_received'] ?? 0),
            'discrepancy_total' => (float) ($row['discrepancy_total'] ?? 0),
            'cash_balance' => (float) ($summary['cash_balance'] ?? 0),
            'cash_entries' => (float) ($summary['cash_entries'] ?? 0),
            'cash_expenses' => (float) ($summary['cash_expenses'] ?? 0),
            'cash_outputs' => (float) ($summary['cash_outputs'] ?? 0),
            'manager_net_period' => round($managerNet, 2),
            'currency' => (string) ($summary['currency'] ?? restaurant_currency($restaurantId)),
        ];
    }

    public function remitServerCash(int $restaurantId, array $payload, array $actor): int
    {
        $this->ensureSchema();
        $sale = $this->findSaleInRestaurant((int) ($payload['sale_id'] ?? 0), $restaurantId);
        $this->assertRemittableSale($sale, $actor);
        $this->assertSaleNotAlreadyRemitted((int) $sale['id'], $restaurantId);

        $toUserId = $this->resolveCashierRecipient($restaurantId, (int) ($payload['to_user_id'] ?? 0));
        $amount = $this->normalizeAmount($sale['total_amount'] ?? 0);
        $currency = restaurant_currency($restaurantId);
        $serverRequestId = ((string) ($sale['origin_type'] ?? '') === 'server_request' && (int) ($sale['origin_id'] ?? 0) > 0)
            ? (int) $sale['origin_id']
            : null;

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO cash_transfers
            (restaurant_id, from_user_id, to_user_id, amount, currency, source_type, source_id, status, note, discrepancy_amount, discrepancy_note, requested_at, created_by, created_at, updated_at)
             VALUES
            (:restaurant_id, :from_user_id, :to_user_id, :amount, :currency, "sale", :source_id, "REMIS_A_CAISSE", :note, 0, NULL, NOW(), :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'from_user_id' => $actor['id'],
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => $currency,
            'source_id' => (int) $sale['id'],
            'note' => trim((string) ($payload['note'] ?? 'Remise serveur liee a la vente.')) ?: null,
            'created_by' => $actor['id'],
        ]);

        $transferId = (int) $this->database->pdo()->lastInsertId();
        $this->audit($restaurantId, $actor, 'cash_server_remitted', 'cash_transfers', $transferId, [
            'sale_id' => (int) $sale['id'],
            'server_request_id' => $serverRequestId,
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => $currency,
        ], 'Remise d argent du serveur a la caisse');

        return $transferId;
    }

    public function listServerRemittanceCandidates(int $restaurantId, ?int $serverId = null): array
    {
        return array_values(array_filter(
            $this->listSaleRemittanceTracking($restaurantId, $serverId),
            static function (array $row): bool {
                $saleStatus = (string) ($row['sale_status'] ?? '');
                $transferId = (int) ($row['transfer_id'] ?? 0);
                $amount = (float) ($row['sale_total_amount'] ?? 0);

                return in_array($saleStatus, ['VALIDE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)
                    && $transferId <= 0
                    && $amount > 0;
            }
        ));
    }

    public function listSaleRemittanceTracking(int $restaurantId, ?int $serverId = null): array
    {
        $sql = 'SELECT s.id AS sale_id,
                       s.server_id,
                       s.total_amount AS sale_total_amount,
                       s.status AS sale_status,
                       s.origin_type,
                       s.origin_id,
                       s.note AS sale_note,
                       s.validated_at,
                       s.created_at AS sale_created_at,
                       su.full_name AS server_name,
                       sr.id AS server_request_id,
                       sr.status AS server_request_status,
                       sr.service_reference,
                       sr.received_at AS server_request_received_at,
                       ct.id AS transfer_id,
                       ct.status AS transfer_status,
                       ct.amount AS transfer_amount,
                       ct.amount_received,
                       ct.discrepancy_amount,
                       ct.discrepancy_note,
                       ct.requested_at AS remitted_at,
                       ct.received_at AS cash_received_at,
                       tu.full_name AS cashier_name,
                       ru.full_name AS cash_received_by_name
                FROM sales s
                LEFT JOIN users su ON su.id = s.server_id
                LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
                LEFT JOIN cash_transfers ct ON ct.id = (
                    SELECT latest.id
                    FROM cash_transfers latest
                    WHERE latest.restaurant_id = s.restaurant_id
                      AND latest.source_type = "sale"
                      AND latest.source_id = s.id
                    ORDER BY latest.id DESC
                    LIMIT 1
                )
                LEFT JOIN users tu ON tu.id = ct.to_user_id
                LEFT JOIN users ru ON ru.id = ct.received_by
                WHERE s.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if ($serverId !== null) {
            $sql .= ' AND s.server_id = :server_id';
            $params['server_id'] = $serverId;
        }

        $sql .= ' ORDER BY COALESCE(s.validated_at, s.created_at) DESC, s.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function receiveByCashier(int $restaurantId, int $transferId, array $payload, array $actor): void
    {
        $this->ensureSchema();
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if (!in_array((string) $transfer['status'], ['REMIS_A_CAISSE', 'ECART_SIGNALE'], true)) {
            throw new \RuntimeException('Cette remise n attend pas la caisse.');
        }

        $amountReceived = $this->normalizeAmount($payload['amount_received'] ?? $transfer['amount']);
        $expectedAmount = (float) $transfer['amount'];
        $discrepancy = round($expectedAmount - $amountReceived, 2);
        $status = abs($discrepancy) > 0.0001 ? 'ECART_SIGNALE' : 'RECU_CAISSE';

        $statement = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET amount_received = :amount_received,
                 received_by = :received_by,
                 received_at = NOW(),
                 status = :status,
                 discrepancy_amount = :discrepancy_amount,
                 discrepancy_note = :discrepancy_note,
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'amount_received' => $amountReceived,
            'received_by' => $actor['id'],
            'status' => $status,
            'discrepancy_amount' => abs($discrepancy) > 0.0001 ? $discrepancy : 0,
            'discrepancy_note' => trim((string) ($payload['discrepancy_note'] ?? '')) ?: null,
            'id' => $transferId,
            'restaurant_id' => $restaurantId,
        ]);

        $this->audit($restaurantId, $actor, 'cash_cashier_received', 'cash_transfers', $transferId, [
            'amount_received' => $amountReceived,
            'status' => $status,
            'discrepancy_amount' => abs($discrepancy) > 0.0001 ? $discrepancy : 0,
        ], 'Reception caisse');
    }

    public function createMovement(int $restaurantId, array $payload, array $actor): int
    {
        $this->ensureSchema();
        $type = strtoupper(trim((string) ($payload['movement_type'] ?? 'ENTREE')));
        if (!in_array($type, ['ENTREE', 'SORTIE', 'DEPENSE', 'AJUSTEMENT'], true)) {
            throw new \RuntimeException('Type de mouvement caisse invalide.');
        }

        $amount = $this->normalizeAmount($payload['amount'] ?? 0);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO cash_movements
            (restaurant_id, movement_type, amount, currency, note, source_type, source_id, created_by, created_at, updated_at)
             VALUES
            (:restaurant_id, :movement_type, :amount, :currency, :note, :source_type, :source_id, :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'movement_type' => $type,
            'amount' => $amount,
            'currency' => restaurant_currency($restaurantId),
            'note' => trim((string) ($payload['note'] ?? '')) ?: null,
            'source_type' => trim((string) ($payload['source_type'] ?? 'manual')) ?: 'manual',
            'source_id' => (int) ($payload['source_id'] ?? 0) ?: null,
            'created_by' => $actor['id'],
        ]);

        $movementId = (int) $this->database->pdo()->lastInsertId();
        $this->audit($restaurantId, $actor, 'cash_movement_created', 'cash_movements', $movementId, [
            'movement_type' => $type,
            'amount' => $amount,
        ], 'Mouvement de caisse');

        return $movementId;
    }

    public function transferToManager(int $restaurantId, array $payload, array $actor): int
    {
        return $this->createChainTransfer($restaurantId, $actor, (int) ($payload['to_user_id'] ?? 0), 'REMISE_GERANT', 'REMIS_A_GERANT', $payload);
    }

    public function receiveByManager(int $restaurantId, int $transferId, array $payload, array $actor): void
    {
        $this->receiveChainTransfer($restaurantId, $transferId, $payload, $actor, 'REMIS_A_GERANT', 'RECU_GERANT', 'cash_manager_received', 'Reception gerant');
    }

    public function transferToOwner(int $restaurantId, array $payload, array $actor): int
    {
        return $this->createChainTransfer($restaurantId, $actor, (int) ($payload['to_user_id'] ?? 0), 'REMISE_PROPRIETAIRE', 'REMIS_A_PROPRIETAIRE', $payload);
    }

    public function receiveByOwner(int $restaurantId, int $transferId, array $payload, array $actor): void
    {
        $this->receiveChainTransfer($restaurantId, $transferId, $payload, $actor, 'REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE', 'cash_owner_received', 'Reception proprietaire');
    }

    public function printableReceipt(int $restaurantId, int $saleId): array
    {
        $sale = $this->findSaleInRestaurant($saleId, $restaurantId);
        $itemsStatement = $this->database->pdo()->prepare(
            'SELECT si.*, mi.name AS menu_item_name
             FROM sale_items si
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $itemsStatement->execute(['sale_id' => $saleId]);

        return [
            'sale' => $sale,
            'items' => $itemsStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function listTransfers(int $restaurantId, array $filters): array
    {
        $sql = 'SELECT ct.*,
                       fu.full_name AS from_user_name,
                       tu.full_name AS to_user_name,
                       ru.full_name AS received_by_name,
                       vu.full_name AS validated_by_name,
                       s.id AS sale_id,
                       s.total_amount AS sale_total_amount,
                       s.status AS sale_status,
                       s.origin_type AS sale_origin_type,
                       s.origin_id AS sale_origin_id,
                       sr.id AS server_request_id,
                       sr.service_reference,
                       sr.status AS server_request_status,
                       su.full_name AS sale_server_name
                 FROM cash_transfers ct
                 LEFT JOIN users fu ON fu.id = ct.from_user_id
                 LEFT JOIN users tu ON tu.id = ct.to_user_id
                 LEFT JOIN users ru ON ru.id = ct.received_by
                 LEFT JOIN users vu ON vu.id = ct.validated_by
                 LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
                 LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
                 LEFT JOIN users su ON su.id = s.server_id
                 WHERE ct.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if (!empty($filters['status'])) {
            $sql .= ' AND ct.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND (ct.from_user_id = :user_id OR ct.to_user_id = :user_id OR ct.received_by = :user_id)';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :date_from';
            $params['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) <= :date_to';
            $params['date_to'] = (string) $filters['date_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY ct.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function listMovements(int $restaurantId, array $filters): array
    {
        $sql = 'SELECT cm.*, u.full_name AS created_by_name
                FROM cash_movements cm
                LEFT JOIN users u ON u.id = cm.created_by
                WHERE cm.restaurant_id = :restaurant_id';
        $params = ['restaurant_id' => $restaurantId];

        if (!empty($filters['movement_type'])) {
            $sql .= ' AND cm.movement_type = :movement_type';
            $params['movement_type'] = strtoupper((string) $filters['movement_type']);
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND cm.created_by = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND cm.created_at >= :date_from';
            $params['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND cm.created_at <= :date_to';
            $params['date_to'] = (string) $filters['date_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY cm.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function summary(int $restaurantId, array $filters): array
    {
        $transfers = $this->listTransfers($restaurantId, $filters);
        $movements = $this->listMovements($restaurantId, $filters);

        $soldStatement = $this->database->pdo()->prepare('SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE restaurant_id = :restaurant_id');
        $soldStatement->execute(['restaurant_id' => $restaurantId]);
        $soldTotal = (float) $soldStatement->fetchColumn();

        $totalRemittedToCash = 0.0;
        $totalReceivedByCash = 0.0;
        $totalToManager = 0.0;
        $totalToOwner = 0.0;
        $totalDiscrepancies = 0.0;
        foreach ($transfers as $transfer) {
            $status = (string) ($transfer['status'] ?? '');
            $amount = (float) ($transfer['amount'] ?? 0);
            $amountReceived = (float) ($transfer['amount_received'] ?? $amount);
            if (in_array($status, ['REMIS_A_CAISSE', 'RECU_CAISSE', 'ECART_SIGNALE', 'REMIS_A_GERANT', 'RECU_GERANT', 'REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE'], true)) {
                $totalRemittedToCash += $amount;
            }
            if (in_array($status, ['RECU_CAISSE', 'REMIS_A_GERANT', 'RECU_GERANT', 'REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE', 'ECART_SIGNALE'], true)) {
                $totalReceivedByCash += $amountReceived;
            }
            if (in_array($status, ['REMIS_A_GERANT', 'RECU_GERANT', 'REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE'], true) && (string) ($transfer['source_type'] ?? '') === 'REMISE_GERANT') {
                $totalToManager += $amount;
            }
            if (in_array($status, ['REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE'], true) && (string) ($transfer['source_type'] ?? '') === 'REMISE_PROPRIETAIRE') {
                $totalToOwner += $amount;
            }
            $totalDiscrepancies += abs((float) ($transfer['discrepancy_amount'] ?? 0));
        }

        $entries = 0.0;
        $expenses = 0.0;
        $outputs = 0.0;
        foreach ($movements as $movement) {
            $type = (string) ($movement['movement_type'] ?? '');
            $amount = (float) ($movement['amount'] ?? 0);
            if ($type === 'ENTREE') {
                $entries += $amount;
            } elseif ($type === 'DEPENSE') {
                $expenses += $amount;
            } else {
                $outputs += $amount;
            }
        }

        return [
            'total_sold' => $soldTotal,
            'total_remitted_to_cash' => $totalRemittedToCash,
            'total_received_by_cash' => $totalReceivedByCash,
            'cash_entries' => $entries,
            'cash_expenses' => $expenses,
            'cash_outputs' => $outputs,
            'cash_balance' => $totalReceivedByCash + $entries - $expenses - $outputs,
            'transferred_to_manager' => $totalToManager,
            'transferred_to_owner' => $totalToOwner,
            'discrepancies' => $totalDiscrepancies,
            'currency' => restaurant_currency($restaurantId),
        ];
    }

    private function createChainTransfer(int $restaurantId, array $actor, int $toUserId, string $sourceType, string $status, array $payload): int
    {
        $this->ensureSchema();
        $this->assertRestaurantUser($toUserId, $restaurantId);
        $amount = $this->normalizeAmount($payload['amount'] ?? 0);
        $available = $this->availableCashForChainTransfer($restaurantId);
        if ($amount > $available + 0.001) {
            throw new \RuntimeException('Solde caisse insuffisant pour ce transfert.');
        }
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO cash_transfers
            (restaurant_id, from_user_id, to_user_id, amount, currency, source_type, source_id, status, note, discrepancy_amount, requested_at, created_by, created_at, updated_at)
             VALUES
            (:restaurant_id, :from_user_id, :to_user_id, :amount, :currency, :source_type, NULL, :status, :note, 0, NOW(), :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'from_user_id' => $actor['id'],
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => restaurant_currency($restaurantId),
            'source_type' => $sourceType,
            'status' => $status,
            'note' => trim((string) ($payload['note'] ?? '')) ?: null,
            'created_by' => $actor['id'],
        ]);

        $transferId = (int) $this->database->pdo()->lastInsertId();
        $this->audit($restaurantId, $actor, 'cash_transfer_created', 'cash_transfers', $transferId, [
            'amount' => $amount,
            'to_user_id' => $toUserId,
            'status' => $status,
            'source_type' => $sourceType,
        ], 'Transfert de caisse');

        return $transferId;
    }

    /**
     * Solde caisse (même formule que summary.cash_balance) pour bloquer un transfert supérieur aux liquidités.
     */
    private function availableCashForChainTransfer(int $restaurantId): float
    {
        $summary = $this->summary($restaurantId, []);

        return max(0.0, round((float) ($summary['cash_balance'] ?? 0), 2));
    }

    private function receiveChainTransfer(int $restaurantId, int $transferId, array $payload, array $actor, string $expectedStatus, string $newStatus, string $auditAction, string $justification): void
    {
        $this->ensureSchema();
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['status'] ?? '') !== $expectedStatus) {
            throw new \RuntimeException('Ce transfert n est pas pret pour cette reception.');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET amount_received = :amount_received,
                 received_by = :received_by,
                 received_at = NOW(),
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'amount_received' => $this->normalizeAmount($payload['amount_received'] ?? $transfer['amount']),
            'received_by' => $actor['id'],
            'status' => $newStatus,
            'id' => $transferId,
            'restaurant_id' => $restaurantId,
        ]);

        $this->audit($restaurantId, $actor, $auditAction, 'cash_transfers', $transferId, [
            'status' => $newStatus,
        ], $justification);
    }

    private function listUsersByRoleCodes(int $restaurantId, array $roleCodes): array
    {
        $placeholders = implode(', ', array_fill(0, count($roleCodes), '?'));
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.full_name, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = ?
               AND r.code IN (' . $placeholders . ')
             ORDER BY u.full_name ASC'
        );
        $statement->execute(array_merge([$restaurantId], $roleCodes));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findSaleInRestaurant(int $saleId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM sales WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $statement->execute(['id' => $saleId, 'restaurant_id' => $restaurantId]);
        $sale = $statement->fetch(PDO::FETCH_ASSOC);
        if ($sale === false) {
            throw new \RuntimeException('Vente introuvable pour ce restaurant.');
        }

        return $sale;
    }

    private function assertRemittableSale(array $sale, array $actor): void
    {
        if (!in_array((string) ($sale['status'] ?? ''), ['VALIDE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)) {
            throw new \RuntimeException('Cloturez d abord cette vente avant remise caisse.');
        }

        if ((float) ($sale['total_amount'] ?? 0) <= 0) {
            throw new \RuntimeException('Cette vente ne contient aucun montant a remettre.');
        }

        $isManager = ($actor['role_code'] ?? null) === 'manager';
        if (!$isManager && (int) ($sale['server_id'] ?? 0) !== (int) ($actor['id'] ?? 0)) {
            throw new \RuntimeException('Vous ne pouvez remettre que vos propres ventes cloturees.');
        }
    }

    private function assertSaleNotAlreadyRemitted(int $saleId, int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM cash_transfers
             WHERE restaurant_id = :restaurant_id
               AND source_type = "sale"
               AND source_id = :sale_id
             LIMIT 1'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'sale_id' => $saleId,
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new \RuntimeException('Cette vente a deja ete remise a la caisse.');
        }
    }

    private function resolveCashierRecipient(int $restaurantId, int $toUserId): int
    {
        $cashiers = $this->listUsersByRoleCodes($restaurantId, ['cashier_accountant', 'stock_manager']);

        if ($toUserId > 0) {
            foreach ($cashiers as $cashier) {
                if ((int) $cashier['id'] === $toUserId) {
                    return $toUserId;
                }
            }

            throw new \RuntimeException('Choisissez une caisse valide pour cette remise.');
        }

        if (count($cashiers) === 1) {
            return (int) $cashiers[0]['id'];
        }

        throw new \RuntimeException('Choisissez la caisse qui doit recevoir cette remise.');
    }

    private function findTransferInRestaurant(int $transferId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM cash_transfers WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $statement->execute(['id' => $transferId, 'restaurant_id' => $restaurantId]);
        $transfer = $statement->fetch(PDO::FETCH_ASSOC);
        if ($transfer === false) {
            throw new \RuntimeException('Transfert de caisse introuvable pour ce restaurant.');
        }

        return $transfer;
    }

    private function assertRestaurantUser(int $userId, int $restaurantId): int
    {
        $statement = $this->database->pdo()->prepare('SELECT id FROM users WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1');
        $statement->execute(['id' => $userId, 'restaurant_id' => $restaurantId]);
        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException('Utilisateur hors perimetre restaurant.');
        }

        return $userId;
    }

    private function normalizeAmount(mixed $amount): float
    {
        $value = round((float) $amount, 2);
        if ($value < 0) {
            throw new \RuntimeException('Montant invalide.');
        }

        return $value;
    }

    /**
     * Remises vente en attente de caisse depuis la veille : réception automatique comme montant intégral encaissé (sans double traitement).
     */
    public function reconcileOverdueCashierReceipts(int $restaurantId): int
    {
        $this->ensureSchema();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $timezoneName = (string) ($restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'));
        try {
            $tz = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $tz = new DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
        }
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $statement = $this->database->pdo()->prepare(
            'SELECT id, amount
             FROM cash_transfers
             WHERE restaurant_id = :tenant
               AND source_type = "sale"
               AND status = "REMIS_A_CAISSE"
               AND COALESCE(requested_at, created_at) < :today_start'
        );
        $statement->execute(['tenant' => $restaurantId, 'today_start' => $todayStart]);
        $systemActor = [
            'id' => null,
            'full_name' => 'Système',
            'role_code' => 'system',
        ];
        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tid = (int) ($row['id'] ?? 0);
            $amount = $this->normalizeAmount($row['amount'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            $upd = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET amount_received = :amount_received,
                     received_by = NULL,
                     received_at = NOW(),
                     status = "RECU_CAISSE",
                     discrepancy_amount = 0,
                     discrepancy_note = NULL,
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :tenant AND status = "REMIS_A_CAISSE"'
            );
            $upd->execute([
                'amount_received' => $amount,
                'id' => $tid,
                'tenant' => $restaurantId,
            ]);
            if ($upd->rowCount() < 1) {
                continue;
            }
            $this->audit($restaurantId, $systemActor, 'cash_cashier_auto_received', 'cash_transfers', $tid, [
                'amount_received' => $amount,
                'automatic' => true,
            ], 'Reception caisse automatique au changement de jour (minuit, fuseau restaurant)');
            $count++;
        }

        return $count;
    }

    private function audit(int $restaurantId, array $actor, string $action, string $entityType, int $entityId, array $newValues, string $justification): void
    {
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? null,
            'actor_role_code' => $actor['role_code'] ?? null,
            'module_name' => 'cash',
            'action_name' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'new_values' => $newValues,
            'justification' => $justification,
        ]);
    }

    private function ensureSchema(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cash_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                from_user_id INT NULL,
                to_user_id INT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                amount_received DECIMAL(12,2) NULL,
                currency VARCHAR(10) NOT NULL DEFAULT "USD",
                source_type VARCHAR(60) NULL,
                source_id INT NULL,
                status VARCHAR(60) NOT NULL DEFAULT "EN_ATTENTE_REMISE",
                note TEXT NULL,
                discrepancy_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                discrepancy_note TEXT NULL,
                requested_at DATETIME NULL,
                received_at DATETIME NULL,
                validated_at DATETIME NULL,
                created_by INT NULL,
                received_by INT NULL,
                validated_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cash_transfers_restaurant (restaurant_id),
                INDEX idx_cash_transfers_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cash_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                movement_type VARCHAR(30) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT "USD",
                note TEXT NULL,
                source_type VARCHAR(60) NULL,
                source_id INT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_cash_movements_restaurant (restaurant_id),
                INDEX idx_cash_movements_type (movement_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensureCashPermissionAndRole();
    }

    private function ensureCashPermissionAndRole(): void
    {
        $pdo = $this->database->pdo();

        $permissionStatement = $pdo->prepare('SELECT id FROM permissions WHERE code = "cash.manage" LIMIT 1');
        $permissionStatement->execute();
        if ($permissionStatement->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO permissions (module_name, action_name, code, is_sensitive, created_at, updated_at)
                 VALUES ("cash", "manage", "cash.manage", 1, NOW(), NOW())'
            )->execute();
        }

        $roleStatement = $pdo->prepare('SELECT id FROM roles WHERE code = "cashier_accountant" AND scope = "system" LIMIT 1');
        $roleStatement->execute();
        if ($roleStatement->fetchColumn() === false) {
            $pdo->prepare(
                'INSERT INTO roles (restaurant_id, name, code, description, scope, is_locked, status, created_at, updated_at)
                 VALUES (NULL, "Caissier / comptable", "cashier_accountant", "Role predefini de caisse et comptabilite.", "system", 1, "active", NOW(), NOW())'
            )->execute();
        }
    }
}
