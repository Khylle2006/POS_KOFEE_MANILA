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

$orderId = (int)($_GET['order_id'] ?? 0);
if (isset($_GET['simulate'])) throw new SecurityFault('SIMULATION_DISABLED', 'Payment simulation is unavailable.', 403);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException('Order #' . $orderId . ' not found.');
    }

    if ((int)$order['user_id'] !== (int)$_SESSION['user_id'] && !has_permission('orders.history')) {
        throw new SecurityFault('PERMISSION_DENIED', 'Permission denied.', 403);
    }
    $isFinalPaid = $order['payment_status'] === 'paid';

    // If order payment is pending with a PayMongo checkout session, query PayMongo to reconcile status
    if (!$isFinalPaid && ($order['payment_status'] ?? '') === 'pending' && !empty($order['paymongo_session_id'])) {
        require_once __DIR__ . '/../includes/payment_events.php';
        try {
            $pmRes = paymongo_request('checkout_sessions/' . rawurlencode($order['paymongo_session_id']));
            if (!empty($pmRes['success']) && !empty($pmRes['data'])) {
                $pdo->beginTransaction();
                try {
                    reconcile_checkout_session($pdo, $orderId, $pmRes['data']);
                    $pdo->commit();
                    invalidate_order_caches();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
                // Refresh order record
                $stmt->execute([':id' => $orderId]);
                $refreshed = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($refreshed) {
                    $order = $refreshed;
                    $isFinalPaid = ($order['payment_status'] === 'paid');
                }
            }
        } catch (Throwable $e) {
            // Keep going if provider reconciliation experiences network issues
        }
    }

    // Fetch full order items for receipt
    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.quantity, oi.price, oi.subtotal, oi.size, p.name, p.image_path
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = :order_id
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $itemsRaw = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(function($it) {
        return [
            'id'       => (int)$it['product_id'],
            'name'     => $it['name'] ?? ('Product #' . $it['product_id']),
            'size'     => $it['size'],
            'qty'      => (int)$it['quantity'],
            'price'    => (float)$it['price'],
            'subtotal' => (float)$it['subtotal'],
            'imageSrc' => $it['image_path'] ?? ''
        ];
    }, $itemsRaw);

    echo json_encode([
        'success'        => true,
        'paid'           => $isFinalPaid,
        'status'         => $order['status'],
        'payment_status' => $order['payment_status'] ?? '',
        'order_id'       => $orderId,
        'order'          => [
            'id'                => (int)$order['id'],
            'total'             => (float)$order['total_amount'],
            'payment_method'    => $order['payment_method'],
            'amount_tendered'   => $order['amount_tendered'] !== null ? (float)$order['amount_tendered'] : null,
            'change_amount'     => $order['change_amount'] !== null ? (float)$order['change_amount'] : null,
            'payment_reference' => $order['payment_reference'] ?? $order['paymongo_payment_id'] ?? '',
            'created_at'        => $order['created_at'],
            'placed_at'         => $order['placed_at'] ?? $order['created_at'],
            'items'             => $items
        ]
    ]);

} catch (Throwable $e) {
    safe_exception($e);
}
