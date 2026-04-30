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

        view('super-admin/users/index', [
            'title' => 'Utilisateurs',
            'users' => Container::getInstance()->get('userAdmin')->listUsers($restaurantId),
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
        Container::getInstance()->get('userAdmin')->changeStatus($userId, (string) $request->input('status', 'active'), $_SESSION['user']);

        flash('success', 'Le statut de l’utilisateur a été mis à jour.');
        redirect('/super-admin/users');
    }
}
