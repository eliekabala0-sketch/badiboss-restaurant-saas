<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Container;
use App\Core\Request;

final class AuthMiddleware
{
    public function handle(Request $request): void
    {
        if (!isset($_SESSION['user'])) {
            redirect('/login');
        }

        $auth = Container::getInstance()->get('auth');
        $freshUser = $auth->refreshSessionUser(is_array($_SESSION['user']) ? $_SESSION['user'] : []);
        if ($freshUser === null) {
            $message = $auth->lastLoginFailureMessage() ?: 'Votre session a ete fermee car votre compte n est plus autorise.';
            unset($_SESSION['user'], $_SESSION['restaurant_id']);
            $_SESSION['_flash']['error'] = $message;
            redirect('/login');
        }

        $_SESSION['user'] = $freshUser;
        if (($freshUser['restaurant_id'] ?? null) !== null) {
            $_SESSION['restaurant_id'] = (int) $freshUser['restaurant_id'];
        } else {
            unset($_SESSION['restaurant_id']);
        }

        enforce_restaurant_write_access($request);
    }
}
