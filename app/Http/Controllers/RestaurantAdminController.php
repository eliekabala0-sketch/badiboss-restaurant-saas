<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class RestaurantAdminController
{
    public function index(Request $request): void
    {
        authorize_access('platform.restaurants.manage');
        $service = Container::getInstance()->get('restaurantAdmin');

        view('super-admin/restaurants/index', [
            'title' => 'Restaurants',
            'restaurants' => $service->listRestaurants(),
            'plans' => $service->listPlans(),
            'visual_defaults' => Container::getInstance()->get('platformSettings')->visualDefaults(),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('tenant_management', null, 'screens', 'restaurants-index', 'Consultation liste restaurants');
    }

    public function show(Request $request): void
    {
        authorize_access('platform.restaurants.manage');
        $service = Container::getInstance()->get('restaurantAdmin');
        $restaurant = $service->findRestaurant((int) $request->route('id'));

        if ($restaurant === null) {
            http_response_code(404);
            echo 'Restaurant not found';
            return;
        }

        view('super-admin/restaurants/show', [
            'title' => 'Detail restaurant',
            'restaurant' => $restaurant,
            'plans' => $service->listPlans(),
            'roles' => $service->listRoles(),
            'subscription' => Container::getInstance()->get('subscriptionService')->summaryForRestaurant($restaurant),
            'subscription_rules' => Container::getInstance()->get('subscriptionService')->subscriptionRules(),
            'visual_defaults' => Container::getInstance()->get('platformSettings')->visualDefaults(),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('tenant_management', (int) $restaurant['id'], 'screens', 'restaurant-show', 'Consultation detail restaurant');
    }

    public function store(Request $request): void
    {
        $upload = Container::getInstance()->get('uploadService');
        $tenantProvisioning = Container::getInstance()->get('tenantProvisioning');

        $payload = [
            'name' => trim((string) $request->input('name')),
            'restaurant_code' => trim((string) $request->input('restaurant_code')),
            'slug' => trim((string) $request->input('slug')),
            'support_email' => trim((string) $request->input('support_email')),
            'phone' => trim((string) $request->input('phone')),
            'city' => trim((string) $request->input('city')),
            'country' => trim((string) $request->input('country')),
            'address_line' => trim((string) $request->input('address_line')),
            'public_name' => trim((string) $request->input('public_name')),
            'web_subdomain' => trim((string) $request->input('web_subdomain')),
            'custom_domain' => trim((string) $request->input('custom_domain')),
            'primary_color' => trim((string) $request->input('primary_color')),
            'secondary_color' => trim((string) $request->input('secondary_color')),
            'accent_color' => trim((string) $request->input('accent_color')),
            'portal_title' => trim((string) $request->input('portal_title')),
            'portal_tagline' => trim((string) $request->input('portal_tagline')),
            'welcome_text' => trim((string) $request->input('welcome_text')),
            'favicon_url' => trim((string) $request->input('favicon_url')),
            'logo_url' => trim((string) $request->input('logo_url')),
            'cover_image_url' => trim((string) $request->input('cover_image_url')),
            'app_display_name' => trim((string) $request->input('app_display_name')),
            'app_short_name' => trim((string) $request->input('app_short_name')),
            'subscription_plan_id' => (int) $request->input('subscription_plan_id', 1),
            'status' => 'active',
            'subscription_status' => 'ACTIVE',
            'subscription_payment_status' => 'PAID',
            'feature_pwa_enabled' => $request->input('feature_pwa_enabled', '0'),
        ];

        if ($payload['name'] === '') {
            flash('error', 'Le nom commercial est obligatoire.');
            redirect('/super-admin/restaurants');
        }

        $proposedCode = $payload['restaurant_code'] !== '' ? $payload['restaurant_code'] : $payload['name'];
        $normalizedCode = $tenantProvisioning->previewRestaurantCode($proposedCode);

        try {
            $payload['logo_url'] = isset($_FILES['logo']) ? $upload->storeRestaurantImage($_FILES['logo'], $normalizedCode, 'logo') : null;
            $payload['cover_image_url'] = isset($_FILES['photo']) ? $upload->storeRestaurantImage($_FILES['photo'], $normalizedCode, 'photo') : null;
            $payload['favicon_url'] = isset($_FILES['favicon']) ? $upload->storeRestaurantImage($_FILES['favicon'], $normalizedCode, 'favicon') : null;
            Container::getInstance()->get('tenantProvisioning')->createRestaurant($payload, $_SESSION['user']);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            flash('error', ui_safe_message($exception->getMessage()));
            redirect('/super-admin/restaurants');
        }

        flash('success', 'Le restaurant a ete cree avec succes.');
        redirect('/super-admin/restaurants');
    }

    public function update(Request $request): void
    {
        $restaurantId = (int) $request->route('id');
        Container::getInstance()->get('restaurantAdmin')->updateRestaurant($restaurantId, [
            'name' => $request->input('name'),
            'slug' => $request->input('slug'),
            'restaurant_code' => $request->input('restaurant_code'),
            'legal_name' => $request->input('legal_name'),
            'support_email' => $request->input('support_email'),
            'phone' => $request->input('phone'),
            'country' => $request->input('country'),
            'city' => $request->input('city'),
            'address_line' => $request->input('address_line'),
            'timezone' => $request->input('timezone', 'Africa/Kinshasa'),
            'currency_code' => $request->input('currency_code', 'USD'),
            'subscription_plan_id' => (int) $request->input('subscription_plan_id', 1),
        ], $_SESSION['user']);

        flash('success', 'Les informations du restaurant ont ete mises a jour.');
        redirect('/super-admin/restaurants/' . $restaurantId);
    }

    public function updateBranding(Request $request): void
    {
        $restaurantId = (int) $request->route('id');
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        if ($restaurant === null) {
            flash('error', 'Restaurant introuvable.');
            redirect('/super-admin/restaurants');
        }

        $upload = Container::getInstance()->get('uploadService');
        $logoUrl = isset($_FILES['logo']) ? $upload->storeRestaurantImage($_FILES['logo'], (string) $restaurant['restaurant_code'], 'logo') : ($restaurant['logo_url'] ?? null);
        $coverImageUrl = isset($_FILES['photo']) ? $upload->storeRestaurantImage($_FILES['photo'], (string) $restaurant['restaurant_code'], 'photo') : ($restaurant['cover_image_url'] ?? null);
        $faviconUrl = isset($_FILES['favicon']) ? $upload->storeRestaurantImage($_FILES['favicon'], (string) $restaurant['restaurant_code'], 'favicon') : ($restaurant['favicon_url'] ?? null);

        Container::getInstance()->get('restaurantAdmin')->updateBranding($restaurantId, [
            'public_name' => $request->input('public_name'),
            'logo_url' => $logoUrl,
            'cover_image_url' => $coverImageUrl,
            'favicon_url' => $faviconUrl,
            'primary_color' => $request->input('primary_color'),
            'secondary_color' => $request->input('secondary_color'),
            'accent_color' => $request->input('accent_color'),
            'web_subdomain' => $request->input('web_subdomain'),
            'custom_domain' => $request->input('custom_domain'),
            'app_display_name' => $request->input('app_display_name'),
            'app_short_name' => $request->input('app_short_name'),
            'portal_title' => $request->input('portal_title'),
            'portal_tagline' => $request->input('portal_tagline'),
            'welcome_text' => $request->input('welcome_text'),
            'pwa_enabled' => $request->input('pwa_enabled'),
        ], $_SESSION['user']);

        flash('success', 'Le branding a ete mis a jour.');
        redirect('/super-admin/restaurants/' . $restaurantId);
    }

    public function updateSettings(Request $request): void
    {
        $restaurantId = (int) $request->route('id');
        Container::getInstance()->get('restaurantAdmin')->updateSettings($restaurantId, [
            'restaurant_return_window_hours' => $request->input('restaurant_return_window_hours', '24'),
            'restaurant_server_auto_close_minutes' => $request->input('restaurant_server_auto_close_minutes', '90'),
            'restaurant_loss_validation_required' => $request->input('restaurant_loss_validation_required', '0'),
            'restaurant_reports_timezone' => $request->input('restaurant_reports_timezone', 'Africa/Lagos'),
            'restaurant_feature_pwa_enabled' => $request->input('restaurant_feature_pwa_enabled', '0'),
            'restaurant_welcome_text' => $request->input('restaurant_welcome_text', ''),
        ], $_SESSION['user']);

        flash('success', 'Les parametres du restaurant ont ete mis a jour.');
        redirect('/super-admin/restaurants/' . $restaurantId);
    }

    public function changeStatus(Request $request): void
    {
        $restaurantId = (int) $request->route('id');
        Container::getInstance()->get('restaurantAdmin')->changeRestaurantStatus(
            $restaurantId,
            (string) $request->input('status', 'active'),
            $_SESSION['user']
        );

        flash('success', 'Le statut du restaurant a ete mis a jour.');
        redirect('/super-admin/restaurants/' . $restaurantId);
    }

    public function declarePayment(Request $request): void
    {
        $restaurantId = current_restaurant_id();
        Container::getInstance()->get('restaurantAdmin')->declareSubscriptionPayment($restaurantId, $_SESSION['user']);
        flash('success', 'Paiement de l abonnement declare. Validation plateforme en attente.');
        redirect('/owner');
    }

    public function updateOwnerCurrency(Request $request): void
    {
        $restaurantId = current_restaurant_id();
        $currency = strtoupper(trim((string) $request->input('currency', 'USD')));

        if (!in_array($currency, ['USD', 'CDF'], true)) {
            flash('error', 'La devise choisie est invalide.');
            redirect('/owner');
        }

        Container::getInstance()->get('restaurantAdmin')->updateRestaurantCurrency($restaurantId, $currency, $_SESSION['user']);

        flash('success', 'La devise du restaurant a ete mise a jour.');
        redirect('/owner');
    }

    public function activateSubscription(Request $request): void
    {
        $restaurantId = (int) $request->route('id');
        Container::getInstance()->get('restaurantAdmin')->activateSubscription($restaurantId, [
            'subscription_started_at' => $request->input('subscription_started_at', date('Y-m-d H:i:s')),
            'subscription_duration_days' => $request->input('subscription_duration_days', 30),
            'payment_status' => $request->input('payment_status', 'PAID'),
            'justification' => $request->input('justification', 'Validation abonnement manuelle'),
        ], $_SESSION['user']);

        flash('success', 'Abonnement active avec succes.');
        redirect('/super-admin/restaurants/' . $restaurantId);
    }
}
