<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/cache.php';
require_login();

header('Content-Type: application/json');

$ingredients = cache_remember('ingredients_catalog', 120, function() {
    $pdo = get_db();
    $stmt = $pdo->query("
        SELECT 
            i.id,
            i.name,
            i.brand,
            i.unit,
            i.quantity,
            c.name as cat_name
        FROM ingredients i
        LEFT JOIN ingredient_categories c ON c.id = i.cat_id
        WHERE i.archived_at IS NULL
        ORDER BY c.name, i.name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
});

echo json_encode($ingredients);

