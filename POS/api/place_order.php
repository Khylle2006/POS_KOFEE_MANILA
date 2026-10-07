<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ingredient_deduction.php';

header('Content-Type: application/json');

// Require login
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

$total   = (float)($data['total']          ?? 0);
$payment = $data['payment_method']          ?? 'cash';
$items   = $data['items']                   ?? [];

if (empty($items) || !is_array($items)) {
    echo json_encode(["success" => false, "error" => "No items to save"]);
    exit;
}

try {
    $pdo = get_db();

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
            echo json_encode(["success" => false, "error" => "Product #$pid is unavailable or deleted."]);
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

    $total = round($computed_total, 2);

    $pdo->beginTransaction();

    // Insert order — use session user_id
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

    deduct_order_ingredients($pdo, $order_id, $user_id);

    $pdo->commit();

    // Invalidate caches dependent on real-time order data
    require_once __DIR__ . '/../includes/cache.php';
    cache_delete_pattern('analytics_');
    cache_delete_pattern('sidebar_badges');

    echo json_encode(["success" => true, "order_id" => $order_id]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('place_order error: ' . $e->getMessage());
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}