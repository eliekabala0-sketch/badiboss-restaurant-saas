<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use PDO;
use Throwable;

final class RailwayDbTools
{
    private const CRITICAL_TABLES = [
        'subscription_plans', 'restaurants', 'restaurant_branding', 'users', 'roles', 'permissions',
        'role_permissions', 'settings', 'menu_categories', 'menu_items', 'stock_items', 'stock_movements',
        'kitchen_production', 'sales', 'server_requests', 'server_request_items', 'kitchen_stock_requests',
        'losses', 'operation_cases', 'audit_logs', 'correction_requests',
    ];

    private const RUNTIME_COLUMN_DEFS = [
        'server_requests' => [
            ['name' => 'service_reference', 'sql' => 'ALTER TABLE server_requests ADD COLUMN service_reference VARCHAR(120) NULL AFTER server_id'],
            ['name' => 'ready_by', 'sql' => 'ALTER TABLE server_requests ADD COLUMN ready_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by'],
            ['name' => 'received_by', 'sql' => 'ALTER TABLE server_requests ADD COLUMN received_by BIGINT UNSIGNED NULL AFTER ready_by'],
            ['name' => 'ready_at', 'sql' => 'ALTER TABLE server_requests ADD COLUMN ready_at DATETIME NULL AFTER supplied_at'],
            ['name' => 'received_at', 'sql' => 'ALTER TABLE server_requests ADD COLUMN received_at DATETIME NULL AFTER ready_at'],
        ],
        'server_request_items' => [
            ['name' => 'unavailable_quantity', 'sql' => 'ALTER TABLE server_request_items ADD COLUMN unavailable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER supplied_quantity'],
            ['name' => 'prepared_at', 'sql' => 'ALTER TABLE server_request_items ADD COLUMN prepared_at DATETIME NULL AFTER technical_confirmed_by'],
            ['name' => 'received_by', 'sql' => 'ALTER TABLE server_request_items ADD COLUMN received_by BIGINT UNSIGNED NULL AFTER prepared_at'],
            ['name' => 'received_at', 'sql' => 'ALTER TABLE server_request_items ADD COLUMN received_at DATETIME NULL AFTER received_by'],
        ],
        'kitchen_stock_requests' => [
            ['name' => 'priority_level', 'sql' => 'ALTER TABLE kitchen_stock_requests ADD COLUMN priority_level ENUM(\'normale\', \'urgente\') NOT NULL DEFAULT \'normale\' AFTER status'],
            ['name' => 'unavailable_quantity', 'sql' => 'ALTER TABLE kitchen_stock_requests ADD COLUMN unavailable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_supplied'],
            ['name' => 'received_by', 'sql' => 'ALTER TABLE kitchen_stock_requests ADD COLUMN received_by BIGINT UNSIGNED NULL AFTER responded_by'],
            ['name' => 'received_at', 'sql' => 'ALTER TABLE kitchen_stock_requests ADD COLUMN received_at DATETIME NULL AFTER responded_at'],
        ],
        'operation_cases' => [
            ['name' => 'responsible_user_id', 'sql' => 'ALTER TABLE operation_cases ADD COLUMN responsible_user_id BIGINT UNSIGNED NULL AFTER responsibility_scope'],
            ['name' => 'submitted_to_manager_by', 'sql' => 'ALTER TABLE operation_cases ADD COLUMN submitted_to_manager_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by'],
            ['name' => 'submitted_to_manager_at', 'sql' => 'ALTER TABLE operation_cases ADD COLUMN submitted_to_manager_at DATETIME NULL AFTER technical_confirmed_at'],
            ['name' => 'trace_snapshot_json', 'sql' => 'ALTER TABLE operation_cases ADD COLUMN trace_snapshot_json LONGTEXT NULL AFTER manager_justification'],
        ],
        'stock_items' => [
            ['name' => 'category_label', 'sql' => 'ALTER TABLE stock_items ADD COLUMN category_label VARCHAR(120) NULL AFTER unit_name'],
            ['name' => 'item_note', 'sql' => 'ALTER TABLE stock_items ADD COLUMN item_note TEXT NULL AFTER estimated_unit_cost'],
            ['name' => 'updated_at', 'sql' => 'ALTER TABLE stock_items ADD COLUMN updated_at DATETIME NULL AFTER created_at'],
        ],
    ];

    public static function install(array $config): array
    {
        $db = new Database($config['database']);
        $pdo = $db->pdo();
        $active = $db->config();
        $report = [
            'database' => self::dbMeta($active),
            'created_tables' => [],
            'existing_tables' => [],
            'ignored_errors' => [],
            'blocking_errors' => [],
            'critical_tables' => [],
        ];

        $schema = @file_get_contents(BASE_PATH . '/database/schema.sql');
        if ($schema === false) {
            $report['blocking_errors'][] = 'schema.sql introuvable';
            return $report;
        }

        foreach (self::splitSchema($schema) as $statement) {
            $table = self::extractTable($statement);
            try {
                $pdo->exec($statement);
                if ($table !== null) {
                    $report['created_tables'][] = $table;
                }
            } catch (Throwable $e) {
                $error = self::mask($e->getMessage());
                if (self::isTableExistsError($error)) {
                    if ($table !== null) {
                        $report['existing_tables'][] = $table;
                    }
                    $report['ignored_errors'][] = ($table ?? 'instruction_sql') . ' => ' . $error;
                    continue;
                }
                $report['blocking_errors'][] = ($table ?? 'instruction_sql') . ' => ' . $error;
            }
        }

        $report['created_tables'] = array_values(array_unique($report['created_tables']));
        $report['existing_tables'] = array_values(array_unique($report['existing_tables']));
        foreach (self::CRITICAL_TABLES as $table) {
            $report['critical_tables'][$table] = self::tableExists($pdo, $table) ? 'OK' : 'MANQUANTE';
        }

        return $report;
    }

