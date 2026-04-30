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

        view('super-admin/dashboard', [
            'title' => 'Super administration',
            'stats' => $stats,
            'restaurants' => $restaurants,
            'plans' => $plans,
            'user' => $_SESSION['user'],
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('dashboard', null, 'screens', 'super-admin', 'Consultation tableau de bord super administrateur');
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
            'can_access_reports' => can_access('reports.view'),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('dashboard', $restaurantId, 'screens', 'owner-dashboard', 'Consultation tableau de bord restaurant');
    }
}
