<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class AuthApiController
{
    public function health(Request $request): void
    {
        Response::json([
            'status' => 'ok',
        ]);
    }

    public function login(Request $request): void
    {
        $payload = $request->json();
        $auth = Container::getInstance()->get('auth');
        $token = $auth->issueApiToken((string) ($payload['email'] ?? ''), (string) ($payload['password'] ?? ''));

        if ($token === null) {
            Response::json([
                'message' => 'Unauthorized',
            ], 401);
        }

        Response::json([
            'message' => 'Authenticated',
            'data' => $token,
        ]);
    }
}
