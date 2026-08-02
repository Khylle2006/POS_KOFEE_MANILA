<?php
// php/dismiss_reminder.php
// Saves the dismissed order count so reminder doesn't re-show
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

header('Content-Type: application/json');

$data        = json_decode(file_get_contents('php://input'), true);
$order_count = (int)($data['order_count'] ?? 0);

if ($order_count > 0) {
    $pdo = get_db();
    $pdo->prepare("
        INSERT INTO app_settings (`key`, `value`) VALUES ('inventory_reminder_dismissed_at', :v)
        ON DUPLICATE KEY UPDATE `value` = :v2
    ")->execute([':v' => $order_count, ':v2' => $order_count]);
}

echo json_encode(['ok' => true]);