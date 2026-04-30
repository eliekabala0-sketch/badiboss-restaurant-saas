<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class ApiTokenMiddleware
{
    public function handle(Request $request): void
    {
        $authHeader = $request->headers['Authorization'] ?? $request->headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.+)/i', (string) $authHeader, $matches)) {
            Response::json(['message' => 'Missing bearer token'], 401);
        }

        $user = Container::getInstance()->get('auth')->userFromToken(trim($matches[1]));

        if ($user === null) {
            Response::json(['message' => 'Invalid or expired token'], 401);
        }

        $_SERVER['api_user'] = $user;
        enforce_restaurant_write_access($request, $user, true);
    }
}
