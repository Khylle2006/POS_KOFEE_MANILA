<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_MIGRATING', true);
require_once __DIR__ . '/../includes/db.php';

$name = app_setting('MIGRATION_DB_NAME');
$user = app_setting('MIGRATION_DB_USER');
if ($name === '' || $user === '') {
    fwrite(STDERR, "Set MIGRATION_DB_NAME, MIGRATION_DB_USER and MIGRATION_DB_PASS explicitly.\n");
    exit(1);
}
try {
    $pdo = new PDO('mysql:host=' . app_setting('MIGRATION_DB_HOST', 'localhost') . ';dbname=' . $name . ';charset=utf8mb4', $user, app_setting('MIGRATION_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '+08:00'");
    $GLOBALS['migration_pdo'] = $pdo;
    if (!(int)$pdo->query("SELECT GET_LOCK('kofee_schema_migration', 10)")->fetchColumn()) {
        throw new RuntimeException('Migration lock unavailable.');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    if (!$pdo->query("SELECT 1 FROM app_migrations WHERE version = '20261008_security_v1'")->fetchColumn()) {
        require_once __DIR__ . '/../includes/permissions.php';
        require_once __DIR__ . '/../includes/paymongo.php';
        require_once __DIR__ . '/../database/migrations/legacy_schema.php';
        require_once __DIR__ . '/../database/migrations/security_v1.php';
        migration_ensure_login_approval_tables($pdo);
        migration_ensure_procurement_letters_table();
        migration_ensure_procurement_tables($pdo);
        migration_ensure_notifications_table($pdo);
        migration_paymongo_ensure_order_columns($pdo);
        migrate_security_v1($pdo);
        migration_install_default_permissions();
        $pdo->prepare('INSERT INTO app_migrations (version) VALUES (?)')->execute(['20261008_security_v1']);
    }
    if (!$pdo->query("SELECT 1 FROM app_migrations WHERE version = '20261010_email_mfa_v1'")->fetchColumn()) {
        require_once __DIR__ . '/../database/migrations/email_mfa_v1.php';
        migrate_email_mfa_v1($pdo);
        $pdo->prepare('INSERT INTO app_migrations (version) VALUES (?)')->execute(['20261010_email_mfa_v1']);
    }
    $pdo->query("SELECT RELEASE_LOCK('kofee_schema_migration')");
    echo "Database migrations complete.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . get_class($exception) . '. Check schema compatibility and credentials; the version was not recorded.' . PHP_EOL);
    exit(1);
}
