<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/private_storage.php';
require_once __DIR__ . '/../includes/private_credentials.php';
require_once __DIR__ . '/../includes/jobs.php';

$failures = 0;
/** @param callable(): void $check */
$report = static function (string $label, callable $check) use (&$failures): void {
    try {
        $check();
        echo 'PASS ' . $label . PHP_EOL;
    } catch (Throwable $error) {
        $failures++;
        echo 'FAIL ' . $label . ' (' . ($error instanceof SecurityFault ? $error->errorCode : 'CHECK_FAILED') . ')' . PHP_EOL;
    }
};

$report('Canonical application URL', static function (): void { app_url(); });
$report('Payment mode', static function (): void { payment_mode(); });
$report('Private data encryption key', static function (): void { private_encryption_key(); });
$report('Email job encryption key', static function (): void { encode_job_payload('email', []); });
$report('DOCX archive validation extension', static function (): void {
    if (!class_exists('ZipArchive')) throw new SecurityFault('ZIP_EXTENSION_REQUIRED', 'ZIP extension is required.', 503);
});
$report('Existing private storage outside the web root', static function (): void {
    // A preflight must not create directories or write any private files.
    $root = realpath(app_setting('PRIVATE_STORAGE_ROOT'));
    $webRoot = realpath(app_setting('WEB_DOCUMENT_ROOT', dirname(__DIR__, 3)));
    if ($root === false || !is_dir($root) || path_within($root, dirname(__DIR__))
        || $webRoot === false || path_within($root, $webRoot)) {
        throw new SecurityFault('STORAGE_CONFIGURATION_INVALID', 'Configure an existing private storage directory.', 503);
    }
});
$report('Application database connection and security migration', static function (): void {
    $pdo = get_db();
    require_runtime_schema($pdo);
    foreach (['auth_sessions', 'auth_devices', 'security_rate_limits', 'security_audit', 'application_tracking_tokens',
        'payment_webhook_events', 'request_idempotency', 'payment_attempts', 'background_jobs', 'private_files', 'auth_throttle', 'password_resets'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() !== 1) throw new SecurityFault('MIGRATION_REQUIRED', 'Security tables are missing.', 503);
    }
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'stock_restored_at'")->fetch()) {
        throw new SecurityFault('MIGRATION_REQUIRED', 'Order security columns are missing.', 503);
    }
});
echo $failures === 0 ? "Preflight passed. Deployment still requires HTTPS and worker verification.\n" : "Resolve failed checks before deployment. No schema or private data was changed.\n";
exit($failures === 0 ? 0 : 1);
