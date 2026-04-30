USE badiboss_restaurant_saas;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE audit_logs;
TRUNCATE TABLE operation_cases;
TRUNCATE TABLE kitchen_stock_requests;
TRUNCATE TABLE menu_items;
TRUNCATE TABLE menu_categories;
TRUNCATE TABLE losses;
TRUNCATE TABLE server_request_items;
TRUNCATE TABLE server_requests;
TRUNCATE TABLE sale_items;
TRUNCATE TABLE sales;
TRUNCATE TABLE kitchen_production;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE stock_items;
TRUNCATE TABLE restaurant_modules;
TRUNCATE TABLE api_tokens;
TRUNCATE TABLE settings;
TRUNCATE TABLE users;
TRUNCATE TABLE role_permissions;
TRUNCATE TABLE permissions;
TRUNCATE TABLE roles;
TRUNCATE TABLE restaurant_branding;
TRUNCATE TABLE restaurants;
TRUNCATE TABLE subscription_plans;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO subscription_plans
(id, name, code, description, monthly_price, yearly_price, max_users, max_restaurants, features_json, status, created_at, updated_at)
VALUES
(1, 'Starter', 'starter', 'Plan de base pour petit restaurant', 49.99, 499.00, 15, 1, JSON_ARRAY('dashboard', 'branding', 'reports_basic'), 'active', NOW(), NOW()),
(2, 'Business', 'business', 'Plan multi-equipes avec modules avancables', 119.99, 1199.00, 80, 1, JSON_ARRAY('dashboard', 'branding', 'reports_advanced', 'pwa'), 'active', NOW(), NOW());

INSERT INTO restaurants
(id, subscription_plan_id, name, restaurant_code, slug, legal_name, status, subscription_status, subscription_payment_status, support_email, phone, country, city, address_line, timezone, currency_code, access_url, download_url, subscription_started_at, subscription_ends_at, subscription_validated_at, subscription_payment_declared_at, subscription_grace_ends_at, subscription_exempted_at, subscription_exemption_reason, activated_at, created_at, updated_at)
VALUES
(1, 2, 'Badi Saveurs Gombe', 'badi-saveurs-gombe', 'badi-saveurs-gombe', 'Badi Saveurs SARL', 'active', 'ACTIVE', 'PAID', 'gombe@badiboss.test', '+243810000001', 'Republique Democratique du Congo', 'Kinshasa', 'Avenue du Commerce, Gombe', 'Africa/Kinshasa', 'USD', 'https://gombe.badiboss.app', 'https://gombe.badiboss.app/install', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 32 DAY), NULL, NULL, NOW(), NOW(), NOW()),
(2, 1, 'Mboka Grill Lubumbashi', 'mboka-grill-lubumbashi', 'mboka-grill-lubumbashi', 'Mboka Grill SARL', 'active', 'GRACE_PERIOD', 'DECLARED', 'owner@mbokagrill.test', '+243820000002', 'Republique Democratique du Congo', 'Lubumbashi', 'Avenue Mama Yemo, Lubumbashi', 'Africa/Lubumbashi', 'USD', 'https://mbokagrill.badiboss.app', 'https://mbokagrill.badiboss.app/install', DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), NULL, NULL, NOW(), NOW(), NOW());

INSERT INTO restaurant_branding
(restaurant_id, public_name, logo_url, cover_image_url, favicon_url, primary_color, secondary_color, accent_color, web_subdomain, custom_domain, app_display_name, app_short_name, portal_title, portal_tagline, welcome_text, download_badge_label, created_at, updated_at)
VALUES
(1, 'Badi Saveurs Gombe', 'https://cdn.example.com/logos/badi-saveurs-gombe.png', 'https://cdn.example.com/photos/badi-saveurs-gombe.jpg', NULL, '#0f766e', '#111827', '#f59e0b', 'gombe', NULL, 'Badi Saveurs', 'BadiSaveurs', 'Portail Badi Saveurs', 'Cuisine congolaise moderne a Gombe', 'Bienvenue chez Badi Saveurs Gombe. Consultez le menu et les informations publiques sans compte.', 'Installer Badi Saveurs', NOW(), NOW()),
(2, 'Mboka Grill', 'https://cdn.example.com/logos/mboka-grill.png', 'https://cdn.example.com/photos/mboka-grill.jpg', NULL, '#9a3412', '#1f2937', '#16a34a', 'mbokagrill', 'commande.mbokagrill.cd', 'Mboka Grill', 'MbokaGrill', 'Portail Mboka Grill', 'Grillades et plats locaux a Lubumbashi', 'Bienvenue chez Mboka Grill Lubumbashi. Consultez le menu public avant de commander.', 'Installer Mboka Grill', NOW(), NOW());

