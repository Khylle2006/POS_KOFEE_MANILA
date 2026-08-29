<?php
// ─────────────────────────────────────────────
//  includes/permissions.php
//  RBAC helpers: roles + permissions.
//  Include this AFTER includes/db.php and includes/auth.php.
// ─────────────────────────────────────────────

require_once __DIR__ . '/db.php';

// ═══════════════════════════════════════════════
//  ROLES
// ═══════════════════════════════════════════════

/** All roles, ordered admin-first then alphabetically. */
function get_all_roles(): array {
    $pdo = get_db();
    return $pdo->query("
        SELECT role_key, label, is_system
        FROM roles
        ORDER BY is_system DESC, label ASC
    ")->fetchAll();
}

function role_exists(string $role_key): bool {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT 1 FROM roles WHERE role_key = :r');
    $stmt->execute([':r' => $role_key]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Create a new role.
 * @return array{ok: bool, error?: string}
 */
function create_role(string $role_key, string $label): array {
    $role_key = strtolower(trim($role_key));
    $label    = trim($label);

    if ($role_key === '' || $label === '') {
        return ['ok' => false, 'error' => 'Role key and label are required.'];
    }
    if (!preg_match('/^[a-z0-9_]{2,30}$/', $role_key)) {
        return ['ok' => false, 'error' => 'Role key must be lowercase letters, numbers, or underscores only.'];
    }
    if (role_exists($role_key)) {
        return ['ok' => false, 'error' => 'That role already exists.'];
    }

    $pdo = get_db();
    $pdo->prepare('INSERT INTO roles (role_key, label, is_system) VALUES (:k, :l, 0)')
        ->execute([':k' => $role_key, ':l' => $label]);

    return ['ok' => true];
}

/**
 * Delete a role. Refuses to delete system roles (admin) or roles still
 * assigned to existing users, so nobody gets silently orphaned.
 * @return array{ok: bool, error?: string}
 */
function delete_role(string $role_key): array {
    $pdo = get_db();

    $stmt = $pdo->prepare('SELECT is_system FROM roles WHERE role_key = :r');
    $stmt->execute([':r' => $role_key]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'Role not found.'];
    }
    if ((int)$row['is_system'] === 1) {
        return ['ok' => false, 'error' => 'This is a system role and cannot be deleted.'];
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :r');
    $inUse->execute([':r' => $role_key]);
    $count = (int)$inUse->fetchColumn();

    if ($count > 0) {
        return ['ok' => false, 'error' => "$count user(s) still have this role. Reassign them first."];
    }

    // role_permissions rows cascade-delete automatically (FK ON DELETE CASCADE)
    $pdo->prepare('DELETE FROM roles WHERE role_key = :r')->execute([':r' => $role_key]);

    return ['ok' => true];
}

// ═══════════════════════════════════════════════
//  PERMISSIONS
// ═══════════════════════════════════════════════

/** All permissions, grouped by category. */
function get_all_permissions(): array {
    $pdo = get_db();
    return $pdo->query("
        SELECT perm_key, label, category, description
        FROM permissions
        ORDER BY category ASC, label ASC
    ")->fetchAll();
}

/** perm_keys granted to a given role. */
function get_role_permissions(string $role): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT perm_key FROM role_permissions WHERE role = :r');
    $stmt->execute([':r' => $role]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Every role => [perm_key, ...] in one query (used by the matrix UI). */
function get_all_role_permissions(): array {
    $pdo  = get_db();
    $rows = $pdo->query('SELECT role, perm_key FROM role_permissions')->fetchAll();
    $map  = [];
    foreach ($rows as $r) {
        $map[$r['role']][] = $r['perm_key'];
    }
    return $map;
}

/**
 * Grant or revoke a single permission for a role.
 *
 * FIXED: previously used INSERT IGNORE, which silently does nothing
 * (no error, no insert) if the table lacks a UNIQUE KEY on
 * (role, perm_key) — this made grants look successful in the UI while
 * never actually reaching the database in some setups. Now uses
 * ON DUPLICATE KEY UPDATE (requires that unique key — see the
 * ALTER TABLE note below) and surfaces real DB errors instead of
 * swallowing them.
 *
 * REQUIRED ONE-TIME MIGRATION (run once in phpMyAdmin, harmless if
 * a matching key already exists):
 *   ALTER TABLE role_permissions
 *     ADD UNIQUE KEY uniq_role_perm (role, perm_key);
 *
 * @return array{ok: bool, error?: string}
 */
function set_role_permission(string $role, string $perm_key, bool $granted): array {
    if ($role === 'admin') {
        return ['ok' => false, 'error' => 'Admin always has full access and cannot be edited.'];
    }
    if (!role_exists($role)) {
        return ['ok' => false, 'error' => "Unknown role: '$role'"];
    }

    $pdo = get_db();
    $chk = $pdo->prepare('SELECT 1 FROM permissions WHERE perm_key = :p');
    $chk->execute([':p' => $perm_key]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'error' => "Unknown permission: '$perm_key'"];
    }

    try {
        if ($granted) {
            $stmt = $pdo->prepare(
                'INSERT INTO role_permissions (role, perm_key) VALUES (:r, :p)
                 ON DUPLICATE KEY UPDATE role = role'
            );
            $stmt->execute([':r' => $role, ':p' => $perm_key]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM role_permissions WHERE role = :r AND perm_key = :p');
            $stmt->execute([':r' => $role, ':p' => $perm_key]);
        }
    } catch (PDOException $e) {
        error_log("set_role_permission failed for role={$role} perm={$perm_key}: " . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }

    return ['ok' => true];
}

// ═══════════════════════════════════════════════
//  PERMISSION CHECK - THE FIXED VERSION
//  COMPATIBLE WITH PHP 7.0+
// ═══════════════════════════════════════════════

/**
 * Does the CURRENT logged-in user have this permission?
 * Admin always returns true — it's a hardcoded bypass so admin
 * can never lock itself out while editing permissions.
 */
function has_permission(string $perm_key): bool {
    static $cache = array();

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $roles = [];
    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        $roles = $_SESSION['roles'];
    } elseif (!empty($_SESSION['role'])) {
        $roles = [$_SESSION['role']];
    }

    if (empty($roles)) {
        $user_id = $_SESSION['user_id'];
        try {
            $pdo = get_db();

            $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $db_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($db_roles) {
                $roles = $db_roles;
            } else {
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $single = $stmt->fetchColumn();
                if ($single) $roles = [$single];
            }

            if ($roles) {
                $_SESSION['roles'] = $roles;
                $_SESSION['role']  = $roles[0];
            } else {
                error_log("User {$user_id} has no role assigned");
                return false;
            }
        } catch (Exception $e) {
            error_log("Error fetching user roles: " . $e->getMessage());
            return false;
        }
    }

    if (in_array('admin', $roles, true)) {
        return true;
    }

    foreach ($roles as $role) {
        if (!isset($cache[$role])) {
            try {
                $cache[$role] = get_role_permissions($role);
            } catch (Exception $e) {
                error_log("Error fetching role permissions for '{$role}': " . $e->getMessage());
                continue;
            }
        }
        if (in_array($perm_key, $cache[$role], true)) {
            return true;
        }
    }

    $username = $_SESSION['username'] ?? 'unknown';
    error_log("Permission denied: User '{$username}' (roles: " . implode(',', $roles) . ") needs '{$perm_key}'");
    return false;
}

/**
 * Redirect away if the current user lacks a permission.
 */
function require_permission(string $perm_key): void {
    if (!isset($_SESSION['user_id'])) {
        error_log("require_permission: User not logged in, redirecting to login");
        header('Location: ../auth/login.php');
        exit;
    }

    if (!has_permission($perm_key)) {
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown';
        error_log("require_permission: User {$_SESSION['user_id']} ('{$username}') denied for '{$perm_key}'");

        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        header('Location: ../auth/login.php?reason=forbidden');
        exit;
    }
}

// ═══════════════════════════════════════════════
//  HELPER: Get user's role
// ═══════════════════════════════════════════════

function get_current_user_role(): string {
    if (!isset($_SESSION['user_id'])) {
        return '';
    }

    if (isset($_SESSION['role']) && !empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }

    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute(array($_SESSION['user_id']));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['role'])) {
            $_SESSION['role'] = $user['role'];
            return $user['role'];
        }
    } catch (Exception $e) {
        error_log("Error getting user role: " . $e->getMessage());
    }

    return '';
}

// ═══════════════════════════════════════════════
//  HELPER: Get all permissions for current user
// ═══════════════════════════════════════════════

function get_current_user_permissions(): array {
    $role = get_current_user_role();
    if (empty($role)) {
        return array();
    }

    if ($role === 'admin') {
        try {
            $pdo = get_db();
            $perms = $pdo->query("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
            return $perms;
        } catch (Exception $e) {
            return array();
        }
    }

    return get_role_permissions($role);
}

// ═══════════════════════════════════════════════
//  HELPER: Clear permission cache
// ═══════════════════════════════════════════════

function clear_permission_cache(): void {
    if (isset($_SESSION['permissions'])) {
        unset($_SESSION['permissions']);
    }
}

// ═══════════════════════════════════════════════
//  INSTALLATION HELPER (Optional)
// ═══════════════════════════════════════════════

function install_default_permissions(): void {
    try {
        $pdo = get_db();

        $default_permissions = array(
            array('menu.manage', 'Manage Menu', 'menu'),
            array('menu.edit', 'Edit Menu Items', 'menu'),
            array('menu.delete', 'Delete Menu Items', 'menu'),
            array('orders.view', 'View Orders', 'orders'),
            array('orders.process', 'Process Orders', 'orders'),
            array('reports.view', 'View Reports', 'reports'),
            array('users.manage', 'Manage Users', 'users'),
            array('settings.view', 'View Settings', 'settings'),
            array('settings.edit', 'Edit Settings', 'settings'),
        );

        foreach ($default_permissions as $perm) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO permissions (perm_key, label, category) VALUES (?, ?, ?)");
            $stmt->execute($perm);
        }

        $pdo->prepare("INSERT IGNORE INTO roles (role_key, label, is_system) VALUES ('admin', 'Administrator', 1)")->execute();

        $perms = $pdo->query("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($perms as $perm) {
            $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES ('admin', ?)")->execute(array($perm));
        }

        $default_roles = array(
            array('manager', 'Manager', 0),
            array('staff', 'Staff', 0),
        );

        foreach ($default_roles as $role) {
            $pdo->prepare("INSERT IGNORE INTO roles (role_key, label, is_system) VALUES (?, ?, ?)")->execute($role);
        }

    } catch (Exception $e) {
        error_log("Error installing default permissions: " . $e->getMessage());
    }
}
?>