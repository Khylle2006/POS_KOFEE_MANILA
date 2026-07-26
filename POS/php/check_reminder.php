<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();
header('Content-Type: application/json');

$pdo = get_db();

$total_orders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$last_dismissed = (int)($pdo->query("
    SELECT `value` FROM app_settings WHERE `key` = 'inventory_reminder_dismissed_at'
")->fetchColumn() ?: 0);

$should_show = (
    $total_orders > 0 &&
    ($total_orders % 20 === 0) &&
    $last_dismissed !== $total_orders
);

$last_update = $pdo->query("SELECT MAX(updated_at) FROM ingredients")->fetchColumn();

echo json_encode([
    'show'         => $should_show,
    'total_orders' => $total_orders,
    'last_update'  => $last_update ? date('M d, Y g:i A', strtotime($last_update)) : null,
]);