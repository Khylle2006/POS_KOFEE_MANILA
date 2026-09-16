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

$data = [];
$raw = file_get_contents('php://input');
$jsonData = json_decode($raw, true);
if (is_array($jsonData)) {
    $data = $jsonData;
} elseif (!empty($_POST)) {
    $data = $_POST;
} else {
    $data = [];
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
$productId = (int)($data['id'] ?? 0);
$isUpdate  = $productId > 0;

if ($isUpdate) {
    $dupCheck = $pdo->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(:name) AND is_deleted = 0 AND id != :pid');
    $dupCheck->execute([':name' => $name, ':pid' => $productId]);
} else {
    $dupCheck = $pdo->prepare('SELECT id FROM products WHERE LOWER(name) = LOWER(:name) AND is_deleted = 0');
    $dupCheck->execute([':name' => $name]);
}
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
    // If upsize price is omitted or 0, default to regular price + ₱20
    $price_large = $price_small + 20.00;
}

$recipe_small = $data['recipe_small'] ?? [];
if (is_string($recipe_small)) {
    $recipe_small = json_decode($recipe_small, true) ?: [];
}
if (!is_array($recipe_small)) $recipe_small = [];

$recipe_large = $data['recipe_large'] ?? [];
if (is_string($recipe_large)) {
    $recipe_large = json_decode($recipe_large, true) ?: [];
}
if (!is_array($recipe_large)) $recipe_large = [];

// ── Handle Optional Product Image Upload ────────────────────────
$uploadedImagePath = null;
$removeImage = ($data['remove_image'] ?? '0') === '1' || ($data['remove_image'] ?? false) === true;

if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid image format. Allowed formats: JPG, PNG, WEBP, GIF.']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Image file exceeds maximum allowable size of 5MB.']);
        exit;
    }

    $ext = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg'
    };

    $targetDir = __DIR__ . '/../assets/menu/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileName = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded image file to server storage.']);
        exit;
    }

    $uploadedImagePath = 'assets/menu/' . $fileName;
}

function resolveMenuDiskPath($path) {
    if (empty($path)) return null;
    $rel = ltrim($path, '/');
    if (strpos($rel, 'assets/') === 0) {
        return __DIR__ . '/../' . $rel;
    } elseif (strpos($rel, 'menu/') === 0) {
        return __DIR__ . '/../assets/' . $rel;
    }
    return __DIR__ . '/../assets/menu/' . $rel;
}

// ── Execute Database Transaction ────────────────────────────────
$finalImagePath = null;
try {
    $pdo->beginTransaction();

    if ($isUpdate) {
        $chk = $pdo->prepare('SELECT id, image_path FROM products WHERE id = :pid');
        $chk->execute([':pid' => $productId]);
        $existingProd = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existingProd) {
            throw new Exception("Product ID {$productId} not found.");
        }

        $oldImagePath = $existingProd['image_path'] ?? null;

        if ($uploadedImagePath !== null) {
            $finalImagePath = $uploadedImagePath;
            if (!empty($oldImagePath)) {
                $oldFile = resolveMenuDiskPath($oldImagePath);
                if ($oldFile && is_file($oldFile)) @unlink($oldFile);
            }
        } elseif ($removeImage) {
            $finalImagePath = null;
            if (!empty($oldImagePath)) {
                $oldFile = resolveMenuDiskPath($oldImagePath);
                if ($oldFile && is_file($oldFile)) @unlink($oldFile);
            }
        } else {
            $finalImagePath = $oldImagePath;
        }

        $prodStmt = $pdo->prepare("
            UPDATE products
            SET category_id = :cat, name = :name, description = :desc, price_small = :ps, price_large = :pl, price = :price, image_path = :img
            WHERE id = :id
        ");
        $prodStmt->execute([
            ':cat'   => $category_id,
            ':name'  => $name,
            ':desc'  => $description,
            ':ps'    => $price_small,
            ':pl'    => $price_large,
            ':price' => (int)round($price_small),
            ':img'   => $finalImagePath,
            ':id'    => $productId,
        ]);

        // Clean out existing recipe lines to replace with updated ones
        $pdo->prepare('DELETE FROM product_ingredients WHERE product_id = :pid')->execute([':pid' => $productId]);
    } else {
        $finalImagePath = $uploadedImagePath;

        // 1. Insert product record
        $prodStmt = $pdo->prepare("
            INSERT INTO products (category_id, name, description, price_small, price_large, price, image_path, stock, is_deleted)
            VALUES (:cat, :name, :desc, :ps, :pl, :price, :img, 1, 0)
        ");
        $prodStmt->execute([
            ':cat'   => $category_id,
            ':name'  => $name,
            ':desc'  => $description,
            ':ps'    => $price_small,
            ':pl'    => $price_large,
            ':price' => (int)round($price_small),
            ':img'   => $finalImagePath,
        ]);

        $productId = (int)$pdo->lastInsertId();
    }

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
        'message'        => $isUpdate ? "Menu item '{$name}' updated successfully!" : "Menu item '{$name}' created successfully!",
        'product_id'     => $productId,
        'name'           => $name,
        'price_small'    => $price_small,
        'price_large'    => $price_large,
        'image_path'     => $finalImagePath,
        'is_update'      => $isUpdate,
        'recipe_counts'  => [
            'small' => $insertedSmall,
            'large' => $insertedLarge,
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Clean up newly uploaded image if database commit failed
    if ($uploadedImagePath) {
        $targetFile = __DIR__ . '/../assets/' . ltrim($uploadedImagePath, '/');
        if (is_file($targetFile)) @unlink($targetFile);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database transaction failed: ' . $e->getMessage()]);
}
