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
    $map = [
        // Inventory & Recipes
        'can_view_inventory'       => ['inventory.view', 'can_view_inventory'],
        'inventory.view'           => ['can_view_inventory', 'inventory.view'],
        'can_manage_inventory'     => ['inventory.manage', 'can_manage_inventory'],
        'inventory.manage'         => ['can_manage_inventory', 'inventory.manage'],

        // Menu & Items
        'can_add_item'             => ['menu.manage', 'menu.edit', 'can_add_item'],
        'menu.manage'              => ['can_add_item', 'menu.manage'],
        'can_edit_pricing'         => ['menu.edit', 'can_edit_pricing'],
        'menu.edit'                => ['can_edit_pricing', 'menu.edit', 'can_add_item'],
        'can_delete_item'          => ['menu.delete', 'can_delete_item'],
        'menu.delete'              => ['can_delete_item', 'menu.delete'],

        // POS & Orders
        'can_new_order'            => ['orders.new', 'can_new_order'],
        'orders.new'               => ['can_new_order', 'orders.new'],
        'can_view_orders'          => ['orders.pending', 'can_view_orders'],
        'orders.pending'           => ['can_view_orders', 'orders.pending'],
        'can_view_history'         => ['orders.history', 'can_view_history'],
        'orders.history'           => ['can_view_history', 'orders.history'],

        // Reports & Analytics
        'can_view_reports'         => ['analytics.view', 'reports.view', 'can_view_reports'],
        'analytics.view'           => ['can_view_reports', 'analytics.view'],
        'reports.view'             => ['can_view_reports', 'analytics.view'],

        // Permissions & Admin
        'can_manage_permissions'   => ['permissions.manage', 'manage_permissions', 'can_manage_permissions'],
        'permissions.manage'       => ['can_manage_permissions', 'manage_permissions', 'permissions.manage'],
        'manage_permissions'       => ['can_manage_permissions', 'permissions.manage', 'manage_permissions'],

        // Users & HR
        'can_manage_users'         => ['users.manage', 'can_manage_users'],
        'users.manage'             => ['can_manage_users', 'users.manage'],
        'can_manage_attendance'    => ['attendance.view', 'attendance.manage', 'can_manage_attendance'],
        'attendance.view'          => ['can_manage_attendance', 'attendance.view'],
        'can_manage_leave'         => ['leave.view', 'leave.manage', 'hr_leave', 'can_manage_leave'],
        'leave.view'               => ['can_manage_leave', 'leave.view', 'hr_leave'],
        'dashboard.view'           => ['dashboard.view', 'can_view_dashboard'],

        // Procurement
        'can_view_procurement'     => ['procurement.view', 'can_view_procurement'],
        'procurement.view'         => ['can_view_procurement', 'procurement.view'],
        'can_manage_requisitions'  => ['procurement.requisitions', 'procurement.requisition.create', 'can_manage_requisitions'],
        'procurement.requisitions' => ['can_manage_requisitions', 'procurement.requisitions'],
        'can_manage_rfq'           => ['procurement.rfq.manage', 'can_manage_rfq'],
        'procurement.rfq.manage'   => ['can_manage_rfq', 'procurement.rfq.manage'],
        'can_manage_po'            => ['procurement.po.manage', 'can_manage_po'],
        'procurement.po.manage'    => ['can_manage_po', 'procurement.po.manage'],
        'can_receive_goods'        => ['procurement.receiving', 'can_receive_goods'],
        'procurement.receiving'    => ['can_receive_goods', 'procurement.receiving'],
        'can_manage_invoices'      => ['procurement.invoice.create', 'can_manage_invoices'],
        'procurement.invoice.create' => ['can_manage_invoices', 'procurement.invoice.create'],
        'can_match_invoices'       => ['procurement.invoice.match', 'can_match_invoices'],
        'procurement.invoice.match'=> ['can_match_invoices', 'procurement.invoice.match'],
        'can_manage_suppliers'     => ['procurement.suppliers.manage', 'can_manage_suppliers'],
        'procurement.suppliers.manage' => ['can_manage_suppliers', 'procurement.suppliers.manage'],
    ];

    return $map[$perm_key] ?? [$perm_key];
}

/**
 * Loads and synchronizes the active user's role permissions into $_SESSION['permissions'].
 */
function sync_user_session_permissions(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) return [];

    $roles = [];
    if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        $roles = $_SESSION['roles'];
    } elseif (!empty($_SESSION['role'])) {
        $roles = [$_SESSION['role']];
    }

    if (empty($roles)) {
        try {
            $pdo = get_db();
            $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($roles)) {
                $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $uStmt->execute([$_SESSION['user_id']]);
                $single = $uStmt->fetchColumn();
                if ($single) $roles = [$single];
            }
            if ($roles) {
                $_SESSION['roles'] = $roles;
                $_SESSION['role']  = $roles[0];
            }
        } catch (Exception $e) {
            error_log("Error syncing user roles: " . $e->getMessage());
        }
    }

    // Admin holds all permissions
    if (in_array('admin', $roles, true)) {
        $_SESSION['permissions'] = ['*'];
        return ['*'];
    }

    $allPerms = [];
    try {
        $pdo = get_db();
        if (!empty($roles)) {
            $inClause = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT perm_key FROM role_permissions WHERE role IN ($inClause)");
            $stmt->execute($roles);
            $allPerms = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        error_log("Error syncing permissions for roles: " . $e->getMessage());
    }

    $_SESSION['permissions'] = $allPerms;
    return $allPerms;
}

