<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalAuditService
{
    public function __construct(
        private readonly Database $database,
        private readonly ExternalAuditEngine $engine
    ) {
    }

    public function dashboard(int $restaurantId, string $date, ?int $onlyAuthorId = null): array
    {
        $this->ensureDefaultCategories($restaurantId);
        $this->notifyMissingReports($restaurantId, $date);
        $statement = $this->database->pdo()->prepare(
            'SELECT r.*, u.full_name AS author_name, res.calculated_sales, res.missing_amount,
                    res.suspicious_amount, res.injection_amount, res.cash_gap
             FROM external_audit_reports r
             INNER JOIN users u ON u.id = r.operational_author_id
             LEFT JOIN external_audit_results res
               ON res.restaurant_id = r.restaurant_id AND res.report_id = r.id
             WHERE r.restaurant_id = :restaurant_id AND r.activity_date = :activity_date'
             . ($onlyAuthorId !== null ? ' AND r.operational_author_id = :author_id' : '')
             . ' ORDER BY r.updated_at DESC LIMIT 100'
        );
        $parameters = ['restaurant_id' => $restaurantId, 'activity_date' => $date];
        if ($onlyAuthorId !== null) {
            $parameters['author_id'] = $onlyAuthorId;
        }
        $statement->execute($parameters);
        $reports = $statement->fetchAll(PDO::FETCH_ASSOC);

        $summary = ['reports' => count($reports), 'drafts' => 0, 'submitted' => 0, 'calculated_sales' => 0.0, 'missing_amount' => 0.0, 'suspicious_amount' => 0.0, 'injection_amount' => 0.0, 'cash_gap' => 0.0];
        foreach ($reports as $report) {
            $summary['drafts'] += $report['status'] === 'BROUILLON' ? 1 : 0;
            $summary['submitted'] += in_array($report['status'], ['SOUMIS', 'VERROUILLE', 'CORRIGE'], true) ? 1 : 0;
            foreach (['calculated_sales', 'missing_amount', 'suspicious_amount', 'injection_amount', 'cash_gap'] as $key) {
                $summary[$key] += (float) ($report[$key] ?? 0);
            }
        }

        return ['reports' => $reports, 'summary' => $summary];
    }

    public function categories(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_categories WHERE restaurant_id = :restaurant_id AND status = "active" ORDER BY name'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function products(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT p.*, c.name AS category_name
             FROM external_audit_products p
             INNER JOIN external_audit_categories c ON c.id = p.category_id AND c.restaurant_id = p.restaurant_id
             WHERE p.restaurant_id = :restaurant_id AND p.status = "active"
             ORDER BY c.name, p.name'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeUsers(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id,u.full_name,r.code AS role_code,r.name AS role_name
             FROM users u INNER JOIN roles r ON r.id=u.role_id
             WHERE u.restaurant_id=:restaurant_id AND u.status="active" ORDER BY u.full_name'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roleExpectations(int $restaurantId): array
    {
        $this->ensureRoleExpectations($restaurantId);
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_role_expectations
             WHERE restaurant_id=:restaurant_id ORDER BY role_label'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateRoleExpectation(int $restaurantId, string $roleCode, string $deadline, bool $required, array $actor): void
    {
        $allowed = ['cashier_server','stock_manager','kitchen'];
        if (!in_array($roleCode, $allowed, true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $deadline)) {
            throw new RuntimeException('Fonction ou heure limite Audit externe invalide.');
        }
        $this->ensureRoleExpectations($restaurantId);
        $statement = $this->database->pdo()->prepare(
            'UPDATE external_audit_role_expectations
             SET deadline_time=:deadline_time,is_required=:is_required,updated_at=NOW()
             WHERE restaurant_id=:restaurant_id AND role_code=:role_code'
        );
        $statement->execute([
            'deadline_time' => $deadline . ':00',
            'is_required' => $required ? 1 : 0,
            'restaurant_id' => $restaurantId,
            'role_code' => $roleCode,
        ]);
        $this->log($restaurantId, null, 'EXPECTATION_UPDATED', [
            'role_code' => $roleCode,
            'deadline_time' => $deadline,
            'is_required' => $required,
        ], (int) $actor['id']);
    }

    public function reportTracking(int $restaurantId, string $from, string $to): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw new RuntimeException('Periode de suivi Audit externe invalide.');
        }
        $this->ensureRoleExpectations($restaurantId);
        $expectationsByRole = [];
        foreach ($this->roleExpectations($restaurantId) as $expectation) {
            if ((bool) $expectation['is_required']) {
                $expectationsByRole[(string) $expectation['role_code']] = $expectation;
            }
        }
        $users = [];
        foreach ($this->activeUsers($restaurantId) as $user) {
            $expectation = $expectationsByRole[(string) $user['role_code']] ?? null;
            if (!is_array($expectation)) {
                continue;
            }
            $users[] = array_merge($user, [
                'role_label' => $expectation['role_label'],
                'report_type' => $expectation['report_type'],
                'deadline_time' => $expectation['deadline_time'],
            ]);
        }
        usort($users, static fn (array $a, array $b): int => [$a['role_label'], $a['full_name']] <=> [$b['role_label'], $b['full_name']]);

        $reportsStatement = $this->database->pdo()->prepare(
            'SELECT id,operational_author_id,activity_date,report_type,status,submitted_at,created_at
             FROM external_audit_reports
             WHERE restaurant_id=:restaurant_id AND activity_date BETWEEN :date_from AND :date_to'
        );
        $reportsStatement->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $reportMap = [];
        foreach ($reportsStatement->fetchAll(PDO::FETCH_ASSOC) as $report) {
            $reportMap[$report['activity_date'] . ':' . $report['operational_author_id'] . ':' . $report['report_type']] = $report;
        }

        $today = today_for_restaurant();
        $now = new \DateTimeImmutable('now');
        $rows = [];
        $byUser = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable(min($to, $today));
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            foreach ($users as $user) {
                $key = $date . ':' . $user['id'] . ':' . $user['report_type'];
                $report = $reportMap[$key] ?? null;
                $received = is_array($report) && in_array((string) $report['status'], ['SOUMIS','VERROUILLE','CORRIGE'], true) && !empty($report['submitted_at']);
                $deadline = new \DateTimeImmutable($date . ' ' . substr((string) $user['deadline_time'], 0, 8));
                $submitted = $received ? new \DateTimeImmutable((string) $report['submitted_at']) : null;
                $lateMinutes = $submitted !== null && $submitted > $deadline
                    ? (int) floor(($submitted->getTimestamp() - $deadline->getTimestamp()) / 60)
                    : 0;
                $isOverdue = !$received && $now > $deadline;
                $status = $received ? ($lateMinutes > 0 ? 'RECU_EN_RETARD' : 'RECU_A_TEMPS') : ($isOverdue ? 'MANQUANT' : 'EN_ATTENTE');
                $row = [
                    'user_id' => (int) $user['id'],
                    'name' => $user['full_name'],
                    'role_code' => $user['role_code'],
                    'function' => $user['role_label'],
                    'expected_report' => $user['report_type'],
                    'received' => $received,
                    'activity_date' => $date,
                    'deadline_time' => substr((string) $user['deadline_time'], 0, 5),
                    'submitted_at' => $submitted?->format('Y-m-d H:i:s'),
                    'submission_time' => $submitted?->format('H:i'),
                    'late_minutes' => $lateMinutes,
                    'delay' => $lateMinutes > 0 ? $lateMinutes . ' min' : ($isOverdue ? 'Non remis' : '0 min'),
                    'status' => $status,
                    'report_id' => (int) ($report['id'] ?? 0),
                ];
                $rows[] = $row;
                $uid = (int) $user['id'];
                $byUser[$uid] ??= [
                    'user_id' => $uid,
                    'name' => $user['full_name'],
                    'function' => $user['role_label'],
                    'expected' => 0,
                    'received' => 0,
                    'missing' => 0,
                    'late' => 0,
                    'on_time' => 0,
                    'punctuality_rate' => 0.0,
                ];
                $byUser[$uid]['expected']++;
                $byUser[$uid]['received'] += $received ? 1 : 0;
                $byUser[$uid]['missing'] += $isOverdue ? 1 : 0;
                $byUser[$uid]['late'] += $lateMinutes > 0 ? 1 : 0;
                $byUser[$uid]['on_time'] += $received && $lateMinutes === 0 ? 1 : 0;
            }
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($byUser as &$indicator) {
            $indicator['punctuality_rate'] = $indicator['expected'] > 0
                ? round(100 * $indicator['on_time'] / $indicator['expected'], 2)
                : 0.0;
        }
        unset($indicator);
        $indicators = array_values($byUser);
        $mostPunctual = $indicators;
        usort($mostPunctual, static fn (array $a, array $b): int => [$b['punctuality_rate'], $b['received']] <=> [$a['punctuality_rate'], $a['received']]);
        $mostLate = $indicators;
        usort($mostLate, static fn (array $a, array $b): int => [$b['late'], $b['received']] <=> [$a['late'], $a['received']]);
        $mostMissing = $indicators;
        usort($mostMissing, static fn (array $a, array $b): int => [$b['missing'], $b['expected']] <=> [$a['missing'], $a['expected']]);

        $serverRows = array_values(array_filter($rows, static fn (array $row): bool => $row['role_code'] === 'cashier_server'));
        return [
            'rows' => $rows,
            'indicators' => $indicators,
            'rankings' => [
                'most_punctual' => $mostPunctual,
                'most_late' => $mostLate,
                'most_missing' => $mostMissing,
            ],
            'summary' => [
                'expected' => count($rows),
                'received' => count(array_filter($rows, static fn (array $row): bool => $row['received'])),
                'missing' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'MANQUANT')),
                'late' => count(array_filter($rows, static fn (array $row): bool => $row['late_minutes'] > 0)),
                'servers_expected' => count($serverRows),
                'server_reports_received' => count(array_filter($serverRows, static fn (array $row): bool => $row['received'])),
                'server_reports_missing' => count(array_filter($serverRows, static fn (array $row): bool => $row['status'] === 'MANQUANT')),
                'active_server_count' => count(array_filter($users, static fn (array $user): bool => $user['role_code'] === 'cashier_server')),
            ],
        ];
    }

    public function productsForReport(int $restaurantId, string $activityDate): array
    {
        $products = $this->products($restaurantId);
        $statement = $this->database->pdo()->prepare(
            'SELECT i.product_id,i.remaining_stock
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             INNER JOIN (
                SELECT i2.product_id,MAX(r2.activity_date) AS max_date
                FROM external_audit_report_items i2
                INNER JOIN external_audit_reports r2 ON r2.id=i2.report_id AND r2.restaurant_id=i2.restaurant_id
                WHERE i2.restaurant_id=:restaurant_id_inner AND r2.activity_date<:activity_date_inner
                  AND r2.status IN ("SOUMIS","VERROUILLE","CORRIGE")
                GROUP BY i2.product_id
             ) latest ON latest.product_id=i.product_id AND latest.max_date=r.activity_date
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date<:activity_date'
        );
        $statement->execute([
            'restaurant_id_inner' => $restaurantId,
            'activity_date_inner' => $activityDate,
            'restaurant_id' => $restaurantId,
            'activity_date' => $activityDate,
        ]);
        $previous = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $previous[(int) $row['product_id']] = (float) $row['remaining_stock'];
        }
        foreach ($products as &$product) {
            $product['previous_stock_default'] = $previous[(int) $product['id']] ?? null;
        }
        unset($product);
        return $products;
    }

    public function createCategory(int $restaurantId, string $name, string $mode, array $actor): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Le nom de la categorie est obligatoire.');
        }
        $code = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_categories
             (restaurant_id, name, code, audit_mode, created_by, created_at, updated_at)
             VALUES (:restaurant_id, :name, :code, :audit_mode, :created_by, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), audit_mode = VALUES(audit_mode), updated_at = NOW()'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'name' => $name, 'code' => $code, 'audit_mode' => $mode, 'created_by' => (int) $actor['id']]);
    }

    public function createProduct(int $restaurantId, array $data, array $actor): void
    {
        $categoryId = (int) ($data['category_id'] ?? 0);
        $check = $this->database->pdo()->prepare('SELECT id FROM external_audit_categories WHERE id = :id AND restaurant_id = :restaurant_id');
        $check->execute(['id' => $categoryId, 'restaurant_id' => $restaurantId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Categorie Audit externe invalide.');
        }
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_products
             (restaurant_id, category_id, name, unit, product_type, sale_price, usual_purchase_price,
              units_per_case, units_per_half_case, created_by, created_at, updated_at)
             VALUES (:restaurant_id, :category_id, :name, :unit, :product_type, :sale_price,
              :usual_purchase_price, :units_per_case, :units_per_half_case, :created_by, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'category_id' => $categoryId,
            'name' => trim((string) ($data['name'] ?? '')),
            'unit' => trim((string) ($data['unit'] ?? 'unite')),
            'product_type' => trim((string) ($data['product_type'] ?? 'standard')),
            'sale_price' => max(0, (float) ($data['sale_price'] ?? 0)),
            'usual_purchase_price' => max(0, (float) ($data['usual_purchase_price'] ?? 0)),
            'units_per_case' => max(0, (float) ($data['units_per_case'] ?? 0)),
            'units_per_half_case' => max(0, (float) ($data['units_per_half_case'] ?? 0)),
            'created_by' => (int) $actor['id'],
        ]);
    }

    public function saveDraft(int $restaurantId, array $data, array $actor): int
    {
        $pdo = $this->database->pdo();
        $reportId = (int) ($data['report_id'] ?? 0);
        $isNewReport = $reportId === 0;
        $authorId = (int) ($data['operational_author_id'] ?? $actor['id']);
        $type = (string) ($data['report_type'] ?? 'boissons');
        $date = (string) ($data['activity_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('La date d activite est obligatoire.');
        }
        if ($authorId !== (int) $actor['id'] && trim((string) ($data['delegation_reason'] ?? '')) === '') {
            throw new RuntimeException('Le motif de saisie pour un agent empeche est obligatoire.');
        }
        if (
            $authorId !== (int) $actor['id']
            && ($actor['scope'] ?? null) !== 'super_admin'
            && !in_array((string) ($actor['role_code'] ?? ''), ['owner', 'manager'], true)
        ) {
            throw new RuntimeException('Seul un gerant autorise peut saisir pour un agent empeche.');
        }
        if (!empty($data['is_test'])) {
            $restaurant = \App\Core\Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
            if (!is_array($restaurant) || !\App\Core\Container::getInstance()->get('restaurantAdmin')->isTestRestaurant($restaurant)) {
                throw new RuntimeException('Un rapport de test ne peut etre cree que dans un restaurant sandbox.');
            }
        }

        $pdo->beginTransaction();
        try {
            if ($reportId > 0) {
                $existing = $this->findReport($restaurantId, $reportId, true);
                $this->assertMayActOnReport($existing, $actor);
                if ($existing['status'] !== 'BROUILLON') {
                    throw new RuntimeException('Un rapport soumis ne peut plus etre modifie.');
                }
                $statement = $pdo->prepare(
                    'UPDATE external_audit_reports SET observations=:observations, declared_sales=:declared_sales,
                     presented_cash=:presented_cash, updated_at=NOW()
                     WHERE id=:id AND restaurant_id=:restaurant_id AND status="BROUILLON"'
                );
                $statement->execute([
                    'observations' => trim((string) ($data['observations'] ?? '')),
                    'declared_sales' => max(0, (float) ($data['declared_sales'] ?? 0)),
                    'presented_cash' => max(0, (float) ($data['presented_cash'] ?? 0)),
                    'id' => $reportId,
                    'restaurant_id' => $restaurantId,
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO external_audit_reports
                     (restaurant_id, report_type, activity_date, operational_author_id, entered_by, entered_by_role,
                      delegation_reason, observations, declared_sales, presented_cash, status, is_test, created_by, created_at, updated_at)
                     VALUES (:restaurant_id,:report_type,:activity_date,:operational_author_id,:entered_by,:entered_by_role,
                      :delegation_reason,:observations,:declared_sales,:presented_cash,"BROUILLON",:is_test,:created_by,NOW(),NOW())'
                );
                $statement->execute([
                    'restaurant_id' => $restaurantId, 'report_type' => $type, 'activity_date' => $date,
                    'operational_author_id' => $authorId, 'entered_by' => (int) $actor['id'],
                    'entered_by_role' => (string) ($actor['role_code'] ?? ''),
                    'delegation_reason' => $authorId !== (int) $actor['id'] ? trim((string) ($data['delegation_reason'] ?? '')) : null,
                    'observations' => trim((string) ($data['observations'] ?? '')),
                    'declared_sales' => max(0, (float) ($data['declared_sales'] ?? 0)),
                    'presented_cash' => max(0, (float) ($data['presented_cash'] ?? 0)),
                    'is_test' => !empty($data['is_test']) ? 1 : 0,
                    'created_by' => (int) $actor['id'],
                ]);
                $reportId = (int) $pdo->lastInsertId();
            }

            foreach (($data['items'] ?? []) as $productId => $item) {
                if ($type === 'serveur') {
                    $item['previous_stock'] = max(0, (float) ($item['sold_quantity_declared'] ?? 0));
                    $item['purchased_quantity'] = 0;
                    $item['explained_entries'] = 0;
                    $item['explained_outputs'] = 0;
                    $item['remaining_stock'] = 0;
                    $item['omitted_remaining_confirmed'] = 1;
                }
                $this->upsertItem($restaurantId, $reportId, (int) $productId, (array) $item, $actor);
            }
            $this->log($restaurantId, $reportId, 'DRAFT_SAVED', ['report_type' => $type], (int) $actor['id']);
            $pdo->commit();
            if ($isNewReport) {
                $this->notifyManagers(
                    $restaurantId,
                    $actor,
                    'external_audit.started',
                    'info',
                    'Rapport Audit externe commence',
                    'Le rapport #' . $reportId . ' a ete commence pour le ' . $date . '.',
                    '/audit-externe/rapports/' . $reportId,
                    'ea:started:' . $reportId
                );
            }
            return $reportId;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function submit(int $restaurantId, int $reportId, string $idempotencyKey, array $actor): array
    {
        if (trim($idempotencyKey) === '') {
            throw new RuntimeException('Cle d idempotence obligatoire.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $report = $this->findReport($restaurantId, $reportId, true);
            $this->assertMayActOnReport($report, $actor);
            if ($report['status'] !== 'BROUILLON') {
                if (($report['idempotency_key'] ?? '') === $idempotencyKey) {
                    $pdo->commit();
                    return $report;
                }
                throw new RuntimeException('Ce rapport est deja soumis.');
            }
            $lines = $this->items($restaurantId, $reportId);
            foreach ($lines as $line) {
                if ($line['remaining_stock'] === null && !(bool) $line['omitted_remaining_confirmed']) {
                    throw new RuntimeException('Confirmez explicitement tout stock restant omis avant soumission.');
                }
            }
            $result = $this->engine->report($lines, (float) $report['declared_sales'], (float) $report['presented_cash'], (float) $report['adjustments_validated']);
            $resultStatement = $pdo->prepare(
                'INSERT INTO external_audit_results
                 (restaurant_id,report_id,engine_version,calculated_sales,declared_sales,purchases,expenses,credits,
                  missing_amount,suspicious_amount,injection_amount,prudent_base,expected_amount,presented_cash,cash_gap,
                  snapshot_json,created_by,created_at,updated_at)
                 VALUES (:restaurant_id,:report_id,:engine_version,:calculated_sales,:declared_sales,:purchases,:expenses,:credits,
                  :missing_amount,:suspicious_amount,:injection_amount,:prudent_base,:expected_amount,:presented_cash,:cash_gap,
                  :snapshot_json,:created_by,NOW(),NOW())'
            );
            $resultStatement->execute(array_merge($result, [
                'restaurant_id' => $restaurantId, 'report_id' => $reportId,
                'snapshot_json' => json_encode(['report' => $report, 'items' => $lines, 'result' => $result], JSON_UNESCAPED_UNICODE),
                'created_by' => (int) $actor['id'],
            ]));
            $update = $pdo->prepare(
                'UPDATE external_audit_reports SET status=CASE WHEN version_no>1 THEN "CORRIGE" ELSE "SOUMIS" END, idempotency_key=:key, submitted_at=NOW(), updated_at=NOW()
                 WHERE id=:id AND restaurant_id=:restaurant_id AND status="BROUILLON"'
            );
            $update->execute(['key' => $idempotencyKey, 'id' => $reportId, 'restaurant_id' => $restaurantId]);
            $this->log($restaurantId, $reportId, 'REPORT_SUBMITTED', ['engine_version' => ExternalAuditEngine::VERSION], (int) $actor['id']);
            $pdo->commit();
            $this->notifyManagers($restaurantId, $actor, 'external_audit.submitted', 'success', 'Rapport Audit externe soumis', 'Le rapport #' . $reportId . ' est soumis et fige.', '/audit-externe/rapports/' . $reportId, 'ea:submitted:' . $reportId . ':' . (string) $report['version_no']);
            if ((float) $result['missing_amount'] >= 50000.0) {
                $this->notifyManagers($restaurantId, $actor, 'external_audit.important_missing', 'danger', 'Manquant important Audit externe', 'Le rapport #' . $reportId . ' presente un manquant de ' . number_format((float) $result['missing_amount'], 0, ',', ' ') . '.', '/audit-externe/rapports/' . $reportId, 'ea:important-missing:' . $reportId . ':' . (string) $report['version_no']);
            }
            $this->notifyManagers($restaurantId, $actor, 'external_audit.ready', 'success', 'Rapport Audit externe pret', 'Le rapport independant #' . $reportId . ' est calcule et consultable.', '/audit-externe/rapports/' . $reportId, 'ea:ready:' . $reportId . ':' . (string) $report['version_no']);
            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function findReport(int $restaurantId, int $reportId, bool $forUpdate = false): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT r.*, u.full_name AS author_name FROM external_audit_reports r
             INNER JOIN users u ON u.id=r.operational_author_id
             WHERE r.id=:id AND r.restaurant_id=:restaurant_id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $reportId, 'restaurant_id' => $restaurantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Rapport Audit externe introuvable.');
        }
        return $row;
    }

    public function items(int $restaurantId, int $reportId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_report_items WHERE restaurant_id=:restaurant_id AND report_id=:report_id ORDER BY category_name_snapshot,product_name_snapshot'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function attachments(int $restaurantId, int $reportId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_attachments
             WHERE restaurant_id=:restaurant_id AND report_id=:report_id ORDER BY created_at DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function attachReportEvidence(int $restaurantId, int $reportId, string $originalName, string $path, string $mimeType, int $size, array $actor): void
    {
        $this->findReport($restaurantId, $reportId);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_attachments
             (restaurant_id,report_id,loss_id,original_name,storage_path,mime_type,size_bytes,created_by,created_at,updated_at)
             VALUES (:restaurant_id,:report_id,NULL,:original_name,:storage_path,:mime_type,:size_bytes,:created_by,NOW(),NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'report_id' => $reportId,
            'original_name' => trim($originalName) ?: 'preuve',
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => max(0, $size),
            'created_by' => (int) $actor['id'],
        ]);
        $this->log($restaurantId, $reportId, 'ATTACHMENT_ADDED', ['path' => $path], (int) $actor['id']);
    }

    public function reset(int $restaurantId, int $reportId, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Le motif de reinitialisation est obligatoire.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $report = $this->findReport($restaurantId, $reportId, true);
            $items = $this->items($restaurantId, $reportId);
            $revision = $pdo->prepare(
                'INSERT INTO external_audit_report_revisions
                 (restaurant_id,report_id,version_no,reason,snapshot_json,created_by,created_at,updated_at)
                 VALUES (:restaurant_id,:report_id,:version_no,:reason,:snapshot_json,:created_by,NOW(),NOW())'
            );
            $revision->execute([
                'restaurant_id' => $restaurantId, 'report_id' => $reportId, 'version_no' => (int) $report['version_no'],
                'reason' => trim($reason), 'snapshot_json' => json_encode(['report' => $report, 'items' => $items], JSON_UNESCAPED_UNICODE),
                'created_by' => (int) $actor['id'],
            ]);
            $pdo->prepare('DELETE FROM external_audit_results WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $pdo->prepare('DELETE FROM external_audit_report_items WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $pdo->prepare(
                'UPDATE external_audit_reports SET status="BROUILLON", version_no=version_no+1, idempotency_key=NULL,
                 declared_sales=0,presented_cash=0,observations=NULL,submitted_at=NULL,locked_at=NULL,updated_at=NOW()
                 WHERE restaurant_id=:restaurant_id AND id=:report_id'
            )->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $this->log($restaurantId, $reportId, 'REPORT_RESET', ['reason' => $reason], (int) $actor['id']);
            $pdo->commit();
            $this->notifyManagers($restaurantId, $actor, 'external_audit.reset', 'warning', 'Rapport Audit externe reinitialise', 'La version du rapport #' . $reportId . ' a ete archivee.', '/audit-externe/rapports/' . $reportId, 'ea:reset:' . $reportId . ':' . ((int) $report['version_no'] + 1));
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function result(int $restaurantId, int $reportId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_results WHERE restaurant_id=:restaurant_id AND report_id=:report_id'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function periodData(int $restaurantId, string $from, string $to): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw new RuntimeException('Periode Audit externe invalide.');
        }
        $statement = $this->database->pdo()->prepare(
            'SELECT res.*,r.id AS id,r.report_type,r.activity_date,r.status,r.version_no,r.operational_author_id,
                    u.full_name AS author_name
             FROM external_audit_reports r
             INNER JOIN users u ON u.id=r.operational_author_id
             LEFT JOIN external_audit_results res ON res.restaurant_id=r.restaurant_id AND res.report_id=r.id
             WHERE r.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
             ORDER BY r.activity_date,r.id'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $reports = $statement->fetchAll(PDO::FETCH_ASSOC);
        $items = $this->periodRows(
            'SELECT i.*,r.activity_date,r.report_type,r.version_no,u.full_name AS author_name
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             INNER JOIN users u ON u.id=r.operational_author_id
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
             ORDER BY r.activity_date,r.id,i.id',
            $restaurantId,
            $from,
            $to
        );
        $corrections = $this->periodRows(
            'SELECT c.*,r.activity_date,u.full_name AS requester_name,d.full_name AS decision_author
             FROM external_audit_correction_requests c
             INNER JOIN external_audit_reports r ON r.id=c.report_id AND r.restaurant_id=c.restaurant_id
             INNER JOIN users u ON u.id=c.requested_by
             LEFT JOIN users d ON d.id=c.decided_by
             WHERE c.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
             ORDER BY c.created_at',
            $restaurantId,
            $from,
            $to
        );
        $versions = $this->periodRows(
            'SELECT v.id,v.report_id,v.version_no,v.reason,v.created_at,r.activity_date,u.full_name AS actor_name
             FROM external_audit_report_revisions v
             INNER JOIN external_audit_reports r ON r.id=v.report_id AND r.restaurant_id=v.restaurant_id
             LEFT JOIN users u ON u.id=v.created_by
             WHERE v.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
             ORDER BY v.created_at',
            $restaurantId,
            $from,
            $to
        );
        $logs = $this->periodRows(
            'SELECT l.id,l.report_id,l.action_code,l.details_json,l.created_at,u.full_name AS actor_name
             FROM external_audit_logs l
             LEFT JOIN external_audit_reports r ON r.id=l.report_id AND r.restaurant_id=l.restaurant_id
             LEFT JOIN users u ON u.id=l.created_by
             WHERE l.restaurant_id=:restaurant_id
               AND ((r.activity_date BETWEEN :date_from AND :date_to) OR (l.report_id IS NULL AND DATE(l.created_at) BETWEEN :date_from AND :date_to))
             ORDER BY l.created_at',
            $restaurantId,
            $from,
            $to
        );
        $daily = [];
        foreach ($reports as $report) {
            if (!isset($daily[$report['activity_date']])) {
                $daily[$report['activity_date']] = ['activity_date' => $report['activity_date'], 'reports' => 0, 'calculated_sales' => 0.0, 'declared_sales' => 0.0, 'purchases' => 0.0, 'expenses' => 0.0, 'credits' => 0.0, 'missing_amount' => 0.0, 'suspicious_amount' => 0.0, 'injection_amount' => 0.0, 'expected_amount' => 0.0, 'presented_cash' => 0.0, 'cash_gap' => 0.0];
            }
            $daily[$report['activity_date']]['reports']++;
            foreach (['calculated_sales','declared_sales','purchases','expenses','credits','missing_amount','suspicious_amount','injection_amount','expected_amount','presented_cash','cash_gap'] as $key) {
                $daily[$report['activity_date']][$key] += (float) ($report[$key] ?? 0);
            }
        }
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $days = [];
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $days[] = $daily[$date] ?? ['activity_date' => $date, 'reports' => 0, 'missing' => true];
            $cursor = $cursor->modify('+1 day');
        }
        return [
            'from' => $from,
            'to' => $to,
            'reports' => $reports,
            'items' => $items,
            'incidents' => array_values(array_filter($items, static fn (array $item): bool => trim((string) ($item['incident_note'] ?? '')) !== '')),
            'corrections' => $corrections,
            'versions' => $versions,
            'logs' => $logs,
            'tracking' => $this->reportTracking($restaurantId, $from, $to),
            'days' => $days,
            'totals' => $this->engine->period(array_values($daily)),
            'internal_confrontation' => $this->internalConfrontation($restaurantId, $from, $to),
            'application_confrontation' => $this->applicationConfrontation($restaurantId, $from, $to),
            'losses' => $this->lossAnalysis($restaurantId, $from, $to),
        ];
    }

    public function internalConfrontation(int $restaurantId, string $from, string $to): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT i.product_id,i.product_name_snapshot AS product,i.category_name_snapshot AS category,
                    i.calculated_sold_quantity,i.sold_quantity_declared,i.sale_price_snapshot,i.credit_amount,
                    r.report_type,u.full_name AS person
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             INNER JOIN users u ON u.id=r.operational_author_id
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
               AND r.status IN ("SOUMIS","VERROUILLE","CORRIGE")'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $responsibles = [];
        $servers = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isServer = $row['report_type'] === 'serveur';
            $quantity = $isServer ? (float) $row['sold_quantity_declared'] : (float) $row['calculated_sold_quantity'];
            $comparisonLine = [
                'product_id' => (int) $row['product_id'], 'product' => $row['product'], 'category' => $row['category'],
                'quantity' => $quantity, 'amount' => $quantity * (float) $row['sale_price_snapshot'],
                'credits' => (float) $row['credit_amount'], 'person' => $row['person'],
            ];
            if ($isServer) {
                $servers[] = $comparisonLine;
            } else {
                $responsibles[] = $comparisonLine;
            }
        }
        return $this->engine->confrontation($responsibles, $servers);
    }

    public function applicationConfrontation(int $restaurantId, string $from, string $to): array
    {
        $pdo = $this->database->pdo();
        $audit = $pdo->prepare(
            'SELECT COALESCE(SUM(res.calculated_sales),0) AS sales,
                    COALESCE(SUM(res.purchases),0) AS purchases,
                    COALESCE(SUM(res.expenses),0) AS expenses,
                    COALESCE(SUM(res.credits),0) AS credits,
                    COALESCE(SUM(res.presented_cash),0) AS presented_cash
             FROM external_audit_results res
             INNER JOIN external_audit_reports r ON r.id=res.report_id AND r.restaurant_id=res.restaurant_id
             WHERE res.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to'
        );
        $audit->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $auditTotals = $audit->fetch(PDO::FETCH_ASSOC) ?: [];
        $application = $pdo->prepare(
            'SELECT COALESCE(SUM(total_amount),0) FROM sales
             WHERE restaurant_id=:restaurant_id AND status="VALIDE"
               AND DATE(COALESCE(validated_at,created_at)) BETWEEN :date_from AND :date_to'
        );
        $application->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $rows = [];
        $append = static function (array &$bucket, string $element, float $auditAmount, float $applicationAmount): void {
            $gap = $auditAmount - $applicationAmount;
            $bucket[] = [
                'element' => $element, 'audit_amount' => $auditAmount, 'application_amount' => $applicationAmount,
                'gap' => $gap, 'observation' => abs($gap) < 0.001 ? 'Coherent' : 'Ecart a expliquer',
                'status' => abs($gap) < 0.001 ? 'COHERENT' : 'JUSTIFICATION_EN_ATTENTE',
            ];
        };
        $append($rows, 'Ventes globales', (float) ($auditTotals['sales'] ?? 0), (float) $application->fetchColumn());
        $append($rows, 'Credits (aucune table operationnelle dediee)', (float) ($auditTotals['credits'] ?? 0), 0.0);

        $auditServers = $pdo->prepare(
            'SELECT u.full_name AS label,COALESCE(SUM(i.sold_quantity_declared*i.sale_price_snapshot),0) AS amount
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             INNER JOIN users u ON u.id=r.operational_author_id
             WHERE i.restaurant_id=:restaurant_id AND r.report_type="serveur"
               AND r.activity_date BETWEEN :date_from AND :date_to
               AND r.status IN ("SOUMIS","VERROUILLE","CORRIGE")
             GROUP BY u.id,u.full_name'
        );
        $auditServers->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $appServers = $pdo->prepare(
            'SELECT COALESCE(u.full_name,"Sans serveur") AS label,COALESCE(SUM(s.total_amount),0) AS amount
             FROM sales s LEFT JOIN users u ON u.id=s.server_id
             WHERE s.restaurant_id=:restaurant_id AND s.status="VALIDE"
               AND DATE(COALESCE(s.validated_at,s.created_at)) BETWEEN :date_from AND :date_to
             GROUP BY s.server_id,u.full_name'
        );
        $appServers->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $this->appendGroupedComparisons($rows, 'Vente par serveur', $auditServers->fetchAll(PDO::FETCH_ASSOC), $appServers->fetchAll(PDO::FETCH_ASSOC));

        $auditProducts = $pdo->prepare(
            'SELECT i.product_name_snapshot AS label,COALESCE(SUM(i.calculated_sale_amount),0) AS amount
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
               AND r.report_type<>"serveur" AND r.status IN ("SOUMIS","VERROUILLE","CORRIGE")
             GROUP BY i.product_name_snapshot'
        );
        $auditProducts->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $appProducts = $pdo->prepare(
            'SELECT mi.name AS label,COALESCE(SUM(si.quantity*si.unit_price),0) AS amount
             FROM sale_items si INNER JOIN sales s ON s.id=si.sale_id
             INNER JOIN menu_items mi ON mi.id=si.menu_item_id
             WHERE s.restaurant_id=:restaurant_id AND s.status="VALIDE" AND si.status="SERVI"
               AND DATE(COALESCE(s.validated_at,s.created_at)) BETWEEN :date_from AND :date_to
             GROUP BY mi.id,mi.name'
        );
        $appProducts->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $this->appendGroupedComparisons($rows, 'Vente par article', $auditProducts->fetchAll(PDO::FETCH_ASSOC), $appProducts->fetchAll(PDO::FETCH_ASSOC));

        $auditCategories = $pdo->prepare(
            'SELECT i.category_name_snapshot AS label,COALESCE(SUM(i.calculated_sale_amount),0) AS amount
             FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to
               AND r.report_type<>"serveur" AND r.status IN ("SOUMIS","VERROUILLE","CORRIGE")
             GROUP BY i.category_name_snapshot'
        );
        $auditCategories->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $appCategories = $pdo->prepare(
            'SELECT mc.name AS label,COALESCE(SUM(si.quantity*si.unit_price),0) AS amount
             FROM sale_items si INNER JOIN sales s ON s.id=si.sale_id
             INNER JOIN menu_items mi ON mi.id=si.menu_item_id
             INNER JOIN menu_categories mc ON mc.id=mi.category_id
             WHERE s.restaurant_id=:restaurant_id AND s.status="VALIDE" AND si.status="SERVI"
               AND DATE(COALESCE(s.validated_at,s.created_at)) BETWEEN :date_from AND :date_to
             GROUP BY mc.id,mc.name'
        );
        $appCategories->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $this->appendGroupedComparisons($rows, 'Vente par categorie', $auditCategories->fetchAll(PDO::FETCH_ASSOC), $appCategories->fetchAll(PDO::FETCH_ASSOC));

        $served = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN si.status="SERVI" THEN si.quantity ELSE 0 END),0) AS served,
                    COALESCE(SUM(CASE WHEN si.status="RETOUR" THEN si.quantity ELSE 0 END),0) AS returned
             FROM sale_items si INNER JOIN sales s ON s.id=si.sale_id
             WHERE s.restaurant_id=:restaurant_id AND s.status="VALIDE"
               AND DATE(COALESCE(s.validated_at,s.created_at)) BETWEEN :date_from AND :date_to'
        );
        $served->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $servedTotals = $served->fetch(PDO::FETCH_ASSOC) ?: [];
        $auditQuantity = array_sum(array_map(static fn (array $item): float => (float) ($item['calculated_sold_quantity'] ?? 0), $this->periodRows(
            'SELECT i.calculated_sold_quantity FROM external_audit_report_items i
             INNER JOIN external_audit_reports r ON r.id=i.report_id AND r.restaurant_id=i.restaurant_id
             WHERE i.restaurant_id=:restaurant_id AND r.activity_date BETWEEN :date_from AND :date_to AND r.report_type<>"serveur"',
            $restaurantId,
            $from,
            $to
        )));
        $append($rows, 'Produits servis (quantite)', $auditQuantity, (float) ($servedTotals['served'] ?? 0));
        $append($rows, 'Retours (quantite)', 0.0, (float) ($servedTotals['returned'] ?? 0));

        $stock = $pdo->prepare(
            'SELECT COALESCE(SUM(total_cost_snapshot),0) FROM stock_movements
             WHERE restaurant_id=:restaurant_id AND movement_type="ENTREE" AND status="VALIDE"
               AND DATE(COALESCE(validated_at,created_at)) BETWEEN :date_from AND :date_to'
        );
        $stock->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $append($rows, 'Achats / entrees stock', (float) ($auditTotals['purchases'] ?? 0), (float) $stock->fetchColumn());

        $lossAudit = $this->lossAnalysis($restaurantId, $from, $to);
        $lossApplication = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM losses
             WHERE restaurant_id=:restaurant_id AND DATE(created_at) BETWEEN :date_from AND :date_to'
        );
        $lossApplication->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $append($rows, 'Pertes declarees', (float) $lossAudit['summary']['total'], (float) $lossApplication->fetchColumn());

        $table = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name="cash_transfers"');
        $table->execute();
        if ((int) $table->fetchColumn() === 1) {
            $cash = $pdo->prepare(
                'SELECT COALESCE(SUM(COALESCE(amount_received,amount)),0) FROM cash_transfers
                 WHERE restaurant_id=:restaurant_id
                   AND DATE(COALESCE(received_at,validated_at,created_at)) BETWEEN :date_from AND :date_to'
            );
            $cash->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
            $append($rows, 'Argent presente / transferts caisse', (float) ($auditTotals['presented_cash'] ?? 0), (float) $cash->fetchColumn());
        }
        return [
            'read_only' => true,
            'rows' => $rows,
        ];
    }

    public function requestCorrection(int $restaurantId, int $reportId, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Le motif de correction est obligatoire.');
        }
        $report = $this->findReport($restaurantId, $reportId);
        $this->assertMayActOnReport($report, $actor);
        if (!in_array($report['status'], ['SOUMIS','VERROUILLE','CORRIGE'], true)) {
            throw new RuntimeException('La correction concerne un rapport soumis.');
        }
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_correction_requests
             (restaurant_id,report_id,reason,status,requested_by,created_by,created_at,updated_at)
             VALUES (:restaurant_id,:report_id,:reason,"PENDING",:requested_by,:created_by,NOW(),NOW())'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId, 'reason' => trim($reason), 'requested_by' => (int) $actor['id'], 'created_by' => (int) $actor['id']]);
        $correctionId = (int) $this->database->pdo()->lastInsertId();
        $this->log($restaurantId, $reportId, 'CORRECTION_REQUESTED', ['reason' => $reason], (int) $actor['id']);
        $this->notifyManagers($restaurantId, $actor, 'external_audit.correction_requested', 'warning', 'Correction Audit externe demandee', 'Une correction est demandee pour le rapport #' . $reportId . '.', '/audit-externe/rapports/' . $reportId, 'ea:correction-request:' . $correctionId);
    }

    public function decideCorrection(int $restaurantId, int $requestId, bool $approve, string $note, array $actor): void
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT * FROM external_audit_correction_requests WHERE id=:id AND restaurant_id=:restaurant_id FOR UPDATE');
            $query->execute(['id' => $requestId, 'restaurant_id' => $restaurantId]);
            $request = $query->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['status'] !== 'PENDING') {
                throw new RuntimeException('Demande de correction introuvable ou deja decidee.');
            }
            $status = $approve ? 'APPROVED' : 'REJECTED';
            $pdo->prepare(
                'UPDATE external_audit_correction_requests SET status=:status,decided_by=:decided_by,
                 decision_note=:decision_note,decided_at=NOW(),updated_at=NOW() WHERE id=:id AND restaurant_id=:restaurant_id'
            )->execute(['status' => $status, 'decided_by' => (int) $actor['id'], 'decision_note' => trim($note), 'id' => $requestId, 'restaurant_id' => $restaurantId]);
            if ($approve) {
                $report = $this->findReport($restaurantId, (int) $request['report_id'], true);
                $items = $this->items($restaurantId, (int) $request['report_id']);
                $pdo->prepare(
                    'INSERT INTO external_audit_report_revisions
                     (restaurant_id,report_id,version_no,reason,snapshot_json,created_by,created_at,updated_at)
                     VALUES (:restaurant_id,:report_id,:version_no,:reason,:snapshot_json,:created_by,NOW(),NOW())'
                )->execute([
                    'restaurant_id' => $restaurantId, 'report_id' => $request['report_id'], 'version_no' => $report['version_no'],
                    'reason' => $request['reason'], 'snapshot_json' => json_encode(['report' => $report, 'items' => $items], JSON_UNESCAPED_UNICODE),
                    'created_by' => (int) $actor['id'],
                ]);
                $pdo->prepare(
                    'UPDATE external_audit_reports SET status="BROUILLON",version_no=version_no+1,idempotency_key=NULL,
                     submitted_at=NULL,locked_at=NULL,updated_at=NOW() WHERE id=:id AND restaurant_id=:restaurant_id'
                )->execute(['id' => $request['report_id'], 'restaurant_id' => $restaurantId]);
                $pdo->prepare('DELETE FROM external_audit_results WHERE report_id=:id AND restaurant_id=:restaurant_id')
                    ->execute(['id' => $request['report_id'], 'restaurant_id' => $restaurantId]);
            }
            $this->log($restaurantId, (int) $request['report_id'], $approve ? 'CORRECTION_APPROVED' : 'CORRECTION_REJECTED', ['note' => $note], (int) $actor['id']);
            $pdo->commit();
            $this->notifyManagers($restaurantId, $actor, $approve ? 'external_audit.correction_approved' : 'external_audit.correction_rejected', $approve ? 'success' : 'warning', $approve ? 'Correction Audit externe acceptee' : 'Correction Audit externe rejetee', 'Decision enregistree pour le rapport #' . $request['report_id'] . '.', '/audit-externe/rapports/' . $request['report_id'], 'ea:correction-decision:' . $requestId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function lock(int $restaurantId, int $reportId, array $actor): void
    {
        $statement = $this->database->pdo()->prepare(
            'UPDATE external_audit_reports SET status="VERROUILLE",locked_at=NOW(),updated_at=NOW()
             WHERE id=:id AND restaurant_id=:restaurant_id AND status IN ("SOUMIS","CORRIGE")'
        );
        $statement->execute(['id' => $reportId, 'restaurant_id' => $restaurantId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Seul un rapport soumis peut etre verrouille.');
        }
        $this->log($restaurantId, $reportId, 'REPORT_LOCKED', [], (int) $actor['id']);
        $this->notifyManagers($restaurantId, $actor, 'external_audit.locked', 'success', 'Rapport Audit externe verrouille', 'Le rapport #' . $reportId . ' est verrouille.', '/audit-externe/rapports/' . $reportId, 'ea:locked:' . $reportId);
        $this->notifyManagers($restaurantId, $actor, 'external_audit.closed', 'success', 'Cloture Audit externe', 'La cloture du rapport #' . $reportId . ' est terminee.', '/audit-externe/rapports/' . $reportId, 'ea:closed:' . $reportId);
    }

    public function cancel(int $restaurantId, int $reportId, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Le motif d annulation est obligatoire.');
        }
        $statement = $this->database->pdo()->prepare(
            'UPDATE external_audit_reports SET status="ANNULE",observations=CONCAT(COALESCE(observations,""),"\nAnnulation: ",:reason),updated_at=NOW()
             WHERE id=:id AND restaurant_id=:restaurant_id AND status<>"ANNULE"'
        );
        $statement->execute(['reason' => trim($reason), 'id' => $reportId, 'restaurant_id' => $restaurantId]);
        $this->log($restaurantId, $reportId, 'REPORT_CANCELLED', ['reason' => $reason], (int) $actor['id']);
    }

    public function revisions(int $restaurantId, int $reportId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT rev.*,u.full_name AS actor_name FROM external_audit_report_revisions rev
             LEFT JOIN users u ON u.id=rev.created_by
             WHERE rev.restaurant_id=:restaurant_id AND rev.report_id=:report_id ORDER BY rev.version_no DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restoreRevision(int $restaurantId, int $reportId, int $revisionId, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Le motif de restauration est obligatoire.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $current = $this->findReport($restaurantId, $reportId, true);
            $currentItems = $this->items($restaurantId, $reportId);
            $statement = $pdo->prepare(
                'SELECT * FROM external_audit_report_revisions
                 WHERE id=:id AND restaurant_id=:restaurant_id AND report_id=:report_id FOR UPDATE'
            );
            $statement->execute(['id' => $revisionId, 'restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $revision = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$revision) {
                throw new RuntimeException('Version archivee introuvable.');
            }
            $snapshot = json_decode((string) $revision['snapshot_json'], true);
            if (!is_array($snapshot) || !is_array($snapshot['report'] ?? null)) {
                throw new RuntimeException('Snapshot de version invalide.');
            }
            $pdo->prepare(
                'INSERT INTO external_audit_report_revisions
                 (restaurant_id,report_id,version_no,reason,snapshot_json,created_by,created_at,updated_at)
                 VALUES (:restaurant_id,:report_id,:version_no,:reason,:snapshot_json,:created_by,NOW(),NOW())'
            )->execute([
                'restaurant_id' => $restaurantId, 'report_id' => $reportId, 'version_no' => $current['version_no'],
                'reason' => 'Avant restauration: ' . trim($reason),
                'snapshot_json' => json_encode(['report' => $current, 'items' => $currentItems], JSON_UNESCAPED_UNICODE),
                'created_by' => (int) $actor['id'],
            ]);
            $pdo->prepare('DELETE FROM external_audit_results WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $pdo->prepare('DELETE FROM external_audit_report_items WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $old = $snapshot['report'];
            $pdo->prepare(
                'UPDATE external_audit_reports SET status="BROUILLON",version_no=version_no+1,idempotency_key=NULL,
                 observations=:observations,declared_sales=:declared_sales,presented_cash=:presented_cash,
                 submitted_at=NULL,locked_at=NULL,updated_at=NOW() WHERE id=:id AND restaurant_id=:restaurant_id'
            )->execute([
                'observations' => $old['observations'] ?? null, 'declared_sales' => (float) ($old['declared_sales'] ?? 0),
                'presented_cash' => (float) ($old['presented_cash'] ?? 0), 'id' => $reportId, 'restaurant_id' => $restaurantId,
            ]);
            foreach (($snapshot['items'] ?? []) as $item) {
                $this->upsertItem($restaurantId, $reportId, (int) ($item['product_id'] ?? 0), $item, $actor);
            }
            $this->log($restaurantId, $reportId, 'REVISION_RESTORED', ['revision_id' => $revisionId, 'reason' => $reason], (int) $actor['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function correctionRequests(int $restaurantId, int $reportId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT c.*,u.full_name AS requester_name FROM external_audit_correction_requests c
             INNER JOIN users u ON u.id=c.requested_by
             WHERE c.restaurant_id=:restaurant_id AND c.report_id=:report_id ORDER BY c.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createLoss(int $restaurantId, array $data, array $actor): int
    {
        $reportId = (int) ($data['report_id'] ?? 0);
        $this->findReport($restaurantId, $reportId);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_losses
             (restaurant_id,report_id,product_id,category_id,activity_date,quantity,value_amount,responsible_user_id,
              involved_people_json,cause,evidence_path,status,manager_decision,decision_by,created_by,created_at,updated_at)
             VALUES (:restaurant_id,:report_id,:product_id,:category_id,:activity_date,:quantity,:value_amount,:responsible_user_id,
              :involved_people_json,:cause,:evidence_path,:status,NULL,NULL,:created_by,NOW(),NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId, 'report_id' => $reportId,
            'product_id' => (int) ($data['product_id'] ?? 0) ?: null, 'category_id' => (int) ($data['category_id'] ?? 0) ?: null,
            'activity_date' => (string) $data['activity_date'], 'quantity' => max(0, (float) ($data['quantity'] ?? 0)),
            'value_amount' => max(0, (float) ($data['value_amount'] ?? 0)),
            'responsible_user_id' => (int) ($data['responsible_user_id'] ?? 0) ?: null,
            'involved_people_json' => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) ($data['involved_people'] ?? ''))))), JSON_UNESCAPED_UNICODE),
            'cause' => trim((string) ($data['cause'] ?? '')), 'evidence_path' => trim((string) ($data['evidence_path'] ?? '')) ?: null,
            'status' => (string) ($data['status'] ?? 'A_VERIFIER'), 'created_by' => (int) $actor['id'],
        ]);
        $lossId = (int) $this->database->pdo()->lastInsertId();
        $this->log($restaurantId, $reportId, 'LOSS_CREATED', ['value' => (float) ($data['value_amount'] ?? 0)], (int) $actor['id']);
        if ((float) ($data['value_amount'] ?? 0) >= 50000.0) {
            $this->notifyManagers($restaurantId, $actor, 'external_audit.important_loss', 'danger', 'Perte importante Audit externe', 'Une perte de ' . number_format((float) $data['value_amount'], 0, ',', ' ') . ' est a analyser pour le rapport #' . $reportId . '.', '/audit-externe/rapports/' . $reportId, 'ea:important-loss:' . $reportId . ':' . $lossId);
        }
        return $lossId;
    }

    public function lossAnalysis(int $restaurantId, string $from, string $to): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT l.*,p.name AS product_name,c.name AS category_name,u.full_name AS responsible_name
             FROM external_audit_losses l
             LEFT JOIN external_audit_products p ON p.id=l.product_id AND p.restaurant_id=l.restaurant_id
             LEFT JOIN external_audit_categories c ON c.id=l.category_id AND c.restaurant_id=l.restaurant_id
             LEFT JOIN users u ON u.id=l.responsible_user_id
             WHERE l.restaurant_id=:restaurant_id AND l.activity_date BETWEEN :date_from AND :date_to
             ORDER BY l.activity_date DESC,l.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['total' => 0.0, 'explained' => 0.0, 'unexplained' => 0.0, 'by_category' => [], 'by_product' => [], 'by_person' => [], 'by_cause' => []];
        foreach ($rows as $row) {
            $value = (float) $row['value_amount'];
            $summary['total'] += $value;
            $explained = in_array($row['status'], ['EXPLIQUE','RESOLU','ANNULE'], true);
            $summary[$explained ? 'explained' : 'unexplained'] += $value;
            foreach (['by_category' => 'category_name','by_product' => 'product_name','by_person' => 'responsible_name','by_cause' => 'cause'] as $bucket => $column) {
                $key = trim((string) ($row[$column] ?? 'Non renseigne')) ?: 'Non renseigne';
                $summary[$bucket][$key] = ($summary[$bucket][$key] ?? 0) + $value;
            }
        }
        return ['rows' => $rows, 'summary' => $summary];
    }

    public function decideLoss(int $restaurantId, int $lossId, string $status, string $decision, array $actor): void
    {
        $allowed = ['A_VERIFIER','EN_JUSTIFICATION','EXPLIQUE','CONFIRME','CONTESTE','RESOLU','ANNULE'];
        if (!in_array($status, $allowed, true) || trim($decision) === '') {
            throw new RuntimeException('Statut et decision du dossier de perte obligatoires.');
        }
        $query = $this->database->pdo()->prepare(
            'SELECT * FROM external_audit_losses WHERE id=:id AND restaurant_id=:restaurant_id'
        );
        $query->execute(['id' => $lossId, 'restaurant_id' => $restaurantId]);
        $loss = $query->fetch(PDO::FETCH_ASSOC);
        if (!$loss) {
            throw new RuntimeException('Dossier de perte introuvable.');
        }
        $statement = $this->database->pdo()->prepare(
            'UPDATE external_audit_losses SET status=:status,manager_decision=:decision,decision_by=:decision_by,updated_at=NOW()
             WHERE id=:id AND restaurant_id=:restaurant_id'
        );
        $statement->execute([
            'status' => $status,
            'decision' => trim($decision),
            'decision_by' => (int) $actor['id'],
            'id' => $lossId,
            'restaurant_id' => $restaurantId,
        ]);
        $this->log($restaurantId, (int) $loss['report_id'], 'LOSS_DECIDED', [
            'loss_id' => $lossId,
            'previous_status' => $loss['status'],
            'status' => $status,
            'decision' => trim($decision),
        ], (int) $actor['id']);
    }

    public function deleteTestReport(int $restaurantId, int $reportId, string $reason, array $actor, array $restaurant): void
    {
        if (trim($reason) === '' || !\App\Core\Container::getInstance()->get('restaurantAdmin')->isTestRestaurant($restaurant)) {
            throw new RuntimeException('Suppression definitive reservee a un restaurant sandbox avec motif.');
        }
        $report = $this->findReport($restaurantId, $reportId);
        if (!(bool) $report['is_test']) {
            throw new RuntimeException('Ce rapport n est pas marque comme test.');
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $this->log($restaurantId, $reportId, 'TEST_REPORT_DELETE_REQUESTED', ['reason' => $reason, 'snapshot' => $report], (int) $actor['id']);
            foreach (['external_audit_attachments','external_audit_losses','external_audit_correction_requests','external_audit_results','external_audit_report_items','external_audit_report_revisions'] as $table) {
                $pdo->prepare('DELETE FROM ' . $table . ' WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                    ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            }
            $pdo->prepare('UPDATE external_audit_logs SET report_id=NULL WHERE restaurant_id=:restaurant_id AND report_id=:report_id')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $pdo->prepare('DELETE FROM external_audit_reports WHERE restaurant_id=:restaurant_id AND id=:report_id AND is_test=1')
                ->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function upsertItem(int $restaurantId, int $reportId, int $productId, array $item, array $actor): void
    {
        if ($productId <= 0) {
            return;
        }
        $productStatement = $this->database->pdo()->prepare(
            'SELECT p.*,c.name AS category_name FROM external_audit_products p
             INNER JOIN external_audit_categories c ON c.id=p.category_id AND c.restaurant_id=p.restaurant_id
             WHERE p.id=:id AND p.restaurant_id=:restaurant_id'
        );
        $productStatement->execute(['id' => $productId, 'restaurant_id' => $restaurantId]);
        $product = $productStatement->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('Produit Audit externe invalide.');
        }
        $packQuantity = $this->engine->caseUnits(
            (float) ($item['cases'] ?? 0),
            (float) ($item['half_cases'] ?? 0),
            (float) ($item['units'] ?? 0),
            (float) $product['units_per_case'],
            (float) $product['units_per_half_case']
        );
        if ($packQuantity > 0) {
            $item['purchased_quantity'] = $packQuantity;
        }
        if ((float) ($item['purchase_total'] ?? 0) <= 0 && (float) ($item['purchase_unit_price'] ?? 0) > 0) {
            $item['purchase_total'] = (float) ($item['purchase_unit_price'] ?? 0) * (float) ($item['purchased_quantity'] ?? 0);
        }
        $item['expense_amount'] = (float) ($item['expense_amount'] ?? 0) + (float) ($item['transport_amount'] ?? 0);
        $line = array_merge($item, ['sale_price_snapshot' => $product['sale_price']]);
        $calculated = $this->engine->product($line);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_report_items
             (restaurant_id,report_id,product_id,category_id,product_name_snapshot,category_name_snapshot,unit_snapshot,
              sale_price_snapshot,purchase_price_snapshot,previous_stock,purchased_quantity,purchase_total,explained_entries,
              explained_outputs,remaining_stock,sold_quantity_declared,credit_amount,expense_amount,incident_note,
              omitted_remaining_confirmed,calculated_available,calculated_sold_quantity,calculated_injection_quantity,
              calculated_sale_amount,calculated_injection_amount,created_by,created_at,updated_at)
             VALUES (:restaurant_id,:report_id,:product_id,:category_id,:product_name_snapshot,:category_name_snapshot,:unit_snapshot,
              :sale_price_snapshot,:purchase_price_snapshot,:previous_stock,:purchased_quantity,:purchase_total,:explained_entries,
              :explained_outputs,:remaining_stock,:sold_quantity_declared,:credit_amount,:expense_amount,:incident_note,
              :omitted_remaining_confirmed,:calculated_available,:calculated_sold_quantity,:calculated_injection_quantity,
              :calculated_sale_amount,:calculated_injection_amount,:created_by,NOW(),NOW())
             ON DUPLICATE KEY UPDATE previous_stock=VALUES(previous_stock),purchased_quantity=VALUES(purchased_quantity),
              purchase_total=VALUES(purchase_total),explained_entries=VALUES(explained_entries),explained_outputs=VALUES(explained_outputs),
              remaining_stock=VALUES(remaining_stock),sold_quantity_declared=VALUES(sold_quantity_declared),
              credit_amount=VALUES(credit_amount),expense_amount=VALUES(expense_amount),incident_note=VALUES(incident_note),
              omitted_remaining_confirmed=VALUES(omitted_remaining_confirmed),calculated_available=VALUES(calculated_available),
              calculated_sold_quantity=VALUES(calculated_sold_quantity),calculated_injection_quantity=VALUES(calculated_injection_quantity),
              calculated_sale_amount=VALUES(calculated_sale_amount),calculated_injection_amount=VALUES(calculated_injection_amount),updated_at=NOW()'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId, 'report_id' => $reportId, 'product_id' => $productId,
            'category_id' => $product['category_id'], 'product_name_snapshot' => $product['name'],
            'category_name_snapshot' => $product['category_name'], 'unit_snapshot' => $product['unit'],
            'sale_price_snapshot' => $product['sale_price'], 'purchase_price_snapshot' => $product['usual_purchase_price'],
            'previous_stock' => (float) ($item['previous_stock'] ?? 0), 'purchased_quantity' => (float) ($item['purchased_quantity'] ?? 0),
            'purchase_total' => (float) ($item['purchase_total'] ?? 0), 'explained_entries' => (float) ($item['explained_entries'] ?? 0),
            'explained_outputs' => (float) ($item['explained_outputs'] ?? 0),
            'remaining_stock' => ($item['remaining_stock'] ?? '') === '' ? null : (float) $item['remaining_stock'],
            'sold_quantity_declared' => (float) ($item['sold_quantity_declared'] ?? 0),
            'credit_amount' => (float) ($item['credit_amount'] ?? 0), 'expense_amount' => (float) ($item['expense_amount'] ?? 0),
            'incident_note' => trim((string) ($item['incident_note'] ?? '')),
            'omitted_remaining_confirmed' => !empty($item['omitted_remaining_confirmed']) ? 1 : 0,
            'calculated_available' => $calculated['available'], 'calculated_sold_quantity' => $calculated['sold_quantity'],
            'calculated_injection_quantity' => $calculated['injection_quantity'], 'calculated_sale_amount' => $calculated['sale_amount'],
            'calculated_injection_amount' => $calculated['injection_amount'], 'created_by' => (int) $actor['id'],
        ]);
    }

    private function log(int $restaurantId, ?int $reportId, string $action, array $details, int $actorId): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO external_audit_logs (restaurant_id,report_id,action_code,details_json,created_by,created_at,updated_at)
             VALUES (:restaurant_id,:report_id,:action_code,:details_json,:created_by,NOW(),NOW())'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'report_id' => $reportId, 'action_code' => $action, 'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_by' => $actorId]);
    }

    private function ensureDefaultCategories(int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT IGNORE INTO external_audit_categories
             (restaurant_id,name,code,included_in_global,audit_mode,status,created_by,created_at,updated_at)
             VALUES
             (:restaurant_boissons,"Boissons","boissons",1,"stock","active",NULL,NOW(),NOW()),
             (:restaurant_cuisine,"Cuisine","cuisine",1,"cuisine","active",NULL,NOW(),NOW()),
             (:restaurant_annexes,"Annexes","annexes",1,"ventes","active",NULL,NOW(),NOW())'
        );
        $statement->execute([
            'restaurant_boissons' => $restaurantId,
            'restaurant_cuisine' => $restaurantId,
            'restaurant_annexes' => $restaurantId,
        ]);
    }

    private function ensureRoleExpectations(int $restaurantId): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT IGNORE INTO external_audit_role_expectations
             (restaurant_id,role_code,role_label,report_type,deadline_time,is_required,created_by,created_at,updated_at)
             VALUES
             (:r1,"cashier_server","Serveur","serveur","23:00:00",1,NULL,NOW(),NOW()),
             (:r2,"stock_manager","Responsable boissons / stock","boissons","22:30:00",1,NULL,NOW(),NOW()),
             (:r3,"kitchen","Responsable cuisine","cuisine","22:30:00",1,NULL,NOW(),NOW())'
        );
        $statement->execute(['r1' => $restaurantId, 'r2' => $restaurantId, 'r3' => $restaurantId]);
    }

    private function notifyManagers(int $restaurantId, array $actor, string $event, string $level, string $title, string $message, string $url, string $key): void
    {
        try {
            \App\Core\Container::getInstance()->get('uiNotifications')->queueForRoles(
                $restaurantId,
                ['owner', 'manager'],
                $actor,
                $event,
                $level,
                $title,
                $message,
                $url,
                $key
            );
        } catch (Throwable $exception) {
            error_log('[EXTERNAL_AUDIT_NOTIFICATION] ' . $exception->getMessage());
        }
    }

    private function notifyMissingReports(int $restaurantId, string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > today_for_restaurant()) {
            return;
        }
        try {
            $tracking = $this->reportTracking($restaurantId, $date, $date);
            foreach ($tracking['rows'] as $missing) {
                if ($missing['status'] !== 'MANQUANT') {
                    continue;
                }
                $actor = ['id' => (int) $missing['user_id'], 'restaurant_id' => $restaurantId];
                $this->notifyManagers(
                    $restaurantId,
                    $actor,
                    'external_audit.missing',
                    'warning',
                    'Rapport Audit externe manquant',
                    $missing['name'] . ' (' . $missing['function'] . ') n a pas remis le rapport ' . $missing['expected_report'] . ' du ' . $date . ' avant ' . $missing['deadline_time'] . '.',
                    '/audit-externe?date=' . $date,
                    'ea:missing:' . $restaurantId . ':' . $date . ':' . $missing['user_id'] . ':' . $missing['expected_report']
                );
            }
        } catch (Throwable $exception) {
            error_log('[EXTERNAL_AUDIT_MISSING_NOTIFICATION] ' . $exception->getMessage());
        }
    }

    private function periodRows(string $sql, int $restaurantId, string $from, string $to): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute(['restaurant_id' => $restaurantId, 'date_from' => $from, 'date_to' => $to]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function appendGroupedComparisons(array &$rows, string $prefix, array $auditRows, array $applicationRows): void
    {
        $audit = [];
        $application = [];
        foreach ($auditRows as $row) {
            $audit[mb_strtolower(trim((string) $row['label']))] = ['label' => (string) $row['label'], 'amount' => (float) $row['amount']];
        }
        foreach ($applicationRows as $row) {
            $application[mb_strtolower(trim((string) $row['label']))] = ['label' => (string) $row['label'], 'amount' => (float) $row['amount']];
        }
        foreach (array_unique(array_merge(array_keys($audit), array_keys($application))) as $key) {
            $auditAmount = (float) ($audit[$key]['amount'] ?? 0);
            $applicationAmount = (float) ($application[$key]['amount'] ?? 0);
            $gap = $auditAmount - $applicationAmount;
            $rows[] = [
                'element' => $prefix . ': ' . ($audit[$key]['label'] ?? $application[$key]['label'] ?? $key),
                'audit_amount' => $auditAmount,
                'application_amount' => $applicationAmount,
                'gap' => $gap,
                'observation' => abs($gap) < 0.001 ? 'Coherent' : 'Ecart a expliquer',
                'status' => abs($gap) < 0.001 ? 'COHERENT' : 'JUSTIFICATION_EN_ATTENTE',
            ];
        }
    }

    private function assertMayActOnReport(array $report, array $actor): void
    {
        if (
            (int) ($report['operational_author_id'] ?? 0) !== (int) ($actor['id'] ?? 0)
            && ($actor['scope'] ?? null) !== 'super_admin'
            && !in_array((string) ($actor['role_code'] ?? ''), ['owner', 'manager'], true)
        ) {
            throw new RuntimeException('Ce rapport appartient a un autre agent.');
        }
    }
}
