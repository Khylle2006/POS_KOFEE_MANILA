<?php
// ============================================================
// API: save_item.php
// Atomic transaction handler for progressive multi-step item & recipe creation
// ============================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

// Check permission to add/manage menu items
if (!has_permission('menu.manage') && !has_permission('can_add_item') && !has_permission('menu.edit')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden: You do not have permission to create menu items.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    $data = $_POST;
}

$pdo = get_db();

// ── Extract & Sanitize Item Identity (Step 1) ──────────────────
$name        = trim($data['name'] ?? '');
$category_id = (int)($data['category_id'] ?? 0);
$description = trim($data['description'] ?? '');

if (empty($name) || strlen($name) < 2) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Item name must be at least 2 characters long.']);
    exit;
}

if ($category_id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please select a valid menu category.']);
    exit;
}

// Check for duplicate item name
$dupCheck = $pdo->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(:name) AND is_deleted = 0');
$dupCheck->execute([':name' => $name]);
if ($dupCheck->fetchColumn()) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => "A menu item named '{$name}' already exists."]);
    exit;
}

// ── Extract & Sanitize Prices & Recipes (Steps 2 & 3) ────────────
$price_small = (float)($data['price_small'] ?? 0);
$price_large = (float)($data['price_large'] ?? 0);

if ($price_small <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Regular size price must be greater than ₱0.00.']);
    exit;
}

if ($price_large <= 0) {
    // If upsize price is omitted or 0, default to regular price or small markup
    $price_large = $price_small + 20.00;
}

$recipe_small = is_array($data['recipe_small'] ?? null) ? $data['recipe_small'] : [];
$recipe_large = is_array($data['recipe_large'] ?? null) ? $data['recipe_large'] : [];

// ── Execute Database Transaction ────────────────────────────────
try {
    $pdo->beginTransaction();

    // 1. Insert product record
    $prodStmt = $pdo->prepare("
        INSERT INTO products (category_id, name, description, price_small, price_large, price, stock, is_deleted)
        VALUES (:cat, :name, :desc, :ps, :pl, :price, 1, 0)
    ");
    $prodStmt->execute([
        ':cat'   => $category_id,
        ':name'  => $name,
        ':desc'  => $description,
        ':ps'    => $price_small,
        ':pl'    => $price_large,
        ':price' => (int)round($price_small),
    ]);

    $productId = (int)$pdo->lastInsertId();

    // 2. Insert Regular Size Recipe Lines into product_ingredients
    $piStmt = $pdo->prepare("
        INSERT INTO product_ingredients (product_id, size, ingredient_id, qty_used)
        VALUES (:pid, :size, :ing, :qty)
        ON DUPLICATE KEY UPDATE qty_used = :qty_dup
    ");

    $insertedSmall = 0;
    foreach ($recipe_small as $row) {
        $ingId = (int)($row['ingredient_id'] ?? 0);
        $qty   = (float)($row['qty_used'] ?? 0);
        if ($ingId > 0 && $qty > 0) {
            $piStmt->execute([
                ':pid'     => $productId,
                ':size'    => 'small',
                ':ing'     => $ingId,
                ':qty'     => $qty,
                ':qty_dup' => $qty,
            ]);
            $insertedSmall++;
        }
    }

    // 3. Insert Upsize Recipe Lines into product_ingredients
    $insertedLarge = 0;
    foreach ($recipe_large as $row) {
        $ingId = (int)($row['ingredient_id'] ?? 0);
        $qty   = (float)($row['qty_used'] ?? 0);
        if ($ingId > 0 && $qty > 0) {
            $piStmt->execute([
                ':pid'     => $productId,
                ':size'    => 'large',
                ':ing'     => $ingId,
                ':qty'     => $qty,
                ':qty_dup' => $qty,
            ]);
            $insertedLarge++;
        }
    }

    // Commit all operations safely
    $pdo->commit();

    echo json_encode([
        'ok'             => true,
        'message'        => "Menu item '{$name}' created successfully!",
        'product_id'     => $productId,
        'name'           => $name,
        'price_small'    => $price_small,
        'price_large'    => $price_large,
        'recipe_counts'  => [
            'small' => $insertedSmall,
            'large' => $insertedLarge,
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database transaction failed: ' . $e->getMessage()]);
}
