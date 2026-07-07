<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();
header('Content-Type: application/json');

$pdo = get_db();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | dine | take | delivery
$date   = $_GET['date']   ?? '';

$where  = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (o.id LIKE :s OR p.name LIKE :s2)';
    $params[':s']  = "%$search%";
    $params[':s2'] = "%$search%";
}
if ($filter !== 'all') {
    $where .= ' AND LOWER(o.payment_method) LIKE :f';
    $params[':f'] = '%' . strtolower($filter) . '%';
}
if ($date) {
    $where .= ' AND o.created_at = :d';
    $params[':d'] = $date;
}

$stmt = $pdo->prepare("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.created_at,
           GROUP_CONCAT(CONCAT(p.name,' x',oi.quantity) SEPARATOR ', ') AS items,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE $where
    GROUP BY o.id
    ORDER BY o.id DESC
    LIMIT 100
");
$stmt->execute($params);

echo json_encode($stmt->fetchAll());