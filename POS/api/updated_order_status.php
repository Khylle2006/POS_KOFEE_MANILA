<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.pending');

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
if ($rawInput === '' || $rawInput === false) {
    $rawInput = file_get_contents('php://stdin');
}
$data   = json_decode($rawInput, true);
$id     = (int)($data['order_id'] ?? 0);
$status = $data['status'] ?? '';

$allowed = ['pending', 'completed', 'cancelled'];

if (!$id || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $pdo = get_db();
    $pdo->beginTransaction();

    // Fetch current order state
    $orderStmt = $pdo->prepare("SELECT status, stock_deducted FROM orders WHERE id = :id FOR UPDATE");
    $orderStmt->execute([':id' => $id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    $currentStatus = $order['status'];
    $stockDeducted = (int)$order['stock_deducted'];

    // If changing to 'cancelled' and stock was deducted, refund ingredients
    if ($status === 'cancelled' && $stockDeducted === 1) {
        $itemsStmt = $pdo->prepare("
            SELECT product_id, size, quantity 
            FROM order_items 
            WHERE order_id = :id
        ");
        $itemsStmt->execute([':id' => $id]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

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

        foreach ($orderItems as $item) {
            $pid = (int)$item['product_id'];
            $sz  = ($item['size'] ?? 'small') === 'large' ? 'large' : 'small';
            $qty = (int)$item['quantity'];

            $recipeStmt->execute([':pid' => $pid, ':size' => $sz]);
            $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ingredients as $ing) {
                $restored = (float)$ing['qty_used'] * $qty;
                $refundStmt->execute([
                    ':restored' => $restored,
                    ':id'       => (int)$ing['ingredient_id'],
                ]);
            }
        }

        $stockDeducted = 0;
    } 
    // If moving back from 'cancelled' to 'pending' or 'completed' and stock was previously refunded
    else if ($currentStatus === 'cancelled' && in_array($status, ['pending', 'completed']) && $stockDeducted === 0) {
        $itemsStmt = $pdo->prepare("
            SELECT product_id, size, quantity 
            FROM order_items 
            WHERE order_id = :id
        ");
        $itemsStmt->execute([':id' => $id]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $recipeStmt = $pdo->prepare("
            SELECT ingredient_id, qty_used 
            FROM product_ingredients 
            WHERE product_id = :pid AND size = :size
        ");

        $deductStmt = $pdo->prepare("
            UPDATE ingredients 
            SET quantity = quantity - :needed 
            WHERE id = :id
        ");

        foreach ($orderItems as $item) {
            $pid = (int)$item['product_id'];
            $sz  = ($item['size'] ?? 'small') === 'large' ? 'large' : 'small';
            $qty = (int)$item['quantity'];

            $recipeStmt->execute([':pid' => $pid, ':size' => $sz]);
            $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ingredients as $ing) {
                $needed = (float)$ing['qty_used'] * $qty;
                $deductStmt->execute([
                    ':needed' => $needed,
                    ':id'     => (int)$ing['ingredient_id'],
                ]);
            }
        }

        $stockDeducted = 1;
    }

    $stmt = $pdo->prepare("UPDATE orders SET status = :status, stock_deducted = :sd WHERE id = :id");
    $stmt->execute([':status' => $status, ':sd' => $stockDeducted, ':id' => $id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_order_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}