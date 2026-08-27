<?php
require_once '../includes/auth.php';
require_once '../includes/notifications.php';
require_login();

header('Content-Type: application/json');
$user = current_user();
$pdo = get_db();
sync_notifications($pdo, $user);

$stmt = $pdo->prepare(
    'SELECT id, type, title, message, link, read_at, created_at
     FROM notifications
     WHERE (user_id = :user_id OR role_key = :role_key)
     ORDER BY created_at DESC LIMIT 20'
);
$stmt->execute([':user_id' => $user['id'], ':role_key' => $user['role']]);
$items = $stmt->fetchAll();

$count = 0;
foreach ($items as $item) {
    if ($item['read_at'] === null) $count++;
}
echo json_encode(['success' => true, 'unread' => $count, 'items' => $items]);
