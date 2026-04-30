CREATE DATABASE IF NOT EXISTS badiboss_restaurant_saas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE badiboss_restaurant_saas;

CREATE TABLE subscription_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(80) NOT NULL UNIQUE,
    description TEXT NULL,
    monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    yearly_price DECIMAL(12,2) NULL,
    max_users INT UNSIGNED NOT NULL DEFAULT 10,
    max_restaurants INT UNSIGNED NOT NULL DEFAULT 1,
    features_json JSON NULL,
    status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE restaurants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_plan_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    restaurant_code VARCHAR(80) NOT NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    legal_name VARCHAR(180) NULL,
    status ENUM('draft', 'active', 'suspended', 'banned', 'archived') NOT NULL DEFAULT 'draft',
    subscription_status ENUM('DRAFT', 'PENDING_PAYMENT', 'PENDING_VALIDATION', 'ACTIVE', 'SUSPENDED', 'EXPIRED', 'GRACE_PERIOD') NOT NULL DEFAULT 'DRAFT',
    subscription_payment_status ENUM('UNPAID', 'DECLARED', 'PAID', 'WAIVED') NOT NULL DEFAULT 'UNPAID',
    support_email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    country VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    address_line VARCHAR(255) NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Kinshasa',
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    access_url VARCHAR(255) NULL,
    download_url VARCHAR(255) NULL,
    subscription_started_at DATETIME NULL,
    subscription_ends_at DATETIME NULL,
    subscription_validated_at DATETIME NULL,
    subscription_payment_declared_at DATETIME NULL,
    subscription_grace_ends_at DATETIME NULL,
    subscription_exempted_at DATETIME NULL,
    subscription_exemption_reason TEXT NULL,
    activated_at DATETIME NULL,
    suspended_at DATETIME NULL,
    banned_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_restaurants_plan FOREIGN KEY (subscription_plan_id) REFERENCES subscription_plans(id)
);

CREATE TABLE restaurant_branding (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    public_name VARCHAR(160) NOT NULL,
    logo_url VARCHAR(255) NULL,
    cover_image_url VARCHAR(255) NULL,
    favicon_url VARCHAR(255) NULL,
    primary_color VARCHAR(20) NOT NULL DEFAULT '#0f766e',
    secondary_color VARCHAR(20) NOT NULL DEFAULT '#111827',
    accent_color VARCHAR(20) NOT NULL DEFAULT '#f59e0b',
    web_subdomain VARCHAR(120) NULL UNIQUE,
    custom_domain VARCHAR(190) NULL UNIQUE,
    app_display_name VARCHAR(160) NOT NULL,
    app_short_name VARCHAR(60) NOT NULL,
    portal_title VARCHAR(180) NULL,
    portal_tagline VARCHAR(255) NULL,
    welcome_text TEXT NULL,
    download_badge_label VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_branding_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(80) NOT NULL,
    description TEXT NULL,
    scope ENUM('system', 'tenant') NOT NULL DEFAULT 'tenant',
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_role_scope (restaurant_id, code),
    CONSTRAINT fk_roles_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(120) NOT NULL,
    action_name VARCHAR(120) NOT NULL,
    code VARCHAR(180) NOT NULL UNIQUE,
    description TEXT NULL,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    effect ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_role_permission_scope (role_id, permission_id, restaurant_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id),
    CONSTRAINT fk_role_permissions_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled', 'banned', 'archived') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    banned_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_users_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_api_tokens_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    setting_key VARCHAR(160) NOT NULL,
    setting_value TEXT NOT NULL,
    value_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') NOT NULL DEFAULT 'string',
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_setting_scope (restaurant_id, setting_key),
    CONSTRAINT fk_settings_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE restaurant_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    module_code VARCHAR(120) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    configured_by BIGINT UNSIGNED NULL,
    configured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_restaurant_module (restaurant_id, module_code),
    CONSTRAINT fk_restaurant_modules_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_restaurant_modules_user FOREIGN KEY (configured_by) REFERENCES users(id)
);

