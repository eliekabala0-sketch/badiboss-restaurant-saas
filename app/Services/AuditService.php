<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function log(array $entry): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO audit_logs
            (restaurant_id, user_id, actor_name, actor_role_code, module_name, action_name, entity_type, entity_id,
             old_values_json, new_values_json, justification, ip_address, user_agent, created_at)
             VALUES
            (:restaurant_id, :user_id, :actor_name, :actor_role_code, :module_name, :action_name, :entity_type, :entity_id,
             :old_values_json, :new_values_json, :justification, :ip_address, :user_agent, NOW())'
        );

        $statement->execute([
            'restaurant_id' => $entry['restaurant_id'] ?? null,
            'user_id' => $entry['user_id'] ?? null,
            'actor_name' => $entry['actor_name'] ?? 'system',
            'actor_role_code' => $entry['actor_role_code'] ?? 'system',
            'module_name' => $entry['module_name'],
            'action_name' => $entry['action_name'],
            'entity_type' => $entry['entity_type'] ?? null,
            'entity_id' => $entry['entity_id'] ?? null,
            'old_values_json' => isset($entry['old_values']) ? json_encode($entry['old_values'], JSON_UNESCAPED_UNICODE) : null,
            'new_values_json' => isset($entry['new_values']) ? json_encode($entry['new_values'], JSON_UNESCAPED_UNICODE) : null,
            'justification' => $entry['justification'] ?? null,
            'ip_address' => $entry['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => $entry['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);
    }
}
