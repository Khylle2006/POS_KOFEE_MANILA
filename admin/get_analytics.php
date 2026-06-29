<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$pdo = get_db();


$weeklySales = $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) AS weekly_sales
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetch();


$weeklyOrders = $pdo->query("
    SELECT COUNT(*) AS weekly_orders
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetch();


$cups = $pdo->query("
    SELECT COALESCE(SUM(quantity),0) AS cups
    FROM order_items
")->fetch();

/
$daily = $pdo->query("
    SELECT DATE(created_at) as date, SUM(total_amount) as total
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll();


$categories = $pdo->query("
    SELECT 
        c.category_name,
        SUM(oi.quantity * oi.price) AS total_sales
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    GROUP BY c.category_name
    ORDER BY total_sales DESC
")->fetchAll();


$bestCategory = $categories[0]['category_name'] ?? 'N/A';


$topItems = $pdo->query("
    SELECT 
        p.name,
        SUM(oi.quantity) AS total_sold
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    GROUP BY p.id, p.name
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll();


$recentOrders = $pdo->query("
    SELECT 
        o.id,
        o.total_amount,
        o.created_at,
        COUNT(oi.id) AS items_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    GROUP BY o.id, o.total_amount, o.created_at
    ORDER BY o.created_at DESC
    LIMIT 10
")->fetchAll();


echo json_encode([
    "weekly_sales" => $weeklySales['weekly_sales'],
    "weekly_orders" => $weeklyOrders['weekly_orders'],
    "cups" => $cups['cups'],
    "best_category" => $bestCategory,
    "daily_sales" => $daily,
    "categories" => $categories,
    "top_items" => $topItems,
    "recent_orders" => $recentOrders
]);