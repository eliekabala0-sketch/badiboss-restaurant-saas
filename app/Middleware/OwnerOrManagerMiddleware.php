<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

final class OwnerOrManagerMiddleware
{
    public function handle(Request $request): void
    {
        $user = $_SESSION['user'] ?? null;
        $allowed = ['owner', 'manager'];

        if (!is_array($user) || !in_array($user['role_code'] ?? null, $allowed, true)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }

        enforce_restaurant_access(false);
    }
}
