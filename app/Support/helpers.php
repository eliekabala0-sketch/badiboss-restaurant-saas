<?php

declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    static $loaded = false;

    if (!$loaded) {
        $envFile = BASE_PATH . '/.env';
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                $value = trim($value, "\"'");

                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        $loaded = true;
    }

    $runtimeValue = getenv($key);
    if ($runtimeValue !== false && $runtimeValue !== '') {
        return (string) $runtimeValue;
    }

    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function correction_request_status_label(?string $status): string
{
    return match ($status) {
        'PENDING' => 'En attente',
        'APPROVED' => 'Approuvee',
        'REJECTED' => 'Rejetee',
        default => validation_status_label($status),
    };
}

function correction_request_type_label(?string $type): string
{
    return match ($type) {
        'stock_quantity_correction' => 'Correction de quantite stock',
        'sensitive_operation_correction' => 'Correction sensible a valider',
        default => (string) $type,
    };
}

function audit_action_label(?string $action): string
{
    return match ($action) {
        'menu_item_created' => 'Plat cree',
        'menu_item_updated' => 'Plat modifie',
        'menu_price_updated' => 'Prix du plat modifie',
        'menu_item_status_changed' => 'Statut du plat modifie',
        'stock_item_created' => 'Article stock cree',
        'stock_item_updated' => 'Article stock modifie',
        'stock_item_price_updated' => 'Cout article stock modifie',
        'stock_quantity_correction_requested' => 'Correction de quantite demandee',
        'stock_quantity_correction_approved' => 'Correction de quantite approuvee',
        'stock_quantity_correction_rejected' => 'Correction de quantite rejetee',
        'sensitive_operation_correction_requested' => 'Correction sensible demandee',
        default => (string) $action,
    };
}

