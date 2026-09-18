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
try {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) throw new RuntimeException('Order not found.');

    if ($order['status'] !== 'completed' && paymongo_order_has_column($pdo, 'paymongo_session_id')) {
        $sessionId = $order['paymongo_session_id'] ?? '';
        if (!empty($sessionId)) {
            $result = paymongo_request('checkout_sessions/' . rawurlencode($sessionId));
            $paid = false;
            foreach (($result['data']['attributes']['payments'] ?? []) as $payment) {
                if (($payment['attributes']['status'] ?? '') === 'paid') {
                    $paid = true;
                    break;
                }
            }
            if ($paid) {
                $pdo->beginTransaction();
                if (paymongo_order_has_column($pdo, 'payment_status')) {
                    $pdo->prepare("UPDATE orders SET status = 'completed', payment_status = 'paid' WHERE id = :id")->execute([':id' => $orderId]);
                } else {
                    $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = :id")->execute([':id' => $orderId]);
                }
                $pdo->commit();
                $order['status'] = 'completed';
            }
        }
    }
    echo json_encode(['success' => true, 'paid' => $order['status'] === 'completed', 'status' => $order['status'], 'order_id' => $orderId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('check_paymongo_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}