<?php
// ─────────────────────────────────────────────
//  includes/permissions.php
//  RBAC helpers: roles + permissions.
//  Include this AFTER includes/db.php and includes/auth.php.
// ─────────────────────────────────────────────

require_once __DIR__ . '/security.php';

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

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM users u WHERE u.role = :primary_role OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = :assigned_role)');
    $inUse->execute([':primary_role' => $role_key, ':assigned_role' => $role_key]);
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
 * @return array{ok: bool, error?: string}
 */
function set_role_permission(string $role, string $perm_key, bool $granted): array {
    if ($role === 'admin') {
        return ['ok' => false, 'error' => 'Admin always has full access and cannot be edited.'];
    }
    if (!role_exists($role)) {
        return ['ok' => false, 'error' => 'Unknown role.'];
    }

    $pdo = get_db();
    $chk = $pdo->prepare('SELECT 1 FROM permissions WHERE perm_key = :p');
    $chk->execute([':p' => $perm_key]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'error' => 'Unknown permission.'];
    }

    if ($granted) {
        $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:r, :p)')
            ->execute([':r' => $role, ':p' => $perm_key]);
    } else {
        $pdo->prepare('DELETE FROM role_permissions WHERE role = :r AND perm_key = :p')
            ->execute([':r' => $role, ':p' => $perm_key]);
    }

    security_audit($pdo, 'role_permission_changed', 'role', null, ['permission' => $perm_key, 'decision' => $granted ? 'granted' : 'revoked']);
    return ['ok' => true];
}

// ═══════════════════════════════════════════════
//  PERMISSION CHECK & SESSION SYNCHRONIZATION
// ═══════════════════════════════════════════════

/**
 * Common permission aliases so both action-based ("can_view_inventory")
 * and module-based ("inventory.view") slugs evaluate identically.
 */
function get_permission_aliases(string $perm_key): array {
    $legacy = [
        'can_view_inventory' => 'inventory.view',
        'can_manage_inventory' => 'inventory.manage',
        'can_add_item' => 'menu.manage',
        'can_edit_pricing' => 'menu.edit',
        'can_delete_item' => 'menu.delete',
        'can_new_order' => 'orders.new',
        'can_view_orders' => 'orders.pending',
        'can_view_history' => 'orders.history',
        'can_view_reports' => 'analytics.view',
        'can_manage_permissions' => 'permissions.manage',
        'manage_permissions' => 'permissions.manage',
        'can_manage_users' => 'users.manage',
        'can_manage_recruitment' => 'recruitment.manage',
        'can_manage_attendance' => 'attendance.manage',
        'can_manage_leave' => 'leave.manage',
        'hr_leave' => 'leave.view',
        'can_manage_requests' => 'requests.manage',
        'hr_requests' => 'requests.manage',
        'can_manage_login_approvals' => 'login_approval.manage',
        'login_approval.manage' => 'login_approval.manage',
        'login_approval.view' => 'login_approval.view',
        'can_view_dashboard' => 'dashboard.view',
        'can_view_procurement' => 'procurement.view',
        'can_manage_requisitions' => 'procurement.requisitions',
        'can_manage_rfq' => 'procurement.rfq.manage',
        'can_manage_po' => 'procurement.po.manage',
        'can_receive_goods' => 'procurement.receiving',
        'can_manage_invoices' => 'procurement.invoice.create',
        'can_match_invoices' => 'procurement.invoice.match',
        'can_manage_suppliers' => 'procurement.suppliers.manage',
        'can_review_finance_quotes' => 'procurement.finance.review',
        'can_view_payroll' => 'payroll.view',
        'can_manage_payroll' => 'payroll.manage',
        'can_manage_loans' => 'payroll.loans',
        'can_request_advance' => 'payroll.advance.request',
        'can_view_own_payroll' => 'payroll.own',
    ];
    return [$legacy[$perm_key] ?? $perm_key];
}

/**
 * Loads and synchronizes the active user's role permissions into $_SESSION['permissions'].
 */
function sync_user_session_permissions(): array {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();
    $_SESSION['permissions'] = [];
    if (empty($_SESSION['user_id'])) return [];
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
    $stmt->execute([$_SESSION['user_id']]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($roles === []) {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $role = $stmt->fetchColumn();
        $roles = $role ? [$role] : [];
    }
    $_SESSION['roles'] = $roles;
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) $_SESSION['role'] = $roles[0] ?? '';
    $permissions = [];
    if (in_array('admin', $roles, true)) {
        $permissions = ['*'];
    } elseif ($roles !== []) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT perm_key FROM role_permissions WHERE role IN ($placeholders)");
        $stmt->execute($roles);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $_SESSION['permissions'] = $permissions;
    $_SESSION['permissions_loaded'] = time();
    return $permissions;
}

