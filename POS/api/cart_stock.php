<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.new');

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
if ($rawInput === '' || $rawInput === false) {
    $rawInput = file_get_contents('php://stdin');
}
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$action = $data['action'] ?? '';
$pdo = get_db();

if ($action === 'deduct') {
    $product_id = (int)($data['product_id'] ?? 0);
    $size       = ($data['size'] ?? 'small') === 'large' ? 'large' : 'small';
    $qty        = max(1, (int)($data['qty'] ?? 1));

    if (!$product_id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Missing product_id']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch recipe ingredients for this product & size
        $recipeStmt = $pdo->prepare("
            SELECT pi.ingredient_id, pi.qty_used, i.name, i.unit, i.quantity
            FROM product_ingredients pi
            JOIN ingredients i ON i.id = pi.ingredient_id
            WHERE pi.product_id = :pid AND pi.size = :size
            FOR UPDATE
        ");
        $recipeStmt->execute([':pid' => $product_id, ':size' => $size]);
        $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

        // If product has no recipe, ignore ingredient deduction
        if (empty($ingredients)) {
            $pdo->commit();
            echo json_encode(['success' => true, 'deducted' => false]);
            exit;
        }

        // 2. Validate stock for all ingredients
        $shortages = [];
        foreach ($ingredients as $ing) {
            $needed = (float)$ing['qty_used'] * $qty;
            $available = (float)$ing['quantity'];
            if ($available < $needed) {
                $shortages[] = sprintf(
                    '%s (need %s %s, only %s %s available)',
                    $ing['name'],
                    rtrim(rtrim(number_format($needed, 2), '0'), '.'),
                    $ing['unit'],
                    rtrim(rtrim(number_format($available, 2), '0'), '.'),
                    $ing['unit']
                );
            }
        }

        if (!empty($shortages)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Cannot add item. Not enough stock for: ' . implode(', ', $shortages),
                'shortages' => $shortages
            ]);
            exit;
        }

        // 3. Deduct stock
        $deductStmt = $pdo->prepare("
            UPDATE ingredients
            SET quantity = quantity - :needed
            WHERE id = :id
        ");
        foreach ($ingredients as $ing) {
            $needed = (float)$ing['qty_used'] * $qty;
            $deductStmt->execute([
                ':needed' => $needed,
                ':id'     => (int)$ing['ingredient_id'],
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'deducted' => true]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'refund') {
    $product_id = (int)($data['product_id'] ?? 0);
    $size       = ($data['size'] ?? 'small') === 'large' ? 'large' : 'small';
    $qty        = max(1, (int)($data['qty'] ?? 1));

    if (!$product_id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Missing product_id']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $recipeStmt = $pdo->prepare("
            SELECT ingredient_id, qty_used
            FROM product_ingredients
            WHERE product_id = :pid AND size = :size
        ");
        $recipeStmt->execute([':pid' => $product_id, ':size' => $size]);
        $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($ingredients)) {
            $refundStmt = $pdo->prepare("
                UPDATE ingredients
                SET quantity = quantity + :restored
                WHERE id = :id
            ");
            foreach ($ingredients as $ing) {
                $restored = (float)$ing['qty_used'] * $qty;
                $refundStmt->execute([
                    ':restored' => $restored,
                    ':id'       => (int)$ing['ingredient_id'],
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'refunded' => true]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'refund_batch') {
    $items = $data['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => true, 'message' => 'No items to refund']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $recipeStmt = $pdo->prepare("
            SELECT ingredient_id, qty_used
            FROM product_ingredients
            WHERE product_id = :pid AND size = :size
        ");

        $refundStmt = $pdo->prepare("
            UPDATE ingredients
            SET quantity = quantity + :restored
            WHERE id = :id
        ");

        $aggregated = []; // ingredient_id => total qty to restore

        foreach ($items as $item) {
            $product_id = (int)($item['id'] ?? 0);
            $size       = ($item['size'] ?? 'small') === 'large' ? 'large' : 'small';
            $qty        = max(1, (int)($item['qty'] ?? 1));

            if (!$product_id) continue;

            $recipeStmt->execute([':pid' => $product_id, ':size' => $size]);
            $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ingredients as $ing) {
                $iid = (int)$ing['ingredient_id'];
                $restored = (float)$ing['qty_used'] * $qty;
                $aggregated[$iid] = ($aggregated[$iid] ?? 0) + $restored;
            }
        }

        foreach ($aggregated as $iid => $totalRestored) {
            $refundStmt->execute([
                ':restored' => $totalRestored,
                ':id'       => $iid,
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'restored_ingredients' => count($aggregated)]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
