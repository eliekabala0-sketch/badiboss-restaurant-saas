<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

final class SuperAdminMiddleware
{
    public function handle(Request $request): void
    {
        if (!isset($_SESSION['user']) || ($_SESSION['user']['scope'] ?? null) !== 'super_admin') {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    }
}
