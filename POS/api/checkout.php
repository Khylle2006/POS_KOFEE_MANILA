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
$reference = trim($data['payment_reference']     ?? '');
$items     = $data['items']                      ?? [];

if (empty($items) || !is_array($items)) {
    echo json_encode(["success" => false, "error" => "No items to save"]);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);

    // Fetch real product prices from database to defeat client-side price tampering
    $prodIds = array_unique(array_filter(array_map(fn($it) => (int)($it['id'] ?? 0), $items)));
    if (empty($prodIds)) {
        echo json_encode(["success" => false, "error" => "No valid products in cart."]);
        exit;
    }
    $inClause = implode(',', array_fill(0, count($prodIds), '?'));
    $pStmt = $pdo->prepare("SELECT id, name, price_small, price_large, price FROM products WHERE id IN ($inClause) AND is_deleted = 0");
    $pStmt->execute(array_values($prodIds));
    $dbProducts = [];
    foreach ($pStmt->fetchAll() as $p) {
        $dbProducts[(int)$p['id']] = $p;
    }

    $computed_total = 0.0;
    $validated_items = [];
    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        if (!isset($dbProducts[$pid])) {
            echo json_encode(["success" => false, "error" => "Product #$pid is unavailable or discontinued."]);
            exit;
        }
        $prod = $dbProducts[$pid];
        $size = in_array($item['size'] ?? 'small', ['small', 'large'], true) ? $item['size'] : 'small';
        $real_price = ($size === 'large')
            ? ((float)$prod['price_large'] > 0 ? (float)$prod['price_large'] : (float)$prod['price_small'])
            : ((float)$prod['price_small'] > 0 ? (float)$prod['price_small'] : (float)$prod['price']);

        $qty = max(1, (int)($item['qty'] ?? 1));
        $subtotal = $real_price * $qty;
        $computed_total += $subtotal;

        $validated_items[] = [
            'id'       => $pid,
            'qty'      => $qty,
            'price'    => $real_price,
            'subtotal' => $subtotal,
            'size'     => $size,
        ];
    }

    $total  = round($computed_total, 2);
    $change = ($tendered !== null && $tendered >= $total) ? round($tendered - $total, 2) : 0.0;

    if ($payment === 'cash' && $tendered !== null && $tendered < $total) {
        echo json_encode(["success" => false, "error" => "Amount tendered is less than order total."]);
        exit;
    }

    $pdo->beginTransaction();

    $user_id = (int)$_SESSION['user_id'];

    // Find linked employee ID if available
    $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
    $empStmt->execute([':uid' => $user_id]);
    $employee_id = $empStmt->fetchColumn() ?: null;

    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, employee_id, total_amount, payment_method, status, payment_status, amount_tendered, change_amount, payment_reference, stock_deducted, ingredients_deducted_at, created_at, placed_at)
        VALUES (:uid, :eid, :total, :payment, 'pending', 'paid', :tendered, :change, :ref, 0, NULL, CURDATE(), NOW())
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

    foreach ($validated_items as $item) {
        $stmtItem->execute([
            ':order_id'   => $order_id,
            ':product_id' => $item['id'],
            ':qty'        => $item['qty'],
            ':price'      => $item['price'],
            ':subtotal'   => $item['subtotal'],
            ':size'       => $item['size'],
        ]);
    }

    // Atomically validate inventory stock, deduct ingredients per recipe, and record usage
    deduct_order_ingredients($pdo, $order_id, $user_id);

    $pdo->commit();

    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('place_order error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}