    public static function seedMinimal(array $config): array
    {
        $db = new Database($config['database']);
        $pdo = $db->pdo();
        $active = $db->config();
        $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $report = ['database' => self::dbMeta($active), 'created' => [], 'updated' => [], 'errors' => [], 'accounts' => []];

        try {
            $pdo->beginTransaction();
            self::upsertPlan($pdo, ['Starter', 'starter', 'Plan de base pour petit restaurant', 49.99, 499.00, 15, 1, json_encode(['dashboard','branding','reports_basic'], JSON_UNESCAPED_UNICODE), 'active'], $report);
            self::upsertPlan($pdo, ['Business', 'business', 'Plan multi-equipes avec modules avancables', 119.99, 1199.00, 80, 1, json_encode(['dashboard','branding','reports_advanced','pwa'], JSON_UNESCAPED_UNICODE), 'active'], $report);
            $starter = self::idBy($pdo, 'SELECT id FROM subscription_plans WHERE code = ? LIMIT 1', ['starter']);
            $business = self::idBy($pdo, 'SELECT id FROM subscription_plans WHERE code = ? LIMIT 1', ['business']);
            $r1 = self::upsertRestaurant($pdo, [$business, 'Badi Saveurs Gombe', 'badi-saveurs-gombe', 'badi-saveurs-gombe', 'Badi Saveurs SARL', 'active', 'ACTIVE', 'PAID', 'gombe@badiboss.test', '+243810000001', 'Republique Democratique du Congo', 'Kinshasa', 'Avenue du Commerce, Gombe', 'Africa/Kinshasa', 'USD', 'https://web-production-99f3.up.railway.app/portal/badi-saveurs-gombe'], $report);
            $r2 = self::upsertRestaurant($pdo, [$starter, 'Mboka Grill Lubumbashi', 'mboka-grill-lubumbashi', 'mboka-grill-lubumbashi', 'Mboka Grill SARL', 'active', 'GRACE_PERIOD', 'DECLARED', 'owner@mbokagrill.test', '+243820000002', 'Republique Democratique du Congo', 'Lubumbashi', 'Avenue Mama Yemo, Lubumbashi', 'Africa/Lubumbashi', 'USD', 'https://web-production-99f3.up.railway.app/portal/mboka-grill-lubumbashi'], $report);
            self::upsertBranding($pdo, [$r1, 'Badi Saveurs Gombe', '#0f766e', '#111827', '#f59e0b', 'gombe', null, 'Badi Saveurs', 'BadiSaveurs', 'Portail Badi Saveurs', 'Cuisine congolaise moderne a Gombe', 'Bienvenue chez Badi Saveurs Gombe.'], $report);
            self::upsertBranding($pdo, [$r2, 'Mboka Grill', '#9a3412', '#1f2937', '#16a34a', 'mbokagrill', 'commande.mbokagrill.cd', 'Mboka Grill', 'MbokaGrill', 'Portail Mboka Grill', 'Grillades et plats locaux a Lubumbashi', 'Bienvenue sur le portail Mboka Grill Lubumbashi.'], $report);
            foreach ([['Super Administrateur','super_admin','Fournisseur SaaS avec acces global'],['Proprietaire','owner','Lecture globale et controle du restaurant'],['Manager','manager','Validation operationnelle quotidienne'],['Gestionnaire Stock','stock_manager','Gestion des mouvements de stock'],['Cuisine','kitchen','Execution cuisine et confirmation technique'],['Serveur Caissier','cashier_server','Prise de commande et signalement terrain'],['Client','customer','Compte client final']] as $role) { self::upsertSystemRole($pdo, $role, $report); }
            foreach ([['tenant_management','view','tenant_management.view','Voir les restaurants SaaS',0],['tenant_management','create','tenant_management.create','Creer un restaurant client',1],['tenant_management','suspend','tenant_management.suspend','Suspendre un restaurant',1],['branding','manage','branding.manage','Gerer le branding du restaurant',1],['users','manage','users.manage','Gerer les utilisateurs',1],['reports','view','reports.view','Voir les rapports',0],['settings','manage','settings.manage','Modifier les regles et seuils',1],['audit','view','audit.view','Consulter le journal d audit',1],['menu','manage','menu.manage','Administrer categories et menus',1],['orders','place','orders.place','Passer ou enregistrer une commande',0],['stock','manage','stock.manage','Gerer le stock, les sorties et les retours',1],['kitchen','manage','kitchen.manage','Gerer la transformation cuisine',1],['sales','manage','sales.manage','Gerer les ventes et retours',1],['reports','daily','reports.daily','Consulter le rapport journalier detaille',1],['losses','manage','losses.manage','Declarer et valider les pertes',1],['roles','manage','roles.manage','Creer des roles et gerer leurs permissions',1],['incidents','signal','incidents.signal','Signaler un incident ou un retour anormal',0],['incidents','confirm','incidents.confirm','Confirmer techniquement un incident',1],['incidents','decide','incidents.decide','Trancher un cas avec justification manager',1]] as $permission) { self::upsertPermission($pdo, $permission, $report); }
            $map = ['super_admin'=>['tenant_management.view','tenant_management.create','tenant_management.suspend','branding.manage','users.manage','reports.view','settings.manage','audit.view','menu.manage','stock.manage','kitchen.manage','sales.manage','reports.daily','losses.manage','roles.manage','incidents.signal','incidents.confirm','incidents.decide'],'owner'=>['branding.manage','users.manage','reports.view','settings.manage','audit.view','menu.manage','stock.manage','kitchen.manage','sales.manage','reports.daily','losses.manage','roles.manage'],'manager'=>['reports.view','audit.view','kitchen.manage','sales.manage','reports.daily','losses.manage','roles.manage','incidents.decide'],'stock_manager'=>['stock.manage','incidents.signal'],'kitchen'=>['orders.place','kitchen.manage','incidents.signal','incidents.confirm'],'cashier_server'=>['orders.place','sales.manage','incidents.signal'],'customer'=>['orders.place']];
            foreach ($map as $roleCode => $permissionCodes) { $roleId = self::idBy($pdo, 'SELECT id FROM roles WHERE restaurant_id IS NULL AND scope = "system" AND code = ? LIMIT 1', [$roleCode]); foreach ($permissionCodes as $permissionCode) { self::upsertRolePermission($pdo, $roleId, self::idBy($pdo, 'SELECT id FROM permissions WHERE code = ? LIMIT 1', [$permissionCode]), $report); } }
            foreach ([[null,'saas.default_return_window_hours','24','integer'],[null,'saas.allow_custom_domains','1','boolean'],[null,'saas.pwa_enabled_by_default','1','boolean'],[$r1,'restaurant_reports_timezone','Africa/Kinshasa','string'],[$r1,'restaurant_feature_pwa_enabled','1','boolean'],[$r2,'restaurant_reports_timezone','Africa/Lubumbashi','string'],[$r2,'restaurant_feature_pwa_enabled','1','boolean']] as $setting) { self::upsertSetting($pdo, $setting, $report); }
            foreach ([[null,'super_admin','Super Administrateur Plateforme','superadmin@badiboss.test','+243810000100',0],[$r1,'owner','Proprietaire Badi Saveurs','owner-gombe@badiboss.test','+243810000101',0],[$r1,'manager','Manager Badi Saveurs','manager-gombe@badiboss.test','+243810000102',1],[$r1,'stock_manager','Responsable Stock Gombe','stock-gombe@badiboss.test','+243810000103',1],[$r1,'kitchen','Cuisine Gombe','kitchen-gombe@badiboss.test','+243810000104',1],[$r1,'cashier_server','Serveur Gombe','server-gombe@badiboss.test','+243810000105',1]] as $user) { self::upsertUser($pdo, [$user[0], self::idBy($pdo, 'SELECT id FROM roles WHERE restaurant_id IS NULL AND scope = "system" AND code = ? LIMIT 1', [$user[1]]), $user[2], $user[3], $user[4], $hash, 'active', $user[5]], $report); $report['accounts'][] = $user[3] . ' / password'; }
            $configuredBy = self::idBy($pdo, 'SELECT id FROM users WHERE email = ? LIMIT 1', ['superadmin@badiboss.test']);
            foreach (['branding','reports','menu','pwa','stock','kitchen','sales','losses'] as $module) { self::upsertModule($pdo, $r1, $module, $configuredBy, $report); self::upsertModule($pdo, $r2, $module, $configuredBy, $report); }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $report['errors'][] = self::mask($e->getMessage());
        }

        return $report;
    }

