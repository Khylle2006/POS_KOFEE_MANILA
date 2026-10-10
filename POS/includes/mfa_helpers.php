<?php
// ==============================================================================
// FILE: includes/mfa_helpers.php
// Multi-Factor Authentication (MFA) & Email OTP Tokenization Engine
// Provides secure code generation, tokenized session verification, and throttling.
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mailer.php';

/**
 * Ensures the database table for login MFA codes exists.
 */
function ensure_mfa_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_mfa_codes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `mfa_token` varchar(64) NOT NULL,
            `code_hash` varchar(255) NOT NULL,
            `attempts` int(11) NOT NULL DEFAULT 0,
            `resend_count` int(11) NOT NULL DEFAULT 0,
            `last_sent_at` datetime NOT NULL,
            `expires_at` datetime NOT NULL,
            `verified_at` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_mfa_token` (`mfa_token`),
            KEY `idx_user_expires` (`user_id`, `expires_at`),
            KEY `idx_token_verified` (`mfa_token`, `verified_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

/**
 * Masks an email address for safe display in the UI (e.g. j***n@domain.com).
 */
function mask_email_address(?string $email): string {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'your registered email';
    }

    $parts = explode('@', $email, 2);
    $name  = $parts[0];
    $domain = $parts[1];

    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = substr($name, 0, 1) . '***';
    } elseif ($len <= 4) {
        $maskedName = substr($name, 0, 1) . '***' . substr($name, -1);
    } else {
        $maskedName = substr($name, 0, 2) . '***' . substr($name, -2);
    }

    return $maskedName . '@' . $domain;
}

/**
 * Generates a cryptographically secure 6-digit numeric OTP code.
 */
function generate_mfa_otp(): string {
    return sprintf('%06d', random_int(100000, 999999));
}

/**
 * Initializes a new MFA challenge for a user upon successful password verification.
 * Dispatches an email with the verification code and returns tokenized session details.
 *
 * @param PDO   $pdo
 * @param array $user
 * @return array{ok: bool, mfa_token?: string, masked_email?: string, expires_at?: string, error?: string, mail_result?: array}
 */
function create_mfa_challenge(PDO $pdo, array $user): array {
    ensure_mfa_table($pdo);

    $userId = (int)$user['id'];
    $email  = trim($user['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok'    => false,
            'error' => 'No valid email address registered for this account. Please contact your administrator.'
        ];
    }

    // Invalidate existing active unverified MFA codes for this user
    try {
        $pdo->prepare("
            UPDATE login_mfa_codes
               SET expires_at = NOW()
             WHERE user_id = :uid
               AND verified_at IS NULL
               AND expires_at > NOW()
        ")->execute([':uid' => $userId]);
    } catch (PDOException $e) {
        error_log('create_mfa_challenge invalidate failed: ' . $e->getMessage());
    }

    $rawOtp    = generate_mfa_otp();
    $codeHash  = password_hash($rawOtp, PASSWORD_DEFAULT);
    $mfaToken  = bin2hex(random_bytes(32)); // 64 hex characters
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60)); // 10 minutes

    try {
        $ins = $pdo->prepare("
            INSERT INTO login_mfa_codes (
                user_id, mfa_token, code_hash, attempts, resend_count,
                last_sent_at, expires_at
            ) VALUES (
                :uid, :tok, :hash, 0, 0,
                NOW(), :exp
            )
        ");
        $ins->execute([
            ':uid'  => $userId,
            ':tok'  => $mfaToken,
            ':hash' => $codeHash,
            ':exp'  => $expiresAt,
        ]);
    } catch (PDOException $e) {
        error_log('create_mfa_challenge insert failed: ' . $e->getMessage());
        return [
            'ok'    => false,
            'error' => 'Unable to initialize MFA session. Please try again.'
        ];
    }

    $nameToUse = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: $user['username'];
    $mailResult = send_mfa_code_email($email, $nameToUse, $rawOtp, 10);

    return [
        'ok'           => true,
        'mfa_token'    => $mfaToken,
        'masked_email' => mask_email_address($email),
        'expires_at'   => $expiresAt,
        'mail_result'  => $mailResult,
    ];
}

/**
 * Retrieves details of an MFA challenge by its session token.
 */