/**
 * Does the CURRENT logged-in user have this permission?
 * Checks $_SESSION permissions directly, pulling from database if not initialized.
 * Admin always returns true.
 */
function has_permission(string $perm_key): bool {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();

    // 1. Must be authenticated
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    // 2. Refresh the cache when missing, not array, or older than 10s
    $age = time() - (int)($_SESSION['permissions_loaded'] ?? 0);
    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions']) || !isset($_SESSION['roles']) || $age > 10) {
        sync_user_session_permissions();
    }

    // 3. Admin bypass — full access across the platform
    $roles = $_SESSION['roles'] ?? (isset($_SESSION['role']) ? [$_SESSION['role']] : []);
    if (in_array('admin', $roles, true)) {
        return true;
    }

    $perms = $_SESSION['permissions'] ?? [];

    if (in_array('*', $perms, true)) {
        return true;
    }

    // 4. Test exact key as well as any configured aliases
    $candidates = get_permission_aliases($perm_key);
    foreach ($candidates as $cand) {
        if (in_array($cand, $perms, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Redirect away if the current user lacks a permission.
 *
 * Sends the user to no_access.php, NOT dashboard.php. Redirecting
 * to dashboard.php loops forever for anyone who also lacks
 * dashboard.view, which is exactly the case this guard exists for.
 */
function require_permission(string $perm_key): void {
    require_login();
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/security.php';
        secure_session_start();
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: ../auth/login.php?reason=unauthenticated');
        exit;
    }

    if (!has_permission($perm_key)) {
        header('Location: no_access.php?perm=' . urlencode($perm_key));
        exit;
    }
}

/** JSON-endpoint equivalent. */
function require_permission_json(string $perm_key): void {
    require_login();
    if (session_status() === PHP_SESSION_NONE) {
        require_once __DIR__ . '/security.php';
        secure_session_start();
    }

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
        exit;
    }

    if (!has_permission($perm_key)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to do that.']);
        exit;
    }
}

// ═══════════════════════════════════════════════
//  HELPER: Get user's role
// ═══════════════════════════════════════════════

/**
 * Get the current user's primary role from session or database
 */
function get_current_user_role(): string {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();
    if (empty($_SESSION['user_id'])) {
        return '';
    }
    
    if (isset($_SESSION['role']) && !empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    
    $roles = get_current_user_roles();
    return $roles[0] ?? '';
}

/**
 * Get all roles held by the current user (supporting multi-role assignments).
 * @return string[]
 */
function get_current_user_roles(): array {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();
    if (empty($_SESSION['user_id'])) {
        return [];
    }

    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        return $_SESSION['roles'];
    }

    $roles = [];
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ? ORDER BY role");
        $stmt->execute([$_SESSION['user_id']]);
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($roles)) {
            $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $uStmt->execute([$_SESSION['user_id']]);
            $single = $uStmt->fetchColumn();
            if ($single) $roles = [$single];
        }

        if (!empty($roles)) {
            $_SESSION['roles'] = $roles;
            if (empty($_SESSION['role'])) {
                $_SESSION['role'] = $roles[0];
            }
            return $roles;
        }
    } catch (Exception $e) {
        $_SESSION['role'] = '';
        $_SESSION['roles'] = [];
        $_SESSION['permissions'] = [];
        throw new SecurityFault('PERMISSIONS_UNAVAILABLE', 'Account permissions are unavailable.', 503);
    }

    return [];
}

/**
 * Check if the current user has any of the specified roles.
 * @param string|string[] $roles
 */
function has_role(string|array $roles): bool {
    $current = get_current_user_roles();
    if (in_array('admin', $current, true)) {
        return true;
    }
    if (is_array($roles)) {
        return (bool)array_intersect($roles, $current);
    }
    return in_array($roles, $current, true);
}

// ═══════════════════════════════════════════════
//  HELPER: Get all permissions for current user
// ═══════════════════════════════════════════════

/**
 * Get all permission keys for the current user across ALL assigned roles.
 * If user holds the 'admin' role, returns all permissions in the system.
 * @return string[]
 */
function get_current_user_permissions(): array {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();
    if (empty($_SESSION['user_id'])) {
        return [];
    }

    $roles = get_current_user_roles();
    if (empty($roles)) {
        return [];
    }

    if (in_array('admin', $roles, true)) {
        try {
            $pdo = get_db();
            return $pdo->query("SELECT perm_key FROM permissions ORDER BY perm_key")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            throw new SecurityFault('PERMISSIONS_UNAVAILABLE', 'Account permissions are unavailable.', 503);
        }
    }

    try {
        $pdo = get_db();
        $inClause = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT perm_key FROM role_permissions WHERE role IN ($inClause) ORDER BY perm_key");
        $stmt->execute($roles);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        throw new SecurityFault('PERMISSIONS_UNAVAILABLE', 'Account permissions are unavailable.', 503);
    }
}

