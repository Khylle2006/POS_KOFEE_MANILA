<?php
declare(strict_types=1);

/** Additive opt-in settings; an absent row means MFA is off. */
function migrate_email_mfa_v1(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_email_mfa (
        user_id INT NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        verified_email VARCHAR(190) DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
