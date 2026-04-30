<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use PDO;

final class PlatformSettingsService
{
    private const DEFAULTS = [
        'global_validation_states_json' => ['PROPOSE', 'CONFIRME_TECHNIQUEMENT', 'EN_ATTENTE_VALIDATION_MANAGER', 'VALIDE', 'REJETE'],
        'global_final_qualifications_json' => ['retour_simple', 'aliment_a_jeter', 'boisson_cassee', 'perte_cuisine', 'perte_serveur', 'perte_stock', 'perte_matiere', 'perte_argent', 'autre'],
        'global_responsibility_targets_json' => ['cuisine', 'serveur', 'stock', 'restaurant', 'autre'],
        'global_incident_types_json' => ['retour_simple', 'retour_avec_anomalie', 'produit_defectueux', 'produit_casse', 'produit_impropre', 'retour_stock_endommage', 'perte_matiere', 'perte_argent'],
        'global_client_access_rules_json' => ['public_menu_enabled' => true, 'public_restaurant_info_enabled' => true, 'auth_required_for_order' => true, 'auth_required_for_reservation' => true],
        'global_automation_rules_json' => ['sale_auto_after_hours' => 24],
        'global_subscription_rules_json' => ['subscription_grace_days' => 2, 'subscription_warning_days' => 5, 'default_duration_days' => 30],
        'global_alert_rules_json' => ['server_incident_threshold' => 3, 'kitchen_loss_threshold' => 2, 'repeated_inconsistency_threshold' => 2, 'frequent_return_threshold' => 3],
        'global_default_restaurant_settings_json' => ['restaurant_return_window_hours' => 24, 'restaurant_server_auto_close_minutes' => 90, 'restaurant_loss_validation_required' => true, 'restaurant_public_menu_enabled' => true, 'restaurant_public_order_requires_auth' => true, 'restaurant_public_reservation_requires_auth' => true],
        'global_module_catalog_json' => ['menu', 'stock', 'kitchen', 'sales', 'reports', 'roles', 'branding'],
        'global_visual_settings_json' => ['default_primary_color' => '#0F766E', 'default_secondary_color' => '#111827', 'default_accent_color' => '#F59E0B', 'default_icon_style' => 'standard'],
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function listSystemSettings(): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT setting_key, setting_value, value_type
             FROM settings
             WHERE restaurant_id IS NULL
             ORDER BY setting_key ASC'
        );
        $statement->execute();

