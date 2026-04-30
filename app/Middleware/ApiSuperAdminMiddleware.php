<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

final class ApiSuperAdminMiddleware
{
    public function handle(Request $request): void
    {
        $user = $_SERVER['api_user'] ?? null;

        if (!is_array($user) || ($user['scope'] ?? null) !== 'super_admin') {
            Response::json(['message' => 'Forbidden'], 403);
        }
    }
}
