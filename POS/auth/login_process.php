<?php
// ─────────────────────────────────────────────
//  auth/login_process.php
//  Authenticates by username + password only.
//  Role is read from the DB after match.
// ─────────────────────────────────────────────

require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

// ── Inputs ───────────────────────────────────
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

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
    $pdo = get_db();
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
        $_SESSION['login_attempts'] = 0;
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

$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['firstname'] = $user['firstname'] ?? '';
$_SESSION['lastname'] = $user['lastname'] ?? '';
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role']; // 'staff' or 'admin' — from DB
$_SESSION['logged_in'] = true;

// ── Update last_login ─────────────────────────
try {
    // Check if column exists (lastlogin vs last_login)
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
        ->execute([':id' => $user['id']]);
} catch (PDOException $e) {
    // Try alternative column name
    try {
        $pdo->prepare('UPDATE users SET lastlogin = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);
    } catch (PDOException $e2) {
        error_log('last_login update failed: ' . $e2->getMessage());
    }
}

// ── DEBUG: Check session after login ─────────
error_log('Login successful! User: ' . $user['username'] . ', Role: ' . $user['role']);
error_log('Session data: ' . print_r($_SESSION, true));

// ── Redirect based on role from DB ───────────
// Use RELATIVE paths instead of absolute
if ($user['role'] === 'admin' || $user['role'] === 'manager') {
    // Check if admin dashboard exists
    if (file_exists('../admin/dashboard.php')) {
        $destination = '../admin/dashboard.php';
    } else {
        // Fallback
        $destination = '../index.php';
    }
} else {
    // Staff
    if (file_exists('../staff/home.php')) {
        $destination = '../staff/home.php';
    } else {
        // Fallback
        $destination = '../index.php';
    }
}

header('Location: ' . $destination);
exit;

// ── Helper ────────────────────────────────────
function redirect_error(string $msg): never {
    $_SESSION['login_error'] = $msg;
    header('Location: ../login.php');
    exit;
}
?>