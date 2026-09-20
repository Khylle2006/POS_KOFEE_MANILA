<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/ingredient_deduction.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}
require_clocked_in_for_pos(true);

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    echo json_encode(["success" => false, "error" => "Invalid JSON input"]);
    exit;
}

$total     = (float)($data['total']             ?? 0);
$payment   = trim($data['payment_method']       ?? 'cash');
$tendered  = isset($data['amount_tendered']) ? (float)$data['amount_tendered'] : null;
$change    = isset($data['change_amount']) ? (float)$data['change_amount'] : null;
$reference = trim($data['payment_reference']     ?? '');
$items     = $data['items']                      ?? [];

if (empty($items)) {
    echo json_encode(["success" => false, "error" => "No items to save"]);
    exit;
}

if ($payment === 'cash' && $tendered !== null && $tendered < $total) {
    echo json_encode(["success" => false, "error" => "Amount tendered is less than order total."]);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);
    $pdo->beginTransaction();

    $user_id = (int)$_SESSION['user_id'];

    // Find linked employee ID if available
    $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
    $empStmt->execute([':uid' => $user_id]);
    $employee_id = $empStmt->fetchColumn() ?: null;

    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, employee_id, total_amount, payment_method, status, payment_status, amount_tendered, change_amount, payment_reference, stock_deducted, ingredients_deducted_at, created_at, placed_at)
        VALUES (:uid, :eid, :total, :payment, 'pending', 'paid', :tendered, :change, :ref, 1, NOW(), CURDATE(), NOW())
    ");
    $stmt->execute([
        ':uid'      => $user_id,
        ':eid'      => $employee_id,
        ':total'    => $total,
        ':payment'  => $payment,
        ':tendered' => $tendered,
        ':change'   => $change,
        ':ref'      => $reference !== '' ? $reference : null,
    ]);

    $order_id = (int)$pdo->lastInsertId();

    $stmtItem = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, subtotal, size)
        VALUES (:order_id, :product_id, :qty, :price, :subtotal, :size)
    ");

    foreach ($items as $item) {
        if (!isset($item['id'], $item['qty'], $item['price'])) {
            throw new Exception("Invalid item format");
        }

        $product_id = (int)$item['id'];
        $qty        = (int)$item['qty'];
        $price      = (float)$item['price'];
        $size       = in_array($item['size'] ?? 'small', ['small', 'large'], true) ? $item['size'] : 'small';
        $subtotal   = $price * $qty;

        $stmtItem->execute([
            ':order_id'   => $order_id,
            ':product_id' => $product_id,
            ':qty'        => $qty,
            ':price'      => $price,
            ':subtotal'   => $subtotal,
            ':size'       => $size,
        ]);
    }

    // Log the ingredient usage for this order
    $logStmt = $pdo->prepare(<<<'SQL'
        INSERT INTO ingredient_usage_log (order_id, ingredient_id, used_qty, processed_by)
        SELECT :order_id, pi.ingredient_id, SUM(pi.qty_used * oi.quantity), :processed_by
        FROM order_items oi
        JOIN product_ingredients pi
          ON pi.product_id = oi.product_id
         AND pi.size = oi.size
        WHERE oi.order_id = :order_id_param
        GROUP BY pi.ingredient_id
        ON DUPLICATE KEY UPDATE used_qty = VALUES(used_qty)
    SQL);
    $logStmt->execute([
        ':order_id'       => $order_id,
        ':processed_by'   => $user_id,
        ':order_id_param' => $order_id,
    ]);

    $pdo->commit();

    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('place_order error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}