CREATE TABLE menu_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    description TEXT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_menu_category (restaurant_id, slug),
    CONSTRAINT fk_menu_categories_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE menu_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(255) NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    available_dine_in TINYINT(1) NOT NULL DEFAULT 1,
    available_takeaway TINYINT(1) NOT NULL DEFAULT 1,
    available_delivery TINYINT(1) NOT NULL DEFAULT 1,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'inactive', 'out_of_stock', 'hidden', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_menu_item (restaurant_id, slug),
    CONSTRAINT fk_menu_items_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_menu_items_category FOREIGN KEY (category_id) REFERENCES menu_categories(id)
);

CREATE TABLE stock_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    unit_name VARCHAR(80) NOT NULL,
    quantity_in_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    alert_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estimated_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    INDEX idx_stock_items_restaurant (restaurant_id),
    CONSTRAINT fk_stock_items_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
);

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('ENTREE', 'SORTIE_CUISINE', 'RETOUR_STOCK', 'PERTE') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('PROVISOIRE', 'VALIDE') NOT NULL DEFAULT 'PROVISOIRE',
    user_id BIGINT UNSIGNED NOT NULL,
    validated_by BIGINT UNSIGNED NULL,
    reference_type VARCHAR(80) NULL,
    reference_id BIGINT UNSIGNED NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    INDEX idx_stock_movements_restaurant_date (restaurant_id, created_at),
    INDEX idx_stock_movements_status (status, movement_type),
    CONSTRAINT fk_stock_movements_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_stock_movements_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
    CONSTRAINT fk_stock_movements_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_stock_movements_validated_by FOREIGN KEY (validated_by) REFERENCES users(id)
);

CREATE TABLE kitchen_production (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    stock_movement_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    dish_type VARCHAR(160) NOT NULL,
    quantity_produced DECIMAL(12,2) NOT NULL,
    quantity_remaining DECIMAL(12,2) NOT NULL,
    unit_real_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_real_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_sale_value_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_sale_value_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('EN_COURS', 'TERMINE') NOT NULL DEFAULT 'EN_COURS',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    INDEX idx_kitchen_restaurant_date (restaurant_id, created_at),
    CONSTRAINT fk_kitchen_production_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_kitchen_production_movement FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id),
    CONSTRAINT fk_kitchen_production_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CONSTRAINT fk_kitchen_production_user FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    server_id BIGINT UNSIGNED NULL,
    sale_type ENUM('SUR_PLACE', 'LIVRAISON') NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('EN_COURS', 'VALIDE', 'ANNULE') NOT NULL DEFAULT 'EN_COURS',
    origin_type VARCHAR(80) NULL,
    origin_id BIGINT UNSIGNED NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    INDEX idx_sales_restaurant_date (restaurant_id, created_at),
    CONSTRAINT fk_sales_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_sales_server FOREIGN KEY (server_id) REFERENCES users(id)
);

CREATE TABLE server_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    server_id BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    status ENUM('DEMANDE', 'FOURNI_PARTIEL', 'FOURNI_TOTAL', 'VENDU_PARTIEL', 'VENDU_TOTAL', 'CLOTURE') NOT NULL DEFAULT 'DEMANDE',
    total_requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_supplied_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_sold_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_returned_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_server_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    supplied_at DATETIME NULL,
    closed_at DATETIME NULL,
    INDEX idx_server_requests_restaurant_date (restaurant_id, created_at),
    CONSTRAINT fk_server_requests_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_server_requests_server FOREIGN KEY (server_id) REFERENCES users(id)
);

CREATE TABLE server_request_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    server_request_id BIGINT UNSIGNED NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NULL,
    requested_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplied_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sold_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    returned_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    returned_quantity_validated DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    requested_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplied_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sold_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    returned_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    server_loss_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_supplied_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_sold_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE',
    supply_status ENUM('DEMANDE', 'FOURNI_TOTAL', 'FOURNI_PARTIEL', 'NON_FOURNI') NOT NULL DEFAULT 'DEMANDE',
    note TEXT NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_server_request_items_request FOREIGN KEY (request_id) REFERENCES server_requests(id),
    CONSTRAINT fk_server_request_items_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
);

