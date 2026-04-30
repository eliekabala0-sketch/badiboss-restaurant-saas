<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class SuperAdminRestaurantController
{
    public function store(Request $request): void
    {
        $payload = [
            'name' => trim((string) $request->input('name')),
            'slug' => trim((string) $request->input('slug')),
            'support_email' => trim((string) $request->input('support_email')),
            'phone' => trim((string) $request->input('phone')),
            'city' => trim((string) $request->input('city')),
            'address_line' => trim((string) $request->input('address_line')),
            'access_url' => trim((string) $request->input('access_url')),
            'download_url' => trim((string) $request->input('download_url')),
            'public_name' => trim((string) $request->input('public_name')),
            'web_subdomain' => trim((string) $request->input('web_subdomain')),
            'custom_domain' => trim((string) $request->input('custom_domain')),
            'primary_color' => trim((string) $request->input('primary_color')),
            'secondary_color' => trim((string) $request->input('secondary_color')),
            'accent_color' => trim((string) $request->input('accent_color')),
            'subscription_plan_id' => (int) $request->input('subscription_plan_id', 1),
            'status' => 'active',
        ];

        if ($payload['name'] === '' || $payload['slug'] === '') {
            $_SESSION['flash_error'] = 'Le nom commercial et le slug sont obligatoires.';
            redirect('/super-admin');
        }

        Container::getInstance()->get('tenantProvisioning')->createRestaurant($payload, $_SESSION['user']);
        $_SESSION['flash_success'] = 'Le restaurant a ete cree avec succes.';

        redirect('/super-admin');
    }
}
