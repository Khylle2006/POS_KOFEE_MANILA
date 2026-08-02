<?php
// php/save_permissions.php — toggle one permission for one role (admin only)
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();

header('Content-Type: application/json');

$user = current_user();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only admins can manage permissions.']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true) ?? [];
$role     = trim($data['role']     ?? '');
$perm_key = trim($data['perm_key'] ?? '');
$granted  = !empty($data['granted']);

$result = set_role_permission($role, $perm_key, $granted);

if (!$result['ok']) {
    http_response_code(422);
}
echo json_encode($result);