function get_mfa_challenge(PDO $pdo, string $mfaToken): ?array {
    ensure_mfa_table($pdo);

    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.firstname, u.lastname, u.email, u.role, u.status
          FROM login_mfa_codes m
          JOIN users u ON u.id = m.user_id
         WHERE m.mfa_token = :tok
         LIMIT 1
    ");
    $stmt->execute([':tok' => $mfaToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Resends a fresh MFA verification code for an existing active session.
 * Enforces rate limiting (60s cooldown) and maximum resend caps.
 *
 * @param PDO    $pdo
 * @param string $mfaToken
 * @return array{ok: bool, message?: string, wait_seconds?: int, error?: string, masked_email?: string}
 */
function resend_mfa_challenge(PDO $pdo, string $mfaToken): array {
    ensure_mfa_table($pdo);

    $challenge = get_mfa_challenge($pdo, $mfaToken);
    if (!$challenge) {
        return ['ok' => false, 'error' => 'Verification session expired or invalid. Please sign in again.'];
    }

    if (!empty($challenge['verified_at'])) {
        return ['ok' => false, 'error' => 'This session has already been verified.'];
    }

    // Rate limiting cooldown (60 seconds between resends)
    $lastSent = strtotime($challenge['last_sent_at']);
    $cooldown = 60;
    $elapsed  = time() - $lastSent;
    if ($elapsed < $cooldown) {
        $remainingSec = $cooldown - $elapsed;
        return [
            'ok'           => false,
            'wait_seconds' => $remainingSec,
            'error'        => "Please wait {$remainingSec} second(s) before requesting another code."
        ];
    }

    // Maximum resend cap
    if ((int)$challenge['resend_count'] >= 4) {
        return [
            'ok'    => false,
            'error' => 'You have reached the maximum number of code requests. Please start a new sign in session.'
        ];
    }

    // Generate new OTP & renew expiry (10 mins)
    $rawOtp    = generate_mfa_otp();
    $codeHash  = password_hash($rawOtp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));

    try {
        $upd = $pdo->prepare("
            UPDATE login_mfa_codes
               SET code_hash = :hash,
                   resend_count = resend_count + 1,
                   attempts = 0,
                   last_sent_at = NOW(),
                   expires_at = :exp
             WHERE id = :id
        ");
        $upd->execute([
            ':hash' => $codeHash,
            ':exp'  => $expiresAt,
            ':id'   => $challenge['id']
        ]);
    } catch (PDOException $e) {
        error_log('resend_mfa_challenge update failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Failed to refresh verification code. Please try again.'];
    }

    $nameToUse = trim(($challenge['firstname'] ?? '') . ' ' . ($challenge['lastname'] ?? '')) ?: $challenge['username'];
    $mailResult = send_mfa_code_email($challenge['email'], $nameToUse, $rawOtp, 10);

    return [
        'ok'           => true,
        'message'      => 'A new 6-digit verification code has been sent to your email.',
        'masked_email' => mask_email_address($challenge['email']),
        'expires_at'   => $expiresAt,
        'mail_result'  => $mailResult,
    ];
}

/**
 * Verifies a submitted 6-digit OTP code against the tokenized challenge.
 * Tracks failed attempts and invalidates the session upon reaching the threshold.
 *
 * @param PDO    $pdo
 * @param string $mfaToken
 * @param string $inputCode
 * @return array{ok: bool, user?: array, error?: string, remaining?: int, locked?: bool, expired?: bool, fatal?: bool}
 */
function verify_mfa_challenge(PDO $pdo, string $mfaToken, string $inputCode): array {
    ensure_mfa_table($pdo);

    $challenge = get_mfa_challenge($pdo, $mfaToken);
    if (!$challenge) {
        return [
            'ok'    => false,
            'fatal' => true,
            'error' => 'Verification session expired or invalid. Please sign in again.'
        ];
    }

    if (!empty($challenge['verified_at'])) {
        return [
            'ok'    => false,
            'fatal' => true,
            'error' => 'This verification code was already used.'
        ];
    }

    // Expiry check
    if (strtotime($challenge['expires_at']) <= time()) {
        return [
            'ok'      => false,
            'expired' => true,
            'error'   => 'The verification code has expired. Please request a new code.'
        ];
    }

    // Maximum attempts check (5 attempts per challenge)
    $attempts = (int)$challenge['attempts'];
    if ($attempts >= 5) {
        return [
            'ok'     => false,
            'locked' => true,
            'error'  => 'Too many invalid attempts. For your security, this sign-in session was closed.'
        ];
    }

    // Clean input code
    $cleanCode = preg_replace('/\D/', '', trim($inputCode));
    if (strlen($cleanCode) !== 6) {
        return [
            'ok'    => false,
            'error' => 'Please enter the complete 6-digit verification code.'
        ];
    }

    // Compare with hashed code
    if (!password_verify($cleanCode, $challenge['code_hash'])) {
        $newAttempts = $attempts + 1;
        try {
            $pdo->prepare("UPDATE login_mfa_codes SET attempts = :att WHERE id = :id")
                ->execute([':att' => $newAttempts, ':id' => $challenge['id']]);
        } catch (PDOException $e) {
            error_log('verify_mfa_challenge attempts increment failed: ' . $e->getMessage());
        }

        $remaining = max(0, 5 - $newAttempts);
        if ($remaining === 0) {
            return [
                'ok'        => false,
                'locked'    => true,
                'remaining' => 0,
                'error'     => 'Too many invalid attempts. For your security, this sign-in session was closed.'
            ];
        }

        return [
            'ok'        => false,
            'remaining' => $remaining,
            'error'     => "Incorrect verification code. {$remaining} attempt(s) remaining."
        ];
    }

    // Success: mark as verified
    try {
        $pdo->prepare("UPDATE login_mfa_codes SET verified_at = NOW() WHERE id = :id")
            ->execute([':id' => $challenge['id']]);
    } catch (PDOException $e) {
        error_log('verify_mfa_challenge mark verified failed: ' . $e->getMessage());
    }

    return [
        'ok'   => true,
        'user' => $challenge,
    ];
}
