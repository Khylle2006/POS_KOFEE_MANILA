<?php
// save_order.php
// FIX: was using $conn (mysqli); converted to PDO.
// FIX: was trying to insert 'items' as a raw column which doesn't exist;
//      now properly inserts into orders + order_items tables.
require_once '../includes/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || empty($data['items'])) {
    echo json_encode(["success" => false, "error" => "No items received"]);
    exit;
}

$items   = $data['items'];
$total   = (float)($data['total']   ?? 0);
$payment = $data['payment_method']  ?? 'cash';
$user_id = (int)$_SESSION['user_id'];

try {
    $pdo = get_db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, total_amount, payment_method, status, created_at)
        VALUES (:uid, :total, :payment, 'completed', NOW())
    ");
    $stmt->execute([
        ':uid'     => $user_id,
        ':total'   => $total,
        ':payment' => $payment,
    ]);

    $order_id = (int)$pdo->lastInsertId();

    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
        VALUES (:order_id, :product_id, :qty, :price, :subtotal)
    ");

    foreach ($items as $item) {
        $product_id = (int)$item['id'];
        $qty        = (int)$item['qty'];
        $price      = (float)$item['price'];

        $stmtItem->execute([
            ':order_id'   => $order_id,
            ':product_id' => $product_id,
            ':qty'        => $qty,
            ':price'      => $price,
            ':subtotal'   => $price * $qty,
        ]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('save_order error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}