function base_path(string $path = ''): string
{
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function config(string $key, mixed $default = null): mixed
{
    return App\Core\Container::getInstance()->get('config.' . $key, $default);
}

function view(string $template, array $data = []): void
{
    App\Core\View::render($template, $data);
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_restaurant_id(): int
{
    $sessionRestaurantId = (int) ($_SESSION['restaurant_id'] ?? 0);
    if ($sessionRestaurantId > 0) {
        return $sessionRestaurantId;
    }

    $user = current_user();
    if (($user['scope'] ?? null) === 'super_admin') {
        $activeRestaurantId = (int) ($_SESSION['active_restaurant_id'] ?? 0);
        if ($activeRestaurantId > 0) {
            return $activeRestaurantId;
        }

        $restaurants = App\Core\Container::getInstance()->get('restaurantAdmin')?->listRestaurants() ?? [];
        if ($restaurants !== []) {
            $restaurantId = (int) $restaurants[0]['id'];
            $_SESSION['active_restaurant_id'] = $restaurantId;

            return $restaurantId;
        }

        return 0;
    }

    $restaurantId = (int) ($user['restaurant_id'] ?? 0);
    if ($restaurantId > 0) {
        $_SESSION['restaurant_id'] = $restaurantId;
    }

    return $restaurantId;
}

function safe_timezone(?string $timezoneName = null): DateTimeZone
{
    $fallback = (string) config('app.timezone', 'Africa/Lagos');

    try {
        return new DateTimeZone($timezoneName ?: $fallback);
    } catch (\Throwable) {
        return new DateTimeZone($fallback);
    }
}

function restaurant_timezone(int|array|null $restaurant = null, bool $forReports = false): DateTimeZone
{
    if (is_array($restaurant)) {
        $timezoneName = $forReports
            ? (string) ($restaurant['settings']['restaurant_reports_timezone'] ?? $restaurant['timezone'] ?? '')
            : (string) ($restaurant['timezone'] ?? '');

        return safe_timezone($timezoneName);
    }

    $restaurantId = is_int($restaurant) ? $restaurant : current_restaurant_id();
    if ($restaurantId <= 0) {
        return safe_timezone();
    }

    $restaurantRow = App\Core\Container::getInstance()->get('restaurantAdmin')?->findRestaurant($restaurantId);
    if (!is_array($restaurantRow)) {
        return safe_timezone();
    }

    $timezoneName = $forReports
        ? (string) ($restaurantRow['settings']['restaurant_reports_timezone'] ?? $restaurantRow['timezone'] ?? '')
        : (string) ($restaurantRow['timezone'] ?? '');

    return safe_timezone($timezoneName);
}

function current_restaurant_now(int|array|null $restaurant = null, bool $forReports = false): DateTimeImmutable
{
    return new DateTimeImmutable('now', restaurant_timezone($restaurant, $forReports));
}

function today_for_restaurant(int|array|null $restaurant = null, bool $forReports = false, string $format = 'Y-m-d'): string
{
    return current_restaurant_now($restaurant, $forReports)->format($format);
}

function can_access(string $ability, ?array $user = null): bool
{
    $user ??= current_user();

    return App\Core\Container::getInstance()->get('authz')->can($user, $ability);
}

function authorize_access(string $ability, ?array $user = null): void
{
    if (!can_access($ability, $user)) {
        http_response_code(403);
        echo 'Action refusée.';
        exit;
    }
}

function redirect_after_login(array $user): never
{
    if (($user['scope'] ?? null) === 'super_admin') {
        redirect('/super-admin');
    }

    $target = match ($user['role_code'] ?? null) {
        'owner', 'manager' => '/owner',
        'stock_manager' => '/stock',
        'kitchen' => '/cuisine',
        'cashier_accountant' => '/caisse',
        'cashier_server' => '/ventes',
        'customer' => !empty($user['restaurant_slug']) ? '/portal/' . rawurlencode((string) $user['restaurant_slug']) : '/login',
        default => '/login',
    };

    redirect($target);
}

function app_url(string $path = ''): string
{
    $baseUrl = rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/');
    $normalizedPath = $path === '' ? '' : '/' . ltrim($path, '/');

    return $baseUrl . $normalizedPath;
}

function restaurant_generated_access_path(array $restaurant): string
{
    $slug = trim((string) ($restaurant['slug'] ?? ''));
    if ($slug === '') {
        $slug = 'restaurant-' . (string) ($restaurant['id'] ?? 'inconnu');
    }

    return '/portal/' . rawurlencode($slug);
}

function restaurant_generated_access_url(array $restaurant): string
{
    return app_url(restaurant_generated_access_path($restaurant));
}

function restaurant_generated_registration_path(array $restaurant): string
{
    $slug = trim((string) ($restaurant['slug'] ?? ''));
    if ($slug === '') {
        $slug = 'restaurant-' . (string) ($restaurant['id'] ?? 'inconnu');
    }

    return '/portal/' . rawurlencode($slug) . '/register';
}

function restaurant_generated_registration_url(array $restaurant): string
{
    return app_url(restaurant_generated_registration_path($restaurant));
}

function restaurant_media_url(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
        return $value;
    }

    return '/' . ltrim($value, '/');
}

function restaurant_media_fallback_url(string $kind = 'logo'): string
{
    return match ($kind) {
        'favicon' => 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><rect width="128" height="128" rx="24" fill="%23111827"/><text x="50%" y="56%" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-size="54" fill="%23F59E0B">B</text></svg>',
        'photo' => 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 540"><rect width="960" height="540" fill="%23F4F1EA"/><rect x="60" y="60" width="840" height="420" rx="28" fill="%23E5DED1" stroke="%23D4AF37" stroke-width="8"/><circle cx="290" cy="205" r="54" fill="%23D4AF37"/><path d="M165 390 330 255l120 108 114-84 171 111H165Z" fill="%23111827" opacity=".8"/><text x="50%" y="90%" text-anchor="middle" font-family="Arial,sans-serif" font-size="34" fill="%236B7280">Photo restaurant indisponible</text></svg>',
        default => 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 280"><rect width="480" height="280" rx="28" fill="%23111827"/><rect x="40" y="40" width="400" height="200" rx="20" fill="%23D4AF37" opacity=".18"/><text x="50%" y="48%" text-anchor="middle" font-family="Arial,sans-serif" font-size="44" fill="%23F5F2EA">Badiboss</text><text x="50%" y="66%" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" fill="%23D4AF37">Visuel par dÃ©faut</text></svg>',
    };
}

function restaurant_media_url_or_default(?string $value, string $kind = 'logo'): string
{
    return restaurant_media_url($value) ?? restaurant_media_fallback_url($kind);
}

function menu_item_media_url_or_default(?string $value): string
{
    return restaurant_media_url($value) ?? restaurant_media_fallback_url('photo');
}

function normalize_hex_color(?string $value, string $default): string
{
    $value = strtoupper(trim((string) $value));
    if (preg_match('/^#[0-9A-F]{6}$/', $value) === 1) {
        return $value;
    }

    return strtoupper($default);
}

function restaurant_status_message(?string $status): ?string
{
    return match ($status) {
        'suspended' => 'Votre restaurant est suspendu. Contactez l’administration.',
        'banned' => 'Votre restaurant est banni. Contactez l’administration.',
        default => null,
    };
}

function restaurant_status_severity(?string $status): string
{
    return match ($status) {
        'suspended' => 'warning',
        'banned' => 'danger',
        default => 'info',
    };
}

function restaurant_status_blocks_operations(?string $status): bool
{
    return in_array($status, ['suspended', 'banned'], true);
}

function current_restaurant_context(): ?array
{
    $user = current_user();
    if (!is_array($user) || ($user['scope'] ?? null) === 'super_admin') {
        return null;
    }

    $restaurantId = (int) ($user['restaurant_id'] ?? 0);
    if ($restaurantId <= 0) {
        return null;
    }

    return App\Core\Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
}

function enforce_restaurant_access(bool $allowDashboard = false): void
{
    $user = current_user();
    if (!is_array($user) || ($user['scope'] ?? null) === 'super_admin') {
        return;
    }

    $restaurant = current_restaurant_context();
    $status = (string) ($restaurant['status'] ?? '');
    if (!restaurant_status_blocks_operations($status)) {
        return;
    }

    if ($allowDashboard === true && in_array((string) ($user['role_code'] ?? ''), ['owner', 'manager'], true)) {
        return;
    }

    http_response_code(423);
    view('auth/restaurant-blocked', [
        'title' => 'Accès limité',
        'restaurant' => $restaurant,
        'status_message' => restaurant_status_message($status),
        'status_severity' => restaurant_status_severity($status),
    ]);
    exit;
}

function enforce_restaurant_write_access(App\Core\Request $request, ?array $user = null, bool $isApi = false): void
{
    if ($request->method !== 'POST') {
        return;
    }

    $user ??= current_user();
    if (!is_array($user) || ($user['scope'] ?? null) === 'super_admin') {
        return;
    }

    $status = (string) ($user['restaurant_status'] ?? '');
    if ($status === '') {
        $restaurant = current_restaurant_context();
        $status = (string) ($restaurant['status'] ?? '');
    }

    if (!restaurant_status_blocks_operations($status)) {
        return;
    }

    $message = restaurant_status_message($status) ?? 'Les opÃ©rations sont bloquÃ©es pour ce restaurant.';

    if ($isApi || str_starts_with($request->uri, '/api/')) {
        App\Core\Response::json([
            'message' => $message,
            'restaurant_status' => $status,
        ], 423);
    }

    if (!headers_sent()) {
        http_response_code(423);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo $message;
    exit;
}

function audit_access(string $moduleName, ?int $restaurantId = null, ?string $entityType = null, ?string $entityId = null, ?string $justification = null): void
{
    $user = current_user();
    if ($user === null) {
        return;
    }

    App\Core\Container::getInstance()->get('audit')->log([
        'restaurant_id' => $restaurantId,
        'user_id' => $user['id'],
        'actor_name' => $user['full_name'],
        'actor_role_code' => $user['role_code'],
        'module_name' => $moduleName,
        'action_name' => 'viewed',
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'justification' => $justification ?? 'Consultation d ecran',
    ]);
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;

        return null;
    }

    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);

    return $message;
}

