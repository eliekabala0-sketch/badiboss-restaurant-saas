<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;

final class SalesAccessMiddleware
{
    public function handle(Request $request): void
    {
        if (!Container::getInstance()->get('authz')->can(current_user(), 'sales.view')) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }

        enforce_restaurant_access(false);
    }
}
