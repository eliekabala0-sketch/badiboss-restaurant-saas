<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class RestaurantAdminService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listRestaurants(): array
    {
        return $this->database->pdo()->query(
            'SELECT r.*, rb.public_name, rb.logo_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                    rb.accent_color, rb.cover_image_url, rb.web_subdomain, rb.custom_domain, rb.portal_title, rb.portal_tagline,
                    rb.welcome_text, sp.name AS plan_name
             FROM restaurants r
             LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
             LEFT JOIN subscription_plans sp ON sp.id = r.subscription_plan_id
             ORDER BY r.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listPlans(): array
    {
        return $this->database->pdo()->query(
            "SELECT id, name, code FROM subscription_plans WHERE status = 'active' ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listRoles(): array
    {
        $roles = $this->database->pdo()->query(
            "SELECT id, restaurant_id, name, code, scope FROM roles WHERE status = 'active' ORDER BY scope ASC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $role['display_name'] = restaurant_role_label((string) ($role['code'] ?? ''));
        }
        unset($role);

        return $roles;
    }

    public function findRestaurant(int $restaurantId): ?array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT r.*, rb.public_name, rb.logo_url, rb.favicon_url, rb.primary_color, rb.secondary_color,
                    rb.accent_color, rb.cover_image_url, rb.web_subdomain, rb.custom_domain, rb.app_display_name, rb.app_short_name,
                    rb.portal_title, rb.portal_tagline, rb.welcome_text, sp.name AS plan_name
             FROM restaurants r
             LEFT JOIN restaurant_branding rb ON rb.restaurant_id = r.id
             LEFT JOIN subscription_plans sp ON sp.id = r.subscription_plan_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $restaurantId]);
        $restaurant = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$restaurant) {
            return null;
        }

        $restaurant['settings'] = $this->listSettings($restaurantId);
        $restaurant['users'] = $this->listUsers($restaurantId);
        $restaurant['categories'] = $this->listCategories($restaurantId);
        $restaurant['items'] = $this->listItems($restaurantId);

        return $restaurant;
    }

    public function updateRestaurant(int $restaurantId, array $payload, array $actor): void
    {
        $current = $this->findRestaurant($restaurantId);
        if ($current === null) {
            return;
        }

        $payload = $this->normalizePayload($payload);

        $statement = $this->database->pdo()->prepare(
            'UPDATE restaurants
             SET name = :name,
                 slug = :slug,
                 restaurant_code = :restaurant_code,
                 legal_name = :legal_name,
                 support_email = :support_email,
                 phone = :phone,
                 country = :country,
                 city = :city,
                 address_line = :address_line,
                 timezone = :timezone,
                 currency_code = :currency_code,
                 access_url = :access_url,
                 download_url = :download_url,
                 subscription_plan_id = :subscription_plan_id,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $restaurantId,
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'restaurant_code' => $payload['restaurant_code'],
            'legal_name' => $payload['legal_name'],
            'support_email' => $payload['support_email'],
            'phone' => $payload['phone'],
            'country' => $payload['country'],
            'city' => $payload['city'],
            'address_line' => $payload['address_line'],
            'timezone' => $payload['timezone'],
            'currency_code' => $payload['currency_code'],
            'access_url' => restaurant_generated_access_url(['slug' => $payload['slug']]),
            'download_url' => $current['download_url'],
            'subscription_plan_id' => $payload['subscription_plan_id'],
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'tenant_management',
            'action_name' => 'restaurant_updated',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $restaurantId,
            'old_values' => $current,
            'new_values' => $payload,
            'justification' => 'Administrative restaurant update',
        ]);
    }

    public function updateBranding(int $restaurantId, array $payload, array $actor): void
    {
        $current = $this->findRestaurant($restaurantId);
        if ($current === null) {
            return;
        }

        $payload = $this->normalizePayload($payload);
        $visualDefaults = Container::getInstance()->get('platformSettings')->visualDefaults();

        $statement = $this->database->pdo()->prepare(
            'UPDATE restaurant_branding
             SET public_name = :public_name,
                 logo_url = :logo_url,
                 cover_image_url = :cover_image_url,
                 favicon_url = :favicon_url,
                 primary_color = :primary_color,
                 secondary_color = :secondary_color,
                 accent_color = :accent_color,
                 web_subdomain = :web_subdomain,
                 custom_domain = :custom_domain,
                 app_display_name = :app_display_name,
                 app_short_name = :app_short_name,
                 portal_title = :portal_title,
                 portal_tagline = :portal_tagline,
                 welcome_text = :welcome_text,
                 updated_at = NOW()
             WHERE restaurant_id = :restaurant_id'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'public_name' => $payload['public_name'] ?? $current['public_name'],
            'logo_url' => $payload['logo_url'],
            'cover_image_url' => $payload['cover_image_url'],
            'favicon_url' => $payload['favicon_url'],
            'primary_color' => normalize_hex_color((string) ($payload['primary_color'] ?? ''), (string) ($visualDefaults['default_primary_color'] ?? '#0f766e')),
            'secondary_color' => normalize_hex_color((string) ($payload['secondary_color'] ?? ''), (string) ($visualDefaults['default_secondary_color'] ?? '#111827')),
            'accent_color' => normalize_hex_color((string) ($payload['accent_color'] ?? ''), (string) ($visualDefaults['default_accent_color'] ?? '#f59e0b')),
            'web_subdomain' => $payload['web_subdomain'],
            'custom_domain' => $payload['custom_domain'],
            'app_display_name' => $payload['app_display_name'] ?? ($payload['public_name'] ?? $current['name']),
            'app_short_name' => $payload['app_short_name'] ?? substr((string) ($payload['public_name'] ?? $current['name']), 0, 12),
            'portal_title' => $payload['portal_title'],
            'portal_tagline' => $payload['portal_tagline'],
            'welcome_text' => $payload['welcome_text'],
        ]);

        $this->upsertSetting($restaurantId, 'restaurant_feature_pwa_enabled', isset($payload['pwa_enabled']) ? '1' : '0', 'boolean');

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'branding',
            'action_name' => 'branding_updated',
            'entity_type' => 'restaurant_branding',
            'entity_id' => (string) $restaurantId,
            'old_values' => $current,
            'new_values' => $payload,
            'justification' => 'Administrative branding update',
        ]);
    }

    public function updateSettings(int $restaurantId, array $settings, array $actor): void
    {
        $before = $this->listSettings($restaurantId);

        $map = [
            'restaurant_return_window_hours' => 'integer',
            'restaurant_server_auto_close_minutes' => 'integer',
            'restaurant_loss_validation_required' => 'boolean',
            'restaurant_reports_timezone' => 'string',
            'restaurant_feature_pwa_enabled' => 'boolean',
            'restaurant_welcome_text' => 'string',
        ];

        foreach ($map as $key => $type) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $value = is_array($settings[$key]) ? json_encode($settings[$key], JSON_UNESCAPED_UNICODE) : (string) $settings[$key];
            $this->upsertSetting($restaurantId, $key, $value, $type);
        }

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'settings',
            'action_name' => 'restaurant_settings_updated',
            'entity_type' => 'settings',
            'entity_id' => (string) $restaurantId,
            'old_values' => $before,
            'new_values' => $settings,
            'justification' => 'Administrative restaurant settings update',
        ]);
    }

    public function changeRestaurantStatus(int $restaurantId, string $status, array $actor): void
    {
        $current = $this->findRestaurant($restaurantId);
        if ($current === null) {
            return;
        }

        $allowed = ['active', 'suspended', 'banned', 'archived'];
        if (!in_array($status, $allowed, true)) {
            return;
        }

        $statement = $this->database->pdo()->prepare(
            'UPDATE restaurants
             SET status = :status,
                 suspended_at = CASE WHEN :status = "suspended" THEN NOW() ELSE suspended_at END,
                 banned_at = CASE WHEN :status = "banned" THEN NOW() ELSE banned_at END,
                 archived_at = CASE WHEN :status = "archived" THEN NOW() ELSE archived_at END,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $restaurantId,
            'status' => $status,
        ]);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => $restaurantId,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'tenant_management',
            'action_name' => 'restaurant_status_changed',
            'entity_type' => 'restaurants',
            'entity_id' => (string) $restaurantId,
            'old_values' => ['status' => $current['status']],
            'new_values' => ['status' => $status],
            'justification' => 'Administrative status change',
        ]);
    }

    public function listSettings(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT setting_key, setting_value, value_type
             FROM settings
             WHERE restaurant_id = :restaurant_id
             ORDER BY setting_key ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        $settings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    public function declareSubscriptionPayment(int $restaurantId, array $actor): void
    {
        Container::getInstance()->get('subscriptionService')->declarePayment($restaurantId, $actor);
    }

    public function activateSubscription(int $restaurantId, array $payload, array $actor): void
    {
        Container::getInstance()->get('subscriptionService')->activateRestaurant($restaurantId, $payload, $actor);
    }

    public function listUsers(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.id, u.full_name, u.email, u.status, r.name AS role_name, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.restaurant_id = :restaurant_id
             ORDER BY u.id DESC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listCategories(int $restaurantId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id, name, slug, display_order, status
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
            'SELECT mi.id, mi.name, mi.slug, mi.price, mi.status, mi.is_available, mi.display_order,
                    mc.name AS category_name
             FROM menu_items mi
             INNER JOIN menu_categories mc ON mc.id = mi.category_id
             WHERE mi.restaurant_id = :restaurant_id
             ORDER BY mi.display_order ASC, mi.id ASC'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function upsertSetting(int $restaurantId, string $key, string $value, string $type): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
             VALUES (:restaurant_id, :setting_key, :setting_value, :value_type, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
        ]);
    }

    private function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            $payload[$key] = $trimmed === '' ? null : $trimmed;
        }

        return $payload;
    }
}
