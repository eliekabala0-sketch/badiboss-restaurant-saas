CREATE TABLE IF NOT EXISTS subscription_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(100) NOT NULL UNIQUE,
    transaction_id VARCHAR(190) NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    payload_json JSON NULL,
    provider_response_json JSON NULL,
    webhook_payload_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_subscription_payments_restaurant (restaurant_id),
    INDEX idx_subscription_payments_transaction (transaction_id),
    CONSTRAINT fk_subscription_payments_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
);
