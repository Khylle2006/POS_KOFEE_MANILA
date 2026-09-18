<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/paymongo.php';

header('Content-Type: application/json');
$raw = file_get_contents('php://input');
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

if (PAYMONGO_WEBHOOK_SECRET !== '') {
    $parts = [];
    foreach (explode(',', $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '') as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[$key] = $value;
    }
    $timestamp = $parts['t'] ?? '';
    $provided = $parts['li'] ?? ($parts['te'] ?? '');
    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, PAYMONGO_WEBHOOK_SECRET);
    if ($timestamp === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($raw, true);
$type = $event['data']['attributes']['type'] ?? '';
$session = $event['data']['attributes']['data'] ?? [];
if ($type !== 'checkout_session.payment.paid') {
    echo json_encode(['received' => true]);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE paymongo_session_id = :session_id LIMIT 1');
    $stmt->execute([':session_id' => $session['id'] ?? '']);
    $order = $stmt->fetch();
    if ($order && $order['status'] !== 'completed') {
        $pdo->beginTransaction();
        if (paymongo_order_has_column($pdo, 'payment_status')) {
            $pdo->prepare("UPDATE orders SET status = 'completed', payment_status = 'paid' WHERE id = :id")
                ->execute([':id' => $order['id']]);
        } else {
            $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = :id")
                ->execute([':id' => $order['id']]);
        }
        $pdo->commit();
    }
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('paymongo_webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Webhook processing failed']);
}