function status_label(?string $status): string
{
    return match ($status) {
        'active' => 'Actif',
        'inactive' => 'Inactif',
        'disabled' => 'Désactivé',
        'draft' => 'Brouillon',
        'suspended' => 'Suspendu',
        'banned' => 'Banni',
        'archived' => 'Archivé',
        'out_of_stock' => 'Épuisé',
        'hidden' => 'Masqué',
        default => (string) $status,
    };
}

function role_label(?string $roleCode): string
{
    return match ($roleCode) {
        'super_admin' => 'Super administrateur',
        'owner' => 'Propriétaire',
        'manager' => 'Manager',
        'stock_manager' => 'Gestionnaire de stock',
        'cashier_accountant' => 'Caissier / comptable',
        'kitchen' => 'Cuisine',
        'cashier_server' => 'Serveur / Caissier',
        'customer' => 'Client',
        default => (string) $roleCode,
    };
}

function report_audit_action_label(?string $actionName): string
{
    return match ((string) $actionName) {
        'sale_closed' => 'Vente clôturée',
        'sale_created' => 'Vente créée',
        'server_request_created' => 'Demande serveur créée',
        'server_request_received' => 'Demande serveur réceptionnée',
        'server_request_closed_as_sale' => 'Commande clôturée en vente',
        'server_request_auto_closed_as_sale' => 'Commande clôturée automatiquement',
        'automatic_sale_after_24h' => 'Vente automatique (24h)',
        'cash_cashier_auto_received' => 'Réception caisse automatique (minuit)',
        'kitchen_stock_request_expired_midnight' => 'Demande stock expirée (minuit)',
        'kitchen_stock_request_auto_received' => 'Réception cuisine automatique (minuit)',
        'stock_item_archived' => 'Article stock archivé',
        'cash_server_remitted' => 'Remise serveur → caisse',
        'cash_cashier_received' => 'Réception caisse',
        'cash_movement_created' => 'Mouvement de caisse',
        'cash_transfer_created' => 'Transfert enregistré',
        default => ucfirst(str_replace('_', ' ', (string) $actionName)),
    };
}

