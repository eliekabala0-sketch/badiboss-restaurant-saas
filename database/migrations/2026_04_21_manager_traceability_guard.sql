ALTER TABLE operation_cases
    ADD COLUMN responsible_user_id BIGINT UNSIGNED NULL AFTER responsibility_scope,
    ADD COLUMN submitted_to_manager_by BIGINT UNSIGNED NULL AFTER technical_confirmed_by,
    ADD COLUMN submitted_to_manager_at DATETIME NULL AFTER technical_confirmed_at,
    ADD COLUMN trace_snapshot_json LONGTEXT NULL AFTER manager_justification;
