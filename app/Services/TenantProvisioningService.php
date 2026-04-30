<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use PDO;

final class TenantProvisioningService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function previewRestaurantCode(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? substr($normalized, 0, 60) : 'restaurant';
    }

    public function createRestaurant(array $payload, array $actor): int
    {
        $payload = $this->preparePayload($payload, false);
        return $this->persistRestaurant($payload, $actor, false);
    }

    public function createSelfServiceRestaurant(array $payload): int
    {
        $payload = $this->preparePayload($payload, true);
        return $this->persistRestaurant($payload, [
            'id' => null,
            'full_name' => 'auto-onboarding',
            'role_code' => 'system',
        ], true);
    }

    private function persistRestaurant(array $payload, array $actor, bool $withPrimaryUser): int
    {
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();

        try {
            $restaurantStatement = $pdo->prepare(
                'INSERT INTO restaurants
                (name, restaurant_code, slug, status, subscription_status, subscription_payment_status, support_email, phone, country, city, address_line,
                 timezone, currency_code, access_url, download_url, subscription_plan_id, created_at, updated_at)
                 VALUES
                (:name, :restaurant_code, :slug, :status, :subscription_status, :subscription_payment_status, :support_email, :phone, :country, :city, :address_line,
                 :timezone, :currency_code, :access_url, :download_url, :subscription_plan_id, NOW(), NOW())'
            );
            $restaurantStatement->execute([
                'name' => $payload['name'],
                'restaurant_code' => $payload['restaurant_code'],
                'slug' => $payload['slug'],
                'status' => $payload['status'],
                'subscription_status' => $payload['subscription_status'],
                'subscription_payment_status' => $payload['subscription_payment_status'],
                'support_email' => $payload['support_email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'country' => $payload['country'] ?? null,
                'city' => $payload['city'] ?? null,
                'address_line' => $payload['address_line'] ?? null,
                'timezone' => $payload['timezone'] ?? 'Africa/Kinshasa',
                'currency_code' => $payload['currency_code'] ?? 'USD',
                'access_url' => $payload['access_url'] ?? null,
                'download_url' => $payload['download_url'] ?? null,
                'subscription_plan_id' => $payload['subscription_plan_id'] ?? null,
            ]);
            $restaurantId = (int) $pdo->lastInsertId();

            $brandingStatement = $pdo->prepare(
                'INSERT INTO restaurant_branding
                (restaurant_id, public_name, logo_url, cover_image_url, favicon_url, primary_color, secondary_color, accent_color, web_subdomain,
                 custom_domain, app_display_name, app_short_name, portal_title, portal_tagline, welcome_text, created_at, updated_at)
                 VALUES
                (:restaurant_id, :public_name, :logo_url, :cover_image_url, :favicon_url, :primary_color, :secondary_color, :accent_color, :web_subdomain,
                 :custom_domain, :app_display_name, :app_short_name, :portal_title, :portal_tagline, :welcome_text, NOW(), NOW())'
            );
            $brandingStatement->execute([
                'restaurant_id' => $restaurantId,
                'public_name' => $payload['public_name'] ?? $payload['name'],
                'logo_url' => $payload['logo_url'] ?? null,
                'cover_image_url' => $payload['cover_image_url'] ?? null,
                'favicon_url' => $payload['favicon_url'] ?? null,
                'primary_color' => $payload['primary_color'] ?? '#0f766e',
                'secondary_color' => $payload['secondary_color'] ?? '#111827',
                'accent_color' => $payload['accent_color'] ?? '#f59e0b',
                'web_subdomain' => $payload['web_subdomain'] ?? null,
                'custom_domain' => $payload['custom_domain'] ?? null,
                'app_display_name' => $payload['app_display_name'] ?? $payload['name'],
                'app_short_name' => $payload['app_short_name'] ?? substr($payload['name'], 0, 12),
                'portal_title' => $payload['portal_title'] ?? $payload['name'],
                'portal_tagline' => $payload['portal_tagline'] ?? 'Pilotez stock, cuisine, ventes et rapports en temps reel.',
                'welcome_text' => $payload['welcome_text'] ?? 'Bienvenue dans votre espace restaurant Badiboss.',
            ]);

            $this->insertRestaurantSettings($restaurantId);
            $this->insertRestaurantModules($restaurantId);

            if ($withPrimaryUser) {
                $this->insertPrimaryUser($restaurantId, $payload);
            }

            Container::getInstance()->get('subscriptionService')->markPendingPayment($restaurantId);

            Container::getInstance()->get('audit')->log([
                'restaurant_id' => $restaurantId,
                'user_id' => $actor['id'] ?? null,
                'actor_name' => $actor['full_name'] ?? 'system',
                'actor_role_code' => $actor['role_code'] ?? 'system',
                'module_name' => 'tenant_management',
                'action_name' => $withPrimaryUser ? 'restaurant_self_registered' : 'restaurant_created',
                'entity_type' => 'restaurants',
                'entity_id' => (string) $restaurantId,
                'new_values' => $payload,
                'justification' => $withPrimaryUser ? 'Creation autonome du restaurant' : 'Initial tenant provisioning',
            ]);

            $pdo->commit();

            return $restaurantId;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($exception->getMessage() !== '' ? $exception->getMessage() : 'Impossible de creer le restaurant.', 0, $exception);
        }
    }

    private function insertRestaurantSettings(int $restaurantId): void
    {
        $defaults = Container::getInstance()->get('platformSettings')->restaurantSettings($restaurantId);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO settings
            (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
             VALUES
            (:restaurant_id, :setting_key, :setting_value, :value_type, 0, NOW(), NOW())'
        );

        foreach ($defaults as $key => $value) {
            $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_array($value) ? 'json' : 'string'));
            $statement->execute([
                'restaurant_id' => $restaurantId,
                'setting_key' => (string) $key,
                'setting_value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                'value_type' => $type,
            ]);
        }
    }

    private function insertRestaurantModules(int $restaurantId): void
    {
        $modules = Container::getInstance()->get('platformSettings')->catalog('global_module_catalog_json');
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO restaurant_modules
            (restaurant_id, module_code, is_enabled, configured_by, configured_at, created_at, updated_at)
             VALUES
            (:restaurant_id, :module_code, 1, NULL, NOW(), NOW(), NOW())'
        );

        foreach ($modules as $moduleCode) {
            $statement->execute([
                'restaurant_id' => $restaurantId,
                'module_code' => (string) $moduleCode,
            ]);
        }
    }

    private function insertPrimaryUser(int $restaurantId, array $payload): void
    {
        $roleId = $this->roleIdByCode((string) $payload['primary_role_code']);
        if ($roleId === null) {
            throw new RuntimeException('Role principal introuvable.');
        }

        $statement = $this->database->pdo()->prepare(
            'INSERT INTO users
            (restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, created_at, updated_at)
             VALUES
            (:restaurant_id, :role_id, :full_name, :email, :phone, :password_hash, "active", 0, NOW(), NOW())'
        );
        $statement->execute([
            'restaurant_id' => $restaurantId,
            'role_id' => $roleId,
            'full_name' => $payload['primary_contact_name'],
            'email' => $payload['primary_contact_email'],
            'phone' => $payload['primary_contact_phone'] ?? null,
            'password_hash' => password_hash((string) $payload['password'], PASSWORD_DEFAULT),
        ]);
    }

    private function roleIdByCode(string $roleCode): ?int
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id
             FROM roles
             WHERE code = :code
             LIMIT 1'
        );
        $statement->execute(['code' => $roleCode]);
        $roleId = $statement->fetchColumn();

        return $roleId !== false ? (int) $roleId : null;
    }

    private function preparePayload(array $payload, bool $selfService): array
    {
        $payload = $this->normalizePayload($payload);
        if (($payload['name'] ?? '') === '') {
            throw new RuntimeException('Le nom du restaurant est obligatoire.');
        }

        $restaurantCode = $this->previewRestaurantCode((string) ($payload['restaurant_code'] ?? $payload['name']));
        if ($this->restaurantCodeExists($restaurantCode)) {
            throw new RuntimeException('Le restaurant_id / code propose est deja utilise.');
        }

        $slug = $payload['slug'] ?? '';
        $slug = trim((string) $slug) !== '' ? $this->previewRestaurantCode((string) $slug) : $restaurantCode;
        if ($this->slugExists($slug)) {
            throw new RuntimeException('Le slug / identifiant public du restaurant est deja utilise.');
        }

        $payload['restaurant_code'] = $restaurantCode;
        $payload['slug'] = $slug;
        $payload['subscription_plan_id'] = $this->resolveSubscriptionPlanId(
            isset($payload['subscription_plan_id']) ? (int) $payload['subscription_plan_id'] : 0
        );
        $payload['status'] = $payload['status'] ?? 'active';
        $payload['subscription_status'] = $selfService ? 'PENDING_PAYMENT' : ($payload['subscription_status'] ?? 'ACTIVE');
        $payload['subscription_payment_status'] = $selfService ? 'UNPAID' : ($payload['subscription_payment_status'] ?? 'PAID');
        $payload['access_url'] = restaurant_generated_access_url(['slug' => $slug]);
        $payload['download_url'] ??= null;
        $payload['timezone'] ??= 'Africa/Kinshasa';
        $payload['currency_code'] ??= 'USD';

        $visualDefaults = Container::getInstance()->get('platformSettings')->visualDefaults();
        $payload['primary_color'] = normalize_hex_color((string) ($payload['primary_color'] ?? ''), (string) ($visualDefaults['default_primary_color'] ?? '#0f766e'));
        $payload['secondary_color'] = normalize_hex_color((string) ($payload['secondary_color'] ?? ''), (string) ($visualDefaults['default_secondary_color'] ?? '#111827'));
        $payload['accent_color'] = normalize_hex_color((string) ($payload['accent_color'] ?? ''), (string) ($visualDefaults['default_accent_color'] ?? '#f59e0b'));

        if ($selfService) {
            if (($payload['primary_contact_email'] ?? '') === '' || ($payload['password'] ?? '') === '') {
                throw new RuntimeException('Le compte principal du restaurant est obligatoire.');
            }
            if ($this->emailExists((string) $payload['primary_contact_email'])) {
                throw new RuntimeException('Cet email principal est deja utilise.');
            }
        }

        return $payload;
    }

    private function resolveSubscriptionPlanId(int $requestedPlanId): int
    {
        if ($requestedPlanId > 0) {
            $statement = $this->database->pdo()->prepare(
                "SELECT id
                 FROM subscription_plans
                 WHERE id = :id
                   AND status = 'active'
                 LIMIT 1"
            );
            $statement->execute(['id' => $requestedPlanId]);
            $resolved = $statement->fetchColumn();

            if ($resolved !== false) {
                return (int) $resolved;
            }
        }

        $fallback = $this->database->pdo()->query(
            "SELECT id
             FROM subscription_plans
             WHERE status = 'active'
             ORDER BY CASE code
                 WHEN 'starter' THEN 0
                 WHEN 'business' THEN 1
                 ELSE 2
             END, id ASC
             LIMIT 1"
        )->fetchColumn();

        if ($fallback === false) {
            throw new RuntimeException('Aucun plan d abonnement actif n est disponible pour creer ce restaurant.');
        }

        return (int) $fallback;
    }

    private function restaurantCodeExists(string $code): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM restaurants WHERE restaurant_code = :restaurant_code LIMIT 1'
        );
        $statement->execute(['restaurant_code' => $code]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function slugExists(string $slug): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM restaurants WHERE slug = :slug LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function emailExists(string $email): bool
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT id FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
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