    public static function runtimeRepair(array $config): array
    {
        $db = new Database($config['database']);
        $pdo = $db->pdo();
        $active = $db->config();
        $report = [
            'database' => self::dbMeta($active),
            'counts_before' => self::runtimeCounts($pdo),
            'plans_before' => self::listPlans($pdo),
            'columns_added' => [],
            'columns_existing' => [],
            'enum_updates' => [],
            'data_backfills' => [],
            'plan_repairs' => [],
            'errors' => [],
            'counts_after' => [],
            'plans_after' => [],
        ];

        try {
            self::ensureOperationalPlans($pdo, $report);
            self::ensureRestaurantCurrency($pdo, $report);
            self::ensureCorrectionRequestsTable($pdo, $report);

            foreach (self::RUNTIME_COLUMN_DEFS as $table => $columns) {
                foreach ($columns as $column) {
                    if (self::columnExists($pdo, $table, $column['name'])) {
                        $report['columns_existing'][] = $table . '.' . $column['name'];
                        continue;
                    }

                    $pdo->exec($column['sql']);
                    $report['columns_added'][] = $table . '.' . $column['name'];
                }
            }

            $pdo->exec("ALTER TABLE server_requests MODIFY status ENUM('DEMANDE','EN_PREPARATION','PRET_A_SERVIR','REMIS_SERVEUR','FOURNI_PARTIEL','FOURNI_TOTAL','VENDU_PARTIEL','VENDU_TOTAL','CLOTURE') NOT NULL DEFAULT 'DEMANDE'");
            $report['enum_updates'][] = 'server_requests.status';

            $pdo->exec("ALTER TABLE server_request_items MODIFY supply_status ENUM('DEMANDE','EN_PREPARATION','PRET_A_SERVIR','REMIS_SERVEUR','FOURNI_TOTAL','FOURNI_PARTIEL','NON_FOURNI','CLOTURE') NOT NULL DEFAULT 'DEMANDE'");
            $report['enum_updates'][] = 'server_request_items.supply_status';

            $pdo->exec("UPDATE kitchen_stock_requests SET status = 'FOURNI_TOTAL' WHERE status = 'DISPONIBLE'");
            $report['data_backfills'][] = 'kitchen_stock_requests.status DISPONIBLE -> FOURNI_TOTAL';
            $pdo->exec("UPDATE kitchen_stock_requests SET status = 'FOURNI_PARTIEL' WHERE status = 'PARTIELLEMENT_DISPONIBLE'");
            $report['data_backfills'][] = 'kitchen_stock_requests.status PARTIELLEMENT_DISPONIBLE -> FOURNI_PARTIEL';
            $pdo->exec("UPDATE kitchen_stock_requests SET status = 'NON_FOURNI' WHERE status = 'INDISPONIBLE'");
            $report['data_backfills'][] = 'kitchen_stock_requests.status INDISPONIBLE -> NON_FOURNI';

            $pdo->exec("ALTER TABLE kitchen_stock_requests MODIFY status ENUM('DEMANDE','EN_COURS_TRAITEMENT','FOURNI_TOTAL','FOURNI_PARTIEL','NON_FOURNI','DISPONIBLE','PARTIELLEMENT_DISPONIBLE','INDISPONIBLE','CLOTURE') NOT NULL DEFAULT 'DEMANDE'");
            $report['enum_updates'][] = 'kitchen_stock_requests.status';

            if (self::columnExists($pdo, 'server_request_items', 'unavailable_quantity')) {
                $pdo->exec('UPDATE server_request_items SET unavailable_quantity = GREATEST(requested_quantity - supplied_quantity, 0) WHERE unavailable_quantity = 0');
                $report['data_backfills'][] = 'server_request_items.unavailable_quantity';
            }

            if (self::columnExists($pdo, 'kitchen_stock_requests', 'unavailable_quantity')) {
                $pdo->exec('UPDATE kitchen_stock_requests SET unavailable_quantity = GREATEST(quantity_requested - quantity_supplied, 0) WHERE unavailable_quantity = 0');
                $report['data_backfills'][] = 'kitchen_stock_requests.unavailable_quantity';
            }

        } catch (Throwable $e) {
            $report['errors'][] = self::mask($e->getMessage());
        }

        $report['counts_after'] = self::runtimeCounts($pdo);
        $report['plans_after'] = self::listPlans($pdo);
        return $report;
    }

