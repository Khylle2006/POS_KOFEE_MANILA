<?php
// ─────────────────────────────────────────────
//  auth/login_process.php
//  Authenticates by username + password.
//  CSRF-protected, with a persistent throttle.
// ─────────────────────────────────────────────

require_once '../includes/db.php';
require_once '../includes/security.php';

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
        'SELECT id, username, firstname, lastname, email, password, role, status
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

// ── Success ───────────────────────────────────
record_login_attempt($username, true);

session_regenerate_id(true);       // defeats session fixation
$_SESSION = [];                    // start from a clean slate
$_SESSION['_created'] = time();

$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['firstname'] = $user['firstname'];
$_SESSION['lastname']  = $user['lastname'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];
$_SESSION['logged_in'] = true;

// Multi-role support; fall back to the legacy single role.
try {
    $role_stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id ORDER BY role');
    $role_stmt->execute([':id' => $user['id']]);
    $all_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['roles'] = $all_roles ?: [$user['role']];
} catch (PDOException $e) {
    error_log('role load failed: ' . $e->getMessage());
    $_SESSION['roles'] = [$user['role']];
}

// Permissions are cached with a timestamp so a revoke takes
// effect within a minute instead of at next login.
$_SESSION['permissions']        = null;
$_SESSION['permissions_loaded'] = 0;

try {
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);
} catch (PDOException $e) {
    error_log('last_login update failed: ' . $e->getMessage());
}

header('Location: index.php');
exit;