INSERT INTO roles
(id, restaurant_id, name, code, description, scope, is_locked, status, created_at, updated_at)
VALUES
(1, NULL, 'Super Administrateur', 'super_admin', 'Fournisseur SaaS avec acces global', 'system', 1, 'active', NOW(), NOW()),
(2, NULL, 'Proprietaire', 'owner', 'Lecture globale et controle du restaurant', 'system', 1, 'active', NOW(), NOW()),
(3, NULL, 'Manager', 'manager', 'Validation operationnelle quotidienne', 'system', 1, 'active', NOW(), NOW()),
(4, NULL, 'Gestionnaire Stock', 'stock_manager', 'Gestion des mouvements de stock', 'system', 1, 'active', NOW(), NOW()),
(5, NULL, 'Cuisine', 'kitchen', 'Execution cuisine et confirmation technique', 'system', 1, 'active', NOW(), NOW()),
(6, NULL, 'Serveur Caissier', 'cashier_server', 'Prise de commande et signalement terrain', 'system', 1, 'active', NOW(), NOW()),
(7, NULL, 'Client', 'customer', 'Compte client final', 'system', 1, 'active', NOW(), NOW());

INSERT INTO permissions
(id, module_name, action_name, code, description, is_sensitive, created_at, updated_at)
VALUES
(1, 'tenant_management', 'view', 'tenant_management.view', 'Voir les restaurants SaaS', 0, NOW(), NOW()),
(2, 'tenant_management', 'create', 'tenant_management.create', 'Creer un restaurant client', 1, NOW(), NOW()),
(3, 'tenant_management', 'suspend', 'tenant_management.suspend', 'Suspendre un restaurant', 1, NOW(), NOW()),
(4, 'branding', 'manage', 'branding.manage', 'Gerer le branding du restaurant', 1, NOW(), NOW()),
(5, 'users', 'manage', 'users.manage', 'Gerer les utilisateurs', 1, NOW(), NOW()),
(6, 'reports', 'view', 'reports.view', 'Voir les rapports', 0, NOW(), NOW()),
(7, 'settings', 'manage', 'settings.manage', 'Modifier les regles et seuils', 1, NOW(), NOW()),
(8, 'audit', 'view', 'audit.view', 'Consulter le journal d audit', 1, NOW(), NOW()),
(9, 'menu', 'manage', 'menu.manage', 'Administrer categories et menus', 1, NOW(), NOW()),
(10, 'orders', 'place', 'orders.place', 'Passer ou enregistrer une commande', 0, NOW(), NOW()),
(11, 'stock', 'manage', 'stock.manage', 'Gerer le stock, les sorties et les retours', 1, NOW(), NOW()),
(12, 'kitchen', 'manage', 'kitchen.manage', 'Gerer la transformation cuisine', 1, NOW(), NOW()),
(13, 'sales', 'manage', 'sales.manage', 'Gerer les ventes et retours', 1, NOW(), NOW()),
(14, 'reports', 'daily', 'reports.daily', 'Consulter le rapport journalier detaille', 1, NOW(), NOW()),
(15, 'losses', 'manage', 'losses.manage', 'Declarer et valider les pertes', 1, NOW(), NOW()),
(16, 'roles', 'manage', 'roles.manage', 'Creer des roles et gerer leurs permissions', 1, NOW(), NOW()),
(17, 'incidents', 'signal', 'incidents.signal', 'Signaler un incident ou un retour anormal', 0, NOW(), NOW()),
(18, 'incidents', 'confirm', 'incidents.confirm', 'Confirmer techniquement un incident', 1, NOW(), NOW()),
(19, 'incidents', 'decide', 'incidents.decide', 'Trancher un cas avec justification manager', 1, NOW(), NOW());

