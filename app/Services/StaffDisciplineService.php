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
        $daily = $this->scoreForDayOrNull($restaurantId, $userId, $todayYmd);
        $weekly = $this->averageLastDaysNullable($restaurantId, $userId, $todayYmd, 7);
        $monthly = $this->averageMonthToDateNullable($restaurantId, $userId, $todayYmd);

        return [
            'daily' => $daily,
            'weekly_avg' => $weekly,
            'monthly_avg' => $monthly,
            'zone' => $this->zoneFromScoreNullable($monthly),
            'ledger_preview' => array_slice($this->listLedgerForUserMonth($restaurantId, $userId, $todayYmd), -12),
        ];
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
        $base = $this->gaugesForUser($restaurantId, $userId, $todayY);

        $active = match ($preset) {
            'today' => $this->snapshotDayGauge($restaurantId, $userId, $todayY, 'Aujourd’hui', $tz),
            'yesterday' => $this->snapshotDayGauge($restaurantId, $userId, $yesterday, 'Hier', $tz),
            'date' => $this->snapshotDayGauge($restaurantId, $userId, $anchor, 'Jour au calendrier', $tz),
            'week' => $this->snapshotRollingAverageGauge($restaurantId, $userId, $anchor, 7, 'Moyenne sur 7 jours', $tz),
            'month' => $this->snapshotCalendarMonthGauge($restaurantId, $userId, $anchor, false, $tz, $todayY),
            'prev_month' => $this->snapshotCalendarMonthGauge($restaurantId, $userId, $anchor, true, $tz, $todayY),
            default => $this->snapshotDayGauge($restaurantId, $userId, $todayY, 'Aujourd’hui', $tz),
        };

        return array_merge($base, [
            'dash_preset' => $preset,
            'dash_anchor_ymd' => $anchor,
            'active_period' => $active,
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

    private function scoreForDayOrNull(int $restaurantId, int $userId, string $dayYmd): ?int
    {
        $st = $this->database->pdo()->prepare(
            'SELECT COALESCE(SUM(delta_points), 0) AS delta_sum, COUNT(*) AS line_count
             FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($row['line_count'] ?? 0) <= 0) {
            return null;
        }
        $delta = (int) ($row['delta_sum'] ?? 0);

        return max(0, min(100, 100 + $delta));
    }

    private function averageLastDaysNullable(int $restaurantId, int $userId, string $todayYmd, int $days): ?float
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $tz = new DateTimeImmutable($todayYmd);
        $sum = 0.0;
        $evalDays = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $tz->modify('-' . $i . ' days')->format('Y-m-d');
            if ($d < $glob) {
                continue;
            }
            $sc = $this->scoreForDayOrNull($restaurantId, $userId, $d);
            if ($sc !== null) {
                $sum += $sc;
                $evalDays++;
            }
        }

        return $evalDays > 0 ? round($sum / $evalDays, 1) : null;
    }

    private function averageMonthToDateNullable(int $restaurantId, int $userId, string $todayYmd): ?float
    {
        try {
            $monthFirst = new DateTimeImmutable(substr($todayYmd, 0, 7) . '-01');
            $end = new DateTimeImmutable($todayYmd);
        } catch (\Throwable) {
            return null;
        }
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $start = $monthFirst->format('Y-m-d') >= $glob ? $monthFirst : new DateTimeImmutable($glob);
        $sum = 0.0;
        $evalDays = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $sc = $this->scoreForDayOrNull($restaurantId, $userId, $d->format('Y-m-d'));
            if ($sc !== null) {
                $sum += $sc;
                $evalDays++;
            }
        }

        return $evalDays > 0 ? round($sum / $evalDays, 1) : null;
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
     * @return array{titre: string, jour: string|null, score: int|float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes?: int, note?: string}
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
                'note' => 'Avant le début d’activité enregistré : non évalué.',
            ];
        }

        $score = $this->scoreForDayOrNull($restaurantId, $userId, $dayYmd);
        if ($score === null) {
            return [
                'titre' => $titre,
                'jour' => $dayYmd,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => [],
                'note' => 'Aucune donnée de points pour ce jour : non évalué (pas de 100 % par défaut).',
            ];
        }

        return [
            'titre' => $titre,
            'jour' => $dayYmd,
            'score' => $score,
            'zone' => $this->zoneFromScore((float) $score),
            'points_detail' => $this->ledgerLinesForDay($restaurantId, $userId, $dayYmd),
        ];
    }

    /**
     * @return array{titre: string, jour: null, score: float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes: int, note?: string}
     */
    private function snapshotRollingAverageGauge(int $restaurantId, int $userId, string $anchorYmd, int $days, string $titre, DateTimeZone $tz): array
    {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        try {
            $end = new DateTimeImmutable($anchorYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return ['titre' => $titre, 'jour' => null, 'score' => null, 'zone' => 'non_evalue', 'points_detail' => [], 'jours_moyennes' => 0, 'note' => 'Date invalide : non évalué.'];
        }
        $sum = 0.0;
        $inWindow = 0;
        $evalDays = 0;
        $details = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $end->modify('-' . $i . ' days')->format('Y-m-d');
            if ($d < $glob) {
                continue;
            }
            $inWindow++;
            $sd = $this->scoreForDayOrNull($restaurantId, $userId, $d);
            if ($sd !== null) {
                $sum += $sd;
                $evalDays++;
            }
            foreach ($this->ledgerLinesForDay($restaurantId, $userId, $d) as $line) {
                $details[] = $line;
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
                'note' => $inWindow <= 0
                    ? 'Aucun jour calendaire dans la fenêtre : non évalué.'
                    : 'Aucun jour avec points sur cette période : non évalué.',
            ];
        }

        $avg = round($sum / $evalDays, 1);

        return [
            'titre' => $titre,
            'jour' => null,
            'score' => $avg,
            'zone' => $this->zoneFromScore((float) $avg),
            'points_detail' => array_slice($details, -24),
            'jours_moyennes' => $evalDays,
        ];
    }

    /**
     * @return array{titre: string, jour: null, score: float|null, zone: string, points_detail: list<array<string, mixed>>, jours_moyennes: int, note?: string}
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
        $sum = 0.0;
        $calendarDays = 0;
        $evalDays = 0;
        $details = [];
        for ($d = $cursor; $d <= $end; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $calendarDays++;
            $sd = $this->scoreForDayOrNull($restaurantId, $userId, $ymd);
            if ($sd !== null) {
                $sum += $sd;
                $evalDays++;
            }
            foreach ($this->ledgerLinesForDay($restaurantId, $userId, $ymd) as $line) {
                $details[] = $line;
            }
        }

        if ($evalDays === 0) {
            return [
                'titre' => $label,
                'jour' => null,
                'score' => null,
                'zone' => 'non_evalue',
                'points_detail' => array_slice($details, -40),
                'jours_moyennes' => $calendarDays,
                'note' => 'Aucun jour avec points sur ce mois : non évalué.',
            ];
        }

        $avg = round($sum / $evalDays, 1);

        return [
            'titre' => $label,
            'jour' => null,
            'score' => $avg,
            'zone' => $this->zoneFromScore((float) $avg),
            'points_detail' => array_slice($details, -40),
            'jours_moyennes' => $evalDays,
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

    /** Vert / Jaune / Orange / Rouge */
    private function zoneFromScore(float $s): string
    {
        if ($s >= 90) {
            return 'vert';
        }
        if ($s >= 70) {
            return 'jaune';
        }
        if ($s >= 50) {
            return 'orange';
        }

        return 'rouge';
    }

    private function zoneFromScoreNullable(?float $s): string
    {
        if ($s === null) {
            return 'non_evalue';
        }

        return $this->zoneFromScore($s);
    }
}

