<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class UserAdminController
{
    public function index(Request $request): void
    {
        authorize_access('platform.users.manage');
        $restaurantId = $request->query['restaurant_id'] ?? null;
        $restaurantId = $restaurantId !== null && $restaurantId !== '' ? (int) $restaurantId : null;

        $restaurantService = Container::getInstance()->get('restaurantAdmin');
        $userPage = Container::getInstance()->get('userAdmin')->listUsersPage($restaurantId, [
            'search' => (string) ($request->query['q'] ?? ''),
            'status' => (string) ($request->query['status'] ?? ''),
            'role_id' => (int) ($request->query['role_id'] ?? 0),
            'page' => (int) ($request->query['page'] ?? 1),
            'per_page' => 20,
        ]);

        view('super-admin/users/index', [
            'title' => 'Utilisateurs',
            'users' => $userPage['items'],
            'user_page' => $userPage,
            'roles' => $restaurantService->listRoles(),
            'restaurants' => $restaurantService->listRestaurants(),
            'selected_restaurant_id' => $restaurantId,
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('users', $restaurantId, 'screens', 'users-index', 'Consultation liste utilisateurs');
    }

    public function store(Request $request): void
    {
        authorize_access('platform.users.manage');
        Container::getInstance()->get('userAdmin')->createUser([
            'restaurant_id' => (string) $request->input('restaurant_id', ''),
            'role_id' => (int) $request->input('role_id'),
            'full_name' => (string) $request->input('full_name'),
            'email' => (string) $request->input('email'),
            'phone' => (string) $request->input('phone'),
            'password' => (string) $request->input('password'),
            'status' => (string) $request->input('status', 'active'),
            'status_reason' => (string) $request->input('status_reason', ''),
            'must_change_password' => $request->input('must_change_password'),
        ], $_SESSION['user']);

        flash('success', 'L’utilisateur a été créé.');
        redirect('/super-admin/users');
    }

    public function update(Request $request): void
    {
        authorize_access('platform.users.manage');
        $userId = (int) $request->route('id');
        Container::getInstance()->get('userAdmin')->updateUser($userId, [
            'restaurant_id' => (string) $request->input('restaurant_id', ''),
            'role_id' => (int) $request->input('role_id'),
            'full_name' => (string) $request->input('full_name'),
            'email' => (string) $request->input('email'),
            'phone' => (string) $request->input('phone'),
            'password' => (string) $request->input('password'),
            'must_change_password' => $request->input('must_change_password'),
        ], $_SESSION['user']);

        flash('success', 'L’utilisateur a été mis à jour.');
        redirect('/super-admin/users');
    }

    public function changeStatus(Request $request): void
    {
        authorize_access('platform.users.manage');
        $userId = (int) $request->route('id');
        Container::getInstance()->get('userAdmin')->changeStatusWithReason(
            $userId,
            (string) $request->input('status', 'active'),
            (string) $request->input('status_reason', ''),
            $_SESSION['user']
        );

        flash('success', 'Le statut de l’utilisateur a été mis à jour.');
        redirect('/super-admin/users');
    }
}
