<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$pdo = get_db();

$sql = "
SELECT
    o.id,
    o.created_at,
    o.payment_method,
    o.total_amount,
    GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR ', ') AS items
FROM orders o
LEFT JOIN order_items oi ON o.id = oi.order_id
LEFT JOIN products p ON p.id = oi.product_id
GROUP BY o.id
ORDER BY o.id DESC
";

$stmt = $pdo->query($sql);
$data = $stmt->fetchAll();

echo json_encode($data);