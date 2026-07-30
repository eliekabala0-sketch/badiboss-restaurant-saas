-- Module Audit externe. Migration strictement additive et sans lecture/ecriture
-- dans les tables operationnelles sales, stock_movements ou cash_transfers.
CREATE TABLE IF NOT EXISTS external_audit_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(80) NOT NULL,
    included_in_global TINYINT(1) NOT NULL DEFAULT 1,
    audit_mode VARCHAR(40) NOT NULL DEFAULT 'stock',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_category (restaurant_id, code),
    KEY idx_ea_category_restaurant (restaurant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    source_product_id BIGINT UNSIGNED NULL,
    name VARCHAR(190) NOT NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'unite',
    product_type VARCHAR(50) NOT NULL DEFAULT 'standard',
    sale_price DECIMAL(16,2) NOT NULL DEFAULT 0,
    usual_purchase_price DECIMAL(16,2) NOT NULL DEFAULT 0,
    units_per_case DECIMAL(12,3) NOT NULL DEFAULT 0,
    units_per_half_case DECIMAL(12,3) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_product_restaurant (restaurant_id, category_id, status),
    CONSTRAINT fk_ea_product_category FOREIGN KEY (category_id) REFERENCES external_audit_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_type VARCHAR(40) NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    activity_date DATE NOT NULL,
    operational_author_id BIGINT UNSIGNED NOT NULL,
    entered_by BIGINT UNSIGNED NOT NULL,
    entered_by_role VARCHAR(80) NOT NULL,
    delegation_reason VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'BROUILLON',
    version_no INT UNSIGNED NOT NULL DEFAULT 1,
    idempotency_key VARCHAR(100) NULL,
    observations TEXT NULL,
    declared_sales DECIMAL(16,2) NOT NULL DEFAULT 0,
    presented_cash DECIMAL(16,2) NOT NULL DEFAULT 0,
    adjustments_validated DECIMAL(16,2) NOT NULL DEFAULT 0,
    submitted_at DATETIME NULL,
    locked_at DATETIME NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_report_author_day (restaurant_id, operational_author_id, activity_date, report_type),
    UNIQUE KEY uq_ea_report_idempotency (restaurant_id, idempotency_key),
    KEY idx_ea_report_dashboard (restaurant_id, activity_date, status),
    KEY idx_ea_report_author (restaurant_id, operational_author_id, activity_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_role_expectations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(80) NOT NULL,
    role_label VARCHAR(120) NOT NULL,
    report_type VARCHAR(40) NOT NULL,
    deadline_time TIME NOT NULL DEFAULT '23:00:00',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_expectation_role (restaurant_id, role_code),
    KEY idx_ea_expectation_active (restaurant_id, is_required, role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_report_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    product_name_snapshot VARCHAR(190) NOT NULL,
    category_name_snapshot VARCHAR(120) NOT NULL,
    unit_snapshot VARCHAR(40) NOT NULL,
    sale_price_snapshot DECIMAL(16,2) NOT NULL,
    purchase_price_snapshot DECIMAL(16,2) NOT NULL DEFAULT 0,
    previous_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
    purchased_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    purchase_total DECIMAL(16,2) NOT NULL DEFAULT 0,
    explained_entries DECIMAL(14,3) NOT NULL DEFAULT 0,
    explained_outputs DECIMAL(14,3) NOT NULL DEFAULT 0,
    remaining_stock DECIMAL(14,3) NULL,
    sold_quantity_declared DECIMAL(14,3) NOT NULL DEFAULT 0,
    credit_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    expense_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    incident_note TEXT NULL,
    omitted_remaining_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    calculated_available DECIMAL(14,3) NOT NULL DEFAULT 0,
    calculated_sold_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    calculated_injection_quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    calculated_sale_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    calculated_injection_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_report_product (restaurant_id, report_id, product_id),
    KEY idx_ea_item_report (restaurant_id, report_id),
    CONSTRAINT fk_ea_item_report FOREIGN KEY (report_id) REFERENCES external_audit_reports(id),
    CONSTRAINT fk_ea_item_product FOREIGN KEY (product_id) REFERENCES external_audit_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    engine_version VARCHAR(40) NOT NULL,
    calculated_sales DECIMAL(16,2) NOT NULL DEFAULT 0,
    declared_sales DECIMAL(16,2) NOT NULL DEFAULT 0,
    purchases DECIMAL(16,2) NOT NULL DEFAULT 0,
    expenses DECIMAL(16,2) NOT NULL DEFAULT 0,
    credits DECIMAL(16,2) NOT NULL DEFAULT 0,
    missing_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    suspicious_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    injection_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    prudent_base DECIMAL(16,2) NOT NULL DEFAULT 0,
    expected_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    presented_cash DECIMAL(16,2) NOT NULL DEFAULT 0,
    cash_gap DECIMAL(16,2) NOT NULL DEFAULT 0,
    snapshot_json JSON NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_result_report (restaurant_id, report_id),
    KEY idx_ea_result_period (restaurant_id, created_at),
    CONSTRAINT fk_ea_result_report FOREIGN KEY (report_id) REFERENCES external_audit_reports(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_report_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    snapshot_json JSON NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_revision (restaurant_id, report_id, version_no),
    KEY idx_ea_revision_report (restaurant_id, report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_correction_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    requested_by BIGINT UNSIGNED NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    decision_note VARCHAR(255) NULL,
    decided_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_correction (restaurant_id, report_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_losses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    activity_date DATE NOT NULL,
    quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    value_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
    responsible_user_id BIGINT UNSIGNED NULL,
    involved_people_json JSON NULL,
    cause VARCHAR(255) NULL,
    evidence_path VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'A_VERIFIER',
    manager_decision TEXT NULL,
    decision_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_loss_analysis (restaurant_id, activity_date, status, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NULL,
    action_code VARCHAR(100) NOT NULL,
    details_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_log (restaurant_id, report_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NULL,
    loss_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(190) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_attachment_report (restaurant_id, report_id),
    KEY idx_ea_attachment_loss (restaurant_id, loss_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_confrontations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    confrontation_type VARCHAR(40) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    report_snapshot_json JSON NOT NULL,
    comparison_snapshot_json JSON NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'A_EXPLIQUER',
    observation TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_confrontation_period (restaurant_id, confrontation_type, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_closures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    period_type VARCHAR(30) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    totals_snapshot_json JSON NOT NULL,
    validation_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'VERROUILLE',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ea_closure_period (restaurant_id, period_type, period_start, period_end),
    KEY idx_ea_closure_restaurant (restaurant_id, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_audit_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    export_type VARCHAR(30) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    file_name VARCHAR(190) NOT NULL,
    totals_snapshot_json JSON NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ea_export_period (restaurant_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (module_name, action_name, code, description, is_sensitive, created_at, updated_at) VALUES
('external_audit', 'view', 'audit.external.view', 'Acceder au module Audit externe', 0, NOW(), NOW()),
('external_audit', 'manage', 'audit.external.manage', 'Gerer catalogue, rapports et clotures', 1, NOW(), NOW()),
('external_audit', 'reset_report', 'audit.reset_report', 'Archiver et rouvrir un rapport Audit externe', 1, NOW(), NOW()),
('external_audit', 'delete_test', 'audit.delete_test', 'Suppression definitive limitee aux tests sandbox', 1, NOW(), NOW());
