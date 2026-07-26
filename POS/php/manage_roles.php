<?php
// php/manage_roles.php — add or remove roles (admin only)
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();

header('Content-Type: application/json');

$user = current_user();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only admins can manage roles.']);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

if ($action === 'add') {
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