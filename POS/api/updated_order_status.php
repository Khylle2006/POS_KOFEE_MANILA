<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment_events.php';
$data = request_data();
$id = positive_quantity($data['order_id'] ?? null, 2147483647);
$status = $data['status'] ?? '';
if (!in_array($status, ['completed', 'cancelled'], true)) {
    throw new SecurityFault('STATUS_INVALID', 'Choose completion or cancellation.');
}
$pdo = get_db();
if ($status === 'cancelled') {
    cancel_gateway_order($pdo, $id, null);
} else {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status, payment_status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order || $order['payment_status'] !== 'paid' || $order['status'] === 'cancelled') {
            throw new SecurityFault('ORDER_STATE_CONFLICT', 'Only a paid, active order can be completed.', 409);
        }
        $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([$id]);
        security_audit($pdo, 'order_completed', 'order', $id, ['status' => 'completed']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    invalidate_order_caches();
}
header('Content-Type: application/json');
echo json_encode(['success' => true]);
