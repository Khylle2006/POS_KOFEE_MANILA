<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
<<<<<<< HEAD
<<<<<<< HEAD
<<<<<<< HEAD
if ($user['role'] !== 'admin') {
=======
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
$user_roles = [];
if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    $user_roles = $_SESSION['roles'];
} elseif (!empty($_SESSION['role'])) {
    $user_roles = [$_SESSION['role']];
}
if (empty($user_roles)) {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :id UNION SELECT role FROM users WHERE id = :id AND role IS NOT NULL AND role <> ''");
        $stmt->execute([':id' => $user['id']]);
        $user_roles = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN))); 
    } catch (Throwable $e) {
        $user_roles = [];
    }
}
$is_admin = in_array('admin', $user_roles, true) || ($user['role'] ?? '') === 'admin';
if (!$is_admin) {
<<<<<<< HEAD
<<<<<<< HEAD
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
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