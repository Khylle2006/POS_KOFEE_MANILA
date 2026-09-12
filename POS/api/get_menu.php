<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.new');

header('Content-Type: application/json');

$pdo = get_db();
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0");

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
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all recipes to determine real-time ingredient availability per size
$recipesStmt = $pdo->query("
    SELECT pi.product_id, pi.size, pi.qty_used, i.name AS ingredient_name, i.quantity, i.unit
    FROM product_ingredients pi
    JOIN ingredients i ON i.id = pi.ingredient_id
    WHERE i.archived_at IS NULL
");
$recipes = $recipesStmt->fetchAll(PDO::FETCH_ASSOC);

$productRecipes = [];
foreach ($recipes as $r) {
    $pid = (int)$r['product_id'];
    $sz  = $r['size'];
    $productRecipes[$pid][$sz][] = $r;
}

$output = [];
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $hasRecipe = !empty($productRecipes[$pid]);

    $smallAvail = true;
    $smallMissing = [];
    if (!empty($productRecipes[$pid]['small'])) {
        foreach ($productRecipes[$pid]['small'] as $ing) {
            if ((float)$ing['quantity'] < (float)$ing['qty_used']) {
                $smallAvail = false;
                $smallMissing[] = $ing['ingredient_name'];
            }
        }
    }

    $largeAvail = true;
    $largeMissing = [];
    if (!empty($productRecipes[$pid]['large'])) {
        foreach ($productRecipes[$pid]['large'] as $ing) {
            if ((float)$ing['quantity'] < (float)$ing['qty_used']) {
                $largeAvail = false;
                $largeMissing[] = $ing['ingredient_name'];
            }
        }
    }

    // If manual product stock is 0, mark both as unavailable
    if ((int)$p['stock'] <= 0) {
        $smallAvail = false;
        $largeAvail = false;
    }

    $p['has_recipe']      = $hasRecipe;
    $p['small_available'] = $smallAvail;
    $p['small_missing']   = array_values(array_unique($smallMissing));
    $p['large_available'] = $largeAvail;
    $p['large_missing']   = array_values(array_unique($largeMissing));

    $output[] = $p;
}

echo json_encode($output);