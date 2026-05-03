<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class RoleAdminService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listAssignableRoles(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, restaurant_id, name, code, description, scope, status, is_locked
             FROM roles
             WHERE status != "archived"
               AND (
                    (scope = "system" AND code != "super_admin")
                    OR (scope = "tenant" AND restaurant_id = :restaurant_id)
               )
             ORDER BY scope ASC, is_locked DESC, name ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        $roles = $statement->fetchAll(PDO::FETCH_ASSOC);
        $permissionMap = $this->permissionIdsByRole($restaurantId);

        foreach ($roles as &$role) {
            $role['display_name'] = restaurant_role_label((string) ($role['code'] ?? ''));
            $role['is_system_preset'] = ($role['scope'] ?? null) === 'system' ? 1 : 0;
            $role['permission_ids'] = $permissionMap[(int) $role['id']] ?? [];
            $role['permission_labels'] = $this->permissionLabelsForIds($role['permission_ids']);
        }
        unset($role);

        return $roles;
    }

    public function listPresetRoles(int $restaurantId): array
    {
        return array_values(array_filter(
            $this->listAssignableRoles($restaurantId),
            static fn (array $role): bool => (int) ($role['is_system_preset'] ?? 0) === 1
        ));
    }

    public function permissionIdsByRole(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT role_id, permission_id, restaurant_id
             FROM role_permissions
             WHERE restaurant_id IS NULL OR restaurant_id = :restaurant_id'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roleId = (int) $row['role_id'];
            $map[$roleId] ??= [];
            $map[$roleId][] = (int) $row['permission_id'];
        }

        foreach ($map as $roleId => $permissionIds) {
            $map[$roleId] = array_values(array_unique($permissionIds));
        }

        return $map;
    }

    public function listPermissions(): array
    {
        $permissions = $this->database->pdo()->query(
            'SELECT id, module_name, action_name, code, is_sensitive
             FROM permissions
             ORDER BY module_name ASC, action_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($permissions as &$permission) {
            $permission['module_label'] = permission_module_label((string) ($permission['module_name'] ?? ''));
            $permission['label'] = permission_label((string) ($permission['code'] ?? ''));
            $permission['description_fr'] = permission_description_fr((string) ($permission['code'] ?? ''));
        }
        unset($permission);

        return $permissions;
    }

    public function listPermissionGroups(): array
    {
        $groups = [];

        foreach ($this->listPermissions() as $permission) {
            $module = (string) ($permission['module_name'] ?? 'general');
            $groups[$module] ??= [
                'code' => $module,
                'label' => permission_module_label($module),
                'permissions' => [],
            ];
            $groups[$module]['permissions'][] = $permission;
        }

        $orderedCodes = ['dashboard', 'stock', 'kitchen', 'sales', 'cash', 'reports', 'menu', 'users', 'roles', 'incidents'];
        $ordered = [];

        foreach ($orderedCodes as $code) {
            if (!isset($groups[$code])) {
                continue;
            }
            $ordered[] = $groups[$code];
            unset($groups[$code]);
        }

        foreach ($groups as $group) {
            $ordered[] = $group;
        }

        return $ordered;
    }

    public function listUsersForRestaurant(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.full_name, u.email, u.status, u.role_id, r.name AS role_name, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = :restaurant_id
             ORDER BY u.full_name ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        $users = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['role_display_name'] = restaurant_role_label((string) ($user['role_code'] ?? ''));
        }
        unset($user);

        return $users;
    }

    public function createTenantRole(int $restaurantId, array $payload, array $actor): void
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Le nom du rôle est obligatoire.');
        }

        $code = $this->normalizeTenantRoleCode((string) ($payload['code'] ?? ''), $name);
        $this->assertTenantRoleCodeAvailable($restaurantId, $code);

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO roles (restaurant_id, name, code, description, scope, is_locked, status, created_at, updated_at)
             VALUES (:restaurant_id, :name, :code, :description, "tenant", 0, :status, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'name' => $name,
            'code' => $code,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'status' => (string) ($payload['status'] ?? 'active'),
        ]);

        $roleId = (int) $this->database->pdo()->lastInsertId();
        $this->syncPermissions($roleId, $restaurantId, array_map('intval', $payload['permission_ids'] ?? []), $actor);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'roles',
            'action_name' => 'tenant_role_created',
            'entity_type' => 'roles',
            'entity_id' => (string) $roleId,
            'new_values' => [
                'name' => $name,
                'code' => $code,
                'permission_ids' => array_values(array_map('intval', $payload['permission_ids'] ?? [])),
            ],
            'justification' => 'Creation role dynamique restaurant',
        ]);
    }

    public function syncPermissions(int $roleId, int $restaurantId, array $permissionIds, array $actor): void
    {
        $role = $this->findAssignableRole($roleId, $restaurantId);
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare(
                'DELETE FROM role_permissions
                 WHERE role_id = :role_id AND restaurant_id = :restaurant_id'
            );
            $delete->execute([
                'role_id' => $roleId,
                'restaurant_id' => $restaurantId,
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, restaurant_id, effect, created_at, updated_at)
                 VALUES (:role_id, :permission_id, :restaurant_id, "allow", NOW(), NOW())'
            );

            foreach (array_unique($permissionIds) as $permissionId) {
                $insert->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'restaurant_id' => $restaurantId,
                ]);
            }

            $pdo->commit();
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'roles',
                'action_name' => ($role['scope'] ?? 'tenant') === 'system'
                    ? 'system_role_permissions_overridden'
                    : 'tenant_role_permissions_synced',
                'entity_type' => 'roles',
                'entity_id' => (string) $roleId,
                'new_values' => ['permission_ids' => array_values($permissionIds)],
                'justification' => ($role['scope'] ?? 'tenant') === 'system'
                    ? 'Mise a jour locale des permissions du role predefini pour ce restaurant'
                    : 'Mise a jour permissions role dynamique',
            ]);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function changeRoleStatus(int $roleId, int $restaurantId, string $status, array $actor): void
    {
        $role = $this->assertRoleBelongsToRestaurant($roleId, $restaurantId);
        if ((int) $role['is_locked'] === 1) {
            throw new \RuntimeException('Ce role verrouille ne peut pas etre desactive ici.');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE roles
             SET status = :status, updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $roleId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    public function assignUserRole(int $userId, int $restaurantId, int $roleId, array $actor): void
    {
        $user = $this->findRestaurantUser($userId, $restaurantId);
        if ($user === null) {
            throw new \RuntimeException('Utilisateur introuvable pour ce restaurant.');
        }

        $this->findAssignableRole($roleId, $restaurantId);

        $statement = $this->database->pdo()->prepare(
            'UPDATE users
             SET role_id = :role_id, updated_at = NOW()
             WHERE id = :id AND restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'role_id' => $roleId,
            'id' => $userId,
            'restaurant_id' => $restaurantId,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'roles',
            'action_name' => 'tenant_user_role_assigned',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => ['role_id' => $roleId],
            'justification' => 'Affectation personne a un role dynamique',
        ]);
    }

    public function assertAssignableRoleForRestaurant(int $roleId, ?int $restaurantId): array
    {
        if ($restaurantId === null || $restaurantId <= 0) {
            $statement = $this->database->pdo()->prepare(
                'SELECT *
                 FROM roles
                 WHERE id = :id
                   AND scope = "system"
                   AND status = "active"
                 LIMIT 1'
            );
            $statement->execute(['id' => $roleId]);
            $role = $statement->fetch(PDO::FETCH_ASSOC);

            if ($role === false) {
                throw new \RuntimeException('Role non autorise pour un compte plateforme.');
            }

            return $role;
        }

        return $this->findAssignableRole($roleId, $restaurantId);
    }

    public function userActivitySnapshot(int $restaurantId, int $userId): array
    {
        $user = $this->findRestaurantUser($userId, $restaurantId);
        if ($user === null) {
            throw new \RuntimeException('Utilisateur introuvable pour ce restaurant.');
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.full_name, u.email, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $userId,
            'restaurant_id' => $restaurantId,
        ]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $sales = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS sales_count, COALESCE(SUM(total_amount), 0) AS sales_total
             FROM sales
             WHERE restaurant_id = :restaurant_id
               AND server_id = :user_id'
        );
        $sales->execute(['restaurant_id' => $restaurantId, 'user_id' => $userId]);

        $requests = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM server_requests WHERE restaurant_id = :restaurant_id AND server_id = :user_id'
        );
        $requests->execute(['restaurant_id' => $restaurantId, 'user_id' => $userId]);

        $cases = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM operation_cases WHERE restaurant_id = :restaurant_id AND (signaled_by = :user_id OR technical_confirmed_by = :user_id OR decided_by = :user_id)'
        );
        $cases->execute(['restaurant_id' => $restaurantId, 'user_id' => $userId]);

        $losses = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS losses_count, COALESCE(SUM(amount), 0) AS losses_total
             FROM losses
             WHERE restaurant_id = :restaurant_id
               AND created_by = :user_id'
        );
        $losses->execute(['restaurant_id' => $restaurantId, 'user_id' => $userId]);

        $audits = $this->database->pdo()->prepare(
            'SELECT module_name, action_name, created_at
             FROM audit_logs
             WHERE restaurant_id = :restaurant_id
               AND user_id = :user_id
             ORDER BY id DESC
             LIMIT 40'
        );
        $audits->execute(['restaurant_id' => $restaurantId, 'user_id' => $userId]);

        return [
            'user' => $profile,
            'sales' => $sales->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'sales_total' => 0],
            'server_requests_count' => (int) $requests->fetchColumn(),
            'operation_cases_count' => (int) $cases->fetchColumn(),
            'losses' => $losses->fetch(PDO::FETCH_ASSOC) ?: ['losses_count' => 0, 'losses_total' => 0],
            'audits' => $audits->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function customerRoleId(): int
    {
        $statement = $this->database->pdo()->query(
            'SELECT id FROM roles WHERE code = "customer" AND scope = "system" LIMIT 1'
        );
        $roleId = (int) $statement->fetchColumn();

        if ($roleId <= 0) {
            throw new \RuntimeException('Le role client est introuvable.');
        }

        return $roleId;
    }

    private function permissionLabelsForIds(array $permissionIds): array
    {
        if ($permissionIds === []) {
            return [];
        }

        $labels = [];
        foreach ($this->listPermissions() as $permission) {
            if (!in_array((int) $permission['id'], $permissionIds, true)) {
                continue;
            }

            $labels[] = (string) $permission['label'];
        }

        sort($labels);

        return $labels;
    }

    private function assertRoleBelongsToRestaurant(int $roleId, int $restaurantId, bool $allowOnlyTenant = true): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM roles
             WHERE id = :id
               AND scope = "tenant"
               AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $roleId,
            'restaurant_id' => $restaurantId,
        ]);
        $role = $statement->fetch(PDO::FETCH_ASSOC);

        if ($role === false) {
            throw new \RuntimeException('Role introuvable pour ce restaurant.');
        }

        if ($allowOnlyTenant && ($role['scope'] ?? null) !== 'tenant') {
            throw new \RuntimeException('Role non autorise.');
        }

        return $role;
    }

    private function findAssignableRole(int $roleId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM roles
             WHERE id = :id
               AND status = "active"
               AND (
                    (scope = "system" AND code != "super_admin")
                    OR (scope = "tenant" AND restaurant_id = :restaurant_id)
               )
             LIMIT 1'
        );
        $statement->execute([
            'id' => $roleId,
            'restaurant_id' => $restaurantId,
        ]);
        $role = $statement->fetch(PDO::FETCH_ASSOC);

        if ($role === false) {
            throw new \RuntimeException('Role non attribuable pour ce restaurant.');
        }

        return $role;
    }

    private function findRestaurantUser(int $userId, int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, restaurant_id, role_id
             FROM users
             WHERE id = :id AND restaurant_id = :restaurant_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $userId,
            'restaurant_id' => $restaurantId,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }

    private function normalizeTenantRoleCode(string $rawCode, string $fallbackName): string
    {
        $source = trim($rawCode) !== '' ? $rawCode : $fallbackName;
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $source));
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            $normalized = 'role_personnalise';
        }

        return substr($normalized, 0, 80);
    }

    private function assertTenantRoleCodeAvailable(int $restaurantId, string $code): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT COUNT(*) FROM roles WHERE restaurant_id = :restaurant_id AND code = :code'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'code' => $code,
        ]);

        if ((int) $statement->fetchColumn() > 0) {
            throw new \RuntimeException('Un role avec ce code existe deja dans ce restaurant.');
        }
    }
}
