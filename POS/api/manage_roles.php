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
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to manage roles.']);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

if ($action === 'add' || $action === 'create') {
    $result = create_role($data['role_key'] ?? '', $data['label'] ?? '');
    echo json_encode($result);
    exit;
}

if ($action === 'delete') {
    $result = delete_role($data['role_key'] ?? '');
    echo json_encode($result);
    exit;
}

http_response_code(422);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);