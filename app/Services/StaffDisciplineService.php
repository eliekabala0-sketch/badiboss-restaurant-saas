<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Présence / jauges / paie — socle : pastilles jour-semaine-mois et journal des points.
 */
final class StaffDisciplineService
{
    /** @var array<string, ?string> */
    private array $userFirstActivityYmdCache = [];

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureSchema(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_payroll_profiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                user_id INT NOT NULL,
                base_salary_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT "USD",
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_staff_payroll (restaurant_id, user_id),
                KEY idx_staff_payroll_restaurant (restaurant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_attendance_day (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                user_id INT NOT NULL,
                day_ymd DATE NOT NULL,
                planned_status VARCHAR(40) NOT NULL DEFAULT "TRAVAIL",
                activity_detected TINYINT(1) NOT NULL DEFAULT 0,
                manager_note VARCHAR(500) NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_staff_day (restaurant_id, user_id, day_ymd),
                KEY idx_staff_att_rest (restaurant_id, day_ymd)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_score_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurant_id INT NOT NULL,
                user_id INT NOT NULL,
                day_ymd DATE NOT NULL,
                reason_code VARCHAR(80) NOT NULL,
                delta_points INT NOT NULL,
                label VARCHAR(500) NOT NULL,
                ref_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_score_user_day (restaurant_id, user_id, day_ymd),
                KEY idx_score_reason (reason_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensureStaffDisciplinePermissions();
    }

    private function ensureStaffDisciplinePermissions(): void
    {
        $pdo = $this->database->pdo();
        $st = $pdo->prepare('SELECT id FROM permissions WHERE code = :c LIMIT 1');
        $st->execute(['c' => 'staff.gauges.view']);
        if ($st->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO permissions (module_name, action_name, code, description, is_sensitive, created_at, updated_at)
             VALUES ("staff", "gauges_view", "staff.gauges.view", "Voir les jauges des autres agents du restaurant.", 0, NOW(), NOW())'
        )->execute();
    }

    /**
     * Première trace d’activité (audit) pour cet agent dans le restaurant — jour calendaire (fuseau rapports).
     * Null = aucune activité historique : la jauge reste « non évalué ».
     */
    public function userFirstActivityDayYmd(int $restaurantId, int $userId, DateTimeZone $tz): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $k = $restaurantId . ':' . $userId;
        if (array_key_exists($k, $this->userFirstActivityYmdCache)) {
            return $this->userFirstActivityYmdCache[$k];
        }
        $st = $this->database->pdo()->prepare(
            'SELECT MIN(created_at) AS t FROM audit_logs WHERE restaurant_id = :rid AND user_id = :uid'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId]);
        $t = $st->fetchColumn();
        if (!is_string($t) || $t === '') {
            $this->userFirstActivityYmdCache[$k] = null;

            return null;
        }
        try {
            $utc = new DateTimeZone('UTC');
            $dt = new DateTimeImmutable($t, $utc);
        } catch (\Throwable) {
            $this->userFirstActivityYmdCache[$k] = null;

            return null;
        }
        $ymd = $dt->setTimezone($tz)->format('Y-m-d');
        if (str_starts_with($ymd, '0000')) {
            $this->userFirstActivityYmdCache[$k] = null;

            return null;
        }
        $this->userFirstActivityYmdCache[$k] = $ymd;

        return $ymd;
    }

    /**
     * Évite les doublons massifs : une pénalité « hold » par code et par jour.
     */
    public function ensureLedgerPenalty(int $restaurantId, int $userId, string $reasonCode, string $dayYmd): void
    {
        if ($userId <= 0 || $reasonCode === '') {
            return;
        }
        $this->ensureSchema();
        $labelMap = [
            'server_shortfall_today' => ['delta' => -15, 'label' => 'Manquant caisse (ventes du jour non couvertes).'],
            'server_shortfall_legacy' => ['delta' => -15, 'label' => 'Arriéré de remise caisse (jours précédents).'],
            'server_remittance_rejected' => ['delta' => -10, 'label' => 'Remise caisse rejetée : montant toujours à charge jusqu’à nouvelle remise valide.'],
            'server_stale_requests' => ['delta' => -10, 'label' => 'Commandes service non clôturées depuis la veille.'],
            'cashier_pending_remis' => ['delta' => -10, 'label' => 'Remises serveur en attente de décision caisse (héritées).'],
            'kitchen_pending' => ['delta' => -10, 'label' => 'Lignes cuisine / service à traiter depuis la veille.'],
            'stock_kitchen_requests_pending' => ['delta' => -10, 'label' => 'Demandes magasin cuisine non finalisées.'],
        ];
        $cfg = $labelMap[$reasonCode] ?? ['delta' => -5, 'label' => 'Blocage régularisation : ' . $reasonCode];
        $st = $this->database->pdo()->prepare(
            'SELECT id FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d AND reason_code = :code
             LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd, 'code' => $reasonCode]);
        if ($st->fetchColumn() !== false) {
            return;
        }
        $ins = $this->database->pdo()->prepare(
            'INSERT INTO staff_score_ledger
            (restaurant_id, user_id, day_ymd, reason_code, delta_points, label, ref_json)
             VALUES (:rid, :uid, :d, :code, :delta, :label, NULL)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'd' => $dayYmd,
            'code' => $reasonCode,
            'delta' => (int) $cfg['delta'],
            'label' => (string) $cfg['label'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listLedgerForUserMonth(int $restaurantId, int $userId, string $monthYmdFirst): array
    {
        $this->ensureSchema();
        $month = substr($monthYmdFirst, 0, 7);
        $st = $this->database->pdo()->prepare(
            'SELECT * FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND DATE_FORMAT(day_ymd, "%Y-%m") = :m
             ORDER BY day_ymd ASC, id ASC'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'm' => $month]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{daily:?int, weekly_avg:?float, monthly_avg:?float, zone:string, ledger_preview: list<array<string,mixed>>}
     */
    public function gaugesForUser(int $restaurantId, int $userId, string $todayYmd): array
    {
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $daily = $this->scoreForDayOrNull($restaurantId, $userId, $todayYmd, $tz);
        $weekly = $this->averageLastDaysNullable($restaurantId, $userId, $todayYmd, 7, $tz);
        $monthly = $this->averageMonthToDateNullable($restaurantId, $userId, $todayYmd, $tz);

        return [
            'daily' => $daily,
            'weekly_avg' => $weekly,
            'monthly_avg' => $monthly,
            'zone' => $this->zoneFromScoreNullable($monthly),
            'ledger_preview' => array_slice($this->listLedgerForUserMonth($restaurantId, $userId, substr($todayYmd, 0, 7) . '-01'), -12),
        ];
    }

    /**
     * Bandeaux jour / 7 j. / mois alignés sur la période opérationnelle (pas figés à « aujourd’hui » seul).
     *
     * @return array{daily:?int, weekly_avg:?float, monthly_avg:?float, zone:string, ledger_preview: list<array<string,mixed>>}
     */
    private function gaugesOperationalSummaryStats(
        int $restaurantId,
        int $userId,
        string $preset,
        string $anchorYmd,
        string $todayY,
        DateTimeZone $tz,
    ): array {
        $rs = Container::getInstance()->get('reportService');
        $refY = $this->operationalReferenceDayYmd($restaurantId, $preset, $anchorYmd, $todayY, $tz);
        $daily = $this->scoreForDayOrNull($restaurantId, $userId, $refY, $tz);
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        if ($preset === 'week') {
            $win = $rs->operationalPeriodWindow($restaurantId, 'week', $anchorYmd);
            $first = max($glob, $win['start']->format('Y-m-d'));
            $last = $win['end']->modify('-1 day')->format('Y-m-d');
            $weekly = $first <= $last
                ? $this->averageEvaluatedDaysInRange($restaurantId, $userId, $first, $last, $tz)
                : null;
        } else {
            $weekly = $this->averageLastDaysNullable($restaurantId, $userId, $refY, 7, $tz);
        }

        if ($preset === 'prev_month') {
            $win = $rs->operationalPeriodWindow($restaurantId, 'prev_month', $anchorYmd);
            $first = $win['start']->format('Y-m-d');
            $last = $win['end']->modify('-1 day')->format('Y-m-d');
            $monthly = $this->averageEvaluatedDaysInRange($restaurantId, $userId, $first, $last, $tz);
        } else {
            $monthEnd = min($refY, $todayY);
            $monthly = $this->averageMonthToDateNullable($restaurantId, $userId, $monthEnd, $tz);
        }

        $ledgerMonthKey = substr($refY, 0, 7) . '-01';

        return [
            'daily' => $daily,
            'weekly_avg' => $weekly,
            'monthly_avg' => $monthly,
            'zone' => $this->zoneFromScoreNullable($monthly),
            'ledger_preview' => array_slice($this->listLedgerForUserMonth($restaurantId, $userId, $ledgerMonthKey), -12),
        ];
    }

    private function operationalReferenceDayYmd(
        int $restaurantId,
        string $preset,
        string $anchorYmd,
        string $todayY,
        DateTimeZone $tz,
    ): string {
        $rs = Container::getInstance()->get('reportService');
        try {
            return match ($preset) {
                'today' => $todayY,
                'yesterday' => (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('-1 day')->format('Y-m-d'),
                'date', 'week', 'month' => $anchorYmd,
                'prev_month' => $rs->operationalPeriodWindow($restaurantId, 'prev_month', $anchorYmd)['end']->modify('-1 day')->format('Y-m-d'),
                default => $todayY,
            };
        } catch (\Throwable) {
            return $todayY;
        }
    }

    private function averageEvaluatedDaysInRange(
        int $restaurantId,
        int $userId,
        string $fromYmd,
        string $toYmd,
        DateTimeZone $tz,
    ): ?float {
        if ($fromYmd > $toYmd) {
            return null;
        }
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return null;
        }
        try {
            $start = max($fromYmd, $glob, $userFirst);
            $endD = new DateTimeImmutable($toYmd . ' 00:00:00', $tz);
            $cur = new DateTimeImmutable($start . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return null;
        }
        if ($start > $toYmd) {
            return null;
        }
        $sum = 0.0;
        $n = 0;
        for ($d = $cur; $d <= $endD; $d = $d->modify('+1 day')) {
            $sc = $this->scoreForDayOrNull($restaurantId, $userId, $d->format('Y-m-d'), $tz);
            if ($sc !== null) {
                $sum += $sc;
                $n++;
            }
        }

        if ($n <= 0) {
            return null;
        }
        $r = round($sum / $n, 1);

        return is_finite($r) ? $r : null;
    }

    /**
     * Panneau jauge selon le même préréglage que le tableau de bord opérationnel.
     *
     * @return array<string, mixed>
     */
    public function gaugesForUserOperationalPanel(int $restaurantId, int $userId, string $preset, string $anchorYmd): array
    {
        if ($userId <= 0) {
            return [];
        }
        $this->ensureSchema();
        $rs = Container::getInstance()->get('reportService');
        $tz = $rs->timezoneForRestaurantReports($restaurantId);
        $todayY = $rs->todayForRestaurant($restaurantId);
        $preset = strtolower(trim($preset));
        $allowed = ['today', 'yesterday', 'date', 'week', 'month', 'prev_month'];
        if (!in_array($preset, $allowed, true)) {
            $preset = 'today';
        }
        $anchor = $anchorYmd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorYmd) ? $anchorYmd : $todayY;
        $yesterday = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('-1 day')->format('Y-m-d');
        $base = $this->gaugesOperationalSummaryStats($restaurantId, $userId, $preset, $anchor, $todayY, $tz);

        $active = match ($preset) {
            'today' => $this->snapshotDayGauge($restaurantId, $userId, $todayY, 'Aujourd’hui', $tz),
            'yesterday' => $this->snapshotDayGauge($restaurantId, $userId, $yesterday, 'Hier', $tz),
            'date' => $this->snapshotDayGauge($restaurantId, $userId, $anchor, 'Jour au calendrier', $tz),
            'week' => $this->snapshotOperationalWeekGauge($restaurantId, $userId, $anchor, $tz),
            'month' => $this->snapshotCalendarMonthGauge($restaurantId, $userId, $anchor, false, $tz, $todayY),
            'prev_month' => $this->snapshotCalendarMonthGauge($restaurantId, $userId, $anchor, true, $tz, $todayY),
            default => $this->snapshotDayGauge($restaurantId, $userId, $todayY, 'Aujourd’hui', $tz),
        };

        $roleCode = $this->resolveRoleCodeForUser($restaurantId, $userId);
        $rowMetrics = $this->buildOperationalRowMetrics($restaurantId, $userId, $roleCode, $preset, $anchor, $active);

        return array_merge($base, [
            'dash_preset' => $preset,
            'dash_anchor_ymd' => $anchor,
            'active_period' => $active,
            'row_metrics' => $rowMetrics,
        ]);
    }

    public function restaurantActivityStartYmd(int $restaurantId): string
    {
        $st = $this->database->pdo()->prepare(
            'SELECT MIN(DATE(created_at)) FROM audit_logs WHERE restaurant_id = :rid'
        );
        $st->execute(['rid' => $restaurantId]);
        $d = $st->fetchColumn();
        if (is_string($d) && $d !== '' && str_starts_with($d, '0000') === false) {
            return $d;
        }
        $r = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $created = (string) ($r['created_at'] ?? '');
        if ($created !== '') {
            return substr($created, 0, 10);
        }

        return Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
    }

    public function lastOperationalResetDayYmd(int $restaurantId): ?string
    {
        $st = $this->database->pdo()->prepare(
            'SELECT DATE(created_at) AS d FROM audit_logs
             WHERE restaurant_id = :rid AND action_name IN ("super_admin_data_reset","super_admin_stock_reset")
             ORDER BY created_at DESC LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId]);
        $d = $st->fetchColumn();
        if (!is_string($d) || $d === '' || str_starts_with($d, '0000')) {
            return null;
        }

        return $d;
    }

    /** @return list<array{user_id:int, full_name:string, role_code:?string, gauges: array<string,mixed>}> */
    public function gaugesSnapshotRestaurant(int $restaurantId, string $todayYmd): array
    {
        $users = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
        $out = [];
        foreach ($users as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $out[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $u['role_code'] ?? null,
                'gauges' => $this->gaugesForUser($restaurantId, $uid, $todayYmd),
            ];
        }

        return $out;
    }

    /**
     * Aperçu restaurant : même préréglage « dash » que les modules opérationnels.
     *
     * @return list<array{user_id:int, full_name:string, role_code:?string, gauges: array<string,mixed>}>
     */
    public function gaugesSnapshotRestaurantOperational(int $restaurantId, string $preset, string $anchorYmd): array
    {
        $this->ensureSchema();
        $users = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
        $out = [];
        foreach ($users as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $out[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $u['role_code'] ?? null,
                'gauges' => $this->gaugesForUserOperationalPanel($restaurantId, $uid, $preset, $anchorYmd),
            ];
        }

        $this->sortOperationalGaugeSnapshotRows($out);

        return $out;
    }

    public function proposedSalaryRetentionPercent(?float $monthlyScoreAvg): float
    {
        if ($monthlyScoreAvg === null) {
            return 0.0;
        }
        if ($monthlyScoreAvg >= 90) {
            return 0.0;
        }
        if ($monthlyScoreAvg >= 70) {
            return 5.0;
        }
        if ($monthlyScoreAvg >= 50) {
            return 15.0;
        }

        return 25.0;
    }

    /**
     * Aperçu paie (lecture) : mois, agents, base, jauge, présences, retenue indicative, net proposé.
     *
     * @return array{month:string, period_label:string, period_start:string, period_end:string, rows: list<array<string,mixed>>}
     */
    public function payrollMonthPreview(int $restaurantId, string $monthInput): array
    {
        $this->ensureSchema();
        $rs = Container::getInstance()->get('reportService');
        $tz = $rs->timezoneForRestaurantReports($restaurantId);
        $todayY = $rs->todayForRestaurant($restaurantId);
        $trim = trim($monthInput);
        if (preg_match('/^(\d{4}-\d{2})/', $trim, $m)) {
            $monthKey = $m[1];
        } else {
            $monthKey = substr($todayY, 0, 7);
        }
        $start = $monthKey . '-01';
        try {
            $endMonth = (new DateTimeImmutable($start . ' 00:00:00', $tz))->modify('last day of this month')->format('Y-m-d');
        } catch (\Throwable) {
            $endMonth = $todayY;
        }
        $end = substr($todayY, 0, 7) === $monthKey ? $todayY : $endMonth;

        $pdo = $this->database->pdo();
        $profSt = $pdo->prepare(
            'SELECT user_id, base_salary_monthly, currency FROM staff_payroll_profiles WHERE restaurant_id = :rid'
        );
        $profSt->execute(['rid' => $restaurantId]);
        $profiles = [];
        foreach ($profSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $profiles[(int) ($row['user_id'] ?? 0)] = $row;
        }

        $rows = [];
        foreach (Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId) as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $roleCode = (string) ($u['role_code'] ?? '');
            $monthlyScore = $this->averageEvaluatedDaysInRange($restaurantId, $uid, $start, $end, $tz);
            $retentionPct = $this->proposedSalaryRetentionPercent($monthlyScore);
            $base = (float) ($profiles[$uid]['base_salary_monthly'] ?? 0);
            $currency = (string) ($profiles[$uid]['currency'] ?? 'USD');
            $bonus = 0.0;
            $net = round($base * (1 - $retentionPct / 100) + $bonus, 2);

            $attSt = $pdo->prepare(
                'SELECT COUNT(*) FROM staff_attendance_day
                 WHERE restaurant_id = :rid AND user_id = :uid AND DATE_FORMAT(day_ymd, "%Y-%m") = :m'
            );
            $attSt->execute(['rid' => $restaurantId, 'uid' => $uid, 'm' => $monthKey]);
            $attDays = (int) $attSt->fetchColumn();

            $penSt = $pdo->prepare(
                'SELECT COALESCE(SUM(LEAST(0, delta_points)), 0) AS pts
                 FROM staff_score_ledger
                 WHERE restaurant_id = :rid AND user_id = :uid AND DATE_FORMAT(day_ymd, "%Y-%m") = :m'
            );
            $penSt->execute(['rid' => $restaurantId, 'uid' => $uid, 'm' => $monthKey]);
            $ledgerPenaltyPts = (int) $penSt->fetchColumn();

            $rows[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $roleCode,
                'base_salary_monthly' => $base,
                'currency' => $currency,
                'monthly_score_avg' => $monthlyScore,
                'monthly_score_zone' => $this->zoneFromScoreNullable($monthlyScore),
                'retention_proposed_pct' => $retentionPct,
                'bonus_monthly' => $bonus,
                'net_pay_proposed' => $net,
                'attendance_days_recorded' => $attDays,
                'ledger_penalty_points_month' => $ledgerPenaltyPts,
            ];
        }

        $this->sortPayrollPreviewRows($rows);

        return [
            'month' => $monthKey,
            'period_label' => 'Mois ' . $monthKey,
            'period_start' => $start,
            'period_end' => $end,
            'rows' => $rows,
        ];
    }

    private function resolveRoleCodeForUser(int $restaurantId, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        foreach (Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId) as $u) {
            if ((int) ($u['id'] ?? 0) === $userId) {
                return (string) ($u['role_code'] ?? '');
            }
        }

        return '';
    }

    /**
     * Ordre lecture rapports : serveurs, cuisine, stock, caisse, autres.
     */
    private function operationalRoleSortGroup(?string $roleCode): int
    {
        return match ((string) $roleCode) {
            'cashier_server' => 1,
            'kitchen' => 2,
            'stock_manager' => 3,
            'cashier_accountant' => 4,
            default => 50,
        };
    }

    /**
     * @param array{user_id:int, full_name:string, role_code:?string, gauges: array<string,mixed>} $row
     *
     * @return array{0:int,1:float,2:string}
     */
    private function operationalGaugeSnapshotSortKey(array $row): array
    {
        $role = (string) ($row['role_code'] ?? '');
        $g = is_array($row['gauges'] ?? null) ? $row['gauges'] : [];
        $ap = is_array($g['active_period'] ?? null) ? $g['active_period'] : [];
        $score = $ap['score'] ?? null;
        $tieBreak = is_numeric($score) ? -(float) $score : 1000.0;

        return [
            $this->operationalRoleSortGroup($role),
            $tieBreak,
            mb_strtolower((string) ($row['full_name'] ?? '')),
        ];
    }

    /**
     * @param list<array{user_id:int, full_name:string, role_code:?string, gauges: array<string,mixed>}> $rows
     */
    private function sortOperationalGaugeSnapshotRows(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            $ka = $this->operationalGaugeSnapshotSortKey($a);
            $kb = $this->operationalGaugeSnapshotSortKey($b);
            foreach ([0, 1, 2] as $i) {
                if ($ka[$i] < $kb[$i]) {
                    return -1;
                }
                if ($ka[$i] > $kb[$i]) {
                    return 1;
                }
            }

            return 0;
        });
    }

