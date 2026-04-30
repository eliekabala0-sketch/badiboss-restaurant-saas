<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class UserAdminService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listUsers(?int $restaurantId = null): array
    {
        $sql = 'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.phone, u.status, u.must_change_password,
                       r.name AS role_name, r.code AS role_code, t.name AS restaurant_name, t.slug AS restaurant_slug
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                LEFT JOIN restaurants t ON t.id = u.restaurant_id';

        $params = [];
        if ($restaurantId !== null) {
            $sql .= ' WHERE u.restaurant_id = :restaurant_id';
            $params['restaurant_id'] = $restaurantId;
        }

        $sql .= ' ORDER BY u.id DESC';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        $users = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['role_display_name'] = restaurant_role_label((string) ($user['role_code'] ?? ''));
        }
        unset($user);

        return $users;
    }

    public function createUser(array $payload, array $actor): void
    {
        $restaurantId = $this->normalizeRestaurantId($payload['restaurant_id'] ?? null);
        $role = Container::getInstance()->get('roleAdmin')->assertAssignableRoleForRestaurant((int) $payload['role_id'], $restaurantId);

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users
            (restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, created_at, updated_at)
             VALUES
            (:restaurant_id, :role_id, :full_name, :email, :phone, :password_hash, :status, :must_change_password, NOW(), NOW())'
        );

        $statement->execute([
            'restaurant_id' => $restaurantId,
            'role_id' => (int) $role['id'],
            'full_name' => trim((string) $payload['full_name']),
            'email' => trim((string) $payload['email']),
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
            'status' => $payload['status'] ?? 'active',
            'must_change_password' => isset($payload['must_change_password']) ? 1 : 0,
        ]);

        $userId = (int) $this->database->pdo()->lastInsertId();
        $roleCode = (string) ($role['code'] ?? '');

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => 'user_created',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => [
                'full_name' => $payload['full_name'],
                'email' => $payload['email'],
                'restaurant_id' => $restaurantId,
                'role_id' => $role['id'],
                'role_code' => $roleCode,
            ],
            'justification' => 'Administrative user creation',
        ]);
    }

    public function updateUser(int $userId, array $payload, array $actor): void
    {
        $current = $this->findUser($userId);
        if ($current === null) {
            return;
        }

        $restaurantId = $this->normalizeRestaurantId($payload['restaurant_id'] ?? null);
        $role = Container::getInstance()->get('roleAdmin')->assertAssignableRoleForRestaurant((int) $payload['role_id'], $restaurantId);

        $statement = $this->database->pdo()->prepare(
            'UPDATE users
             SET restaurant_id = :restaurant_id,
                 role_id = :role_id,
                 full_name = :full_name,
                 email = :email,
                 phone = :phone,
                 must_change_password = :must_change_password,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'restaurant_id' => $restaurantId,
            'role_id' => (int) $role['id'],
            'full_name' => trim((string) $payload['full_name']),
            'email' => trim((string) $payload['email']),
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'must_change_password' => isset($payload['must_change_password']) ? 1 : 0,
        ]);

        if (!empty($payload['password'])) {
            $passwordStatement = $this->database->pdo()->prepare(
                'UPDATE users SET password_hash = :password_hash, must_change_password = 1, updated_at = NOW() WHERE id = :id'
            );
            $passwordStatement->execute([
                'id' => $userId,
                'password_hash' => password_hash((string) $payload['password'], PASSWORD_BCRYPT),
            ]);
        }

        $action = ((int) $current['role_id'] !== (int) $role['id']) ? 'user_role_changed' : 'user_updated';

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => $action,
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'old_values' => $current,
            'new_values' => array_merge($payload, [
                'restaurant_id' => $restaurantId,
                'role_id' => (int) $role['id'],
                'role_code' => (string) ($role['code'] ?? ''),
            ]),
            'justification' => 'Administrative user update',
        ]);
    }

    public function changeStatus(int $userId, string $status, array $actor): void
    {
        $current = $this->findUser($userId);
        if ($current === null) {
            return;
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE users
             SET status = :status,
                 banned_at = CASE WHEN :status = "banned" THEN NOW() ELSE banned_at END,
                 archived_at = CASE WHEN :status = "archived" THEN NOW() ELSE archived_at END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'status' => $status,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $current['restaurant_id'] !== null ? (int) $current['restaurant_id'] : null,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'users',
            'action_name' => 'user_status_changed',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'old_values' => ['status' => $current['status']],
            'new_values' => ['status' => $status],
            'justification' => 'Administrative user status change',
        ]);
    }

    public function registerPublicCustomer(array $restaurant, array $payload): int
    {
        $restaurantId = (int) ($restaurant['id'] ?? 0);
        if ($restaurantId <= 0) {
            throw new \RuntimeException('Restaurant introuvable.');
        }

        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($fullName === '' || $email === '' || $password === '') {
            throw new \RuntimeException('Nom, e-mail et mot de passe sont obligatoires.');
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users
            (restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, created_at, updated_at)
             VALUES
            (:restaurant_id, :role_id, :full_name, :email, :phone, :password_hash, "active", 0, NOW(), NOW())'
        );

        $statement->execute([
            'restaurant_id' => $restaurantId,
            'role_id' => Container::getInstance()->get('roleAdmin')->customerRoleId(),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);

        $userId = (int) $this->database->pdo()->lastInsertId();

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => null,
            'actor_name' => 'Portail public client',
            'actor_role_code' => 'customer',
            'module_name' => 'users',
            'action_name' => 'public_customer_registered',
            'entity_type' => 'users',
            'entity_id' => (string) $userId,
            'new_values' => [
                'restaurant_id' => $restaurantId,
                'email' => $email,
                'role_code' => 'customer',
            ],
            'justification' => 'Inscription client liee au restaurant',
        ]);

        return $userId;
    }

    public function findUser(int $userId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.*, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    private function normalizeRestaurantId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $restaurantId = (int) $value;

        return $restaurantId > 0 ? $restaurantId : null;
    }
}
