<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$rawInput = file_get_contents('php://input');
if ($rawInput === '' || $rawInput === false) {
    $rawInput = file_get_contents('php://stdin');
}
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    echo json_encode(["success" => false, "error" => "Invalid JSON input"]);
    exit;
}

$total   = (float)($data['total']          ?? 0);
$payment = $data['payment_method']          ?? 'cash';
$items   = $data['items']                   ?? [];

if (empty($items)) {
    echo json_encode(["success" => false, "error" => "No items to save"]);
    exit;
}

try {
    $pdo = get_db();
    $pdo->beginTransaction();

    $user_id = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, total_amount, payment_method, status, created_at)
        VALUES (:uid, :total, :payment, 'pending', NOW())
    ");
    $stmt->execute([
        ':uid'     => $user_id,
        ':total'   => $total,
        ':payment' => $payment,
    ]);

    $order_id = (int)$pdo->lastInsertId();

    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, size, quantity, price, subtotal)
        VALUES (:order_id, :product_id, :size, :qty, :price, :subtotal)
    ");

    // Ingredients needed, per product's product_ingredients recipe.
    // Keyed by inventory_id so the same ingredient used by multiple
    // cart lines (or multiple recipe rows) is only checked/deducted once,
    // for the correct combined amount.
    $recipeStmt = $pdo->prepare("
        SELECT ingredient_id, qty_used
        FROM product_ingredients
        WHERE product_id = :pid AND size = :size
    ");

    $required = []; // ingredient_id => total qty required for this whole order

    foreach ($items as $item) {
        if (!isset($item['id'], $item['qty'], $item['price'])) {
            throw new Exception("Invalid item format");
        }

        $product_id = (int)$item['id'];
        $qty        = (int)$item['qty'];
        $price      = (float)$item['price'];
        $size       = ($item['size'] ?? 'small') === 'large' ? 'large' : 'small';
        $subtotal   = $price * $qty;

        if ($qty <= 0) {
            throw new Exception("Invalid quantity for item #$product_id");
        }

        $stmtItem->execute([
            ':order_id'   => $order_id,
            ':product_id' => $product_id,
            ':size'       => $size,
            ':qty'        => $qty,
            ':price'      => $price,
            ':subtotal'   => $subtotal,
        ]);

        // Pull this product's recipe for the ordered size. If nothing is
        // defined yet, it simply doesn't consume any tracked ingredient
        // (so checkout still works for items without a recipe set up).
        $recipeStmt->execute([':pid' => $product_id, ':size' => $size]);
        $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ingredients as $ing) {
            $inventoryId = (int)$ing['ingredient_id'];
            $needed      = (float)$ing['qty_used'] * $qty;
            $required[$inventoryId] = ($required[$inventoryId] ?? 0) + $needed;
        }
    }

    $pre_deducted = !empty($data['pre_deducted']);

    // Lock and validate every required ingredient in one pass before
    // deducting anything, so a shortfall on the last item rolls back
    // the whole checkout rather than leaving a partial deduction.
    // If items were already deducted on click into the cart, skip second deduction.
    if (!$pre_deducted && !empty($required)) {
        $ids          = array_keys($required);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $lockStmt = $pdo->prepare("
            SELECT id, name, quantity, unit
            FROM ingredients
            WHERE id IN ($placeholders)
            FOR UPDATE
        ");
        $lockStmt->execute($ids);
        $onHand = [];
        foreach ($lockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $onHand[(int)$row['id']] = $row;
        }

        $shortages = [];
        foreach ($required as $inventoryId => $needed) {
            $row = $onHand[$inventoryId] ?? null;
            if (!$row) {
                throw new Exception("An ingredient in this order's recipe no longer exists.");
            }
            if ((float)$row['quantity'] < $needed) {
                $shortages[] = sprintf(
                    '%s (need %s %s, have %s %s)',
                    $row['name'],
                    rtrim(rtrim(number_format($needed, 2), '0'), '.'),
                    $row['unit'],
                    rtrim(rtrim(number_format((float)$row['quantity'], 2), '0'), '.'),
                    $row['unit']
                );
            }
        }

        if (!empty($shortages)) {
            throw new Exception('Not enough stock for: ' . implode(', ', $shortages));
        }

        $deductStmt = $pdo->prepare("
            UPDATE ingredients
            SET quantity = quantity - :needed
            WHERE id = :id
        ");
        foreach ($required as $inventoryId => $needed) {
            $deductStmt->execute([
                ':needed' => $needed,
                ':id'     => $inventoryId,
            ]);
        }
    }

    $pdo->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")
        ->execute([':id' => $order_id]);

    $pdo->commit();

    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('checkout error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}