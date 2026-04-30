<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

final class AuthMiddleware
{
    public function handle(Request $request): void
    {
        if (!isset($_SESSION['user'])) {
            redirect('/login');
        }

        enforce_restaurant_write_access($request);
    }
}
