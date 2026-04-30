<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AuthService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function attemptWebLogin(string $email, string $password): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.password_hash, u.status,
                    r.code AS role_code, t.status AS tenant_status, t.name AS restaurant_name, t.slug AS restaurant_slug,
                    t.restaurant_code, t.subscription_status, t.subscription_payment_status
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN restaurants t ON t.id = u.restaurant_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        if (
            $user['restaurant_id'] !== null
            && in_array($user['tenant_status'], ['archived'], true)
        ) {
            return null;
        }

        $updateLogin = $this->database->pdo()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $updateLogin->execute(['id' => (int) $user['id']]);

        return [
            'id' => (int) $user['id'],
            'restaurant_id' => $user['restaurant_id'] !== null ? (int) $user['restaurant_id'] : null,
            'role_id' => (int) $user['role_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role_code' => $user['role_code'],
            'scope' => $user['restaurant_id'] === null ? 'super_admin' : 'tenant',
            'restaurant_name' => $user['restaurant_name'],
            'restaurant_slug' => $user['restaurant_slug'],
            'restaurant_code' => $user['restaurant_code'],
            'subscription_status' => $user['subscription_status'],
            'subscription_payment_status' => $user['subscription_payment_status'],
            'restaurant_status' => $user['tenant_status'],
        ];
    }

    public function issueApiToken(string $email, string $password): ?array
    {
        $user = $this->attemptWebLogin($email, $password);

        if ($user === null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . (int) config('app.api_token_ttl_hours', 24) . ' hours'));

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO api_tokens (user_id, restaurant_id, token, expires_at, created_at)
             VALUES (:user_id, :restaurant_id, :token, :expires_at, NOW())'
        );
        $statement->execute([
            'user_id' => $user['id'],
            'restaurant_id' => $user['restaurant_id'],
            'token' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return [
            'access_token' => $token,
            'expires_at' => $expiresAt,
            'user' => $user,
        ];
    }

    public function userFromToken(string $plainToken): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.status,
                    r.code AS role_code, t.status AS tenant_status, at.expires_at, at.revoked_at
             FROM api_tokens at
             INNER JOIN users u ON u.id = at.user_id
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN restaurants t ON t.id = u.restaurant_id
             WHERE at.token = :token
             LIMIT 1'
        );
        $statement->execute([
            'token' => hash('sha256', $plainToken),
        ]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['revoked_at'] !== null || strtotime($user['expires_at']) < time()) {
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        if ($user['restaurant_id'] !== null && in_array($user['tenant_status'], ['archived'], true)) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'restaurant_id' => $user['restaurant_id'] !== null ? (int) $user['restaurant_id'] : null,
            'role_id' => (int) $user['role_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role_code' => $user['role_code'],
            'scope' => $user['restaurant_id'] === null ? 'super_admin' : 'tenant',
            'restaurant_status' => $user['tenant_status'],
        ];
    }
}
