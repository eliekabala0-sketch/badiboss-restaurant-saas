<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class RestaurantOnboardingController
{
    public function home(Request $request): void
    {
        if (current_user() !== null) {
            redirect_after_login(current_user());
        }

        view('landing/home', [
            'title' => 'Badiboss Restaurant SaaS',
            'plans' => Container::getInstance()->get('restaurantAdmin')->listPlans(),
            'settings' => Container::getInstance()->get('platformSettings')->listSystemSettings(),
        ]);
    }

    public function showRegistration(Request $request): void
    {
        view('auth/register-restaurant', [
            'title' => 'Créer mon restaurant',
            'plans' => Container::getInstance()->get('restaurantAdmin')->listPlans(),
            'flash_error' => flash('error'),
            'form_old' => $_SESSION['_old_register_restaurant'] ?? [],
        ]);

        unset($_SESSION['_old_register_restaurant']);
    }

    public function register(Request $request): void
    {
        $payload = [
            'name' => trim((string) $request->input('name')),
            'restaurant_code' => trim((string) $request->input('restaurant_code')),
            'support_email' => trim((string) $request->input('support_email')),
            'phone' => trim((string) $request->input('phone')),
            'city' => trim((string) $request->input('city')),
            'country' => trim((string) $request->input('country', 'République Démocratique du Congo')),
            'address_line' => trim((string) $request->input('address_line')),
            'public_name' => trim((string) $request->input('name')),
            'primary_contact_name' => trim((string) $request->input('primary_contact_name')),
            'primary_contact_email' => trim((string) $request->input('primary_contact_email')),
            'primary_contact_phone' => trim((string) $request->input('primary_contact_phone')),
            'primary_role_code' => trim((string) $request->input('primary_role_code', 'owner')),
            'password' => (string) $request->input('password'),
            'subscription_plan_id' => (int) $request->input('subscription_plan_id', 1),
        ];

        $_SESSION['_old_register_restaurant'] = $payload;

        if ($payload['name'] === '' || $payload['primary_contact_name'] === '' || $payload['primary_contact_email'] === '' || $payload['password'] === '') {
            flash('error', 'Les informations restaurant et compte principal sont obligatoires.');
            redirect('/creer-mon-restaurant');
        }

        if (!in_array($payload['primary_role_code'], ['owner', 'manager'], true)) {
            flash('error', 'Le compte principal doit etre proprietaire ou gerant principal.');
            redirect('/creer-mon-restaurant');
        }

        $upload = Container::getInstance()->get('uploadService');
        $proposedCode = $payload['restaurant_code'] !== '' ? $payload['restaurant_code'] : $payload['name'];
        $normalizedCode = Container::getInstance()->get('tenantProvisioning')->previewRestaurantCode($proposedCode);

        try {
            $payload['logo_url'] = isset($_FILES['logo']) ? $upload->storeRestaurantImage($_FILES['logo'], $normalizedCode, 'logo') : null;
            $payload['cover_image_url'] = isset($_FILES['photo']) ? $upload->storeRestaurantImage($_FILES['photo'], $normalizedCode, 'photo') : null;
            $restaurantId = Container::getInstance()->get('tenantProvisioning')->createSelfServiceRestaurant($payload);
        } catch (\Throwable $exception) {
            flash('error', $exception->getMessage());
            redirect('/creer-mon-restaurant');
        }

        unset($_SESSION['_old_register_restaurant']);
        flash('success', 'Votre restaurant a ete cree. Connectez-vous avec le compte principal pour finaliser l activation.');
        redirect('/login');
    }
}
