USE badiboss_restaurant_saas;

SET NAMES utf8mb4;

ALTER TABLE server_requests
    MODIFY status ENUM(
        'DEMANDE',
        'EN_PREPARATION',
        'PRET_A_SERVIR',
        'REMIS_SERVEUR',
        'FOURNI_PARTIEL',
        'FOURNI_TOTAL',
        'VENDU_PARTIEL',
        'VENDU_TOTAL',
        'CLOTURE'
    ) NOT NULL DEFAULT 'DEMANDE',
    ADD COLUMN IF NOT EXISTS service_reference VARCHAR(120) NULL AFTER server_id,
    ADD COLUMN IF NOT EXISTS ready_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by,
    ADD COLUMN IF NOT EXISTS received_by BIGINT UNSIGNED NULL AFTER ready_by,
    ADD COLUMN IF NOT EXISTS ready_at DATETIME NULL AFTER supplied_at,
    ADD COLUMN IF NOT EXISTS received_at DATETIME NULL AFTER ready_at;

ALTER TABLE server_request_items
    MODIFY supply_status ENUM(
        'DEMANDE',
        'EN_PREPARATION',
        'PRET_A_SERVIR',
        'REMIS_SERVEUR',
        'FOURNI_TOTAL',
        'FOURNI_PARTIEL',
        'NON_FOURNI',
        'CLOTURE'
    ) NOT NULL DEFAULT 'DEMANDE',
    ADD COLUMN IF NOT EXISTS unavailable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER supplied_quantity,
    ADD COLUMN IF NOT EXISTS prepared_at DATETIME NULL AFTER technical_confirmed_by,
    ADD COLUMN IF NOT EXISTS received_by BIGINT UNSIGNED NULL AFTER prepared_at,
    ADD COLUMN IF NOT EXISTS received_at DATETIME NULL AFTER received_by;

ALTER TABLE kitchen_stock_requests
    MODIFY status ENUM(
        'DEMANDE',
        'EN_COURS_TRAITEMENT',
        'FOURNI_TOTAL',
        'FOURNI_PARTIEL',
        'NON_FOURNI',
        'DISPONIBLE',
        'PARTIELLEMENT_DISPONIBLE',
        'INDISPONIBLE',
        'CLOTURE'
    ) NOT NULL DEFAULT 'DEMANDE',
    ADD COLUMN IF NOT EXISTS priority_level ENUM('normale', 'urgente') NOT NULL DEFAULT 'normale' AFTER status,
    ADD COLUMN IF NOT EXISTS unavailable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_supplied,
    ADD COLUMN IF NOT EXISTS received_by BIGINT UNSIGNED NULL AFTER responded_by,
    ADD COLUMN IF NOT EXISTS received_at DATETIME NULL AFTER responded_at;

UPDATE kitchen_stock_requests
SET status = 'FOURNI_TOTAL'
WHERE status = 'DISPONIBLE';

UPDATE kitchen_stock_requests
SET status = 'FOURNI_PARTIEL'
WHERE status = 'PARTIELLEMENT_DISPONIBLE';

UPDATE kitchen_stock_requests
SET status = 'NON_FOURNI'
WHERE status = 'INDISPONIBLE';

UPDATE server_request_items
SET unavailable_quantity = GREATEST(requested_quantity - supplied_quantity, 0)
WHERE unavailable_quantity = 0;

UPDATE kitchen_stock_requests
SET unavailable_quantity = GREATEST(quantity_requested - quantity_supplied, 0)
WHERE unavailable_quantity = 0;
