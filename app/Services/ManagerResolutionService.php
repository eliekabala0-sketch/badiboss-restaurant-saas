<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

/**
 * Résolution des arriérés par responsable (gérant / propriétaire / super admin).
 * Ne modifie pas les liens ?focus= (affichage géré côté vue / JS existant).
 */
final class ManagerResolutionService
{
    /** @see responsible_outcome_label() */
    public const OUTCOME_VALIDE_GERANT = 'VALIDE_GERANT';

    public const OUTCOME_CLOTURE_GERANT = 'CLOTURE_GERANT';

    public const OUTCOME_MANQUANT_GERANT = 'MANQUANT_GERANT';

    public const OUTCOME_REJET_GERANT = 'REJET_GERANT';

    public const OUTCOME_PARTIEL_GERANT = 'PARTIEL_GERANT';

    public const OUTCOME_FORCE_CAISSE_GERANT = 'FORCE_CAISSE_GERANT';

    public const OUTCOME_ESCALADE_PROPRIETAIRE = 'ESCALADE_PROPRIETAIRE';

    public function __construct(private readonly Database $database)
    {
    }

    public static function actorCanResolve(?array $user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        if (($user['scope'] ?? '') === 'super_admin') {
            return true;
        }

        return in_array((string) ($user['role_code'] ?? ''), ['owner', 'manager'], true);
    }

    public function ensureShortageSchema(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS server_payroll_shortages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                server_user_id INT NOT NULL,
                source_kind VARCHAR(40) NOT NULL,
                source_id INT NOT NULL,
                articles_json TEXT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                origin_day_ymd DATE NULL,
                decision_code VARCHAR(80) NOT NULL,
                decision_note TEXT NULL,
                state VARCHAR(32) NOT NULL DEFAULT "A_RETENIR",
                imputation_basis VARCHAR(24) NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_sps_restaurant (restaurant_id),
                KEY idx_sps_server (restaurant_id, server_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @param list<array<string, mixed>>|null $articles
     */
    public function recordServerPayrollShortage(
        int $restaurantId,
        int $serverUserId,
        string $sourceKind,
        int $sourceId,
        float $amount,
        ?string $originDayYmd,
        string $decisionCode,
        string $decisionNote,
        ?string $imputationBasis,
        ?array $articles,
        array $actor,
    ): int {
        $this->ensureShortageSchema();
        $pdo = $this->database->pdo();
        $dupCk = $pdo->prepare(
            'SELECT id FROM server_payroll_shortages
             WHERE restaurant_id = :rid AND source_kind = :sk AND source_id = :sid
             LIMIT 1'
        );
        $dupCk->execute(['rid' => $restaurantId, 'sk' => $sourceKind, 'sid' => $sourceId]);
        $existingId = $dupCk->fetchColumn();
        if ($existingId !== false && is_numeric($existingId)) {
            return (int) $existingId;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO server_payroll_shortages
            (restaurant_id, server_user_id, source_kind, source_id, articles_json, amount, origin_day_ymd, decision_code, decision_note, state, imputation_basis, created_by, created_at, updated_at)
             VALUES
            (:rid, :uid, :sk, :sid, :art, :amt, :orig, :dcode, :dnote, "A_RETENIR", :imb, :cb, NOW(), NOW())'
        );
        $stmt->execute([
            'rid' => $restaurantId,
            'uid' => $serverUserId,
            'sk' => $sourceKind,
            'sid' => $sourceId,
            'art' => $articles !== null ? json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'amt' => round($amount, 2),
            'orig' => $originDayYmd,
            'dcode' => $decisionCode,
            'dnote' => $decisionNote !== '' ? $decisionNote : null,
            'imb' => $imputationBasis,
            'cb' => $actor['id'] ?? null,
        ]);
        $id = (int) $this->database->pdo()->lastInsertId();
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? null,
            'actor_role_code' => $actor['role_code'] ?? null,
            'module_name' => 'manager_resolution',
            'action_name' => 'server_payroll_shortage_recorded',
            'entity_type' => 'server_payroll_shortages',
            'entity_id' => (string) $id,
            'new_values' => [
                'server_user_id' => $serverUserId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'amount' => round($amount, 2),
                'decision_code' => $decisionCode,
            ],
            'justification' => $decisionNote,
        ]);

        return $id;
    }

