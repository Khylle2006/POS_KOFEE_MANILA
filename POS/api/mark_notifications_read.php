<?php
require_once '../includes/auth.php';
require_once '../includes/notifications.php';
require_login();

header('Content-Type: application/json');
$user = current_user();
$pdo = get_db();

$stmt = $pdo->prepare(
    'UPDATE notifications SET read_at = NOW()
     WHERE read_at IS NULL AND (user_id = :user_id OR role_key = :role_key)'
);
$stmt->execute([':user_id' => $user['id'], ':role_key' => $user['role']]);
echo json_encode(['success' => true]);