CREATE TABLE sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    kitchen_production_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    status ENUM('SERVI', 'RETOUR') NOT NULL DEFAULT 'SERVI',
    return_reason TEXT NULL,
    return_validated_by_kitchen BIGINT UNSIGNED NULL,
    return_validated_by_manager BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    returned_at DATETIME NULL,
    INDEX idx_sale_items_sale (sale_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_sale_items_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CONSTRAINT fk_sale_items_production FOREIGN KEY (kitchen_production_id) REFERENCES kitchen_production(id),
    CONSTRAINT fk_sale_items_kitchen_validator FOREIGN KEY (return_validated_by_kitchen) REFERENCES users(id),
    CONSTRAINT fk_sale_items_manager_validator FOREIGN KEY (return_validated_by_manager) REFERENCES users(id)
);

CREATE TABLE kitchen_stock_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NOT NULL,
    quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity_supplied DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity DECIMAL(12,2) NULL,
    status ENUM('DEMANDE', 'DISPONIBLE', 'PARTIELLEMENT_DISPONIBLE', 'INDISPONIBLE') NOT NULL DEFAULT 'DEMANDE',
    planning_status ENUM('urgence', 'a_prevoir') NULL,
    note TEXT NULL,
    response_note TEXT NULL,
    responded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_kitchen_stock_requests_restaurant_date (restaurant_id, created_at),
    CONSTRAINT fk_kitchen_stock_requests_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_kitchen_stock_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_kitchen_stock_requests_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
    CONSTRAINT fk_kitchen_stock_requests_responded_by FOREIGN KEY (responded_by) REFERENCES users(id)
);

CREATE TABLE losses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    loss_type ENUM('MATIERE_PREMIERE', 'ARGENT') NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    validated_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    INDEX idx_losses_restaurant_date (restaurant_id, created_at),
    CONSTRAINT fk_losses_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_losses_validated_by FOREIGN KEY (validated_by) REFERENCES users(id),
    CONSTRAINT fk_losses_created_by FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE operation_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NULL,
    case_type VARCHAR(80) NOT NULL,
    reported_category VARCHAR(120) NULL,
    source VARCHAR(50) NULL,
    source_module VARCHAR(80) NOT NULL,
    source_entity_type VARCHAR(120) NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    source_entity_id BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    quantity_affected DECIMAL(12,2) NOT NULL,
    unit_name VARCHAR(80) NOT NULL,
    status VARCHAR(80) NOT NULL,
    final_qualification VARCHAR(120) NULL,
    responsibility_scope VARCHAR(120) NULL,
    signal_notes TEXT NULL,
    technical_notes TEXT NULL,
    manager_justification TEXT NULL,
    material_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by BIGINT UNSIGNED NULL,
    signaled_by BIGINT UNSIGNED NOT NULL,
    validated_by BIGINT UNSIGNED NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    resolved_by BIGINT UNSIGNED NULL,
    decision VARCHAR(120) NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    technical_confirmed_at DATETIME NULL,
    decided_at DATETIME NULL,
    resolved_at DATETIME NULL,
    INDEX idx_operation_cases_restaurant_date (restaurant_id, created_at),
    INDEX idx_operation_cases_status (status),
    INDEX idx_operation_cases_source (source_module, source_entity_type, source_entity_id),
    CONSTRAINT fk_operation_cases_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_operation_cases_stock_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
    CONSTRAINT fk_operation_cases_signaled_by FOREIGN KEY (signaled_by) REFERENCES users(id),
    CONSTRAINT fk_operation_cases_technical_confirmed_by FOREIGN KEY (technical_confirmed_by) REFERENCES users(id),
    CONSTRAINT fk_operation_cases_decided_by FOREIGN KEY (decided_by) REFERENCES users(id)
);

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(180) NOT NULL,
    actor_role_code VARCHAR(120) NOT NULL,
    module_name VARCHAR(120) NOT NULL,
    action_name VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NULL,
    entity_id VARCHAR(120) NULL,
    old_values_json JSON NULL,
    new_values_json JSON NULL,
    justification TEXT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_restaurant_date (restaurant_id, created_at),
    INDEX idx_audit_module_date (module_name, created_at),
    CONSTRAINT fk_audit_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
);