/**
 * @param array<string, mixed> $row
 */
function nominative_timeline_sentence(array $row, string $currencyCode, DateTimeZone $tz): string
{
    $name = mb_strtoupper(trim((string) ($row['actor_name'] ?? '')), 'UTF-8');
    $role = restaurant_role_label((string) ($row['actor_role_code'] ?? ''));
    $action = report_audit_action_label((string) ($row['action_name'] ?? ''));
    $when = format_date_fr($row['created_at'] ?? null, $tz);

    $detail = trim((string) ($row['timeline_detail'] ?? ''));
    if ($detail === '' && !empty($row['new_values_json'])) {
        $decoded = json_decode((string) $row['new_values_json'], true);
        if (is_array($decoded)) {
            $chunks = [];
            foreach ($decoded as $k => $v) {
                $chunks[] = (string) $k . ' : ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            $detail = implode(', ', $chunks);
        }
    }
    if ($detail === '' && !empty($row['justification'])) {
        $detail = trim((string) $row['justification']);
    }

    $amount = '';
    if (isset($row['line_amount']) && (float) $row['line_amount'] !== 0.0) {
        $amount = format_money((float) $row['line_amount'], $currencyCode);
    }

    $mid = array_filter([$detail !== '' ? $detail : null, $amount !== '' ? $amount : null], static fn ($v) => $v !== null);

    return trim($name . ' — ' . $role . ' — ' . $action . ( $mid !== [] ? ' — ' . implode(' — ', $mid) : '' ) . ' — ' . $when);
}

function yes_no_label(mixed $value): string
{
    return ((int) $value === 1 || $value === true || $value === '1') ? 'Oui' : 'Non';
}

function restaurant_role_label(?string $roleCode): string
{
    return match ($roleCode) {
        'owner' => 'Propriétaire',
        'manager' => 'Gérant',
        'stock_manager' => 'Responsable stock',
        'cashier_accountant' => 'Caissier / comptable',
        'cashier_server' => 'Serveur / caissier',
        default => role_label($roleCode),
    };
}

function permission_module_label(?string $moduleCode): string
{
    return match ((string) $moduleCode) {
        'branding' => 'Image du restaurant',
        'dashboard', 'owner', 'owner.dashboard' => 'Tableau de bord',
        'cash', 'cashier', 'finance' => 'Caisse',
        'incidents' => 'Décisions gérant',
        'kitchen' => 'Cuisine',
        'menu' => 'Menu',
        'reports' => 'Rapports',
        'roles' => 'Rôles',
        'sales', 'orders' => 'Ventes',
        'settings' => 'Paramètres',
        'stock', 'losses' => 'Stock',
        'tenant_management' => 'Restaurants',
        'users' => 'Utilisateurs',
        default => ucfirst(str_replace('_', ' ', (string) $moduleCode)),
    };
}

function permission_label(?string $permissionCode): string
{
    return match ((string) $permissionCode) {
        'audit.view' => 'Voir le journal d’audit',
        'branding.manage' => 'Gérer l’image du restaurant',
        'incidents.confirm' => 'Confirmer techniquement un incident',
        'incidents.decide' => 'Décider les cas complexes',
        'incidents.signal' => 'Signaler un incident',
        'kitchen.manage' => 'Gérer la cuisine',
        'losses.manage' => 'Déclarer et valider les pertes',
        'menu.manage' => 'Gérer le menu',
        'orders.place' => 'Accès client de base',
        'reports.daily' => 'Voir le rapport journalier',
        'reports.view' => 'Voir les rapports',
        'roles.manage' => 'Gérer les rôles',
        'sales.manage' => 'Gérer les ventes',
        'settings.manage' => 'Gérer les paramètres',
        'stock.manage' => 'Gérer le stock',
        'tenant_management.create' => 'Créer un restaurant',
        'tenant_management.suspend' => 'Suspendre ou bannir un restaurant',
        'tenant_management.view' => 'Voir les restaurants',
        'users.manage' => 'Gérer les utilisateurs',
        default => (string) $permissionCode,
    };
}

function permission_description_fr(?string $permissionCode): string
{
    return match ((string) $permissionCode) {
        'audit.view' => 'Consulter les traces sensibles de la plateforme.',
        'branding.manage' => 'Mettre à jour le nom public, les visuels et le portail.',
        'incidents.confirm' => 'Confirmer les incidents côté cuisine ou technique.',
        'incidents.decide' => 'Trancher les situations complexes avec justification.',
        'incidents.signal' => 'Remonter un incident ou un retour anormal.',
        'kitchen.manage' => 'Accéder à la cuisine et traiter la production.',
        'losses.manage' => 'Déclarer ou valider les pertes matière et argent.',
        'menu.manage' => 'Créer, modifier et publier les éléments du menu.',
        'orders.place' => 'Utiliser les parcours client publics quand ils sont activés.',
        'reports.daily' => 'Lire le détail quotidien des rapports.',
        'reports.view' => 'Accéder aux synthèses et rapports opérationnels.',
        'roles.manage' => 'Créer des rôles et gérer les accès.',
        'sales.manage' => 'Accéder aux ventes, retours et demandes serveur.',
        'settings.manage' => 'Modifier les règles et seuils globaux.',
        'stock.manage' => 'Gérer les entrées, sorties et retours de stock.',
        'tenant_management.create' => 'Créer un nouveau restaurant dans la plateforme.',
        'tenant_management.suspend' => 'Suspendre, bannir ou réactiver un restaurant.',
        'tenant_management.view' => 'Consulter la liste et la fiche des restaurants.',
        'users.manage' => 'Créer, affecter et mettre à jour les utilisateurs.',
        default => '',
    };
}

/**
 * Ligne carte identifiée comme boisson (catégorie) — même règle que la logique cuisine / stock.
 */
function menu_line_is_beverage(?string $categoryName, ?string $categorySlug): bool
{
    $n = mb_strtolower(trim((string) $categoryName));
    $s = mb_strtolower(trim((string) $categorySlug));
    if ($n === 'boisson' || $s === 'boisson' || $s === 'boissons') {
        return true;
    }

    return str_contains($n, 'boisson') || str_contains($s, 'boisson');
}

function restaurant_currency(array|int|string|null $restaurant = null): string
{
    $candidate = null;

    if (is_array($restaurant)) {
        $candidate = $restaurant['currency'] ?? $restaurant['currency_code'] ?? null;
    } elseif (is_string($restaurant) && $restaurant !== '') {
        $candidate = $restaurant;
    } elseif (is_int($restaurant) && $restaurant > 0) {
        $context = App\Core\Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurant);
        $candidate = $context['currency'] ?? $context['currency_code'] ?? null;
    } else {
        $context = current_restaurant_context();
        $candidate = $context['currency'] ?? $context['currency_code'] ?? null;
    }

    $normalized = strtoupper((string) $candidate);

    return in_array($normalized, ['USD', 'CDF'], true) ? $normalized : 'USD';
}