    public static function cleanupTestServerRequest(array $config, string $serviceReference): array
    {
        $db = new Database($config['database']);
        $pdo = $db->pdo();
        $active = $db->config();
        $serviceReference = trim($serviceReference);

        $report = [
            'database' => self::dbMeta($active),
            'service_reference' => $serviceReference,
            'found' => [
                'server_requests' => [],
                'server_request_items' => [],
                'sales' => [],
                'sale_items' => [],
                'operation_cases' => [],
                'audit_logs' => [],
            ],
            'deleted' => [
                'audit_logs' => 0,
                'operation_cases' => 0,
                'sale_items' => 0,
                'sales' => 0,
                'server_request_items' => 0,
                'server_requests' => 0,
            ],
            'status' => 'noop',
            'errors' => [],
        ];

        if ($serviceReference === '') {
            $report['errors'][] = 'service_reference vide';
            $report['status'] = 'error';
            return $report;
        }

        try {
            $requestStatement = $pdo->prepare(
                'SELECT sr.id, sr.restaurant_id, sr.server_id, sr.status, sr.service_reference, sr.note, sr.created_at, r.name AS restaurant_name
                 FROM server_requests sr
                 INNER JOIN restaurants r ON r.id = sr.restaurant_id
                 WHERE sr.service_reference = :service_reference
                 ORDER BY sr.id ASC'
            );
            $requestStatement->execute(['service_reference' => $serviceReference]);
            $requests = $requestStatement->fetchAll(PDO::FETCH_ASSOC);
            $report['found']['server_requests'] = $requests;

            if ($requests === []) {
                $report['status'] = 'not_found';
                return $report;
            }

            $requestIds = array_map(static fn (array $row): int => (int) $row['id'], $requests);
            $requestIdList = implode(',', array_map('intval', $requestIds));

            $items = $pdo->query(
                'SELECT id, request_id, menu_item_id, status, requested_quantity, supplied_quantity, sold_quantity, returned_quantity
                 FROM server_request_items
                 WHERE request_id IN (' . $requestIdList . ')
                 ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $report['found']['server_request_items'] = $items;
            $itemIds = array_map(static fn (array $row): int => (int) $row['id'], $items);

            $sales = $pdo->query(
                'SELECT id, restaurant_id, status, origin_type, origin_id, total_amount, note, created_at
                 FROM sales
                 WHERE origin_type = "server_request"
                   AND origin_id IN (' . $requestIdList . ')
                 ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $report['found']['sales'] = $sales;
            $saleIds = array_map(static fn (array $row): int => (int) $row['id'], $sales);

            if ($saleIds !== []) {
                $saleIdList = implode(',', array_map('intval', $saleIds));
                $saleItems = $pdo->query(
                    'SELECT id, sale_id, menu_item_id, quantity, unit_price, status, created_at
                     FROM sale_items
                     WHERE sale_id IN (' . $saleIdList . ')
                     ORDER BY id ASC'
                )->fetchAll(PDO::FETCH_ASSOC);
                $report['found']['sale_items'] = $saleItems;
            }

            $caseFilters = ['(source_entity_type = "server_requests" AND source_entity_id IN (' . $requestIdList . '))'];
            if ($itemIds !== []) {
                $itemIdList = implode(',', array_map('intval', $itemIds));
                $caseFilters[] = '(source_entity_type = "server_request_items" AND source_entity_id IN (' . $itemIdList . '))';
            }
            if ($saleIds !== []) {
                $saleIdList = implode(',', array_map('intval', $saleIds));
                $caseFilters[] = '(source_entity_type = "sales" AND source_entity_id IN (' . $saleIdList . '))';
            }

            $caseStatement = $pdo->query(
                'SELECT id, source_module, source_entity_type, source_entity_id, status, created_at
                 FROM operation_cases
                 WHERE ' . implode(' OR ', $caseFilters) . '
                 ORDER BY id ASC'
            );
            $report['found']['operation_cases'] = $caseStatement->fetchAll(PDO::FETCH_ASSOC);

            $auditFilters = ['(entity_type = "server_requests" AND entity_id IN (' . implode(',', array_map(static fn (int $id): string => $pdo->quote((string) $id), $requestIds)) . '))'];
            if ($itemIds !== []) {
                $auditFilters[] = '(entity_type = "server_request_items" AND entity_id IN (' . implode(',', array_map(static fn (int $id): string => $pdo->quote((string) $id), $itemIds)) . '))';
            }
            if ($saleIds !== []) {
                $auditFilters[] = '(entity_type = "sales" AND entity_id IN (' . implode(',', array_map(static fn (int $id): string => $pdo->quote((string) $id), $saleIds)) . '))';
            }
            $auditFilters[] = '(new_values_json LIKE ' . $pdo->quote('%' . $serviceReference . '%') . ')';
            $auditFilters[] = '(justification LIKE ' . $pdo->quote('%' . $serviceReference . '%') . ')';

            $auditStatement = $pdo->query(
                'SELECT id, module_name, action_name, entity_type, entity_id, created_at
                 FROM audit_logs
                 WHERE ' . implode(' OR ', $auditFilters) . '
                 ORDER BY id ASC'
            );
            $report['found']['audit_logs'] = $auditStatement->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            if ($report['found']['audit_logs'] !== []) {
                $auditIds = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $report['found']['audit_logs']));
                $report['deleted']['audit_logs'] = $pdo->exec('DELETE FROM audit_logs WHERE id IN (' . $auditIds . ')');
            }

            if ($report['found']['operation_cases'] !== []) {
                $caseIds = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $report['found']['operation_cases']));
                $report['deleted']['operation_cases'] = $pdo->exec('DELETE FROM operation_cases WHERE id IN (' . $caseIds . ')');
            }

            if ($saleIds !== []) {
                $saleIdList = implode(',', array_map('intval', $saleIds));
                $report['deleted']['sale_items'] = $pdo->exec('DELETE FROM sale_items WHERE sale_id IN (' . $saleIdList . ')');
                $report['deleted']['sales'] = $pdo->exec('DELETE FROM sales WHERE id IN (' . $saleIdList . ')');
            }

