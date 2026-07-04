<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role('admin');

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $cat_id      = (int)($_POST['category_id']  ?? 0);
    $name        = trim($_POST['name']           ?? '');
    $desc        = trim($_POST['description']    ?? '');
    $price_small = (float)($_POST['price_small'] ?? 0);
    $price_large = (float)($_POST['price_large'] ?? 0);

    if (!$cat_id) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please select a category.']);
        exit;
    }
    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter a drink name.']);
        exit;
    }
    if ($price_small <= 0 || $price_large <= 0) {   
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter valid prices for both sizes.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO products (name, description, price_small, price_large, category_id)
             VALUES (:name, :desc, :price_small, :price_large, :cat)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':desc'        => $desc,
            ':price_small' => $price_small,
            ':price_large' => $price_large,
            ':cat'         => $cat_id,
        ]);

        echo json_encode(['ok' => true, 'name' => $name]);
        exit;

    } catch (PDOException $e) {
        error_log('Add item error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save item: ' . $e->getMessage()]);
        exit;
    }
}

// Load categories from DB
$categories = $pdo->query('SELECT id, category_name FROM categories ORDER BY category_name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>POS System — Add Menu Item</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/add_items.css">
</head>
<body>
    <?php include('../includes/admin_sidebar.php'); ?>

    <div id="page-addmenu" class="page active">
        <div class="page-header">
            <div>
                <h1>Add Menu Item</h1>
                <p>Add a new drink to your menu</p>
            </div>
        </div>
        <div class="page-body">
            <div class="add-menu-wrap">

                <div class="form-card">
                    <h2>Item Details</h2>

                    <div class="field-group">
                        <label class="field-label">Category <span class="req">*</span></label>
                        <select class="field-select" id="add-category">
                            <option value="">Select a category…</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="add-name">Drink Name <span class="req">*</span></label>
                        <input class="field-input" type="text" id="add-name" placeholder="e.g. Taro Milk Tea"/>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="add-desc">
                            Description
                            <span style="color:var(--text-muted);font-weight:400">(optional)</span>
                        </label>
                        <textarea class="field-textarea" id="add-desc" placeholder="Brief description…"></textarea>
                    </div>
                </div>

                <div class="form-card">
                    <h2>Pricing</h2>
                    <div class="two-col">
                        <div class="field-group">
                            <label class="field-label" for="add-price-small">Small Price (₱) <span class="req">*</span></label>
                            <input class="field-input" type="number" id="add-price-small" placeholder="0" min="1" step="0.01"/>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="add-price-large">Large Price (₱) <span class="req">*</span></label>
                            <input class="field-input" type="number" id="add-price-large" placeholder="0" min="1" step="0.01"/>
                        </div>
                </div>

                <div id="add-msg" style="display:none;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:8px;"></div>

                <button class="submit-btn" onclick="addMenuItem()">➕ Add to Menu</button>

            </div>
        </div>
    </div>

    <script src="../js/add_item.js"></script>

    </script>
</body>
</html>