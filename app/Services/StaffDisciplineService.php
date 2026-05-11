<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
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
     * @return array{daily:int, weekly_avg:float, monthly_avg:float, zone:string, ledger_preview: list<array<string,mixed>>}
     */
    public function gaugesForUser(int $restaurantId, int $userId, string $todayYmd): array
    {
        $this->ensureSchema();
        $daily = $this->scoreForDay($restaurantId, $userId, $todayYmd);
        $weekly = $this->averageLastDays($restaurantId, $userId, $todayYmd, 7);
        $monthly = $this->averageMonthToDate($restaurantId, $userId, $todayYmd);

        return [
            'daily' => $daily,
            'weekly_avg' => $weekly,
            'monthly_avg' => $monthly,
            'zone' => $this->zoneFromScore($monthly),
            'ledger_preview' => array_slice($this->listLedgerForUserMonth($restaurantId, $userId, $todayYmd), -12),
        ];
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

    public function proposedSalaryRetentionPercent(float $monthlyScoreAvg): float
    {
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

    private function scoreForDay(int $restaurantId, int $userId, string $dayYmd): int
    {
        $st = $this->database->pdo()->prepare(
            'SELECT COALESCE(SUM(delta_points), 0) FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd]);
        $delta = (int) $st->fetchColumn();

        return max(0, min(100, 100 + $delta));
    }

    private function averageLastDays(int $restaurantId, int $userId, string $todayYmd, int $days): float
    {
        $tz = new \DateTimeImmutable($todayYmd);
        $sum = 0.0;
        $n = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $tz->modify('-' . $i . ' days')->format('Y-m-d');
            $sum += $this->scoreForDay($restaurantId, $userId, $d);
            $n++;
        }

        return $n > 0 ? round($sum / $n, 1) : 100.0;
    }

    private function averageMonthToDate(int $restaurantId, int $userId, string $todayYmd): float
    {
        try {
            $start = new \DateTimeImmutable(substr($todayYmd, 0, 7) . '-01');
            $end = new \DateTimeImmutable($todayYmd);
        } catch (\Throwable) {
            return 100.0;
        }
        $sum = 0.0;
        $n = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $sum += $this->scoreForDay($restaurantId, $userId, $d->format('Y-m-d'));
            $n++;
        }

        return $n > 0 ? round($sum / $n, 1) : 100.0;
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
}
