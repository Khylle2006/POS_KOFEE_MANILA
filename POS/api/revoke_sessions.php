<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$target = request_data()['user_id'] ?? $_SESSION['user_id'];
if (!is_int($target) && !(is_string($target) && ctype_digit($target))) {
    throw new SecurityFault('INPUT_INVALID', 'Invalid user.');
}
if ((int)$target !== (int)$_SESSION['user_id'] && !has_permission('users.manage')) {
    throw new SecurityFault('PERMISSION_DENIED', 'Permission denied.', 403);
}
revoke_user_sessions(get_db(), (int)$target);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'success' => true]);
