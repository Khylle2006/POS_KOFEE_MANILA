<?php
// ─────────────────────────────────────────────
//  auth/login_process.php
//  Authenticates by username + password.
//  CSRF-protected, with a persistent throttle.
// ─────────────────────────────────────────────

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/login_approval_helpers.php';
require_once '../includes/email_mfa.php';
require_once '../includes/login_flow.php';

secure_session_start();
send_security_headers();

function redirect_error(string $msg, string $username = ''): never {
    $_SESSION['login_error'] = $msg;
    if ($username !== '') {
        $_SESSION['login_username'] = $username;
    }
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// ── Inputs ────────────────────────────────────
$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

// ── CSRF ──────────────────────────────────────
if (!csrf_verify()) {
    redirect_error('Your session expired. Please try again.', $username);
}

if ($username === '' || $password === '' || strlen($username) > 190) {
    redirect_error('Please fill in both fields.', $username);
}

// ── Throttle (database-backed, survives cookie resets) ──
$wait = login_lockout_seconds($username);
if ($wait > 0) {
    $minutes = (int)ceil($wait / 60);
    header('Retry-After: ' . $wait);
    throw new SecurityFault('RATE_LIMITED', 'Too many sign-in attempts. Try again later.', 429);
}

// ── Look up the account ───────────────────────
try {
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT id, username, firstname, lastname, email, password, role, status, avatar_path
           FROM users
          WHERE username = :u
          LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Login error: ' . 'Service temporarily unavailable.');
    redirect_error('A server error occurred. Please try again.', $username);
}

// ── Verify ────────────────────────────────────
// A dummy hash is verified when the user does not exist, so the
// response time does not reveal which usernames are valid.
if (!verify_login_password($password, $user['password'] ?? null) || !$user) {
    record_login_attempt($username, false);
    redirect_error('Incorrect username or password.', $username);
}

// ── Account status ────────────────────
$status = $user['status'] ?? '';
if ($status !== 'active') { record_login_attempt($username, false); redirect_error('Your account is not active. Contact your manager.', $username); }
$context = array_intersect_key($_POST, array_flip(['latitude', 'longitude', 'accuracy', 'location_status', 'device_info']));
// Remove any previous authentication or approval state before starting a new sign-in.
$_SESSION = [];
session_regenerate_id(true);
if (email_mfa_enabled($pdo, (int)$user['id'])) {
    try {
        start_email_mfa($pdo, (int)$user['id'], 'login', $context);
    } catch (SecurityFault $exception) {
        redirect_error($exception->getMessage(), $username);
    }
    header('Location: mfa_verify.php');
    exit;
}
complete_password_login($pdo, $user, $context);