    /**
     * Colonnes optionnelles d’état terminal (migration douce, sans DROP).
     */
    public function ensureResponsibleOutcomeColumns(): void
    {
        $pdo = $this->database->pdo();
        foreach ($this->outcomeColumnStatements() as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable) {
            }
        }
    }

    /** @return list<string> */
    private function outcomeColumnStatements(): array
    {
        return [
            'ALTER TABLE server_requests ADD COLUMN responsible_outcome_code VARCHAR(40) NULL',
            'ALTER TABLE server_requests ADD COLUMN responsible_outcome_at DATETIME NULL',
            'ALTER TABLE server_requests ADD COLUMN responsible_outcome_by BIGINT UNSIGNED NULL',
            'ALTER TABLE server_requests ADD COLUMN responsible_outcome_detail TEXT NULL',
            'ALTER TABLE cash_transfers ADD COLUMN responsible_outcome_code VARCHAR(40) NULL',
            'ALTER TABLE cash_transfers ADD COLUMN responsible_outcome_at DATETIME NULL',
            'ALTER TABLE cash_transfers ADD COLUMN responsible_outcome_by BIGINT UNSIGNED NULL',
            'ALTER TABLE cash_transfers ADD COLUMN responsible_outcome_detail TEXT NULL',
        ];
    }

    public function serverRequestHasResponsibleOutcome(int $restaurantId, int $requestId): bool
    {
        $this->ensureResponsibleOutcomeColumns();
        $st = $this->database->pdo()->prepare(
            'SELECT responsible_outcome_code FROM server_requests WHERE id = :id AND restaurant_id = :rid LIMIT 1'
        );
        $st->execute(['id' => $requestId, 'rid' => $restaurantId]);
        $v = $st->fetchColumn();

        return is_string($v) && trim($v) !== '';
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function markServerRequestResponsibleOutcome(
        int $restaurantId,
        int $requestId,
        string $outcomeCode,
        array $actor,
        array $detail,
    ): void {
        $this->ensureResponsibleOutcomeColumns();
        $stmt = $this->database->pdo()->prepare(
            'UPDATE server_requests
             SET responsible_outcome_code = :code,
                 responsible_outcome_at = NOW(),
                 responsible_outcome_by = :uid,
                 responsible_outcome_detail = :det
             WHERE id = :id AND restaurant_id = :rid'
        );
        $stmt->execute([
            'code' => $outcomeCode,
            'uid' => $actor['id'] ?? null,
            'det' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $requestId,
            'rid' => $restaurantId,
        ]);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function markCashTransferResponsibleOutcome(
        int $restaurantId,
        int $transferId,
        string $outcomeCode,
        array $actor,
        array $detail,
    ): void {
        $this->ensureResponsibleOutcomeColumns();
        $stmt = $this->database->pdo()->prepare(
            'UPDATE cash_transfers
             SET responsible_outcome_code = :code,
                 responsible_outcome_at = NOW(),
                 responsible_outcome_by = :uid,
                 responsible_outcome_detail = :det
             WHERE id = :id AND restaurant_id = :rid'
        );
        $stmt->execute([
            'code' => $outcomeCode,
            'uid' => $actor['id'] ?? null,
            'det' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $transferId,
            'rid' => $restaurantId,
        ]);
    }

    /**
     * @param array<string, mixed> $transfer ligne cash_transfers
     */
    public function assertCashTransferResolutionIdempotent(array $transfer, string $decisionUpper, array $actor): void
    {
        $outcome = trim((string) ($transfer['responsible_outcome_code'] ?? ''));
        $status = (string) ($transfer['status'] ?? '');
        $role = (string) ($actor['role_code'] ?? '');
        $scope = (string) ($actor['scope'] ?? '');
        $ownerActor = $role === 'owner' || $scope === 'super_admin';

        if ($decisionUpper === 'SUBMIT_OWNER' && $outcome === self::OUTCOME_ESCALADE_PROPRIETAIRE) {
            throw new \RuntimeException('Cette remise a deja ete envoyee au proprietaire.');
        }

        $financialTerminal = in_array(
            $outcome,
            [self::OUTCOME_VALIDE_GERANT, self::OUTCOME_PARTIEL_GERANT, self::OUTCOME_REJET_GERANT, self::OUTCOME_FORCE_CAISSE_GERANT],
            true,
        );
        if ($financialTerminal) {
            throw new \RuntimeException('Cette remise a deja ete tranchee par un responsable.');
        }

        if ($outcome === self::OUTCOME_ESCALADE_PROPRIETAIRE
            && $status === 'EN_ATTENTE_PROPRIETAIRE'
            && !$ownerActor
            && in_array($decisionUpper, ['RECEIVE_FULL', 'VALIDER', 'PARTIAL_ACCEPT', 'PARTIAL', 'REJECT_FINAL'], true)) {
            throw new \RuntimeException('Decision reservee au proprietaire sur cette remise.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecentResponsibleDecisions(int $restaurantId, int $limit = 12): array
    {
        $this->ensureResponsibleOutcomeColumns();
        $lim = max(1, min(40, $limit));
        $pdo = $this->database->pdo();
        $st1 = $pdo->prepare(
            'SELECT "commande" AS row_kind, sr.id AS ref_id, sr.server_id AS agent_uid,
                sr.responsible_outcome_code AS outcome_code, sr.responsible_outcome_at AS decided_at,
                u.full_name AS agent_label, COALESCE(sr.total_supplied_amount, sr.total_requested_amount, 0) AS amount_hint,
                NULL AS sale_id, sr.status AS raw_status, NULL AS transfer_status
            FROM server_requests sr
            INNER JOIN users u ON u.id = sr.server_id
            WHERE sr.restaurant_id = :rid AND sr.responsible_outcome_at IS NOT NULL
            ORDER BY sr.responsible_outcome_at DESC
            LIMIT ' . (int) $lim
        );
        $st1->execute(['rid' => $restaurantId]);
        $a = $st1->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $st2 = $pdo->prepare(
            'SELECT "caisse" AS row_kind, ct.id AS ref_id, COALESCE(s.server_id, ct.from_user_id, 0) AS agent_uid,
                ct.responsible_outcome_code AS outcome_code, ct.responsible_outcome_at AS decided_at,
                COALESCE(us.full_name, "—") AS agent_label, ct.amount AS amount_hint,
                ct.source_id AS sale_id, NULL AS raw_status, ct.status AS transfer_status
            FROM cash_transfers ct
            LEFT JOIN sales s ON s.id = ct.source_id AND s.restaurant_id = ct.restaurant_id
            LEFT JOIN users us ON us.id = COALESCE(s.server_id, ct.from_user_id)
            WHERE ct.restaurant_id = :rid AND ct.source_type = "sale" AND ct.responsible_outcome_at IS NOT NULL
            ORDER BY ct.responsible_outcome_at DESC
            LIMIT ' . (int) $lim
        );
        $st2->execute(['rid' => $restaurantId]);
        $b = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $merged = array_merge($a, $b);
        usort($merged, static function (array $x, array $y): int {
            return strcmp((string) ($y['decided_at'] ?? ''), (string) ($x['decided_at'] ?? ''));
        });

        return array_slice($merged, 0, $lim);
    }

    /**
     * Contexte affiché dans le bloc « Résolution responsable » (GET avec ?focus=).
     *
     * @return array<string, mixed>|null
     */
    public function buildPanelContext(int $restaurantId, string $focusKind, int $focusId, ?array $actor): ?array
    {
        if (!self::actorCanResolve($actor)) {
            return null;
        }
        if ($focusId <= 0 || $focusKind === '') {
            return null;
        }
        $this->ensureResponsibleOutcomeColumns();

        $cash = Container::getInstance()->get('cashService');
        $disc = Container::getInstance()->get('staffDiscipline');
        $report = Container::getInstance()->get('reportService');
        $todayYmd = $report->todayForRestaurant($restaurantId);

        return match ($focusKind) {
            'server_request' => $this->contextServerRequest(
                $restaurantId,
                $focusId,
                $actor,
                $todayYmd,
                $disc,
            ),
            'sale' => $this->contextSale($restaurantId, $focusId, $todayYmd, $cash),
            'cash_transfer' => $this->contextCashTransfer($restaurantId, $focusId, $actor, $todayYmd, $cash),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextServerRequest(
        int $restaurantId,
        int $requestId,
        ?array $actor,
        string $todayYmd,
        StaffDisciplineService $disc,
    ): ?array {
        $st = $this->database->pdo()->prepare(
            'SELECT sr.*, u.full_name AS server_name
             FROM server_requests sr
             INNER JOIN users u ON u.id = sr.server_id
             WHERE sr.id = :id AND sr.restaurant_id = :rid LIMIT 1'
        );
        $st->execute(['id' => $requestId, 'rid' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $itemsSt = $this->database->pdo()->prepare(
            'SELECT sri.*, mi.name AS menu_item_name
             FROM server_request_items sri
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             WHERE sri.request_id = :rid
             ORDER BY sri.id ASC'
        );
        $itemsSt->execute(['rid' => $requestId]);
        $lines = $itemsSt->fetchAll(PDO::FETCH_ASSOC);
        $outcomeCode = trim((string) ($row['responsible_outcome_code'] ?? ''));
        if ($outcomeCode !== '') {
            return $this->resolvedServerRequestPanel($requestId, $row, $lines, $disc, $restaurantId, $todayYmd);
        }
        $status = (string) ($row['status'] ?? '');
        $serverId = (int) ($row['server_id'] ?? 0);
        $blockReason = $this->inferBlockReasonServerRequest($status, $row);
        $amount = (float) ($row['total_supplied_amount'] ?? $row['total_requested_amount'] ?? 0);

        return [
            'entity_kind' => 'server_request',
            'entity_id' => $requestId,
            'operation_label' => 'Commande service n° ' . $requestId . ' · ' . trim((string) ($row['service_reference'] ?? '')),
            'agent_label' => (string) ($row['server_name'] ?? ''),
            'server_user_id' => $serverId,
            'origin_at' => (string) ($row['created_at'] ?? ''),
            'amount_hint' => $amount,
            'status_label' => service_flow_status_label($status),
            'block_reason' => $blockReason,
            'lines' => $lines,
            'sanction_preview' => $serverId > 0 ? $disc->gaugesForUser($restaurantId, $serverId, $todayYmd) : [],
            'decisions' => $this->serverRequestDecisionButtons($status, $row, $lines),
            'penalty_message_default' => 'Régularisé par responsable — pénalité conservée',
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function resolvedServerRequestPanel(
        int $requestId,
        array $row,
        array $lines,
        StaffDisciplineService $disc,
        int $restaurantId,
        string $todayYmd,
    ): array {
        $serverId = (int) ($row['server_id'] ?? 0);
        $amt = (float) ($row['total_supplied_amount'] ?? $row['total_requested_amount'] ?? 0);
        $code = trim((string) ($row['responsible_outcome_code'] ?? ''));
        $decider = '';
        $bid = (int) ($row['responsible_outcome_by'] ?? 0);
        if ($bid > 0) {
            $u = $this->database->pdo()->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
            $u->execute(['id' => $bid]);
            $d = $u->fetchColumn();
            $decider = is_string($d) ? $d : '';
        }
        $detailRaw = trim((string) ($row['responsible_outcome_detail'] ?? ''));
        $detail = [];
        if ($detailRaw !== '') {
            try {
                $decoded = json_decode($detailRaw, true, 512, JSON_THROW_ON_ERROR);
                $detail = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $detail = ['detail_brut' => $detailRaw];
            }
        }

        return [
            'entity_kind' => 'server_request',
            'entity_id' => $requestId,
            'already_resolved' => true,
            'operation_label' => 'Commande service n° ' . $requestId . ' · ' . trim((string) ($row['service_reference'] ?? '')),
            'agent_label' => (string) ($row['server_name'] ?? ''),
            'server_user_id' => $serverId,
            'origin_at' => (string) ($row['created_at'] ?? ''),
            'amount_hint' => $amt,
            'status_label' => service_flow_status_label((string) ($row['status'] ?? '')),
            'block_reason' => '',
            'lines' => $lines,
            'sanction_preview' => $serverId > 0 ? $disc->gaugesForUser($restaurantId, $serverId, $todayYmd) : [],
            'decisions' => [],
            'outcome_code' => $code,
            'outcome_label' => responsible_outcome_label($code),
            'outcome_at' => (string) ($row['responsible_outcome_at'] ?? ''),
            'outcome_detail' => $detail,
            'decided_by_label' => $decider,
            'penalty_message_default' => 'Décision responsable enregistrée — consulter l’historique (audit) pour le détail financier et discipline.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array{code:string,label:string}>
     */
    private function serverRequestDecisionButtons(string $status, array $row, array $lines): array
    {
        if (trim((string) ($row['responsible_outcome_code'] ?? '')) !== '') {
            return [];
        }
        if (in_array($status, ['ANNULE', 'REFUSE_CUISINE', 'CLOTURE', 'VENDU_TOTAL', 'VENDU_PARTIEL'], true)) {
            return [];
        }
        $sup = (float) ($row['total_supplied_amount'] ?? 0);
        $out = [];
        if ($sup > 0.0001) {
            $out[] = ['code' => 'served_sale', 'label' => 'Valider comme servie'];
            $out[] = ['code' => 'close_no_sale', 'label' => 'Clôturer sans vente'];
            $out[] = ['code' => 'server_shortage', 'label' => 'Mettre en manquant (à charge agent)'];
        }
        $out[] = ['code' => 'reject_cancel', 'label' => 'Rejeter ou annuler'];

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferBlockReasonServerRequest(string $status, array $row): string
    {
        if (in_array($status, ['DEMANDE', 'EN_PREPARATION'], true)) {
            return 'Demande cuisine non finalisée ou non remise au service.';
        }
        if (in_array($status, ['PRET_A_SERVIR', 'FOURNI_PARTIEL', 'FOURNI_TOTAL'], true)) {
            return 'Réception côté serveur non confirmée ou vente non clôturée.';
        }
        if ($status === 'REMIS_SERVEUR') {
            return 'Commande remise au serveur mais non clôturée en vente.';
        }

        return 'Commande ouverte ou mise en file de régularisation.';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextSale(
        int $restaurantId,
        int $saleId,
        string $todayYmd,
        CashService $cash,
    ): ?array {
        $st = $this->database->pdo()->prepare(
            'SELECT s.*, u.full_name AS server_name
             FROM sales s
             LEFT JOIN users u ON u.id = s.server_id
             WHERE s.id = :id AND s.restaurant_id = :rid LIMIT 1'
        );
        $st->execute(['id' => $saleId, 'rid' => $restaurantId]);
        $sale = $st->fetch(PDO::FETCH_ASSOC);
        if ($sale === false) {
            return null;
        }
        $tracking = $cash->listSaleRemittanceTracking($restaurantId, null);
        $mine = null;
        foreach ($tracking as $t) {
            if ((int) ($t['sale_id'] ?? 0) === $saleId) {
                $mine = $t;
                break;
            }
        }
        $trSt = $this->database->pdo()->prepare(
            'SELECT * FROM cash_transfers
             WHERE restaurant_id = :rid AND source_type = "sale" AND source_id = :sid
             ORDER BY id DESC LIMIT 1'
        );
        $trSt->execute(['rid' => $restaurantId, 'sid' => $saleId]);
        $transfer = $trSt->fetch(PDO::FETCH_ASSOC) ?: null;
        $tid = $transfer !== false && is_array($transfer) ? (int) ($transfer['id'] ?? 0) : 0;
        $serverId = (int) ($sale['server_id'] ?? 0);
        $disc = Container::getInstance()->get('staffDiscipline');
        $tstat = $mine !== null ? (string) ($mine['transfer_status'] ?? '') : '';
        $blockReason = 'Vente liée aux remises caisse ou aux manquants.';
        if (in_array($tstat, ['REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT'], true)) {
            $blockReason = 'Remise caisse rejetée : régularisation attendue.';
        }

        $it = $this->database->pdo()->prepare(
            'SELECT si.*, mi.name AS menu_item_name FROM sale_items si
             INNER JOIN menu_items mi ON mi.id = si.menu_item_id
             WHERE si.sale_id = :sid ORDER BY si.id ASC'
        );
        $it->execute(['sid' => $saleId]);
        $saleLines = $it->fetchAll(PDO::FETCH_ASSOC);

        if (is_array($transfer) && trim((string) ($transfer['responsible_outcome_code'] ?? '')) !== '') {
            return $this->resolvedCashRemittancePanel(
                $sale,
                $transfer,
                $saleLines,
                $disc,
                $restaurantId,
                $todayYmd,
                'sale',
                $saleId,
                $tid,
            );
        }

        return [
            'entity_kind' => 'sale',
            'entity_id' => $saleId,
            'cash_transfer_id' => $tid,
            'operation_label' => 'Vente n° ' . $saleId,
            'agent_label' => (string) ($sale['server_name'] ?? ''),
            'server_user_id' => $serverId,
            'origin_at' => (string) ($sale['validated_at'] ?? $sale['created_at'] ?? ''),
            'amount_hint' => (float) ($sale['total_amount'] ?? 0),
            'status_label' => (string) ($sale['status'] ?? ''),
            'block_reason' => $blockReason,
            'transfer_status' => $tstat,
            'lines' => $saleLines,
            'sanction_preview' => $serverId > 0 ? $disc->gaugesForUser($restaurantId, $serverId, $todayYmd) : [],
            'decisions' => $tid > 0 ? $this->cashRemittanceDecisions($transfer ?: []) : [],
            'penalty_message_default' => 'Régularisé par responsable — pénalité conservée',
        ];
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    private function cashRemittanceDecisions(array $transfer): array
    {
        if (trim((string) ($transfer['responsible_outcome_code'] ?? '')) !== '') {
            return [];
        }
        $status = (string) ($transfer['status'] ?? '');
        if ((string) ($transfer['source_type'] ?? '') !== 'sale') {
            return [];
        }
        if ($status === 'EN_ATTENTE_PROPRIETAIRE') {
            return [
                ['code' => 'receive_full', 'label' => 'Valider comme reçue (argent en caisse)'],
                ['code' => 'partial_accept', 'label' => 'Accepter partiellement'],
                ['code' => 'reject_final', 'label' => 'Rejeter définitivement'],
            ];
        }
        if (!in_array($status, ['REMIS_A_CAISSE', 'SOUMIS_GERANT', 'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT', 'ECART_SIGNALE'], true)) {
            return [];
        }

        return [
            ['code' => 'receive_full', 'label' => 'Valider comme reçue (argent en caisse)'],
            ['code' => 'partial_accept', 'label' => 'Accepter partiellement (écart à charge agent)'],
            ['code' => 'reject_final', 'label' => 'Rejeter définitivement'],
            ['code' => 'submit_owner', 'label' => 'Demander l’avis du propriétaire'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $saleLines
     *
     * @return array<string, mixed>
     */
    private function resolvedCashRemittancePanel(
        array $sale,
        array $transfer,
        array $saleLines,
        StaffDisciplineService $disc,
        int $restaurantId,
        string $todayYmd,
        string $entityKind,
        int $entityId,
        int $transferId,
    ): array {
        $saleId = (int) ($sale['id'] ?? 0);
        $serverId = (int) ($sale['server_id'] ?? $transfer['from_user_id'] ?? 0);
        $code = trim((string) ($transfer['responsible_outcome_code'] ?? ''));
        $decider = '';
        $bid = (int) ($transfer['responsible_outcome_by'] ?? 0);
        if ($bid > 0) {
            $u = $this->database->pdo()->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
            $u->execute(['id' => $bid]);
            $d = $u->fetchColumn();
            $decider = is_string($d) ? $d : '';
        }
        $detailRaw = trim((string) ($transfer['responsible_outcome_detail'] ?? ''));
        $detail = [];
        if ($detailRaw !== '') {
            try {
                $decoded = json_decode($detailRaw, true, 512, JSON_THROW_ON_ERROR);
                $detail = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $detail = ['detail_brut' => $detailRaw];
            }
        }

        return [
            'entity_kind' => $entityKind,
            'entity_id' => $entityId,
            'cash_transfer_id' => $transferId,
            'sale_id' => $saleId,
            'already_resolved' => true,
            'operation_label' => $entityKind === 'sale'
                ? ('Vente n° ' . $saleId . ' · remise traitée')
                : ('Remise caisse · transfert n° ' . $transferId),
            'agent_label' => (string) ($sale['server_name'] ?? $transfer['from_user_name'] ?? ''),
            'server_user_id' => $serverId,
            'origin_at' => (string) ($transfer['requested_at'] ?? $transfer['created_at'] ?? $sale['created_at'] ?? ''),
            'amount_hint' => (float) ($transfer['amount'] ?? $sale['total_amount'] ?? 0),
            'status_label' => cash_transfer_public_label((string) ($transfer['status'] ?? '')),
            'block_reason' => '',
            'transfer_status' => (string) ($transfer['status'] ?? ''),
            'lines' => $saleLines,
            'sanction_preview' => $serverId > 0 ? $disc->gaugesForUser($restaurantId, $serverId, $todayYmd) : [],
            'decisions' => [],
            'transfer' => $transfer,
            'outcome_code' => $code,
            'outcome_label' => responsible_outcome_label($code),
            'outcome_at' => (string) ($transfer['responsible_outcome_at'] ?? ''),
            'outcome_detail' => $detail,
            'decided_by_label' => $decider,
            'penalty_message_default' => 'Décision responsable enregistrée — montants et imputation figés pour les rapports.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextCashTransfer(
        int $restaurantId,
        int $transferId,
        ?array $actor,
        string $todayYmd,
        CashService $cash,
    ): ?array {
        $transfer = $cash->findTransferForManagerResolution($restaurantId, $transferId);
        if ($transfer === null) {
            return null;
        }
        $saleId = (int) ($transfer['source_id'] ?? 0);
        $serverId = (int) ($transfer['from_user_id'] ?? 0);
        if ((string) ($transfer['source_type'] ?? '') === 'sale' && $saleId > 0) {
            $sst = $this->database->pdo()->prepare('SELECT server_id FROM sales WHERE id = :id AND restaurant_id = :rid LIMIT 1');
            $sst->execute(['id' => $saleId, 'rid' => $restaurantId]);
            $sid = $sst->fetchColumn();
            if (is_string($sid) || is_int($sid)) {
                $serverId = (int) $sid;
            }
        }
        $disc = Container::getInstance()->get('staffDiscipline');
        $status = (string) ($transfer['status'] ?? '');
        $sale = ['id' => $saleId, 'server_id' => $serverId, 'server_name' => '', 'total_amount' => 0, 'created_at' => ''];
        $saleLines = [];
        if ($saleId > 0) {
            $sst = $this->database->pdo()->prepare(
                'SELECT s.*, u.full_name AS server_name FROM sales s
                 LEFT JOIN users u ON u.id = s.server_id
                 WHERE s.id = :id AND s.restaurant_id = :rid LIMIT 1'
            );
            $sst->execute(['id' => $saleId, 'rid' => $restaurantId]);
            $srow = $sst->fetch(PDO::FETCH_ASSOC);
            if (is_array($srow)) {
                $sale = $srow;
            }
            $it = $this->database->pdo()->prepare(
                'SELECT si.*, mi.name AS menu_item_name FROM sale_items si
                 INNER JOIN menu_items mi ON mi.id = si.menu_item_id
                 WHERE si.sale_id = :sid ORDER BY si.id ASC'
            );
            $it->execute(['sid' => $saleId]);
            $saleLines = $it->fetchAll(PDO::FETCH_ASSOC);
        }
        if (trim((string) ($transfer['responsible_outcome_code'] ?? '')) !== '') {
            return $this->resolvedCashRemittancePanel(
                $sale,
                $transfer,
                $saleLines,
                $disc,
                $restaurantId,
                $todayYmd,
                'cash_transfer',
                $transferId,
                $transferId,
            );
        }

        return [
            'entity_kind' => 'cash_transfer',
            'entity_id' => $transferId,
            'sale_id' => $saleId,
            'operation_label' => 'Remise caisse · transfert n° ' . $transferId . ($saleId > 0 ? (' · vente n° ' . $saleId) : ''),
            'agent_label' => (string) ($transfer['from_user_name'] ?? ''),
            'server_user_id' => (int) ($sale['server_id'] ?? $serverId),
            'origin_at' => (string) ($transfer['requested_at'] ?? $transfer['created_at'] ?? ''),
            'amount_hint' => (float) ($transfer['amount'] ?? 0),
            'status_label' => cash_transfer_public_label($status),
            'block_reason' => $this->inferCashBlockReason($status),
            'transfer' => $transfer,
            'sanction_preview' => $serverId > 0 ? $disc->gaugesForUser($restaurantId, $serverId, $todayYmd) : [],
            'decisions' => $this->cashRemittanceDecisions($transfer),
            'penalty_message_default' => 'Régularisé par responsable — pénalité conservée',
        ];
    }

    private function inferCashBlockReason(string $status): string
    {
        return match ($status) {
            'REMIS_A_CAISSE' => 'Remise en attente de réception ou débloquée depuis la veille.',
            'SOUMIS_GERANT' => 'Remise soumise au gérant pour décision.',
            'REMISE_REJETEE_CAISSE', 'REMISE_REJETEE_GERANT' => 'Remise rejetée : montant encore à la charge du serveur tant qu’il n’y a pas de remise valide.',
            'ECART_SIGNALE' => 'Écart constaté à la caisse.',
            'EN_ATTENTE_PROPRIETAIRE' => 'Décision réservée au propriétaire (escalade).',
            default => 'Opération caisse nécessitant une décision responsable.',
        };
    }
}
