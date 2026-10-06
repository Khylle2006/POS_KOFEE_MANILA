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
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

$orderType = trim($data['order_type'] ?? 'Dine In');
$items     = $data['items'] ?? [];

if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'error' => 'No items for checkout.']);
    exit;
}

if (!paymongo_is_configured() && !paymongo_is_demo()) {
    echo json_encode([
        'success' => false,
        'error'   => 'PayMongo API key is not configured. Please set PAYMONGO_SECRET_KEY in includes/config.local.php or use cash / manual e-wallet.'
    ]);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);

    // Fetch real product prices from database to defeat client-side price tampering
    $prodIds = array_unique(array_filter(array_map(fn($it) => (int)($it['id'] ?? 0), $items)));
    if (empty($prodIds)) {
        echo json_encode(['success' => false, 'error' => 'No valid products in cart.']);
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
    $lineItems = [];

    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        if (!isset($dbProducts[$pid])) {
            echo json_encode(['success' => false, 'error' => "Product #$pid is unavailable or discontinued."]);
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
            'name'     => $prod['name'],
        ];

        $lineItems[] = [
            'currency' => 'PHP',
            'amount'   => (int)round($real_price * 100),
            'name'     => trim($prod['name']) . ' (' . ucfirst($size) . ')',
            'quantity' => $qty
        ];
    }

    $total = round($computed_total, 2);
    if ($total <= 0) {
        echo json_encode(['success' => false, 'error' => 'Calculated order total must be greater than zero.']);
        exit;
    }

    $user_id = (int)$_SESSION['user_id'];
    $empStmt = $pdo->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
    $empStmt->execute([':uid' => $user_id]);
    $employee_id = $empStmt->fetchColumn() ?: null;

    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare("
        INSERT INTO orders (user_id, employee_id, total_amount, payment_method, status, payment_status, stock_deducted, ingredients_deducted_at, created_at, placed_at)
        VALUES (:uid, :eid, :total, 'paymongo', 'pending', 'pending', 1, NOW(), CURDATE(), NOW())
    ");
    $orderStmt->execute([
        ':uid'   => $user_id,
        ':eid'   => $employee_id,
        ':total' => $total
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, product_id, quantity, price, subtotal, size)
        VALUES (:order_id, :product_id, :qty, :price, :subtotal, :size)
    ");

    foreach ($validated_items as $item) {
        $itemStmt->execute([
            ':order_id'   => $orderId,
            ':product_id' => $item['id'],
            ':qty'        => $item['qty'],
            ':price'      => $item['price'],
            ':subtotal'   => $item['subtotal'],
            ':size'       => $item['size'],
        ]);
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
        ':order_id'       => $orderId,
        ':processed_by'   => $user_id,
        ':order_id_param' => $orderId,
    ]);

    $baseUrl = paymongo_get_base_url();

    $result = paymongo_request('checkout_sessions', [
        'billing' => [
            'name'  => 'Walk-in Customer #' . $orderId,
            'email' => 'pos@kofeemanila.ph'
        ],
        'send_email_receipt'   => false,
        'show_description'     => true,
        'description'          => "Kofee Manila Order #$orderId ($orderType)",
        'line_items'           => $lineItems,
        'payment_method_types' => ['gcash', 'paymaya', 'card'],
        'reference_number'     => (string)$orderId,
        'success_url'          => "$baseUrl/php/menu.php?paymongo_success=1&order_id=$orderId",
        'cancel_url'           => "$baseUrl/php/menu.php?paymongo_cancel=1&order_id=$orderId",
    ], 'POST');

    if (!$result['success'] || empty($result['data']['id'])) {
        throw new RuntimeException($result['error'] ?? 'Unable to create PayMongo checkout session.');
    }

    $sessionId   = $result['data']['id'];
    $checkoutUrl = $result['data']['attributes']['checkout_url'] ?? '';

    $updStmt = $pdo->prepare('UPDATE orders SET paymongo_session_id = :session_id WHERE id = :id');
    $updStmt->execute([':session_id' => $sessionId, ':id' => $orderId]);

    $pdo->commit();

    echo json_encode([
        'success'      => true,
        'order_id'     => $orderId,
        'session_id'   => $sessionId,
        'checkout_url' => $checkoutUrl,
        'is_demo'      => paymongo_is_demo()
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('create_paymongo_checkout error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}