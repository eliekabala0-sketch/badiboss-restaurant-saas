<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;

final class OwnerAreaMiddleware
{
    public function handle(Request $request): void
    {
        if (!Container::getInstance()->get('authz')->can(current_user(), 'tenant.dashboard.view')) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }

        enforce_restaurant_access(true);
    }
}
