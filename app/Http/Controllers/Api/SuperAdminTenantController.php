<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use PDO;

final class SuperAdminTenantController
{
    public function index(Request $request): void
    {
        $pdo = Container::getInstance()->get('db')->pdo();
        $rows = $pdo->query(
            'SELECT r.id, r.name, r.slug, r.status, r.access_url, r.download_url,
                    rb.public_name, rb.logo_url, rb.primary_color, rb.secondary_color, rb.custom_domain
             FROM restaurants r
             LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
             ORDER BY r.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        Response::json([
            'data' => $rows,
        ]);
    }

    public function store(Request $request): void
    {
        $payload = $request->json();
        $restaurantId = Container::getInstance()->get('tenantProvisioning')->createRestaurant($payload, $_SERVER['api_user']);

        Response::json([
            'message' => 'Restaurant créé',
            'restaurant_id' => $restaurantId,
        ], 201);
    }
}