            if ($itemIds !== []) {
                $itemIdList = implode(',', array_map('intval', $itemIds));
                $report['deleted']['server_request_items'] = $pdo->exec('DELETE FROM server_request_items WHERE id IN (' . $itemIdList . ')');
            }

            $report['deleted']['server_requests'] = $pdo->exec('DELETE FROM server_requests WHERE id IN (' . $requestIdList . ')');

            $pdo->commit();
            $report['status'] = 'deleted';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $report['errors'][] = self::mask($e->getMessage());
            $report['status'] = 'error';
        }

        return $report;
    }

    public static function inspectTestServerRequest(array $config, string $serviceReference): array
    {
        $db = new Database($config['database']);
        $pdo = $db->pdo();
        $active = $db->config();
        $serviceReference = trim($serviceReference);

        $report = [
            'database' => self::dbMeta($active),
            'service_reference' => $serviceReference,
            'status' => 'not_found',
            'request' => null,
            'items' => [],
            'sales' => [],
            'sale_items' => [],
            'errors' => [],
        ];

        if ($serviceReference === '') {
            $report['status'] = 'error';
            $report['errors'][] = 'service_reference vide';
            return $report;
        }

        try {
            $requestStatement = $pdo->prepare(
                'SELECT sr.id, sr.restaurant_id, sr.server_id, sr.status, sr.service_reference, sr.total_requested_amount,
                        sr.total_supplied_amount, sr.total_sold_amount, sr.note, sr.created_at,
                        r.name AS restaurant_name, COALESCE(NULLIF(r.currency, ""), NULLIF(r.currency_code, ""), "USD") AS restaurant_currency
                 FROM server_requests sr
                 INNER JOIN restaurants r ON r.id = sr.restaurant_id
                 WHERE sr.service_reference = :service_reference
                 ORDER BY sr.id DESC
                 LIMIT 1'
            );
            $requestStatement->execute(['service_reference' => $serviceReference]);
            $requestRow = $requestStatement->fetch(PDO::FETCH_ASSOC);

            if ($requestRow === false) {
                return $report;
            }

            $report['request'] = $requestRow;
            $report['status'] = 'found';

            $itemsStatement = $pdo->prepare(
                'SELECT sri.id, sri.request_id, sri.menu_item_id, sri.requested_quantity, sri.supplied_quantity, sri.sold_quantity,
                        sri.unit_price, sri.requested_total, sri.note, sri.status, sri.supply_status,
                        mi.name AS menu_item_name, mi.price AS current_menu_price
                 FROM server_request_items sri
                 INNER JOIN menu_items mi ON mi.id = sri.menu_item_id
                 WHERE sri.request_id = :request_id
                 ORDER BY sri.id ASC'
            );
            $itemsStatement->execute(['request_id' => (int) $requestRow['id']]);
            $report['items'] = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);

            $salesStatement = $pdo->prepare(
                'SELECT id, status, sale_type, total_amount, origin_type, origin_id, note, created_at, validated_at
                 FROM sales
                 WHERE origin_type = "server_request" AND origin_id = :request_id
                 ORDER BY id ASC'
            );
            $salesStatement->execute(['request_id' => (int) $requestRow['id']]);
            $sales = $salesStatement->fetchAll(PDO::FETCH_ASSOC);
            $report['sales'] = $sales;

            if ($sales !== []) {
                $saleIds = implode(',', array_map(static fn (array $row): string => (string) ((int) $row['id']), $sales));
                $report['sale_items'] = $pdo->query(
                    'SELECT si.id, si.sale_id, si.menu_item_id, si.quantity, si.unit_price, si.status, mi.name AS menu_item_name
                     FROM sale_items si
                     INNER JOIN menu_items mi ON mi.id = si.menu_item_id
                     WHERE si.sale_id IN (' . $saleIds . ')
                     ORDER BY si.id ASC'
                )->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            $report['status'] = 'error';
            $report['errors'][] = self::mask($e->getMessage());
        }

        return $report;
    }

    public static function renderInstall(array $r): string
    {
        return implode(PHP_EOL, ['INSTALLATION / REPARATION DB RAILWAY', 'source=' . $r['database']['source'], 'host=' . $r['database']['host'], 'port=' . $r['database']['port'], 'database=' . $r['database']['database'], 'user=' . $r['database']['user'], '', 'TABLES CREEES:'] + []);
    }

    public static function renderInstallReport(array $r): string
    {
        $out = ['INSTALLATION / REPARATION DB RAILWAY', 'source=' . $r['database']['source'], 'host=' . $r['database']['host'], 'port=' . $r['database']['port'], 'database=' . $r['database']['database'], 'user=' . $r['database']['user'], '', 'TABLES CREEES:'];
        foreach ($r['created_tables'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'TABLES DEJA EXISTANTES:'; foreach ($r['existing_tables'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'ERREURS IGNOREES:'; foreach ($r['ignored_errors'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'ERREURS BLOQUANTES:'; foreach ($r['blocking_errors'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'TABLES CRITIQUES:'; foreach ($r['critical_tables'] as $k => $v) { $out[] = '- ' . $k . ' => ' . $v; }
        return implode(PHP_EOL, $out);
    }

    public static function renderSeedReport(array $r): string
    {
        $out = ['SEED MINIMAL RAILWAY', 'source=' . $r['database']['source'], 'host=' . $r['database']['host'], 'port=' . $r['database']['port'], 'database=' . $r['database']['database'], 'user=' . $r['database']['user'], '', 'ENTITES CREEES:'];
        foreach ($r['created'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'ENTITES MISES A JOUR:'; foreach ($r['updated'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'ERREURS:'; foreach ($r['errors'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = ''; $out[] = 'COMPTES DISPONIBLES:'; foreach ($r['accounts'] as $v) { $out[] = '- ' . $v; }
        return implode(PHP_EOL, $out);
    }

    public static function renderRuntimeRepairReport(array $r): string
    {
        $out = [
            'RUNTIME REPAIR RAILWAY',
            'source=' . $r['database']['source'],
            'host=' . $r['database']['host'],
            'port=' . $r['database']['port'],
            'database=' . $r['database']['database'],
            'user=' . $r['database']['user'],
            '',
            'COMPTAGES AVANT:',
        ];
        foreach ($r['counts_before'] as $k => $v) { $out[] = '- ' . $k . '=' . $v; }
        $out[] = '';
        $out[] = 'PLANS AVANT:';
        foreach ($r['plans_before'] ?: ['aucun'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'COLONNES AJOUTEES:';
        foreach ($r['columns_added'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'COLONNES DEJA PRESENTES:';
        foreach ($r['columns_existing'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'ENUMS MIS A JOUR:';
        foreach ($r['enum_updates'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'BACKFILLS:';
        foreach ($r['data_backfills'] ?: ['aucun'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'PLANS REPARÉS:';
        foreach ($r['plan_repairs'] ?: ['aucun'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'ERREURS:';
        foreach ($r['errors'] ?: ['aucune'] as $v) { $out[] = '- ' . $v; }
        $out[] = '';
        $out[] = 'COMPTAGES APRES:';
        foreach ($r['counts_after'] as $k => $v) { $out[] = '- ' . $k . '=' . $v; }
        $out[] = '';
        $out[] = 'PLANS APRES:';
        foreach ($r['plans_after'] ?: ['aucun'] as $v) { $out[] = '- ' . $v; }
        return implode(PHP_EOL, $out);
    }

    public static function renderCleanupTestServerRequestReport(array $r): string
    {
        $out = [
            'CLEANUP TEST SERVER REQUEST',
            'source=' . $r['database']['source'],
            'host=' . $r['database']['host'],
            'port=' . $r['database']['port'],
            'database=' . $r['database']['database'],
            'user=' . $r['database']['user'],
            'service_reference=' . $r['service_reference'],
            'status=' . $r['status'],
            '',
            'FOUND:',
            '- server_requests=' . count($r['found']['server_requests']),
            '- server_request_items=' . count($r['found']['server_request_items']),
            '- sales=' . count($r['found']['sales']),
            '- sale_items=' . count($r['found']['sale_items']),
            '- operation_cases=' . count($r['found']['operation_cases']),
            '- audit_logs=' . count($r['found']['audit_logs']),
            '',
            'DELETED:',
        ];

        foreach ($r['deleted'] as $key => $value) {
            $out[] = '- ' . $key . '=' . $value;
        }

        $out[] = '';
        $out[] = 'ERRORS:';
        foreach ($r['errors'] ?: ['aucune'] as $v) {
            $out[] = '- ' . $v;
        }

        return implode(PHP_EOL, $out);
    }

    public static function renderInspectTestServerRequestReport(array $r): string
    {
        $out = [
            'INSPECT TEST SERVER REQUEST',
            'source=' . $r['database']['source'],
            'host=' . $r['database']['host'],
            'port=' . $r['database']['port'],
            'database=' . $r['database']['database'],
            'user=' . $r['database']['user'],
            'service_reference=' . $r['service_reference'],
            'status=' . $r['status'],
            '',
        ];

        if ($r['request'] !== null) {
            $out[] = 'REQUEST:';
            $out[] = '- id=' . (string) $r['request']['id'];
            $out[] = '- restaurant=' . (string) $r['request']['restaurant_name'];
            $out[] = '- currency=' . (string) $r['request']['restaurant_currency'];
            $out[] = '- total_requested_amount=' . (string) $r['request']['total_requested_amount'];
            $out[] = '- total_sold_amount=' . (string) $r['request']['total_sold_amount'];
            $out[] = '- status=' . (string) $r['request']['status'];
            $out[] = '';
        }

        $out[] = 'ITEMS:';
        if ($r['items'] === []) {
            $out[] = '- none';
        } else {
            foreach ($r['items'] as $item) {
                $out[] = '- item_id=' . (string) $item['id']
                    . ' menu_item=' . (string) $item['menu_item_name']
                    . ' requested_quantity=' . (string) $item['requested_quantity']
                    . ' snapshot_unit_price=' . (string) $item['unit_price']
                    . ' current_menu_price=' . (string) $item['current_menu_price']
                    . ' requested_total=' . (string) $item['requested_total']
                    . ' note=' . (string) ($item['note'] ?? '');
            }
        }

        $out[] = '';
        $out[] = 'SALES:';
        if ($r['sales'] === []) {
            $out[] = '- none';
        } else {
            foreach ($r['sales'] as $sale) {
                $out[] = '- sale_id=' . (string) $sale['id']
                    . ' status=' . (string) $sale['status']
                    . ' total_amount=' . (string) $sale['total_amount']
                    . ' validated_at=' . (string) ($sale['validated_at'] ?? '');
            }
        }

        $out[] = '';
        $out[] = 'SALE_ITEMS:';
        if ($r['sale_items'] === []) {
            $out[] = '- none';
        } else {
            foreach ($r['sale_items'] as $item) {
                $out[] = '- sale_item_id=' . (string) $item['id']
                    . ' menu_item=' . (string) $item['menu_item_name']
                    . ' quantity=' . (string) $item['quantity']
                    . ' unit_price=' . (string) $item['unit_price']
                    . ' status=' . (string) $item['status'];
            }
        }

        $out[] = '';
        $out[] = 'ERRORS:';
        foreach ($r['errors'] ?: ['aucune'] as $error) {
            $out[] = '- ' . $error;
        }

        return implode(PHP_EOL, $out);
    }

    private static function dbMeta(array $c): array { return ['source' => (string) ($c['source'] ?? 'unknown'), 'host' => (string) ($c['host'] ?? 'unknown'), 'port' => (string) ($c['port'] ?? 'unknown'), 'database' => (string) ($c['database'] ?? 'unknown'), 'user' => (string) ($c['username'] ?? 'unknown')]; }
    private static function splitSchema(string $sql): array { $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql; $sql = preg_replace('/CREATE\s+DATABASE\b.*?;/is', '', $sql) ?? $sql; $sql = preg_replace('/USE\s+[a-zA-Z0-9_`]+\s*;/i', '', $sql) ?? $sql; $lines = preg_split('/\R/', $sql) ?: []; $keep = []; foreach ($lines as $line) { $t = trim($line); if ($t === '' || str_starts_with($t, '--')) { continue; } $keep[] = $line; } $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $keep)) ?: []; return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== '')); }
    private static function extractTable(string $sql): ?string { return preg_match('/^CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i', trim($sql), $m) === 1 ? $m[1] : null; }
    private static function isTableExistsError(string $m): bool { $m = strtolower($m); return str_contains($m, 'already exists') || str_contains($m, '42s01') || str_contains($m, '1050'); }
    private static function mask(string $m): string { $m = preg_replace('/password\s*=\s*[^;\s]+/i', 'password=***', $m) ?? $m; $m = preg_replace('/\/\/([^:@\/]+):([^@\/]+)@/', '//***:***@', $m) ?? $m; return trim($m); }
    private static function tableExists(PDO $pdo, string $table): bool { $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'); $s->execute([$table]); return (int) $s->fetchColumn() > 0; }
    private static function columnExists(PDO $pdo, string $table, string $column): bool { $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'); $s->execute([$table, $column]); return (int) $s->fetchColumn() > 0; }
    private static function runtimeCounts(PDO $pdo): array { $counts = []; foreach (['restaurants','users','audit_logs','sales','server_requests','kitchen_stock_requests'] as $table) { $counts[$table] = self::tableExists($pdo, $table) ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : -1; } return $counts; }
    private static function listPlans(PDO $pdo): array { if (!self::tableExists($pdo, 'subscription_plans')) { return []; } $rows = $pdo->query('SELECT id, code, status FROM subscription_plans ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC); return array_map(static fn (array $row): string => (string) $row['id'] . ':' . (string) $row['code'] . ':' . (string) $row['status'], $rows); }
    private static function idBy(PDO $pdo, string $sql, array $args): int { $s = $pdo->prepare($sql); $s->execute($args); return (int) $s->fetchColumn(); }
    private static function exists(PDO $pdo, string $sql, array $args): bool { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchColumn() !== false; }
    private static function add(array &$report, string $type, string $label): void { $report[$type][] = $label; }
    private static function ensureOperationalPlans(PDO $pdo, array &$report): void
    {
        foreach ([
            ['Starter', 'starter', 'Plan de base pour petit restaurant', 49.99, 499.00, 15, 1, json_encode(['dashboard','branding','reports_basic'], JSON_UNESCAPED_UNICODE), 'active'],
            ['Business', 'business', 'Plan multi-equipes avec modules avancables', 119.99, 1199.00, 80, 1, json_encode(['dashboard','branding','reports_advanced','pwa'], JSON_UNESCAPED_UNICODE), 'active'],
        ] as $plan) {
            self::upsertPlan($pdo, $plan, $report);
            $report['plan_repairs'][] = 'subscription_plans:' . $plan[1];
        }
    }
    private static function ensureRestaurantCurrency(PDO $pdo, array &$report): void
    {
        if (!self::columnExists($pdo, 'restaurants', 'currency')) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER currency_code");
            $report['columns_added'][] = 'restaurants.currency';
        } else {
            $report['columns_existing'][] = 'restaurants.currency';
        }

        $pdo->exec("UPDATE restaurants SET currency = CASE WHEN UPPER(COALESCE(NULLIF(currency_code, ''), 'USD')) IN ('USD', 'CDF') THEN UPPER(COALESCE(NULLIF(currency_code, ''), 'USD')) ELSE 'USD' END WHERE currency IS NULL OR currency = '' OR UPPER(currency) NOT IN ('USD', 'CDF')");
        $report['data_backfills'][] = 'restaurants.currency';
    }

    private static function ensureCorrectionRequestsTable(PDO $pdo, array &$report): void
    {
        if (self::tableExists($pdo, 'correction_requests')) {
            $report['columns_existing'][] = 'correction_requests.table';
            return;
        }

        $pdo->exec(
            'CREATE TABLE correction_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurant_id BIGINT UNSIGNED NOT NULL,
                module_name VARCHAR(80) NOT NULL,
                entity_type VARCHAR(120) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                request_type VARCHAR(120) NOT NULL,
                requested_by BIGINT UNSIGNED NOT NULL,
                requested_role_code VARCHAR(80) NOT NULL,
                status ENUM("PENDING","APPROVED","REJECTED") NOT NULL DEFAULT "PENDING",
                old_values_json JSON NULL,
                proposed_values_json JSON NULL,
                justification TEXT NOT NULL,
                review_notes TEXT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_correction_requests_restaurant_status (restaurant_id, status, created_at),
                CONSTRAINT fk_correction_requests_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
                CONSTRAINT fk_correction_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
                CONSTRAINT fk_correction_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id)
            )'
        );
        $report['columns_added'][] = 'correction_requests.table';
    }
    private static function upsertPlan(PDO $pdo, array $v, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM subscription_plans WHERE code = ? LIMIT 1', [$v[1]]); $s = $pdo->prepare('INSERT INTO subscription_plans (name, code, description, monthly_price, yearly_price, max_users, max_restaurants, features_json, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), monthly_price = VALUES(monthly_price), yearly_price = VALUES(yearly_price), max_users = VALUES(max_users), max_restaurants = VALUES(max_restaurants), features_json = VALUES(features_json), status = VALUES(status), updated_at = NOW()'); $s->execute($v); self::add($r, $exists ? 'updated' : 'created', 'subscription_plans:' . $v[1]); }
    private static function upsertRestaurant(PDO $pdo, array $v, array &$r): int { $exists = self::exists($pdo, 'SELECT id FROM restaurants WHERE restaurant_code = ? LIMIT 1', [$v[2]]); $s = $pdo->prepare('INSERT INTO restaurants (subscription_plan_id, name, restaurant_code, slug, legal_name, status, subscription_status, subscription_payment_status, support_email, phone, country, city, address_line, timezone, currency_code, access_url, activated_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE subscription_plan_id = VALUES(subscription_plan_id), name = VALUES(name), slug = VALUES(slug), legal_name = VALUES(legal_name), status = VALUES(status), subscription_status = VALUES(subscription_status), subscription_payment_status = VALUES(subscription_payment_status), support_email = VALUES(support_email), phone = VALUES(phone), country = VALUES(country), city = VALUES(city), address_line = VALUES(address_line), timezone = VALUES(timezone), currency_code = VALUES(currency_code), access_url = VALUES(access_url), updated_at = NOW()'); $s->execute($v); self::add($r, $exists ? 'updated' : 'created', 'restaurants:' . $v[2]); return self::idBy($pdo, 'SELECT id FROM restaurants WHERE restaurant_code = ? LIMIT 1', [$v[2]]); }
    private static function upsertBranding(PDO $pdo, array $v, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM restaurant_branding WHERE restaurant_id = ? LIMIT 1', [$v[0]]); $s = $pdo->prepare('INSERT INTO restaurant_branding (restaurant_id, public_name, logo_url, cover_image_url, favicon_url, primary_color, secondary_color, accent_color, web_subdomain, custom_domain, app_display_name, app_short_name, portal_title, portal_tagline, welcome_text, download_badge_label, created_at, updated_at) VALUES (?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW()) ON DUPLICATE KEY UPDATE public_name = VALUES(public_name), primary_color = VALUES(primary_color), secondary_color = VALUES(secondary_color), accent_color = VALUES(accent_color), web_subdomain = VALUES(web_subdomain), custom_domain = VALUES(custom_domain), app_display_name = VALUES(app_display_name), app_short_name = VALUES(app_short_name), portal_title = VALUES(portal_title), portal_tagline = VALUES(portal_tagline), welcome_text = VALUES(welcome_text), updated_at = NOW()'); $s->execute($v); self::add($r, $exists ? 'updated' : 'created', 'restaurant_branding:' . $v[0]); }
    private static function upsertSystemRole(PDO $pdo, array $v, array &$r): void { $id = self::idBy($pdo, 'SELECT id FROM roles WHERE restaurant_id IS NULL AND scope = "system" AND code = ? LIMIT 1', [$v[1]]); if ($id > 0) { $s = $pdo->prepare('UPDATE roles SET name = ?, description = ?, is_locked = 1, status = "active", updated_at = NOW() WHERE id = ?'); $s->execute([$v[0], $v[2], $id]); self::add($r, 'updated', 'roles:' . $v[1]); return; } $s = $pdo->prepare('INSERT INTO roles (restaurant_id, name, code, description, scope, is_locked, status, created_at, updated_at) VALUES (NULL, ?, ?, ?, "system", 1, "active", NOW(), NOW())'); $s->execute($v); self::add($r, 'created', 'roles:' . $v[1]); }
    private static function upsertPermission(PDO $pdo, array $v, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM permissions WHERE code = ? LIMIT 1', [$v[2]]); $s = $pdo->prepare('INSERT INTO permissions (module_name, action_name, code, description, is_sensitive, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE module_name = VALUES(module_name), action_name = VALUES(action_name), description = VALUES(description), is_sensitive = VALUES(is_sensitive), updated_at = NOW()'); $s->execute($v); self::add($r, $exists ? 'updated' : 'created', 'permissions:' . $v[2]); }
    private static function upsertRolePermission(PDO $pdo, int $roleId, int $permissionId, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM role_permissions WHERE role_id = ? AND permission_id = ? AND restaurant_id IS NULL LIMIT 1', [$roleId, $permissionId]); if ($exists) { $s = $pdo->prepare('UPDATE role_permissions SET effect = "allow", updated_at = NOW() WHERE role_id = ? AND permission_id = ? AND restaurant_id IS NULL'); $s->execute([$roleId, $permissionId]); self::add($r, 'updated', 'role_permissions:' . $roleId . ':' . $permissionId); return; } $s = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, restaurant_id, effect, created_at, updated_at) VALUES (?, ?, NULL, "allow", NOW(), NOW())'); $s->execute([$roleId, $permissionId]); self::add($r, 'created', 'role_permissions:' . $roleId . ':' . $permissionId); }
    private static function upsertSetting(PDO $pdo, array $v, array &$r): void { $null = $v[0] === null; $exists = $null ? self::exists($pdo, 'SELECT id FROM settings WHERE restaurant_id IS NULL AND setting_key = ? LIMIT 1', [$v[1]]) : self::exists($pdo, 'SELECT id FROM settings WHERE restaurant_id = ? AND setting_key = ? LIMIT 1', [$v[0], $v[1]]); if ($exists) { $s = $null ? $pdo->prepare('UPDATE settings SET setting_value = ?, value_type = ?, updated_at = NOW() WHERE restaurant_id IS NULL AND setting_key = ?') : $pdo->prepare('UPDATE settings SET setting_value = ?, value_type = ?, updated_at = NOW() WHERE restaurant_id = ? AND setting_key = ?'); $null ? $s->execute([$v[2], $v[3], $v[1]]) : $s->execute([$v[2], $v[3], $v[0], $v[1]]); self::add($r, 'updated', 'settings:' . ($null ? 'system' : (string) $v[0]) . ':' . $v[1]); return; } $s = $pdo->prepare('INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at) VALUES (?, ?, ?, ?, 0, NOW(), NOW())'); $s->execute($v); self::add($r, 'created', 'settings:' . ($null ? 'system' : (string) $v[0]) . ':' . $v[1]); }
    private static function upsertModule(PDO $pdo, int $restaurantId, string $module, int $configuredBy, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM restaurant_modules WHERE restaurant_id = ? AND module_code = ? LIMIT 1', [$restaurantId, $module]); $s = $pdo->prepare('INSERT INTO restaurant_modules (restaurant_id, module_code, is_enabled, configured_by, configured_at, created_at, updated_at) VALUES (?, ?, 1, ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE is_enabled = 1, configured_by = VALUES(configured_by), configured_at = NOW(), updated_at = NOW()'); $s->execute([$restaurantId, $module, $configuredBy]); self::add($r, $exists ? 'updated' : 'created', 'restaurant_modules:' . $restaurantId . ':' . $module); }
    private static function upsertUser(PDO $pdo, array $v, array &$r): void { $exists = self::exists($pdo, 'SELECT id FROM users WHERE email = ? LIMIT 1', [$v[3]]); $s = $pdo->prepare('INSERT INTO users (restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, last_login_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW()) ON DUPLICATE KEY UPDATE restaurant_id = VALUES(restaurant_id), role_id = VALUES(role_id), full_name = VALUES(full_name), phone = VALUES(phone), password_hash = VALUES(password_hash), status = VALUES(status), must_change_password = VALUES(must_change_password), updated_at = NOW()'); $s->execute($v); self::add($r, $exists ? 'updated' : 'created', 'users:' . $v[3]); }
}
