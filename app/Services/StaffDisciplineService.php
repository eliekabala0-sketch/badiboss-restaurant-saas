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

    /** @var array<string, true> */
    private array $absenceEscalationSynced = [];

    /** @var array<string, string> */
    private array $engagementStartYmdCache = [];

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
        $this->ensureStaffPayrollProfileExtras();
    }

    /**
     * Colonnes optionnelles (ALTER idempotent) — pas de suppression de données.
     */
    private function ensureStaffPayrollProfileExtras(): void
    {
        $pdo = $this->database->pdo();
        foreach ([
            'ALTER TABLE staff_payroll_profiles ADD COLUMN service_start_ymd DATE NULL',
            'ALTER TABLE staff_payroll_profiles ADD COLUMN bonus_monthly DECIMAL(12,2) NOT NULL DEFAULT 0',
            'ALTER TABLE staff_payroll_profiles ADD COLUMN profile_note VARCHAR(500) NULL',
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
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
     * Premier jour où la discipline s’applique : max(début restaurant / post-reset,
     * date sur le profil paie, sinon date de création du compte — fuseau rapports).
     */
    public function effectiveAgentEngagementStartYmd(int $restaurantId, int $userId, DateTimeZone $tz): string
    {
        $this->ensureSchema();
        $cacheKey = $restaurantId . ':' . $userId . ':' . $tz->getName();
        if (array_key_exists($cacheKey, $this->engagementStartYmdCache)) {
            return $this->engagementStartYmdCache[$cacheKey];
        }
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $st = $this->database->pdo()->prepare(
            'SELECT u.created_at, p.service_start_ymd
             FROM users u
             LEFT JOIN staff_payroll_profiles p ON p.restaurant_id = u.restaurant_id AND p.user_id = u.id
             WHERE u.id = :uid AND u.restaurant_id = :rid
             LIMIT 1'
        );
        $st->execute(['uid' => $userId, 'rid' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row === null) {
            $this->engagementStartYmdCache[$cacheKey] = $glob;

            return $glob;
        }
        $createdYmd = $glob;
        $createdAt = trim((string) ($row['created_at'] ?? ''));
        if ($createdAt !== '') {
            try {
                $createdYmd = (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }
        if (str_starts_with($createdYmd, '0000')) {
            $createdYmd = $glob;
        }
        $manual = trim((string) ($row['service_start_ymd'] ?? ''));
        if ($manual !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $manual)) {
            $base = $manual;
        } else {
            $base = $createdYmd;
        }
        $out = max($glob, $base);
        $this->engagementStartYmdCache[$cacheKey] = $out;

        return $out;
    }

    /** @return array{status: string, created_at: string}|null */
    private function userTenantRowForDiscipline(int $restaurantId, int $userId): ?array
    {
        $st = $this->database->pdo()->prepare(
            'SELECT status, created_at FROM users WHERE id = :uid AND restaurant_id = :rid LIMIT 1'
        );
        $st->execute(['uid' => $userId, 'rid' => $restaurantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function normalizeAttendanceStatusPublic(string $raw): string
    {
        return $this->normalizeAttendanceStatus($raw);
    }

    public function upsertStaffAttendanceDay(
        int $restaurantId,
        int $targetUserId,
        string $dayYmd,
        string $plannedStatusRaw,
        ?string $managerNote,
        array $actor,
    ): void {
        $this->ensureSchema();
        if ($targetUserId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd)) {
            throw new \RuntimeException('Données présence invalides.');
        }
        $rawU = strtoupper(str_replace([' ', '-'], '_', trim($plannedStatusRaw)));
        if (in_array($rawU, ['AUTO', 'CLEAR', 'RETOUR_ACTIVITE', 'ACTIVITE_AUTO'], true)) {
            $del = $this->database->pdo()->prepare(
                'DELETE FROM staff_attendance_day WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d'
            );
            $del->execute(['rid' => $restaurantId, 'uid' => $targetUserId, 'd' => $dayYmd]);
            foreach (array_keys($this->engagementStartYmdCache) as $ck) {
                if (str_starts_with($ck, $restaurantId . ':' . $targetUserId . ':')) {
                    unset($this->engagementStartYmdCache[$ck]);
                }
            }

            return;
        }
        $status = $this->normalizeAttendanceStatus($plannedStatusRaw);
        $note = $managerNote !== null ? trim($managerNote) : '';
        $note = $note === '' ? null : $note;
        $actorId = (int) ($actor['id'] ?? 0);
        $ins = $this->database->pdo()->prepare(
            'INSERT INTO staff_attendance_day
            (restaurant_id, user_id, day_ymd, planned_status, manager_note, updated_by)
             VALUES (:rid, :uid, :d, :st, :note, :by)
             ON DUPLICATE KEY UPDATE planned_status = VALUES(planned_status), manager_note = VALUES(manager_note), updated_by = VALUES(updated_by)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $targetUserId,
            'd' => $dayYmd,
            'st' => $status,
            'note' => $note,
            'by' => $actorId > 0 ? $actorId : null,
        ]);
    }

    public function upsertStaffPayrollProfile(
        int $restaurantId,
        int $targetUserId,
        array $payload,
        array $actor,
    ): void {
        $this->ensureSchema();
        $base = (float) ($payload['base_salary_monthly'] ?? 0);
        if ($base < 0 || !is_finite($base)) {
            throw new \RuntimeException('Salaire de base invalide.');
        }
        $bonus = (float) ($payload['bonus_monthly'] ?? 0);
        if ($bonus < 0 || !is_finite($bonus)) {
            throw new \RuntimeException('Prime invalide.');
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        if ($currency === '' || strlen($currency) > 8) {
            throw new \RuntimeException('Devise invalide.');
        }
        $svcRaw = trim((string) ($payload['service_start_ymd'] ?? ''));
        $serviceStart = null;
        if ($svcRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $svcRaw)) {
            $serviceStart = $svcRaw;
        }
        $profileNote = trim((string) ($payload['profile_note'] ?? ''));
        $profileNote = $profileNote === '' ? null : $profileNote;
        $actorId = (int) ($actor['id'] ?? 0);
        $sql = 'INSERT INTO staff_payroll_profiles
            (restaurant_id, user_id, base_salary_monthly, bonus_monthly, currency, service_start_ymd, profile_note, updated_by)
            VALUES (:rid, :uid, :base, :bonus, :cur, :svc, :pn, :by)
            ON DUPLICATE KEY UPDATE base_salary_monthly = VALUES(base_salary_monthly), bonus_monthly = VALUES(bonus_monthly), currency = VALUES(currency), service_start_ymd = VALUES(service_start_ymd), profile_note = VALUES(profile_note), updated_by = VALUES(updated_by)';
        $this->database->pdo()->prepare($sql)->execute([
            'rid' => $restaurantId,
            'uid' => $targetUserId,
            'base' => round($base, 2),
            'bonus' => round($bonus, 2),
            'cur' => $currency,
            'svc' => $serviceStart,
            'pn' => $profileNote,
            'by' => $actorId > 0 ? $actorId : null,
        ]);
        foreach (array_keys($this->engagementStartYmdCache) as $ck) {
            if (str_starts_with($ck, $restaurantId . ':' . $targetUserId . ':')) {
                unset($this->engagementStartYmdCache[$ck]);
            }
        }
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
            'server_shortfall_today' => ['delta' => -32, 'label' => 'Manquant caisse (ventes du jour non couvertes) — gravité élevée.'],
            'server_shortfall_legacy' => ['delta' => -32, 'label' => 'Arriéré de remise caisse (jours précédents) — gravité élevée.'],
            'server_remittance_rejected' => ['delta' => -22, 'label' => 'Remise caisse rejetée : montant toujours à charge jusqu’à nouvelle remise valide.'],
            'server_stale_requests' => ['delta' => -12, 'label' => 'Commandes service non clôturées depuis la veille.'],
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

    /**
     * Trace visible en jauge : régularisation sans effacer les pénalités déjà inscrites.
     *
     * @param array<string, mixed> $operationSnapshot
     */
    public function recordManagerRegularizationPreservesPenalty(
        int $restaurantId,
        int $userId,
        array $operationSnapshot,
        string $decisionCode,
    ): void {
        if ($userId <= 0) {
            return;
        }
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $dayYmd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $ins = $this->database->pdo()->prepare(
            'INSERT INTO staff_score_ledger
            (restaurant_id, user_id, day_ymd, reason_code, delta_points, label, ref_json)
             VALUES (:rid, :uid, :d, :code, 0, :label, :ref)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'd' => $dayYmd,
            'code' => 'manager_regularized_kept_penalty',
            'label' => 'Régularisé par responsable — pénalité conservée',
            'ref' => json_encode(['decision' => $decisionCode, 'operation' => $operationSnapshot], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Clémence : compensation positive traçable (audit via journal score + module audit appelant).
     *
     * @param array<string, mixed> $ref
     */
    public function grantDisciplinaryClemency(
        int $restaurantId,
        int $userId,
        string $reason,
        array $actor,
        array $ref,
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Motif de clémence obligatoire.');
        }
        if ($userId <= 0) {
            throw new \RuntimeException('Utilisateur cible invalide pour clémence.');
        }
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $dayYmd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $ins = $this->database->pdo()->prepare(
            'INSERT INTO staff_score_ledger
            (restaurant_id, user_id, day_ymd, reason_code, delta_points, label, ref_json)
             VALUES (:rid, :uid, :d, "discipline_clemency_manager", :delta, :label, :ref)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'd' => $dayYmd,
            'delta' => 20,
            'label' => 'Indulgence responsable (voir motif en référence) — propriétaire informé via audit.',
            'ref' => json_encode(array_merge($ref, ['clemency_reason' => $reason, 'granted_by' => $actor['id'] ?? null]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? null,
            'actor_role_code' => $actor['role_code'] ?? null,
            'module_name' => 'staff_discipline',
            'action_name' => 'discipline_clemency_granted',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => ['reason' => $reason, 'ref' => $ref],
            'justification' => 'Clémence discipline avec motif obligatoire (visibilité propriétaire)',
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
        if ($userId > 0 && !$this->isOwnerDisciplineRole($this->resolveRoleCodeForUser($restaurantId, $userId))) {
            $this->syncAbsenceEscalationsForUser($restaurantId, $userId);
        }
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
            $monthSnap = $this->snapshotCalendarMonthGauge($restaurantId, $userId, $anchorYmd, true, $tz, $todayY);
            $monthly = $monthSnap['score'] ?? null;
        } else {
            $monthEnd = min($refY, $todayY);
            $monthSnap = $this->snapshotCalendarMonthGauge($restaurantId, $userId, $monthEnd, false, $tz, $todayY);
            $monthly = $monthSnap['score'] ?? null;
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        try {
            $start = max($fromYmd, $glob, $engagement);
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

        if (!$this->isOwnerDisciplineRole($this->resolveRoleCodeForUser($restaurantId, $userId))) {
            $this->syncAbsenceEscalationsForUser($restaurantId, $userId);
        }

        if ($this->isOwnerDisciplineRole($this->resolveRoleCodeForUser($restaurantId, $userId))) {
            return [
                'daily' => null,
                'weekly_avg' => null,
                'monthly_avg' => null,
                'zone' => 'non_evalue',
                'ledger_preview' => [],
                'dash_preset' => $preset,
                'dash_anchor_ymd' => $anchor,
                'active_period' => [
                    'titre' => 'Propriétaire',
                    'jour' => null,
                    'score' => null,
                    'zone' => 'non_evalue',
                    'points_detail' => [],
                    'note' => 'Compte propriétaire : pas de jauge discipline (suivi des équipiers uniquement).',
                ],
                'row_metrics' => [],
            ];
        }

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
        $manual = $this->restaurantDisciplineManualStartYmd($restaurantId);
        if ($manual !== null) {
            return $manual;
        }
        $firstOp = $this->firstRestaurantCommercialActivityYmd($restaurantId);
        if ($firstOp !== null && $firstOp !== '' && !str_starts_with($firstOp, '0000')) {
            return $firstOp;
        }
        $r = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $created = (string) ($r['created_at'] ?? '');
        if ($created !== '') {
            return substr($created, 0, 10);
        }

        return Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
    }

    private function restaurantDisciplineManualStartYmd(int $restaurantId): ?string
    {
        $settings = Container::getInstance()->get('restaurantAdmin')->listSettings($restaurantId);
        foreach (['discipline.restaurant_start_ymd', 'discipline.start_ymd'] as $key) {
            $raw = trim((string) ($settings[$key] ?? ''));
            if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && !str_starts_with($raw, '0000')) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * Premier jour où une activité commerciale / opérationnelle réelle apparaît (lecture seule).
     * Évite de prendre le tout premier audit (onboarding, tests) comme « début discipline ».
     */
    private function firstRestaurantCommercialActivityYmd(int $restaurantId): ?string
    {
        $pdo = $this->database->pdo();
        $sql = 'SELECT MIN(d) AS mind FROM (
            SELECT MIN(DATE(' . sql_sale_activity_datetime_expr('s', 'sr') . ')) AS d
            FROM sales s
            ' . sql_sale_activity_left_join_server_request('s', 'sr') . '
            WHERE s.restaurant_id = :rid1 AND (' . sql_sale_activity_datetime_expr('s', 'sr') . ') IS NOT NULL
            UNION ALL
            SELECT MIN(DATE(sr.created_at)) AS d
            FROM server_requests sr
            WHERE sr.restaurant_id = :rid2 AND sr.status NOT IN ("ANNULE","REFUSE_CUISINE")
            UNION ALL
            SELECT MIN(DATE(sri.prepared_at)) AS d
            FROM server_request_items sri
            INNER JOIN server_requests sr3 ON sr3.id = sri.request_id
            WHERE sr3.restaurant_id = :rid3 AND sri.prepared_at IS NOT NULL
            UNION ALL
            SELECT MIN(DATE(sm.created_at)) AS d
            FROM stock_movements sm
            WHERE sm.restaurant_id = :rid4 AND sm.status = "VALIDE"
            UNION ALL
            SELECT MIN(DATE(kp.created_at)) AS d
            FROM kitchen_production kp
            WHERE kp.restaurant_id = :rid5
        ) t WHERE d IS NOT NULL AND d > "1970-01-02"';
        try {
            $st = $pdo->prepare($sql);
            $st->execute([
                'rid1' => $restaurantId,
                'rid2' => $restaurantId,
                'rid3' => $restaurantId,
                'rid4' => $restaurantId,
                'rid5' => $restaurantId,
            ]);
            $d = $st->fetchColumn();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($d) || $d === '' || str_starts_with($d, '0000')) {
            return null;
        }

        return $d;
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
            if ($uid <= 0 || $this->isOwnerDisciplineRole((string) ($u['role_code'] ?? ''))) {
                continue;
            }
            if (($u['status'] ?? '') !== 'active') {
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
            if ($uid <= 0 || $this->isOwnerDisciplineRole((string) ($u['role_code'] ?? ''))) {
                continue;
            }
            if (($u['status'] ?? '') !== 'active') {
                continue;
            }
            try {
                $gauges = $this->gaugesForUserOperationalPanel($restaurantId, $uid, $preset, $anchorYmd);
            } catch (\Throwable) {
                $gauges = [
                    'daily' => null,
                    'weekly_avg' => null,
                    'monthly_avg' => null,
                    'zone' => 'non_evalue',
                    'ledger_preview' => [],
                    'dash_preset' => $preset,
                    'dash_anchor_ymd' => $anchorYmd,
                    'active_period' => [
                        'titre' => 'Lecture a verifier',
                        'jour' => $anchorYmd,
                        'score' => null,
                        'zone' => 'non_evalue',
                        'points_detail' => [],
                        'note' => 'Une anomalie de calcul a ete isolee pour cet agent. Les autres jauges restent chargees.',
                    ],
                    'row_metrics' => [],
                ];
            }
            $out[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $u['role_code'] ?? null,
                'gauges' => $gauges,
            ];
        }

        $this->sortOperationalGaugeSnapshotRows($out);

        return $out;
    }

    /**
     * Lecture detaillee discipline du jour, allegee pour les ecrans owner / gerant.
     *
     * @return list<array{user_id:int, full_name:string, role_code:?string, gauges: array<string,mixed>}>
     */
    public function gaugesSnapshotRestaurantDailyLight(int $restaurantId, string $todayYmd): array
    {
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $users = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
        $out = [];

        foreach ($users as $u) {
            $uid = (int) ($u['id'] ?? 0);
            $roleCode = (string) ($u['role_code'] ?? '');
            if ($uid <= 0 || $this->isOwnerDisciplineRole($roleCode)) {
                continue;
            }
            if (($u['status'] ?? '') !== 'active') {
                continue;
            }

            $gauges = [
                'daily' => null,
                'weekly_avg' => null,
                'monthly_avg' => null,
                'zone' => 'non_evalue',
                'ledger_preview' => [],
                'dash_preset' => 'today',
                'dash_anchor_ymd' => $todayYmd,
                'active_period' => [
                    'titre' => 'Aujourd hui',
                    'jour' => $todayYmd,
                    'score' => null,
                    'zone' => 'non_evalue',
                    'points_detail' => [],
                    'score_breakdown' => ['evaluated' => false, 'action_count' => 0],
                    'note' => 'Lecture discipline indisponible pour cet agent.',
                ],
                'row_metrics' => [],
            ];

            try {
                $active = $this->snapshotDayGauge($restaurantId, $uid, $todayYmd, 'Aujourd hui', $tz);
                $weekly = $this->averageLastDaysNullable($restaurantId, $uid, $todayYmd, 7, $tz);
                $globalStart = $this->effectiveGlobalStartYmd($restaurantId);
                $engagementStart = $this->effectiveAgentEngagementStartYmd($restaurantId, $uid, $tz);
                $monthStart = substr($todayYmd, 0, 7) . '-01';
                $periodStart = max($monthStart, $globalStart, $engagementStart);
                $dayBreakdown = is_array($active['score_breakdown'] ?? null) ? $active['score_breakdown'] : [];
                $dayKind = (string) ($dayBreakdown['evaluation_kind'] ?? '');
                $dayActions = (int) ($dayBreakdown['action_count'] ?? 0);
                $monthScore = null;
                $monthZone = (string) ($active['zone'] ?? 'non_evalue');
                $monthEvalDays = 0;
                $monthActions = $dayActions;
                $unjustified = $dayKind === 'absence_unjustified' ? 1 : 0;
                $softAbsence = in_array($dayKind, ['absence_authorized', 'absence_illness', 'late_justified'], true) ? 1 : 0;
                $zeroActivity = ($dayActions === 0 && !in_array($dayKind, ['neutral_rest', 'neutral_exempt', 'manager_present_confirm'], true)) ? 1 : 0;

                $shortfallHits = $roleCode === 'cashier_server'
                    ? $this->ledgerReasonCountForUserDayRange(
                        $restaurantId,
                        $uid,
                        $periodStart,
                        $todayYmd,
                        ['server_shortfall_today', 'server_shortfall_legacy']
                    )
                    : 0;
                $lateRemittance = $roleCode === 'cashier_server'
                    ? $this->serverLateRemittanceMetricsForRange($restaurantId, $uid, $periodStart, $todayYmd)
                    : ['late_count' => 0, 'max_delay_days' => 0];

                $gauges = [
                    'daily' => ($active['score'] ?? null),
                    'weekly_avg' => $weekly,
                    'monthly_avg' => $monthScore,
                    'zone' => $monthZone,
                    'ledger_preview' => array_slice((array) ($active['points_detail'] ?? []), -12),
                    'dash_preset' => 'today',
                    'dash_anchor_ymd' => $todayYmd,
                    'active_period' => $active,
                    'row_metrics' => [
                        'activite_actions' => (int) (($active['score_breakdown']['action_count'] ?? 0)),
                        'activite_pct_moyenne_periode' => null,
                        'jours_evalues_periode' => $monthEvalDays,
                        'absences_injustifiees' => $unjustified,
                        'absences_justifiees_maladie' => $softAbsence,
                        'jours_sans_activite_mesuree' => $zeroActivity,
                        'manquants_caisse_hits' => $shortfallHits,
                        'late_remittance_hits' => (int) ($lateRemittance['late_count'] ?? 0),
                        'late_remittance_max_delay_days' => (int) ($lateRemittance['max_delay_days'] ?? 0),
                        'actions_mois' => $monthActions,
                    ],
                ];
            } catch (\Throwable) {
            }

            $out[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $u['role_code'] ?? null,
                'gauges' => $gauges,
            ];
        }

        $this->sortOperationalGaugeSnapshotRows($out);

        return $out;
    }

    /**
     * Règles horaires pour retard / remise caisse (paramètres restaurant, défauts raisonnables).
     *
     * @return array{work_start:string, arrival_grace_minutes:int, cash_deadline:string, notice_unset:bool}
     */
    public function disciplineWorkScheduleForRestaurant(int $restaurantId): array
    {
        $defaults = [
            'work_start' => '08:00',
            'arrival_grace_minutes' => 15,
            'cash_deadline' => '22:00',
            'notice_unset' => false,
        ];
        $map = Container::getInstance()->get('restaurantAdmin')->listSettings($restaurantId);
        $wk = trim((string) ($map['discipline.work_start_time'] ?? ''));
        $gr = trim((string) ($map['discipline.arrival_grace_minutes'] ?? ''));
        $cd = trim((string) ($map['discipline.cash_remittance_deadline_time'] ?? ''));
        $unset = false;
        if ($wk === '' || !preg_match('/^\d{1,2}:\d{2}$/', $wk)) {
            $wk = $defaults['work_start'];
            $unset = true;
        }
        if ($gr === '' || !ctype_digit($gr)) {
            $gr = (string) $defaults['arrival_grace_minutes'];
            $unset = true;
        }
        if ($cd === '' || !preg_match('/^\d{1,2}:\d{2}$/', $cd)) {
            $cd = $defaults['cash_deadline'];
            $unset = true;
        }

        return [
            'work_start' => $wk,
            'arrival_grace_minutes' => max(0, min(120, (int) $gr)),
            'cash_deadline' => $cd,
            'notice_unset' => $unset,
        ];
    }

    /**
     * Alertes discipline immédiates (lecture synthétique + liens actions).
     *
     * @return list<array<string, mixed>>
     */
    public function listDisciplinaryAlerts(int $restaurantId): array
    {
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $alerts = [];
        $pdo = $this->database->pdo();

        foreach (Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId) as $u) {
            $uid = (int) ($u['id'] ?? 0);
            $role = (string) ($u['role_code'] ?? '');
            if ($uid <= 0 || $this->isOwnerDisciplineRole($role)) {
                continue;
            }
            if (($u['status'] ?? '') !== 'active') {
                continue;
            }
            $name = (string) ($u['full_name'] ?? '');
            $score = $this->gaugesForUser($restaurantId, $uid, $todayY);
            $monthly = $score['monthly_avg'];

            $unjWeek = 0;
            $unjDays = [];
            try {
                $mon = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('monday this week');
            } catch (\Throwable) {
                $mon = new DateTimeImmutable($todayY . ' 00:00:00', $tz);
            }
            for ($i = 0; $i < 7; $i++) {
                $d = $mon->modify('+' . $i . ' days')->format('Y-m-d');
                if ($d > $todayY) {
                    break;
                }
                $ev = $this->evaluateCalendarDay($restaurantId, $uid, $role, $d, $tz);
                if (($ev['evaluation_kind'] ?? '') === 'absence_unjustified' && !empty($ev['evaluated'])) {
                    $unjWeek++;
                    $unjDays[] = $d;
                }
            }
            if ($unjWeek >= 2) {
                $alerts[] = [
                    'kind' => 'absence_week',
                    'severity' => $unjWeek >= 3 ? 'critique' : 'elevee',
                    'user_id' => $uid,
                    'agent' => $name,
                    'role' => $role,
                    'message' => $name . ' : ' . $unjWeek . ' absence(s) / inactivité non justifiée(s) cette semaine',
                    'dates' => array_reverse($unjDays),
                    'score_hint' => $monthly,
                    'proposition' => $unjWeek >= 3
                        ? 'Sanction grave + entretien gérant obligatoire'
                        : 'Alerter et demander justification écrite',
                ];
            }

            $streak = $this->countTrailingUnjustifiedStreak($restaurantId, $uid, $role, $todayY, $tz, 10);
            if ($streak >= 2) {
                $alerts[] = [
                    'kind' => 'absence_streak',
                    'severity' => $streak >= 3 ? 'critique' : 'elevee',
                    'user_id' => $uid,
                    'agent' => $name,
                    'role' => $role,
                    'message' => $name . ' : ' . $streak . ' jour(s) consécutif(s) sans activité justifiée',
                    'dates' => [],
                    'score_hint' => $monthly,
                    'proposition' => $streak >= 3
                        ? 'Sanction lourde + validation gérant'
                        : 'Alerte disciplinaire immédiate',
                ];
            }

            if ($role === 'cashier_server') {
                $stSf = $pdo->prepare(
                    'SELECT COUNT(*) FROM staff_score_ledger
                     WHERE restaurant_id = :rid AND user_id = :uid
                       AND reason_code IN ("server_shortfall_today","server_shortfall_legacy")
                       AND day_ymd >= DATE_SUB(:today, INTERVAL 14 DAY)'
                );
                $stSf->execute(['rid' => $restaurantId, 'uid' => $uid, 'today' => $todayY]);
                $sf = (int) $stSf->fetchColumn();
                if ($sf > 0) {
                    $alerts[] = [
                        'kind' => 'cash_shortfall',
                        'severity' => 'elevee',
                        'user_id' => $uid,
                        'agent' => $name,
                        'role' => $role,
                        'message' => $name . ' : manquant caisse signalé (14 j.)',
                        'dates' => [],
                        'score_hint' => $monthly,
                        'proposition' => 'Régulariser remise ou décision gérant sur arriérés',
                    ];
                }
                $stLt = $pdo->prepare(
                    'SELECT COUNT(*) FROM cash_transfers
                     WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                       AND sale_day_ymd IS NOT NULL AND remittance_day_ymd IS NOT NULL
                       AND sale_day_ymd <> remittance_day_ymd
                       AND COALESCE(requested_at, created_at) >= DATE_SUB(:today, INTERVAL 30 DAY)'
                );
                $stLt->execute(['rid' => $restaurantId, 'uid' => $uid, 'today' => $todayY]);
                $lt = (int) $stLt->fetchColumn();
                if ($lt >= 3) {
                    $alerts[] = [
                        'kind' => 'late_remittance_pattern',
                        'severity' => 'elevee',
                        'user_id' => $uid,
                        'agent' => $name,
                        'role' => $role,
                        'message' => $name . ' : ' . $lt . ' remise(s) tardive(s) (30 j.)',
                        'dates' => [],
                        'score_hint' => $monthly,
                        'proposition' => 'Sanction discipline + suivi caisse quotidien',
                    ];
                }
            }

            if ($role === 'kitchen') {
                $stA = $pdo->prepare(
                    'SELECT MAX(created_at) FROM audit_logs
                     WHERE restaurant_id = :rid AND user_id = :uid
                       AND module_name IN ("kitchen","sales","stock")'
                );
                $stA->execute(['rid' => $restaurantId, 'uid' => $uid]);
                $last = $stA->fetchColumn();
                if (is_string($last) && $last !== '') {
                    try {
                        $lastDt = new DateTimeImmutable($last, new DateTimeZone('UTC'));
                        $daysSince = $lastDt->diff(new DateTimeImmutable('now', new DateTimeZone('UTC')))->days;
                        if ($daysSince >= 2) {
                            $alerts[] = [
                                'kind' => 'kitchen_inactive',
                                'severity' => 'moyenne',
                                'user_id' => $uid,
                                'agent' => $name,
                                'role' => $role,
                                'message' => $name . ' : aucune activité cuisine enregistrée depuis ' . $daysSince . ' jour(s)',
                                'dates' => [],
                                'score_hint' => $monthly,
                                'proposition' => 'Vérifier planning ou absence non déclarée',
                            ];
                        }
                    } catch (\Throwable) {
                    }
                }
            }
        }

        usort($alerts, static function (array $a, array $b): int {
            $order = ['critique' => 0, 'elevee' => 1, 'moyenne' => 2];
            $sa = $order[(string) ($a['severity'] ?? '')] ?? 9;
            $sb = $order[(string) ($b['severity'] ?? '')] ?? 9;

            return $sa <=> $sb;
        });

        return array_slice($alerts, 0, 40);
    }

    public function recordDisciplinaryAlertFollowUp(
        int $restaurantId,
        int $targetUserId,
        string $actionCode,
        string $note,
        array $actor,
    ): void {
        $note = trim($note);
        $allowed = ['warn', 'justify', 'sanction', 'clemency', 'watch'];
        if (!in_array($actionCode, $allowed, true)) {
            throw new \RuntimeException('Action alerte invalide.');
        }
        if ($note === '' && $actionCode !== 'watch') {
            throw new \RuntimeException('Note obligatoire pour cette action.');
        }
        $this->ensureSchema();
        $tz = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
        $dayYmd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $label = match ($actionCode) {
            'warn' => 'Suite alerte discipline : avertissement enregistré',
            'justify' => 'Suite alerte discipline : justification demandée',
            'sanction' => 'Suite alerte discipline : sanction appliquée (voir note)',
            'clemency' => 'Suite alerte discipline : clémence / mesure d’apaisement',
            default => 'Suite alerte discipline : agent placé sous surveillance renforcée',
        };
        $delta = match ($actionCode) {
            'sanction' => -12,
            'clemency' => 8,
            default => 0,
        };
        $ins = $this->database->pdo()->prepare(
            'INSERT INTO staff_score_ledger
            (restaurant_id, user_id, day_ymd, reason_code, delta_points, label, ref_json)
             VALUES (:rid, :uid, :d, :code, :delta, :label, :ref)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $targetUserId,
            'd' => $dayYmd,
            'code' => 'disc_alert_followup_' . $actionCode,
            'delta' => $delta,
            'label' => $label,
            'ref' => json_encode([
                'by' => $actor['id'] ?? null,
                'note' => $note,
                'action' => $actionCode,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? '',
            'actor_role_code' => $actor['role_code'] ?? '',
            'module_name' => 'staff_discipline',
            'action_name' => 'disciplinary_alert_followup',
            'entity_type' => 'users',
            'entity_id' => (string) $targetUserId,
            'new_values' => ['action' => $actionCode, 'note' => $note],
            'justification' => 'Suivi alerte discipline',
        ]);
    }

    private function countTrailingUnjustifiedStreak(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $todayY,
        DateTimeZone $tz,
        int $maxDays,
    ): int {
        $streak = 0;
        for ($i = 0; $i < $maxDays; $i++) {
            $d = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $roleCode, $d, $tz);
            if (($ev['evaluation_kind'] ?? '') === 'absence_unjustified' && !empty($ev['evaluated'])) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    private function syncAbsenceEscalationsForUser(int $restaurantId, int $userId): void
    {
        $role = $this->resolveRoleCodeForUser($restaurantId, $userId);
        if ($this->isOwnerDisciplineRole($role)) {
            return;
        }
        $rs = Container::getInstance()->get('reportService');
        $tz = $rs->timezoneForRestaurantReports($restaurantId);
        $todayY = $rs->todayForRestaurant($restaurantId);
        $k = $restaurantId . ':' . $userId . ':' . $todayY;
        if (isset($this->absenceEscalationSynced[$k])) {
            return;
        }
        $this->absenceEscalationSynced[$k] = true;

        $pdo = $this->database->pdo();
        $weekBuckets = [];
        $daysFlags = [];
        for ($i = 0; $i < 21; $i++) {
            $d = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $role, $d, $tz);
            $isUnj = ($ev['evaluation_kind'] ?? '') === 'absence_unjustified' && !empty($ev['evaluated']);
            $daysFlags[$d] = $isUnj;
            if ($isUnj) {
                try {
                    $mon = (new DateTimeImmutable($d . ' 00:00:00', $tz))->modify('monday this week')->format('Y-m-d');
                } catch (\Throwable) {
                    $mon = substr($d, 0, 7) . '-01';
                }
                $weekBuckets[$mon] = ($weekBuckets[$mon] ?? 0) + 1;
            }
        }

        $streak = 0;
        for ($i = 0; $i < 21; $i++) {
            $d = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            if (!empty($daysFlags[$d])) {
                $streak++;
            } else {
                break;
            }
        }

        if ($streak >= 3) {
            $anchor = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->format('Y-m-d');
            $this->insertEscalationLedgerOnce(
                $pdo,
                $restaurantId,
                $userId,
                $anchor,
                'disc_abs_streak_3plus',
                -55,
                'Absences successives (3 j. ou plus) — sanction lourde (automatique).',
                ['streak' => $streak, 'anchor' => $anchor],
            );
        } elseif ($streak === 2) {
            $anchor = (new DateTimeImmutable($todayY . ' 00:00:00', $tz))->format('Y-m-d');
            $this->insertEscalationLedgerOnce(
                $pdo,
                $restaurantId,
                $userId,
                $anchor,
                'disc_abs_streak_2',
                -28,
                'Absences successives (2 j.) — alerte disciplinaire renforcée.',
                ['streak' => 2, 'anchor' => $anchor],
            );
        }

        foreach ($weekBuckets as $weekMon => $cnt) {
            if ($cnt >= 3) {
                $this->insertEscalationLedgerOnce(
                    $pdo,
                    $restaurantId,
                    $userId,
                    $weekMon,
                    'disc_abs_week_3_' . $weekMon,
                    -40,
                    $cnt . ' absences / inactivité non justifiée(s) sur la même semaine — sanction grave.',
                    ['week_start' => $weekMon, 'count' => $cnt],
                );
            } elseif ($cnt >= 2) {
                $this->insertEscalationLedgerOnce(
                    $pdo,
                    $restaurantId,
                    $userId,
                    $weekMon,
                    'disc_abs_week_2_' . $weekMon,
                    -35,
                    $cnt . ' absences / inactivité non justifiée(s) sur la même semaine — alerte + pénalité.',
                    ['week_start' => $weekMon, 'count' => $cnt],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function insertEscalationLedgerOnce(
        PDO $pdo,
        int $restaurantId,
        int $userId,
        string $dayYmd,
        string $reasonCode,
        int $delta,
        string $label,
        array $ref,
    ): void {
        $st = $pdo->prepare(
            'SELECT id FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d AND reason_code = :code
             LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd, 'code' => $reasonCode]);
        if ($st->fetchColumn() !== false) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO staff_score_ledger
            (restaurant_id, user_id, day_ymd, reason_code, delta_points, label, ref_json)
             VALUES (:rid, :uid, :d, :code, :delta, :label, :ref)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'd' => $dayYmd,
            'code' => $reasonCode,
            'delta' => $delta,
            'label' => $label,
            'ref' => json_encode($ref, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function firstAuditInWindow(int $restaurantId, int $userId, string $s, string $e, PDO $pdo): ?DateTimeImmutable
    {
        $st = $pdo->prepare(
            'SELECT MIN(created_at) AS t FROM audit_logs
             WHERE restaurant_id = :rid AND user_id = :uid
               AND created_at >= :s AND created_at < :e'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
        $t = $st->fetchColumn();
        if (!is_string($t) || $t === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($t, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
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

    private function countUnjustifiedAbsenceDaysInRange(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $fromYmd,
        string $toYmd,
        DateTimeZone $tz,
    ): int {
        $eng = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $start = max($fromYmd, $eng);
        if ($start > $toYmd) {
            return 0;
        }
        try {
            $cur = new DateTimeImmutable($start . ' 00:00:00', $tz);
            $endD = new DateTimeImmutable($toYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return 0;
        }
        $n = 0;
        for ($d = $cur; $d <= $endD; $d = $d->modify('+1 day')) {
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $roleCode, $d->format('Y-m-d'), $tz);
            if (!empty($ev['evaluated']) && ($ev['evaluation_kind'] ?? '') === 'absence_unjustified') {
                $n++;
            }
        }

        return $n;
    }

    private function countReposAttendanceDaysInMonth(int $restaurantId, int $userId, string $monthKey): int
    {
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM staff_attendance_day
             WHERE restaurant_id = :rid AND user_id = :uid AND DATE_FORMAT(day_ymd, "%Y-%m") = :m
               AND planned_status = "REPOS"'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'm' => $monthKey]);

        return (int) $st->fetchColumn();
    }

    private function maxUnjustifiedAbsenceStreakInRange(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $fromYmd,
        string $toYmd,
        DateTimeZone $tz,
    ): int {
        $eng = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $start = max($fromYmd, $eng);
        if ($start > $toYmd) {
            return 0;
        }
        try {
            $cur = new DateTimeImmutable($start . ' 00:00:00', $tz);
            $endD = new DateTimeImmutable($toYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return 0;
        }
        $maxStreak = 0;
        $streak = 0;
        for ($d = $cur; $d <= $endD; $d = $d->modify('+1 day')) {
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $roleCode, $d->format('Y-m-d'), $tz);
            if (!empty($ev['evaluated']) && ($ev['evaluation_kind'] ?? '') === 'absence_unjustified') {
                $streak++;
                if ($streak > $maxStreak) {
                    $maxStreak = $streak;
                }
            } else {
                $streak = 0;
            }
        }

        return $maxStreak;
    }

    private function negativeLedgerPointsForUserDayRange(
        int $restaurantId,
        int $userId,
        string $fromYmd,
        string $toYmd,
    ): int {
        if ($userId <= 0 || $fromYmd > $toYmd) {
            return 0;
        }
        $st = $this->database->pdo()->prepare(
            'SELECT COALESCE(SUM(LEAST(0, delta_points)), 0) AS pts
             FROM staff_score_ledger
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd >= :from AND day_ymd <= :to'
        );
        $st->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'from' => $fromYmd,
            'to' => $toYmd,
        ]);

        return (int) $st->fetchColumn();
    }

    /**
     * @return array{late_count:int,max_delay_days:int}
     */
    private function serverLateRemittanceMetricsForRange(
        int $restaurantId,
        int $userId,
        string $fromYmd,
        string $toYmd,
    ): array {
        if ($userId <= 0 || $fromYmd > $toYmd) {
            return ['late_count' => 0, 'max_delay_days' => 0];
        }
        $st = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS late_count,
                    COALESCE(MAX(GREATEST(0, DATEDIFF(remittance_day_ymd, sale_day_ymd))), 0) AS max_delay_days
             FROM cash_transfers
             WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
               AND sale_day_ymd IS NOT NULL AND remittance_day_ymd IS NOT NULL
               AND sale_day_ymd >= :from AND sale_day_ymd <= :to
               AND remittance_day_ymd > sale_day_ymd'
        );
        $st->execute([
            'rid' => $restaurantId,
            'uid' => $userId,
            'from' => $fromYmd,
            'to' => $toYmd,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'late_count' => (int) ($row['late_count'] ?? 0),
            'max_delay_days' => (int) ($row['max_delay_days'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $monthStats
     *
     * @return array{
     *   score:?float,
     *   raw_score:?float,
     *   cap_score:?float,
     *   cap_reasons:list<string>,
     *   metrics:array<string,mixed>
     * }
     */
    private function monthlySeverityAdjustedScore(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $fromYmd,
        string $toYmd,
        DateTimeZone $tz,
        ?float $rawScore,
        array $monthStats,
    ): array {
        $ledgerPenaltyPts = $this->negativeLedgerPointsForUserDayRange($restaurantId, $userId, $fromYmd, $toYmd);
        $unjustifiedAbsenceDays = (int) ($monthStats['days_unjustified_absence'] ?? 0);
        $softAbsenceDays = (int) ($monthStats['days_soft_absence'] ?? 0);
        $measuredActivityDays = (int) ($monthStats['days_with_activity'] ?? 0);
        $zeroActivityDays = (int) ($monthStats['days_without_measured_activity'] ?? 0);
        $maxUnjustifiedStreak = $this->maxUnjustifiedAbsenceStreakInRange(
            $restaurantId,
            $userId,
            $roleCode,
            $fromYmd,
            $toYmd,
            $tz,
        );
        $shortfallHits = $roleCode === 'cashier_server'
            ? $this->ledgerReasonCountForUserDayRange(
                $restaurantId,
                $userId,
                $fromYmd,
                $toYmd,
                ['server_shortfall_today', 'server_shortfall_legacy'],
            )
            : 0;
        $remittanceRejectedHits = $roleCode === 'cashier_server'
            ? $this->ledgerReasonCountForUserDayRange(
                $restaurantId,
                $userId,
                $fromYmd,
                $toYmd,
                ['server_remittance_rejected'],
            )
            : 0;
        $lateRemittance = $roleCode === 'cashier_server'
            ? $this->serverLateRemittanceMetricsForRange($restaurantId, $userId, $fromYmd, $toYmd)
            : ['late_count' => 0, 'max_delay_days' => 0];
        $activityPctVsRole = $this->averageActivitePctVsRoleForWindow(
            $restaurantId,
            $userId,
            $roleCode,
            'month',
            $toYmd,
        );

        $capScore = 100.0;
        $capReasons = [];
        $capApplied = false;
        $applyCap = static function (float $current, float $next): float {
            return $next < $current ? $next : $current;
        };

        if ($ledgerPenaltyPts <= -55) {
            $newCap = $applyCap($capScore, 59.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'penalites du mois tres elevees';
                $capApplied = true;
            }
        } elseif ($ledgerPenaltyPts <= -35) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'penalites du mois elevees';
                $capApplied = true;
            }
        } elseif ($ledgerPenaltyPts <= -20) {
            $newCap = $applyCap($capScore, 89.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'penalites du mois visibles';
                $capApplied = true;
            }
        }

        if ($unjustifiedAbsenceDays >= 2) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'absences non justifiees repetees';
                $capApplied = true;
            }
        } elseif ($unjustifiedAbsenceDays >= 1) {
            $newCap = $applyCap($capScore, 89.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'absence non justifiee';
                $capApplied = true;
            }
        }

        if ($maxUnjustifiedStreak >= 3) {
            $newCap = $applyCap($capScore, 59.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'absences successives';
                $capApplied = true;
            }
        } elseif ($maxUnjustifiedStreak >= 2) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = '2 jours consecutifs sans activite justifiee';
                $capApplied = true;
            }
        }

        if ($softAbsenceDays >= 3) {
            $newCap = $applyCap($capScore, 88.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'absences justifiees ou retards justifies frequents';
                $capApplied = true;
            }
        }

        if ($shortfallHits >= 2) {
            $newCap = $applyCap($capScore, 59.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'manquants caisse repetes';
                $capApplied = true;
            }
        } elseif ($shortfallHits >= 1) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'manquant caisse';
                $capApplied = true;
            }
        }

        if ($remittanceRejectedHits >= 1) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'remise caisse rejetee';
                $capApplied = true;
            }
        }

        if ((int) ($lateRemittance['max_delay_days'] ?? 0) >= 2) {
            $newCap = $applyCap($capScore, 59.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'retard caisse grave';
                $capApplied = true;
            }
        } elseif ((int) ($lateRemittance['late_count'] ?? 0) >= 2) {
            $newCap = $applyCap($capScore, 74.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'retards caisse repetes';
                $capApplied = true;
            }
        } elseif ((int) ($lateRemittance['late_count'] ?? 0) >= 1) {
            $newCap = $applyCap($capScore, 89.0);
            if ($newCap < $capScore) {
                $capScore = $newCap;
                $capReasons[] = 'retard de versement caisse';
                $capApplied = true;
            }
        }

        if ($activityPctVsRole !== null && is_finite($activityPctVsRole)) {
            if ($activityPctVsRole < 50.0) {
                $newCap = $applyCap($capScore, 73.0);
                if ($newCap < $capScore) {
                    $capScore = $newCap;
                    $capReasons[] = 'faible activite vs collegues';
                    $capApplied = true;
                }
            } elseif ($activityPctVsRole < 80.0) {
                $newCap = $applyCap($capScore, 88.0);
                if ($newCap < $capScore) {
                    $capScore = $newCap;
                    $capReasons[] = 'activite en dessous de la moyenne du role';
                    $capApplied = true;
                }
            }
        }

        $finalScore = $rawScore;
        if ($finalScore !== null && is_finite($finalScore) && $capApplied) {
            $finalScore = round(min($finalScore, $capScore), 1);
        }

        return [
            'score' => ($finalScore !== null && is_finite($finalScore)) ? $finalScore : null,
            'raw_score' => ($rawScore !== null && is_finite($rawScore)) ? round($rawScore, 1) : null,
            'cap_score' => $capApplied ? round($capScore, 1) : null,
            'cap_reasons' => array_values(array_unique($capReasons)),
            'metrics' => [
                'unjustified_absence_days' => $unjustifiedAbsenceDays,
                'soft_absence_days' => $softAbsenceDays,
                'max_unjustified_streak' => $maxUnjustifiedStreak,
                'cash_shortfall_hits' => $shortfallHits,
                'late_remittance_hits' => (int) ($lateRemittance['late_count'] ?? 0),
                'late_remittance_max_delay_days' => (int) ($lateRemittance['max_delay_days'] ?? 0),
                'remittance_rejected_hits' => $remittanceRejectedHits,
                'ledger_penalty_points_month' => $ledgerPenaltyPts,
                'activity_pct_vs_role_avg' => ($activityPctVsRole !== null && is_finite($activityPctVsRole)) ? round($activityPctVsRole, 1) : null,
                'measured_activity_days' => $measuredActivityDays,
                'days_without_measured_activity' => $zeroActivityDays,
            ],
        ];
    }

    /**
     * Aperçu paie (lecture) : mois, agents, base, jauge, présences, retenue indicative, net proposé.
     *
     * @return array{month:string, period_label:string, period_start:string, period_end:string, rows: list<array<string,mixed>>}
     */
    public function payrollMonthPreview(int $restaurantId, string $monthInput, bool $includeHeavyScores = false): array
    {
        $this->ensureSchema();
        $rs = Container::getInstance()->get('reportService');
        $tz = $rs->timezoneForRestaurantReports($restaurantId);
        $todayY = $rs->todayForRestaurant($restaurantId);
        $restaurantStartYmd = $this->restaurantActivityStartYmd($restaurantId);
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
            'SELECT user_id, base_salary_monthly, bonus_monthly, currency, service_start_ymd, profile_note
             FROM staff_payroll_profiles WHERE restaurant_id = :rid'
        );
        $profSt->execute(['rid' => $restaurantId]);
        $profiles = [];
        foreach ($profSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $profiles[(int) ($row['user_id'] ?? 0)] = $row;
        }

        $rows = [];
        foreach (Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId) as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid <= 0 || $this->isOwnerDisciplineRole((string) ($u['role_code'] ?? ''))) {
                continue;
            }
            if (($u['status'] ?? '') !== 'active') {
                continue;
            }
            $roleCode = (string) ($u['role_code'] ?? '');
            $monthGauge = $this->snapshotCalendarMonthGauge($restaurantId, $uid, $end, false, $tz, $todayY);
            $dayGauge = $includeHeavyScores ? $this->snapshotDayGauge($restaurantId, $uid, $end, 'Jour', $tz) : [];
            $weekGauge = $includeHeavyScores ? $this->snapshotOperationalWeekGauge($restaurantId, $uid, $end, $tz) : [];
            $disciplineMonth = is_array($monthGauge['score_breakdown']['month_rules'] ?? null)
                ? $monthGauge['score_breakdown']['month_rules']
                : [];
            $monthlyScore = array_key_exists('score', $disciplineMonth)
                ? $disciplineMonth['score']
                : ($monthGauge['score'] ?? null);
            $rawMonthlyScore = array_key_exists('raw_score', $disciplineMonth)
                ? $disciplineMonth['raw_score']
                : $monthlyScore;
            $retentionPct = $this->proposedSalaryRetentionPercent(is_numeric($monthlyScore) ? (float) $monthlyScore : null);
            $base = (float) ($profiles[$uid]['base_salary_monthly'] ?? 0);
            $currency = (string) ($profiles[$uid]['currency'] ?? 'USD');
            $bonus = (float) ($profiles[$uid]['bonus_monthly'] ?? 0);
            if (!is_finite($bonus) || $bonus < 0) {
                $bonus = 0.0;
            }
            $monthMetrics = is_array($disciplineMonth['metrics'] ?? null) ? $disciplineMonth['metrics'] : [];
            $unjDays = array_key_exists('unjustified_absence_days', $monthMetrics)
                ? (int) ($monthMetrics['unjustified_absence_days'] ?? 0)
                : $this->countUnjustifiedAbsenceDaysInRange($restaurantId, $uid, $roleCode, $start, $end, $tz);
            $perDayAbs = $base > 0 ? min($base / 22, $base * 0.12) : 0.0;
            $absDeduction = round(min($base * 0.35, $unjDays * $perDayAbs), 2);
            $scoreRetentionAmt = round($base * ($retentionPct / 100), 2);
            $net = round(max(0, $base - $scoreRetentionAmt + $bonus - $absDeduction), 2);

            $attSt = $pdo->prepare(
                'SELECT COUNT(*) FROM staff_attendance_day
                 WHERE restaurant_id = :rid AND user_id = :uid AND DATE_FORMAT(day_ymd, "%Y-%m") = :m'
            );
            $attSt->execute(['rid' => $restaurantId, 'uid' => $uid, 'm' => $monthKey]);
            $attDays = (int) $attSt->fetchColumn();
            $restDays = $this->countReposAttendanceDaysInMonth($restaurantId, $uid, $monthKey);

            $ledgerPenaltyPts = array_key_exists('ledger_penalty_points_month', $monthMetrics)
                ? (int) ($monthMetrics['ledger_penalty_points_month'] ?? 0)
                : $this->negativeLedgerPointsForUserDayRange($restaurantId, $uid, $start, $end);
            $monthBreakdown = is_array($monthGauge['score_breakdown'] ?? null) ? $monthGauge['score_breakdown'] : [];
            $effectiveStart = (string) ($monthBreakdown['effective_period_start'] ?? max(
                $start,
                $restaurantStartYmd,
                $this->effectiveAgentEngagementStartYmd($restaurantId, $uid, $tz)
            ));

            $rows[] = [
                'user_id' => $uid,
                'full_name' => (string) ($u['full_name'] ?? ''),
                'role_code' => $roleCode,
                'base_salary_monthly' => $base,
                'currency' => $currency,
                'monthly_score_avg' => $monthlyScore,
                'monthly_score_raw_avg' => $rawMonthlyScore,
                'monthly_score_cap' => $disciplineMonth['cap_score'] ?? null,
                'monthly_score_zone' => $this->zoneFromScoreNullable($monthlyScore),
                'retention_proposed_pct' => $retentionPct,
                'retention_amount_est' => $scoreRetentionAmt,
                'bonus_monthly' => $bonus,
                'unjustified_absence_days' => $unjDays,
                'justified_absence_days' => (int) ($monthMetrics['soft_absence_days'] ?? 0),
                'rest_days_recorded' => $restDays,
                'deduction_absence_est' => $absDeduction,
                'service_start_ymd' => $profiles[$uid]['service_start_ymd'] ?? null,
                'profile_note' => $profiles[$uid]['profile_note'] ?? null,
                'net_pay_proposed' => $net,
                'attendance_days_recorded' => $attDays,
                'measured_activity_days' => (int) ($monthMetrics['measured_activity_days'] ?? 0),
                'ledger_penalty_points_month' => $ledgerPenaltyPts,
                'cash_shortfall_hits' => (int) ($monthMetrics['cash_shortfall_hits'] ?? 0),
                'late_remittance_hits' => (int) ($monthMetrics['late_remittance_hits'] ?? 0),
                'late_remittance_max_delay_days' => (int) ($monthMetrics['late_remittance_max_delay_days'] ?? 0),
                'activity_pct_vs_role_avg' => $monthMetrics['activity_pct_vs_role_avg'] ?? null,
                'discipline_cap_reasons' => $disciplineMonth['cap_reasons'] ?? [],
                'period_effective_start' => $effectiveStart,
                'restaurant_start_ymd' => $restaurantStartYmd,
                'day_score' => $dayGauge['score'] ?? null,
                'week_score' => $weekGauge['score'] ?? null,
                'month_score' => $monthGauge['score'] ?? null,
                'month_note' => (string) ($monthGauge['note'] ?? ''),
                'non_evaluated_reason' => $monthlyScore === null ? (string) ($monthGauge['note'] ?? 'Non évalué.') : '',
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

    /** Compte propriétaire : pas de cotations discipline (affichage équipe uniquement). */
    private function isOwnerDisciplineRole(string $roleCode): bool
    {
        return $roleCode === 'owner';
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
     * Moyenne du % d’activité vs moyenne du rôle sur la fenêtre opérationnelle
     * (jours « audit_activity » avec comparatif calculé seulement).
     */
    private function averageActivitePctVsRoleForWindow(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $preset,
        string $anchorYmd,
    ): ?float {
        $operational = ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'];
        if (!in_array($roleCode, $operational, true)) {
            return null;
        }
        $rs = Container::getInstance()->get('reportService');
        $tz = $rs->timezoneForRestaurantReports($restaurantId);
        $win = $rs->operationalPeriodWindow($restaurantId, $preset, $anchorYmd);
        $sum = 0.0;
        $n = 0;
        for ($d = $win['start']; $d < $win['end']; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $ev = $this->evaluateCalendarDay($restaurantId, $userId, $roleCode, $ymd, $tz);
            if (!($ev['evaluated'] ?? false)) {
                continue;
            }
            if (($ev['evaluation_kind'] ?? '') !== 'audit_activity') {
                continue;
            }
            $p = $ev['activite_pct_vs_role'] ?? null;
            if ($p !== null && is_numeric($p)) {
                $sum += (float) $p;
                $n++;
            }
        }

        return $n > 0 ? round($sum / $n, 1) : null;
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
            'activite_pct_moyenne_periode' => $this->averageActivitePctVsRoleForWindow(
                $restaurantId,
                $userId,
                $roleCode,
                $preset,
                $anchorYmd,
            ),
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
     * Comparatif stric même rôle : pénalités selon % de la moyenne d’activité de l’équipe sur la journée
     * (≥80 % : 0 · 50–79 % : -10 · 25–49 % : -20 · <25 % : -30). Un seul agent du rôle : aucun comparatif.
     *
     * @return array{
     *   lines: list<array{label:string,points:int}>,
     *   meta: array{ratio:?float, role_mean:?float, peers_n:int},
     *   peers: list<int>
     * }
     */
    private function computePeerRoleActivityPackage(
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
            return [
                'lines' => [],
                'meta' => ['ratio' => null, 'role_mean' => null, 'peers_n' => 0],
                'peers' => [],
            ];
        }
        $peers = $this->peerUserIdsSameRole($restaurantId, $roleCode);
        $nPeers = count($peers);
        if ($nPeers < 2) {
            return [
                'lines' => [],
                'meta' => ['ratio' => null, 'role_mean' => null, 'peers_n' => $nPeers],
                'peers' => $peers,
            ];
        }

        $counts = [];
        foreach ($peers as $pid) {
            $counts[$pid] = $this->measureActivityVolumeForDay($restaurantId, $pid, $roleCode, $dayYmd, $tz)['action_count'];
        }
        $roleAvg = array_sum($counts) / $nPeers;
        $maxC = max($counts);
        $mine = $counts[$userId] ?? $userActionCount;

        if ($maxC < 4) {
            return [
                'lines' => [],
                'meta' => ['ratio' => null, 'role_mean' => round($roleAvg, 2), 'peers_n' => $nPeers],
                'peers' => $peers,
            ];
        }

        $ratio = $roleAvg > 0 ? $mine / $roleAvg : 0.0;
        if (!is_finite($ratio)) {
            $ratio = 0.0;
        }

        $penalty = 0;
        if ($ratio >= 0.80) {
            $penalty = 0;
        } elseif ($ratio >= 0.50) {
            $penalty = -10;
        } elseif ($ratio >= 0.25) {
            $penalty = -20;
        } else {
            $penalty = -30;
        }

        $bonus = 0;
        if ($penalty === 0) {
            if ($ratio >= 1.35) {
                $bonus = 8;
            } elseif ($ratio >= 1.15) {
                $bonus = 5;
            }
        }

        $pts = $penalty + $bonus;
        $pts = max(-30, min(8, $pts));

        $lines = [];
        if ($pts !== 0) {
            $lines[] = [
                'label' => sprintf(
                    'Activité vs moyenne du rôle (jour) : vous %d actes · moy. équipe %.1f · %d %% de la moyenne',
                    $mine,
                    $roleAvg,
                    (int) max(0, min(500, (int) round($ratio * 100)))
                ),
                'points' => $pts,
            ];
        }

        return [
            'lines' => $lines,
            'meta' => [
                'ratio' => $ratio,
                'role_mean' => round($roleAvg, 2),
                'peers_n' => $nPeers,
            ],
            'peers' => $peers,
        ];
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
                "SELECT COUNT(*) FROM sales s
                 " . sql_sale_activity_left_join_server_request('s', 'sr') . "
                 WHERE s.restaurant_id = :rid AND s.server_id = :uid
                 AND s.status IN ($closedIn)
                 AND " . sql_sale_activity_datetime_expr('s', 'sr') . " >= :s AND " . sql_sale_activity_datetime_expr('s', 'sr') . " < :e"
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

    private function hasExplicitAttendanceRow(int $restaurantId, int $userId, string $dayYmd): bool
    {
        $this->ensureSchema();
        $st = $this->database->pdo()->prepare(
            'SELECT 1 FROM staff_attendance_day
             WHERE restaurant_id = :rid AND user_id = :uid AND day_ymd = :d
             LIMIT 1'
        );
        $st->execute(['rid' => $restaurantId, 'uid' => $userId, 'd' => $dayYmd]);

        return $st->fetchColumn() !== false;
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
            'PRESENT' => 'PRESENT_CONFIRME',
            'PRÉSENT' => 'PRESENT_CONFIRME',
            'PRESENT_CONFIRME' => 'PRESENT_CONFIRME',
            'RETARD_JUSTIFIE' => 'RETARD_JUSTIFIE',
            'RETARD_JUSTIFIÉ' => 'RETARD_JUSTIFIE',
        ];

        return $aliases[$u] ?? (str_starts_with($u, 'REPOS') ? 'REPOS' : 'TRAVAIL');
    }

    /**
     * @param list<array{label:string,points:int}> $extraPenalties
     *
     * @return array{evaluated:true, score:int, action_count:int, activity_breakdown: list<array{label:string,count:int}>, ledger_delta:int, ledger_lines: list<array<string,mixed>>, extra_penalties: list<array{label:string,points:int}>, base_score:int, evaluation_kind:string, synthetic_adjustment:int, peer_activity_ratio:?float, role_activity_mean:?float, activite_pct_vs_role:?int}
     */
    private function finalizeEvaluatedDay(
        int $actionCount,
        array $breakdown,
        int $ledgerDelta,
        array $ledgerLines,
        array $extraPenalties,
        string $evaluationKind,
        int $syntheticAdjustment,
        ?float $peerActivityRatio = null,
        ?float $peerRoleActivityMean = null,
        int $peerGroupSize = 0,
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

        if ($evaluationKind === 'audit_activity'
            && $peerActivityRatio !== null
            && is_finite($peerActivityRatio)
            && $peerGroupSize >= 2) {
            if ($peerActivityRatio < 0.25) {
                $score = min($score, 58);
            } elseif ($peerActivityRatio < 0.50) {
                $score = min($score, 73);
            } elseif ($peerActivityRatio < 0.80) {
                $score = min($score, 88);
            }
        }

        $pct = ($peerActivityRatio !== null && is_finite($peerActivityRatio))
            ? (int) max(0, min(500, (int) round($peerActivityRatio * 100)))
            : null;

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
            'peer_activity_ratio' => $peerActivityRatio,
            'role_activity_mean' => $peerRoleActivityMean,
            'activite_pct_vs_role' => $pct,
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
        if ($roleCode === 'owner') {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => 0,
                'ledger_lines' => [],
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'owner_exempt',
                'synthetic_adjustment' => 0,
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
        }

        $uTenant = $this->userTenantRowForDiscipline($restaurantId, $userId);
        if ($uTenant === null) {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => 0,
                'ledger_lines' => [],
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'unknown_user',
                'synthetic_adjustment' => 0,
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
        }
        if (($uTenant['status'] ?? '') !== 'active') {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => 0,
                'ledger_lines' => [],
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'account_inactive',
                'synthetic_adjustment' => 0,
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
        }

        $pdo = $this->database->pdo();
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
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
        }

        $engagementYmd = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        if ($dayYmd < $engagementYmd) {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => $ledgerDelta,
                'ledger_lines' => $ledgerLines,
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'pre_hire',
                'synthetic_adjustment' => 0,
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
        }

        $pack = $this->measureActivityVolumeForDay($restaurantId, $userId, $roleCode, $dayYmd, $tz);
        $actionCount = $pack['action_count'];
        $breakdown = $pack['breakdown'];
        $s = $pack['s'];
        $e = $pack['e'];

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
            $peerPack = $this->computePeerRoleActivityPackage(
                $restaurantId,
                $userId,
                $roleCode,
                $dayYmd,
                $actionCount,
                $tz,
                $pdo,
            );
            $peerAdj = $peerPack['lines'];
            if ($roleCode === 'kitchen' && $peerPack['peers'] !== []) {
                $sp = $this->kitchenPeerSpeedAdjustment(
                    $restaurantId,
                    $userId,
                    $peerPack['peers'],
                    $pdo,
                    $s,
                    $e,
                );
                if ($sp !== null) {
                    $peerAdj[] = $sp;
                }
            }
            $merged = array_merge($extraPenalties, $bonuses, $peerAdj);
            $meta = $peerPack['meta'];

            return $this->finalizeEvaluatedDay(
                $actionCount,
                $breakdown,
                $ledgerDelta,
                $ledgerLines,
                $merged,
                'audit_activity',
                0,
                $meta['ratio'],
                $meta['role_mean'],
                $meta['peers_n'],
            );
        }

        if ($this->ledgerDeclaresDayExempt($ledgerLines)) {
            $bd = [['label' => 'Exonération discipline (journal) · jour neutre', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'neutral_exempt', 0);
        }

        $hasExplicitAttendance = $this->hasExplicitAttendanceRow($restaurantId, $userId, $dayYmd);
        if (!$hasExplicitAttendance) {
            $firstActivityYmd = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
            if ($firstActivityYmd === null) {
                return [
                    'evaluated' => false,
                    'score' => null,
                    'action_count' => 0,
                    'activity_breakdown' => [],
                    'ledger_delta' => $ledgerDelta,
                    'ledger_lines' => $ledgerLines,
                    'extra_penalties' => [],
                    'base_score' => 100,
                    'evaluation_kind' => 'never_active',
                    'synthetic_adjustment' => 0,
                    'peer_activity_ratio' => null,
                    'role_activity_mean' => null,
                    'activite_pct_vs_role' => null,
                ];
            }
            if ($firstActivityYmd > $dayYmd) {
                return [
                    'evaluated' => false,
                    'score' => null,
                    'action_count' => 0,
                    'activity_breakdown' => [],
                    'ledger_delta' => $ledgerDelta,
                    'ledger_lines' => $ledgerLines,
                    'extra_penalties' => [],
                    'base_score' => 100,
                    'evaluation_kind' => 'not_yet_active',
                    'synthetic_adjustment' => 0,
                    'peer_activity_ratio' => null,
                    'role_activity_mean' => null,
                    'activite_pct_vs_role' => null,
                ];
            }
        }

        if (!$hasExplicitAttendance && $roleCode === 'manager') {
            return [
                'evaluated' => false,
                'score' => null,
                'action_count' => 0,
                'activity_breakdown' => [],
                'ledger_delta' => $ledgerDelta,
                'ledger_lines' => $ledgerLines,
                'extra_penalties' => [],
                'base_score' => 100,
                'evaluation_kind' => 'implicit_neutral_no_override',
                'synthetic_adjustment' => 0,
                'peer_activity_ratio' => null,
                'role_activity_mean' => null,
                'activite_pct_vs_role' => null,
            ];
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
        if ($planned === 'PRESENT_CONFIRME') {
            $bd = [['label' => 'Présence confirmée par le responsable · neutre', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'manager_present_confirm', 0);
        }
        if ($planned === 'RETARD_JUSTIFIE') {
            $bd = [['label' => 'Retard justifié (planning)', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'late_justified', -6);
        }
        if ($planned === 'ABSENCE_AUTORISEE') {
            $bd = [['label' => 'Absence autorisée (planning) · pénalité légère', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_authorized', -12);
        }
        if ($planned === 'MALADIE') {
            $bd = [['label' => 'Maladie (planning) · pénalité légère', 'count' => 0]];

            return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_illness', -14);
        }

        $streakTravailSansActivite = $this->consecutiveApplicableTravailZeroActivityStreak(
            $restaurantId,
            $userId,
            $roleCode,
            $dayYmd,
            $tz,
            14,
        );
        $penAbs = $streakTravailSansActivite >= 3 ? -28 : ($streakTravailSansActivite >= 2 ? -18 : -12);
        $bd = [['label' => 'Saisie responsable « travail » sans activité métier mesurée (pénalité renforcée si répétée)', 'count' => 0]];

        return $this->finalizeEvaluatedDay(0, $bd, $ledgerDelta, $ledgerLines, [], 'absence_unjustified', $penAbs);
    }

    /**
     * Compte les jours applicables au travail sans activité, même sans ligne explicite,
     * dès que l’agent a déjà commencé à travailler dans le restaurant.
     */
    private function consecutiveApplicableTravailZeroActivityStreak(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $endYmd,
        DateTimeZone $tz,
        int $maxDays,
    ): int {
        $glob = $this->effectiveGlobalStartYmd($restaurantId);
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $firstActivityYmd = $this->userFirstActivityDayYmd($restaurantId, $userId, $tz);
        if ($firstActivityYmd === null) {
            return 0;
        }

        $streak = 0;
        for ($i = 0; $i < $maxDays; $i++) {
            try {
                $d = (new DateTimeImmutable($endYmd . ' 00:00:00', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            } catch (\Throwable) {
                break;
            }
            if ($d < max($glob, $engagement) || $d < $firstActivityYmd) {
                break;
            }
            $pack = $this->measureActivityVolumeForDay($restaurantId, $userId, $roleCode, $d, $tz);
            if ((int) ($pack['action_count'] ?? 0) > 0) {
                break;
            }
            $ledgerLines = $this->ledgerLinesForDay($restaurantId, $userId, $d);
            if ($this->ledgerDeclaresDayExempt($ledgerLines)) {
                break;
            }
            if ($this->attendancePlannedStatusForDay($restaurantId, $userId, $d) !== 'TRAVAIL') {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * Nombre de jours calendaires consécutifs se terminant à {@see $endYmd} avec ligne de présence « travail »
     * et aucune activité domaine / audit comptée (pour graduer la sanction uniquement sur l’absence répétée explicite).
     */
    private function consecutiveExplicitTravailZeroActivityStreak(
        int $restaurantId,
        int $userId,
        string $roleCode,
        string $endYmd,
        DateTimeZone $tz,
        int $maxDays,
    ): int {
        $streak = 0;
        for ($i = 0; $i < $maxDays; $i++) {
            try {
                $d = (new DateTimeImmutable($endYmd . ' 00:00:00', $tz))->modify('-' . $i . ' days')->format('Y-m-d');
            } catch (\Throwable) {
                break;
            }
            if (!$this->hasExplicitAttendanceRow($restaurantId, $userId, $d)) {
                break;
            }
            if ($this->attendancePlannedStatusForDay($restaurantId, $userId, $d) !== 'TRAVAIL') {
                break;
            }
            if ($this->measureActivityVolumeForDay($restaurantId, $userId, $roleCode, $d, $tz)['action_count'] > 0) {
                break;
            }
            $streak++;
        }

        return $streak;
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
                    $pts = max(-28, -14 * min(2, $rej));
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
                $pts = max(-36, -12 * min(3, $late));
                $out[] = ['label' => 'Remises tardives (jour de vente ≠ jour de remise) — gravité élevée', 'points' => $pts];
            }
            $schedCd = $this->disciplineWorkScheduleForRestaurant($restaurantId);
            $tzCd = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
            try {
                $dayStartCd = new DateTimeImmutable($dayYmd . ' 00:00:00', $tzCd);
                $cdParts = explode(':', $schedCd['cash_deadline']);
                $cdH = (int) ($cdParts[0] ?? 22);
                $cdMi = (int) ($cdParts[1] ?? 0);
                $cashDeadline = $dayStartCd->setTime(max(0, min(23, $cdH)), max(0, min(59, $cdMi)));
                $suffixCd = $schedCd['notice_unset'] ? ' (horaire par défaut)' : '';
                $stCd = $pdo->prepare(
                    'SELECT COALESCE(requested_at, created_at) AS t FROM cash_transfers
                     WHERE restaurant_id = :rid AND from_user_id = :uid AND source_type = "sale"
                       AND COALESCE(requested_at, created_at) >= :s AND COALESCE(requested_at, created_at) < :e'
                );
                $stCd->execute(['rid' => $restaurantId, 'uid' => $userId, 's' => $s, 'e' => $e]);
                $lateAfterDl = 0;
                while ($rowCd = $stCd->fetch(PDO::FETCH_ASSOC)) {
                    $ts = (string) ($rowCd['t'] ?? '');
                    if ($ts === '') {
                        continue;
                    }
                    try {
                        $locT = (new DateTimeImmutable($ts, new DateTimeZone('UTC')))->setTimezone($tzCd);
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($locT->format('Y-m-d') === $dayYmd && $locT > $cashDeadline) {
                        $lateAfterDl++;
                    }
                }
                if ($lateAfterDl > 0) {
                    $out[] = [
                        'label' => 'Remise caisse après l’heure limite paramétrée (' . $schedCd['cash_deadline'] . ')' . $suffixCd,
                        'points' => max(-18, -6 * min(3, $lateAfterDl)),
                    ];
                }
            } catch (\Throwable) {
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

        if (in_array($roleCode, ['cashier_server', 'kitchen', 'stock_manager', 'cashier_accountant'], true)) {
            $sched = $this->disciplineWorkScheduleForRestaurant($restaurantId);
            $tzLocal = Container::getInstance()->get('reportService')->timezoneForRestaurantReports($restaurantId);
            $first = $this->firstAuditInWindow($restaurantId, $userId, $s, $e, $pdo);
            if ($first !== null) {
                try {
                    $dayStart = new DateTimeImmutable($dayYmd . ' 00:00:00', $tzLocal);
                    $parts = explode(':', $sched['work_start']);
                    $h = (int) ($parts[0] ?? 8);
                    $mi = (int) ($parts[1] ?? 0);
                    $deadline = $dayStart->setTime(max(0, min(23, $h)), max(0, min(59, $mi)))
                        ->modify('+' . (int) $sched['arrival_grace_minutes'] . ' minutes');
                    $localFirst = $first->setTimezone($tzLocal);
                    if ($localFirst > $deadline) {
                        $minsLate = (int) max(0, floor(($localFirst->getTimestamp() - $deadline->getTimestamp()) / 60));
                        $suffix = $sched['notice_unset'] ? ' (horaire par défaut)' : '';
                        $pts = $minsLate <= 20 ? -5 : ($minsLate <= 60 ? -8 : -12);
                        $out[] = [
                            'label' => 'Retard léger · 1re action après ' . $sched['work_start']
                                . ' + ' . $sched['arrival_grace_minutes'] . ' min' . $suffix . ' (+' . $minsLate . ' min)',
                            'points' => $pts,
                        ];
                    }
                } catch (\Throwable) {
                }
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        try {
            $cursor = new DateTimeImmutable($todayYmd . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return null;
        }
        $sum = 0.0;
        $evalDays = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $cursor->modify('-' . $i . ' days')->format('Y-m-d');
            if ($d < max($glob, $engagement)) {
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $monthStartYmd = $monthFirst->format('Y-m-d') >= $glob ? $monthFirst->format('Y-m-d') : $glob;
        $startYmd = max($monthStartYmd, $engagement);
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
                'late_justified' => 'Ajustement discipline · retard justifié',
                'manager_present_confirm' => 'Ajustement discipline · présence confirmée',
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
                'not_yet_active' => 'Non évalué — agent pas encore actif à cette date malgré une activité ultérieure dans le mois.',
                'pre_hire' => 'Non évalué — avant la date de prise de service (compte ou date renseignée sur le profil paie).',
                'pre_reset' => 'Non évalué — période avant donnée exploitable après réinitialisation.',
                'account_inactive' => 'Compte inactif, suspendu ou archivé : pas de cotation discipline.',
                'unknown_user' => 'Utilisateur introuvable pour ce restaurant.',
                'implicit_neutral_no_override' => 'Non coté : aucune saisie responsable (repos / maladie / exonération) et aucune activité métier mesurée — la présence suit l’activité réelle.',
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $floor = max($glob, $engagement);
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $floor = max($glob, $engagement);
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
        $engagement = $this->effectiveAgentEngagementStartYmd($restaurantId, $userId, $tz);
        $cursorY = max($cursor->format('Y-m-d'), $engagement);
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
                $ek = (string) ($ev['evaluation_kind'] ?? '');
                if ($ac === 0 && !in_array($ek, ['neutral_rest', 'neutral_exempt', 'manager_present_confirm'], true)) {
                    $stZeroAct++;
                }
                $sc = (int) ($ev['score'] ?? 0);
                $penOff += max(0, 100 - $sc);
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
                } elseif ($ek === 'manager_present_confirm') {
                    $stExempt++;
                } elseif ($ek === 'late_justified') {
                    $stSoft++;
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

        $rawAvg = round($sum / $evalDays, 1);
        if (!is_finite($rawAvg)) {
            $rawAvg = 0.0;
        }
        $monthRules = $this->monthlySeverityAdjustedScore(
            $restaurantId,
            $userId,
            $role,
            $cursor->format('Y-m-d'),
            $end->format('Y-m-d'),
            $tz,
            $rawAvg,
            $monthStats,
        );
        $avg = $monthRules['score'] ?? $rawAvg;
        $note = 'Moyenne provisoire du mois sur les jours depuis l\'entree en service (activite, repos neutre, absences penalisees).';
        if (($monthRules['cap_score'] ?? null) !== null && !empty($monthRules['cap_reasons'])) {
            $note = 'Moyenne brute ' . $monthRules['raw_score'] . ' %, plafonnee a ' . $monthRules['cap_score']
                . ' % pour le mois : ' . implode(', ', (array) $monthRules['cap_reasons']) . '.';
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
                'effective_period_start' => $cursor->format('Y-m-d'),
                'restaurant_start_ymd' => $glob,
                'engagement_start_ymd' => $engagement,
                'month_stats' => $monthStats,
                'month_rules' => $monthRules,
            ],
            'note' => $note,
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
