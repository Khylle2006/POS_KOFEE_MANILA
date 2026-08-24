<?php
// ============================================
// FILE: includes/auth.php (COMPLETE VERSION)
// Replace your entire auth.php with this
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';


// ═══════════════════════════════════════════════
//  LOGIN FUNCTION - THIS WAS MISSING!
// ═══════════════════════════════════════════════

/**
 * Authenticate a user and set session data
 * @param string $username
 * @param string $password
 * @return array{ok: bool, error?: string, user?: array}
 */
function login_user(string $username, string $password): array {
    try {
        $pdo = get_db();
        
        // Get user with role
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_key as role_name 
            FROM users u
            LEFT JOIN roles r ON u.role = r.role_key
            WHERE u.username = :username AND u.status = 'active'
        ");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['ok' => false, 'error' => 'Invalid username or password'];
        }
        
        // Verify password (adjust based on your password hashing)
        if (!password_verify($password, $user['password'])) {
            return ['ok' => false, 'error' => 'Invalid username or password'];
        }
        
        // 🔥 CRITICAL: Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['firstname'] = $user['firstname'] ?? '';
        $_SESSION['lastname'] = $user['lastname'] ?? '';
        $_SESSION['email'] = $user['email'] ?? '';
        $_SESSION['role'] = $user['role'] ?? 'staff'; // ← THIS IS CRUCIAL!
        $_SESSION['status'] = $user['status'] ?? 'active';
        
        // Debug: Log successful login
        error_log("User logged in: {$user['username']} (Role: {$_SESSION['role']})");
        
        return [
            'ok' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $_SESSION['role'],
                'firstname' => $_SESSION['firstname'],
                'lastname' => $_SESSION['lastname'],
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return ['ok' => false, 'error' => 'Login failed. Please try again.'];
    }
}

/**
 * Logout user - clear session
 */
function logout_user(): void {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

// ═══════════════════════════════════════════════
//  LOGIN CHECK
// ═══════════════════════════════════════════════

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ../auth/login.php?reason=unauthenticated');
        exit;
    }

    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $status = $stmt->fetchColumn();

        if ($status === 'terminated') {
            session_destroy();
            header('Location: ../auth/login.php?reason=terminated');
            exit;
        }
    } catch (Exception $e) {
        session_destroy();
        header('Location: ../auth/login.php?reason=error');
        exit;
    }
}

// ═══════════════════════════════════════════════
//  ROLE CHECK (simple, for quick role gating)
// ═══════════════════════════════════════════════

function require_role(string ...$roles): void {
    require_login();
    
    $user_role = $_SESSION['role'] ?? '';
    
    if (!in_array($user_role, $roles, true)) {
        $dest = in_array($user_role, ['admin', 'manager']) 
            ? '../php/dashboard.php' 
            : '../php/menu.php';
        header('Location: ' . $dest . '?reason=forbidden');
        exit;
    }
}

// ═══════════════════════════════════════════════
//  PERMISSION CHECK - DEFINED IN permissions.php
// ═══════════════════════════════════════════════
//
//  has_permission($perm_key)     — Check if user has permission (no redirect)
//  require_permission($perm_key) — Check and redirect if denied
//
//  These are defined in includes/permissions.php and included automatically.
// ═══════════════════════════════════════════════

// ═══════════════════════════════════════════════
//  USER HELPERS
// ═══════════════════════════════════════════════

function current_user(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'firstname' => $_SESSION['firstname'] ?? '',
        'lastname'  => $_SESSION['lastname']  ?? '',
        'email'     => $_SESSION['email']     ?? '',
        'role'      => $_SESSION['role']      ?? 'staff',
        'status'    => $_SESSION['status']    ?? 'active',
        'name'      => trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')),
    ];
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_admin_or_manager(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
}

function get_dashboard_url(): string {
    return is_admin_or_manager() ? '../php/dashboard.php' : '../php/menu.php';
}

// ═══════════════════════════════════════════════
//  REDIRECT HELPERS
// ═══════════════════════════════════════════════

function redirect_to_login(string $reason = ''): void {
    $url = '../auth/login.php';
    if ($reason) {
        $url .= '?reason=' . urlencode($reason);
    }
    header('Location: ' . $url);
    exit;
}

function redirect_to_dashboard(): void {
    header('Location: ' . get_dashboard_url());
    exit;
}

// ═══════════════════════════════════════════════
//  SESSION REFRESH - Fix role if missing
// ═══════════════════════════════════════════════

/**
 * Refresh session data from database
 * Useful if role or user data changes
 */
function refresh_session(): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_key as role_name 
            FROM users u
            LEFT JOIN roles r ON u.role = r.role_key
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['role'] = $user['role'] ?? 'staff';
            $_SESSION['username'] = $user['username'];
            $_SESSION['firstname'] = $user['firstname'] ?? '';
            $_SESSION['lastname'] = $user['lastname'] ?? '';
            $_SESSION['email'] = $user['email'] ?? '';
            $_SESSION['status'] = $user['status'] ?? 'active';
        }
    } catch (Exception $e) {
        // Silent fail
    }
}
?>