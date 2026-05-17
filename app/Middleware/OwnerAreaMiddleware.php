<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;

final class OwnerAreaMiddleware
{
    public function handle(Request $request): void
    {
        $user = current_user();
        if (
            is_array($user)
            && ($user['scope'] ?? null) === 'super_admin'
            && in_array(strtoupper((string) ($request->method ?? 'GET')), ['GET', 'HEAD'], true)
        ) {
            enforce_restaurant_access(true);
            return;
        }

        if (!Container::getInstance()->get('authz')->can($user, 'tenant.dashboard.view')) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }

        enforce_restaurant_access(true);
    }
}
