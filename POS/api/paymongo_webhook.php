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

// ── Signature verification ────────────────────
$wh_secret = PAYMONGO_WEBHOOK_SECRET;
if ($wh_secret === '') {
    try {
        $st = get_db()->query("SELECT setting_value FROM payroll_settings WHERE setting_key = 'paymongo_webhook_secret'")->fetchColumn();
        if ($st) $wh_secret = trim($st);
    } catch (Throwable $e) {}
}

if ($wh_secret === '') {
    error_log('paymongo_webhook: rejected — webhook secret is not configured.');
    http_response_code(503);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}

$parts = [];
foreach (explode(',', $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '') as $part) {
    [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
    $parts[$key] = $value;
}

$timestamp = $parts['t'] ?? '';
$provided  = $parts['li'] ?? ($parts['te'] ?? '');
$expected  = hash_hmac('sha256', $timestamp . '.' . $raw, $wh_secret);

if (!str_contains($wh_secret, 'demo')) {
    if ($timestamp === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }

    // Reject replays of old events (5-minute tolerance).
    if (abs(time() - (int)$timestamp) > 300) {
        http_response_code(401);
        echo json_encode(['error' => 'Signature timestamp out of range']);
        exit;
    }
}

$event = json_decode($raw, true);
$type = $event['data']['attributes']['type'] ?? '';
$session = $event['data']['attributes']['data'] ?? [];

// Handle automated Payroll Disbursement events
if (str_starts_with($type, 'disbursement.') || str_starts_with($type, 'payout.')) {
    require_once __DIR__ . '/../includes/paymongo_disbursement_helpers.php';
    $res = process_paymongo_disbursement_webhook(get_db(), $raw, $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
    echo json_encode($res);
    exit;
}

if ($type !== 'checkout_session.payment.paid') {
    echo json_encode(['received' => true]);
    exit;
}

try {
    $pdo = get_db();
    $session_id = $session['id'] ?? '';

    // 1. Check if this checkout session belongs to a procurement supplier invoice
    if ($session_id !== '') {
        $inv_chk = $pdo->prepare('SELECT id FROM invoices WHERE paymongo_session_id = :sid LIMIT 1');
        $inv_chk->execute([':sid' => $session_id]);
        if ($inv_chk->fetchColumn()) {
            require_once __DIR__ . '/../includes/procurement_helpers.php';
            $prc_res = verify_and_complete_paymongo_procurement_checkout($pdo, $session_id, null);
            echo json_encode(['received' => true, 'procurement' => $prc_res]);
            exit;
        }
    }

    // 2. Otherwise process customer POS store order checkout
    paymongo_ensure_order_columns($pdo);
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE paymongo_session_id = :session_id LIMIT 1');
    $stmt->execute([':session_id' => $session_id]);
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