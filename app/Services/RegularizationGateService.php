<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Garde-fous début de journée : lecture seule + pénalités disciplinaires traçables.
 */
final class RegularizationGateService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{
     *   blocked: bool,
     *   reasons: list<string>,
     *   codes: list<string>,
     *   backlog: array<string, int>,
     *   items: list<array<string, mixed>>,
     *   items_today_soft: list<array<string, mixed>>,
     *   super_admin_unblocked: bool
     * }
     */
    public function assessForUser(int $restaurantId, array $user): array
    {
        $scope = (string) ($user['scope'] ?? '');
        if ($scope === 'super_admin') {
            return [
                'blocked' => false,
                'reasons' => [],
                'codes' => [],
                'backlog' => [],
                'items' => [],
                'items_today_soft' => [],
                'super_admin_unblocked' => true,
            ];
        }

        $role = (string) ($user['role_code'] ?? '');
        $uid = (int) ($user['id'] ?? 0);
        $backlog = Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId);
        $cutoff = $this->todayStartSql($restaurantId);
        $items = $this->visibleTasksForRole($restaurantId, $role, $uid, $cutoff, 40);
        [$itemsBlocking, $itemsTodaySoft] = $this->partitionHoldItems($items);

        if (in_array($role, ['owner', 'manager'], true)) {
            return [
                'blocked' => false,
                'reasons' => [],
                'codes' => [],
                'backlog' => $backlog,
                'items' => $itemsBlocking,
                'items_today_soft' => $itemsTodaySoft,
                'super_admin_unblocked' => false,
            ];
        }

        $reasons = [];
        $codes = [];

        if ($role === 'cashier_server' && $uid > 0) {
            $cash = Container::getInstance()->get('reportService')->agentServerCashAccountReadModel($restaurantId, $uid);
            $legacySf = (float) ($cash['legacy_shortfall'] ?? 0);
            if ($legacySf > 0.0001) {
                $reasons[] = 'Anciennes ventes non entièrement régularisées à la caisse.';
                $codes[] = 'server_shortfall_legacy';
            }
            if ($this->serverHasRejectedRemittanceOutstanding($restaurantId, $uid)) {
                $reasons[] = 'Au moins une remise caisse a été rejetée : le montant reste à votre charge tant qu’une remise valide n’est pas enregistrée.';
                $codes[] = 'server_remittance_rejected';
            }
            if ($this->serverHasStaleOpenRequests($restaurantId, $uid)) {
                $reasons[] = 'Commandes service non clôturées depuis la veille.';
                $codes[] = 'server_stale_requests';
            }
        }

        if ($role === 'cashier_accountant') {
            if (($backlog['overdue_remis_a_caisse'] ?? 0) > 0) {
                $reasons[] = 'Une ou plusieurs remises serveur attendent une décision caisse (héritées de la veille).';
                $codes[] = 'cashier_pending_remis';
            }
        }

        if ($role === 'kitchen') {
            if ($this->kitchenHasBacklogItems($restaurantId)) {
                $reasons[] = 'Des lignes cuisine du service restent à traiter (veille).';
                $codes[] = 'kitchen_pending';
            }
        }

        if ($role === 'stock_manager') {
            if ($this->stockMagasinHasOpenFromPriorDay($restaurantId)) {
                $reasons[] = 'Demandes magasin cuisine à finaliser (veille).';
                $codes[] = 'stock_kitchen_requests_pending';
            }
        }

        $hasAlerts = $reasons !== [];
        if ($hasAlerts && $uid > 0) {
            Container::getInstance()->get('staffDiscipline')->ensureLedgerPenalty(
                $restaurantId,
                $uid,
                $codes[0] ?? 'regularization_hold',
                $this->todayYmd($restaurantId),
            );
        }

        return [
            'blocked' => false,
            'reasons' => $reasons,
            'codes' => $codes,
            'backlog' => $backlog,
            'items' => $itemsBlocking,
            'items_today_soft' => $itemsTodaySoft,
            'super_admin_unblocked' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function partitionHoldItems(array $items): array
    {
        $blocking = [];
        $soft = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (($it['hold_tier'] ?? 'blocking') === 'today_soft') {
                $soft[] = $it;
            } else {
                $blocking[] = $it;
            }
        }

        return [$blocking, $soft];
    }

    /**
     * Tâches visibles pour le panneau propriétaire (vision transversale).
     *
     * @return list<array<string, mixed>>
     */
    public function listRestaurantWideTasks(int $restaurantId, int $limit = 35): array
    {
        $cutoff = $this->todayStartSql($restaurantId);

        return $this->gatherRawTasks($restaurantId, $cutoff, $limit);
    }

    private function visibleTasksForRole(int $restaurantId, string $role, int $uid, string $cutoff, int $limit): array
    {
        $raw = $this->gatherRawTasks($restaurantId, $cutoff, 80);
        $out = [];
        foreach ($raw as $row) {
            if ($this->taskMatchesRole($row, $role, $uid)) {
                $out[] = $row;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gatherRawTasks(int $restaurantId, string $cutoff, int $limit): array
    {
        Container::getInstance()->get('managerResolution')->ensureResponsibleOutcomeColumns();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $currency = (string) ($restaurant['currency'] ?? '');

        $tasks = [];

        $stReq = $this->database->pdo()->prepare(
            'SELECT sr.id, sr.service_reference, sr.status, sr.created_at, sr.server_id,
                    u.full_name AS server_name,
                    COALESCE(sr.total_supplied_amount, sr.total_sold_amount, sr.total_requested_amount, 0) AS amount_hint
             FROM server_requests sr
             INNER JOIN users u ON u.id = sr.server_id
             WHERE sr.restaurant_id = :rid
               AND sr.status NOT IN ("ANNULE", "REFUSE_CUISINE", "CLOTURE", "VENDU_TOTAL", "VENDU_PARTIEL")
               AND COALESCE(sr.responsible_outcome_code, "") = ""
               AND sr.created_at < :cutoff
             ORDER BY sr.created_at ASC
             LIMIT 25'
        );
        $stReq->execute(['rid' => $restaurantId, 'cutoff' => $cutoff]);
        foreach ($stReq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $srid = (int) ($r['id'] ?? 0);
            $ref = trim((string) ($r['service_reference'] ?? ''));
            if ($ref === '') {
                $ref = 'SR-' . $srid;
            }
            $tasks[] = [
                'audience' => ['server', 'manager', 'owner'],
                'server_user_id' => (int) ($r['server_id'] ?? 0),
                'type_label' => 'Commande service',
                'reference' => $ref,
                'at_raw' => $createdAt,
                'happened_at' => $this->formatTs($createdAt, $restaurantId),
                'agent_label' => (string) ($r['server_name'] ?? ''),
                'amount_label' => $this->moneyHint((float) ($r['amount_hint'] ?? 0), $currency),
                'detail_label' => 'Commande non clôturée',
                'status_label' => service_flow_status_label((string) ($r['status'] ?? '')),
                'action_label' => 'Clôturer, annuler ou régulariser sur Ventes',
                'href' => '/ventes?focus=server_request:' . $srid,
                'focus' => 'server_request:' . $srid,
                'manquant_a_charge' => false,
            ];
        }

        $stCash = $this->database->pdo()->prepare(
            'SELECT ct.id, ct.status, ct.amount, ct.source_id AS sale_id,
                    COALESCE(ct.requested_at, ct.created_at) AS ts,
                    COALESCE(u.full_name, "Serveur") AS from_name
             FROM cash_transfers ct
             LEFT JOIN users u ON u.id = ct.from_user_id
             WHERE ct.restaurant_id = :rid
               AND ct.source_type = "sale"
               AND ct.status = "REMIS_A_CAISSE"
               AND COALESCE(ct.responsible_outcome_code, "") = ""
               AND COALESCE(ct.requested_at, ct.created_at) < :cutoff
             ORDER BY ct.id ASC
             LIMIT 20'
        );
        $stCash->execute(['rid' => $restaurantId, 'cutoff' => $cutoff]);
        foreach ($stCash->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $saleId = (int) ($r['sale_id'] ?? 0);
            $tid = (int) ($r['id'] ?? 0);
            $tasks[] = [
                'audience' => ['cashier', 'manager', 'owner'],
                'server_user_id' => 0,
                'type_label' => 'Remise caisse',
                'reference' => 'RC-' . $tid,
                'at_raw' => (string) ($r['ts'] ?? ''),
                'happened_at' => $this->formatTs((string) ($r['ts'] ?? ''), $restaurantId),
                'agent_label' => (string) ($r['from_name'] ?? ''),
                'amount_label' => $this->moneyHint((float) ($r['amount'] ?? 0), $currency),
                'detail_label' => $saleId > 0 ? ('Vente n° ' . $saleId) : 'Vente liée',
                'status_label' => cash_transfer_public_label((string) ($r['status'] ?? '')),
                'action_label' => 'Recevoir, rejeter ou soumettre au gérant sur Caisse',
                'href' => '/caisse?focus=cash_transfer:' . $tid,
                'focus' => 'cash_transfer:' . $tid,
                'manquant_a_charge' => false,
                'hold_tier' => 'blocking',
            ];
        }

        $stKi = $this->database->pdo()->prepare(
            'SELECT sri.id, sri.status, mi.name AS dish_name, sr.id AS request_id,
                    sr.service_reference, sr.created_at, sr.server_id, u.full_name AS server_name
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
             INNER JOIN users u ON u.id = sr.server_id
             WHERE sr.restaurant_id = :rid
               AND COALESCE(sr.responsible_outcome_code, "") = ""
               AND sri.status IN ("DEMANDE", "EN_PREPARATION", "FOURNI_PARTIEL", "FOURNI_TOTAL", "PRET_A_SERVIR")
               AND COALESCE(sr.created_at, sr.updated_at) < :cutoff
             ORDER BY sr.created_at ASC
             LIMIT 25'
        );
        $stKi->execute(['rid' => $restaurantId, 'cutoff' => $cutoff]);
        foreach ($stKi->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reqId = (int) ($r['request_id'] ?? 0);
            $sriId = (int) ($r['id'] ?? 0);
            $ref = trim((string) ($r['service_reference'] ?? ''));
            if ($ref === '') {
                $ref = 'SR-' . $reqId;
            }
            $tasks[] = [
                'audience' => ['kitchen', 'manager', 'owner'],
                'server_user_id' => (int) ($r['server_id'] ?? 0),
                'type_label' => 'Préparation cuisine',
                'reference' => $ref . ' · ligne ' . $sriId,
                'at_raw' => (string) ($r['created_at'] ?? ''),
                'happened_at' => $this->formatTs((string) ($r['created_at'] ?? ''), $restaurantId),
                'agent_label' => (string) ($r['server_name'] ?? ''),
                'amount_label' => '—',
                'detail_label' => (string) ($r['dish_name'] ?? 'Plat'),
                'status_label' => validation_status_label((string) ($r['status'] ?? '')),
                'action_label' => 'Valider ou rejeter la ligne sur Cuisine',
                'href' => '/cuisine?focus=server_request_item:' . $sriId,
                'focus' => 'server_request_item:' . $sriId,
                'manquant_a_charge' => false,
            ];
        }

        $cutoffTs = strtotime($cutoff) ?: 0;
        $stockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);
        foreach (($stockBlocks['requests'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $status = (string) ($r['status'] ?? '');
            if (in_array($status, ['ANNULE', 'REFUSE_STOCK', 'CLOTURE', 'FOURNI_TOTAL', 'FOURNI_PARTIEL'], true)) {
                continue;
            }
            if (!empty($r['has_any_received_item']) && $status === 'NON_FOURNI') {
                continue;
            }
            $createdAt = (string) ($r['created_at'] ?? '');
            $createdTs = $createdAt !== '' ? (strtotime($createdAt) ?: 0) : 0;
            if ($cutoffTs > 0 && $createdTs > 0 && $createdTs >= $cutoffTs) {
                continue;
            }
            $ksrId = (int) ($r['id'] ?? 0);
            $tasks[] = [
                'audience' => ['stock', 'manager', 'owner'],
                'server_user_id' => 0,
                'type_label' => 'Demande stock',
                'reference' => 'STK-' . $ksrId,
                'at_raw' => (string) ($r['created_at'] ?? ''),
                'happened_at' => $this->formatTs((string) ($r['created_at'] ?? ''), $restaurantId),
                'agent_label' => '—',
                'amount_label' => '—',
                'detail_label' => trim((string) ($r['note'] ?? '')) !== '' ? trim((string) $r['note']) : 'Demande magasin ouverte',
                'status_label' => stock_request_status_label($status),
                'action_label' => 'Traiter ou clôturer sur Stock',
                'href' => '/stock?focus=kitchen_stock_request:' . $ksrId,
                'focus' => 'kitchen_stock_request:' . $ksrId,
                'manquant_a_charge' => false,
                'hold_tier' => 'blocking',
            ];
        }

        $stCh = $this->database->pdo()->prepare(
            'SELECT ct.id, ct.status, ct.source_type, ct.amount,
                    COALESCE(ct.requested_at, ct.created_at) AS ts,
                    COALESCE(u.full_name, "—") AS from_name
             FROM cash_transfers ct
             LEFT JOIN users u ON u.id = ct.from_user_id
             WHERE ct.restaurant_id = :rid
               AND ct.status IN ("REMIS_A_GERANT", "REMIS_A_PROPRIETAIRE")
               AND COALESCE(ct.responsible_outcome_code, "") = ""
               AND COALESCE(ct.requested_at, ct.created_at) < :cutoff
             ORDER BY ct.id ASC
             LIMIT 15'
        );
        $stCh->execute(['rid' => $restaurantId, 'cutoff' => $cutoff]);
        foreach ($stCh->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stype = (string) ($r['source_type'] ?? '');
            $tid = (int) ($r['id'] ?? 0);
            $tasks[] = [
                'audience' => ['manager', 'owner'],
                'server_user_id' => 0,
                'type_label' => $stype === 'REMISE_PROPRIETAIRE' ? 'Transfert vers propriétaire' : 'Transfert vers gérant',
                'reference' => 'TRF-' . $tid,
                'at_raw' => (string) ($r['ts'] ?? ''),
                'happened_at' => $this->formatTs((string) ($r['ts'] ?? ''), $restaurantId),
                'agent_label' => (string) ($r['from_name'] ?? ''),
                'amount_label' => $this->moneyHint((float) ($r['amount'] ?? 0), $currency),
                'detail_label' => 'Chaîne caisse',
                'status_label' => cash_transfer_public_label((string) ($r['status'] ?? '')),
                'action_label' => 'Valider la réception sur Caisse ou contacter le super administrateur',
                'href' => '/caisse?focus=cash_transfer:' . $tid,
                'focus' => 'cash_transfer:' . $tid,
                'manquant_a_charge' => false,
                'hold_tier' => 'blocking',
            ];
        }

        $this->appendServerShortfallAndRejections($restaurantId, $currency, $tasks);

        usort($tasks, static function (array $a, array $b): int {
            return strcmp((string) ($a['at_raw'] ?? ''), (string) ($b['at_raw'] ?? ''));
        });

        $trimmed = array_slice($tasks, 0, $limit);
        foreach ($trimmed as &$t) {
            unset($t['at_raw']);
            if (!array_key_exists('hold_tier', $t)) {
                $t['hold_tier'] = 'blocking';
            }
        }
        unset($t);

        return $trimmed;
    }

    /**
     * Jour de vente / service pour savoir si l’arriéré est antérieur au jour courant (blocage après clôture de journée).
     *
     * @param array<string, mixed> $row ligne listSaleRemittanceTracking ou équivalent
     */
    private function saleServiceDayYmdForHold(array $row, int $restaurantId): string
    {
        $day = trim((string) ($row['sale_day_ymd'] ?? ''));
        if ($day !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return $day;
        }
        $tz = $this->reportTz($restaurantId);
        foreach ([
            'sale_activity_at',
            'server_request_received_at',
            'validated_at',
        ] as $k) {
            $t = trim((string) ($row[$k] ?? ''));
            if ($t === '') {
                continue;
            }
            try {
                return (new DateTimeImmutable($t))->setTimezone($tz)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $tasks
     */
    private function appendServerShortfallAndRejections(int $restaurantId, string $currency, array &$tasks): void
    {
        $todayYmd = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $stU = $this->database->pdo()->prepare(
            'SELECT id FROM users WHERE restaurant_id = :rid AND status = "active"'
        );
        $stU->execute(['rid' => $restaurantId]);
        $userIds = array_map(static fn ($v): int => (int) $v, $stU->fetchAll(PDO::FETCH_COLUMN) ?: []);
        foreach ($userIds as $sid) {
            $cash = Container::getInstance()->get('cashService')->listSaleRemittanceTracking($restaurantId, $sid);
            foreach ($cash as $row) {
                if (trim((string) ($row['responsible_outcome_code'] ?? '')) !== '') {
                    continue;
                }
                $st = (string) ($row['transfer_status'] ?? '');
                if ($st === 'REMISE_REJETEE_CAISSE') {
                    $saleId = (int) ($row['sale_id'] ?? 0);
                    $rawTs = (string) ($row['remitted_at'] ?? $row['cash_received_at'] ?? $row['validated_at'] ?? $row['sale_created_at'] ?? '');
                    $gerantFinal = false;
                    $svcYmd = $this->saleServiceDayYmdForHold($row, $restaurantId);
                    $tier = ($svcYmd !== '' && $svcYmd >= $todayYmd) ? 'today_soft' : 'blocking';
                    $tasks[] = [
                        'audience' => ['server', 'manager', 'owner'],
                        'server_user_id' => $sid,
                        'type_label' => 'Remise caisse',
                        'reference' => 'VTE-' . $saleId,
                        'at_raw' => $rawTs,
                        'happened_at' => $this->formatTs($rawTs, $restaurantId),
                        'agent_label' => (string) ($row['server_name'] ?? ''),
                        'amount_label' => $this->moneyHint((float) ($row['transfer_amount'] ?? $row['sale_total_amount'] ?? 0), $currency),
                        'detail_label' => $gerantFinal ? 'Décision responsable (non reçu)' : 'Remise refusée à la caisse',
                        'status_label' => $gerantFinal ? 'À charge agent (hors nouvelle remise)' : 'Rejeté · une nouvelle remise peut être attendue',
                        'action_label' => $gerantFinal
                            ? 'Voir le détail sur Ventes (décision enregistrée)'
                            : 'Refaire une remise ou demander l’aide du responsable sur Ventes',
                        'href' => '/ventes?focus=sale:' . $saleId,
                        'focus' => 'sale:' . $saleId,
                        'manquant_a_charge' => true,
                        'hold_tier' => $tier,
                    ];
                }
            }
        }

        $report = Container::getInstance()->get('reportService');
        $tz = $report->timezoneForRestaurantReports($restaurantId);
        $todayY = $report->todayForRestaurant($restaurantId);
        $todayStart = $report->normalizeDatePublic($todayY, $tz);
        [$tStart, $tEnd] = $report->periodBoundsPublic($todayStart, 'daily', $tz);

        foreach ($userIds as $sid) {
            if ($sid <= 0) {
                continue;
            }
            $brk = $report->serverRemittanceShortfallBreakdown($restaurantId, $tStart, $tEnd, $sid);
            foreach (($brk['agents'] ?? []) as $ag) {
                if ((int) ($ag['server_user_id'] ?? 0) !== $sid) {
                    continue;
                }
                foreach (($ag['missing_sales'] ?? []) as $ms) {
                    $rawMs = (string) ($ms['validated_at'] ?? '');
                    $saleIdMs = (int) ($ms['sale_id'] ?? 0);
                    $tasks[] = [
                        'audience' => ['server', 'manager', 'owner'],
                        'server_user_id' => $sid,
                        'type_label' => 'Manquant caisse',
                        'reference' => 'VTE-' . $saleIdMs,
                        'at_raw' => $rawMs,
                        'happened_at' => $this->formatTs($rawMs, $restaurantId),
                        'agent_label' => (string) ($ag['server_name'] ?? ''),
                        'amount_label' => $this->moneyHint((float) ($ms['total_amount'] ?? 0), $currency),
                        'detail_label' => 'Vente clôturée sans remise caisse complète (jour en cours — à finaliser avant fin de journée)',
                        'status_label' => 'En cours',
                        'action_label' => 'Remettre le montant sur Ventes',
                        'href' => '/ventes?focus=sale:' . $saleIdMs,
                        'focus' => 'sale:' . $saleIdMs,
                        'manquant_a_charge' => true,
                        'hold_tier' => 'today_soft',
                    ];
                }
            }
        }
    }

    private function taskMatchesRole(array $task, string $role, int $uid): bool
    {
        $aud = $task['audience'] ?? [];
        if (!is_array($aud)) {
            return false;
        }
        $serverUid = (int) ($task['server_user_id'] ?? 0);

        return match ($role) {
            'owner', 'manager' => true,
            'cashier_server' => in_array('server', $aud, true) && ($serverUid <= 0 || $serverUid === $uid),
            'cashier_accountant' => in_array('cashier', $aud, true),
            'kitchen' => in_array('kitchen', $aud, true),
            'stock_manager' => in_array('stock', $aud, true),
            default => false,
        };
    }

    private function moneyHint(float $amount, string $currency): string
    {
        $n = number_format($amount, $amount > 0 && $amount < 1000 ? 2 : 0, ',', ' ');
        $cur = trim($currency);

        return $cur !== '' ? ($n . ' ' . $cur) : $n;
    }

    private function formatTs(string $ts, int $restaurantId): string
    {
        if ($ts === '') {
            return '—';
        }
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);

        try {
            return (new DateTimeImmutable($ts))->setTimezone($tz)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $ts;
        }
    }

    private function todayStartSql(int $restaurantId): string
    {
        $tz = $this->reportTz($restaurantId);

        return (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    }

    private function todayYmd(int $restaurantId): string
    {
        return Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
    }

    private function reportTz(int $restaurantId): DateTimeZone
    {
        return Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
    }

    private function serverHasRejectedRemittanceOutstanding(int $restaurantId, int $serverUserId): bool
    {
        $todayYmd = $this->todayYmd($restaurantId);
        foreach (Container::getInstance()->get('cashService')->listSaleRemittanceTracking($restaurantId, $serverUserId) as $row) {
            if ((int) ($row['server_id'] ?? 0) !== $serverUserId) {
                continue;
            }
            if (trim((string) ($row['responsible_outcome_code'] ?? '')) !== '') {
                continue;
            }
            $st = (string) ($row['transfer_status'] ?? '');
            if ($st !== 'REMISE_REJETEE_CAISSE') {
                continue;
            }
            $saleYmd = $this->saleServiceDayYmdForHold($row, $restaurantId);
            if ($saleYmd === '' || $saleYmd >= $todayYmd) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function serverHasStaleOpenRequests(int $restaurantId, int $serverUserId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM server_requests
             WHERE restaurant_id = :rid AND server_id = :uid
               AND status NOT IN ("ANNULE", "REFUSE_CUISINE", "CLOTURE", "VENDU_TOTAL", "VENDU_PARTIEL")
               AND COALESCE(responsible_outcome_code, "") = ""
               AND created_at < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $serverUserId, 'today_start' => $todayStart]);

        return (int) $st->fetchColumn() > 0;
    }

    private function kitchenHasBacklogItems(int $restaurantId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sr.restaurant_id = :rid
               AND COALESCE(sr.responsible_outcome_code, "") = ""
               AND sri.status IN ("DEMANDE", "EN_PREPARATION", "FOURNI_PARTIEL", "FOURNI_TOTAL", "PRET_A_SERVIR")
               AND COALESCE(sr.created_at, sr.updated_at) < :today_start'
        );
        $st->execute(['rid' => $restaurantId, 'today_start' => $todayStart]);

        return (int) $st->fetchColumn() > 0;
    }

    private function stockMagasinHasOpenFromPriorDay(int $restaurantId): bool
    {
        $tz = $this->reportTz($restaurantId);
        $todayStart = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayStartTs = strtotime($todayStart) ?: 0;
        $stockBlocks = Container::getInstance()->get('stockService')->listKitchenStockRequestBlocks($restaurantId);
        foreach (($stockBlocks['requests'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, ['ANNULE', 'REFUSE_STOCK', 'CLOTURE', 'FOURNI_TOTAL', 'FOURNI_PARTIEL'], true)) {
                continue;
            }
            if (!empty($row['has_any_received_item']) && $status === 'NON_FOURNI') {
                continue;
            }
            $createdAt = (string) ($row['created_at'] ?? '');
            $createdTs = $createdAt !== '' ? (strtotime($createdAt) ?: 0) : 0;
            if ($todayStartTs > 0 && $createdTs > 0 && $createdTs < $todayStartTs) {
                return true;
            }
        }

        return false;
    }
}
