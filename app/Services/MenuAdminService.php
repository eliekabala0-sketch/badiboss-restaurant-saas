<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class MenuAdminService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listCategories(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM menu_categories
             WHERE restaurant_id = :restaurant_id
             ORDER BY display_order ASC, id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listItems(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT mi.*, mc.name AS category_name
             FROM menu_items mi
             INNER JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.restaurant_id = :restaurant_id
             ORDER BY mi.display_order ASC, mi.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listPublicItems(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT mi.*, mc.name AS category_name
             FROM menu_items mi
             INNER JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.restaurant_id = :restaurant_id
               AND mc.status = "active"
               AND mi.status = "active"
               AND mi.is_available = 1
             ORDER BY mi.display_order ASC, mi.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCategory(int $restaurantId, array $payload, array $actor): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO menu_categories
            (restaurant_id, name, slug, description, display_order, status, created_at, updated_at)
             VALUES
            (:restaurant_id, :name, :slug, :description, :display_order, :status, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'name' => trim((string) $payload['name']),
            'slug' => trim((string) $payload['slug']),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'display_order' => (int) ($payload['display_order'] ?? 0),
            'status' => $payload['status'] ?? 'active',
        ]);

        $categoryId = (int) $this->database->pdo()->lastInsertId();
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'menu',
            'action_name' => 'menu_category_created',
            'entity_type' => 'menu_categories',
            'entity_id' => (string) $categoryId,
            'new_values' => $payload,
            'justification' => 'Administrative menu category creation',
        ]);
    }

    public function updateCategory(int $categoryId, array $payload, array $actor): void
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM menu_categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            return;
        }

        $update = $this->database->pdo()->prepare(
            'UPDATE menu_categories
             SET name = :name,
                 slug = :slug,
                 description = :description,
                 display_order = :display_order,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'id' => $categoryId,
            'name' => trim((string) $payload['name']),
            'slug' => trim((string) $payload['slug']),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'display_order' => (int) ($payload['display_order'] ?? 0),
            'status' => $payload['status'] ?? 'active',
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => (int) $current['restaurant_id'],
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'menu',
            'action_name' => 'menu_category_updated',
            'entity_type' => 'menu_categories',
            'entity_id' => (string) $categoryId,
            'old_values' => $current,
            'new_values' => $payload,
            'justification' => 'Administrative menu category update',
        ]);
    }

    public function createItem(int $restaurantId, array $payload, array $actor): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO menu_items
            (restaurant_id, category_id, name, slug, description, image_url, price, status, is_available,
             display_order, available_dine_in, available_takeaway, available_delivery, created_at, updated_at)
             VALUES
            (:restaurant_id, :category_id, :name, :slug, :description, :image_url, :price, :status, :is_available,
             :display_order, :available_dine_in, :available_takeaway, :available_delivery, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'category_id' => (int) $payload['category_id'],
            'name' => trim((string) $payload['name']),
            'slug' => trim((string) $payload['slug']),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'image_url' => trim((string) ($payload['image_url'] ?? '')) ?: null,
            'price' => (float) $payload['price'],
            'status' => $payload['status'] ?? 'active',
            'is_available' => isset($payload['is_available']) ? 1 : 0,
            'display_order' => (int) ($payload['display_order'] ?? 0),
            'available_dine_in' => isset($payload['available_dine_in']) ? 1 : 0,
            'available_takeaway' => isset($payload['available_takeaway']) ? 1 : 0,
            'available_delivery' => isset($payload['available_delivery']) ? 1 : 0,
        ]);

        $itemId = (int) $this->database->pdo()->lastInsertId();
        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'menu',
            'action_name' => 'menu_item_created',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $itemId,
            'new_values' => $payload,
            'justification' => 'Administrative menu item creation',
        ]);
    }

    public function updateItem(int $itemId, array $payload, array $actor): void
    {
        $current = $this->findItem($itemId);
        if ($current === null) {
            return;
        }

        if (($actor['scope'] ?? null) !== 'super_admin' && (int) $current['restaurant_id'] !== (int) ($actor['restaurant_id'] ?? 0)) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE menu_items
             SET category_id = :category_id,
                 name = :name,
                 slug = :slug,
                 description = :description,
                 image_url = :image_url,
                 price = :price,
                 status = :status,
                 is_available = :is_available,
                 display_order = :display_order,
                 available_dine_in = :available_dine_in,
                 available_takeaway = :available_takeaway,
                 available_delivery = :available_delivery,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $itemId,
            'category_id' => (int) $payload['category_id'],
            'name' => trim((string) $payload['name']),
            'slug' => trim((string) $payload['slug']),
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'image_url' => trim((string) ($payload['image_url'] ?? '')) ?: null,
            'price' => (float) $payload['price'],
            'status' => $payload['status'] ?? 'active',
            'is_available' => isset($payload['is_available']) ? 1 : 0,
            'display_order' => (int) ($payload['display_order'] ?? 0),
            'available_dine_in' => isset($payload['available_dine_in']) ? 1 : 0,
            'available_takeaway' => isset($payload['available_takeaway']) ? 1 : 0,
            'available_delivery' => isset($payload['available_delivery']) ? 1 : 0,
        ]);

        $oldPrice = (float) ($current['price'] ?? 0);
        $newPrice = (float) $payload['price'];
        $actionName = abs($oldPrice - $newPrice) > 0.00001 ? 'menu_price_updated' : 'menu_item_updated';
        $justification = abs($oldPrice - $newPrice) > 0.00001
            ? 'Prix modifie sans recalcul des ventes historiques'
            : 'Mise a jour controlee du menu';

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => (int) $current['restaurant_id'],
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'menu',
            'action_name' => $actionName,
            'entity_type' => 'menu_items',
            'entity_id' => (string) $itemId,
            'old_values' => $current,
            'new_values' => $payload,
            'justification' => $justification,
        ]);
    }

    public function markItemStatus(int $itemId, string $status, array $actor): void
    {
        $current = $this->findItem($itemId);
        if ($current === null) {
            return;
        }

        if (($actor['scope'] ?? null) !== 'super_admin' && (int) $current['restaurant_id'] !== (int) ($actor['restaurant_id'] ?? 0)) {
            throw new \RuntimeException('Article menu hors perimetre restaurant.');
        }

        $status = in_array($status, ['active', 'out_of_stock', 'hidden', 'archived'], true) ? $status : 'active';
        $statement = $this->database->pdo()->prepare(
            'UPDATE menu_items
             SET status = :status,
                 is_available = CASE WHEN :status = "active" THEN 1 ELSE 0 END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $itemId,
            'status' => $status,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => (int) $current['restaurant_id'],
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'menu',
            'action_name' => 'menu_item_status_changed',
            'entity_type' => 'menu_items',
            'entity_id' => (string) $itemId,
            'old_values' => ['status' => $current['status']],
            'new_values' => ['status' => $status],
            'justification' => 'Menu availability update',
        ]);
    }

    public function findItem(int $itemId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $itemId]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);

        return $item ?: null;
    }

    public function recentAudits(int $restaurantId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $statement = $this->database->pdo()->prepare(
            'SELECT *
             FROM audit_logs
             WHERE restaurant_id = :restaurant_id
               AND module_name = "menu"
               AND action_name IN ("menu_item_created", "menu_item_updated", "menu_price_updated", "menu_item_status_changed")
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $row['old_values'] = $this->decodeAuditJson($row['old_values_json'] ?? null);
            $row['new_values'] = $this->decodeAuditJson($row['new_values_json'] ?? null);

            return $row;
        }, $rows);
    }

    private function decodeAuditJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
