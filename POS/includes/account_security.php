<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

function register_auth_session(PDO $pdo, int $userId): void
{
    $handle = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_sessions (token_hash, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 12 HOUR))')
        ->execute([hash('sha256', $handle), $userId]);
    $_SESSION['auth_handle'] = $handle;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    security_audit($pdo, 'login', 'user', $userId);
}

function revoke_user_sessions(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$userId]);
    $pdo->prepare('DELETE FROM auth_devices WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare("UPDATE login_authorizations SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending','approved') AND session_created = 0")
        ->execute([$userId]);
    security_audit($pdo, 'sessions_revoked', 'user', $userId);
}

function validate_auth_session(PDO $pdo): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['auth_handle'])) {
        throw new SecurityFault('AUTHENTICATION_REQUIRED', 'Please sign in again.', 401);
    }
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > 1800
        || time() - (int)($_SESSION['authenticated_at'] ?? 0) > 43200) {
        $_SESSION = [];
        throw new SecurityFault('SESSION_EXPIRED', 'Please sign in again.', 401);
    }
    $stmt = $pdo->prepare('SELECT u.status FROM auth_sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.user_id = ? AND s.revoked_at IS NULL AND s.expires_at > NOW()');
    $stmt->execute([hash('sha256', (string)$_SESSION['auth_handle']), (int)$_SESSION['user_id']]);
    if ($stmt->fetchColumn() !== 'active') {
        $_SESSION = [];
        throw new SecurityFault('SESSION_REVOKED', 'Please sign in again.', 401);
    }
    $_SESSION['last_activity'] = time();
}

/** Recover old or invalid sessions on the sign-in page without hiding service failures. */
function resume_auth_session(PDO $pdo): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    require_runtime_schema($pdo);
    try {
        validate_auth_session($pdo);
        return true;
    } catch (SecurityFault $exception) {
        if ($exception->status !== 401) {
            throw $exception;
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return false;
    }
}

function require_recent_password(): void
{
    if (time() - (int)($_SESSION['reauthenticated_at'] ?? 0) > 300) {
        throw new SecurityFault('REAUTHENTICATION_REQUIRED', 'Confirm your password to continue.', 428);
    }
}

function require_admin_account_management(PDO $pdo, int $userId, bool $actorIsAdmin): void
{
    if ($actorIsAdmin) return;
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' UNION SELECT 1 FROM user_roles WHERE user_id = ? AND role = 'admin'");
    $stmt->execute([$userId, $userId]);
    if ($stmt->fetchColumn()) {
        throw new SecurityFault('PERMISSION_DENIED', 'Only system administrators can modify administrator accounts.', 403);
    }
}

function consume_password_reset(PDO $pdo, string $token, string $password): int
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || ($error = new_password_error($password)) !== null) {
        throw new SecurityFault('RESET_INVALID', $error ?? 'Reset link is invalid.');
    }
    $digest = hash('sha256', $token);
    $lookup = $pdo->prepare('SELECT user_id FROM password_resets WHERE token = ? LIMIT 1');
    $lookup->execute([$digest]);
    $userId = (int)$lookup->fetchColumn();
    $pdo->beginTransaction();
    try {
        // Issuance, consumption, and account deactivation lock the user first.
        $lock = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' FOR UPDATE");
        $lock->execute([$userId]);
        if (!$lock->fetchColumn()) throw new SecurityFault('RESET_EXPIRED', 'Reset link is invalid or expired.', 409);
        $lock = $pdo->prepare('SELECT id FROM password_resets WHERE user_id = ? AND token = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        $lock->execute([$userId, $digest]);
        if (!$lock->fetchColumn()) throw new SecurityFault('RESET_EXPIRED', 'Reset link is expired or already used.', 409);
        $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $userId]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        revoke_user_sessions($pdo, $userId);
        security_audit($pdo, 'password_reset', 'user', $userId);
        $pdo->commit();
        return $userId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function rate_limit(string $scope, string $identity, int $maximum, int $seconds): void
{
    $pdo = get_db();
    $key = hash('sha256', $scope . '|' . $identity);
    // An upsert plus a row lock makes the limit shared by concurrent requests.
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO security_rate_limits (bucket_key, window_start, attempts) VALUES (?, NOW(), 0)')->execute([$key]);
        $stmt = $pdo->prepare('SELECT UNIX_TIMESTAMP(window_start) AS started, attempts FROM security_rate_limits WHERE bucket_key = ? FOR UPDATE');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $remaining = max(0, $seconds - (time() - (int)$row['started']));
        if ($remaining > 0 && (int)$row['attempts'] >= $maximum) {
            if ($own) {
                $pdo->commit();
            }
            header('Retry-After: ' . $remaining);
            throw new SecurityFault('RATE_LIMITED', 'Too many requests. Please try again later.', 429);
        }
        if ($remaining === 0) {
            $pdo->prepare('UPDATE security_rate_limits SET attempts = 1, window_start = NOW() WHERE bucket_key = ?')->execute([$key]);
        } else {
            $pdo->prepare('UPDATE security_rate_limits SET attempts = attempts + 1 WHERE bucket_key = ?')->execute([$key]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function trusted_device(PDO $pdo, int $userId): bool
{
    $token = $_COOKIE['kofee_device'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM auth_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()');
    $stmt->execute([$userId, hash('sha256', $token)]);
    return (bool)$stmt->fetchColumn();
}

function issue_trusted_device(PDO $pdo, int $userId, int $approvedBy): void
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_devices (user_id, token_hash, approved_by, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([$userId, hash('sha256', $token), $approvedBy]);
    setcookie('kofee_device', $token, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => app_production() || app_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function require_pending_approval(string $token): void
{
    $expected = $_SESSION['pending_auth_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        throw new SecurityFault('APPROVAL_INVALID', 'This login approval belongs to another browser session.', 403);
    }
}

/** Consume approval and establish a session under one row lock. */
function consume_login_approval(PDO $pdo, string $token): bool
{
    require_pending_approval($token);
    $pdo->beginTransaction();
    try {
        // Revocation and reset flows lock the user before cancelling their approvals.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([(int)($_SESSION['pending_auth_user_id'] ?? 0)]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->rollBack();
            return false;
        }
        $stmt = $pdo->prepare('SELECT * FROM login_authorizations WHERE auth_token = ? FOR UPDATE');
        $stmt->execute([$token]);
        $approval = $stmt->fetch();
        if (!$approval || $approval['status'] !== 'approved' || (int)$approval['session_created'] !== 0
            || strtotime($approval['expires_at']) <= time() || (int)$approval['user_id'] !== (int)($_SESSION['pending_auth_user_id'] ?? 0)) {
            $pdo->rollBack();
            return false;
        }
        establish_user_session($pdo, $user);
        issue_trusted_device($pdo, (int)$user['id'], (int)$approval['approved_by']);
        $pdo->prepare('UPDATE login_authorizations SET session_created = 1 WHERE id = ?')->execute([$approval['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION = [];
        throw $exception;
    }
}