function format_money(mixed $amount, array|int|string|null $currency = null): string
{
    $resolvedCurrency = restaurant_currency($currency);
    $numericAmount = (float) $amount;

    if ($resolvedCurrency === 'CDF') {
        $formatted = number_format($numericAmount, abs($numericAmount - round($numericAmount)) < 0.00001 ? 0 : 2, '.', ' ');
        return $formatted . ' FC';
    }

    return '$' . number_format($numericAmount, 2, '.', ',');
}

function ui_safe_message(?string $message, string $fallback = 'Action impossible pour le moment. Veuillez reessayer ou contacter l administrateur.'): string
{
    $clean = trim((string) $message);
    if ($clean === '') {
        return $fallback;
    }

    $technicalMarkers = [
        'sqlstate',
        'stack trace',
        'fatal error',
        'warning:',
        'notice:',
        'undefined',
        'pdoexception',
        'exception',
        ' in ',
        ' on line ',
        'call to',
        'argument ',
        'profil',
        'db error',
    ];

    $normalized = strtolower($clean);
    foreach ($technicalMarkers as $marker) {
        if (str_contains($normalized, $marker)) {
            return $fallback;
        }
    }

    return $clean;
}

function named_actor_label(?string $name, ?string $roleCode = null): string
{
    $cleanName = trim((string) $name);
    $roleLabel = trim(restaurant_role_label($roleCode));

    if ($cleanName === '' && $roleLabel === '') {
        return 'Agent non identifie';
    }

    if ($cleanName === '') {
        return $roleLabel;
    }

    if ($roleLabel === '') {
        return $cleanName;
    }

    return $roleLabel . ' ' . $cleanName;
}

