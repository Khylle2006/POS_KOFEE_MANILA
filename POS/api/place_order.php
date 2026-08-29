<?php
require_once '../includes/db.php';
require_once '../includes/notifications.php';

header('Content-Type: application/json');

// Require login
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

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

// FIX: use PDO (was using $conn / mysqli)
try {
    $pdo = get_db();
    $pdo->beginTransaction();

    // Insert order — use session user_id, not hardcoded 1
    $user_id = (int)$_SESSION['user_id'];

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
        if (!isset($item['id'], $item['qty'], $item['price'])) {
            throw new Exception("Invalid item format");
        }

        $product_id = (int)$item['id'];
        $qty        = (int)$item['qty'];
        $price      = (float)$item['price'];
        $subtotal   = $price * $qty;

        $stmtItem->execute([
            ':order_id'   => $order_id,
            ':product_id' => $product_id,
            ':qty'        => $qty,
            ':price'      => $price,
            ':subtotal'   => $subtotal,
        ]);
    }

    $pdo->commit();

    $roles = ['admin', 'manager', 'staff', 'crew'];
    notify_roles($pdo, 'order:new:' . $order_id, 'order', 'New order received', 'Order #' . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ' is waiting.', 'pending_orders.php', $roles);

    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('place_order error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}