<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');

header('Content-Type: application/json');

$pdo = get_db();

$stmt = $pdo->query("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.created_at,
           GROUP_CONCAT(CONCAT(p.name,' x',oi.quantity) SEPARATOR ', ') AS items
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    GROUP BY o.id
    ORDER BY
        FIELD(o.status, 'pending', 'completed', 'cancelled'),
        o.id DESC
    LIMIT 200
");

echo json_encode($stmt->fetchAll());