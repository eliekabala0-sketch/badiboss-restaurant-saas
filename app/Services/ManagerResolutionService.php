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
        $stmt = $this->database->pdo()->prepare(
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
     * @return list<array{code:string,label:string}>
     */
    private function serverRequestDecisionButtons(string $status, array $row, array $lines): array
    {
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
            ['code' => 'receive_full', 'label' => 'Valider comme reçue à la caisse'],
            ['code' => 'partial_accept', 'label' => 'Accepter partiellement (manquant serveur sur la différence)'],
            ['code' => 'reject_final', 'label' => 'Rejeter définitivement (reste à charge serveur)'],
            ['code' => 'submit_owner', 'label' => 'Soumettre au propriétaire'],
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

        return [
            'entity_kind' => 'cash_transfer',
            'entity_id' => $transferId,
            'sale_id' => $saleId,
            'operation_label' => 'Remise caisse · transfert n° ' . $transferId . ($saleId > 0 ? (' · vente n° ' . $saleId) : ''),
            'agent_label' => (string) ($transfer['from_user_name'] ?? ''),
            'server_user_id' => $serverId,
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
