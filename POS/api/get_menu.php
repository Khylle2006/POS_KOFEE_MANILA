<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/cache.php';
require_login();
require_permission('orders.new');

header('Content-Type: application/json');

// Fetch menu catalog from cache (5-minute TTL) or execute optimized query
$menuData = cache_remember('pos_menu_catalog', 300, function() {
    $pdo = get_db();
    // Archived items must never reach the POS, the customer menu, or the cart.
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.name,
            p.price_small,
            p.price_large,
            p.stock,
            p.image_path,
            c.category_name
        FROM products p
        JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
        WHERE p.is_deleted = 0
        ORDER BY c.category_name, p.name
    ");
    return $stmt->fetchAll();
});

// HTTP Conditional validation (ETag & Cache-Control)
$etag = '"' . md5(serialize($menuData)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=60, must-revalidate');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

echo json_encode($menuData);