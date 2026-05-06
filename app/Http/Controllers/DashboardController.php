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

    public function owner(Request $request): void
    {
        authorize_access('tenant.dashboard.view');
        $restaurantId = current_restaurant_id();
        Container::getInstance()->get('salesService')->reconcileOverdueReturnsToAutomaticSales($restaurantId);
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $subscription = Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurantId);
        $settings = Container::getInstance()->get('platformSettings')->listSystemSettings();
        $incidentService = Container::getInstance()->get('incidentService');

        $canAccessReports = can_access('reports.view');

        view('owner/dashboard', [
            'title' => 'Tableau de bord restaurant',
            'user' => $_SESSION['user'],
            'restaurant' => $restaurant,
            'subscription' => $subscription,
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
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('dashboard', $restaurantId, 'screens', 'owner-dashboard', 'Consultation tableau de bord restaurant');
    }

    private function superAdminDashboardPayload(
        ?array $resetPreview = null,
        ?array $resetReport = null,
        ?string $inlineError = null,
        ?array $stockResetPreview = null,
        ?array $stockResetReport = null,
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
            'user' => $_SESSION['user'],
            'flash_success' => flash('success'),
            'flash_error' => $inlineError ?? flash('error'),
        ];
    }
}