/**
 * Does the CURRENT logged-in user have this permission?
 * Checks $_SESSION permissions directly, pulling from database if not initialized.
 * Admin always returns true.
 */
function has_permission(string $perm_key): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // 1. Must be authenticated
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    // 2. Admin bypass — full access across the platform
    $roles = $_SESSION['roles'] ?? (isset($_SESSION['role']) ? [$_SESSION['role']] : []);
    if (in_array('admin', $roles, true) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
        return true;
    }

    // 3. Ensure permissions are cached in $_SESSION
    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
        sync_user_session_permissions();
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
 */
function require_permission(string $perm_key): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../auth/login.php');
        exit;
    }

    if (!has_permission($perm_key)) {
        header('Location: dashboard.php?error=unauthorized');
        exit;
    }
}

// ═══════════════════════════════════════════════
//  HELPER: Get user's role
// ═══════════════════════════════════════════════

/**
 * Get the current user's role from session or database
 */
function get_current_user_role(): string {
    if (!isset($_SESSION['user_id'])) {
        return '';
    }
    
    if (isset($_SESSION['role']) && !empty($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    
    // Try to get from database
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

/**
 * Get all permission keys for the current user
 */
function get_current_user_permissions(): array {
    $role = get_current_user_role();
    if (empty($role)) {
        return array();
    }
    
    if ($role === 'admin') {
        // Admin has all permissions - return all from database
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

/**
 * Clear the permission cache (useful after updating permissions)
 */
function clear_permission_cache(): void {
    // Clear static cache (can't clear static directly, will be rebuilt)
    // Just unset session cache
    if (isset($_SESSION['permissions'])) {
        unset($_SESSION['permissions']);
    }
}

// ═══════════════════════════════════════════════
//  INSTALLATION HELPER (Optional)
// ═══════════════════════════════════════════════

/**
 * Install default permissions and roles (run once)
 */
function install_default_permissions(): void {
    try {
        $pdo = get_db();
        
        // Define comprehensive default permissions with both standard keys and action aliases
        $default_permissions = [
            ['dashboard.view', 'View Dashboard Overview', 'reports', 'Access system summary metrics, daily charts, and quick actions'],
            ['orders.new', 'Create POS Orders', 'orders', 'Ring up customer orders and process checkout payments'],
            ['orders.pending', 'Kitchen & Pending Orders', 'orders', 'View and fulfill active orders in the kitchen/barista queue'],
            ['orders.history', 'Order Receipts & History', 'orders', 'View completed transaction history and print receipts'],
            ['inventory.view', 'View Inventory & BOM', 'inventory', 'View ingredient stock levels, unit costs, and reorder flags'],
            ['inventory.manage', 'Manage & Restock Inventory', 'inventory', 'Add new ingredients, adjust stock, and record deliveries'],
            ['menu.manage', 'Menu Management & Add Items', 'menu', 'Create new drinks and recipes in the menu manager'],
            ['menu.edit', 'Edit Items & Pricing', 'menu', 'Modify drink prices, descriptions, and recipe configurations'],
            ['menu.delete', 'Delete & Archive Items', 'menu', 'Archive or permanently delete menu products'],
            ['analytics.view', 'Financial Analytics & Reports', 'reports', 'View sales reports, P&L, bestsellers, and cashier summaries'],
            ['users.manage', 'Staff & User Management', 'users', 'Create and modify employee accounts and system roles'],
            ['attendance.view', 'Attendance & Time-Clock', 'hr', 'Review staff clock-in/out records and calculate working hours'],
            ['leave.view', 'Leave & PTO Management', 'hr', 'Review, approve, and reject employee leave requests'],
            ['permissions.manage', 'Manage Role Permissions', 'settings', 'Configure dynamic RBAC permissions matrix for system roles'],
            // Procurement Permissions
            ['procurement.view', 'View Procurement', 'Procurement', 'See the procurement dashboard and requisition list'],
            ['procurement.requisitions', 'Create / Edit Requisitions', 'Procurement', 'View and manage purchase requisitions'],
            ['procurement.requisition.create', 'File Purchase Requisitions', 'Procurement', 'Request new goods or services for department'],
            ['procurement.requisition.review', 'Review Requisitions', 'Procurement', 'Check budget availability, approve or reject requisitions'],
            ['procurement.rfq.manage', 'Manage RFQs', 'Procurement', 'Create and send Requests for Quotation to suppliers'],
            ['procurement.bidding.review', 'Review Supplier Bids', 'Procurement', 'Evaluate submitted quotes on price, quality, delivery, risk'],
            ['procurement.negotiation', 'Negotiate Supplier Terms', 'Procurement', 'Contact suppliers and negotiate final commercial terms'],
            ['procurement.po.manage', 'Manage Purchase Orders', 'Procurement', 'Create, send, and approve Purchase Orders'],
            ['procurement.receiving', 'Record Goods Receipt', 'Procurement', 'Confirm delivery and log received quantities (GRN)'],
            ['procurement.grn.discrepancy.manage', 'Resolve Delivery Discrepancies', 'Procurement', 'Review and act on short/over/damaged delivery discrepancies'],
            ['procurement.invoice.create', 'Log Supplier Invoices', 'Procurement', 'Record incoming supplier invoices against a Purchase Order'],
            ['procurement.invoice.match', 'Match Invoices (3-Way Match)', 'Procurement', 'Match Purchase Order, Goods Receipt, and Invoice'],
            ['procurement.payment.process', 'Process Supplier Payments', 'Procurement', 'Schedule and execute payments to suppliers'],
            ['procurement.performance.rate', 'Rate Supplier Performance', 'Procurement', 'Score suppliers on quality, timeliness, price, and communication'],
            ['procurement.close', 'Close & Rate Orders', 'Procurement', 'Close completed orders and rate supplier performance'],
            ['procurement.reports.view', 'View Procurement Reports', 'Procurement', 'Access procurement reports and export data'],
            ['procurement.audit.view', 'View Procurement Audit Log', 'Procurement', 'See the full procurement activity/audit trail'],
            ['procurement.budget.manage', 'Manage Procurement Budgets', 'Procurement', 'Allocate and adjust departmental procurement budgets per period'],
            ['procurement.attachments.manage', 'Manage Procurement Attachments', 'Procurement', 'Upload and view supporting documents on procurement records'],
            ['procurement.suppliers.manage', 'Manage Suppliers', 'Procurement', 'Add, edit, or deactivate suppliers in the directory'],
            ['procurement.supplier.portal', 'Supplier Portal Access', 'Procurement', 'Supplier-side access: view RFQ invites, submit bids, acknowledge POs'],
        ];
        
        $pStmt = $pdo->prepare("INSERT IGNORE INTO permissions (perm_key, label, category, description) VALUES (?, ?, ?, ?)");
        foreach ($default_permissions as $perm) {
            $pStmt->execute($perm);
        }
        
        // Ensure standard roles exist
        $roles_to_ensure = [
            ['admin', 'Administrator', 1],
            ['manager', 'Branch Manager', 0],
            ['cashier', 'Cashier', 0],
            ['staff', 'Crew / Barista', 0],
            ['procurement', 'Procurement Officer', 0],
            ['warehouse', 'Warehouse / Receiving', 0],
            ['finance', 'Finance Officer', 0],
            ['supplier', 'Supplier', 0],
        ];
        
        $rStmt = $pdo->prepare("INSERT IGNORE INTO roles (role_key, label, is_system) VALUES (?, ?, ?)");
        foreach ($roles_to_ensure as $r) {
            $rStmt->execute($r);
        }
        
        // Ensure Admin has all permissions
        $allPerms = $pdo->query("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        $rpStmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (?, ?)");
        foreach ($allPerms as $pk) {
            $rpStmt->execute(['admin', $pk]);
        }
        
        // Default role grants
        $default_grants = [
            'manager'     => ['dashboard.view', 'orders.new', 'orders.pending', 'orders.history', 'inventory.view', 'inventory.manage', 'menu.manage', 'menu.edit', 'analytics.view', 'attendance.view', 'leave.view', 'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review'],
            'cashier'     => ['dashboard.view', 'orders.new', 'orders.pending', 'orders.history'],
            'staff'       => ['dashboard.view', 'orders.pending', 'attendance.view'],
            'crew'        => ['dashboard.view', 'orders.new', 'orders.pending', 'attendance.view'],
            'procurement' => ['dashboard.view', 'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review', 'procurement.rfq.manage', 'procurement.bidding.review', 'procurement.negotiation', 'procurement.po.manage', 'procurement.close', 'procurement.reports.view', 'procurement.suppliers.manage', 'procurement.performance.rate', 'procurement.attachments.manage'],
            'warehouse'   => ['dashboard.view', 'inventory.view', 'inventory.manage', 'procurement.view', 'procurement.receiving', 'procurement.grn.discrepancy.manage'],
            'finance'     => ['dashboard.view', 'analytics.view', 'procurement.view', 'procurement.invoice.create', 'procurement.invoice.match', 'procurement.payment.process', 'procurement.budget.manage', 'procurement.reports.view', 'procurement.audit.view'],
            'supplier'    => ['procurement.supplier.portal'],
        ];

        foreach ($default_grants as $r => $perms) {
            foreach ($perms as $pk) {
                $rpStmt->execute([$r, $pk]);
            }
        }
        
    } catch (Exception $e) {
        error_log("Error installing default permissions: " . $e->getMessage());
    }
}
?>