<?php
declare(strict_types=1);

/** @return list<string> */
function security_schema_sql(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS auth_sessions (token_hash CHAR(64) PRIMARY KEY, user_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, KEY user_sessions (user_id, revoked_at)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS auth_devices (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, approved_by INT NOT NULL, expires_at DATETIME NOT NULL, KEY user_devices (user_id)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS security_rate_limits (bucket_key CHAR(64) PRIMARY KEY, window_start DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS security_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, actor_id INT NULL, action VARCHAR(80) NOT NULL, entity_type VARCHAR(60) NOT NULL, entity_id BIGINT NULL, request_id CHAR(24) NOT NULL, details TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY audit_time (created_at), KEY audit_actor (actor_id)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS application_tracking_tokens (id BIGINT AUTO_INCREMENT PRIMARY KEY, application_type VARCHAR(20) NOT NULL, application_id INT NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, KEY tracking_application (application_type, application_id)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS payment_webhook_events (event_id VARCHAR(120) PRIMARY KEY, payload_hash CHAR(64) NOT NULL, processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS request_idempotency (scope VARCHAR(80) NOT NULL, actor_id INT NOT NULL, key_hash CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL, response MEDIUMTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (scope, actor_id, key_hash)) ENGINE=InnoDB',
        "CREATE TABLE IF NOT EXISTS payment_attempts (id BIGINT AUTO_INCREMENT PRIMARY KEY, operation_key VARCHAR(120) NOT NULL UNIQUE, entity_type VARCHAR(30) NOT NULL, entity_id INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', provider_id VARCHAR(120) NULL, payload_hash CHAR(64) NULL, amount_centavos BIGINT NOT NULL DEFAULT 0, response MEDIUMTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS background_jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY, job_key VARCHAR(160) NOT NULL UNIQUE, job_type VARCHAR(40) NOT NULL, payload MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0, available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_at DATETIME NULL, last_error_code VARCHAR(80) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY job_queue (status, available_at)) ENGINE=InnoDB",
        'CREATE TABLE IF NOT EXISTS private_files (id BIGINT AUTO_INCREMENT PRIMARY KEY, logical_path VARCHAR(255) NOT NULL UNIQUE, storage_name CHAR(64) NOT NULL UNIQUE, sha256 CHAR(64) NOT NULL, mime_type VARCHAR(120) NOT NULL, byte_size BIGINT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS auth_throttle (id BIGINT AUTO_INCREMENT PRIMARY KEY, identifier VARCHAR(190) NOT NULL, ip_address VARCHAR(45) NOT NULL, succeeded TINYINT NOT NULL, attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY auth_identifier (identifier, attempted_at), KEY auth_ip (ip_address, attempted_at)) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS password_resets (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, email VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY reset_token (token), KEY reset_user (user_id)) ENGINE=InnoDB',
    ];
}

function migrate_security_v1(PDO $pdo): void
{
    foreach (security_schema_sql() as $sql) {
        $pdo->exec($sql);
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'stock_restored_at'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE orders ADD stock_restored_at DATETIME NULL');
    }
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE used_at IS NULL AND token NOT REGEXP '^[a-f0-9]{64}$'")->execute();
}