    /**
     * Métriques lisibles pour tableaux owner / paie (sans réévaluer tout le mois).
     *
     * @return array<string, mixed>
     */
    private function buildOperationalRowMetrics(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $preset,
        string $anchorYmd,
        array $activePeriod,
    ): array {
        $sb = is_array($activePeriod['score_breakdown'] ?? null) ? $activePeriod['score_breakdown'] : [];
        $ms = is_array($sb['month_stats'] ?? null) ? $sb['month_stats'] : [];
        $activite = array_key_exists('actions_total', $sb)
            ? (int) $sb['actions_total']
            : (array_key_exists('action_count', $sb) ? (int) $sb['action_count'] : null);

        $rs = Container::getInstance()->get('reportService');
        $win = $rs->operationalPeriodWindow($restaurantId, $preset, $anchorYmd);
        $s = $win['start']->format('Y-m-d H:i:s');
        $e = $win['end']->format('Y-m-d H:i:s');
        $fromD = $win['start']->format('Y-m-d');
        $toD = $win['end']->modify('-1 day')->format('Y-m-d');

        $prepAvg = null;
        if ($roleCode === 'kitchen') {
            $prepAvg = $this->kitchenAvgPrepMinutesForUser($restaurantId, $userId, $s, $e);
        }

        $shortfalls = 0;
        if ($roleCode === 'cashier_server' && $fromD <= $toD) {
            $shortfalls = $this->ledgerReasonCountForUserDayRange(
                $restaurantId,
                $userId,
                $fromD,
                $toD,
                ['server_shortfall_today', 'server_shortfall_legacy'],
            );
        }

        return [
            'activite_actions' => $activite,
            'jours_evalues_periode' => array_key_exists('evaluated_days', $sb) ? (int) $sb['evaluated_days'] : null,
            'absences_injustifiees' => $ms['days_unjustified_absence'] ?? null,
            'absences_justifiees_maladie' => $ms['days_soft_absence'] ?? null,
            'jours_sans_activite_mesuree' => $ms['days_without_measured_activity'] ?? null,
            'preparation_moy_min' => $prepAvg,
            'manquants_caisse_hits' => $shortfalls,
        ];
    }

