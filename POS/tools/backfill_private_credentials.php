<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/private_credentials.php';
try {
    $pdo = get_db(); require_runtime_schema($pdo); private_encryption_key();
    $pdo->beginTransaction();
    $count = 0;
    foreach (['employee_payment_details', 'employee_payment_change_requests'] as $table) {
        $rows = $pdo->query("SELECT id, account_number_encrypted FROM $table WHERE account_number_encrypted IS NOT NULL FOR UPDATE")->fetchAll();
        $update = $pdo->prepare("UPDATE $table SET account_number_encrypted = ? WHERE id = ?");
        foreach ($rows as $row) {
            if (str_starts_with($row['account_number_encrypted'], 'v1:')) { decrypt_private_value($row['account_number_encrypted']); continue; }
            $plain = base64_decode($row['account_number_encrypted'], true);
            if ($plain === false || !preg_match('/^[0-9]{8,20}$/D', $plain)) throw new RuntimeException('Invalid legacy account details require review.');
            $update->execute([encrypt_private_value($plain), $row['id']]); $count++;
        }
    }
    security_audit($pdo, 'credentials_backfilled', 'migration', null, ['count' => $count]);
    $pdo->commit(); echo $count . " account records encrypted.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Credential backfill failed: ' . get_class($exception) . PHP_EOL); exit(1);
}
