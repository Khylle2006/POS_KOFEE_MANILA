<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime.php';

function private_encryption_key(): string
{
    $key = base64_decode(app_setting('PRIVATE_DATA_KEY'), true);
    if ($key === false || strlen($key) !== 32) throw new SecurityFault('PRIVATE_KEY_NOT_CONFIGURED', 'Private account storage is unavailable.', 503);
    return $key;
}

function encrypt_private_value(string $value): string
{
    $iv = random_bytes(12);
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', private_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new SecurityFault('ENCRYPTION_FAILED', 'Private account storage is unavailable.', 503);
    return 'v1:' . base64_encode($iv . $tag . $encrypted);
}

function decrypt_private_value(string $encoded): string
{
    if (!str_starts_with($encoded, 'v1:')) throw new SecurityFault('PRIVATE_DATA_BACKFILL_REQUIRED', 'Account details require a secure migration before payout.', 503);
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) < 28) throw new SecurityFault('DECRYPTION_FAILED', 'Private account storage is unavailable.', 503);
    $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', private_encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($value === false) throw new SecurityFault('DECRYPTION_FAILED', 'Private account storage is unavailable.', 503);
    return $value;
}
