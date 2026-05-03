<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AuthorizationService
{
    private const ABILITY_RULES = [
        'tenant.dashboard.view' => ['roles' => ['owner', 'manager']],
        'tenant.access.manage' => ['roles' => ['owner', 'manager'], 'permissions' => ['roles.manage']],
        'platform.admin.view' => ['roles' => ['super_admin']],
        'platform.restaurants.manage' => ['roles' => ['super_admin'], 'permissions' => ['tenant_management.view', 'tenant_management.create', 'tenant_management.suspend']],
        'platform.users.manage' => ['roles' => ['super_admin'], 'permissions' => ['users.manage']],
        'platform.permissions.manage' => ['roles' => ['super_admin']],
        'platform.audit.view' => ['roles' => ['super_admin'], 'permissions' => ['audit.view']],
        'platform.settings.manage' => ['roles' => ['super_admin'], 'permissions' => ['settings.manage']],
        'menu.view' => ['roles' => ['owner', 'manager']],
        'menu.item.edit' => ['roles' => ['owner', 'manager'], 'permissions' => ['menu.manage']],
        'menu.status.manage' => ['roles' => ['manager'], 'permissions' => ['menu.manage']],
        'stock.view' => ['roles' => ['owner', 'manager', 'stock_manager'], 'permissions' => ['stock.manage']],
        'stock.create' => ['roles' => ['stock_manager'], 'permissions' => ['stock.manage']],
        'stock.item.edit' => ['roles' => ['manager', 'stock_manager'], 'permissions' => ['stock.manage']],
        'stock.entry.create' => ['roles' => ['stock_manager'], 'permissions' => ['stock.manage']],
        'stock.kitchen.issue' => ['roles' => ['stock_manager'], 'permissions' => ['stock.manage']],
        'stock.correction.request' => ['roles' => ['manager', 'stock_manager'], 'permissions' => ['stock.manage']],
        'correction.approve' => ['roles' => ['owner', 'manager'], 'permissions' => ['incidents.decide', 'stock.manage']],
        'stock.return.validate' => ['roles' => ['manager', 'stock_manager'], 'permissions' => ['stock.manage']],
        'stock.loss.declare' => ['roles' => ['stock_manager'], 'permissions' => ['losses.manage']],
        'stock.damage.signal' => ['roles' => ['stock_manager'], 'permissions' => ['incidents.signal']],
        'kitchen.view' => ['roles' => ['owner', 'manager', 'kitchen'], 'permissions' => ['kitchen.manage']],
        'kitchen.production.create' => ['roles' => ['kitchen'], 'permissions' => ['kitchen.manage']],
        'kitchen.return.confirm' => ['roles' => ['kitchen'], 'permissions' => ['incidents.confirm']],
        'kitchen.incident.signal' => ['roles' => ['kitchen'], 'permissions' => ['incidents.signal']],
        'incident.confirm.technical' => ['roles' => ['kitchen'], 'permissions' => ['incidents.confirm']],
        'sales.view' => ['roles' => ['owner', 'manager', 'cashier_server'], 'permissions' => ['sales.manage']],
        'sales.create' => ['roles' => ['cashier_server'], 'permissions' => ['sales.manage']],
        'sales.request.create' => ['roles' => ['cashier_server'], 'permissions' => ['sales.manage']],
        'sales.request.close' => ['roles' => ['cashier_server', 'manager'], 'permissions' => ['sales.manage']],
        'cash.view' => ['roles' => ['owner', 'manager', 'cashier_accountant', 'stock_manager'], 'permissions' => ['cash.manage']],
        'cash.remit.server' => ['roles' => ['cashier_server'], 'permissions' => ['cash.manage', 'sales.manage']],
        'cash.receive.cashier' => ['roles' => ['cashier_accountant', 'stock_manager'], 'permissions' => ['cash.manage']],
        'cash.transfer.manager' => ['roles' => ['cashier_accountant', 'stock_manager'], 'permissions' => ['cash.manage']],
        'cash.receive.manager' => ['roles' => ['manager'], 'permissions' => ['cash.manage']],
        'cash.transfer.owner' => ['roles' => ['manager'], 'permissions' => ['cash.manage']],
        'cash.receive.owner' => ['roles' => ['owner'], 'permissions' => ['cash.manage']],
        'cash.expense.manage' => ['roles' => ['cashier_accountant', 'stock_manager', 'manager'], 'permissions' => ['cash.manage']],
        'sales.cancel' => ['roles' => ['manager'], 'permissions' => ['sales.manage']],
        'sales.return.validate' => ['roles' => ['manager'], 'permissions' => ['sales.manage']],
        'sales.incident.signal' => ['roles' => ['cashier_server'], 'permissions' => ['incidents.signal']],
        'incident.decide' => ['roles' => ['manager'], 'permissions' => ['incidents.decide']],
        'cash_loss.declare' => ['roles' => ['manager'], 'permissions' => ['losses.manage']],
        'kitchen.request.fulfill' => ['roles' => ['kitchen'], 'permissions' => ['kitchen.manage']],
        'kitchen.stock.request' => ['roles' => ['kitchen'], 'permissions' => ['kitchen.manage']],
        'stock.request.respond' => ['roles' => ['stock_manager', 'manager'], 'permissions' => ['stock.manage']],
        'reports.view' => ['roles' => ['owner', 'manager'], 'permissions' => ['reports.view', 'reports.daily']],
    ];

    private const SUPER_ADMIN_AUDIT_ABILITIES = [
        'menu.view',
        'stock.view',
        'kitchen.view',
        'sales.view',
        'reports.view',
    ];

    private const SUBSCRIPTION_RESTRICTED_ABILITIES = [
        'tenant.access.manage',
        'menu.item.edit',
        'menu.status.manage',
        'stock.create',
        'stock.item.edit',
        'stock.entry.create',
        'stock.kitchen.issue',
        'stock.correction.request',
        'correction.approve',
        'stock.return.validate',
        'stock.loss.declare',
        'stock.damage.signal',
        'kitchen.production.create',
        'kitchen.return.confirm',
        'kitchen.incident.signal',
        'incident.confirm.technical',
        'sales.create',
        'sales.request.create',
        'sales.request.close',
        'cash.view',
        'cash.remit.server',
        'cash.receive.cashier',
        'cash.transfer.manager',
        'cash.receive.manager',
        'cash.transfer.owner',
        'cash.receive.owner',
        'cash.expense.manage',
        'sales.cancel',
        'sales.return.validate',
        'sales.incident.signal',
        'incident.decide',
        'cash_loss.declare',
        'kitchen.request.fulfill',
        'kitchen.stock.request',
        'stock.request.respond',
        'reports.view',
    ];

    private ?array $permissionCache = null;

    public function __construct(private readonly Database $database)
    {
    }

    public function can(?array $user, string $ability): bool
    {
        if (!is_array($user)) {
            return false;
        }

        $roleCode = (string) ($user['role_code'] ?? '');
        if ($roleCode === '') {
            return false;
        }

        if (($user['scope'] ?? null) === 'super_admin') {
            if (str_starts_with($ability, 'platform.')) {
                return true;
            }

            return in_array($ability, self::SUPER_ADMIN_AUDIT_ABILITIES, true);
        }

        $rule = self::ABILITY_RULES[$ability] ?? null;
        if ($rule === null) {
            return false;
        }

        if (
            ($user['scope'] ?? null) === 'tenant'
            && in_array($ability, self::SUBSCRIPTION_RESTRICTED_ABILITIES, true)
            && !\App\Core\Container::getInstance()->get('subscriptionService')->canUseOperationalFeatures($user)
        ) {
            return false;
        }

        $matchesRole = in_array($roleCode, $rule['roles'], true);
        $permissionCodes = $rule['permissions'] ?? [];

        if ($permissionCodes === [] && !$matchesRole) {
            return false;
        }

        if ($permissionCodes === [] && $matchesRole) {
            return true;
        }

        $rolePermissions = $this->permissionCodesForRole((int) ($user['role_id'] ?? 0), (int) ($user['restaurant_id'] ?? 0));
        if ($rolePermissions['allow'] === [] && $rolePermissions['deny'] === []) {
            return $matchesRole;
        }

        foreach ($permissionCodes as $permissionCode) {
            if (in_array($permissionCode, $rolePermissions['deny'], true)) {
                return false;
            }
        }

        foreach ($permissionCodes as $permissionCode) {
            if (in_array($permissionCode, $rolePermissions['allow'], true)) {
                return true;
            }
        }

        return $matchesRole;
    }

    private function permissionCodesForRole(int $roleId, int $restaurantId): array
    {
        if ($this->permissionCache === null) {
            $statement = $this->database->pdo()->query(
                'SELECT rp.role_id, rp.restaurant_id, p.code AS permission_code, rp.effect
                 FROM role_permissions rp
                 INNER JOIN permissions p ON p.id = rp.permission_id'
            );

            $this->permissionCache = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) $row['role_id'] . ':' . (string) ($row['restaurant_id'] ?? 0);
                $effect = (string) $row['effect'];
                $this->permissionCache[$code][$effect][] = (string) $row['permission_code'];
            }
        }

        $scopedKey = $roleId . ':' . $restaurantId;
        $globalKey = $roleId . ':0';

        return [
            'allow' => array_values(array_unique(array_merge($this->permissionCache[$globalKey]['allow'] ?? [], $this->permissionCache[$scopedKey]['allow'] ?? []))),
            'deny' => array_values(array_unique(array_merge($this->permissionCache[$globalKey]['deny'] ?? [], $this->permissionCache[$scopedKey]['deny'] ?? []))),
        ];
    }
}