// ═══════════════════════════════════════════════
//  HELPER: Clear permission cache
// ═══════════════════════════════════════════════

/**
 * Clear the permission cache (useful after updating permissions)
 */
function clear_permission_cache(): void {
    unset($_SESSION['permissions'], $_SESSION['permissions_loaded']);
}

// ═══════════════════════════════════════════════
// ═══════════════════════════════════════════════
//  ROLE PERMISSION DEFAULTS & INSTALLATION
// ═══════════════════════════════════════════════

/**
 * Return the clean, standard default permission grants for any system role.
 */
function get_default_role_permissions(string $role): array {
    $defaults = [
        'admin' => ['*'],
        'manager' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'orders.new', 'orders.pending', 'orders.history',
            'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
            'menu.manage', 'menu.edit', 'menu.delete',
            'attendance.view', 'attendance.manage', 'leave.view', 'leave.manage', 'requests.manage',
            'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.loans', 'payroll.own', 'payroll.advance.request',
            'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review',
            'procurement.reports.view', 'procurement.attachments.manage', 'procurement.audit.view',
            'analytics.view', 'operations.activity.view',
            'store.view', 'store.manage', 'files.download'
        ],
        'cashier' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'orders.new', 'orders.pending', 'orders.history',
            'menu.manage',
            'store.view',
            'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
        ],
        'crew' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'orders.new', 'orders.pending', 'orders.history',
            'inventory.view',
            'store.view',
            'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
        ],
        'warehouse' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
            'procurement.view', 'procurement.receiving', 'procurement.grn.discrepancy.manage',
            'procurement.requisition.create', 'procurement.attachments.manage',
            'store.view',
            'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
        ],
        'procurement' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review',
            'procurement.rfq.manage', 'procurement.bidding.review', 'procurement.negotiation',
            'procurement.po.manage', 'procurement.close',
            'procurement.performance.rate', 'procurement.suppliers.manage', 'procurement.reports.view',
            'procurement.budget.manage', 'procurement.attachments.manage', 'procurement.audit.view',
            'inventory.view', 'store.view',
            'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
        ],
        'finance' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'analytics.view', 'operations.activity.view',
            'payroll.view', 'payroll.approve', 'payroll.release', 'payroll.payout.approve', 'payroll.payout.paymongo', 'payroll.loans', 'payroll.own', 'payroll.advance.request',
            'procurement.view', 'procurement.finance.review', 'procurement.invoice.create', 'procurement.invoice.match', 'procurement.payment.process',
            'procurement.budget.manage', 'procurement.reports.view', 'procurement.audit.view', 'procurement.attachments.manage',
            'attendance.view', 'leave.view', 'files.download'
        ],
        'hr' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'users.manage', 'login_approval.manage', 'recruitment.manage', 'attendance.view', 'attendance.manage', 'leave.view', 'leave.manage', 'requests.manage', 'employee.payment.manage',
            'payroll.view', 'payroll.manage', 'payroll.loans', 'payroll.settings', 'payroll.own', 'payroll.advance.request',
            'analytics.view', 'files.download', 'store.view'
        ],
        'ops' => [
            'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
            'orders.new', 'orders.pending', 'orders.history',
            'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
            'menu.manage', 'menu.edit',
            'operations.activity.view', 'analytics.view',
            'attendance.view', 'leave.view', 'requests.manage',
            'store.view', 'store.manage',
            'payroll.own', 'payroll.advance.request'
        ],
        'supplier' => [
            'procurement.supplier.portal',
            'profile.view', 'profile.edit'
        ],
    ];

    return $defaults[$role] ?? [];
}

/**
 * Reset a role's permissions back to recommended system defaults.
 */
function reset_role_to_default_permissions(string $role): array {
    if ($role === 'admin') {
        return ['ok' => false, 'error' => 'Admin always has full permissions across the platform.'];
    }

    $defaultPerms = get_default_role_permissions($role);
    if (empty($defaultPerms)) {
        return ['ok' => false, 'error' => "No default template found for role '{$role}'."];
    }

    $pdo = get_db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM role_permissions WHERE role = :r')->execute([':r' => $role]);

        $ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:r, :p)');
        foreach ($defaultPerms as $pk) {
            $ins->execute([':r' => $role, ':p' => $pk]);
        }

        $pdo->commit();
        clear_permission_cache();
        return ['ok' => true, 'message' => "Role '{$role}' reset to default permissions.", 'permissions' => $defaultPerms];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Install default permissions and roles (idempotent setup).
 */
function install_default_permissions(): void {
    require_runtime_schema(get_db());
}
?>
