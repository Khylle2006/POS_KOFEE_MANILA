<?php
// ============================================================
// API: update_permissions.php
// Handles dynamic AJAX updates to role permissions from the matrix UI
// ============================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

// Ensure user has admin or permission-management authority
$user = current_user();
$roles = $_SESSION['roles'] ?? (isset($_SESSION['role']) ? [$_SESSION['role']] : []);
$isAdmin = in_array('admin', $roles, true) || ($user['role'] ?? '') === 'admin';

if (!$isAdmin && !has_permission('permissions.manage') && !has_permission('can_manage_permissions')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied: Only administrators can update role permissions.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    // Fall back to $_POST
    $data = $_POST;
}

$pdo = get_db();

try {
    // Case 1: Single permission toggle: { role: "cashier", permission: "orders.new", granted: true/false }
    if (isset($data['role']) && (isset($data['permission']) || isset($data['perm_key']))) {
        $role = strtolower(trim($data['role']));
        $perm = trim($data['permission'] ?? $data['perm_key']);
        $granted = filter_var($data['granted'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($role === 'admin') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'The Admin role always has full permissions and cannot be modified.']);
            exit;
        }

        if (empty($role) || empty($perm)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Role and permission identifier are required.']);
            exit;
        }

        $result = set_role_permission($role, $perm, $granted);

        if (!$result['ok']) {
            http_response_code(422);
            echo json_encode($result);
            exit;
        }

        // Clear session cache so that current users with this role get immediate updates
        clear_permission_cache();

        echo json_encode([
            'ok' => true,
            'message' => $granted ? "Permission '{$perm}' granted to {$role}" : "Permission '{$perm}' revoked from {$role}",
            'role' => $role,
            'permission' => $perm,
            'granted' => $granted
        ]);
        exit;
    }

    // Case 2: Full role permissions replacement: { role: "cashier", permissions: ["orders.new", "orders.pending"] }
    if (isset($data['role']) && isset($data['permissions']) && is_array($data['permissions'])) {
        $role = strtolower(trim($data['role']));
        $perms = array_filter(array_map('trim', $data['permissions']));

        if ($role === 'admin') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'The Admin role always has full permissions and cannot be modified.']);
            exit;
        }

        $pdo->beginTransaction();

        // Remove existing permissions for this role
        $del = $pdo->prepare('DELETE FROM role_permissions WHERE role = :role');
        $del->execute([':role' => $role]);

        // Insert new permissions
        if (!empty($perms)) {
            $ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:role, :perm)');
            foreach ($perms as $perm) {
                $ins->execute([':role' => $role, ':perm' => $perm]);
            }
        }

        $pdo->commit();
        clear_permission_cache();

        echo json_encode([
            'ok' => true,
            'message' => "Successfully updated permissions for role '{$role}'.",
            'role' => $role,
            'count' => count($perms)
        ]);
        exit;
    }

    // Case 3: Batch updates matrix: { updates: [ { role, permission, granted }, ... ] }
    if (isset($data['updates']) && is_array($data['updates'])) {
        $pdo->beginTransaction();

        $grantStmt  = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:role, :perm)');
        $revokeStmt = $pdo->prepare('DELETE FROM role_permissions WHERE role = :role AND perm_key = :perm');

        foreach ($data['updates'] as $item) {
            $r = strtolower(trim($item['role'] ?? ''));
            $p = trim($item['permission'] ?? $item['perm_key'] ?? '');
            $g = filter_var($item['granted'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($r === 'admin' || empty($r) || empty($p)) continue;

            if ($g) {
                $grantStmt->execute([':role' => $r, ':perm' => $p]);
            } else {
                $revokeStmt->execute([':role' => $r, ':perm' => $p]);
            }
        }

        $pdo->commit();
        clear_permission_cache();

        echo json_encode([
            'ok' => true,
            'message' => 'Batch permissions successfully saved to database.',
            'count' => count($data['updates'])
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Unrecognized payload format. Provide role + permission, role + permissions array, or updates array.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

