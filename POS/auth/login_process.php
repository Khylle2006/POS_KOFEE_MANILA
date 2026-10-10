<?php
// ─────────────────────────────────────────────
//  auth/login_process.php
//  Authenticates by username + password.
//  CSRF-protected, with a persistent throttle.
// ─────────────────────────────────────────────

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/login_approval_helpers.php';
require_once '../includes/mfa_helpers.php';

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
$username = trim($_POST['username'] ?? '');
$password = $_POST['password']      ?? '';

// ── CSRF ──────────────────────────────────────
if (!csrf_verify()) {
    redirect_error('Your session expired. Please try again.', $username);
}

if ($username === '' || $password === '') {
    redirect_error('Please fill in both fields.', $username);
}

// ── Throttle (database-backed, survives cookie resets) ──
$wait = login_lockout_seconds($username);
if ($wait > 0) {
    $minutes = (int)ceil($wait / 60);
    redirect_error("Too many failed attempts. Try again in {$minutes} minute(s).", $username);
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
    error_log('Login error: ' . $e->getMessage());
    redirect_error('A server error occurred. Please try again.', $username);
}

// ── Verify ────────────────────────────────────
// A dummy hash is verified when the user does not exist, so the
// response time does not reveal which usernames are valid.
$stored = $user['password']
       ?? '$2y$10$usesomesillystringforsalttoavoidtimingleaksxxxxxxxxxxxxxxxxx';

if (!password_verify($password, $stored) || !$user) {
    record_login_attempt($username, false);
    redirect_error('Incorrect username or password.', $username);
}

// ── Account status ────────────────────
$status = $user['status'] ?? 'active';
if ($status === 'blocked') {
    record_login_attempt($username, false);
    redirect_error('Your account has been blocked. Contact your manager.', $username);
}
if ($status === 'on_hold') {
    record_login_attempt($username, false);
    redirect_error('Your account is currently on hold. Contact your manager.', $username);
}

// ── Step 2: Multi-Factor Authentication (MFA) Email Code Verification ──
// Password credentials are validated. Initiate MFA challenge with a tokenized 6-digit code.
$mfaRes = create_mfa_challenge($pdo, $user);
if (!$mfaRes['ok']) {
    redirect_error($mfaRes['error'] ?? 'Could not initialize MFA verification. Please try again.', $username);
}

// Preserve client-provided geolocation & device info across the MFA verification step
$lat = (isset($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : null;
$lon = (isset($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : null;
$acc = (isset($_POST['accuracy']) && is_numeric($_POST['accuracy'])) ? (float)$_POST['accuracy'] : null;
$locStatus = trim($_POST['location_status'] ?? 'unknown');
$deviceInfo = trim($_POST['device_info'] ?? '');

$_SESSION['pending_mfa'] = [
    'user_id'         => (int)$user['id'],
    'username'        => $user['username'],
    'mfa_token'       => $mfaRes['mfa_token'],
    'latitude'        => $lat,
    'longitude'       => $lon,
    'accuracy'        => $acc,
    'location_status' => $locStatus,
    'device_info'     => $deviceInfo,
    'initiated_at'    => time(),
];

header('Location: mfa_verify.php?token=' . urlencode($mfaRes['mfa_token']));
exit;