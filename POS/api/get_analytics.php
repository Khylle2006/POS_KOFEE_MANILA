<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/analytics_expenses.php';
require_login();
require_permission('analytics.view');

header('Content-Type: application/json');

header('Cache-Control: no-store');
$analyticsData = (function() {
    $pdo = get_db();

    // ── 1. Sales & Orders Metrics (exclude cancelled) ──
    $all_sales_row = $pdo->query("
        SELECT COALESCE(SUM(total_amount),0) AS total_sales,
               COUNT(*) AS total_orders
        FROM orders
        WHERE status != 'cancelled'
    ")->fetch();
    $total_sales  = (float)$all_sales_row['total_sales'];
    $total_orders = (int)$all_sales_row['total_orders'];

    // Weekly sales & orders (last 7 days, exclude cancelled)
    $weekly = $pdo->query("
        SELECT COALESCE(SUM(total_amount),0) AS weekly_sales,
               COUNT(*) AS weekly_orders
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND status != 'cancelled'
    ")->fetch();
    $weekly_sales  = (float)$weekly['weekly_sales'];
    $weekly_orders = (int)$weekly['weekly_orders'];

    // Monthly sales & orders (current month, exclude cancelled)
    $monthly = $pdo->query("
        SELECT COALESCE(SUM(total_amount),0) AS monthly_sales,
               COUNT(*) AS monthly_orders
        FROM orders
        WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND status != 'cancelled'
    ")->fetch();
    $monthly_sales  = (float)$monthly['monthly_sales'];
    $monthly_orders = (int)$monthly['monthly_orders'];

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

    // Read the payment ledgers on every request so payroll and procurement stay in sync.
    $expense_breakdown = analytics_expense_breakdown($pdo);
    $total_expenses = round(array_sum($expense_breakdown['all']), 2);
    $weekly_expenses = round(array_sum($expense_breakdown['weekly']), 2);
    $monthly_expenses = round(array_sum($expense_breakdown['monthly']), 2);
    $total_profit   = round($total_sales - $total_expenses, 2);
    $weekly_profit  = round($weekly_sales - $weekly_expenses, 2);
    $monthly_profit = round($monthly_sales - $monthly_expenses, 2);

    $profit_margin         = $total_sales > 0 ? round(($total_profit / $total_sales) * 100, 1) : 0.0;
    $weekly_profit_margin  = $weekly_sales > 0 ? round(($weekly_profit / $weekly_sales) * 100, 1) : 0.0;
    $monthly_profit_margin = $monthly_sales > 0 ? round(($monthly_profit / $monthly_sales) * 100, 1) : 0.0;

    $avg_order_value  = $total_orders > 0 ? round($total_sales / $total_orders, 2) : 0.0;
    $weekly_avg_order = $weekly_orders > 0 ? round($weekly_sales / $weekly_orders, 2) : 0.0;
    $monthly_avg_order = $monthly_orders > 0 ? round($monthly_sales / $monthly_orders, 2) : 0.0;

    return [
        // All-Time Summary
        'total_sales'           => (float)$total_sales,
        'total_expenses'        => (float)$total_expenses,
        'total_profit'          => (float)$total_profit,
        'profit_margin'         => (float)$profit_margin,
        'total_orders'          => (int)$total_orders,
        'avg_order_value'       => (float)$avg_order_value,

        // Weekly Summary (Last 7 Days)
        'weekly_sales'          => (float)$weekly_sales,
        'weekly_expenses'       => (float)$weekly_expenses,
        'weekly_profit'         => (float)$weekly_profit,
        'weekly_profit_margin'  => (float)$weekly_profit_margin,
        'weekly_orders'         => (int)$weekly_orders,
        'weekly_avg_order'      => (float)$weekly_avg_order,

        // Monthly Summary (Current Month)
        'monthly_sales'         => (float)$monthly_sales,
        'monthly_expenses'      => (float)$monthly_expenses,
        'monthly_profit'        => (float)$monthly_profit,
        'monthly_profit_margin' => (float)$monthly_profit_margin,
        'monthly_orders'        => (int)$monthly_orders,
        'monthly_avg_order'     => (float)$monthly_avg_order,

        // Detailed breakdowns
        'expense_breakdown'     => $expense_breakdown,

        // Preserved operational fields
        'cups'                  => (int)$cups['cups'],
        'best_category'         => $best_category,
        'daily_sales'           => $daily,
        'categories'            => $categories,
        'top_items'             => $top_items,
        'recent_orders'         => $recent,
    ];
})();

echo json_encode($analyticsData);
