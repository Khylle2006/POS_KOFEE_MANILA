<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
$userRoles = $_SESSION['roles'] ?? (isset($user['roles']) ? $user['roles'] : [$user['role'] ?? '']);
$canManage = has_permission('permissions.manage') || in_array('admin', $userRoles, true) || ($user['role'] ?? '') === 'admin';
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to manage permissions.']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true) ?? [];
$role     = strtolower(trim($data['role'] ?? ''));

if ($role === 'admin') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Admin permissions cannot be edited.']);
    exit;
}

if (empty($role)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Role is required.']);
    exit;
}

$pdo = get_db();

// Case 1: Bulk replace all permissions for a role
if (isset($data['permissions']) && is_array($data['permissions'])) {
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare('DELETE FROM role_permissions WHERE role = :role');
        $del->execute([':role' => $role]);

        if (!empty($data['permissions'])) {
            $ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:role, :perm)');
            foreach ($data['permissions'] as $pk) {
                $pk = trim($pk);
                if ($pk !== '') {
                    $ins->execute([':role' => $role, ':perm' => $pk]);
                }
            }
        }
        $pdo->commit();
        clear_permission_cache();
        echo json_encode(['ok' => true, 'message' => 'Role permissions saved successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Case 2: Batch array of changes: updates = [ { perm_key, granted } ]
if (isset($data['updates']) && is_array($data['updates'])) {
    try {
        $pdo->beginTransaction();
        $grantStmt  = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (:role, :perm)');
        $revokeStmt = $pdo->prepare('DELETE FROM role_permissions WHERE role = :role AND perm_key = :perm');

        foreach ($data['updates'] as $up) {
            $pk = trim($up['perm_key'] ?? $up['permission'] ?? '');
            $g  = !empty($up['granted']);
            if ($pk === '') continue;
            if ($g) {
                $grantStmt->execute([':role' => $role, ':perm' => $pk]);
            } else {
                $revokeStmt->execute([':role' => $role, ':perm' => $pk]);
            }
        }
        $pdo->commit();
        clear_permission_cache();
        echo json_encode(['ok' => true, 'message' => 'Permissions batch saved successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Case 3: Single toggle: { role, perm_key, granted }
$perm_key = trim($data['perm_key'] ?? $data['permission'] ?? '');
$granted  = !empty($data['granted']);

$result = set_role_permission($role, $perm_key, $granted);
if ($result['ok']) {
    clear_permission_cache();
} else {
    http_response_code(422);
}
echo json_encode($result);