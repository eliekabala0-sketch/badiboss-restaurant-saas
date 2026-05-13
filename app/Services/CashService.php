<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

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
    public function periodCashClarity(int $restaurantId, string $dateFromYmd, string $dateToYmd, ?int $onlySaleRemittancesFromUserId = null): array
    {
        $this->ensureSchema();
        $periodWhere = $this->sqlCashTransferPeriodPredicate('ct');
        $scopeSql = '';
        $scopeParams = [];
        if ($onlySaleRemittancesFromUserId !== null && $onlySaleRemittancesFromUserId > 0) {
            $scopeSql = ' AND ct.source_type = "sale" AND ct.from_user_id = :scope_srv_uid';
            $scopeParams['scope_srv_uid'] = $onlySaleRemittancesFromUserId;
        }
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
               AND ' . $periodWhere
                . $scopeSql
                );
        $statement->execute(array_merge([
            'restaurant_id' => $restaurantId,
            'start_at' => $dateFromYmd . ' 00:00:00',
            'end_at' => $dateToYmd . ' 23:59:59',
            'dfrom' => $dateFromYmd,
            'dto' => $dateToYmd,
        ], $scopeParams));
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

        $saleTs = (string) ($sale['validated_at'] ?? $sale['created_at'] ?? '');
        $saleDayYmd = $this->mysqlDateTimeToYmd($restaurantId, $saleTs) ?? '';
        $remitDayYmd = (new DateTimeImmutable('now', $this->reportTimezone($restaurantId)))->format('Y-m-d');
        $lateBasis = null;
        if ($saleDayYmd !== '' && $remitDayYmd !== '' && $saleDayYmd !== $remitDayYmd) {
            $lateBasis = 'PENDING';
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO cash_transfers
            (restaurant_id, from_user_id, to_user_id, amount, currency, source_type, source_id, sale_day_ymd, remittance_day_ymd, late_remittance_basis, status, note, discrepancy_amount, discrepancy_note, requested_at, created_by, created_at, updated_at)
             VALUES
            (:restaurant_id, :from_user_id, :to_user_id, :amount, :currency, "sale", :source_id, :sale_day_ymd, :remittance_day_ymd, :late_remittance_basis, "REMIS_A_CAISSE", :note, 0, NULL, NOW(), :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'from_user_id' => $actor['id'],
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => $currency,
            'source_id' => (int) $sale['id'],
            'sale_day_ymd' => $saleDayYmd !== '' ? $saleDayYmd : null,
            'remittance_day_ymd' => $remitDayYmd,
            'late_remittance_basis' => $lateBasis,
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
            function (array $row): bool {
                $saleStatus = (string) ($row['sale_status'] ?? '');
                $transferId = (int) ($row['transfer_id'] ?? 0);
                $transferStatus = (string) ($row['transfer_status'] ?? '');
                $amount = (float) ($row['sale_total_amount'] ?? 0);
                if (!in_array($saleStatus, ['VALIDE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true) || $amount <= 0) {
                    return false;
                }
                if ($transferId <= 0) {
                    return true;
                }

                return !$this->isBlockingSaleRemittanceStatus($transferStatus);
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
                       ct.sale_day_ymd,
                       ct.remittance_day_ymd,
                       ct.late_remittance_basis,
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

    /**
     * Remises vente dont la date de vente et la date de remise diffèrent : rattachement comptable à trancher.
     *
     * @return list<array<string, mixed>>
     */
    public function listPendingLateRemittanceAttributions(int $restaurantId): array
    {
        $this->ensureSchema();
        $statement = $this->database->pdo()->prepare(
            'SELECT ct.*,
                    fu.full_name AS from_user_name,
                    tu.full_name AS to_user_name,
                    s.id AS sale_id,
                    s.total_amount AS sale_total_amount,
                    s.status AS sale_status,
                    s.validated_at AS sale_validated_at,
                    s.created_at AS sale_created_at,
                    sr.id AS server_request_id,
                    sr.service_reference,
                    su.full_name AS sale_server_name
             FROM cash_transfers ct
             LEFT JOIN users fu ON fu.id = ct.from_user_id
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
             LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
             LEFT JOIN users su ON su.id = s.server_id
             WHERE ct.restaurant_id = :restaurant_id
               AND ct.source_type = "sale"
               AND ct.late_remittance_basis = "PENDING"
             ORDER BY ct.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function decideLateRemittanceAttribution(int $restaurantId, int $transferId, string $basis, array $actor): void
    {
        $this->ensureSchema();
        $basis = strtoupper(trim($basis));
        if (!in_array($basis, ['SALE_DAY', 'REMITTANCE_DAY', 'RESOLUTION_DAY'], true)) {
            throw new \RuntimeException('Choix de rattachement invalide.');
        }
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['source_type'] ?? '') !== 'sale' || (string) ($transfer['late_remittance_basis'] ?? '') !== 'PENDING') {
            throw new \RuntimeException('Aucune remise tardive en attente sur ce transfert.');
        }
        $this->managerAssignRemittanceImputation($restaurantId, $transferId, $basis, $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingManagerSaleRemittances(int $restaurantId): array
    {
        $this->ensureSchema();
        $statement = $this->database->pdo()->prepare(
            'SELECT ct.*,
                    fu.full_name AS from_user_name,
                    tu.full_name AS to_user_name,
                    s.id AS sale_id,
                    s.total_amount AS sale_total_amount,
                    s.status AS sale_status,
                    sr.id AS server_request_id,
                    sr.service_reference,
                    su.full_name AS sale_server_name
             FROM cash_transfers ct
             LEFT JOIN users fu ON fu.id = ct.from_user_id
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
             LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
             LEFT JOIN users su ON su.id = s.server_id
             WHERE ct.restaurant_id = :restaurant_id
               AND ct.source_type = "sale"
               AND ct.status = "SOUMIS_GERANT"
             ORDER BY ct.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historique compact des remises vente (tous statuts) pour pilotage gérant / propriétaire.
     *
     * @return list<array<string, mixed>>
     */
    public function listSaleRemittanceHistory(int $restaurantId, int $limit = 40): array
    {
        $this->ensureSchema();
        $limit = max(1, min(120, $limit));
        $statement = $this->database->pdo()->prepare(
            'SELECT ct.*,
                    fu.full_name AS from_user_name,
                    tu.full_name AS to_user_name,
                    ru.full_name AS received_by_name,
                    vu.full_name AS validated_by_name,
                    s.id AS sale_id,
                    s.total_amount AS sale_total_amount,
                    s.status AS sale_status,
                    sr.id AS server_request_id,
                    sr.service_reference,
                    su.full_name AS sale_server_name
             FROM cash_transfers ct
             LEFT JOIN users fu ON fu.id = ct.from_user_id
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             LEFT JOIN users ru ON ru.id = ct.received_by
             LEFT JOIN users vu ON vu.id = ct.validated_by
             LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
             LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
             LEFT JOIN users su ON su.id = s.server_id
             WHERE ct.restaurant_id = :restaurant_id
               AND ct.source_type = "sale"
             ORDER BY ct.id DESC
             LIMIT ' . (string) $limit
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Transfert avec libellés pour le bloc résolution responsable.
     *
     * @return array<string, mixed>|null
     */
    public function findTransferForManagerResolution(int $restaurantId, int $transferId): ?array
    {
        $this->ensureSchema();
        $statement = $this->database->pdo()->prepare(
            'SELECT ct.*,
                    fu.full_name AS from_user_name,
                    tu.full_name AS to_user_name,
                    s.id AS sale_id,
                    s.total_amount AS sale_total_amount,
                    s.status AS sale_status,
                    sr.id AS server_request_id,
                    sr.service_reference,
                    su.full_name AS sale_server_name
             FROM cash_transfers ct
             LEFT JOIN users fu ON fu.id = ct.from_user_id
             LEFT JOIN users tu ON tu.id = ct.to_user_id
             LEFT JOIN sales s ON ct.source_type = "sale" AND s.id = ct.source_id
             LEFT JOIN server_requests sr ON s.origin_type = "server_request" AND sr.id = s.origin_id
             LEFT JOIN users su ON su.id = s.server_id
             WHERE ct.id = :id AND ct.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $transferId, 'restaurant_id' => $restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Rattachement comptable (vente / remise / résolution) pour remises vente.
     */
    public function managerAssignRemittanceImputation(int $restaurantId, int $transferId, string $basis, array $actor): void
    {
        $this->ensureSchema();
        $basis = strtoupper(trim($basis));
        if (!in_array($basis, ['SALE_DAY', 'REMITTANCE_DAY', 'RESOLUTION_DAY'], true)) {
            throw new \RuntimeException('Choix d imputation invalide.');
        }
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['source_type'] ?? '') !== 'sale') {
            throw new \RuntimeException('Imputation reservee aux remises liees a une vente.');
        }
        $saleId = (int) ($transfer['source_id'] ?? 0);
        $sale = $this->findSaleInRestaurant($saleId, $restaurantId);
        $saleTs = (string) ($sale['validated_at'] ?? $sale['created_at'] ?? '');
        $saleDay = $this->mysqlDateTimeToYmd($restaurantId, $saleTs) ?? '';
        $remitTs = (string) ($transfer['requested_at'] ?? $transfer['created_at'] ?? '');
        $remitDay = $this->mysqlDateTimeToYmd($restaurantId, $remitTs) ?? '';
        $today = (new DateTimeImmutable('now', $this->reportTimezone($restaurantId)))->format('Y-m-d');
        $saleDayYmd = $basis === 'SALE_DAY' ? ($saleDay !== '' ? $saleDay : null) : ((string) ($transfer['sale_day_ymd'] ?? '') ?: ($saleDay !== '' ? $saleDay : null));
        $remittanceDayYmd = $basis === 'REMITTANCE_DAY'
            ? ($remitDay !== '' ? $remitDay : $today)
            : ($basis === 'RESOLUTION_DAY' ? $today : ((string) ($transfer['remittance_day_ymd'] ?? '') ?: ($remitDay !== '' ? $remitDay : $today)));
        if ($basis === 'SALE_DAY') {
            $late = ($saleDay !== '' && $remitDay !== '' && $saleDay !== $remitDay) ? $basis : $basis;
        } elseif ($basis === 'REMITTANCE_DAY') {
            $late = $basis;
        } else {
            $late = 'RESOLUTION_DAY';
        }
        $stmt = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET sale_day_ymd = COALESCE(:sday, sale_day_ymd),
                 remittance_day_ymd = COALESCE(:rday, remittance_day_ymd),
                 late_remittance_basis = IF(late_remittance_basis = "PENDING", :basis_if_pending, :basis_norm),
                 note = TRIM(CONCAT(IFNULL(note, ""), " [Imputation responsable ", :basis_lbl, "]")),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :rid'
        );
        $stmt->execute([
            'sday' => $saleDayYmd,
            'rday' => $remittanceDayYmd,
            'basis_if_pending' => $late,
            'basis_norm' => $late,
            'basis_lbl' => $basis === 'SALE_DAY' ? 'jour vente' : ($basis === 'REMITTANCE_DAY' ? 'jour remise' : 'jour resolution'),
            'id' => $transferId,
            'rid' => $restaurantId,
        ]);
        $this->audit($restaurantId, $actor, 'cash_manager_imputation', 'cash_transfers', $transferId, [
            'basis' => $basis,
            'sale_day_ymd' => $saleDayYmd,
            'remittance_day_ymd' => $remittanceDayYmd,
        ], 'Imputation comptable (responsable)');
    }

    /**
     * Décisions gérant / propriétaire / super admin sur remise vente.
     *
     * @param array{
     *   decision?: string,
     *   reason?: string,
     *   amount_accepted?: float|int|string,
     *   imputation_basis?: string,
     *   grant_clemency?: string,
     *   clemency_reason?: string
     * } $payload
     */
    public function managerResolveSaleRemittance(int $restaurantId, int $transferId, array $payload, array $actor): void
    {
        $this->ensureSchema();
        $decision = strtoupper(trim((string) ($payload['decision'] ?? '')));
        $reason = trim((string) ($payload['reason'] ?? ''));
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['source_type'] ?? '') !== 'sale' || (int) ($transfer['source_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Resolution reservee aux remises liees a une vente.');
        }
        $status = (string) ($transfer['status'] ?? '');
        $role = (string) ($actor['role_code'] ?? '');
        $scope = (string) ($actor['scope'] ?? '');
        if ($status === 'EN_ATTENTE_PROPRIETAIRE') {
            if ($scope !== 'super_admin' && $role !== 'owner') {
                throw new \RuntimeException('Cette remise est en attente de decision proprietaire.');
            }
        } elseif ($status === 'SOUMIS_GERANT') {
            if ($decision !== 'SUBMIT_OWNER' && $scope !== 'super_admin' && $role !== 'manager' && $role !== 'owner') {
                throw new \RuntimeException('Decision caisse soumise : le gerant ou le proprietaire doit trancher.');
            }
        }

        $saleId = (int) ($transfer['source_id'] ?? 0);
        $sale = $this->findSaleInRestaurant($saleId, $restaurantId);
        $serverUserId = (int) ($sale['server_id'] ?? $transfer['from_user_id'] ?? 0);
        $amount = (float) ($transfer['amount'] ?? 0);
        $snapshot = $this->buildCashTransferOperationSnapshot($restaurantId, $transfer);
        $mr = Container::getInstance()->get('managerResolution');
        $mr->ensureResponsibleOutcomeColumns();
        $mr->assertCashTransferResolutionIdempotent($transfer, $decision, $actor);
        $oldState = [
            'transfer_status' => $status,
            'transfer_amount' => $amount,
            'responsible_outcome_code_before' => (string) ($transfer['responsible_outcome_code'] ?? ''),
        ];

        if ($decision === 'SUBMIT_OWNER') {
            if (!in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT', 'ECART_SIGNALE'], true)) {
                throw new \RuntimeException('Soumission proprietaire impossible sur ce statut.');
            }
            if ($reason === '') {
                throw new \RuntimeException('Motif obligatoire pour escalade proprietaire.');
            }
            $stmt = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET status = "EN_ATTENTE_PROPRIETAIRE",
                     note = TRIM(CONCAT(IFNULL(note, ""), " [Escalade proprietaire] ", :reason)),
                     validated_by = :vid,
                     validated_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :rid'
            );
            $stmt->execute([
                'reason' => $reason,
                'vid' => $actor['id'] ?? null,
                'id' => $transferId,
                'rid' => $restaurantId,
            ]);
            $this->audit($restaurantId, $actor, 'cash_remise_escalade_proprietaire', 'cash_transfers', $transferId, [
                'operation' => $snapshot,
                'reason' => $reason,
                'new_status' => 'EN_ATTENTE_PROPRIETAIRE',
            ], 'Remise vente soumise au proprietaire', $oldState);
            $mr->markCashTransferResponsibleOutcome(
                $restaurantId,
                $transferId,
                ManagerResolutionService::OUTCOME_ESCALADE_PROPRIETAIRE,
                $actor,
                array_merge($oldState, [
                    'new_status' => 'EN_ATTENTE_PROPRIETAIRE',
                    'motif' => $reason,
                ]),
            );

            return;
        } elseif ($decision === 'REJECT_FINAL') {
            if (!in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT', 'ECART_SIGNALE', 'EN_ATTENTE_PROPRIETAIRE'], true)) {
                throw new \RuntimeException('Rejet definitif impossible sur ce statut.');
            }
            if ($reason === '') {
                throw new \RuntimeException('Motif obligatoire pour rejet definitif.');
            }
            $stmt = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET status = "REMISE_REJETEE_GERANT",
                     discrepancy_note = :reason,
                     validated_by = :vid,
                     validated_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :rid'
            );
            $stmt->execute([
                'reason' => $reason,
                'vid' => $actor['id'] ?? null,
                'id' => $transferId,
                'rid' => $restaurantId,
            ]);
            $this->audit($restaurantId, $actor, 'cash_remise_rejet_definitif_gerant', 'cash_transfers', $transferId, [
                'operation' => $snapshot,
                'reason' => $reason,
                'new_status' => 'REMISE_REJETEE_GERANT',
            ], 'Rejet definitif remise (responsable)', $oldState);
            Container::getInstance()->get('managerResolution')->recordServerPayrollShortage(
                $restaurantId,
                $serverUserId,
                'cash_transfer',
                $transferId,
                $amount,
                $this->mysqlDateTimeToYmd($restaurantId, (string) ($sale['validated_at'] ?? $sale['created_at'] ?? '')),
                'reject_final',
                $reason,
                $payload['imputation_basis'] ?? null,
                [
                    ['label' => 'Remise vente rejetée (définitif)', 'sale_id' => $saleId],
                ],
                $actor,
            );
        } elseif ($decision === 'PARTIAL_ACCEPT' || $decision === 'PARTIAL') {
            if (!in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT', 'ECART_SIGNALE', 'EN_ATTENTE_PROPRIETAIRE'], true)) {
                throw new \RuntimeException('Acceptation partielle impossible sur ce statut.');
            }
            if ($reason === '') {
                throw new \RuntimeException('Motif obligatoire pour acceptation partielle.');
            }
            $accepted = $this->normalizeAmount($payload['amount_accepted'] ?? 0);
            if ($accepted <= 0 || $accepted > $amount + 0.0001) {
                throw new \RuntimeException('Montant accepte incoherent.');
            }
            $diff = round($amount - $accepted, 2);
            $stmt = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET status = "RECU_CAISSE",
                     amount_received = :ar,
                     received_by = :rid_by,
                     received_at = NOW(),
                     discrepancy_amount = :disc,
                     discrepancy_note = :n,
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :rid'
            );
            $stmt->execute([
                'ar' => $accepted,
                'rid_by' => $actor['id'] ?? null,
                'disc' => $diff > 0.0001 ? $diff : 0,
                'n' => $reason,
                'id' => $transferId,
                'rid' => $restaurantId,
            ]);
            $this->audit($restaurantId, $actor, 'cash_remise_partielle_responsable', 'cash_transfers', $transferId, [
                'amount_received' => $accepted,
                'difference' => $diff,
                'operation' => $snapshot,
                'new_status' => 'RECU_CAISSE',
            ], 'Acceptation partielle (responsable)', $oldState);
            if ($diff > 0.0001 && $serverUserId > 0) {
                Container::getInstance()->get('managerResolution')->recordServerPayrollShortage(
                    $restaurantId,
                    $serverUserId,
                    'cash_transfer',
                    $transferId,
                    $diff,
                    $this->mysqlDateTimeToYmd($restaurantId, (string) ($sale['validated_at'] ?? $sale['created_at'] ?? '')),
                    'partial_accept',
                    $reason,
                    $payload['imputation_basis'] ?? null,
                    [
                        ['label' => 'Ecart remise / reception partielle', 'sale_id' => $saleId, 'declared' => $amount, 'received' => $accepted],
                    ],
                    $actor,
                );
            }
        } elseif ($decision === 'RECEIVE_FULL' || $decision === 'VALIDER') {
            if (!in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT', 'ECART_SIGNALE', 'EN_ATTENTE_PROPRIETAIRE'], true)) {
                throw new \RuntimeException('Validation reception impossible sur ce statut.');
            }
            $stmt = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET status = "RECU_CAISSE",
                     amount_received = :amt,
                     received_by = :rid_by,
                     received_at = NOW(),
                     discrepancy_amount = 0,
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :rid'
            );
            $stmt->execute([
                'amt' => $amount,
                'rid_by' => $actor['id'] ?? null,
                'id' => $transferId,
                'rid' => $restaurantId,
            ]);
            $this->audit($restaurantId, $actor, 'cash_remise_recue_responsable', 'cash_transfers', $transferId, [
                'amount_received' => $amount,
                'operation' => $snapshot,
                'new_status' => 'RECU_CAISSE',
            ], 'Reception caisse validee par responsable', $oldState);
        } else {
            throw new \RuntimeException('Decision invalide.');
        }

        $imb = trim((string) ($payload['imputation_basis'] ?? ''));
        if ($imb !== '') {
            $this->managerAssignRemittanceImputation($restaurantId, $transferId, $imb, $actor);
        }

        if ($decision === 'SUBMIT_OWNER') {
            return;
        }

        $transferFresh = $this->findTransferInRestaurant($transferId, $restaurantId);
        $stNew = (string) ($transferFresh['status'] ?? '');
        $outcome = match ($decision) {
            'REJECT_FINAL' => ManagerResolutionService::OUTCOME_REJET_GERANT,
            'PARTIAL_ACCEPT', 'PARTIAL' => ManagerResolutionService::OUTCOME_PARTIEL_GERANT,
            default => ManagerResolutionService::OUTCOME_VALIDE_GERANT,
        };
        $mr->markCashTransferResponsibleOutcome(
            $restaurantId,
            $transferId,
            $outcome,
            $actor,
            [
                'old_status' => $status,
                'new_status' => $stNew,
                'declared_amount' => $amount,
                'amount_received' => (float) ($transferFresh['amount_received'] ?? 0),
                'discrepancy_amount' => (float) ($transferFresh['discrepancy_amount'] ?? 0),
                'sale_id' => $saleId,
                'imputation_basis' => $imb,
            ],
        );
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? null,
            'actor_role_code' => $actor['role_code'] ?? null,
            'module_name' => 'manager_resolution',
            'action_name' => 'cash_transfer_responsible_terminal',
            'entity_type' => 'cash_transfers',
            'entity_id' => (string) $transferId,
            'old_values' => $oldState,
            'new_values' => [
                'outcome' => $outcome,
                'transfer_status_after' => $stNew,
            ],
            'justification' => $reason !== '' ? $reason : 'Decision responsable remise vente',
        ]);

        $grant = ($payload['grant_clemency'] ?? '') === '1' || ($payload['grant_clemency'] ?? '') === 'on';
        if ($grant) {
            $cr = trim((string) ($payload['clemency_reason'] ?? ''));
            Container::getInstance()->get('staffDiscipline')->grantDisciplinaryClemency(
                $restaurantId,
                $serverUserId,
                $cr,
                $actor,
                ['cash_transfer_id' => $transferId, 'decision' => $decision],
            );
        } else {
            if ($serverUserId > 0) {
                Container::getInstance()->get('staffDiscipline')->recordManagerRegularizationPreservesPenalty(
                    $restaurantId,
                    $serverUserId,
                    $snapshot,
                    $decision,
                );
            }
        }
    }

    public function rejectSaleRemittanceByCashier(int $restaurantId, int $transferId, string $reason, array $actor): void
    {
        $this->ensureSchema();
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif de rejet obligatoire.');
        }
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['source_type'] ?? '') !== 'sale' || (int) ($transfer['source_id'] ?? 0) <= 0) {
            throw new \RuntimeException('Cette action ne concerne que les remises liees a une vente.');
        }
        if ((string) $transfer['status'] !== 'REMIS_A_CAISSE') {
            throw new \RuntimeException('Seule une remise en attente de caisse peut etre rejetee ainsi.');
        }
        $snapshot = $this->buildCashTransferOperationSnapshot($restaurantId, $transfer);
        $stmt = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET status = "REMISE_REJETEE_CAISSE",
                 discrepancy_note = :reason,
                 validated_by = :validated_by,
                 validated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id AND status = "REMIS_A_CAISSE"'
        );
        $stmt->execute([
            'reason' => $reason,
            'validated_by' => $actor['id'],
            'id' => $transferId,
            'restaurant_id' => $restaurantId,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('Rejet impossible (statut change ou transfert introuvable).');
        }
        $this->audit($restaurantId, $actor, 'cash_remise_rejetee_caisse', 'cash_transfers', $transferId, [
            'old_status' => 'REMIS_A_CAISSE',
            'new_status' => 'REMISE_REJETEE_CAISSE',
            'operation' => $snapshot,
            'reason' => $reason,
        ], 'Rejet remise serveur par la caisse');
    }

    public function submitSaleRemittanceToManager(int $restaurantId, int $transferId, string $reason, array $actor): void
    {
        $this->ensureSchema();
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif de soumission obligatoire.');
        }
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) ($transfer['source_type'] ?? '') !== 'sale') {
            throw new \RuntimeException('Soumission reservee aux remises vente.');
        }
        if ((string) $transfer['status'] !== 'REMIS_A_CAISSE') {
            throw new \RuntimeException('Soumission impossible sur ce statut.');
        }
        $snapshot = $this->buildCashTransferOperationSnapshot($restaurantId, $transfer);
        $stmt = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET status = "SOUMIS_GERANT",
                 note = TRIM(CONCAT(IFNULL(note, ""), " [Soumission gérant caisse] ", :reason)),
                 validated_by = :validated_by,
                 validated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id AND status = "REMIS_A_CAISSE"'
        );
        $stmt->execute([
            'reason' => $reason,
            'validated_by' => $actor['id'],
            'id' => $transferId,
            'restaurant_id' => $restaurantId,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('Soumission impossible (conflit de statut).');
        }
        $this->audit($restaurantId, $actor, 'cash_remise_soumise_gerant', 'cash_transfers', $transferId, [
            'old_status' => 'REMIS_A_CAISSE',
            'new_status' => 'SOUMIS_GERANT',
            'operation' => $snapshot,
            'reason' => $reason,
        ], 'Remise soumise au gerant pour decision');
    }

    public function managerDecideSaleRemittance(int $restaurantId, int $transferId, string $decision, string $reason, array $actor): void
    {
        $this->ensureSchema();
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif obligatoire pour la decision gerant.');
        }
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['VALIDER', 'REJETER'], true)) {
            throw new \RuntimeException('Decision gerant invalide.');
        }
        $transfer = $this->findTransferInRestaurant($transferId, $restaurantId);
        if ((string) $transfer['status'] !== 'SOUMIS_GERANT') {
            throw new \RuntimeException('Aucune soumission gerant en attente sur ce transfert.');
        }
        $snapshot = $this->buildCashTransferOperationSnapshot($restaurantId, $transfer);
        if ($decision === 'VALIDER') {
            $stmt = $this->database->pdo()->prepare(
                'UPDATE cash_transfers
                 SET status = "RECU_CAISSE",
                     amount_received = amount,
                     received_by = :received_by,
                     received_at = NOW(),
                     note = TRIM(CONCAT(IFNULL(note, ""), " [Validation gérant] ", :reason)),
                     updated_at = NOW()
                 WHERE id = :id AND restaurant_id = :restaurant_id AND status = "SOUMIS_GERANT"'
            );
            $stmt->execute([
                'received_by' => $actor['id'],
                'reason' => $reason,
                'id' => $transferId,
                'restaurant_id' => $restaurantId,
            ]);
            if ($stmt->rowCount() < 1) {
                throw new \RuntimeException('Validation gerant impossible.');
            }
            $this->audit($restaurantId, $actor, 'cash_remise_validee_gerant', 'cash_transfers', $transferId, [
                'old_status' => 'SOUMIS_GERANT',
                'new_status' => 'RECU_CAISSE',
                'operation' => $snapshot,
                'reason' => $reason,
            ], 'Validation gerant d une remise soumise par la caisse');
            return;
        }

        $stmt = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET status = "REMISE_REJETEE_GERANT",
                 discrepancy_note = :reason,
                 updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id AND status = "SOUMIS_GERANT"'
        );
        $stmt->execute([
            'reason' => $reason,
            'id' => $transferId,
            'restaurant_id' => $restaurantId,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('Rejet gerant impossible.');
        }
        $this->audit($restaurantId, $actor, 'cash_remise_rejetee_gerant', 'cash_transfers', $transferId, [
            'old_status' => 'SOUMIS_GERANT',
            'new_status' => 'REMISE_REJETEE_GERANT',
            'operation' => $snapshot,
            'reason' => $reason,
        ], 'Rejet gerant d une remise soumise par la caisse');
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

    /**
     * Reclassement d’un mouvement de caisse déjà enregistré (pas de suppression) — audit obligatoire.
     *
     * @param array{movement_type:string, reason?:string} $payload
     */
    public function managerReclassifyMovement(int $restaurantId, int $movementId, array $payload, array $actor): void
    {
        $this->ensureSchema();
        $newType = strtoupper(trim((string) ($payload['movement_type'] ?? '')));
        if (!in_array($newType, ['ENTREE', 'SORTIE', 'DEPENSE', 'AJUSTEMENT'], true)) {
            throw new \RuntimeException('Type de mouvement cible invalide.');
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('Motif de reclassement obligatoire (traçabilité).');
        }
        $st = $this->database->pdo()->prepare(
            'SELECT * FROM cash_movements WHERE id = :id AND restaurant_id = :rid LIMIT 1'
        );
        $st->execute(['id' => $movementId, 'rid' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Mouvement introuvable.');
        }
        $oldType = (string) ($row['movement_type'] ?? '');
        if ($oldType === $newType) {
            throw new \RuntimeException('Le type est déjà identique : aucun reclassement.');
        }
        $oldNote = (string) ($row['note'] ?? '');
        $tag = '[Reclassement responsable ' . date('Y-m-d H:i') . ' : ' . $oldType . ' → ' . $newType . ' — ' . $reason . ']';
        $newNote = trim($oldNote . ' ' . $tag);
        $upd = $this->database->pdo()->prepare(
            'UPDATE cash_movements SET movement_type = :mt, note = :note, updated_at = NOW()
             WHERE id = :id AND restaurant_id = :rid'
        );
        $upd->execute(['mt' => $newType, 'note' => $newNote !== '' ? $newNote : null, 'id' => $movementId, 'rid' => $restaurantId]);
        $this->audit($restaurantId, $actor, 'cash_movement_reclassified', 'cash_movements', $movementId, [
            'previous_movement_type' => $oldType,
            'new_movement_type' => $newType,
            'reason' => $reason,
        ], 'Reclassement mouvement caisse (responsable)');
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
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $sql .= ' AND ' . $this->sqlCashTransferPeriodPredicate('ct');
            $params['start_at'] = (string) $filters['date_from'] . ' 00:00:00';
            $params['end_at'] = (string) $filters['date_to'] . ' 23:59:59';
            $params['dfrom'] = (string) $filters['date_from'];
            $params['dto'] = (string) $filters['date_to'];
        } else {
            if (!empty($filters['date_from'])) {
                $sql .= ' AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) >= :date_from';
                $params['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
            }
            if (!empty($filters['date_to'])) {
                $sql .= ' AND COALESCE(ct.received_at, ct.requested_at, ct.created_at) <= :date_to';
                $params['date_to'] = (string) $filters['date_to'] . ' 23:59:59';
            }
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

        $scopeSrv = (int) ($filters['scope_closed_sales_server_user_id'] ?? 0);
        if ($scopeSrv > 0 && !empty($filters['date_from']) && !empty($filters['date_to'])) {
            $soldStatement = $this->database->pdo()->prepare(
                'SELECT COALESCE(SUM(total_amount), 0) FROM sales
                 WHERE restaurant_id = :restaurant_id AND server_id = :srv
                   AND status IN ("VALIDE", "CLOTURE", "VENDU_TOTAL", "VENDU_PARTIEL")
                   AND COALESCE(validated_at, created_at) >= :sfrom
                   AND COALESCE(validated_at, created_at) <= :sto'
            );
            $soldStatement->execute([
                'restaurant_id' => $restaurantId,
                'srv' => $scopeSrv,
                'sfrom' => (string) $filters['date_from'] . ' 00:00:00',
                'sto' => (string) $filters['date_to'] . ' 23:59:59',
            ]);
            $soldTotal = (float) $soldStatement->fetchColumn();
        } else {
            $soldStatement = $this->database->pdo()->prepare(
                'SELECT COALESCE(SUM(total_amount), 0) FROM sales
                 WHERE restaurant_id = :restaurant_id
                   AND status IN ("VALIDE", "CLOTURE", "VENDU_TOTAL", "VENDU_PARTIEL")'
            );
            $soldStatement->execute(['restaurant_id' => $restaurantId]);
            $soldTotal = (float) $soldStatement->fetchColumn();
        }

        $totalRemittedToCash = 0.0;
        $totalReceivedByCash = 0.0;
        $totalToManager = 0.0;
        $totalToOwner = 0.0;
        $totalDiscrepancies = 0.0;
        foreach ($transfers as $transfer) {
            $status = (string) ($transfer['status'] ?? '');
            $amount = (float) ($transfer['amount'] ?? 0);
            $amountReceived = (float) ($transfer['amount_received'] ?? $amount);
            if (in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'RECU_CAISSE', 'ECART_SIGNALE', 'REMIS_A_GERANT', 'RECU_GERANT', 'REMIS_A_PROPRIETAIRE', 'RECU_PROPRIETAIRE'], true)) {
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
            'SELECT status
             FROM cash_transfers
             WHERE restaurant_id = :restaurant_id
               AND source_type = "sale"
               AND source_id = :sale_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'sale_id' => $saleId,
        ]);
        $status = $statement->fetchColumn();
        if ($status !== false && $this->isBlockingSaleRemittanceStatus((string) $status)) {
            throw new \RuntimeException('Cette vente a deja une remise en cours ou validee.');
        }
    }

    private function isBlockingSaleRemittanceStatus(string $status): bool
    {
        return in_array($status, [
            'REMIS_A_CAISSE',
            'SOUMIS_GERANT',
            'EN_ATTENTE_PROPRIETAIRE',
            'RECU_CAISSE',
            'ECART_SIGNALE',
        ], true);
    }

    /**
     * @param array<string, mixed> $transfer Row cash_transfers (+ optional joined names from listTransfers)
     * @return array<string, mixed>
     */
    private function buildCashTransferOperationSnapshot(int $restaurantId, array $transfer): array
    {
        $saleId = 0;
        if ((string) ($transfer['source_type'] ?? '') === 'sale') {
            $saleId = (int) ($transfer['source_id'] ?? 0);
        }

        $snapshot = [
            'cash_transfer_id' => (int) ($transfer['id'] ?? 0),
            'reference' => 'CT-' . (string) ((int) ($transfer['id'] ?? 0)),
            'source_type' => (string) ($transfer['source_type'] ?? ''),
            'source_id' => (int) ($transfer['source_id'] ?? 0),
            'status_at_action' => (string) ($transfer['status'] ?? ''),
            'amount' => (float) ($transfer['amount'] ?? 0),
            'currency' => (string) ($transfer['currency'] ?? restaurant_currency($restaurantId)),
            'from_user_id' => $transfer['from_user_id'] !== null && $transfer['from_user_id'] !== '' ? (int) $transfer['from_user_id'] : null,
            'to_user_id' => $transfer['to_user_id'] !== null && $transfer['to_user_id'] !== '' ? (int) $transfer['to_user_id'] : null,
            'from_user_name' => $transfer['from_user_name'] ?? null,
            'to_user_name' => $transfer['to_user_name'] ?? null,
            'requested_at' => $transfer['requested_at'] ?? null,
            'created_at' => $transfer['created_at'] ?? null,
            'sale' => null,
            'sale_lines' => [],
        ];

        if ($saleId <= 0) {
            return $snapshot;
        }

        $sale = $this->findSaleInRestaurant($saleId, $restaurantId);
        $ref = 'Vente #' . $saleId;
        if ((string) ($sale['origin_type'] ?? '') === 'server_request' && (int) ($sale['origin_id'] ?? 0) > 0) {
            $srId = (int) $sale['origin_id'];
            $srStmt = $this->database->pdo()->prepare(
                'SELECT id, service_reference, status FROM server_requests WHERE id = :id AND restaurant_id = :restaurant_id LIMIT 1'
            );
            $srStmt->execute(['id' => $srId, 'restaurant_id' => $restaurantId]);
            $sr = $srStmt->fetch(PDO::FETCH_ASSOC);
            if ($sr !== false) {
                $ref .= ' · demande #' . $srId . ' ' . trim((string) ($sr['service_reference'] ?? ''));
            }
        }

        $itemsStatement = $this->database->pdo()->prepare(
            'SELECT si.id, si.menu_item_id, si.quantity, si.unit_price, si.status, mi.name AS menu_item_name
             FROM sale_items si
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $itemsStatement->execute(['sale_id' => $saleId]);
        $lines = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);

        $snapshot['reference'] = trim($ref);
        $snapshot['sale'] = [
            'sale_id' => $saleId,
            'total_amount' => (float) ($sale['total_amount'] ?? 0),
            'status' => (string) ($sale['status'] ?? ''),
            'origin_type' => (string) ($sale['origin_type'] ?? ''),
            'origin_id' => (int) ($sale['origin_id'] ?? 0),
        ];
        $snapshot['sale_lines'] = array_map(static function (array $row): array {
            return [
                'sale_item_id' => (int) ($row['id'] ?? 0),
                'menu_item_name' => (string) ($row['menu_item_name'] ?? ''),
                'quantity' => (float) ($row['quantity'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'line_status' => (string) ($row['status'] ?? ''),
            ];
        }, $lines);

        return $snapshot;
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
    /**
     * Désactivé : la caisse ne doit plus marquer « reçu » sans action explicite du caissier.
     * Les remises REMIS_A_CAISSE d’un jour passé restent visibles dans la file « À régulariser ».
     */
    public function reconcileOverdueCashierReceipts(int $restaurantId): int
    {
        return 0;
    }

    private function audit(int $restaurantId, array $actor, string $action, string $entityType, int $entityId, array $newValues, string $justification, ?array $oldValues = null): void
    {
        $log = [
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
        ];
        if ($oldValues !== null && $oldValues !== []) {
            $log['old_values'] = $oldValues;
        }
        Container::getInstance()->get('audit')->log($log);
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

        $this->ensureCashTransferAttributionColumns();
        $this->ensureCashPermissionAndRole();
    }

    /**
     * Période [:start_at,:end_at] (datetime) et [:dfrom,:dto] (Y-m-d inclus) pour rattachement des remises vente.
     */
    private function sqlCashTransferPeriodPredicate(string $alias): string
    {
        return '(('
            . '(' . $alias . '.source_type <> "sale" OR ' . $alias . '.source_type IS NULL)'
            . ' AND COALESCE(' . $alias . '.received_at, ' . $alias . '.requested_at, ' . $alias . '.created_at) >= :start_at'
            . ' AND COALESCE(' . $alias . '.received_at, ' . $alias . '.requested_at, ' . $alias . '.created_at) <= :end_at)'
            . ' OR ('
            . $alias . '.source_type = "sale"'
            . ' AND NOT IFNULL(' . $alias . '.late_remittance_basis, "") = "PENDING"'
            . ' AND ('
            . '(' . $alias . '.late_remittance_basis = "SALE_DAY" AND ' . $alias . '.sale_day_ymd IS NOT NULL AND ' . $alias . '.sale_day_ymd >= :dfrom AND ' . $alias . '.sale_day_ymd <= :dto)'
            . ' OR (' . $alias . '.late_remittance_basis = "REMITTANCE_DAY" AND ' . $alias . '.remittance_day_ymd IS NOT NULL AND ' . $alias . '.remittance_day_ymd >= :dfrom AND ' . $alias . '.remittance_day_ymd <= :dto)'
            . ' OR (' . $alias . '.late_remittance_basis = "RESOLUTION_DAY" AND ' . $alias . '.remittance_day_ymd IS NOT NULL AND ' . $alias . '.remittance_day_ymd >= :dfrom AND ' . $alias . '.remittance_day_ymd <= :dto)'
            . ' OR ('
            . '(' . $alias . '.late_remittance_basis IS NULL OR ' . $alias . '.late_remittance_basis = "")'
            . ' AND ' . $alias . '.sale_day_ymd IS NOT NULL AND ' . $alias . '.remittance_day_ymd IS NOT NULL AND ' . $alias . '.sale_day_ymd = ' . $alias . '.remittance_day_ymd'
            . ' AND ' . $alias . '.sale_day_ymd >= :dfrom AND ' . $alias . '.sale_day_ymd <= :dto)'
            . ' OR ('
            . 'COALESCE(' . $alias . '.received_at, ' . $alias . '.requested_at, ' . $alias . '.created_at) >= :start_at'
            . ' AND COALESCE(' . $alias . '.received_at, ' . $alias . '.requested_at, ' . $alias . '.created_at) <= :end_at'
            . ' AND NOT (' . $alias . '.late_remittance_basis IN ("SALE_DAY", "REMITTANCE_DAY", "RESOLUTION_DAY")'
            . ' OR ('
            . '(' . $alias . '.late_remittance_basis IS NULL OR ' . $alias . '.late_remittance_basis = "")'
            . ' AND ' . $alias . '.sale_day_ymd IS NOT NULL AND ' . $alias . '.remittance_day_ymd IS NOT NULL AND ' . $alias . '.sale_day_ymd = ' . $alias . '.remittance_day_ymd'
            . '))))))';
    }

    private function reportTimezone(int $restaurantId): DateTimeZone
    {
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $timezoneName = (string) ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? config('app.timezone', 'Africa/Lagos'));
        try {
            return new DateTimeZone($timezoneName);
        } catch (Throwable) {
            return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
        }
    }

    private function mysqlDateTimeToYmd(int $restaurantId, string $mysqlDatetime): ?string
    {
        $mysqlDatetime = trim($mysqlDatetime);
        if ($mysqlDatetime === '') {
            return null;
        }
        $tz = $this->reportTimezone($restaurantId);
        try {
            return (new DateTimeImmutable($mysqlDatetime, $tz))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureCashTransferAttributionColumns(): void
    {
        $pdo = $this->database->pdo();
        try {
            $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($db) || $db === '') {
                return;
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :s AND TABLE_NAME = "cash_transfers" AND COLUMN_NAME = "sale_day_ymd"'
            );
            $st->execute(['s' => $db]);
            if ((int) $st->fetchColumn() > 0) {
                return;
            }
        } catch (Throwable) {
            return;
        }
        try {
            $pdo->exec('ALTER TABLE cash_transfers ADD COLUMN sale_day_ymd DATE NULL AFTER source_id');
            $pdo->exec('ALTER TABLE cash_transfers ADD COLUMN remittance_day_ymd DATE NULL AFTER sale_day_ymd');
            $pdo->exec('ALTER TABLE cash_transfers ADD COLUMN late_remittance_basis VARCHAR(24) NULL AFTER remittance_day_ymd');
        } catch (Throwable) {
            // Colonnes déjà présentes ou environnement sans ALTER.
        }
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