INSERT INTO role_permissions
(role_id, permission_id, restaurant_id, effect, created_at, updated_at)
VALUES
(1, 1, NULL, 'allow', NOW(), NOW()),
(1, 2, NULL, 'allow', NOW(), NOW()),
(1, 3, NULL, 'allow', NOW(), NOW()),
(1, 4, NULL, 'allow', NOW(), NOW()),
(1, 5, NULL, 'allow', NOW(), NOW()),
(1, 6, NULL, 'allow', NOW(), NOW()),
(1, 7, NULL, 'allow', NOW(), NOW()),
(1, 8, NULL, 'allow', NOW(), NOW()),
(1, 9, NULL, 'allow', NOW(), NOW()),
(1, 11, NULL, 'allow', NOW(), NOW()),
(1, 12, NULL, 'allow', NOW(), NOW()),
(1, 13, NULL, 'allow', NOW(), NOW()),
(1, 14, NULL, 'allow', NOW(), NOW()),
(1, 15, NULL, 'allow', NOW(), NOW()),
(1, 16, NULL, 'allow', NOW(), NOW()),
(1, 17, NULL, 'allow', NOW(), NOW()),
(1, 18, NULL, 'allow', NOW(), NOW()),
(1, 19, NULL, 'allow', NOW(), NOW()),
(2, 4, NULL, 'allow', NOW(), NOW()),
(2, 5, NULL, 'allow', NOW(), NOW()),
(2, 6, NULL, 'allow', NOW(), NOW()),
(2, 7, NULL, 'allow', NOW(), NOW()),
(2, 8, NULL, 'allow', NOW(), NOW()),
(2, 9, NULL, 'allow', NOW(), NOW()),
(2, 11, NULL, 'allow', NOW(), NOW()),
(2, 12, NULL, 'allow', NOW(), NOW()),
(2, 13, NULL, 'allow', NOW(), NOW()),
(2, 14, NULL, 'allow', NOW(), NOW()),
(2, 15, NULL, 'allow', NOW(), NOW()),
(2, 16, NULL, 'allow', NOW(), NOW()),
(3, 6, NULL, 'allow', NOW(), NOW()),
(3, 8, NULL, 'allow', NOW(), NOW()),
(3, 12, NULL, 'allow', NOW(), NOW()),
(3, 13, NULL, 'allow', NOW(), NOW()),
(3, 14, NULL, 'allow', NOW(), NOW()),
(3, 15, NULL, 'allow', NOW(), NOW()),
(3, 16, NULL, 'allow', NOW(), NOW()),
(3, 19, NULL, 'allow', NOW(), NOW()),
(4, 11, NULL, 'allow', NOW(), NOW()),
(4, 17, NULL, 'allow', NOW(), NOW()),
(5, 10, NULL, 'allow', NOW(), NOW()),
(5, 12, NULL, 'allow', NOW(), NOW()),
(5, 17, NULL, 'allow', NOW(), NOW()),
(5, 18, NULL, 'allow', NOW(), NOW()),
(6, 10, NULL, 'allow', NOW(), NOW()),
(6, 13, NULL, 'allow', NOW(), NOW()),
(6, 17, NULL, 'allow', NOW(), NOW()),
(7, 10, NULL, 'allow', NOW(), NOW());

