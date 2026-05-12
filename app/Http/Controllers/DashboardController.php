<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use PDO;

final class DashboardController
{
    public function home(Request $request): void
    {
        if (isset($_SESSION['user'])) {
            redirect_after_login($_SESSION['user']);
        }

        view('landing/home', [
            'title' => 'Badiboss Restaurant SaaS',
            'plans' => Container::getInstance()->get('restaurantAdmin')->listPlans(),
            'settings' => Container::getInstance()->get('platformSettings')->listSystemSettings(),
        ]);
    }

    public function health(Request $request): void
    {
        echo 'OK';
    }

    /**
     * Déploiement / support : commit Git exposé par la plateforme (Railway, etc.). Public, sans secret.
     */
    public function healthVersion(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $commit = (string) (
            getenv('RAILWAY_GIT_COMMIT_SHA')
            ?: getenv('RAILWAY_GIT_COMMIT')
            ?: getenv('RENDER_GIT_COMMIT')
            ?: getenv('VERCEL_GIT_COMMIT_SHA')
            ?: getenv('GIT_COMMIT')
            ?: ''
        );
        $branch = (string) (
            getenv('RAILWAY_GIT_BRANCH')
            ?: getenv('RENDER_GIT_BRANCH')
            ?: getenv('VERCEL_GIT_COMMIT_REF')
            ?: getenv('GIT_BRANCH')
            ?: ''
        );
        $appVersion = (string) (getenv('APP_VERSION') ?: '');
        $payload = [
            'commit' => $commit !== '' ? $commit : 'unknown',
            'commit_short' => $commit !== '' ? substr($commit, 0, 7) : 'unknown',
            'branch' => $branch,
            'app_version' => $appVersion,
            'time' => gmdate('c'),
        ];
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function superAdmin(Request $request): void
    {
        authorize_access('platform.admin.view');
        view('super-admin/dashboard', $this->superAdminDashboardPayload());

        audit_access('dashboard', null, 'screens', 'super-admin', 'Consultation tableau de bord super administrateur');
    }

    public function previewOperationalReset(Request $request): void
    {
        authorize_access('platform.admin.view');

        try {
            $preview = Container::getInstance()->get('operationalResetService')->preview([
                'restaurant_id' => $request->input('restaurant_id'),
                'scope' => $request->input('scope', 'restaurant'),
                'user_id' => $request->input('user_id', 0),
                'period_type' => $request->input('period_type', 'day'),
                'day_value' => $request->input('day_value', ''),
                'week_value' => $request->input('week_value', ''),
                'month_value' => $request->input('month_value', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
                'data_types' => $request->input('data_types', []),
            ]);

            view('super-admin/dashboard', $this->superAdminDashboardPayload($preview, null));
            return;
        } catch (\RuntimeException $exception) {
            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, ui_safe_message($exception->getMessage())));
        }
    }