function cash_transfer_status_label(?string $status): string
{
    return match ((string) $status) {
        'REMIS_A_CAISSE' => 'Remis par le serveur',
        'SOUMIS_GERANT' => 'Soumis au gerant (decision en attente)',
        'REMISE_REJETEE_CAISSE' => 'Remise rejetee par la caisse',
        'REMISE_REJETEE_GERANT' => 'Remise rejetee par le gerant',
        'RECU_CAISSE' => 'Recu par la caisse',
        'ECART_SIGNALE' => 'Ecart signale',
        'REMIS_A_GERANT' => 'Remis au gerant',
        'RECU_GERANT' => 'Recu par le gerant',
        'REMIS_A_PROPRIETAIRE' => 'Remis au proprietaire',
        'RECU_PROPRIETAIRE' => 'Recu par le proprietaire',
        default => validation_status_label($status),
    };
}

function signed_actor_line(
    string $actionLabel,
    ?string $name,
    ?string $roleCode = null,
    ?string $at = null,
    array|int|string|null $restaurant = null,
    ?DateTimeZone $timezone = null
): string {
    $parts = [trim($actionLabel) . ' par ' . named_actor_label($name, $roleCode)];

    if ($at !== null && trim($at) !== '') {
        $parts[] = format_date_fr($at, $timezone);
    }

    $restaurantContext = is_array($restaurant) ? $restaurant : current_restaurant_context();
    $restaurantName = trim((string) ($restaurantContext['public_name'] ?? $restaurantContext['name'] ?? ''));
    if ($restaurantName !== '') {
        $parts[] = $restaurantName;
    }

    return implode(' - ', $parts);
}

