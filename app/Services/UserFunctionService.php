<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class UserFunctionService
{
    public const OPERATIONAL_CODES = ['kitchen', 'cashier_accountant', 'stock_manager', 'cashier_server'];

    public function __construct(private readonly Database $database)
    {
    }

    public function rolesForUser(int $userId, int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.role_id, r.code, r.name FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.restaurant_id = :restaurant_id'
        );
        $statement->execute(['id' => $userId, 'restaurant_id' => $restaurantId]);
        $primary = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$primary) {
            return [];
        }
        $roles = [[
            'id' => (int) $primary['role_id'],
            'code' => $primary['code'],
            'name' => $primary['name'],
        ]];
        $statement = $this->database->pdo()->prepare(
            'SELECT setting_value FROM settings WHERE restaurant_id = :restaurant_id AND setting_key = :setting_key'
        );
        $statement->execute(['restaurant_id' => $restaurantId, 'setting_key' => 'user_additional_roles_' . $userId]);
        $ids = json_decode((string) ($statement->fetchColumn() ?: '[]'), true);
        if (!is_array($ids) || $ids === []) {
            return $roles;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $statement = $this->database->pdo()->prepare(
            'SELECT id, code, name FROM roles WHERE scope = "system" AND status = "active"
             AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $role) {
            if ((int) $role['id'] !== (int) $primary['role_id']
                && in_array($role['code'], self::OPERATIONAL_CODES, true)) {
                $role['id'] = (int) $role['id'];
                $roles[] = $role;
            }
        }
        return $roles;
    }

    public function applySessionRole(array $freshUser, array $previousUser): array
    {
        $restaurantId = (int) ($freshUser['restaurant_id'] ?? 0);
        if ($restaurantId <= 0) {
            return $freshUser;
        }
        $roles = $this->rolesForUser((int) $freshUser['id'], $restaurantId);
        $freshUser['primary_role_id'] = (int) $freshUser['role_id'];
        $freshUser['available_roles'] = $roles;
        // A changed primary job invalidates an old session selection.
        $selectedId = (int) ($previousUser['primary_role_id'] ?? 0) === $freshUser['primary_role_id']
            ? (int) ($previousUser['role_id'] ?? 0)
            : $freshUser['primary_role_id'];
        foreach ($roles as $role) {
            if ((int) $role['id'] === $selectedId) {
                $freshUser['role_id'] = (int) $role['id'];
                $freshUser['role_code'] = $role['code'];
                break;
            }
        }
        return $freshUser;
    }

    public function selectRole(array $user, int $roleId): array
    {
        $roles = $this->rolesForUser((int) $user['id'], (int) ($user['restaurant_id'] ?? 0));
        foreach ($roles as $role) {
            if ((int) $role['id'] === $roleId) {
                $user['primary_role_id'] = (int) $roles[0]['id'];
                $user['available_roles'] = $roles;
                $user['role_id'] = $roleId;
                $user['role_code'] = $role['code'];
                return $user;
            }
        }
        throw new \RuntimeException('Cette fonction ne vous est pas attribuee.');
    }

    public function saveAdditionalRoles(int $userId, int $restaurantId, array $roleIds, array $actor): void
    {
        if ((int) ($actor['restaurant_id'] ?? 0) !== $restaurantId
            || !in_array($actor['role_code'] ?? '', ['owner', 'manager'], true)) {
            throw new \RuntimeException('Seuls le proprietaire et le gerant du restaurant peuvent affecter ces fonctions.');
        }
        $current = $this->rolesForUser($userId, $restaurantId);
        if ($current === []) {
            throw new \RuntimeException('Utilisateur introuvable pour ce restaurant.');
        }
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        $ids = array_values(array_diff($ids, [(int) $current[0]['id']]));
        foreach ($ids as $id) {
            $role = Container::getInstance()->get('roleAdmin')->assertAssignableRoleForRestaurant($id, $restaurantId);
            if (($role['scope'] ?? '') !== 'system' || !in_array($role['code'], self::OPERATIONAL_CODES, true)) {
                throw new \RuntimeException('Fonction supplementaire non autorisee.');
            }
        }
        sort($ids);
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
                 VALUES (:restaurant_id, :setting_key, :setting_value, "json", 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            );
            $statement->execute([
                'restaurant_id' => $restaurantId,
                'setting_key' => 'user_additional_roles_' . $userId,
                'setting_value' => json_encode($ids, JSON_THROW_ON_ERROR),
            ]);
            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'],
                'actor_name' => $actor['full_name'],
                'actor_role_code' => $actor['role_code'],
                'module_name' => 'roles',
                'action_name' => 'user_additional_roles_updated',
                'entity_type' => 'users',
                'entity_id' => (string) $userId,
                'old_values' => ['role_ids' => array_column(array_slice($current, 1), 'id')],
                'new_values' => ['role_ids' => $ids],
                'justification' => 'Affectation de fonctions supplementaires a une personne',
            ]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
