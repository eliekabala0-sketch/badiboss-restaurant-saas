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

        view('owner/access', [
            'title' => 'Roles et acces',
            'roles' => Container::getInstance()->get('roleAdmin')->listAssignableRoles($restaurantId),
            'preset_roles' => Container::getInstance()->get('roleAdmin')->listPresetRoles($restaurantId),
            'role_permissions' => Container::getInstance()->get('roleAdmin')->permissionIdsByRole($restaurantId),
            'permissions' => Container::getInstance()->get('roleAdmin')->listPermissions(),
            'permission_groups' => Container::getInstance()->get('roleAdmin')->listPermissionGroups(),
            'users' => Container::getInstance()->get('roleAdmin')->listUsersForRestaurant($restaurantId),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);

        audit_access('roles', $restaurantId, 'screens', 'tenant-access', 'Consultation roles et acces restaurant');
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
        redirect('/owner/access');
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
