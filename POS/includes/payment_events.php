<?php
declare(strict_types=1);
require_once __DIR__ . '/order_service.php';

function cancel_gateway_order(PDO $pdo, int $id, ?int $owner): void
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order || ($owner !== null && (int)$order['user_id'] !== $owner)) {
        throw new SecurityFault('ORDER_NOT_FOUND', 'Order not found.', 404);
    }
    if ($order['status'] === 'cancelled') return;
    if ($order['payment_status'] !== 'pending' || $order['payment_method'] !== 'paymongo') {
        throw new SecurityFault('ORDER_STATE_CONFLICT', 'A paid order needs a separate refund review.', 409);
    }
    $sessionId = (string)($order['paymongo_session_id'] ?? '');
    if (paymongo_is_demo()) {
        require_demo_payment();
        $expired = true;
    } else {
        // Only an authoritative expiration may release stock; a UI close is not a failed payment.
        $result = $sessionId !== '' ? paymongo_request('checkout_sessions/' . rawurlencode($sessionId) . '/expire', [], 'POST') : [];
        $expired = ($result['success'] ?? false) && ($result['data']['attributes']['status'] ?? '') === 'expired';
    }
    if (!$expired) {
        throw new SecurityFault('PAYMENT_OUTCOME_PENDING', 'Cancellation is awaiting payment-provider confirmation. Stock remains held.', 409);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT payment_status, status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        if ($current['payment_status'] === 'paid') {
            throw new SecurityFault('ORDER_STATE_CONFLICT', 'Payment was received. Request a refund review.', 409);
        }
        if ($current['status'] !== 'cancelled') {
            restore_order_stock($pdo, $id);
            $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")->execute([$id]);
            security_audit($pdo, 'order_cancelled', 'order', $id, ['status' => 'cancelled']);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    invalidate_order_caches();
}

/** @param array<string, mixed> $session */
function reconcile_checkout_session(PDO $pdo, int $id, array $session): void
{
    $attrs = $session['attributes'] ?? [];
    if (!is_array($attrs)) throw new SecurityFault('PROVIDER_PAYLOAD_INVALID', 'Invalid provider data.', 400);
    $stmt = $pdo->prepare('SELECT total_amount, paymongo_session_id, status, payment_status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order || ($session['id'] ?? '') !== $order['paymongo_session_id']) {
        throw new SecurityFault('PAYMENT_SESSION_MISMATCH', 'Checkout session does not match.', 409);
    }
    $paid = 0;
    $paymentId = '';
    foreach ($attrs['payments'] ?? [] as $payment) {
        $details = $payment['attributes'] ?? [];
        if (($details['status'] ?? '') === 'paid') {
            if (($details['currency'] ?? '') !== 'PHP' || !is_int($details['amount'] ?? null)) {
                throw new SecurityFault('PAYMENT_AMOUNT_INVALID', 'Payment amount is invalid.', 409);
            }
            $paid += $details['amount'];
            $paymentId = (string)($payment['id'] ?? '');
        }
    }
    if ($paid > 0) {
        if ($paid !== money_centavos($order['total_amount']) || $paymentId === '') {
            throw new SecurityFault('PAYMENT_AMOUNT_MISMATCH', 'Payment amount does not match the order.', 409);
        }
        finalize_order_payment($pdo, $id, $paymentId);
    } elseif (($attrs['status'] ?? '') === 'expired' && $order['payment_status'] === 'pending') {
        restore_order_stock($pdo, $id);
        $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'failed' WHERE id = ?")->execute([$id]);
    }
}

/** @return array<string, mixed> */
function process_payment_webhook(PDO $pdo, string $raw, string $signature, bool $disbursementOnly = false): array
{
    require_method('POST');
    $secret = app_setting($disbursementOnly ? 'PAYMONGO_DISBURSEMENT_WEBHOOK_SECRET' : 'PAYMONGO_WEBHOOK_SECRET');
    verify_paymongo_signature($raw, $signature, $secret, payment_mode());
    try { $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $exception) { throw new SecurityFault('JSON_INVALID', 'Invalid webhook JSON.', 400); }
    $eventId = $event['data']['id'] ?? '';
    $type = $event['data']['attributes']['type'] ?? '';
    $session = $event['data']['attributes']['data'] ?? [];
    if (!is_string($eventId) || !preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $eventId) || !is_string($type) || !is_array($session)) {
        throw new SecurityFault('WEBHOOK_INVALID', 'Invalid webhook event.', 400);
    }
    $pdo->beginTransaction();
    try {
        $hash = hash('sha256', $raw);
        $insert = $pdo->prepare('INSERT IGNORE INTO payment_webhook_events (event_id, payload_hash) VALUES (?, ?)');
        $insert->execute([$eventId, $hash]);
        if ($insert->rowCount() === 0) {
            $stmt = $pdo->prepare('SELECT payload_hash FROM payment_webhook_events WHERE event_id = ? FOR UPDATE');
            $stmt->execute([$eventId]);
            if (!hash_equals((string)$stmt->fetchColumn(), $hash)) throw new SecurityFault('EVENT_CONFLICT', 'Event content changed.', 409);
            $pdo->commit();
            return ['received' => true, 'duplicate' => true, 'ok' => true];
        }
        if (str_starts_with($type, 'disbursement.') || str_starts_with($type, 'payout.')) {
            require_once __DIR__ . '/paymongo_disbursement_helpers.php';
            $result = apply_paymongo_disbursement_event($pdo, $event);
            if (!($result['ok'] ?? false)) throw new SecurityFault('WEBHOOK_PROCESSING_FAILED', 'Transfer event could not be processed.', 503);
        } elseif (!$disbursementOnly && $type === 'checkout_session.payment.paid') {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE paymongo_session_id = ? LIMIT 1');
            $stmt->execute([(string)($session['id'] ?? '')]);
            $id = $stmt->fetchColumn();
            if ($id) {
                reconcile_checkout_session($pdo, (int)$id, $session);
            } else {
                require_once __DIR__ . '/invoice_payment_service.php';
                $result = reconcile_invoice_checkout($pdo, $session);
                if (!($result['ok'] ?? false)) throw new SecurityFault('WEBHOOK_PROCESSING_FAILED', 'Invoice event could not be processed.', 503);
            }
        }
        $pdo->commit();
        invalidate_order_caches();
        return ['received' => true, 'ok' => true];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
