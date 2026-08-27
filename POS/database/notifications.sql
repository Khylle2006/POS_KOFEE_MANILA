CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role_key VARCHAR(50) NULL,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    event_key VARCHAR(180) NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id, read_at, created_at),
    INDEX idx_notifications_role (role_key, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compatibility upgrades for installations that already have an older
-- user-only notifications table.
ALTER TABLE notifications
    MODIFY user_id INT NULL,
    ADD COLUMN IF NOT EXISTS role_key VARCHAR(50) NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS link VARCHAR(255) NULL AFTER message,
    ADD COLUMN IF NOT EXISTS event_key VARCHAR(180) NULL AFTER link,
    ADD COLUMN IF NOT EXISTS read_at DATETIME NULL AFTER event_key;

UPDATE notifications SET event_key = CONCAT('legacy:', id) WHERE event_key IS NULL;
ALTER TABLE notifications
    MODIFY event_key VARCHAR(180) NOT NULL,
    ADD UNIQUE KEY uniq_notification_event (event_key);
