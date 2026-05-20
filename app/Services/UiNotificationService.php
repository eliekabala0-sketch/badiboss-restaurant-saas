<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class UiNotificationService
{
    private bool $schemaEnsured = false;

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $pdo = $this->database->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ui_notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurant_id BIGINT UNSIGNED NOT NULL,
                recipient_user_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                role_code VARCHAR(80) NULL,
                event_code VARCHAR(120) NOT NULL,
                level VARCHAR(20) NOT NULL DEFAULT "info",
                title VARCHAR(190) NOT NULL,
                message VARCHAR(255) NOT NULL,
                target_url VARCHAR(255) NULL,
                event_key VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                UNIQUE KEY uq_ui_notification_recipient_event (recipient_user_id, event_key),
                KEY idx_ui_notifications_recipient (recipient_user_id, id),
                KEY idx_ui_notifications_restaurant (restaurant_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->schemaEnsured = true;
    }

    /**
     * @param list<int> $userIds
     */
    public function queueForUsers(
        int $restaurantId,
        array $userIds,
        ?array $actor,
        string $eventCode,
        string $level,
        string $title,
        string $message,
        ?string $targetUrl,
        string $eventKey,
        ?string $roleCode = null,
        int $ttlHours = 24
    ): void {
        $this->ensureSchema();
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return;
        }

        $actorId = (int) (($actor['id'] ?? 0) ?: 0);
        if ($actorId > 0) {
            $userIds = array_values(array_filter($userIds, static fn (int $id): bool => $id !== $actorId));
        }
        if ($userIds === []) {
            return;
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO ui_notifications
            (restaurant_id, recipient_user_id, actor_user_id, role_code, event_code, level, title, message, target_url, event_key, created_at, expires_at)
             VALUES
            (:restaurant_id, :recipient_user_id, :actor_user_id, :role_code, :event_code, :level, :title, :message, :target_url, :event_key, NOW(), DATE_ADD(NOW(), INTERVAL :ttl_hours HOUR))
             ON DUPLICATE KEY UPDATE
                actor_user_id = VALUES(actor_user_id),
                role_code = VALUES(role_code),
                level = VALUES(level),
                title = VALUES(title),
                message = VALUES(message),
                target_url = VALUES(target_url),
                expires_at = VALUES(expires_at)'
        );

        foreach ($userIds as $recipientUserId) {
            $statement->bindValue(':restaurant_id', $restaurantId, PDO::PARAM_INT);
            $statement->bindValue(':recipient_user_id', $recipientUserId, PDO::PARAM_INT);
            $statement->bindValue(':actor_user_id', $actorId > 0 ? $actorId : null, $actorId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $statement->bindValue(':role_code', $roleCode, $roleCode !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $statement->bindValue(':event_code', $eventCode, PDO::PARAM_STR);
            $statement->bindValue(':level', $level, PDO::PARAM_STR);
            $statement->bindValue(':title', $title, PDO::PARAM_STR);
            $statement->bindValue(':message', $message, PDO::PARAM_STR);
            $statement->bindValue(':target_url', $targetUrl, $targetUrl !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $statement->bindValue(':event_key', $eventKey, PDO::PARAM_STR);
            $statement->bindValue(':ttl_hours', max(1, $ttlHours), PDO::PARAM_INT);
            $statement->execute();
        }
    }

    /**
     * @param list<string> $roleCodes
     */
    public function queueForRoles(
        int $restaurantId,
        array $roleCodes,
        ?array $actor,
        string $eventCode,
        string $level,
        string $title,
        string $message,
        ?string $targetUrl,
        string $eventKey,
        int $ttlHours = 24
    ): void {
        $roleCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            $roleCodes
        ), static fn (string $code): bool => $code !== '')));
        if ($roleCodes === []) {
            return;
        }

        $usersByRole = $this->activeUsersByRoleCodes($restaurantId, $roleCodes);
        foreach ($roleCodes as $roleCode) {
            $userIds = array_map(
                static fn (array $row): int => (int) ($row['id'] ?? 0),
                $usersByRole[$roleCode] ?? []
            );
            $this->queueForUsers(
                $restaurantId,
                $userIds,
                $actor,
                $eventCode,
                $level,
                $title,
                $message,
                $targetUrl,
                $eventKey . ':role:' . $roleCode,
                $roleCode,
                $ttlHours
            );
        }
    }

    public function listForUser(int $restaurantId, int $userId, int $sinceId = 0, int $limit = 12): array
    {
        $this->ensureSchema();
        if ($restaurantId <= 0 || $userId <= 0) {
            return [];
        }

        $statement = $this->database->pdo()->prepare(
            'SELECT id, event_code, level, title, message, target_url, event_key, created_at
             FROM ui_notifications
             WHERE restaurant_id = :restaurant_id
               AND recipient_user_id = :recipient_user_id
               AND id > :since_id
               AND (expires_at IS NULL OR expires_at >= NOW())
             ORDER BY id ASC
             LIMIT ' . max(1, min(30, $limit))
        );
        $statement->bindValue(':restaurant_id', $restaurantId, PDO::PARAM_INT);
        $statement->bindValue(':recipient_user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':since_id', max(0, $sinceId), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<string> $roleCodes
     * @return array<string, list<array<string, mixed>>>
     */
    private function activeUsersByRoleCodes(int $restaurantId, array $roleCodes): array
    {
        if ($restaurantId <= 0 || $roleCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = ?
               AND u.status = "active"
               AND r.code IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$restaurantId], $roleCodes));

        $grouped = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roleCode = (string) ($row['role_code'] ?? '');
            if ($roleCode === '') {
                continue;
            }
            $grouped[$roleCode] ??= [];
            $grouped[$roleCode][] = $row;
        }

        return $grouped;
    }
}
