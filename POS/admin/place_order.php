<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid JSON input"
    ]);
    exit;
}

$total = (float)($data['total'] ?? 0);
$payment = $data['payment_method'] ?? 'unknown';
$items = $data['items'] ?? [];

if (empty($items)) {
    echo json_encode([
        "success" => false,
        "error" => "No items to save"
    ]);
    exit;
}

$conn->begin_transaction();

try {

   
    $stmt = $conn->prepare("
        INSERT INTO orders (user_id, total_amount, payment_method, status, created_at)
        VALUES (?, ?, ?, 'completed', NOW())
    ");

    if (!$stmt) {
        throw new Exception("ORDER PREPARE FAILED: " . $conn->error);
    }

    $user_id = 1; 
    $stmt->bind_param("ids", $user_id, $total, $payment);

    if (!$stmt->execute()) {
        throw new Exception("ORDER INSERT FAILED: " . $stmt->error);
    }

    $order_id = $stmt->insert_id;

  
    $stmtItem = $conn->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmtItem) {
        throw new Exception("ITEM PREPARE FAILED: " . $conn->error);
    }

    foreach ($items as $item) {

        if (!isset($item['id'], $item['qty'], $item['price'])) {
            throw new Exception("INVALID ITEM FORMAT");
        }

        $product_id = (int)$item['id'];
        $qty = (int)$item['qty'];
        $price = (float)$item['price'];
        $subtotal = $price * $qty;

        $stmtItem->bind_param(
            "iiidd",
            $order_id,
            $product_id,
            $qty,
            $price,
            $subtotal
        );

        if (!$stmtItem->execute()) {
            throw new Exception("ITEM INSERT FAILED: " . $stmtItem->error);
        }
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "order_id" => $order_id
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}