USE badiboss_restaurant_saas;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_plans (
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

CREATE TABLE IF NOT EXISTS restaurants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_plan_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    restaurant_code VARCHAR(80) NULL,
    slug VARCHAR(160) NOT NULL,
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
    activated_at DATETIME NULL,
    subscription_started_at DATETIME NULL,
    subscription_ends_at DATETIME NULL,
    subscription_validated_at DATETIME NULL,
    subscription_payment_declared_at DATETIME NULL,
    subscription_grace_ends_at DATETIME NULL,
    subscription_exempted_at DATETIME NULL,
    subscription_exemption_reason TEXT NULL,
    suspended_at DATETIME NULL,
    banned_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS restaurant_branding (
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
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(80) NOT NULL,
    description TEXT NULL,
    scope ENUM('system', 'tenant') NOT NULL DEFAULT 'tenant',
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_name VARCHAR(120) NOT NULL,
    action_name VARCHAR(120) NOT NULL,
    code VARCHAR(180) NOT NULL UNIQUE,
    description TEXT NULL,
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    effect ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
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
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NULL,
    setting_key VARCHAR(160) NOT NULL,
    setting_value TEXT NOT NULL,
    value_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') NOT NULL DEFAULT 'string',
    is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS restaurant_modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    module_code VARCHAR(120) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    configured_by BIGINT UNSIGNED NULL,
    configured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS menu_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    description TEXT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS menu_items (
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
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    unit_name VARCHAR(80) NOT NULL,
    quantity_in_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    alert_threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estimated_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS stock_movements (
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
    validated_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS kitchen_production (
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
    closed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS sales (
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
    validated_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS server_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    server_id BIGINT UNSIGNED NULL,
    requested_by BIGINT UNSIGNED NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE',
    total_requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_supplied_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_sold_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_returned_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_server_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    supplied_at DATETIME NULL,
    closed_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS server_request_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NULL,
    server_request_id BIGINT UNSIGNED NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    menu_item_id BIGINT UNSIGNED NULL,
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
    supply_status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE',
    note TEXT NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS sale_items (
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
    returned_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS kitchen_stock_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    stock_item_id BIGINT UNSIGNED NOT NULL,
    quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity_supplied DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity DECIMAL(12,2) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE',
    planning_status VARCHAR(50) NULL,
    note TEXT NULL,
    response_note TEXT NULL,
    responded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    responded_at DATETIME NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS losses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    loss_type ENUM('MATIERE_PREMIERE', 'ARGENT') NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    description TEXT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    validated_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS operation_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(50) NULL,
    case_type VARCHAR(80) NULL,
    reported_category VARCHAR(120) NULL,
    source VARCHAR(50) NULL,
    source_module VARCHAR(80) NULL,
    source_entity_type VARCHAR(120) NULL,
    reference_id BIGINT UNSIGNED NULL,
    source_entity_id BIGINT UNSIGNED NULL,
    stock_item_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    quantity_affected DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_name VARCHAR(80) NOT NULL DEFAULT 'unite',
    status VARCHAR(80) NOT NULL DEFAULT 'PROPOSE',
    final_qualification VARCHAR(120) NULL,
    responsibility_scope VARCHAR(120) NULL,
    signal_notes TEXT NULL,
    technical_notes TEXT NULL,
    manager_justification TEXT NULL,
    material_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by BIGINT UNSIGNED NULL,
    signaled_by BIGINT UNSIGNED NULL,
    validated_by BIGINT UNSIGNED NULL,
    technical_confirmed_by BIGINT UNSIGNED NULL,
    resolved_by BIGINT UNSIGNED NULL,
    decision VARCHAR(120) NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    validated_at DATETIME NULL,
    technical_confirmed_at DATETIME NULL,
    decided_at DATETIME NULL,
    resolved_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
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
    created_at DATETIME NOT NULL
);

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS restaurant_code VARCHAR(80) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS subscription_status ENUM('DRAFT', 'PENDING_PAYMENT', 'PENDING_VALIDATION', 'ACTIVE', 'SUSPENDED', 'EXPIRED', 'GRACE_PERIOD') NOT NULL DEFAULT 'DRAFT' AFTER status,
    ADD COLUMN IF NOT EXISTS subscription_payment_status ENUM('UNPAID', 'DECLARED', 'PAID', 'WAIVED') NOT NULL DEFAULT 'UNPAID' AFTER subscription_status,
    ADD COLUMN IF NOT EXISTS support_email VARCHAR(190) NULL AFTER subscription_payment_status,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL AFTER support_email,
    ADD COLUMN IF NOT EXISTS country VARCHAR(100) NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL AFTER country,
    ADD COLUMN IF NOT EXISTS address_line VARCHAR(255) NULL AFTER city,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Kinshasa' AFTER address_line,
    ADD COLUMN IF NOT EXISTS currency_code CHAR(3) NOT NULL DEFAULT 'USD' AFTER timezone,
    ADD COLUMN IF NOT EXISTS access_url VARCHAR(255) NULL AFTER currency_code,
    ADD COLUMN IF NOT EXISTS download_url VARCHAR(255) NULL AFTER access_url,
    ADD COLUMN IF NOT EXISTS activated_at DATETIME NULL AFTER download_url,
    ADD COLUMN IF NOT EXISTS subscription_started_at DATETIME NULL AFTER activated_at,
    ADD COLUMN IF NOT EXISTS subscription_ends_at DATETIME NULL AFTER subscription_started_at,
    ADD COLUMN IF NOT EXISTS subscription_validated_at DATETIME NULL AFTER subscription_ends_at,
    ADD COLUMN IF NOT EXISTS subscription_payment_declared_at DATETIME NULL AFTER subscription_validated_at,
    ADD COLUMN IF NOT EXISTS subscription_grace_ends_at DATETIME NULL AFTER subscription_payment_declared_at,
    ADD COLUMN IF NOT EXISTS subscription_exempted_at DATETIME NULL AFTER subscription_grace_ends_at,
    ADD COLUMN IF NOT EXISTS subscription_exemption_reason TEXT NULL AFTER subscription_exempted_at;

ALTER TABLE restaurant_branding
    ADD COLUMN IF NOT EXISTS cover_image_url VARCHAR(255) NULL AFTER logo_url,
    ADD COLUMN IF NOT EXISTS favicon_url VARCHAR(255) NULL AFTER cover_image_url,
    ADD COLUMN IF NOT EXISTS app_display_name VARCHAR(160) NOT NULL DEFAULT 'Restaurant' AFTER custom_domain,
    ADD COLUMN IF NOT EXISTS app_short_name VARCHAR(60) NOT NULL DEFAULT 'Restaurant' AFTER app_display_name,
    ADD COLUMN IF NOT EXISTS portal_title VARCHAR(180) NULL AFTER app_short_name,
    ADD COLUMN IF NOT EXISTS portal_tagline VARCHAR(255) NULL AFTER portal_title,
    ADD COLUMN IF NOT EXISTS welcome_text TEXT NULL AFTER portal_tagline,
    ADD COLUMN IF NOT EXISTS download_badge_label VARCHAR(120) NULL AFTER welcome_text;

ALTER TABLE stock_items
    ADD COLUMN IF NOT EXISTS estimated_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER alert_threshold;

ALTER TABLE stock_movements
    ADD COLUMN IF NOT EXISTS unit_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity,
    ADD COLUMN IF NOT EXISTS total_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_cost_snapshot;

ALTER TABLE kitchen_production
    ADD COLUMN IF NOT EXISTS unit_real_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_remaining,
    ADD COLUMN IF NOT EXISTS total_real_cost_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_real_cost_snapshot,
    ADD COLUMN IF NOT EXISTS unit_sale_value_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_real_cost_snapshot,
    ADD COLUMN IF NOT EXISTS total_sale_value_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_sale_value_snapshot;

ALTER TABLE server_requests
    ADD COLUMN IF NOT EXISTS server_id BIGINT UNSIGNED NULL AFTER restaurant_id,
    ADD COLUMN IF NOT EXISTS requested_by BIGINT UNSIGNED NULL AFTER server_id,
    ADD COLUMN IF NOT EXISTS technical_confirmed_by BIGINT UNSIGNED NULL AFTER requested_by,
    ADD COLUMN IF NOT EXISTS decided_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by,
    ADD COLUMN IF NOT EXISTS total_returned_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_sold_amount,
    ADD COLUMN IF NOT EXISTS total_server_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_returned_amount,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN IF NOT EXISTS supplied_at DATETIME NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER supplied_at;

ALTER TABLE server_request_items
    ADD COLUMN IF NOT EXISTS request_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS server_request_id BIGINT UNSIGNED NULL AFTER request_id,
    ADD COLUMN IF NOT EXISTS restaurant_id BIGINT UNSIGNED NULL AFTER server_request_id,
    ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL AFTER menu_item_id,
    ADD COLUMN IF NOT EXISTS returned_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sold_quantity,
    ADD COLUMN IF NOT EXISTS returned_quantity_validated DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER returned_quantity,
    ADD COLUMN IF NOT EXISTS requested_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER unit_price,
    ADD COLUMN IF NOT EXISTS supplied_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER requested_total,
    ADD COLUMN IF NOT EXISTS sold_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER supplied_total,
    ADD COLUMN IF NOT EXISTS returned_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER sold_total,
    ADD COLUMN IF NOT EXISTS server_loss_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER returned_total,
    ADD COLUMN IF NOT EXISTS total_requested_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER server_loss_total,
    ADD COLUMN IF NOT EXISTS total_supplied_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_requested_amount,
    ADD COLUMN IF NOT EXISTS total_sold_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_supplied_amount,
    ADD COLUMN IF NOT EXISTS status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE' AFTER total_sold_amount,
    ADD COLUMN IF NOT EXISTS supply_status VARCHAR(50) NOT NULL DEFAULT 'DEMANDE' AFTER status,
    ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER supply_status,
    ADD COLUMN IF NOT EXISTS technical_confirmed_by BIGINT UNSIGNED NULL AFTER note,
    ADD COLUMN IF NOT EXISTS decided_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by;

ALTER TABLE kitchen_stock_requests
    ADD COLUMN IF NOT EXISTS quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER stock_item_id,
    ADD COLUMN IF NOT EXISTS quantity_supplied DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_requested,
    ADD COLUMN IF NOT EXISTS quantity DECIMAL(12,2) NULL AFTER quantity_supplied,
    ADD COLUMN IF NOT EXISTS planning_status VARCHAR(50) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER planning_status,
    ADD COLUMN IF NOT EXISTS response_note TEXT NULL AFTER note,
    ADD COLUMN IF NOT EXISTS responded_at DATETIME NULL AFTER responded_by,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE operation_cases
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL AFTER restaurant_id,
    ADD COLUMN IF NOT EXISTS case_type VARCHAR(80) NULL AFTER type,
    ADD COLUMN IF NOT EXISTS reported_category VARCHAR(120) NULL AFTER case_type,
    ADD COLUMN IF NOT EXISTS source VARCHAR(50) NULL AFTER reported_category,
    ADD COLUMN IF NOT EXISTS source_module VARCHAR(80) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS source_entity_type VARCHAR(120) NULL AFTER source_module,
    ADD COLUMN IF NOT EXISTS reference_id BIGINT UNSIGNED NULL AFTER source_entity_type,
    ADD COLUMN IF NOT EXISTS source_entity_id BIGINT UNSIGNED NULL AFTER reference_id,
    ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL AFTER source_entity_id,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER stock_item_id,
    ADD COLUMN IF NOT EXISTS quantity_affected DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER description,
    ADD COLUMN IF NOT EXISTS unit_name VARCHAR(80) NOT NULL DEFAULT 'unite' AFTER quantity_affected,
    ADD COLUMN IF NOT EXISTS final_qualification VARCHAR(120) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS responsibility_scope VARCHAR(120) NULL AFTER final_qualification,
    ADD COLUMN IF NOT EXISTS signal_notes TEXT NULL AFTER responsibility_scope,
    ADD COLUMN IF NOT EXISTS technical_notes TEXT NULL AFTER signal_notes,
    ADD COLUMN IF NOT EXISTS manager_justification TEXT NULL AFTER technical_notes,
    ADD COLUMN IF NOT EXISTS material_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER manager_justification,
    ADD COLUMN IF NOT EXISTS cash_loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER material_loss_amount,
    ADD COLUMN IF NOT EXISTS technical_confirmed_at DATETIME NULL AFTER validated_at,
    ADD COLUMN IF NOT EXISTS decided_at DATETIME NULL AFTER technical_confirmed_at,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER decided_at;

UPDATE restaurants
SET restaurant_code = COALESCE(NULLIF(restaurant_code, ''), CONCAT('restaurant-', id))
WHERE restaurant_code IS NULL OR restaurant_code = '';

UPDATE restaurants
SET slug = COALESCE(NULLIF(slug, ''), restaurant_code, CONCAT('restaurant-', id))
WHERE slug IS NULL OR slug = '';

UPDATE restaurants
SET subscription_status = CASE
        WHEN subscription_status IN ('DRAFT', 'PENDING_PAYMENT', 'PENDING_VALIDATION', 'ACTIVE', 'SUSPENDED', 'EXPIRED', 'GRACE_PERIOD') THEN subscription_status
        WHEN status = 'active' THEN 'ACTIVE'
        WHEN status IN ('suspended', 'banned', 'archived') THEN 'SUSPENDED'
        ELSE 'DRAFT'
    END,
    subscription_payment_status = CASE
        WHEN subscription_payment_status IN ('UNPAID', 'DECLARED', 'PAID', 'WAIVED') THEN subscription_payment_status
        WHEN status = 'active' THEN 'PAID'
        ELSE 'UNPAID'
    END
WHERE subscription_status IS NULL
   OR subscription_status = ''
   OR subscription_payment_status IS NULL
   OR subscription_payment_status = '';

UPDATE restaurants
SET subscription_grace_ends_at = DATE_ADD(subscription_ends_at, INTERVAL 2 DAY)
WHERE subscription_ends_at IS NOT NULL
  AND subscription_grace_ends_at IS NULL;

UPDATE restaurant_branding rb
INNER JOIN restaurants r ON r.id = rb.restaurant_id
SET rb.public_name = COALESCE(NULLIF(rb.public_name, ''), r.name),
    rb.app_display_name = COALESCE(NULLIF(rb.app_display_name, ''), rb.public_name, r.name),
    rb.app_short_name = COALESCE(NULLIF(rb.app_short_name, ''), LEFT(COALESCE(rb.public_name, r.name), 60)),
    rb.portal_title = COALESCE(NULLIF(rb.portal_title, ''), rb.public_name, r.name),
    rb.portal_tagline = COALESCE(NULLIF(rb.portal_tagline, ''), 'Pilotez stock, cuisine, ventes et rapports en temps reel.'),
    rb.updated_at = NOW();

UPDATE stock_items
SET estimated_unit_cost = COALESCE(estimated_unit_cost, 0);

UPDATE stock_movements sm
INNER JOIN stock_items si ON si.id = sm.stock_item_id
SET sm.unit_cost_snapshot = CASE
        WHEN sm.unit_cost_snapshot IS NULL OR sm.unit_cost_snapshot = 0 THEN COALESCE(si.estimated_unit_cost, 0)
        ELSE sm.unit_cost_snapshot
    END,
    sm.total_cost_snapshot = CASE
        WHEN sm.total_cost_snapshot IS NULL OR sm.total_cost_snapshot = 0 THEN sm.quantity * COALESCE(NULLIF(sm.unit_cost_snapshot, 0), si.estimated_unit_cost, 0)
        ELSE sm.total_cost_snapshot
    END;

UPDATE kitchen_production kp
INNER JOIN stock_movements sm ON sm.id = kp.stock_movement_id
LEFT JOIN menu_items mi ON mi.id = kp.menu_item_id
SET kp.total_real_cost_snapshot = CASE
        WHEN kp.total_real_cost_snapshot IS NULL OR kp.total_real_cost_snapshot = 0 THEN COALESCE(sm.total_cost_snapshot, 0)
        ELSE kp.total_real_cost_snapshot
    END,
    kp.unit_real_cost_snapshot = CASE
        WHEN kp.unit_real_cost_snapshot IS NULL OR kp.unit_real_cost_snapshot = 0 THEN CASE WHEN kp.quantity_produced > 0 THEN COALESCE(sm.total_cost_snapshot, 0) / kp.quantity_produced ELSE 0 END
        ELSE kp.unit_real_cost_snapshot
    END,
    kp.unit_sale_value_snapshot = CASE
        WHEN kp.unit_sale_value_snapshot IS NULL OR kp.unit_sale_value_snapshot = 0 THEN COALESCE(mi.price, 0)
        ELSE kp.unit_sale_value_snapshot
    END,
    kp.total_sale_value_snapshot = CASE
        WHEN kp.total_sale_value_snapshot IS NULL OR kp.total_sale_value_snapshot = 0 THEN kp.quantity_produced * COALESCE(mi.price, 0)
        ELSE kp.total_sale_value_snapshot
    END;

UPDATE server_requests
SET server_id = COALESCE(server_id, requested_by),
    requested_by = COALESCE(requested_by, server_id),
    total_returned_amount = COALESCE(total_returned_amount, 0),
    total_server_loss_amount = COALESCE(total_server_loss_amount, 0),
    updated_at = COALESCE(updated_at, created_at);

UPDATE server_request_items sri
INNER JOIN server_requests sr ON sr.id = COALESCE(sri.request_id, sri.server_request_id)
SET sri.request_id = COALESCE(sri.request_id, sri.server_request_id),
    sri.server_request_id = COALESCE(sri.server_request_id, sri.request_id),
    sri.restaurant_id = COALESCE(sri.restaurant_id, sr.restaurant_id),
    sri.returned_quantity = COALESCE(sri.returned_quantity, sri.returned_quantity_validated, 0),
    sri.returned_quantity_validated = COALESCE(sri.returned_quantity_validated, sri.returned_quantity, 0),
    sri.requested_total = COALESCE(sri.requested_total, sri.total_requested_amount, 0),
    sri.supplied_total = COALESCE(sri.supplied_total, sri.total_supplied_amount, 0),
    sri.sold_total = COALESCE(sri.sold_total, sri.total_sold_amount, 0),
    sri.returned_total = COALESCE(sri.returned_total, COALESCE(sri.returned_quantity, sri.returned_quantity_validated, 0) * COALESCE(sri.unit_price, 0), 0),
    sri.server_loss_total = COALESCE(sri.server_loss_total, GREATEST(COALESCE(sri.supplied_quantity, 0) - COALESCE(sri.sold_quantity, 0) - COALESCE(sri.returned_quantity, sri.returned_quantity_validated, 0), 0) * COALESCE(sri.unit_price, 0), 0),
    sri.total_requested_amount = COALESCE(sri.total_requested_amount, sri.requested_total, 0),
    sri.total_supplied_amount = COALESCE(sri.total_supplied_amount, sri.supplied_total, 0),
    sri.total_sold_amount = COALESCE(sri.total_sold_amount, sri.sold_total, 0),
    sri.status = COALESCE(NULLIF(sri.status, ''), NULLIF(sri.supply_status, ''), 'DEMANDE'),
    sri.supply_status = COALESCE(NULLIF(sri.supply_status, ''), NULLIF(sri.status, ''), 'DEMANDE'),
    sri.updated_at = COALESCE(sri.updated_at, sri.created_at);

UPDATE kitchen_stock_requests
SET quantity_requested = COALESCE(quantity_requested, quantity, 0),
    quantity_supplied = COALESCE(quantity_supplied, 0),
    status = COALESCE(NULLIF(status, ''), 'DEMANDE'),
    response_note = COALESCE(response_note, note),
    updated_at = COALESCE(updated_at, created_at);

UPDATE operation_cases
SET case_type = COALESCE(NULLIF(case_type, ''), NULLIF(type, ''), 'INCIDENT'),
    reported_category = COALESCE(reported_category, NULLIF(decision, ''), NULL),
    source_module = COALESCE(NULLIF(source_module, ''), NULLIF(source, ''), 'operations'),
    source_entity_type = COALESCE(NULLIF(source_entity_type, ''), NULLIF(source, ''), 'unknown'),
    source_entity_id = COALESCE(source_entity_id, reference_id, 0),
    description = COALESCE(description, signal_notes, manager_justification),
    quantity_affected = CASE WHEN quantity_affected IS NULL OR quantity_affected <= 0 THEN 1 ELSE quantity_affected END,
    unit_name = COALESCE(NULLIF(unit_name, ''), 'unite'),
    final_qualification = COALESCE(final_qualification, decision),
    signal_notes = COALESCE(signal_notes, description),
    material_loss_amount = COALESCE(material_loss_amount, 0),
    cash_loss_amount = COALESCE(cash_loss_amount, 0),
    signaled_by = COALESCE(signaled_by, created_by),
    technical_confirmed_by = COALESCE(technical_confirmed_by, validated_by),
    decided_by = COALESCE(decided_by, validated_by),
    resolved_by = COALESCE(resolved_by, decided_by, validated_by, technical_confirmed_by),
    validated_at = COALESCE(validated_at, decided_at),
    technical_confirmed_at = COALESCE(technical_confirmed_at, validated_at),
    decided_at = COALESCE(decided_at, validated_at),
    resolved_at = COALESCE(resolved_at, decided_at, validated_at);

INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
SELECT NULL, src.setting_key, src.setting_value, 'json', 0, NOW(), NOW()
FROM (
    SELECT 'global_validation_states_json' AS setting_key, '["PROPOSE","CONFIRME_TECHNIQUEMENT","EN_ATTENTE_VALIDATION_MANAGER","VALIDE","REJETE"]' AS setting_value
    UNION ALL SELECT 'global_final_qualifications_json', '["retour_simple","aliment_a_jeter","boisson_cassee","perte_cuisine","perte_serveur","perte_stock","perte_matiere","perte_argent","autre"]'
    UNION ALL SELECT 'global_responsibility_targets_json', '["cuisine","serveur","stock","restaurant","autre"]'
    UNION ALL SELECT 'global_incident_types_json', '["retour_simple","retour_avec_anomalie","produit_defectueux","produit_casse","produit_impropre","retour_stock_endommage","perte_matiere","perte_argent"]'
    UNION ALL SELECT 'global_client_access_rules_json', '{"public_menu_enabled":true,"public_restaurant_info_enabled":true,"auth_required_for_order":true,"auth_required_for_reservation":true}'
    UNION ALL SELECT 'global_automation_rules_json', '{"sale_auto_after_hours":24}'
    UNION ALL SELECT 'global_subscription_rules_json', '{"subscription_grace_days":2,"subscription_warning_days":5,"default_duration_days":30}'
    UNION ALL SELECT 'global_alert_rules_json', '{"server_incident_threshold":3,"kitchen_loss_threshold":2,"repeated_inconsistency_threshold":2,"frequent_return_threshold":3}'
    UNION ALL SELECT 'global_default_restaurant_settings_json', '{"restaurant_return_window_hours":24,"restaurant_loss_validation_required":true,"restaurant_public_menu_enabled":true,"restaurant_public_order_requires_auth":true,"restaurant_public_reservation_requires_auth":true}'
    UNION ALL SELECT 'global_module_catalog_json', '["menu","stock","kitchen","sales","reports","roles","branding"]'
) AS src
LEFT JOIN settings s
    ON s.restaurant_id IS NULL
   AND s.setting_key = src.setting_key
WHERE s.id IS NULL;

INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
SELECT r.id, 'restaurant_public_menu_enabled', '1', 'boolean', 0, NOW(), NOW()
FROM restaurants r
LEFT JOIN settings s ON s.restaurant_id = r.id AND s.setting_key = 'restaurant_public_menu_enabled'
WHERE s.id IS NULL;

INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
SELECT r.id, 'restaurant_public_order_requires_auth', '1', 'boolean', 0, NOW(), NOW()
FROM restaurants r
LEFT JOIN settings s ON s.restaurant_id = r.id AND s.setting_key = 'restaurant_public_order_requires_auth'
WHERE s.id IS NULL;

INSERT INTO settings (restaurant_id, setting_key, setting_value, value_type, is_sensitive, created_at, updated_at)
SELECT r.id, 'restaurant_public_reservation_requires_auth', '1', 'boolean', 0, NOW(), NOW()
FROM restaurants r
LEFT JOIN settings s ON s.restaurant_id = r.id AND s.setting_key = 'restaurant_public_reservation_requires_auth'
WHERE s.id IS NULL;

INSERT INTO restaurant_modules (restaurant_id, module_code, is_enabled, configured_by, configured_at, created_at, updated_at)
SELECT r.id, m.module_code, 1, NULL, NOW(), NOW(), NOW()
FROM restaurants r
INNER JOIN (
    SELECT 'menu' AS module_code
    UNION ALL SELECT 'stock'
    UNION ALL SELECT 'kitchen'
    UNION ALL SELECT 'sales'
    UNION ALL SELECT 'reports'
    UNION ALL SELECT 'roles'
    UNION ALL SELECT 'branding'
) m
LEFT JOIN restaurant_modules rm
    ON rm.restaurant_id = r.id
   AND rm.module_code = m.module_code
WHERE rm.id IS NULL;

DELETE rp
FROM role_permissions rp
INNER JOIN roles r ON r.id = rp.role_id
INNER JOIN permissions p ON p.id = rp.permission_id
WHERE r.code IN ('stock_manager', 'kitchen', 'cashier_server')
  AND p.code IN ('reports.view', 'reports.daily');

DROP PROCEDURE IF EXISTS add_index_if_missing;
DELIMITER $$
CREATE PROCEDURE add_index_if_missing(IN p_table VARCHAR(128), IN p_index VARCHAR(128), IN p_sql TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = p_sql;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL add_index_if_missing('restaurants', 'uq_restaurants_restaurant_code', 'ALTER TABLE restaurants ADD UNIQUE KEY uq_restaurants_restaurant_code (restaurant_code)');
CALL add_index_if_missing('restaurants', 'uq_restaurants_slug', 'ALTER TABLE restaurants ADD UNIQUE KEY uq_restaurants_slug (slug)');
CALL add_index_if_missing('server_requests', 'idx_server_requests_restaurant_date', 'ALTER TABLE server_requests ADD INDEX idx_server_requests_restaurant_date (restaurant_id, created_at)');
CALL add_index_if_missing('server_requests', 'idx_server_requests_server', 'ALTER TABLE server_requests ADD INDEX idx_server_requests_server (server_id)');
CALL add_index_if_missing('server_request_items', 'idx_server_request_items_request', 'ALTER TABLE server_request_items ADD INDEX idx_server_request_items_request (request_id)');
CALL add_index_if_missing('server_request_items', 'idx_server_request_items_restaurant', 'ALTER TABLE server_request_items ADD INDEX idx_server_request_items_restaurant (restaurant_id)');
CALL add_index_if_missing('kitchen_stock_requests', 'idx_kitchen_stock_requests_restaurant_date', 'ALTER TABLE kitchen_stock_requests ADD INDEX idx_kitchen_stock_requests_restaurant_date (restaurant_id, created_at)');
CALL add_index_if_missing('operation_cases', 'idx_operation_cases_restaurant_date', 'ALTER TABLE operation_cases ADD INDEX idx_operation_cases_restaurant_date (restaurant_id, created_at)');
CALL add_index_if_missing('operation_cases', 'idx_operation_cases_status', 'ALTER TABLE operation_cases ADD INDEX idx_operation_cases_status (status)');
CALL add_index_if_missing('operation_cases', 'idx_operation_cases_source', 'ALTER TABLE operation_cases ADD INDEX idx_operation_cases_source (source_module, source_entity_type, source_entity_id)');

DROP PROCEDURE IF EXISTS add_index_if_missing;
