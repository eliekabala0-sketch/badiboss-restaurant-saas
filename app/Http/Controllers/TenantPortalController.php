<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;
use PDO;

final class TenantPortalController
{
    public function show(Request $request): void
    {
        $tenant = Container::getInstance()->get('tenantResolver')->resolve($request);

        if ($tenant === null) {
            http_response_code(404);
            echo 'Restaurant introuvable.';
            return;
        }

        $restaurantSettings = Container::getInstance()->get('platformSettings')->restaurantSettings((int) $tenant['id']);

        view('portal/show', [
            'title' => $tenant['portal_title'] ?: $tenant['public_name'],
            'tenant' => $tenant,
            'menu_items' => !empty($restaurantSettings['restaurant_public_menu_enabled'])
                ? Container::getInstance()->get('menuAdmin')->listPublicItems((int) $tenant['id'])
                : [],
            'public_rules' => [
                'public_menu_enabled' => !empty($restaurantSettings['restaurant_public_menu_enabled']),
                'auth_required_for_order' => !empty($restaurantSettings['restaurant_public_order_requires_auth']),
                'auth_required_for_reservation' => !empty($restaurantSettings['restaurant_public_reservation_requires_auth']),
            ],
            'registration_path' => restaurant_generated_registration_path($tenant),
        ]);
    }

    public function showRegistration(Request $request): void
    {
        $tenant = $this->resolveTenantForRegistration($request);

        view('portal/register', [
            'title' => $tenant !== null
                ? 'Inscription client - ' . ($tenant['public_name'] ?: $tenant['name'])
                : 'Inscription client',
            'tenant' => $tenant,
            'success' => flash('success'),
            'error' => flash('error'),
            'input' => [
                'full_name' => (string) ($request->query['full_name'] ?? ''),
                'email' => (string) ($request->query['email'] ?? ''),
                'phone' => (string) ($request->query['phone'] ?? ''),
                'restaurant_code' => (string) ($request->query['restaurant_code'] ?? ''),
            ],
        ]);
    }

    public function register(Request $request): void
    {
        $tenant = $this->resolveTenantForRegistration($request, true);
        if ($tenant === null) {
            flash('error', 'Restaurant introuvable pour cette inscription.');
            redirect('/portal/register');
        }

        Container::getInstance()->get('userAdmin')->registerPublicCustomer($tenant, [
            'full_name' => (string) $request->input('full_name'),
            'email' => (string) $request->input('email'),
            'phone' => (string) $request->input('phone'),
            'password' => (string) $request->input('password'),
        ]);

        flash('success', 'Votre compte client a ete cree. Connectez-vous pour continuer.');
        redirect('/login');
    }

    private function resolveTenantForRegistration(Request $request, bool $allowRestaurantCodeLookup = false): ?array
    {
        $slug = trim((string) $request->route('slug', ''));
        if ($slug !== '') {
            return Container::getInstance()->get('tenantResolver')->resolve($request);
        }

        if (!$allowRestaurantCodeLookup) {
            return null;
        }

        $restaurantCode = trim((string) $request->input('restaurant_code', ''));
        if ($restaurantCode === '') {
            return null;
        }

        $statement = Container::getInstance()->get('db')->pdo()->prepare(
            'SELECT r.*, rb.public_name, rb.logo_url, rb.cover_image_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                    rb.accent_color, rb.web_subdomain, rb.custom_domain, rb.portal_title, rb.portal_tagline, rb.welcome_text
             FROM restaurants r
             LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
             WHERE r.restaurant_code = :restaurant_code
             LIMIT 1'
        );
        $statement->execute(['restaurant_code' => $restaurantCode]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC);

        return $tenant ?: null;
    }
}