/**
 * Ligne compacte pour historique : « Annulée / Déclinée par [nom rôle] — motif — date ».
 */
function request_terminal_resolution_line(
    string $kind,
    ?string $actorName,
    ?string $roleCode,
    string $motif,
    ?string $at,
    ?DateTimeZone $timezone = null
): string {
    $verb = $kind === 'declinee' ? 'Declinee' : 'Annulee';
    $who = named_actor_label($actorName, $roleCode);
    $motif = trim($motif);
    $when = ($at !== null && trim((string) $at) !== '') ? format_date_fr($at, $timezone) : '';

    return $verb . ' par ' . $who . ' — ' . ($motif !== '' ? $motif : '—') . ' — ' . ($when !== '' ? $when : '—');
}

function movement_type_label(?string $type): string
{
    return match ($type) {
        'ENTREE' => 'Entrée',
        'SORTIE_CUISINE' => 'Sortie cuisine',
        'SORTIE' => 'Sortie stock',
        'RETOUR_STOCK' => 'Retour stock',
        'PERTE' => 'Perte',
        'CONSOMMATION_CUISINE' => 'Consommation cuisine',
        'CORRECTION_INVENTAIRE' => 'Correction inventaire',
        default => (string) $type,
    };
}

function subscription_status_label(?string $status): string
{
    return match ($status) {
        'DRAFT' => 'Brouillon',
        'PENDING_PAYMENT' => 'En attente de paiement',
        'PENDING_VALIDATION' => 'En attente de validation',
        'ACTIVE' => 'Actif',
        'SUSPENDED' => 'Suspendu',
        'EXPIRED' => 'Expiré',
        'GRACE_PERIOD' => 'En grâce',
        default => status_label($status),
    };
}

function subscription_payment_label(?string $status): string
{
    return match ($status) {
        'UNPAID' => 'Non payé',
        'DECLARED' => 'Paiement déclaré',
        'PAID' => 'Payé',
        'WAIVED' => 'Exempté',
        default => (string) $status,
    };
}

function format_date_fr(?string $value, ?DateTimeZone $timezone = null): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        $targetTimezone = $timezone ?? safe_timezone();

        return (new DateTimeImmutable($value, safe_timezone()))->setTimezone($targetTimezone)->format('d/m/Y H:i');
    } catch (\Throwable) {
        return $value;
    }
}

function validation_status_label(?string $status): string
{
    return match ($status) {
        'PROVISOIRE' => 'Provisoire',
        'VALIDE' => 'Validé',
        'EN_COURS' => 'En cours',
        'DEMANDE' => 'En attente',
        'EN_PREPARATION' => 'En préparation',
        'PRET_A_SERVIR' => 'Prêt à servir',
        'REMIS_SERVEUR' => 'Remis au serveur',
        'FOURNI_TOTAL' => 'Fourni totalement',
        'FOURNI_PARTIEL' => 'Fourni partiellement',
        'NON_FOURNI' => 'Non fourni',
        'PROPOSE' => 'Proposé',
        'CONFIRME_TECHNIQUEMENT' => 'Confirmé techniquement',
        'EN_ATTENTE_VALIDATION_MANAGER' => 'En attente manager',
        'REJETE' => 'Rejeté',
        'CLASSE_EN_PERTE' => 'Classé en perte',
        'CLASSE_EN_RETOUR_SIMPLE' => 'Classé en retour simple',
        'TERMINE' => 'Terminé',
        'SERVI' => 'Servi',
        'RETOUR' => 'Retour',
        'SUR_PLACE' => 'Sur place',
        'LIVRAISON' => 'Livraison',
        'ANNULÉ', 'ANNULE' => 'Annulé',
        default => (string) $status,
    };
}

function loss_type_label(?string $type): string
{
    return match ($type) {
        'MATIERE_PREMIERE' => 'Matière première',
        'ARGENT' => 'Argent',
        default => (string) $type,
    };
}

