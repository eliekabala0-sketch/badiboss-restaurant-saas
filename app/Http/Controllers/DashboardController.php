<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Support\SandboxMidnightSalesE2eRunner;
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

    public function setupSalesSandbox(Request $request): void
    {
        authorize_access('platform.admin.view');
        $actor = current_user() ?? [];
        $code = strtolower(trim((string) $request->input('restaurant_code', 'test-ventes-minuit')));
        $code = Container::getInstance()->get('tenantProvisioning')->previewRestaurantCode($code);
        if (!is_sandbox_restaurant_code($code)) {
            throw new \RuntimeException('Code sandbox hors allowlist.');
        }

        $restaurantId = $this->ensureSandboxRestaurant($code, $actor);
        $this->ensureSandboxSubscriptionActive($restaurantId, $actor);
        $createdUsers = $this->ensureSandboxAccounts($restaurantId, $code, $actor);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => $actor['full_name'] ?? 'system',
            'actor_role_code' => $actor['role_code'] ?? 'system',
            'module_name' => 'tenant_management',
            'action_name' => 'sandbox_sales_setup',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $restaurantId,
            'new_values' => [
                'restaurant_code' => $code,
                'allowed_sandbox_codes' => sandbox_allowed_restaurant_codes(),
                'accounts_created_or_verified' => $createdUsers,
            ],
            'justification' => 'Setup sandbox ventes/minuit',
        ]);

        flash('success', 'Sandbox ventes/minuit prêt (restaurant + comptes test).');
        redirect('/super-admin');
    }

    public function runSalesSandboxMidnight(Request $request): void
    {
        authorize_access('platform.admin.view');
        $restaurantId = (int) $request->input('restaurant_id', 0);
        if ($restaurantId <= 0) {
            throw new \RuntimeException('restaurant_id requis.');
        }
        $readOnly = !in_array(strtolower((string) $request->input('read_only_diagnostic', '1')), ['0', 'false', 'no'], true);
        $result = Container::getInstance()->get('salesService')->runSandboxMidnightReconcile(
            $restaurantId,
            current_user() ?? [],
            $readOnly
        );

        flash(
            'success',
            ($result['read_only_diagnostic'] ? '[DRY-RUN] ' : '')
            . 'Candidats=' . (string) ($result['candidate_count'] ?? 0)
            . ' | ventes_liees_avant=' . (string) ($result['sales_linked_before'] ?? 0)
            . ' | ventes_liees_apres=' . (string) ($result['sales_linked_after'] ?? 0)
            . ' | créées=' . (string) ($result['created_count'] ?? 0)
        );
        redirect('/super-admin');
    }

    public function backdateSandboxRemisYesterday(Request $request): void
    {
        authorize_access('platform.admin.view');
        $restaurantId = (int) $request->input('restaurant_id', 0);
        $serverRequestId = (int) $request->input('server_request_id', 0);
        if ($restaurantId <= 0 || $serverRequestId <= 0) {
            throw new \RuntimeException('restaurant_id et server_request_id requis.');
        }

        Container::getInstance()->get('salesService')->backdateSandboxRemittedRequestActivityYesterday(
            $restaurantId,
            $serverRequestId,
            current_user() ?? []
        );
        flash('success', 'Sandbox : horodatage REMIS_SERVEUR reculé à hier (tests runner minuit).');
        redirect('/super-admin');
    }

    /**
     * Temporaire : exécute l’E2E sandbox minuit (test-ventes-minuit uniquement), rapport texte.
     */
    public function runSandboxMidnightE2e(Request $request): void
    {
        authorize_access('platform.admin.view');
        $actor = current_user() ?? [];
        $result = SandboxMidnightSalesE2eRunner::execute('HTTP super-admin');

        $rid = (int) ($result['metrics']['restaurant_id'] ?? 0);
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $rid > 0 ? $rid : null,
            'user_id' => $actor['id'] ?? null,
            'actor_name' => (string) ($actor['full_name'] ?? 'super_admin'),
            'actor_role_code' => (string) ($actor['role_code'] ?? 'system'),
            'module_name' => 'sandbox',
            'action_name' => 'sandbox_midnight_e2e_run',
            'entity_type' => 'restaurants',
            'entity_id' => $rid > 0 ? (string) $rid : SandboxMidnightSalesE2eRunner::CODE,
            'new_values' => [
                'exit_code' => $result['exit_code'],
                'checks' => $result['checks'],
                'commit_short' => $result['metrics']['commit_short'] ?? '',
                'error' => $result['metrics']['error'] ?? null,
            ],
            'justification' => 'E2E sandbox minuit (endpoint super-admin, restaurant allowlist uniquement)',
        ]);

        $code = $result['exit_code'] === 0 ? 200 : ($result['exit_code'] === 2 ? 422 : 500);
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $result['report_lines']) . "\n";
    }

    public function owner(Request $request): void
    {
        $actor = current_user() ?? [];
        if (($actor['scope'] ?? null) !== 'super_admin') {
            authorize_access('tenant.dashboard.view');
        }
        $restaurantId = current_restaurant_id();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $subscription = Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurantId);
        $settings = Container::getInstance()->get('platformSettings')->listSystemSettings();
        $incidentService = Container::getInstance()->get('incidentService');
        $loadOwnerDetails = (string) ($request->query['details'] ?? '') === '1';
        $loadOwnerInsights = (string) ($request->query['insights'] ?? '') === '1';
        $insightsQuery = $request->query;
        $insightsQuery['insights'] = '1';

        $canAccessReports = can_access('reports.view');

        $cashSvc = Container::getInstance()->get('cashService');
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $loadDisciplineDashboard = (string) ($request->query['discipline_dashboard'] ?? '') === '1';
        $ownerInsightsWarning = null;
        try {
            $dash = $this->ownerOperationalDashboardBundle($request, $restaurantId, $loadOwnerInsights);
            $wideTasks = $loadOwnerInsights
                ? Container::getInstance()->get('regularizationGate')->listRestaurantWideTasks($restaurantId, 30)
                : [];
            $hold = $loadOwnerInsights
                ? Container::getInstance()->get('regularizationGate')->assessForUser($restaurantId, $actor)
                : [
                    'blocked' => false,
                    'reasons' => [],
                    'codes' => [],
                    'backlog' => [],
                    'items' => [],
                    'items_today_soft' => [],
                    'super_admin_unblocked' => ($actor['scope'] ?? null) === 'super_admin',
                ];
        } catch (\Throwable $e) {
            if (!$loadOwnerInsights) {
                throw $e;
            }
            error_log('[OWNER_INSIGHTS_DEGRADED] rid=' . $restaurantId . ' actor=' . (int) ($actor['id'] ?? 0) . ' ' . $e->getMessage());
            $ownerInsightsWarning = 'Analyse avancee temporairement indisponible. Utilisez les rapports detailles.';
            $loadOwnerInsights = false;
            $dash = $this->ownerOperationalDashboardBundle($request, $restaurantId, false);
            $wideTasks = [];
            $hold = [
                'blocked' => false,
                'reasons' => [],
                'codes' => [],
                'backlog' => [],
                'items' => [],
                'items_today_soft' => [],
                'super_admin_unblocked' => ($actor['scope'] ?? null) === 'super_admin',
            ];
        }
        $disciplineSchedule = $staffDisc->disciplineWorkScheduleForRestaurant($restaurantId);
        $disciplinaryAlerts = $loadDisciplineDashboard && can_access('staff.team_gauges.view')
            ? $staffDisc->listDisciplinaryAlerts($restaurantId)
            : [];

        view('owner/dashboard', array_merge($dash, [
            'title' => 'Tableau de bord restaurant',
            'user' => $actor,
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'pending_manager_sale_remittances' => $loadOwnerDetails ? $cashSvc->listPendingManagerSaleRemittances($restaurantId) : [],
            'pending_late_remittance_attributions' => $loadOwnerDetails ? $cashSvc->listPendingLateRemittanceAttributions($restaurantId) : [],
            'sale_remittance_history' => $loadOwnerDetails ? $cashSvc->listSaleRemittanceHistory($restaurantId, 24) : [],
            'cash_today_snapshot' => $loadOwnerInsights && $canAccessReports
                ? Container::getInstance()->get('reportService')->cashTodayOperationalSnapshot($restaurantId)
                : null,
            'correction_requests_pending' => $loadOwnerDetails ? Container::getInstance()->get('correctionService')->listPendingForRestaurant($restaurantId) : [],
            'correction_requests_recent' => $loadOwnerDetails ? Container::getInstance()->get('correctionService')->listRecentForRestaurant($restaurantId, 12) : [],
            'manager_queue_cases' => $loadOwnerDetails ? $incidentService->listManagerDecisionQueue($restaurantId) : [],
            'case_decision_history' => $loadOwnerDetails ? $incidentService->listRecentDecisions($restaurantId, 8) : [],
            'sales_period_totals' => $loadOwnerDetails ? Container::getInstance()->get('salesService')->salesTotalsByServerForPeriods($restaurantId) : [],
            'final_qualifications' => $settings['global_final_qualifications_json'] ?? [],
            'can_access_stock' => can_access('stock.view'),
            'can_access_kitchen' => can_access('kitchen.view'),
            'can_access_sales' => can_access('sales.view'),
            'can_access_cash' => can_access('cash.view'),
            'can_access_reports' => $canAccessReports,
            'report_detail_summary' => $loadOwnerInsights && $canAccessReports
                ? ($loadOwnerDetails ? Container::getInstance()->get('reportService')->reportDetailSummaryForDashboard($restaurantId) : null)
                : null,
            'regularization_backlog' => $loadOwnerInsights
                ? Container::getInstance()->get('salesService')->regularizationBacklogCounts($restaurantId)
                : [],
            'restaurant_reg_tasks' => $wideTasks,
            'day_start_hold' => $hold,
            'discipline_work_schedule' => $disciplineSchedule,
            'discipline_dashboard_loaded' => $loadDisciplineDashboard,
            'disciplinary_alerts' => $disciplinaryAlerts,
            'staff_gauges_overview' => $loadDisciplineDashboard && can_access('staff.team_gauges.view')
                ? $staffDisc->gaugesSnapshotRestaurantOperational(
                    $restaurantId,
                    (string) ($dash['dash_preset'] ?? 'today'),
                    (string) ($dash['dash_date'] ?? $todayY),
                )
                : [],
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
            'owner_details_loaded' => $loadOwnerDetails,
            'owner_insights_loaded' => $loadOwnerInsights,
            'owner_insights_query' => http_build_query($insightsQuery),
            'owner_insights_warning' => $ownerInsightsWarning,
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
        $actor = current_user() ?? [];
        if (($actor['scope'] ?? null) !== 'super_admin') {
            authorize_access('payroll.prepare.view');
        }
        $restaurantId = current_restaurant_id();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $disciplinePreset = strtolower(trim((string) ($request->query['preset'] ?? 'today')));
        $disciplineAllowed = ['today', 'yesterday', 'date', 'week', 'month', 'prev_month'];
        if (!in_array($disciplinePreset, $disciplineAllowed, true)) {
            $disciplinePreset = 'today';
        }
        $disciplineDateRaw = trim((string) ($request->query['date'] ?? ''));
        $disciplineDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $disciplineDateRaw) ? $disciplineDateRaw : $todayY;
        $disciplineWindow = Container::getInstance()->get('reportService')->operationalPeriodWindow($restaurantId, $disciplinePreset, $disciplineDate);
        $monthIn = trim((string) ($request->query['month'] ?? ''));
        if ($monthIn === '') {
            $monthIn = substr($todayY, 0, 7);
        }
        $loadHeavy = (string) ($request->query['heavy'] ?? '') === '1';
        $allStaffUsers = array_values(array_filter(
            Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId),
            static fn (array $u): bool => ($u['status'] ?? '') === 'active' && (string) ($u['role_code'] ?? '') !== 'owner',
        ));
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = 12;
        $totalStaff = count($allStaffUsers);
        $totalPages = max(1, (int) ceil(max(1, $totalStaff) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $pagedUsers = array_slice($allStaffUsers, ($page - 1) * $perPage, $perPage);
        $pagedUserIds = array_values(array_map(static fn (array $u): int => (int) ($u['id'] ?? 0), $pagedUsers));
        $disciplineRows = $staffDisc->gaugesSnapshotRestaurantDailyLight($restaurantId, $disciplineDate, $pagedUserIds);
        try {
            $preview = $staffDisc->payrollMonthPreview($restaurantId, $monthIn, $loadHeavy, $pagedUserIds);
            $payrollPreviewWarning = null;
        } catch (\Throwable $e) {
            error_log('[PAYROLL_PREVIEW_FALLBACK] rid=' . $restaurantId . ' actor=' . (int) ($actor['id'] ?? 0) . ' ' . $e->getMessage());
            $preview = $staffDisc->payrollMonthPreviewLight($restaurantId, $monthIn, $disciplineRows, $pagedUserIds);
            $payrollPreviewWarning = 'Preparation detaillee temporairement indisponible. Une vue rapide est affichee pour eviter un blocage.';
            $loadHeavy = false;
        }

        view('owner/prepare-payroll', [
            'title' => 'Préparer la paie',
            'user' => $actor,
            'restaurant' => $restaurant,
            'payroll_preview' => $preview,
            'payroll_discipline_rows' => $disciplineRows,
            'payroll_discipline_preset' => $disciplinePreset,
            'payroll_discipline_date' => $disciplineDate,
            'payroll_discipline_period_label' => (string) ($disciplineWindow['label'] ?? ''),
            'month_query' => $preview['month'],
            'payroll_heavy_loaded' => $loadHeavy,
            'payroll_preview_warning' => $payrollPreviewWarning,
            'staff_page' => $page,
            'staff_per_page' => $perPage,
            'staff_total_count' => $totalStaff,
            'staff_total_pages' => $totalPages,
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
        ]);

        audit_access('payroll', $restaurantId, 'screens', 'prepare-payroll', 'Consultation préparation paie');
    }

    public function disciplineHub(Request $request): void
    {
        $actor = current_user() ?? [];
        if (($actor['scope'] ?? null) !== 'super_admin') {
            authorize_access('staff.team_gauges.view');
        }
        $restaurantId = current_restaurant_id();
        $flashSuccess = flash('success');
        $flashError = flash('error');
        session_release_read_lock();
        $staffDisc = Container::getInstance()->get('staffDiscipline');
        $staffDisc->ensureSchema();
        $todayY = Container::getInstance()->get('reportService')->todayForRestaurant($restaurantId);
        $preset = strtolower(trim((string) ($request->query['preset'] ?? 'today')));
        $allowed = ['today', 'yesterday', 'date', 'week', 'month', 'prev_month'];
        if (!in_array($preset, $allowed, true)) {
            $preset = 'today';
        }
        $anchorRaw = trim((string) ($request->query['date'] ?? ''));
        $anchorYmd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorRaw) ? $anchorRaw : $todayY;
        $periodWindow = Container::getInstance()->get('reportService')->operationalPeriodWindow($restaurantId, $preset, $anchorYmd);
        $loadAlerts = (string) ($request->query['alerts'] ?? '') === '1';
        $users = Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId);
        $attUsers = array_values(array_filter(
            $users,
            static fn (array $u): bool => ($u['status'] ?? '') === 'active' && (string) ($u['role_code'] ?? '') !== 'owner',
        ));
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = 12;
        $totalStaff = count($attUsers);
        $totalPages = max(1, (int) ceil(max(1, $totalStaff) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $pagedUsers = array_slice($attUsers, ($page - 1) * $perPage, $perPage);
        $pagedUserIds = array_values(array_map(static fn (array $u): int => (int) ($u['id'] ?? 0), $pagedUsers));
        $pdo = Container::getInstance()->get('db')->pdo();
        $payrollProfiles = [];
        $ps = $pdo->prepare(
            'SELECT user_id, base_salary_monthly, bonus_monthly, currency, service_start_ymd, profile_note
             FROM staff_payroll_profiles WHERE restaurant_id = :rid'
        );
        $ps->execute(['rid' => $restaurantId]);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $uid = (int) ($pr['user_id'] ?? 0);
            if ($pagedUserIds !== [] && !in_array($uid, $pagedUserIds, true)) {
                continue;
            }
            $payrollProfiles[$uid] = $pr;
        }

        view('owner/discipline-hub', [
            'title' => 'Discipline & présences',
            'user' => $actor,
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
            'today_ymd' => $todayY,
            'discipline_preset' => $preset,
            'discipline_anchor_date' => $anchorYmd,
            'discipline_period_label' => (string) ($periodWindow['label'] ?? ''),
            'discipline_schedule' => $staffDisc->disciplineWorkScheduleForRestaurant($restaurantId),
            'alerts' => $loadAlerts ? $staffDisc->listDisciplinaryAlerts($restaurantId) : [],
            'gauge_rows' => $staffDisc->gaugesSnapshotRestaurantDailyLight($restaurantId, $anchorYmd, $pagedUserIds),
            'discipline_heavy_loaded' => false,
            'discipline_alerts_loaded' => $loadAlerts,
            'staff_users' => $attUsers,
            'payroll_profiles' => $payrollProfiles,
            'staff_page' => $page,
            'staff_per_page' => $perPage,
            'staff_total_count' => $totalStaff,
            'staff_total_pages' => $totalPages,
            'flash_success' => $flashSuccess,
            'flash_error' => $flashError,
        ]);

        audit_access('discipline', $restaurantId, 'screens', 'discipline-hub', 'Consultation hub discipline');
    }

    public function postDisciplineAttendance(Request $request): void
    {
        authorize_access('staff.team_gauges.view');
        $restaurantId = current_restaurant_id();
        try {
            Container::getInstance()->get('staffDiscipline')->upsertStaffAttendanceDay(
                $restaurantId,
                (int) $request->input('target_user_id', 0),
                trim((string) $request->input('day_ymd', '')),
                (string) $request->input('planned_status', ''),
                $request->input('manager_note'),
                current_user() ?? [],
            );
            flash('success', 'Présence / statut enregistré.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }
        redirect('/owner/discipline');
    }

    public function postDisciplinePayrollProfile(Request $request): void
    {
        authorize_access('staff.team_gauges.view');
        $restaurantId = current_restaurant_id();
        try {
            Container::getInstance()->get('staffDiscipline')->upsertStaffPayrollProfile(
                $restaurantId,
                (int) $request->input('target_user_id', 0),
                [
                    'base_salary_monthly' => $request->input('base_salary_monthly', 0),
                    'bonus_monthly' => $request->input('bonus_monthly', 0),
                    'currency' => $request->input('currency', 'USD'),
                    'service_start_ymd' => $request->input('service_start_ymd', ''),
                    'profile_note' => $request->input('profile_note', ''),
                ],
                current_user() ?? [],
            );
            flash('success', 'Profil paie / date de service enregistré.');
        } catch (\Throwable $e) {
            flash('error', ui_safe_message($e->getMessage()));
        }
        redirect('/owner/discipline');
    }

    /**
     * @return array{dash_preset: string, dash_date: string, today_ymd_restaurant: string, module_today_pulse: array<string, mixed>}
     */
    private function ownerOperationalDashboardBundle(Request $request, int $restaurantId, bool $loadPulse = true): array
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
        $pulse = $loadPulse ? $rs->moduleOperationalPulse($restaurantId, $preset, $d) : [];

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

    private function ensureSandboxRestaurant(string $code, array $actor): int
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $statement = $pdo->prepare('SELECT id FROM restaurants WHERE restaurant_code = :code LIMIT 1');
        $statement->execute(['code' => $code]);
        $existingId = (int) ($statement->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        return Container::getInstance()->get('tenantProvisioning')->createRestaurant([
            'name' => strtoupper($code),
            'restaurant_code' => $code,
            'slug' => $code,
            'support_email' => 'sandbox+' . $code . '@badiboss.test',
            'phone' => '+243000000000',
            'city' => 'Sandbox',
            'country' => 'CD',
            'address_line' => 'Sandbox only',
            'public_name' => strtoupper($code),
            'subscription_plan_id' => 1,
            'status' => 'active',
            'subscription_status' => 'ACTIVE',
            'subscription_payment_status' => 'PAID',
            'timezone' => 'Africa/Kinshasa',
            'currency_code' => 'USD',
        ], $actor);
    }

    /**
     * @return list<string>
     */
    private function ensureSandboxAccounts(int $restaurantId, string $code, array $actor): array
    {
        $templates = [
            ['full_name' => 'Owner Sandbox', 'role_code' => 'owner', 'email' => 'owner-' . $code . '@badiboss.test'],
            ['full_name' => 'Manager Sandbox', 'role_code' => 'manager', 'email' => 'manager-' . $code . '@badiboss.test'],
            ['full_name' => 'Server Sandbox', 'role_code' => 'cashier_server', 'email' => 'server-' . $code . '@badiboss.test'],
            ['full_name' => 'Kitchen Sandbox', 'role_code' => 'kitchen', 'email' => 'kitchen-' . $code . '@badiboss.test'],
            ['full_name' => 'Stock Sandbox', 'role_code' => 'stock_manager', 'email' => 'stock-' . $code . '@badiboss.test'],
            ['full_name' => 'Caisse Sandbox', 'role_code' => 'cashier_accountant', 'email' => 'caisse-' . $code . '@badiboss.test'],
        ];
        $pdo = Container::getInstance()->get('db')->pdo();
        $created = [];
        foreach ($templates as $tpl) {
            $exists = $pdo->prepare('SELECT id FROM users WHERE restaurant_id = :rid AND email = :email LIMIT 1');
            $exists->execute(['rid' => $restaurantId, 'email' => $tpl['email']]);
            if ((int) ($exists->fetchColumn() ?: 0) > 0) {
                $created[] = $tpl['email'] . ':exists';
                continue;
            }
            $roleStatement = $pdo->prepare(
                'SELECT id FROM roles
                 WHERE code = :code
                   AND status = "active"
                   AND (scope = "system" OR (scope = "tenant" AND restaurant_id = :rid))
                 ORDER BY scope ASC
                 LIMIT 1'
            );
            $roleStatement->execute(['code' => $tpl['role_code'], 'rid' => $restaurantId]);
            $roleId = (int) ($roleStatement->fetchColumn() ?: 0);
            if ($roleId <= 0) {
                throw new \RuntimeException('Role introuvable pour sandbox: ' . $tpl['role_code']);
            }

            Container::getInstance()->get('userAdmin')->createUser([
                'restaurant_id' => $restaurantId,
                'role_id' => $roleId,
                'full_name' => $tpl['full_name'],
                'email' => $tpl['email'],
                'phone' => '+243000000000',
                'password' => 'password',
                'status' => 'active',
            ], $actor);
            $created[] = $tpl['email'] . ':created';
        }

        return $created;
    }

    private function ensureSandboxSubscriptionActive(int $restaurantId, array $actor): void
    {
        $subscription = Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurantId);
        if (is_array($subscription) && (bool) ($subscription['is_operational'] ?? false)) {
            return;
        }

        Container::getInstance()->get('subscriptionService')->activateRestaurant($restaurantId, [
            'subscription_started_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'subscription_duration_days' => 3650,
            'payment_status' => 'WAIVED',
            'justification' => 'Activation abonnement sandbox (tests ventes/minuit)',
        ], $actor);
    }
}
