<?php
// ─────────────────────────────────────────────
//  auth/login_process.php
//  Authenticates by username + password only.
//  Role is read from the DB after match.
// ─────────────────────────────────────────────

require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// ── Inputs ───────────────────────────────────
$username = trim($_POST['username'] ?? '');
$password = $_POST['password']      ?? '';

if ($username === '' || $password === '') {
    redirect_error('Please fill in both fields.');
}

// ── Brute-force throttle ─────────────────────
if (!empty($_SESSION['login_lockout_until']) && time() < $_SESSION['login_lockout_until']) {
    $wait = $_SESSION['login_lockout_until'] - time();
    redirect_error("Too many failed attempts. Try again in {$wait}s.");
}

// ── Query by username ─────────────────────────
try {
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT id, username, firstname, lastname, email,
                password, role, status
         FROM   users
         WHERE  username = :u
         LIMIT  1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    redirect_error('A server error occurred. Please try again.');
}

// ── Verify password ───────────────────────────
if (!$user || !password_verify($password, $user['password'])) {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_lockout_until'] = time() + 30;
        $_SESSION['login_attempts']      = 0;
        redirect_error('Too many failed attempts. Please wait 30 seconds.');
    }
    redirect_error('Incorrect username or password.');
}

// ── Check account status ──────────────────────
$status = $user['status'] ?? 'active';
if ($status === 'blocked') {
    redirect_error('Your account has been blocked. Contact your manager.');
}
if ($status === 'on_hold') {
    redirect_error('Your account is currently on hold. Contact your manager.');
}

// ── Success ───────────────────────────────────
session_regenerate_id(true);
unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);

$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['firstname'] = $user['firstname'];
$_SESSION['lastname']  = $user['lastname'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];   // primary role — kept for legacy code
$_SESSION['logged_in'] = true;

// A user can now hold multiple roles (see includes/manage_users.php's
// user_roles table). Load ALL of them into the session; fall back to the
// single legacy role if this account hasn't been assigned any yet.
$role_stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id ORDER BY role');
$role_stmt->execute([':id' => $user['id']]);
$all_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
$_SESSION['roles'] = $all_roles ?: [$user['role']];

// ── Update last_login ─────────────────────────
try {
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);
} catch (PDOException $e) {
    error_log('last_login update failed: ' . $e->getMessage());
}


// Everyone (admin/hr/finance/crew/manager) now has dashboard.view granted
// via role_permissions (see database/rbac_setup.sql), so send everybody
// to the dashboard as their landing page. The sidebar still shows each
// role only the nav links their permissions allow.
header('Location: index.php');
exit;
function redirect_error(string $msg): never {
    $_SESSION['login_error'] = $msg;
    header('Location: login.php');
    exit;
}