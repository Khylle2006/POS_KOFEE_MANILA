<?php
declare(strict_types=1);
require_once __DIR__ . '/account_security.php';

function email_mfa_available(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT 1 FROM app_migrations WHERE version = '20261010_email_mfa_v1'")->fetchColumn();
}

function email_mfa_enabled(PDO $pdo, int $userId): bool
{
    if (!email_mfa_available($pdo)) return false;
    $stmt = $pdo->prepare('SELECT enabled FROM user_email_mfa WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

/** @return array<string, mixed> */
function email_mfa_user(PDO $pdo, int $userId, bool $lock = false): array
{
    $sql = $lock
        ? 'SELECT id, username, firstname, lastname, email, password, role, status, avatar_path FROM users WHERE id = ? FOR UPDATE'
        : 'SELECT id, username, firstname, lastname, email, password, role, status, avatar_path FROM users WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active') {
        throw new SecurityFault('MFA_ACCOUNT_UNAVAILABLE', 'Please sign in again.', 401);
    }
    return $user;
}

function email_mfa_send_code(string $email, string $code): void
{
    require_once __DIR__ . '/mailer.php';
    $config = get_kofee_smtp_config();
    if (!$config['configured']) {
        throw new SecurityFault('MFA_EMAIL_UNAVAILABLE', 'Email verification is unavailable. Please contact your administrator.', 503);
    }
    // Short-lived codes are sent immediately rather than waiting for the email worker.
    $result = send_kofee_email($email, '', 'BrewVanti verification code',
        '<h1>Your verification code</h1><p><strong>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p><p>This code expires in 10 minutes. If you did not request it, ignore this email.</p>',
        'Your verification code is ' . $code . '. It expires in 10 minutes.', $config);
    if (empty($result['sent'])) {
        throw new SecurityFault('MFA_EMAIL_UNAVAILABLE', 'The verification email could not be sent. Please try again later.', 503);
    }
}

/** @param array<string, mixed> $user @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function new_email_mfa_challenge(array $user, string $purpose, array $context = [], ?callable $sender = null, ?int $now = null): array
{
    if (!in_array($purpose, ['login', 'enroll'], true)) throw new LogicException('Invalid MFA purpose.');
    $email = (string)($user['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new SecurityFault('MFA_EMAIL_INVALID', 'Save a valid email address in your profile before enabling email MFA.');
    }
    $code = (string)random_int(100000, 999999);
    ($sender ?? 'email_mfa_send_code')($email, $code);
    return ['user_id' => (int)$user['id'], 'purpose' => $purpose, 'email' => $email,
        'password_fingerprint' => hash('sha256', (string)$user['password']),
        'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'expires_at' => ($now ?? time()) + 600,
        'sent_at' => $now ?? time(), 'attempts' => 0, 'context' => $context];
}

/** @param array<string, mixed> $challenge @param array<string, mixed> $user */
function verify_email_mfa_challenge(array &$challenge, array $user, string $purpose, string $code, ?int $now = null): void
{
    if (($challenge['purpose'] ?? '') !== $purpose || (int)($challenge['user_id'] ?? 0) !== (int)$user['id']
        || ($challenge['email'] ?? '') !== $user['email'] || $user['status'] !== 'active'
        || !hash_equals((string)($challenge['password_fingerprint'] ?? ''), hash('sha256', (string)$user['password']))
        || (int)($challenge['expires_at'] ?? 0) <= ($now ?? time()) || (int)($challenge['attempts'] ?? 0) >= 5) {
        $challenge = [];
        throw new SecurityFault('MFA_CODE_EXPIRED', 'This verification expired. Request a new code.', 409);
    }
    $challenge['attempts'] = (int)$challenge['attempts'] + 1;
    if (!preg_match('/^\d{6}$/D', $code) || !password_verify($code, (string)$challenge['code_hash'])) {
        if ($challenge['attempts'] >= 5) $challenge = [];
        throw new SecurityFault('MFA_CODE_INVALID', 'The verification code is incorrect.', 422);
    }
    // Consuming the session-bound challenge prevents replay and cross-purpose reuse.
    $challenge = [];
}

/** @param array<string, mixed> $context */
function start_email_mfa(PDO $pdo, int $userId, string $purpose, array $context = []): void
{
    $previous = $_SESSION['email_mfa_' . $purpose] ?? [];
    if ((int)($previous['sent_at'] ?? 0) > time() - 60) {
        throw new SecurityFault('MFA_RESEND_WAIT', 'Wait one minute before requesting another code.', 429);
    }
    rate_limit('email-mfa-send-' . $purpose, (string)$userId, 3, 600);
    $_SESSION['email_mfa_' . $purpose] = new_email_mfa_challenge(email_mfa_user($pdo, $userId), $purpose, $context);
}

/** @param array<string, mixed> $challenge */
function enable_email_mfa(PDO $pdo, int $userId, array &$challenge, string $code): void
{
    $pdo->beginTransaction();
    try {
        $user = email_mfa_user($pdo, $userId, true);
        verify_email_mfa_challenge($challenge, $user, 'enroll', $code);
        $pdo->prepare('INSERT INTO user_email_mfa (user_id, enabled, verified_email) VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE enabled = 1, verified_email = VALUES(verified_email)')->execute([$userId, $user['email']]);
        security_audit($pdo, 'email_mfa_enabled', 'user', $userId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function disable_email_mfa(PDO $pdo, int $userId, string $password): void
{
    $pdo->beginTransaction();
    try {
        $user = email_mfa_user($pdo, $userId, true);
        if (!verify_login_password($password, (string)$user['password'])) {
            throw new SecurityFault('PASSWORD_INVALID', 'Your current password is incorrect.');
        }
        $pdo->prepare('UPDATE user_email_mfa SET enabled = 0, verified_email = NULL WHERE user_id = ?')->execute([$userId]);
        security_audit($pdo, 'email_mfa_disabled', 'user', $userId);
        $pdo->commit();
        unset($_SESSION['email_mfa_enroll']);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
