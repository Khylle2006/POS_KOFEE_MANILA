<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode([
        "success" => false,
        "error" => "No data received"
    ]);
    exit;
}

try {
    $pdo = get_db();

    $total = $data['total'] ?? 0;
    $payment = $data['payment_method'] ?? 'cash';
    $items = $data['items'] ?? [];

    
    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, total_amount, payment_method, status, created_at)
        VALUES (1, ?, ?, 'completed', NOW())
    ");

    $stmt->execute([$total, $payment]);

    $order_id = $pdo->lastInsertId();

    
    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $stmtItem->execute([
            $order_id,
            $item['id'],
            $item['qty'],
            $item['price'],
            $item['price'] * $item['qty']
        ]);
    }

    echo json_encode([
        "success" => true,
        "order_id" => $order_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}