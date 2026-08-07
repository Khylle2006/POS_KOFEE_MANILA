<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('analytics.view');

header('Content-Type: application/json');

$pdo = get_db();

// Weekly sales & orders (exclude cancelled)
$weekly = $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) AS weekly_sales,
           COUNT(*) AS weekly_orders
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND status != 'cancelled'
")->fetch();

// Total cups sold this week (exclude cancelled)
$cups = $pdo->query("
    SELECT COALESCE(SUM(oi.quantity),0) AS cups
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND o.status != 'cancelled'
")->fetch();

// Daily sales last 7 days (exclude cancelled)
$daily_raw = $pdo->query("
    SELECT DATE(created_at) AS date, SUM(total_amount) AS total
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    AND status != 'cancelled'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$daily = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily[] = [
        'date'  => date('D', strtotime($d)),
        'full'  => $d,
        'total' => (int)($daily_raw[$d] ?? 0),
    ];
}

// Sales by category (exclude cancelled)
$categories = $pdo->query("
    SELECT c.category_name AS label,
           COALESCE(SUM(oi.subtotal),0) AS total_sales
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    LEFT JOIN order_items oi ON oi.product_id = p.id
    LEFT JOIN orders o ON o.id = oi.order_id AND o.status != 'cancelled'
    GROUP BY c.id, c.category_name
    ORDER BY total_sales DESC
")->fetchAll();

$cat_total  = array_sum(array_column($categories, 'total_sales')) ?: 1;
$cat_colors = ['#8B5E3C','#C9A96E','#e07b5a','#d4b896','#c47d3e'];
foreach ($categories as $i => &$c) {
    $c['pct']   = round(($c['total_sales'] / $cat_total) * 100);
    $c['color'] = $cat_colors[$i % count($cat_colors)];
}
unset($c);

$best_category = $categories[0]['label'] ?? 'N/A';

// Top 5 selling products (exclude cancelled)
$top_items = $pdo->query("
    SELECT p.name, SUM(oi.quantity) AS total_sold
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status != 'cancelled'
    GROUP BY p.id, p.name
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll();

// Recent 10 orders (exclude cancelled)
$recent = $pdo->query("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.created_at,
           GROUP_CONCAT(CONCAT(p.name,' x',oi.quantity) SEPARATOR ', ') AS items
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.status != 'cancelled'
    GROUP BY o.id
    ORDER BY o.id DESC LIMIT 10
")->fetchAll();

echo json_encode([
    'weekly_sales'  => (int)$weekly['weekly_sales'],
    'weekly_orders' => (int)$weekly['weekly_orders'],
    'cups'          => (int)$cups['cups'],
    'best_category' => $best_category,
    'daily_sales'   => $daily,
    'categories'    => $categories,
    'top_items'     => $top_items,
    'recent_orders' => $recent,
]);