    private function kitchenAvgPrepMinutesForUser(int $restaurantId, int $userId, string $s, string $e): ?float
    {
        if ($userId <= 0) {
            return null;
        }
        $st = $this->database->pdo()->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at)) AS avg_m, COUNT(*) AS n
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sr.restaurant_id = :rid AND sri.technical_confirmed_by = :uid
               AND sri.prepared_at IS NOT NULL
               AND sri.prepared_at >= :s AND sri.prepared_at < :e
               AND TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at) >= 0'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $n = (int) ($row['n'] ?? 0);
        if ($n <= 0) {
            return null;
        }
        $avg = round((float) ($row['avg_m'] ?? 0), 1);

        return is_finite($avg) ? $avg : null;
    }

    /**
     * @param list<string> $codes
     */
    private function ledgerReasonCountForUserDayRange(
        int $restaurantId,
        int $userId,
        string $fromYmd,
        string $toYmd,
        array $codes,
    ): int {
        if ($userId <= 0 || $fromYmd > $toYmd || $codes === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $sql = 'SELECT COUNT(*) FROM staff_score_ledger
                WHERE restaurant_id = ? AND user_id = ? AND day_ymd >= ? AND day_ymd <= ? AND reason_code IN (' . $ph . ')';
        $args = array_merge([$restaurantId, $userId, $fromYmd, $toYmd], $codes);
        $st = $this->database->pdo()->prepare($sql);
        $st->execute($args);

        return (int) $st->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0:int,1:float,2:string}
     */
    private function payrollPreviewRowSortKey(array $row): array
    {
        $role = (string) ($row['role_code'] ?? '');
        $m = $row['monthly_score_avg'] ?? null;
        $tieBreak = is_numeric($m) ? -(float) $m : 1000.0;

        return [
            $this->operationalRoleSortGroup($role),
            $tieBreak,
            mb_strtolower((string) ($row['full_name'] ?? '')),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sortPayrollPreviewRows(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            $ka = $this->payrollPreviewRowSortKey($a);
            $kb = $this->payrollPreviewRowSortKey($b);
            foreach ([0, 1, 2] as $i) {
                if ($ka[$i] < $kb[$i]) {
                    return -1;
                }
                if ($ka[$i] > $kb[$i]) {
                    return 1;
                }
            }

            return 0;
        });
    }

    /**
     * @return array{s: string, e: string}
     */
    private function mysqlDayWindowStrings(string $dayYmd, DateTimeZone $tz): array
    {
        try {
            $start = new DateTimeImmutable($dayYmd . ' 00:00:00', $tz);
            $end = $start->modify('+1 day');
        } catch (\Throwable) {
            return ['s' => $dayYmd . ' 00:00:00', 'e' => $dayYmd . ' 23:59:59'];
        }

        return [
            's' => $start->format('Y-m-d H:i:s'),
            'e' => $end->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Volume d’activité « jauge » pour un agent et un jour (aligné audit + domaine métier).
     *
     * @return array{action_count:int, breakdown: list<array{label:string,count:int}>, s:string, e:string}
     */
    private function measureActivityVolumeForDay(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $dayYmd,
        DateTimeZone $tz,
    ): array {
        $pdo = $this->database->pdo();
        $win = $this->mysqlDayWindowStrings($dayYmd, $tz);
        $s = $win['s'];
        $e = $win['e'];
        $breakdown = [];
        $actionCount = 0;
        $operationalRoles = ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'];
        $management = in_array($roleCode, ['owner', 'manager'], true);
        $broadAudit = $management || $roleCode === '' || !in_array($roleCode, $operationalRoles, true);
        if ($broadAudit) {
            $st = $pdo->prepare(
                'SELECT module_name, action_name, COUNT(*) AS c
                 FROM audit_logs
                 WHERE restaurant_id = :rid AND user_id = :uid
                   AND created_at >= :s AND created_at < :e
                 GROUP BY module_name, action_name
                 ORDER BY c DESC
                 LIMIT 40'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $c = (int) ($row['c'] ?? 0);
                $mn = (string) ($row['module_name'] ?? '');
                $an = (string) ($row['action_name'] ?? '');
                $breakdown[] = ['label' => $this->auditActionLabelFr($mn, $an), 'count' => $c];
                $actionCount += $c;
            }
        } else {
            foreach ($this->auditPairsForRole($roleCode) as [$mod, $act]) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM audit_logs
                     WHERE restaurant_id = :rid AND user_id = :uid
                       AND module_name = :m AND action_name = :a
                       AND created_at >= :s AND created_at < :e'
                );
                $st->execute([
                    'rid' => $restaurantId,
                    'uid' => $userId,
                    'm' => $mod,
                    'a' => $act,
                    's' => $s,
                    'e' => $e,
                ]);
                $c = (int) $st->fetchColumn();
                if ($c > 0) {
                    $breakdown[] = ['label' => $this->auditActionLabelFr($mod, $act), 'count' => $c];
                    $actionCount += $c;
                }
            }
        }

        $auditOnlyCount = $actionCount;
        $rs = Container::getInstance()->get('reportService');
        $domainSum = 0;
        if (in_array($roleCode, ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'], true)) {
            foreach ($rs->disciplineDomainActivityLinesForUser($restaurantId, $userId, $roleCode, $s, $e) as $row) {
                $breakdown[] = $row;
                $domainSum += (int) ($row['count'] ?? 0);
            }
            $actionCount = max($auditOnlyCount, $domainSum);
        }

        return ['action_count' => $actionCount, 'breakdown' => $breakdown, 's' => $s, 'e' => $e];
    }

    /** @return list<int> */
    private function peerUserIdsSameRole(int $restaurantId, string $roleCode): array
    {
        $peers = [];
        foreach (Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId) as $u) {
            $pid = (int) ($u['id'] ?? 0);
            if ($pid <= 0 || (string) ($u['role_code'] ?? '') !== $roleCode) {
                continue;
            }
            $peers[] = $pid;
        }

        return $peers;
    }

    /**
     * Écart vs les collègues du même rôle (même jour) + vitesse cuisine relative.
     *
     * @return list<array{label:string,points:int}>
     */
    private function peerRelativeActivityAdjustments(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $dayYmd,
        int $userActionCount,
        DateTimeZone $tz,
        PDO $pdo,
    ): array {
        $operational = ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'];
        if (!in_array($roleCode, $operational, true)) {
            return [];
        }
        $peers = $this->peerUserIdsSameRole($restaurantId, $roleCode);
        if (count($peers) < 2) {
            return [];
        }

        $counts = [];
        foreach ($peers as $pid) {
            $counts[$pid] = $this->measureActivityVolumeForDay($restaurantId, $pid, $roleCode, $dayYmd, $tz)['action_count'];
        }
        $maxC = max($counts);
        if ($maxC < 5) {
            return [];
        }

        $mine = $counts[$userId] ?? 0;
        $others = $counts;
        unset($others[$userId]);
        if ($others === []) {
            return [];
        }
        $medianOther = $this->medianInt(array_values($others));
        $ratio = $medianOther > 0 ? $mine / $medianOther : ($mine > 0 ? 2.5 : 0.0);

        $adj = 0;
        if ($ratio < 0.3) {
            $adj -= 24;
        } elseif ($ratio < 0.5) {
            $adj -= 16;
        } elseif ($ratio < 0.72) {
            $adj -= 8;
        } elseif ($ratio > 1.35) {
            $adj += 10;
        } elseif ($ratio > 1.12) {
            $adj += 5;
        }

        $out = [];
        if ($adj !== 0) {
            $out[] = [
                'label' => 'Comparatif d’activité vs collègues (même rôle, même journée · volume mesuré)',
                'points' => max(-35, min(12, $adj)),
            ];
        }

        if ($roleCode === 'kitchen') {
            $win = $this->mysqlDayWindowStrings($dayYmd, $tz);
            $sp = $this->kitchenPeerSpeedAdjustment($restaurantId, $userId, $peers, $pdo, $win['s'], $win['e']);
            if ($sp !== null) {
                $out[] = $sp;
            }
        }

        return $out;
    }

    /** @param list<int> $vals */
    private function medianInt(array $vals): float
    {
        if ($vals === []) {
            return 0.0;
        }
        sort($vals);
        $n = count($vals);
        $mid = (int) floor(($n - 1) / 2);

        return $n % 2 === 1 ? (float) $vals[$mid] : ((float) $vals[$mid] + (float) $vals[$mid + 1]) / 2.0;
    }

    /**
     * @param list<int> $peerUserIds
     *
     * @return array{label:string,points:int}|null
     */
    private function kitchenPeerSpeedAdjustment(
        int $restaurantId,
        int $userId,
        array $peerUserIds,
        PDO $pdo,
        string $s,
        string $e,
    ): ?array {
        if ($s === '' || $e === '') {
            return null;
        }
        $avgs = [];
        $st = $pdo->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at)) AS avg_m, COUNT(*) AS n
             FROM server_request_items sri
             INNER JOIN server_requests sr ON sr.id = sri.request_id
             WHERE sr.restaurant_id = :rid AND sri.technical_confirmed_by = :uid
               AND sri.prepared_at IS NOT NULL
               AND sri.prepared_at >= :s AND sri.prepared_at < :e
               AND TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at) >= 0'
        );
        foreach ($peerUserIds as $pid) {
            $st->execute(['rid' => $restaurantId, 'uid' => $pid, 's' => $s, 'e' => $e]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['avg_m' => null, 'n' => 0];
            $n = (int) ($row['n'] ?? 0);
            if ($n < 2) {
                $avgs[$pid] = null;
                continue;
            }
            $avgs[$pid] = (float) ($row['avg_m'] ?? 0);
        }
        $mine = $avgs[$userId] ?? null;
        if ($mine === null) {
            return null;
        }
        $peerVals = [];
        foreach ($avgs as $pid => $v) {
            if ($v !== null && $pid !== $userId) {
                $peerVals[] = $v;
            }
        }
        if (count($peerVals) === 0) {
            return null;
        }
        sort($peerVals);
        $med = $peerVals[(int) floor((count($peerVals) - 1) / 2)];
        $pts = 0;
        if ($mine > $med + 25 && $mine >= 75) {
            $pts -= 10;
        } elseif ($mine > $med + 10 && $mine >= 55) {
            $pts -= 5;
        } elseif ($mine < $med - 10 && $mine > 0 && $mine <= 40) {
            $pts += 6;
        }

        if ($pts === 0) {
            return null;
        }

        return ['label' => 'Rapidité cuisine vs collègues (préparation moyenne, journée)', 'points' => max(-14, min(8, $pts))];
    }

    /**
     * Bonus performance (points positifs), en complément des pénalités métier.
     *
     * @return list<array{label:string,points:int}>
     */
    private function metricPerformanceBonuses(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $s,
        string $e,
        array $ledgerReasons,
        PDO $pdo,
    ): array {
        $bonus = [];
        $closedIn = '"VALIDE","CLOTURE","VENDU_TOTAL","VENDU_PARTIEL"';
        if ($roleCode === 'cashier_server') {
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM sales WHERE restaurant_id = :rid AND server_id = :uid
                 AND status IN ($closedIn)
                 AND COALESCE(validated_at, created_at) >= :s AND COALESCE(validated_at, created_at) < :e"
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $mySales = (int) $st->fetchColumn();
            $peers = $this->peerUserIdsSameRole($restaurantId, 'cashier_server');
            if (count($peers) >= 2) {
                $counts = [];
                foreach ($peers as $pid) {
                    $st->execute(['rid' => $restaurantId, 'uid' => $pid, 's' => $s, 'e' => $e]);
                    $counts[$pid] = (int) $st->fetchColumn();
                }
                $sorted = array_values($counts);
                rsort($sorted, SORT_NUMERIC);
                $top = (int) ($sorted[0] ?? 0);
                $second = (int) ($sorted[1] ?? 0);
                $uidMax = null;
                $maxVal = -1;
                foreach ($counts as $pid => $c) {
                    if ($c > $maxVal) {
                        $maxVal = $c;
                        $uidMax = $pid;
                    }
                }
                if ($top >= 2 && $uidMax === $userId && $mySales === $top) {
                    if ($second > 0 && $top >= (int) floor($second * 1.35)) {
                        $bonus[] = ['label' => 'Meilleure activité ventes du jour (nettement devant les autres serveurs)', 'points' => 8];
                    } else {
                        $bonus[] = ['label' => 'Meilleure activité ventes du jour (serveurs)', 'points' => 5];
                    }
                }
            }
            if (
                $mySales >= 2
                && empty($ledgerReasons['server_remittance_rejected'])
            ) {
                $stL = $pdo->prepare(
                    'SELECT COUNT(*) FROM cash_transfers
                     WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                       AND sale_day_ymd IS NOT NULL AND remittance_day_ymd IS NOT NULL
                       AND sale_day_ymd <> remittance_day_ymd
                       AND COALESCE(requested_at, created_at) >= :s
                       AND COALESCE(requested_at, created_at) < :e'
                );
                $stL->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                if ((int) $stL->fetchColumn() === 0) {
                    $stR = $pdo->prepare(
                        'SELECT COUNT(*) FROM cash_transfers
                         WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                           AND status IN ("REMISE_REJETEE_CAISSE","REMISE_REJETEE_GERANT")
                           AND COALESCE(updated_at, requested_at, created_at) >= :s
                           AND COALESCE(updated_at, requested_at, created_at) < :e'
                    );
                    $stR->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                    if ((int) $stR->fetchColumn() === 0) {
                        $bonus[] = ['label' => 'Régularité remises (pas de rejet ni remise tardive ce jour)', 'points' => 4];
                    }
                }
            }
        }
        if ($roleCode === 'kitchen') {
            $st = $pdo->prepare(
                'SELECT AVG(TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at)) AS avg_m, COUNT(*) AS n
                 FROM server_request_items sri
                 INNER JOIN server_requests sr ON sr.id = sri.request_id
                 WHERE sr.restaurant_id = :rid AND sri.technical_confirmed_by = :uid
                   AND sri.prepared_at IS NOT NULL
                   AND sri.prepared_at >= :s AND sri.prepared_at < :e
                   AND TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at) >= 0'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $avgM = (float) ($row['avg_m'] ?? 0);
            $n = (int) ($row['n'] ?? 0);
            if ($n >= 4 && $avgM > 0 && $avgM <= 35.0) {
                $bonus[] = ['label' => 'Excellente rapidité de préparation (volume suffisant)', 'points' => 6];
            }
        }
        if ($roleCode === 'stock_manager') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_movements
                 WHERE restaurant_id = :rid AND (validated_by = :uid OR user_id = :uid)
                   AND validated_at IS NOT NULL
                   AND validated_at >= :s AND validated_at < :e'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $mov = (int) $st->fetchColumn();
            if ($mov >= 4) {
                $stS = $pdo->prepare(
                    'SELECT COUNT(*) FROM kitchen_stock_requests
                     WHERE restaurant_id = :rid AND responded_by = :uid
                       AND responded_at IS NOT NULL
                       AND responded_at >= :s AND responded_at < :e
                       AND TIMESTAMPDIFF(HOUR, created_at, responded_at) > 48'
                );
                $stS->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                if ((int) $stS->fetchColumn() === 0) {
                    $bonus[] = ['label' => 'Magasin réactif (mouvements validés, pas de réponse > 48 h)', 'points' => 5];
                }
            }
        }
        if ($roleCode === 'cashier_accountant') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM cash_transfers
                 WHERE restaurant_id = :rid AND source_type = "sale"
                   AND received_by = :uid
                   AND received_at IS NOT NULL
                   AND received_at >= :s AND received_at < :e'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $rx = (int) $st->fetchColumn();
            if ($rx >= 3) {
                $stLate = $pdo->prepare(
                    'SELECT COUNT(*) FROM cash_transfers
                     WHERE restaurant_id = :rid AND source_type = "sale"
                       AND received_by = :uid
                       AND received_at IS NOT NULL
                       AND received_at >= :s AND received_at < :e
                       AND requested_at IS NOT NULL
                       AND TIMESTAMPDIFF(HOUR, requested_at, received_at) > 24'
                );
                $stLate->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                if ((int) $stLate->fetchColumn() === 0) {
                    $bonus[] = ['label' => 'Caisse réactive (réceptions sans délai excessif)', 'points' => 4];
                }
            }
        }

        return $bonus;
    }

    /** @return list<array{0:string,1:string}> */
    private function auditPairsForRole(string $roleCode): array
    {
        return match ($roleCode) {
            'cashier_server' => [
                ['sales', 'server_request_created'],
                ['sales', 'sale_created'],
                ['sales', 'server_request_closed_as_sale'],
                ['sales', 'server_request_auto_closed_as_sale'],
                ['sales', 'automatic_sale_after_24h'],
                ['sales', 'request_cancelled'],
                ['cash', 'cash_server_remitted'],
            ],
            'kitchen' => [
                ['kitchen', 'server_request_item_fulfilled'],
                ['kitchen', 'kitchen_production_created'],
                ['kitchen', 'sale_return_validated_by_kitchen'],
                ['kitchen', 'kitchen_stock_request_created'],
                ['sales', 'request_declined'],
            ],
            'stock_manager' => [
                ['stock', 'stock_entry_validated'],
                ['stock', 'stock_free_movement_entry'],
                ['stock', 'stock_free_movement_out'],
                ['stock', 'stock_free_movement_return'],
                ['stock', 'stock_inventory_correction'],
                ['stock', 'stock_item_created'],
                ['stock', 'stock_sent_to_kitchen'],
                ['stock', 'stock_return_validated'],
                ['stock', 'kitchen_stock_request_received'],
                ['stock', 'kitchen_stock_request_responded'],
                ['stock', 'kitchen_stock_request_processing_started'],
                ['stock', 'stock_request_declined'],
                ['stock', 'stock_request_cancelled'],
            ],
            'cashier_accountant' => [
                ['cash', 'cash_cashier_received'],
                ['cash', 'cash_remise_rejetee_caisse'],
                ['cash', 'cash_remise_soumise_gerant'],
                ['cash', 'cash_remise_rejetee_gerant'],
            ],
            default => [],
        };
    }

    private function auditActionLabelFr(string $module, string $action): string
    {
        $key = $module . "\0" . $action;
        $map = [
            "sales\0server_request_created" => 'Commandes service créées',
            "sales\0sale_created" => 'Ventes enregistrées',
            "sales\0server_request_closed_as_sale" => 'Commandes clôturées en vente',
            "sales\0server_request_auto_closed_as_sale" => 'Clôtures automatiques (vente)',
            "sales\0automatic_sale_after_24h" => 'Ventes système (24 h)',
            "sales\0request_cancelled" => 'Commandes annulées',
            "cash\0cash_server_remitted" => 'Remises à la caisse',
            "kitchen\0server_request_item_fulfilled" => 'Lignes commande traitées (cuisine)',
            "kitchen\0kitchen_production_created" => 'Productions enregistrées',
            "kitchen\0sale_return_validated_by_kitchen" => 'Retours plats validés',
            "kitchen\0kitchen_stock_request_created" => 'Demandes magasin (cuisine)',
            "sales\0request_declined" => 'Commandes refusées (cuisine)',
            "stock\0stock_entry_validated" => 'Entrées stock validées',
            "stock\0stock_free_movement_entry" => 'Mouvements stock (entrée)',
            "stock\0stock_free_movement_out" => 'Mouvements stock (sortie)',
            "stock\0stock_free_movement_return" => 'Mouvements stock (retour)',
            "stock\0stock_inventory_correction" => 'Inventaires / corrections',
            "stock\0kitchen_stock_request_received" => 'Réceptions demandes magasin',
            "stock\0kitchen_stock_request_responded" => 'Réponses demandes magasin',
            "stock\0kitchen_stock_request_processing_started" => 'Prises en charge demandes magasin',
            "cash\0cash_cashier_received" => 'Remises vente reçues',
            "cash\0cash_remise_rejetee_caisse" => 'Remises rejetées (caisse)',
            "cash\0cash_remise_soumise_gerant" => 'Remises soumises au gérant',
            "cash\0cash_remise_rejetee_gerant" => 'Remises rejetées par le gérant',
        ];

        return $map[$key] ?? ($module . ' · ' . $action);
    }

    /** @param list<array<string, mixed>> $ledgerLines */
    private function ledgerDeclaresDayExempt(array $ledgerLines): bool
    {
        foreach ($ledgerLines as $ln) {
            $c = strtoupper(trim((string) ($ln['reason_code'] ?? '')));
            if (in_array($c, ['DISCIPLINE_DAY_EXEMPT', 'DISCIPLINE_EXEMPT_DAY', 'DISCIPLINE_EXONERATION'], true)) {
                return true;
            }
        }

        return false;
    }

    private function attendancePlannedStatusForDay(int $restaurantId, int $userId, string $dayYmd): string
    {
        $this->ensureSchema();
        $st = $this->database->pdo()->prepare(
            'SELECT planned_status FROM staff_attendance_day
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d
             LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd]);
        $raw = $st->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return 'TRAVAIL';
        }

        return $this->normalizeAttendanceStatus($raw);
    }

    private function normalizeAttendanceStatus(string $raw): string
    {
        $u = strtoupper(str_replace([' ', '-'], '_', trim($raw)));
        $aliases = [
            'TRAVAIL' => 'TRAVAIL',
            'WORK' => 'TRAVAIL',
            'REPOS' => 'REPOS',
            'REST' => 'REPOS',
            'OFF' => 'REPOS',
            'CONGE' => 'REPOS',
            'EXONERE' => 'EXONERE',
            'EXONÉRÉ' => 'EXONERE',
            'EXEMPT' => 'EXONERE',
            'ABSENCE_AUTORISEE' => 'ABSENCE_AUTORISEE',
            'ABSENCE_AUTORISÉE' => 'ABSENCE_AUTORISEE',
            'ABS_AUTORISEE' => 'ABSENCE_AUTORISEE',
            'MALADIE' => 'MALADIE',
            'SICK' => 'MALADIE',
        ];

        return $aliases[$u] ?? (str_starts_with($u, 'REPOS') ? 'REPOS' : 'TRAVAIL');
    }

    /**
     * @param list<array{label:string,points:int}> $extraPenalties
     *
     * @return array{evaluated:bool, score:?int, action_count:int, activity_breakdown: list<array{label:string,count:int}>, ledger_delta:int, ledger_lines: list<array<string,mixed>>, extra_penalties: list<array{label:string,points:int}>, base_score:int, evaluation_kind:string, synthetic_adjustment:int}
     */
    private function finalizeEvaluatedDay(
        int $actionCount,
        array $breakdown,
        int $ledgerDelta,
        array $ledgerLines,
        array $extraPenalties,
        string $evaluationKind,
        int $syntheticAdjustment,
    ): array {
        $extraSum = 0;
        foreach ($extraPenalties as $ep) {
            $extraSum += (int) ($ep['points'] ?? 0);
        }
        $raw = 100 + $ledgerDelta + $extraSum + $syntheticAdjustment;
        if (!is_finite($raw)) {
            $raw = 100.0;
        }
        $score = max(0, min(100, (int) round($raw)));

        return [
            'evaluated' => true,
            'score' => $score,
            'action_count' => $actionCount,
            'activity_breakdown' => $breakdown,
            'ledger_delta' => $ledgerDelta,
            'ledger_lines' => $ledgerLines,
            'extra_penalties' => $extraPenalties,
            'base_score' => 100,
            'evaluation_kind' => $evaluationKind,
            'synthetic_adjustment' => $syntheticAdjustment,
        ];
    }

    /**
     * Évaluation d’un jour calendrier (restaurant) : activité réelle + journal des points + pénalités complémentaires.
     *
     * @return array{
     *   evaluated: bool,
     *   score: ?int,
     *   action_count: int,
     *   activity_breakdown: list<array{label:string,count:int}>,
     *   ledger_delta: int,
     *   ledger_lines: list<array<string,mixed>>,
     *   extra_penalties: list<array{label:string,points:int}>,
     *   base_score: int
     * }
     */
    private function evaluateCalendarDay(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $dayYmd,
        DateTimeZone $tz,
    ): array {
        $pdo = $this->database->pdo();
        $pack = $this->measureActivityVolumeForDay($restaurantId, $userId, $roleCode, $dayYmd, $tz);
        $actionCount = $pack['action_count'];
        $breakdown = $pack['breakdown'];
        $s = $pack['s'];
        $e = $pack['e'];

        $ledgerLines = $this->ledgerLinesForDay($restaurantId, $userId, $dayYmd);
        $ledgerDelta = 0;
        $ledgerReasons = [];
        foreach ($ledgerLines as $ln) {
            $ledgerDelta += (int) ($ln['delta_points'] ?? 0);
            $ledgerReasons[(string) ($ln['reason_code'] ?? '')] = true;
        }

        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        if ($dayYmd < $glob) {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => $ledgerDelta,
                'ledger_lines' => $ledgerLines,
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'pre_reset',
                'synthetic_adjustment' => 0,
            ];
        }

        $firstEver = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($firstEver === null || $dayYmd < $firstEver) {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => $ledgerDelta,
                'ledger_lines' => $ledgerLines,
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => $firstEver === null ? 'never_active' : 'pre_hire',
                'synthetic_adjustment' => 0,
            ];
        }

        if ($actionCount > 0) {
            $extraPenalties = $this->metricExtraPenalties(
                $restaurantId,
                $userId,
                $roleCode,
                $dayYmd,
                $s,
                $e,
                $ledgerReasons,
                $pdo,
            );
            $bonuses = $this->metricPerformanceBonuses(
                $restaurantId,
                $userId,
                $roleCode,
                $s,
                $e,
                $ledgerReasons,
                $pdo,
            );
            $peerAdj = $this->peerRelativeActivityAdjustments(
                $restaurantId,
                $userId,
                $roleCode,
                $dayYmd,
                $actionCount,
                $tz,
                $pdo,
            );
            $merged = array_merge($extraPenalties, $bonuses, $peerAdj);

            return $this->finalizeEvaluatedDay(
                $actionCount,
                $breakdown,
                $ledgerDelta,
                $ledgerLines,
                $merged,
                'audit_activity',
                0,
            );
        }

        if ($this->ledgerDeclaresDayExempt($ledgerLines)) {
            $bd = [['label' => 'Exonération discipline (journal) · jour neutre', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'neutral_exempt', 0);
        }

        $planned = $this->attendancePlannedStatusForDay($restaurantId, $userId, $dayYmd);
        if ($planned === 'REPOS') {
            $bd = [['label' => 'Jour de repos (planning) · neutre', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'neutral_rest', 0);
        }
        if ($planned === 'EXONERE') {
            $bd = [['label' => 'Exonéré (planning) · neutre', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'neutral_exempt', 0);
        }
        if ($planned === 'ABSENCE_AUTORISEE') {
            $bd = [['label' => 'Absence autorisée (planning) · pénalité légère', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_authorized', -12);
        }
        if ($planned === 'MALADIE') {
            $bd = [['label' => 'Maladie (planning) · pénalité légère', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_illness', -14);
        }

        $bd = [['label' => 'Aucune activité mesurée (jour ouvré) · absence / inactivité', 'count' => 0]];

        return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_unjustified', -35);
    }

    /**
     * @param array<string, bool> $ledgerReasons
     *
     * @return list<array{label:string,points:int}>
     */
    private function metricExtraPenalties(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $dayYmd,
        string $s,
        string $e,
        array $ledgerReasons,
        PDO $pdo,
    ): array {
        $out = [];
        if ($roleCode === 'cashier_server') {
            if (empty($ledgerReasons['server_remittance_rejected'])) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM cash_transfers
                     WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                       AND status IN ("REMISE_REJETEE_CAISSE","REMISE_REJETEE_GERANT")
                       AND COALESCE(updated_at, requested_at, created_at) >= :s
                       AND COALESCE(updated_at, requested_at, created_at) < :e'
                );
                $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                $rej = (int) $st->fetchColumn();
                if ($rej > 0) {
                    $pts = max(-18, -8 * min(2, $rej));
                    $out[] = ['label' => 'Remises rejetées (période)', 'points' => $pts];
                }
            }
            $st2 = $pdo->prepare(
                'SELECT COUNT(*) FROM cash_transfers
                 WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                   AND sale_day_ymd IS NOT NULL AND remittance_day_ymd IS NOT NULL
                   AND sale_day_ymd <> remittance_day_ymd
                   AND COALESCE(requested_at, created_at) >= :s
                   AND COALESCE(requested_at, created_at) < :e'
            );
            $st2->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $late = (int) $st2->fetchColumn();
            if ($late > 0) {
                $pts = max(-15, -5 * min(3, $late));
                $out[] = ['label' => 'Remises tardives (jour de vente ≠ jour de remise)', 'points' => $pts];
            }
        }
        if ($roleCode === 'kitchen') {
            $st = $pdo->prepare(
                'SELECT AVG(TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at)) AS avg_m
                 FROM server_request_items sri
                 INNER JOIN server_requests sr ON sr.id = sri.request_id
                 WHERE sr.restaurant_id = :rid AND sri.technical_confirmed_by = :uid
                   AND sri.prepared_at IS NOT NULL
                   AND sri.prepared_at >= :s AND sri.prepared_at < :e
                   AND TIMESTAMPDIFF(MINUTE, sri.created_at, sri.prepared_at) >= 0'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $avgM = (float) ($row['avg_m'] ?? 0);
            if ($avgM > 120.0) {
                $out[] = ['label' => 'Délai moyen de préparation élevé (> 2 h)', 'points' => -8];
            }
        }
        if ($roleCode === 'stock_manager') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM kitchen_stock_requests
                 WHERE restaurant_id = :rid AND responded_by = :uid
                   AND responded_at IS NOT NULL
                   AND responded_at >= :s AND responded_at < :e
                   AND TIMESTAMPDIFF(HOUR, created_at, responded_at) > 48'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $slow = (int) $st->fetchColumn();
            if ($slow > 0) {
                $out[] = ['label' => 'Demandes magasin réponses très tardives (> 48 h)', 'points' => -6];
            }
        }
        if ($roleCode === 'cashier_accountant') {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM cash_transfers
                 WHERE restaurant_id = :rid AND source_type = "sale"
                   AND received_by = :uid
                   AND received_at IS NOT NULL
                   AND received_at >= :s AND received_at < :e
                   AND requested_at IS NOT NULL
                   AND TIMESTAMPDIFF(HOUR, requested_at, received_at) > 24'
            );
            $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $lateRx = (int) $st->fetchColumn();
            if ($lateRx > 0) {
                $pts = max(-10, -5 * min(2, $lateRx));
                $out[] = ['label' => 'Réceptions caisse lentes (> 24 h après remise)', 'points' => $pts];
            }
            $stE = $pdo->prepare(
                'SELECT COUNT(*) FROM cash_transfers
                 WHERE restaurant_id = :rid AND source_type = "sale"
                   AND received_by = :uid
                   AND received_at >= :s AND received_at < :e
                   AND status = "ECART_SIGNALE"'
            );
            $stE->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
            $ecartN = (int) $stE->fetchColumn();
            if ($ecartN > 0) {
                $out[] = ['label' => 'Écarts signalés à la réception', 'points' => max(-12, -6 * min(2, $ecartN))];
            }
        }

        return $out;
    }

    private function compositeDayScoreNullable(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $dayYmd,
        DateTimeZone $tz,
    ): ?int {
        $ev = $this->evaluateCalendarDay($restaurantId, $userId, $roleCode, $dayYmd, $tz);

        return $ev['evaluated'] ? $ev['score'] : null;
    }

    private function scoreForDayOrNull(int $restaurantId, int $userId, string $dayYmd, DateTimeZone $tz): ?int
    {
        $this->ensureSchema();
        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);

        return $this->compositeDayScoreNullable($restaurantId, $userId, $role, $dayYmd, $tz);
    }

    private function averageLastDaysNullable(int $restaurantId, int $userId, string $todayYmd, int $days, DateTimeZone $tz): ?float
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return null;
        }
        $floor = max($glob, $userFirst);
        try {
            $cursor = new DateTimeImmutable($todayYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return null;
        }
        $sum = 0.0;
        $evalDays = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $cursor->modify('-' . $i . ' days')->format('Y-m-d');
            if ($d < $floor) {
                continue;
            }
            $sc = $this->scoreForDayOrNull($restaurantId, $userId, $d, $tz);
            if ($sc !== null) {
                $sum += $sc;
                $evalDays++;
            }
        }

        if ($evalDays <= 0) {
            return null;
        }
        $r = round($sum / $evalDays, 1);

        return is_finite($r) ? $r : null;
    }

    private function averageMonthToDateNullable(int $restaurantId, int $userId, string $todayYmd, DateTimeZone $tz): ?float
    {
        try {
            $monthFirst = new DateTimeImmutable(substr($todayYmd, 0, 7) . '-01');
            $end = new DateTimeImmutable($todayYmd);
        } catch (\Throwable) {
            return null;
        }
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return null;
        }
        $monthStartYmd = $monthFirst->format('Y-m-d') >= $glob ? $monthFirst->format('Y-m-d') : $glob;
        $startYmd = max($monthStartYmd, $userFirst);
        try {
            $start = new DateTimeImmutable($startYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return null;
        }
        if ($start > $end) {
            return null;
        }
        $sum = 0.0;
        $evalDays = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $sc = $this->scoreForDayOrNull($restaurantId, $userId, $d->format('Y-m-d'), $tz);
            if ($sc !== null) {
                $sum += $sc;
                $evalDays++;
            }
        }

        if ($evalDays <= 0) {
            return null;
        }
        $r = round($sum / $evalDays, 1);

        return is_finite($r) ? $r : null;
    }

    private function effectiveGlobalStartYmd(int $restaurantId): string
    {
        $activity = $this->restaurantActivityStartYmd($restaurantId);
        $reset = $this->lastOperationalResetDayYmd($restaurantId);
        $starts = [$activity];
        if ($reset !== null) {
            try {
                $starts[] = (new DateTimeImmutable($reset))->modify('+1 day')->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        return max($starts);
    }

    /**
     * Journal + pénalités complémentaires pour l’affichage partiel.
     *
     * @param array<string, mixed> $ev
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerPenaltyRowsForGauge(array $ev): array
    {
        $rows = [];
        foreach ($ev['ledger_lines'] ?? [] as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $rows[] = [
                'kind' => 'ledger',
                'delta_points' => (int) ($ln['delta_points'] ?? 0),
                'label' => (string) ($ln['label'] ?? ''),
            ];
        }
        foreach ($ev['extra_penalties'] ?? [] as $ep) {
            if (!is_array($ep)) {
                continue;
            }
            $rows[] = [
                'kind' => 'penalty',
                'delta_points' => (int) ($ep['points'] ?? 0),
                'label' => (string) ($ep['label'] ?? ''),
            ];
        }
        $syn = (int) ($ev['synthetic_adjustment'] ?? 0);
        if ($syn !== 0) {
            $ek = (string) ($ev['evaluation_kind'] ?? '');
            $synLabel = match ($ek) {
                'absence_authorized' => 'Ajustement discipline · absence autorisée',
                'absence_illness' => 'Ajustement discipline · maladie',
                'absence_unjustified' => 'Ajustement discipline · absence / inactivité non justifiée',
                default => 'Ajustement discipline (jour sans mesure d’activité)',
            };
            $rows[] = [
                'kind' => 'synthetic',
                'delta_points' => $syn,
                'label' => $synLabel,
            ];
        }

        return $rows;
    }

    /**
     * @return array{titre: string, jour: string|null, score: int|float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes?: int, note?: string, score_breakdown?: array<string, mixed>}
     */
    private function snapshotDayGauge(int $restaurantId, int $userId, string $dayYmd, string $titre, DateTimeZone $tz): array
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        if ($dayYmd < $glob) {
            return [
                'titre' => $titre,
                'jour' => $dayYmd,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'score_breakdown' => ['evaluated' => false, 'action_count' => 0],
                'note' => 'Avant le début d’activité enregistré : non évalué.',
            ];
        }

        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);
        $ev = $this->evaluateCalendarDay($restaurantId, $userId, $role, $dayYmd, $tz);
        if (!$ev['evaluated']) {
            $why = (string) ($ev['evaluation_kind'] ?? '');
            $note = match ($why) {
                'never_active' => 'Non évalué — aucune activité historique dans ce restaurant.',
                'pre_hire' => 'Non évalué — agent pas encore en service à cette date.',
                'pre_reset' => 'Non évalué — période avant donnée exploitable après réinitialisation.',
                default => 'Non évalué.',
            };

            return [
                'titre' => $titre,
                'jour' => $dayYmd,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'score_breakdown' => $ev,
                'note' => $note,
            ];
        }

        return [
            'titre' => $titre,
            'jour' => $dayYmd,
            'score' => $ev['score'],
            'zone' => $this->zoneFromScore((float) ($ev['score'] ?? 0)),
            'points_detail' => $this->ledgerPenaltyRowsForGauge($ev),
            'score_breakdown' => $ev,
        ];
    }

    /**
     * Moyenne sur la même semaine calendaire que le pulse opérationnel (lundi → lundi exclus).
     *
     * @return array{titre: string, jour: null, score: float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes: int, note?: string, score_breakdown?: array<string, mixed>}
     */
    private function snapshotOperationalWeekGauge(int $restaurantId, int $userId, string $anchorYmd, DateTimeZone $tz): array
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $win = Container::getInstance()->get('reportService')->operationalPeriodWindow($restaurantId, 'week', $anchorYmd);
        $titre = (string) ($win['label'] ?? 'Semaine');
        $start = $win['start'];
        $endExcl = $win['end'];
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return [
                'titre' => $titre,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'jours_moyennes' => 0,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'window_days' => 0],
                'note' => 'Aucune activité historique pour cet agent : non évalué.',
            ];
        }
        $floor = max($glob, $userFirst);
        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);
        $sum = 0.0;
        $inWindow = 0;
        $evalDays = 0;
        $details = [];
        $actionsSum = 0;
        for ($d = $start; $d < $endExcl; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            if ($ymd < $floor) {
                continue;
            }
            $inWindow++;
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $role, $ymd, $tz);
            if ($ev['evaluated']) {
                $sum += (float) ($ev['score'] ?? 0);
                $evalDays++;
                $actionsSum += (int) ($ev['action_count'] ?? 0);
                foreach ($this->ledgerPenaltyRowsForGauge($ev) as $row) {
                    $details[] = $row;
                }
            }
        }

        if ($evalDays === 0) {
            return [
                'titre' => $titre,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => array_slice($details, -24),
                'jours_moyennes' => $inWindow,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'window_days' => $inWindow],
                'note' => $inWindow <= 0
                    ? 'Aucun jour applicable dans cette semaine (avant entrée en service ou reset).'
                    : 'Aucune journée évaluable sur cette semaine : non évalué.',
            ];
        }

        $avg = round($sum / $evalDays, 1);
        if (!is_finite($avg)) {
            $avg = 0.0;
        }

        return [
            'titre' => $titre,
            'jour' => null,
            'score' => $avg,
            'zone' => $this->zoneFromScore($avg),
            'points_detail' => array_slice($details, -24),
            'jours_moyennes' => $evalDays,
            'score_breakdown' => [
                'evaluated_days' => $evalDays,
                'actions_total' => $actionsSum,
                'window_days' => $inWindow,
            ],
        ];
    }

    /**
     * @return array{titre: string, jour: null, score: float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes: int, note?: string, score_breakdown?: array<string, mixed>}
     */
    private function snapshotRollingAverageGauge(int $restaurantId, int $userId, string $anchorYmd, int $days, string $titre, DateTimeZone $tz): array
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return [
                'titre' => $titre,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'jours_moyennes' => 0,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'window_days' => 0],
                'note' => 'Aucune activité historique pour cet agent : non évalué.',
            ];
        }
        $floor = max($glob, $userFirst);
        try {
            $end = new DateTimeImmutable($anchorYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return ['titre' => $titre, 'jour' => null, 'score' => null, 'zone' => 'non_evalue', 'points_detail' => [], 'jours_moyennes' => 0, 'note' => 'Date invalide : non évalué.'];
        }
        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);
        $sum = 0.0;
        $inWindow = 0;
        $evalDays = 0;
        $details = [];
        $actionsSum = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $end->modify('-' . $i . ' days')->format('Y-m-d');
            if ($d < $floor) {
                continue;
            }
            $inWindow++;
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $role, $d, $tz);
            if ($ev['evaluated']) {
                $sum += (float) ($ev['score'] ?? 0);
                $evalDays++;
                $actionsSum += (int) ($ev['action_count'] ?? 0);
                foreach ($this->ledgerPenaltyRowsForGauge($ev) as $row) {
                    $details[] = $row;
                }
            }
        }

        if ($evalDays === 0) {
            return [
                'titre' => $titre,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => array_slice($details, -24),
                'jours_moyennes' => $inWindow,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'window_days' => $inWindow],
                'note' => $inWindow <= 0
                    ? 'Aucun jour applicable dans la fenêtre (avant entrée en service).'
                    : 'Aucune journée évaluable sur cette période : non évalué.',
            ];
        }

        $avg = round($sum / $evalDays, 1);
        if (!is_finite($avg)) {
            $avg = 0.0;
        }

        return [
            'titre' => $titre,
            'jour' => null,
            'score' => $avg,
            'zone' => $this->zoneFromScore((float) $avg),
            'points_detail' => array_slice($details, -24),
            'jours_moyennes' => $evalDays,
            'score_breakdown' => [
                'evaluated_days' => $evalDays,
                'actions_total' => $actionsSum,
                'window_days' => $inWindow,
            ],
        ];
    }

    /**
     * @return array{titre: string, jour: null, score: float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes: int, note?: string, score_breakdown?: array<string, mixed>}
     */
    private function snapshotCalendarMonthGauge(int $restaurantId, int $userId, string $anchorYmd, bool $previous, DateTimeZone $tz, string $todayYmd): array
    {
        try {
            $anchor = new DateTimeImmutable($anchorYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return ['titre' => 'Mois', 'jour' => null, 'score' => null, 'zone' => 'non_evalue', 'points_detail' => [], 'jours_moyennes' => 0, 'note' => 'Date invalide : non évalué.'];
        }
        if ($previous) {
            $firstThis = $anchor->modify('first day of this month')->setTime(0, 0, 0);
            $start = $firstThis->modify('-1 month');
            $end = $firstThis->modify('-1 day')->setTime(0, 0, 0);
            $label = 'Mois précédent · ' . $start->format('m/Y');
        } else {
            $start = $anchor->modify('first day of this month')->setTime(0, 0, 0);
            $endClamp = min($anchor->format('Y-m-d'), $todayYmd);
            $end = new DateTimeImmutable($endClamp . ' 00:00:00', $tz);
            $label = 'Mois en cours · ' . $start->format('m/Y');
        }
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $cursor = $start->format('Y-m-d') >= $glob ? $start : new DateTimeImmutable($glob . ' 00:00:00', $tz);
        $userFirst = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($userFirst === null) {
            return [
                'titre' => $label,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'jours_moyennes' => 0,
                'score_breakdown' => [
                    'evaluated_days' => 0,
                    'actions_total' => 0,
                    'calendar_days' => 0,
                    'month_stats' => [],
                ],
                'note' => 'Aucune activité historique pour cet agent : non évalué.',
            ];
        }
        $cursorY = max($cursor->format('Y-m-d'), $userFirst);
        try {
            $cursor = new DateTimeImmutable($cursorY . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return ['titre' => $label, 'jour' => null, 'score' => null, 'zone' => 'non_evalue', 'points_detail' => [], 'jours_moyennes' => 0, 'note' => 'Période invalide : non évalué.'];
        }
        if ($cursor > $end) {
            return [
                'titre' => $label,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'jours_moyennes' => 0,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'calendar_days' => 0, 'month_stats' => []],
                'note' => 'Période entièrement avant l’entrée en service de l’agent : non évalué.',
            ];
        }
        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);
        $sum = 0.0;
        $calendarDays = 0;
        $evalDays = 0;
        $details = [];
        $actionsSum = 0;
        $stActivity = 0;
        $stRest = 0;
        $stExempt = 0;
        $stSoft = 0;
        $stUnj = 0;
        $stZeroAct = 0;
        $penOff = 0;
        for ($d = $cursor; $d <= $end; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $calendarDays++;
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $role, $ymd, $tz);
            if ($ev['evaluated']) {
                $sum += (float) ($ev['score'] ?? 0);
                $evalDays++;
                $ac = (int) ($ev['action_count'] ?? 0);
                $actionsSum += $ac;
                if ($ac === 0) {
                    $stZeroAct++;
                }
                $sc = (int) ($ev['score'] ?? 0);
                $penOff += max(0, 100 - $sc);
                $ek = (string) ($ev['evaluation_kind'] ?? '');
                if ($ek === 'audit_activity') {
                    $stActivity++;
                } elseif ($ek === 'neutral_rest') {
                    $stRest++;
                } elseif ($ek === 'neutral_exempt') {
                    $stExempt++;
                } elseif ($ek === 'absence_authorized' || $ek === 'absence_illness') {
                    $stSoft++;
                } elseif ($ek === 'absence_unjustified') {
                    $stUnj++;
                }
                foreach ($this->ledgerPenaltyRowsForGauge($ev) as $row) {
                    $details[] = $row;
                }
            }
        }

        $monthStats = [
            'days_scored' => $evalDays,
            'days_with_activity' => $stActivity,
            'days_without_measured_activity' => $stZeroAct,
            'days_rest_neutral' => $stRest,
            'days_exempt_neutral' => $stExempt,
            'days_soft_absence' => $stSoft,
            'days_unjustified_absence' => $stUnj,
            'days_no_activity_measured' => $stRest + $stExempt + $stSoft + $stUnj,
            'penalty_points_off_base' => $penOff,
        ];

        if ($evalDays === 0) {
            return [
                'titre' => $label,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => array_slice($details, -40),
                'jours_moyennes' => $calendarDays,
                'score_breakdown' => ['evaluated_days' => 0, 'actions_total' => 0, 'calendar_days' => $calendarDays, 'month_stats' => $monthStats],
                'note' => 'Aucune journée évaluable sur ce mois : non évalué.',
            ];
        }

        $avg = round($sum / $evalDays, 1);
        if (!is_finite($avg)) {
            $avg = 0.0;
        }

        return [
            'titre' => $label,
            'jour' => null,
            'score' => $avg,
            'zone' => $this->zoneFromScore((float) $avg),
            'points_detail' => array_slice($details, -40),
            'jours_moyennes' => $evalDays,
            'score_breakdown' => [
                'evaluated_days' => $evalDays,
                'actions_total' => $actionsSum,
                'calendar_days' => $calendarDays,
                'month_stats' => $monthStats,
            ],
            'note' => 'Moyenne provisoire du mois sur les jours depuis l’entrée en service (activité, repos neutre, absences pénalisées).',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ledgerLinesForDay(int $restaurantId, int $userId, string $dayYmd): array
    {
        $st = $this->database->pdo()->prepare(
            'SELECT reason_code, delta_points, label FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d
             ORDER BY id ASC'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Profil discipline : excellent ≥90, bon ≥75, moyen ≥60, problématique ≥40, très problématique <40.
     */
    private function zoneFromScore(float $s): string
    {
        if (!is_finite($s)) {
            return 'non_evalue';
        }
        if ($s >= 90) {
            return 'vert';
        }
        if ($s >= 75) {
            return 'jaune';
        }
        if ($s >= 60) {
            return 'orange';
        }
        if ($s >= 40) {
            return 'rouge';
        }

        return 'rouge_critique';
    }

    private function zoneFromScoreNullable(?float $s): string
    {
        if ($s === null || !is_finite($s)) {
            return 'non_evalue';
        }

        return $this->zoneFromScore($s);
    }
}