INSERT INTO users
(id, restaurant_id, role_id, full_name, email, phone, password_hash, status, must_change_password, last_login_at, created_at, updated_at)
VALUES
(1, NULL, 1, 'Super Administrateur Plateforme', 'superadmin@badiboss.test', '+243810000100', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 0, NOW(), NOW(), NOW()),
(2, 1, 2, 'Proprietaire Badi Saveurs', 'owner-gombe@badiboss.test', '+243810000101', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 0, NOW(), NOW(), NOW()),
(3, 1, 3, 'Manager Badi Saveurs', 'manager-gombe@badiboss.test', '+243810000102', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 1, NULL, NOW(), NOW()),
(4, 1, 4, 'Responsable Stock Gombe', 'stock-gombe@badiboss.test', '+243810000103', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 1, NULL, NOW(), NOW()),
(5, 1, 5, 'Cuisine Gombe', 'kitchen-gombe@badiboss.test', '+243810000104', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 1, NULL, NOW(), NOW()),
(6, 1, 6, 'Serveur Gombe', 'server-gombe@badiboss.test', '+243810000105', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 1, NULL, NOW(), NOW()),
(7, 2, 2, 'Proprietaire Mboka Grill', 'owner@mbokagrill.test', '+243820000106', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active', 0, NOW(), NOW(), NOW());

INSERT INTO settings
(restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
VALUES
(NULL, 'saas.default_return_window_hours', '24', 'integer', 0, NOW(), NOW()),
(NULL, 'saas.allow_custom_domains', '1', 'boolean', 0, NOW(), NOW()),
(NULL, 'saas.pwa_enabled_by_default', '1', 'boolean', 0, NOW(), NOW()),
(NULL, 'global_validation_states_json', '["PROPOSE","CONFIRME_TECHNIQUEMENT","EN_ATTENTE_VALIDATION_MANAGER","VALIDE","REJETE"]', 'json', 0, NOW(), NOW()),
(NULL, 'global_final_qualifications_json', '["retour_simple","aliment_a_jeter","boisson_cassee","perte_cuisine","perte_serveur","perte_stock","perte_matiere","perte_argent","autre"]', 'json', 0, NOW(), NOW()),
(NULL, 'global_responsibility_targets_json', '["cuisine","serveur","stock","restaurant","autre"]', 'json', 0, NOW(), NOW()),
(NULL, 'global_incident_types_json', '["retour_simple","retour_avec_anomalie","produit_defectueux","produit_casse","produit_impropre","retour_stock_endommage","perte_matiere","perte_argent"]', 'json', 0, NOW(), NOW()),
(NULL, 'global_client_access_rules_json', '{"public_menu_enabled":true,"public_restaurant_info_enabled":true,"auth_required_for_order":true,"auth_required_for_reservation":true}', 'json', 0, NOW(), NOW()),
(NULL, 'global_automation_rules_json', '{"sale_auto_after_hours":24}', 'json', 0, NOW(), NOW()),
(NULL, 'global_subscription_rules_json', '{"subscription_grace_days":2,"subscription_warning_days":5,"default_duration_days":30}', 'json', 0, NOW(), NOW()),
(NULL, 'global_alert_rules_json', '{"server_incident_threshold":3,"kitchen_loss_threshold":2,"repeated_inconsistency_threshold":2,"frequent_return_threshold":3}', 'json', 0, NOW(), NOW()),
(NULL, 'global_default_restaurant_settings_json', '{"restaurant_return_window_hours":24,"restaurant_loss_validation_required":true,"restaurant_public_menu_enabled":true,"restaurant_public_order_requires_auth":true,"restaurant_public_reservation_requires_auth":true}', 'json', 0, NOW(), NOW()),
(NULL, 'global_module_catalog_json', '["menu","stock","kitchen","sales","reports","roles","branding"]', 'json', 0, NOW(), NOW()),
(1, 'restaurant_return_window_hours', '24', 'integer', 0, NOW(), NOW()),
(1, 'restaurant_loss_validation_required', '1', 'boolean', 0, NOW(), NOW()),
(1, 'restaurant_reports_timezone', 'Africa/Kinshasa', 'string', 0, NOW(), NOW()),
(1, 'restaurant_feature_pwa_enabled', '1', 'boolean', 0, NOW(), NOW()),
(1, 'restaurant_public_menu_enabled', '1', 'boolean', 0, NOW(), NOW()),
(1, 'restaurant_public_order_requires_auth', '1', 'boolean', 0, NOW(), NOW()),
(1, 'restaurant_public_reservation_requires_auth', '1', 'boolean', 0, NOW(), NOW()),
(1, 'restaurant_welcome_text', 'Bienvenue sur le portail Badi Saveurs Gombe.', 'string', 0, NOW(), NOW()),
(2, 'restaurant_return_window_hours', '24', 'integer', 0, NOW(), NOW()),
(2, 'restaurant_loss_validation_required', '1', 'boolean', 0, NOW(), NOW()),
(2, 'restaurant_reports_timezone', 'Africa/Lubumbashi', 'string', 0, NOW(), NOW()),
(2, 'restaurant_feature_pwa_enabled', '1', 'boolean', 0, NOW(), NOW()),
(2, 'restaurant_public_menu_enabled', '1', 'boolean', 0, NOW(), NOW()),
(2, 'restaurant_public_order_requires_auth', '1', 'boolean', 0, NOW(), NOW()),
(2, 'restaurant_public_reservation_requires_auth', '1', 'boolean', 0, NOW(), NOW()),
(2, 'restaurant_welcome_text', 'Bienvenue sur le portail Mboka Grill Lubumbashi.', 'string', 0, NOW(), NOW());

INSERT INTO restaurant_modules
(restaurant_id, module_code, is_enabled, configured_by, configured_at, created_at, updated_at)
VALUES
(1, 'branding', 1, 1, NOW(), NOW(), NOW()),
(1, 'reports', 1, 1, NOW(), NOW(), NOW()),
(1, 'menu', 1, 1, NOW(), NOW(), NOW()),
(1, 'pwa', 1, 1, NOW(), NOW(), NOW()),
(1, 'stock', 1, 1, NOW(), NOW(), NOW()),
(1, 'kitchen', 1, 1, NOW(), NOW(), NOW()),
(1, 'sales', 1, 1, NOW(), NOW(), NOW()),
(1, 'losses', 1, 1, NOW(), NOW(), NOW()),
(2, 'branding', 1, 1, NOW(), NOW(), NOW()),
(2, 'reports', 1, 1, NOW(), NOW(), NOW()),
(2, 'menu', 1, 1, NOW(), NOW(), NOW()),
(2, 'pwa', 1, 1, NOW(), NOW(), NOW()),
(2, 'stock', 1, 1, NOW(), NOW(), NOW()),
(2, 'kitchen', 1, 1, NOW(), NOW(), NOW()),
(2, 'sales', 1, 1, NOW(), NOW(), NOW()),
(2, 'losses', 1, 1, NOW(), NOW(), NOW());

INSERT INTO menu_categories
(id, restaurant_id, name, slug, description, display_order, status, created_at, updated_at)
VALUES
(1, 1, 'Plats congolais', 'plats-congolais', 'Specialites maison de Kinshasa', 1, 'active', NOW(), NOW()),
(2, 1, 'Boissons', 'boissons', 'Jus, sodas et boissons locales', 2, 'active', NOW(), NOW()),
(3, 2, 'Grillades', 'grillades', 'Poulet, chevre et poissons grilles', 1, 'active', NOW(), NOW()),
(4, 2, 'Accompagnements', 'accompagnements', 'Frites, lituma, chikwangue et riz', 2, 'active', NOW(), NOW());

INSERT INTO menu_items
(restaurant_id, category_id, name, slug, description, image_url, price, display_order, available_dine_in, available_takeaway, available_delivery, is_available, status, created_at, updated_at)
VALUES
(1, 1, 'Poulet mayo et banane plantain', 'poulet-mayo-banane-plantain', 'Plat maison apprecie a Kinshasa', NULL, 12.00, 1, 1, 1, 1, 1, 'active', NOW(), NOW()),
(1, 2, 'Jus de gingembre maison', 'jus-gingembre-maison', 'Boisson rafraichissante locale', NULL, 3.50, 2, 1, 1, 1, 1, 'active', NOW(), NOW()),
(2, 3, 'Chevre grillee Mboka', 'chevre-grillee-mboka', 'Grillade signature de Lubumbashi', NULL, 18.00, 1, 1, 1, 1, 1, 'active', NOW(), NOW()),
(2, 4, 'Chikwangue', 'chikwangue', 'Accompagnement local tres demande', NULL, 2.50, 2, 1, 1, 0, 1, 'out_of_stock', NOW(), NOW());

INSERT INTO stock_items
(id, restaurant_id, name, unit_name, quantity_in_stock, alert_threshold, estimated_unit_cost, created_at)
VALUES
(1, 1, 'Poulet entier', 'piece', 40.00, 8.00, 5.00, NOW()),
(2, 1, 'Riz blanc', 'sac', 18.00, 4.00, 12.00, NOW()),
(3, 1, 'Biere Primus', 'casier', 24.00, 6.00, 18.00, NOW()),
(4, 2, 'Chevre', 'piece', 15.00, 3.00, 9.00, NOW()),
(5, 2, 'Chikwangue', 'paquet', 50.00, 10.00, 1.00, NOW()),
(6, 2, 'Frites surgelees', 'carton', 12.00, 3.00, 7.00, NOW());

INSERT INTO stock_movements
(id, restaurant_id, stock_item_id, movement_type, quantity, unit_cost_snapshot, total_cost_snapshot, status, user_id, validated_by, reference_type, reference_id, note, created_at, validated_at)
VALUES
(1, 1, 1, 'ENTREE', 40.00, 5.00, 200.00, 'VALIDE', 4, 2, 'approvisionnement', 1, 'Reception du jour', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 1, 2, 'SORTIE_CUISINE', 1.00, 12.00, 12.00, 'PROVISOIRE', 4, NULL, 'kitchen_production', 1, 'Sac envoye en cuisine pour le service midi', DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL),
(3, 2, 4, 'SORTIE_CUISINE', 2.00, 9.00, 18.00, 'PROVISOIRE', 7, NULL, 'kitchen_production', 2, 'Preparation grillades soiree', DATE_SUB(NOW(), INTERVAL 26 HOUR), NULL);

INSERT INTO kitchen_production
(id, restaurant_id, stock_movement_id, menu_item_id, dish_type, quantity_produced, quantity_remaining, unit_real_cost_snapshot, total_real_cost_snapshot, unit_sale_value_snapshot, total_sale_value_snapshot, status, created_by, created_at, closed_at)
VALUES
(1, 1, 2, 1, 'Poulet mayo', 18.00, 6.00, 0.67, 12.00, 12.00, 216.00, 'EN_COURS', 5, DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL),
(2, 2, 3, 3, 'Chevre grillee', 10.00, 4.00, 1.80, 18.00, 18.00, 180.00, 'EN_COURS', 7, DATE_SUB(NOW(), INTERVAL 25 HOUR), NULL);

INSERT INTO sales
(id, restaurant_id, server_id, sale_type, total_amount, status, origin_type, origin_id, note, created_at, validated_at)
VALUES
(1, 1, 6, 'SUR_PLACE', 24.00, 'VALIDE', 'manuel', 1, 'Service du midi table 4', DATE_SUB(NOW(), INTERVAL 90 MINUTE), DATE_SUB(NOW(), INTERVAL 80 MINUTE)),
(2, 1, 6, 'LIVRAISON', 3.50, 'EN_COURS', 'manuel', 2, 'Commande quartier Gombe', DATE_SUB(NOW(), INTERVAL 40 MINUTE), NULL);

INSERT INTO sale_items
(id, sale_id, menu_item_id, kitchen_production_id, quantity, unit_price, status, return_reason, return_validated_by_kitchen, return_validated_by_manager, created_at, returned_at)
VALUES
(1, 1, 1, 1, 2.00, 12.00, 'SERVI', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 90 MINUTE), NULL),
(2, 2, 2, NULL, 1.00, 3.50, 'SERVI', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 40 MINUTE), NULL);