function service_flow_status_label(?string $status): string
{
    return match ($status) {
        'DEMANDE' => 'En attente',
        'EN_PREPARATION' => 'En préparation',
        'PRET_A_SERVIR' => 'Prêt à servir',
        'REMIS_SERVEUR' => 'Remis au serveur',
        'CLOTURE', 'VENDU_PARTIEL', 'VENDU_TOTAL' => 'Clôturé',
        'FOURNI_TOTAL' => 'Prêt à servir',
        'FOURNI_PARTIEL' => 'En préparation',
        'NON_FOURNI' => 'Non disponible',
        'ANNULE' => 'Annulée (service)',
        'REFUSE_CUISINE' => 'Déclinée cuisine',
        'REFUSE_STOCK' => 'Déclinée stock',
        default => validation_status_label($status),
    };
}

function stock_request_status_label(?string $status): string
{
    return match ($status) {
        'DEMANDE' => 'En attente',
        'EN_COURS_TRAITEMENT' => 'En cours de traitement',
        'FOURNI_TOTAL', 'DISPONIBLE' => 'Fourni totalement',
        'FOURNI_PARTIEL', 'PARTIELLEMENT_DISPONIBLE' => 'Fourni partiellement',
        'NON_FOURNI', 'INDISPONIBLE' => 'Non fourni',
        'CLOTURE' => 'Clôturé',
        'ANNULE' => 'Annulée (cuisine)',
        'REFUSE_STOCK' => 'Déclinée stock',
        default => validation_status_label($status),
    };
}

function priority_label(?string $priority): string
{
    return match ($priority) {
        'urgente' => 'Urgente',
        'normale', null, '' => 'Normale',
        default => (string) $priority,
    };
}

function case_source_label(?string $sourceModule): string
{
    return match ($sourceModule) {
        'sales' => 'Service salle',
        'kitchen' => 'Cuisine',
        'stock' => 'Stock',
        default => (string) $sourceModule,
    };
}

function case_responsibility_label(?string $scope, ?string $responsibleName = null): string
{
    return match ($scope) {
        'agent_lie' => $responsibleName !== null && trim($responsibleName) !== ''
            ? 'Agent lié : ' . $responsibleName
            : 'Agent lié',
        'sans_faute_individuelle' => 'Sans faute individuelle',
        'restaurant', null, '' => 'Restaurant',
        default => (string) $scope,
    };
}

/**
 * Regroupe le libellé catégorie stock pour filtres (heuristique métier, insensible à la casse).
 */
function stock_item_category_bucket(?string $categoryLabel): string
{
    $l = mb_strtolower(trim((string) $categoryLabel), 'UTF-8');
    if ($l === '') {
        return 'uncat';
    }

    if (preg_match('/\bboisson|soda|jus\b|\bvin\b|bi[eè]re|caf[eé]\b|th[eé]\b|spirit|whisky|coca|sprite|soft|liqueur|champagne|cola|energy|\beau\b/i', $l)) {
        return 'boissons';
    }

    if (preg_match('/viande|volaille|poisson|l[eé]gume|fruit|[eé]pice|farine|huile|\briz\b|p[aâ]te|mati[eè]re|cuisine|frais|surgel|boucher|boulange|laitier|fromage|œuf|oeuf|tomate|oignon/i', $l)) {
        return 'cuisine';
    }

    return 'autres';
}

/**
 * @param list<array<string, mixed>> $items Lignes stock_items du restaurant
 *
 * @return list<int>|null null = pas de filtre (tout afficher)
 */
function stock_item_ids_matching_category_filter(array $items, string $filter): ?array
{
    $filter = trim($filter);
    if ($filter === '' || strcasecmp($filter, 'all') === 0) {
        return null;
    }

    if (str_starts_with($filter, 'exact:')) {
        $label = rawurldecode(substr($filter, 6));
        $ids = [];
        foreach ($items as $it) {
            if (trim((string) ($it['category_label'] ?? '')) === $label) {
                $ids[] = (int) $it['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    if (str_starts_with($filter, 'bucket_')) {
        $bucket = substr($filter, 7);
        $ids = [];
        foreach ($items as $it) {
            if (stock_item_category_bucket($it['category_label'] ?? null) === $bucket) {
                $ids[] = (int) $it['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    return null;
}
