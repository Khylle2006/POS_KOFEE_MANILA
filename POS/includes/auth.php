<?php
// ============================================
// FILE: includes/auth.php
// ============================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

secure_session_start();

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

/**
 * Ensure an active employee profile is linked to the given user account.
 * If missing, automatically provisions an active employee profile.
 */
function get_or_create_user_employee(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;

    try {
        $stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $emp = $stmt->fetch();
        if ($emp) return $emp;

        // Fetch user account info
        $uStmt = $pdo->prepare('SELECT * FROM users WHERE id = :uid LIMIT 1');
        $uStmt->execute([':uid' => $userId]);
        $u = $uStmt->fetch();
        if (!$u) return null;

        $role = strtolower(trim($u['role'] ?? ''));
        if ($role === 'supplier') return null;

        // Generate a clean employee code like EMP-0016
        $code = 'EMP-' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :c');
        $chk->execute([':c' => $code]);
        if ($chk->fetch()) {
            $code .= '-' . time();
        }

        $fname = !empty($u['firstname']) ? $u['firstname'] : $u['username'];
        $lname = !empty($u['lastname']) ? $u['lastname'] : 'Staff';
        $pos = ucfirst($role ?: 'Staff');
        $email = !empty($u['email']) ? $u['email'] : null;

        $ins = $pdo->prepare("
            INSERT INTO employees (user_id, employee_code, firstname, lastname, position, department, email, hire_date, employment_type, status)
            VALUES (:uid, :code, :fn, :ln, :pos, 'Operations', :email, CURDATE(), 'Full-time', 'active')
        ");
        $ins->execute([
            ':uid' => $userId,
            ':code' => $code,
            ':fn' => $fname,
            ':ln' => $lname,
            ':pos' => $pos,
            ':email' => $email
        ]);

        $newId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM employees WHERE id = :id');
        $st->execute([':id' => $newId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('get_or_create_user_employee failed: ' . $e->getMessage());
        return null;
    }
}

/** Return whether a staff member has started today's shift. Admin and supplier are exempt. */
function user_is_clocked_in(): bool {
    if (empty($_SESSION['user_id'])) return false;

    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $roles = array_map('strtolower', $_SESSION['roles'] ?? []);
    if ($role === 'admin' || in_array('admin', $roles, true) || $role === 'supplier' || in_array('supplier', $roles, true)) {
        return true;
    }

    try {
        $pdo = get_db();
        $employee = get_or_create_user_employee($pdo, (int)$_SESSION['user_id']);
        $employeeId = (int)($employee['id'] ?? 0);
        if (!$employeeId) return false;

        $attendance = $pdo->prepare(
            "SELECT 1 FROM attendance
             WHERE employee_id = :employee_id
               AND (
                   attendance_date = CURDATE()
                   OR (attendance_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at >= NOW() - INTERVAL 18 HOUR)
               )
               AND time_in IS NOT NULL
               AND time_out IS NULL
               AND status IN ('present', 'late', 'half_day')
             ORDER BY id DESC
             LIMIT 1"
        );
        $attendance->execute([':employee_id' => $employeeId]);
        return (bool)$attendance->fetchColumn();
    } catch (Throwable $e) {
        error_log('Clock-in check failed: ' . $e->getMessage());
        return false;
    }
}

/** Require crew to clock in before accessing POS order workflows. */
function require_clocked_in_for_pos(bool $json = false): void {
    if (user_is_clocked_in()) return;

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Clock in first before accessing the POS system.',
            'redirect' => '../php/employee_dashboard.php?reason=clock_in_required',
        ]);
        exit;
    }

    header('Location: ../php/employee_dashboard.php?reason=clock_in_required');
    exit;
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
//  PERMISSION CHECK (WITHOUT REDIRECT)
// ═══════════════════════════════════════════════

// ═══════════════════════════════════════════════
//  USER HELPERS
// ═══════════════════════════════════════════════

function current_user(): array {
    $roles = (!empty($_SESSION['roles']) && is_array($_SESSION['roles']))
        ? $_SESSION['roles']
        : (!empty($_SESSION['role']) ? [$_SESSION['role']] : []);

    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'firstname' => $_SESSION['firstname'] ?? '',
        'lastname'  => $_SESSION['lastname']  ?? '',
        'email'     => $_SESSION['email']     ?? '',
        'role'      => $_SESSION['role']      ?? 'staff', // primary role — legacy code
        'roles'     => $roles,                            // ALL roles this account holds
        'status'    => $_SESSION['status']    ?? 'active',
        'name'      => trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')),
    ];
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function is_admin(): bool {
    $roles = (!empty($_SESSION['roles']) && is_array($_SESSION['roles']))
        ? $_SESSION['roles']
        : [$_SESSION['role'] ?? ''];
    return in_array('admin', $roles, true);
}

function is_admin_or_manager(): bool {
    $roles = (!empty($_SESSION['roles']) && is_array($_SESSION['roles']))
        ? $_SESSION['roles']
        : [$_SESSION['role'] ?? ''];
    return (bool)array_intersect(['admin', 'manager'], $roles);
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