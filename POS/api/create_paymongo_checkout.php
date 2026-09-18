<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
require_clocked_in_for_pos(true);

$data = json_decode(file_get_contents('php://input'), true);
$total = (float)($data['total'] ?? 0);
$orderType = trim($data['order_type'] ?? 'Dine In');
$items = $data['items'] ?? [];

if ($total <= 0 || !is_array($items) || !$items) {
    echo json_encode(['success' => false, 'error' => 'No items or invalid total for checkout.']);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);
    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare("INSERT INTO orders (user_id, total_amount, payment_method, status, stock_deducted, ingredients_deducted_at, created_at) VALUES (:user_id, :total, 'paymongo', 'pending', 1, NOW(), NOW())");
    $orderStmt->execute([':user_id' => (int)$_SESSION['user_id'], ':total' => $total]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal, size) VALUES (:order_id, :product_id, :qty, :price, :subtotal, :size)");
    $lineItems = [];
    foreach ($items as $item) {
        if (!isset($item['id'], $item['qty'], $item['price'])) throw new RuntimeException('Invalid item format.');
        $qty = max(1, (int)$item['qty']);
        $price = (float)$item['price'];
        $size = in_array($item['size'] ?? 'small', ['small', 'large'], true) ? $item['size'] : 'small';
        $itemStmt->execute([':order_id' => $orderId, ':product_id' => (int)$item['id'], ':qty' => $qty, ':price' => $price, ':subtotal' => $price * $qty, ':size' => $size]);
        $lineItems[] = ['currency' => 'PHP', 'amount' => (int)round($price * 100), 'name' => trim($item['name'] ?? ('Item #' . (int)$item['id'])) . ' (' . ucfirst($size) . ')', 'quantity' => $qty];
    }

    $logStmt = $pdo->prepare(<<<'SQL'
        INSERT INTO ingredient_usage_log (order_id, ingredient_id, used_qty, processed_by)
        SELECT :order_id, pi.ingredient_id, SUM(pi.qty_used * oi.quantity), :processed_by
        FROM order_items oi
        JOIN product_ingredients pi ON pi.product_id = oi.product_id AND pi.size = oi.size
        WHERE oi.order_id = :order_id_param
        GROUP BY pi.ingredient_id
        ON DUPLICATE KEY UPDATE used_qty = VALUES(used_qty)
    SQL);
    $logStmt->execute([
        ':order_id' => $orderId,
        ':processed_by' => (int)$_SESSION['user_id'],
        ':order_id_param' => $orderId,
    ]);

    $baseUrl = rtrim(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/POS_KOFEE_MANILA/POS', '/');
    $result = paymongo_request('checkout_sessions', [
        'billing' => ['name' => 'Walk-in Customer #' . $orderId, 'email' => 'pos@kofeemanila.ph'],
        'send_email_receipt' => false,
        'show_description' => true,
        'description' => "Kofee Manila Order #$orderId ($orderType)",
        'line_items' => $lineItems,
        'payment_method_types' => ['gcash', 'paymaya', 'card'],
        'reference_number' => (string)$orderId,
        'success_url' => "$baseUrl/php/menu.php?paymongo_success=1&order_id=$orderId",
        'cancel_url' => "$baseUrl/php/menu.php?paymongo_cancel=1&order_id=$orderId",
    ], 'POST');

    if (!$result['success'] || empty($result['data']['id']) || empty($result['data']['attributes']['checkout_url'])) {
        throw new RuntimeException($result['error'] ?? 'Unable to create PayMongo checkout.');
    }

    if (paymongo_order_has_column($pdo, 'paymongo_session_id')) {
        $pdo->prepare('UPDATE orders SET paymongo_session_id = :session_id WHERE id = :id')->execute([':session_id' => $result['data']['id'], ':id' => $orderId]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'order_id' => $orderId, 'checkout_url' => $result['data']['attributes']['checkout_url']]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('create_paymongo_checkout error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}