INSERT INTO server_requests
(id, restaurant_id, server_id, requested_by, technical_confirmed_by, decided_by, status, total_requested_amount, total_supplied_amount, total_sold_amount, total_returned_amount, total_server_loss_amount, note, created_at, updated_at, supplied_at, closed_at)
VALUES
(1, 1, 6, 6, 5, NULL, 'FOURNI_PARTIEL', 11.00, 10.50, 0.00, 0.00, 0.00, 'Demande du serveur pour le service en cours.', DATE_SUB(NOW(), INTERVAL 35 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE), NULL);

INSERT INTO server_request_items
(id, request_id, menu_item_id, requested_quantity, supplied_quantity, sold_quantity, returned_quantity_validated, unit_price, requested_total, supplied_total, sold_total, returned_total, server_loss_total, supply_status, created_at, updated_at)
VALUES
(1, 1, 1, 1.00, 1.00, 0.00, 0.00, 8.00, 8.00, 8.00, 0.00, 0.00, 0.00, 'FOURNI_TOTAL', DATE_SUB(NOW(), INTERVAL 35 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
(2, 1, 2, 2.00, 2.00, 0.00, 0.00, 1.00, 2.00, 2.00, 0.00, 0.00, 0.00, 'FOURNI_TOTAL', DATE_SUB(NOW(), INTERVAL 35 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE)),
(3, 1, 2, 2.00, 1.00, 0.00, 0.00, 0.50, 1.00, 0.50, 0.00, 0.00, 0.00, 'FOURNI_PARTIEL', DATE_SUB(NOW(), INTERVAL 35 MINUTE), DATE_SUB(NOW(), INTERVAL 25 MINUTE));

INSERT INTO kitchen_stock_requests
(id, restaurant_id, requested_by, stock_item_id, quantity_requested, quantity_supplied, quantity, status, planning_status, note, response_note, responded_by, created_at, responded_at, updated_at)
VALUES
(1, 1, 5, 3, 2.00, 0.00, 2.00, 'INDISPONIBLE', 'urgence', 'Vitalo indisponible pour le service courant.', 'Classe en urgence par le stock.', 4, DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 10 MINUTE), DATE_SUB(NOW(), INTERVAL 10 MINUTE));

INSERT INTO losses
(id, restaurant_id, loss_type, reference_id, description, amount, validated_by, created_by, created_at, validated_at)
VALUES
(1, 1, 'MATIERE_PREMIERE', 2, 'Riz humide non recuperable apres preparation', 5.00, 3, 4, DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
(2, 2, 'ARGENT', NULL, 'Ecart de caisse livraison du soir', 4.00, 7, 7, DATE_SUB(NOW(), INTERVAL 10 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE));

INSERT INTO operation_cases
(id, restaurant_id, case_type, reported_category, source_module, source_entity_type, source_entity_id, stock_item_id, quantity_affected, unit_name, status, final_qualification, responsibility_scope, signal_notes, technical_notes, manager_justification, material_loss_amount, cash_loss_amount, signaled_by, technical_confirmed_by, decided_by, created_at, technical_confirmed_at, decided_at)
VALUES
(1, 1, 'SALE_RETURN', 'retour_simple', 'sales', 'sale_items', 1, 2, 2.00, 'sac', 'VALIDE', 'retour_simple', 'restaurant', 'Plat revenu avant service complet.', 'Retour confirme comme recuperable.', NULL, 0.00, 0.00, 6, 5, NULL, DATE_SUB(NOW(), INTERVAL 25 MINUTE), DATE_SUB(NOW(), INTERVAL 23 MINUTE), NULL),
(2, 1, 'STOCK_DAMAGE', 'retour_stock_endommage', 'stock', 'stock_items', 2, 2, 1.00, 'sac', 'VALIDE', 'perte_stock', 'stock', 'Sac humide signale au retour stock.', NULL, 'Produit non reintegrable au stock sain.', 5.00, 0.00, 4, NULL, 3, DATE_SUB(NOW(), INTERVAL 18 MINUTE), NULL, DATE_SUB(NOW(), INTERVAL 12 MINUTE));

INSERT INTO audit_logs
(restaurant_id, user_id, actor_name, actor_role_code, module_name, action_name, entity_type, entity_id, old_values_json, new_values_json, justification, ip_address, user_agent, created_at)
VALUES
(NULL, 1, 'Super Administrateur Plateforme', 'super_admin', 'tenant_management', 'seed_initialized', 'restaurants', '1,2', NULL, JSON_OBJECT('restaurants_seeded', 2), 'Initialisation des comptes de demonstration RDC', '127.0.0.1', 'seed-script', NOW());
