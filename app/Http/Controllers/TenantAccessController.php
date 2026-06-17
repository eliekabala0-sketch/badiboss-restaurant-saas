<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class TenantAccessController
{
    public function index(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();
        $accessEditor = (string) ($request->query['access_editor'] ?? '') === '1';
        $roleAdmin = Container::getInstance()->get('roleAdmin');
        $userPage = $roleAdmin->listUsersForRestaurantPage($restaurantId, [
            'search' => (string) ($request->query['q'] ?? ''),
            'status' => (string) ($request->query['status'] ?? ''),
            'role_id' => (int) ($request->query['role_id'] ?? 0),
            'page' => (int) ($request->query['page'] ?? 1),
            'per_page' => 20,
        ]);

        view('owner/access', [
            'title' => 'Personnel et acces',
            'roles' => $roleAdmin->listAssignableRoles($restaurantId),
            'preset_roles' => $roleAdmin->listPresetRoles($restaurantId),
            'role_permissions' => $accessEditor ? $roleAdmin->permissionIdsByRole($restaurantId) : [],
            'permissions' => $accessEditor ? $roleAdmin->listPermissions() : [],
            'permission_groups' => $accessEditor ? $roleAdmin->listPermissionGroups() : [],
            'users' => $userPage['items'],
            'user_page' => $userPage,
            'access_editor' => $accessEditor,
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('roles', $restaurantId, 'screens', 'tenant-access', 'Consultation roles et acces restaurant');
    }

    public function storeUser(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('userAdmin')->createUser([
            'restaurant_id' => (string) $restaurantId,
            'role_id' => (int) $request->input('role_id'),
            'full_name' => (string) $request->input('full_name'),
            'email' => (string) $request->input('email'),
            'phone' => (string) $request->input('phone'),
            'password' => (string) $request->input('password'),
            'status' => (string) $request->input('status', 'active'),
            'status_reason' => (string) $request->input('status_reason', ''),
            'must_change_password' => $request->input('must_change_password'),
        ], current_user());

        flash('success', 'Utilisateur cree pour ce restaurant.');
        redirect('/owner/users');
    }

    public function updateUser(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('userAdmin')->updateRestaurantUser(
            (int) $request->route('id'),
            $restaurantId,
            [
                'role_id' => (int) $request->input('role_id'),
                'full_name' => (string) $request->input('full_name'),
                'email' => (string) $request->input('email'),
                'phone' => (string) $request->input('phone'),
                'password' => (string) $request->input('password'),
                'must_change_password' => $request->input('must_change_password'),
            ],
            current_user()
        );

        flash('success', 'Fiche agent mise a jour.');
        redirect('/owner/users');
    }

    public function changeUserStatus(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();
        $userId = (int) $request->route('id');
        $target = Container::getInstance()->get('userAdmin')->findUser($userId);
        if ($target === null || (int) ($target['restaurant_id'] ?? 0) !== $restaurantId) {
            throw new \RuntimeException('Utilisateur introuvable pour ce restaurant.');
        }

        Container::getInstance()->get('userAdmin')->changeStatusWithReason(
            $userId,
            (string) $request->input('status', 'active'),
            (string) $request->input('status_reason', ''),
            current_user()
        );

        flash('success', 'Statut agent mis a jour.');
        redirect('/owner/users');
    }

    public function storeRole(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('roleAdmin')->createTenantRole($restaurantId, [
            'name' => (string) $request->input('name'),
            'code' => (string) $request->input('code'),
            'description' => (string) $request->input('description'),
            'status' => (string) $request->input('status', 'active'),
            'permission_ids' => (array) $request->input('permission_ids', []),
        ], current_user());

        flash('success', 'Role dynamique cree.');
        redirect('/owner/access');
    }

    public function updateRolePermissions(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('roleAdmin')->syncPermissions(
            (int) $request->route('id'),
            $restaurantId,
            array_map('intval', (array) $request->input('permission_ids', [])),
            current_user()
        );

        flash('success', 'Permissions du role mises a jour.');
        redirect('/owner/access');
    }

    public function changeRoleStatus(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('roleAdmin')->changeRoleStatus(
            (int) $request->route('id'),
            $restaurantId,
            (string) $request->input('status', 'active'),
            current_user()
        );

        flash('success', 'Statut du role mis a jour.');
        redirect('/owner/access');
    }

    public function assignUserRole(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();

        Container::getInstance()->get('roleAdmin')->assignUserRole(
            (int) $request->route('id'),
            $restaurantId,
            (int) $request->input('role_id'),
            current_user()
        );

        flash('success', 'Utilisateur affecte au role.');
        redirect('/owner/users');
    }

    public function showUserHistory(Request $request): void
    {
        authorize_access('tenant.access.manage');
        $restaurantId = current_restaurant_id();
        $snapshot = Container::getInstance()->get('roleAdmin')->userActivitySnapshot(
            $restaurantId,
            (int) $request->route('id')
        );

        view('owner/user-history', [
            'title' => 'Historique utilisateur',
            'snapshot' => $snapshot,
            'restaurant' => current_restaurant_context(),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);
    }
}