        $settings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $this->decodeValue($row['setting_value'], $row['value_type']);
        }

        foreach (self::DEFAULTS as $key => $value) {
            $settings[$key] ??= $value;
        }

        return $settings;
    }

    public function updateSystemSettings(array $payload, array $actor): void
    {
        $before = $this->listSystemSettings();
        $normalizedSettings = $this->normalizeSettingsPayload($payload);
        foreach ($normalizedSettings as $key => $value) {
            $this->upsertSetting($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'json');
        }

        $planChanges = $this->updateSubscriptionPlans($payload);

        Container::getInstance()->get('audit')->log([
            'restaurant_id' => null,
            'user_id' => $actor['id'],
            'actor_name' => $actor['full_name'],
            'actor_role_code' => $actor['role_code'],
            'module_name' => 'settings',
            'action_name' => 'platform_settings_updated',
            'entity_type' => 'settings',
            'entity_id' => 'platform',
            'old_values' => $before,
            'new_values' => [
                'settings' => $normalizedSettings,
                'subscription_plans' => $planChanges,
            ],
            'justification' => 'Parametrage plateforme sans code',
        ]);
    }

    public function catalog(string $key): array
    {
        $settings = $this->listSystemSettings();
        $value = $settings[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function restaurantSettings(int $restaurantId): array
    {
        $settings = $this->listSystemSettings();
        $defaults = $settings['global_default_restaurant_settings_json'] ?? self::DEFAULTS['global_default_restaurant_settings_json'];

        $statement = $this->database->pdo()->prepare(
            'SELECT setting_key, setting_value, value_type
             FROM settings
             WHERE restaurant_id = :restaurant_id'
        );
        $statement->execute(['restaurant_id' => $restaurantId]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $defaults[$row['setting_key']] = $this->decodeValue($row['setting_value'], $row['value_type']);
        }

        return $defaults;
    }

    public function visualDefaults(): array
    {
        $settings = $this->listSystemSettings();
        $visualDefaults = $settings['global_visual_settings_json'] ?? self::DEFAULTS['global_visual_settings_json'];

        return [
            'default_primary_color' => normalize_hex_color((string) ($visualDefaults['default_primary_color'] ?? '#0F766E'), '#0F766E'),
            'default_secondary_color' => normalize_hex_color((string) ($visualDefaults['default_secondary_color'] ?? '#111827'), '#111827'),
            'default_accent_color' => normalize_hex_color((string) ($visualDefaults['default_accent_color'] ?? '#F59E0B'), '#F59E0B'),
            'default_icon_style' => (string) ($visualDefaults['default_icon_style'] ?? 'standard'),
        ];
    }

    public function listSubscriptionPlans(): array
    {
        return $this->database->pdo()->query(
            'SELECT id, name, code, description, monthly_price, yearly_price, max_users, max_restaurants, status
             FROM subscription_plans
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeSettingsPayload(array $payload): array
    {
        if ($this->containsLegacyJsonPayload($payload)) {
            $settings = [];
            foreach (self::DEFAULTS as $key => $defaultValue) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }

                $raw = trim((string) $payload[$key]);
                $decoded = $raw !== '' ? json_decode($raw, true) : $defaultValue;
                if ($decoded === null && $raw !== 'null' && $raw !== '') {
                    $decoded = $defaultValue;
                }

                $settings[$key] = $decoded;
            }

            return $settings;
        }

        return [
            'global_validation_states_json' => $this->cleanList($payload['validation_states'] ?? self::DEFAULTS['global_validation_states_json']),
            'global_final_qualifications_json' => $this->cleanList($payload['final_qualifications'] ?? self::DEFAULTS['global_final_qualifications_json']),
            'global_responsibility_targets_json' => $this->cleanList($payload['responsibility_targets'] ?? self::DEFAULTS['global_responsibility_targets_json']),
            'global_incident_types_json' => $this->cleanList($payload['incident_types'] ?? self::DEFAULTS['global_incident_types_json']),
            'global_client_access_rules_json' => [
                'public_menu_enabled' => isset($payload['public_menu_enabled']),
                'public_restaurant_info_enabled' => isset($payload['public_restaurant_info_enabled']),
                'auth_required_for_order' => isset($payload['auth_required_for_order']),
                'auth_required_for_reservation' => isset($payload['auth_required_for_reservation']),
            ],
            'global_automation_rules_json' => [
                'sale_auto_after_hours' => max(1, (int) ($payload['sale_auto_after_hours'] ?? 24)),
            ],
            'global_subscription_rules_json' => [
                'subscription_grace_days' => max(0, (int) ($payload['subscription_grace_days'] ?? 2)),
                'subscription_warning_days' => max(0, (int) ($payload['subscription_warning_days'] ?? 5)),
                'default_duration_days' => max(1, (int) ($payload['default_duration_days'] ?? 30)),
            ],
            'global_alert_rules_json' => [
                'server_incident_threshold' => max(1, (int) ($payload['server_incident_threshold'] ?? 3)),
                'kitchen_loss_threshold' => max(1, (int) ($payload['kitchen_loss_threshold'] ?? 2)),
                'repeated_inconsistency_threshold' => max(1, (int) ($payload['repeated_inconsistency_threshold'] ?? 2)),
                'frequent_return_threshold' => max(1, (int) ($payload['frequent_return_threshold'] ?? 3)),
            ],
            'global_default_restaurant_settings_json' => [
                'restaurant_return_window_hours' => max(1, (int) ($payload['restaurant_return_window_hours'] ?? 24)),
                'restaurant_server_auto_close_minutes' => max(15, (int) ($payload['restaurant_server_auto_close_minutes'] ?? 90)),
                'restaurant_loss_validation_required' => isset($payload['restaurant_loss_validation_required']),
                'restaurant_public_menu_enabled' => isset($payload['restaurant_public_menu_enabled']),
                'restaurant_public_order_requires_auth' => isset($payload['restaurant_public_order_requires_auth']),
                'restaurant_public_reservation_requires_auth' => isset($payload['restaurant_public_reservation_requires_auth']),
            ],
            'global_module_catalog_json' => $this->cleanList($payload['module_catalog'] ?? self::DEFAULTS['global_module_catalog_json']),
            'global_visual_settings_json' => [
                'default_primary_color' => normalize_hex_color((string) ($payload['default_primary_color'] ?? '#0F766E'), '#0F766E'),
                'default_secondary_color' => normalize_hex_color((string) ($payload['default_secondary_color'] ?? '#111827'), '#111827'),
                'default_accent_color' => normalize_hex_color((string) ($payload['default_accent_color'] ?? '#F59E0B'), '#F59E0B'),
                'default_icon_style' => trim((string) ($payload['default_icon_style'] ?? 'standard')) ?: 'standard',
            ],
        ];
    }

    private function containsLegacyJsonPayload(array $payload): bool
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function cleanList(mixed $values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }

        $cleaned = [];
        foreach ($values as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            $cleaned[] = $trimmed;
        }

        return array_values(array_unique($cleaned));
    }

    private function updateSubscriptionPlans(array $payload): array
    {
        $changes = [];
        $planIds = array_keys((array) ($payload['plan_name'] ?? []));

        foreach ($planIds as $planId) {
            $id = (int) $planId;
            if ($id <= 0) {
                continue;
            }

            $statement = $this->database->pdo()->prepare(
                'UPDATE subscription_plans
                 SET name = :name,
                     code = :code,
                     description = :description,
                     monthly_price = :monthly_price,
                     yearly_price = :yearly_price,
                     max_users = :max_users,
                     max_restaurants = :max_restaurants,
                     status = :status,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'name' => trim((string) ($payload['plan_name'][$planId] ?? '')),
                'code' => trim((string) ($payload['plan_code'][$planId] ?? '')),
                'description' => trim((string) ($payload['plan_description'][$planId] ?? '')) ?: null,
                'monthly_price' => (float) ($payload['plan_monthly_price'][$planId] ?? 0),
                'yearly_price' => ((string) ($payload['plan_yearly_price'][$planId] ?? '')) !== '' ? (float) $payload['plan_yearly_price'][$planId] : null,
                'max_users' => max(1, (int) ($payload['plan_max_users'][$planId] ?? 1)),
                'max_restaurants' => max(1, (int) ($payload['plan_max_restaurants'][$planId] ?? 1)),
                'status' => in_array(($payload['plan_status'][$planId] ?? 'active'), ['active', 'inactive', 'archived'], true)
                    ? $payload['plan_status'][$planId]
                    : 'active',
            ]);

            $changes[] = ['id' => $id, 'action' => 'updated'];
        }

        $newPlanName = trim((string) ($payload['new_plan_name'] ?? ''));
        $newPlanCode = trim((string) ($payload['new_plan_code'] ?? ''));
        if ($newPlanName !== '' && $newPlanCode !== '') {
            $statement = $this->database->pdo()->prepare(
                'INSERT INTO subscription_plans
                 (name, code, description, monthly_price, yearly_price, max_users, max_restaurants, status, created_at, updated_at)
                 VALUES
                 (:name, :code, :description, :monthly_price, :yearly_price, :max_users, :max_restaurants, :status, NOW(), NOW())'
            );
            $statement->execute([
                'name' => $newPlanName,
                'code' => $newPlanCode,
                'description' => trim((string) ($payload['new_plan_description'] ?? '')) ?: null,
                'monthly_price' => (float) ($payload['new_plan_monthly_price'] ?? 0),
                'yearly_price' => ((string) ($payload['new_plan_yearly_price'] ?? '')) !== '' ? (float) $payload['new_plan_yearly_price'] : null,
                'max_users' => max(1, (int) ($payload['new_plan_max_users'] ?? 1)),
                'max_restaurants' => max(1, (int) ($payload['new_plan_max_restaurants'] ?? 1)),
                'status' => in_array(($payload['new_plan_status'] ?? 'active'), ['active', 'inactive', 'archived'], true)
                    ? $payload['new_plan_status']
                    : 'active',
            ]);

            $changes[] = ['id' => (int) $this->database->pdo()->lastInsertId(), 'action' => 'created'];
        }

        return $changes;
    }

    private function decodeValue(string $value, string $type): mixed
    {
        return match ($type) {
            'json' => json_decode($value, true) ?? [],
            'boolean' => $value === '1',
            'integer' => (int) $value,
            'decimal' => (float) $value,
            default => $value,
        };
    }

    private function upsertSetting(string $key, string $value, string $type): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
             VALUES (NULL, :setting_key, :setting_value, :value_type, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()'
        );
        $statement->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'value_type' => $type,
        ]);
    }
}
