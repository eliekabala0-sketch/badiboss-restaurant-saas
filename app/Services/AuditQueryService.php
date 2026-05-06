<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AuditQueryService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function search(array $filters): array
    {
        $sql = 'SELECT a.*, r.name AS restaurant_name, u.full_name AS user_name
                FROM audit_logs a
                LEFT JOIN restaurants r ON r.id = a.restaurant_id
                LEFT JOIN users u ON u.id = a.user_id
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['restaurant_id'])) {
            $sql .= ' AND a.restaurant_id = :restaurant_id';
            $params['restaurant_id'] = (int) $filters['restaurant_id'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= ' AND a.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['module_name'])) {
            $sql .= ' AND a.module_name = :module_name';
            $params['module_name'] = $filters['module_name'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= ' AND DATE(a.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND DATE(a.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        if (!empty($filters['action_name'])) {
            $sql .= ' AND a.action_name = :action_name';
            $params['action_name'] = (string) $filters['action_name'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (
                a.entity_id LIKE :q
                OR a.justification LIKE :q
                OR a.new_values_json LIKE :q
                OR a.actor_name LIKE :q
            )';
            $params['q'] = '%' . $q . '%';
        }

        $sql .= ' ORDER BY a.id DESC LIMIT 200';
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
