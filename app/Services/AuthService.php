<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AuthService
{
    private ?string $lastLoginFailureMessage = null;

    public function __construct(private readonly Database $database)
    {
    }

    public function attemptWebLogin(string $email, string $password): ?array
    {
        $this->lastLoginFailureMessage = null;
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.password_hash, u.status,
                    u.disabled_at, u.banned_at, u.archived_at, u.status_reason,
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
            $this->lastLoginFailureMessage = $this->accountRestrictionMessage($user, true);
            return null;
        }

        if (
            $user['restaurant_id'] !== null
            && in_array($user['tenant_status'], ['archived'], true)
        ) {
            $this->lastLoginFailureMessage = 'Ce restaurant est archive. Veuillez contacter votre responsable.';
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

    public function lastLoginFailureMessage(): ?string
    {
        return $this->lastLoginFailureMessage;
    }

    public function refreshSessionUser(array $sessionUser): ?array
    {
        $this->lastLoginFailureMessage = null;
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $user = $this->findLoginUserById($userId);
        if ($user === null) {
            $this->lastLoginFailureMessage = 'Votre session a ete fermee car votre compte est introuvable.';
            return null;
        }

        if ($user['status'] !== 'active') {
            $this->lastLoginFailureMessage = $this->accountRestrictionMessage($user, false);
            return null;
        }

        if ($user['restaurant_id'] !== null && in_array($user['tenant_status'], ['archived'], true)) {
            $this->lastLoginFailureMessage = 'Votre session a ete fermee car ce restaurant est archive.';
            return null;
        }

        return $this->sessionPayload($user);
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

    private function findLoginUserById(int $userId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.restaurant_id, u.role_id, u.full_name, u.email, u.status,
                    u.disabled_at, u.banned_at, u.archived_at, u.status_reason,
                    r.code AS role_code, t.status AS tenant_status, t.name AS restaurant_name, t.slug AS restaurant_slug,
                    t.restaurant_code, t.subscription_status, t.subscription_payment_status
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN restaurants t ON t.id = u.restaurant_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    private function sessionPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'restaurant_id' => $user['restaurant_id'] !== null ? (int) $user['restaurant_id'] : null,
            'role_id' => (int) $user['role_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role_code' => $user['role_code'],
            'scope' => $user['restaurant_id'] === null ? 'super_admin' : 'tenant',
            'restaurant_name' => $user['restaurant_name'] ?? null,
            'restaurant_slug' => $user['restaurant_slug'] ?? null,
            'restaurant_code' => $user['restaurant_code'] ?? null,
            'subscription_status' => $user['subscription_status'] ?? null,
            'subscription_payment_status' => $user['subscription_payment_status'] ?? null,
            'restaurant_status' => $user['tenant_status'] ?? null,
        ];
    }

    private function accountRestrictionMessage(array $user, bool $duringLogin): string
    {
        $status = (string) ($user['status'] ?? '');
        $audit = $this->latestUserStatusAudit((int) ($user['id'] ?? 0));
        $actor = named_actor_label($audit['actor_name'] ?? null, $audit['actor_role_code'] ?? null);
        $reason = trim((string) ($user['status_reason'] ?? ''));

        if ($status === 'banned') {
            $blockedAt = (string) (($user['banned_at'] ?? '') ?: ($audit['created_at'] ?? '') ?: ($user['updated_at'] ?? ''));
            $elapsed = $this->humanElapsedSince($blockedAt);
            $prefix = $duringLogin ? 'Vous ne pouvez pas vous connecter.' : 'Votre session a ete bloquee.';

            return trim($prefix . ' Votre connexion a ete bloquee il y a ' . $elapsed . ' par ' . $actor . '. Veuillez voir votre chef hierarchique avant de vous connecter a nouveau.');
        }

        if ($status === 'disabled') {
            $since = (string) (($user['disabled_at'] ?? '') ?: ($audit['created_at'] ?? '') ?: ($user['updated_at'] ?? ''));
            $message = 'Vous etes suspendu depuis ' . $this->humanDateTime($since) . ' par ' . $actor . '.';
            if ($reason !== '') {
                $message .= ' Motif : ' . $reason . '.';
            }

            return $message . ' Veuillez voir votre chef hierarchique.';
        }

        if ($status === 'archived') {
            return 'Ce compte est archive. Veuillez voir votre chef hierarchique.';
        }

        return 'Identifiants invalides ou compte inactif.';
    }

    private function latestUserStatusAudit(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT actor_name, actor_role_code, created_at
             FROM audit_logs
             WHERE module_name = "users"
               AND action_name = "user_status_changed"
               AND entity_type = "users"
               AND entity_id = :entity_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['entity_id' => (string) $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: [];
    }

    private function humanElapsedSince(string $dateTime): string
    {
        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return 'quelques instants';
        }

        $seconds = max(0, time() - $timestamp);
        if ($seconds < 60) {
            return 'quelques instants';
        }
        if ($seconds < 3600) {
            $minutes = max(1, (int) floor($seconds / 60));
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($seconds < 86400) {
            $hours = max(1, (int) floor($seconds / 3600));
            return $hours . ' heure' . ($hours > 1 ? 's' : '');
        }

        $days = max(1, (int) floor($seconds / 86400));
        return $days . ' jour' . ($days > 1 ? 's' : '');
    }

    private function humanDateTime(string $dateTime): string
    {
        $timestamp = strtotime($dateTime);
        if ($timestamp === false) {
            return 'une date non precisee';
        }

        return date('d/m/Y H:i', $timestamp);
    }
}