    public function previewStockReset(Request $request): void
    {
        authorize_access('platform.admin.view');

        try {
            $preview = Container::getInstance()->get('stockResetService')->preview([
                'restaurant_id' => $request->input('restaurant_id'),
                'stock_period_preset' => $request->input('stock_period_preset', 'today'),
                'stock_week_value' => $request->input('stock_week_value', ''),
                'stock_month_value' => $request->input('stock_month_value', ''),
                'stock_date_from' => $request->input('stock_date_from', ''),
                'stock_date_to' => $request->input('stock_date_to', ''),
                'stock_options' => $request->input('stock_options', []),
            ]);

            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, null, $preview, null));
            return;
        } catch (\RuntimeException $exception) {
            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, ui_safe_message($exception->getMessage())));
        }
    }

    public function executeOperationalReset(Request $request): void
    {
        authorize_access('platform.admin.view');

        try {
            $result = Container::getInstance()->get('operationalResetService')->execute([
                'restaurant_id' => $request->input('restaurant_id'),
                'scope' => $request->input('scope', 'restaurant'),
                'user_id' => $request->input('user_id', 0),
                'period_type' => $request->input('period_type', 'day'),
                'day_value' => $request->input('day_value', ''),
                'week_value' => $request->input('week_value', ''),
                'month_value' => $request->input('month_value', ''),
                'date_from' => $request->input('date_from', ''),
                'date_to' => $request->input('date_to', ''),
                'data_types' => $request->input('data_types', []),
                'confirmation_text' => $request->input('confirmation_text', ''),
                'reset_reason' => $request->input('reset_reason', ''),
            ], current_user() ?? []);

            view('super-admin/dashboard', $this->superAdminDashboardPayload($result['preview'], $result));
            return;
        } catch (\RuntimeException $exception) {
            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, ui_safe_message($exception->getMessage())));
        }
    }

    public function executeStockReset(Request $request): void
    {
        authorize_access('platform.admin.view');

        try {
            $result = Container::getInstance()->get('stockResetService')->execute([
                'restaurant_id' => $request->input('restaurant_id'),
                'stock_period_preset' => $request->input('stock_period_preset', 'today'),
                'stock_week_value' => $request->input('stock_week_value', ''),
                'stock_month_value' => $request->input('stock_month_value', ''),
                'stock_date_from' => $request->input('stock_date_from', ''),
                'stock_date_to' => $request->input('stock_date_to', ''),
                'stock_options' => $request->input('stock_options', []),
                'confirmation_text' => $request->input('confirmation_text', ''),
                'reset_reason' => $request->input('reset_reason', ''),
            ], current_user() ?? []);

            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, null, $result['preview'], $result));
            return;
        } catch (\RuntimeException $exception) {
            view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, ui_safe_message($exception->getMessage())));
        }
    }

    public function superAdminOperationLookup(Request $request): void
    {
        authorize_access('platform.admin.view');

        $restaurantId = (int) $request->input('restaurant_id', 0);
        $kind = trim((string) $request->input('kind', ''));
        $entityId = (int) $request->input('entity_id', 0);
        $lookup = null;
        if ($restaurantId > 0 && $entityId > 0 && $kind !== '') {
            $lookup = Container::getInstance()->get('superAdminOperationsService')->lookup($restaurantId, $kind, $entityId);
            if ($lookup === null) {
                flash('error', 'Entite introuvable ou type invalide.');
            } else {
                flash('success', 'Fiche operation chargee (super admin).');
            }
        }

        view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, null, null, null, $lookup));
        audit_access('dashboard', null, 'screens', 'super-admin-ops-lookup', 'Recherche operation super admin');
    }

    public function superAdminOperationForce(Request $request): void
    {
        authorize_access('platform.admin.view');

        try {
            Container::getInstance()->get('superAdminOperationsService')->forceSetStatus(
                (int) $request->input('restaurant_id', 0),
                trim((string) $request->input('kind', '')),
                (int) $request->input('entity_id', 0),
                trim((string) $request->input('target_status', '')),
                (string) $request->input('reason', ''),
                (bool) $request->input('confirm_ack'),
                current_user() ?? []
            );
            flash('success', 'Changement de statut super admin enregistre (voir journal audit).');
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
        }

        view('super-admin/dashboard', $this->superAdminDashboardPayload(null, null, null, null, null, null));
        audit_access('dashboard', null, 'screens', 'super-admin-ops-force', 'Force statut operation super admin');
    }

    public function owner(Request $request): void
    {
        authorize_access('tenant.dashboard.view');
        $restaurantId = current_restaurant_id();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $subscription = Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurantId);
        $settings = Container::getInstance()->get('platformSettings')->listSystemSettings();
        $incidentService = Container::getInstance()->get('incidentService');

        $canAccessReports = can_access('reports.view');

        $cashSvc = Container::getInstance()->get('cashService');
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();

        $dash = $this->ownerOperationalDashboardBundle($request, $restaurantId);
        $wideTasks = Container::getInstance()->get('regularizationGate')->listRestaurantWideTasks($restaurantId, 30);
        $hold = Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, current_user() ?? []);
        $disciplineSchedule = $staffDisc->disciplineWorkScheduleForRestaurant($restaurantId);
        $disciplinaryAlerts = can_access('staff.team_gauges.view')
            ? $staffDisc->listDisciplinaryAlerts($restaurantId)
            : [];

        view('owner/dashboard', array_merge($dash, [
            'title' => 'Tableau de bord restaurant',
            'user' => $_SESSION['user'],
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'pending_manager_sale_remittances' => $cashSvc->listPendingManagerSaleRemittances($restaurantId),
            'pending_late_remittance_attributions' => $cashSvc->listPendingLateRemittanceAttributions($restaurantId),
            'sale_remittance_history' => $cashSvc->listSaleRemittanceHistory($restaurantId, 45),
            'cash_today_snapshot' => $canAccessReports
                ? Container::getInstance()->get('reportService')->cashTodayOperationalSnapshot($restaurantId)
                : null,
            'correction_requests_pending' => Container::getInstance()->get('correctionService')->listPendingForRestaurant($restaurantId),
            'correction_requests_recent' => Container::getInstance()->get('correctionService')->listRecentForRestaurant($restaurantId, 12),
            'manager_queue_cases' => $incidentService->listManagerDecisionQueue($restaurantId),
            'case_decision_history' => $incidentService->listRecentDecisions($restaurantId, 8),
            'sales_period_totals' => Container::getInstance()->get('salesService')->salesTotalsByServerForPeriods($restaurantId),
            'final_qualifications' => $settings['global_final_qualifications_json'] ?? [],
            'can_access_stock' => can_access('stock.view'),
            'can_access_kitchen' => can_access('kitchen.view'),
            'can_access_sales' => can_access('sales.view'),
            'can_access_cash' => can_access('cash.view'),
            'can_access_reports' => $canAccessReports,
            'report_detail_summary' => $canAccessReports
                ? Container::getInstance()->get('reportService')->reportDetailSummaryForDashboard($restaurantId)
                : null,
            'regularization_backlog' => Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId),
            'restaurant_reg_tasks' => $wideTasks,
            'day_start_hold' => $hold,
            'discipline_work_schedule' => $disciplineSchedule,
            'disciplinary_alerts' => $disciplinaryAlerts,
            'staff_gauges_overview' => can_access('staff.team_gauges.view')
                ? $staffDisc->gaugesSnapshotRestaurantOperational(
                    $restaurantId,
                    (string) ($dash['dash_preset'] ?? 'today'),
                    (string) ($dash['dash_date'] ?? $todayY),
                )
                : [],
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]));

        audit_access('dashboard', $restaurantId, 'screens', 'owner-dashboard', 'Consultation tableau de bord restaurant');
    }

    public function postDisciplinaryAlertAction(Request $request): void
    {
        authorize_access('tenant.dashboard.view');
        if (!can_access('staff.team_gauges.view')) {
            flash('error', 'Action reservee au pilotage discipline (gerant / proprietaire).');
            redirect('/owner');
        }
        $restaurantId = current_restaurant_id();
        try {
            Container::getInstance()->get('staffDiscipline')->recordDisciplinaryAlertFollowUp(
                $restaurantId,
                (int) $request->input('target_user_id', 0),
                (string) $request->input('action', ''),
                (string) $request->input('note', ''),
                is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [],
            );
            flash('success', 'Suivi alerte enregistre (journal discipline + audit).');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }
        redirect('/owner');
    }

    public function preparePayroll(Request $request): void
    {
        authorize_access('payroll.prepare.view');
        $restaurantId = current_restaurant_id();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $monthIn = trim((string) ($request->query['month'] ?? ''));
        if ($monthIn === '') {
            $monthIn = substr($todayY, 0, 7);
        }
        $preview = $staffDisc->payrollMonthPreview($restaurantId, $monthIn);

        view('owner/prepare-payroll', [
            'title' => 'Préparer la paie',
            'user' => $_SESSION['user'],
            'restaurant' => $restaurant,
            'payroll_preview' => $preview,
            'month_query' => $preview['month'],
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('payroll', $restaurantId, 'screens', 'prepare-payroll', 'Consultation préparation paie');
    }

    /**
     * @return array{dash_preset: string, dash_date: string, today_ymd_restaurant: string, module_today_pulse: array<string, mixed>}
     */
    private function ownerOperationalDashboardBundle(Request $request, int $restaurantId): array
    {
        $rs = Container::getInstance()->get('reportService');
        $todayY = $rs->todayForRestaurant($restaurantId);
        $preset = strtolower(trim((string) ($request->query['dash_preset'] ?? 'today')));
        $allowed = ['today', 'yesterday', 'date', 'week', 'month', 'prev_month'];
        if (!in_array($preset, $allowed, true)) {
            $preset = 'today';
        }
        $dRaw = trim((string) ($request->query['dash_date'] ?? ''));
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dRaw) ? $dRaw : $todayY;
        $pulse = $rs->moduleOperationalPulse($restaurantId, $preset, $d);

        return [
            'dash_preset' => $preset,
            'dash_date' => $d,
            'today_ymd_restaurant' => $todayY,
            'module_today_pulse' => $pulse,
        ];
    }

    private function superAdminDashboardPayload(
        ?array $resetPreview = null,
        ?array $resetReport = null,
        ?string $inlineError = null,
        ?array $stockResetPreview = null,
        ?array $stockResetReport = null,
        ?array $superAdminOpsLookup = null,
    ): array {
        $pdo = Container::getInstance()->get('db')->pdo();

        $stats = [
            'restaurants_total' => (int) $pdo->query('SELECT COUNT(*) FROM restaurants')->fetchColumn(),
            'restaurants_active' => (int) $pdo->query("SELECT COUNT(*) FROM restaurants WHERE status = 'active'")->fetchColumn(),
            'users_total' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'audit_entries' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
        ];

        $restaurants = $pdo->query(
            'SELECT r.id, r.name, r.slug, r.status, r.access_url, rb.public_name, rb.primary_color, rb.web_subdomain
             FROM restaurants r
             LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
             ORDER BY r.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $plans = $pdo->query(
            'SELECT id, name, code FROM subscription_plans WHERE status = "active" ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $restaurantUsers = $pdo->query(
            'SELECT id, restaurant_id, full_name, email
             FROM users
             WHERE restaurant_id IS NOT NULL
             ORDER BY full_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $historyStmt = $pdo->prepare(
            'SELECT al.id, al.restaurant_id, al.user_id, al.actor_name, al.created_at, al.justification, al.new_values_json,
                    r.name AS restaurant_name
             FROM audit_logs al
             LEFT JOIN restaurants r ON r.id = al.restaurant_id
             WHERE al.action_name = :action
             ORDER BY al.created_at DESC
             LIMIT 50'
        );
        $historyStmt->execute(['action' => 'super_admin_stock_reset']);
        $stockResetHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'title' => 'Super administration',
            'stats' => $stats,
            'restaurants' => $restaurants,
            'plans' => $plans,
            'restaurant_users' => $restaurantUsers,
            'reset_preview' => $resetPreview,
            'reset_report' => $resetReport,
            'stock_reset_preview' => $stockResetPreview,
            'stock_reset_report' => $stockResetReport,
            'stock_reset_history' => $stockResetHistory,
            'super_admin_ops_lookup' => $superAdminOpsLookup,
            'super_admin_ops_statuses' => Container::getInstance()->get('superAdminOperationsService')->allowedStatusesByKind(),
            'user' => $_SESSION['user'],
            'flash_success' => flash('success'),
            'flash_error' => $inlineError ?? flash('error'),
        ];
    }
}
