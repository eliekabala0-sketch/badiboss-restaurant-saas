<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class AuthController
{
    public function showLogin(Request $request): void
    {
        view('auth/login', [
            'title' => 'Connexion',
            'success' => flash('success'),
        ]);
    }

    public function login(Request $request): void
    {
        $auth = Container::getInstance()->get('auth');
        $user = $auth->attemptWebLogin((string) $request->input('email'), (string) $request->input('password'));

        if ($user === null) {
            view('auth/login', [
                'title' => 'Connexion',
                'error' => $auth->lastLoginFailureMessage() ?: 'Identifiants invalides ou compte inactif.',
                'success' => flash('success'),
            ]);
            return;
        }

        $_SESSION['user'] = $user;
        if (($user['restaurant_id'] ?? null) !== null) {
            $_SESSION['restaurant_id'] = (int) $user['restaurant_id'];
        } else {
            unset($_SESSION['restaurant_id']);
        }

        if (restaurant_status_blocks_operations($user['restaurant_status'] ?? null)) {
            flash('restaurant_notice', restaurant_status_message($user['restaurant_status'] ?? null));
        }

        redirect_after_login($user);
    }

    public function switchRole(Request $request): void
    {
        if (empty($_SESSION['_functions_csrf'])
            || !hash_equals((string) $_SESSION['_functions_csrf'], (string) $request->input('_functions_csrf', ''))) {
            http_response_code(403);
            return;
        }
        try {
            $_SESSION['user'] = (new \App\Services\UserFunctionService(Container::getInstance()->get('db')))
                ->selectRole(current_user(), (int) $request->input('role_id'));
        } catch (\RuntimeException $exception) {
            flash('error', ui_safe_message($exception->getMessage()));
        }
        redirect_after_login($_SESSION['user']);
    }

    public function logout(Request $request): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        redirect('/login');
    }
}
