ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS paymongo_session_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS paymongo_payment_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending';