<?php
declare(strict_types=1);
require_once __DIR__ . '/payout_service.php';

/** @return array<string,mixed> */
function submit_invoice_checkout(PDO $pdo, int $invoiceId, float $amount, int $actor): array
{
    if (!paymongo_is_configured() && !paymongo_is_demo()) throw new SecurityFault('PAYMENT_NOT_CONFIGURED', 'Checkout is unavailable.', 503);
    $requestKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? '';
    if (!is_string($requestKey) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $requestKey)) throw new SecurityFault('IDEMPOTENCY_KEY_REQUIRED', 'A checkout operation key is required.', 400);
    $cents = money_centavos(number_format($amount, 2, '.', ''));
    if ($cents <= 0) throw new SecurityFault('AMOUNT_INVALID', 'Payment must be positive.');
    $key = 'invoice-checkout-' . hash('sha256', $actor . ':' . $requestKey);
    $previous = claim_transfer($pdo, $key, 'invoice_checkout', $invoiceId, ['amount_centavos' => $cents]);
    if ($previous !== null) return $previous;
    $base = app_url();
    if (paymongo_is_demo()) {
        $session = ['id' => 'cs_demo_prc_' . bin2hex(random_bytes(12)), 'attributes' => ['checkout_url' => $base . '/php/payments.php?new_for_invoice=' . $invoiceId]];
        $result = ['success' => true, 'data' => $session];
    } else {
        $result = paymongo_request('checkout_sessions', ['description' => 'Supplier invoice #' . $invoiceId,
            'line_items' => [['amount' => $cents, 'currency' => 'PHP', 'quantity' => 1, 'name' => 'Invoice #' . $invoiceId]],
            'payment_method_types' => ['gcash', 'paymaya', 'card'], 'send_email_receipt' => false,
            'success_url' => $base . '/php/payments.php?paymongo_return=1&invoice_id=' . $invoiceId,
            'cancel_url' => $base . '/php/payments.php?new_for_invoice=' . $invoiceId], 'POST');
    }
    if (empty($result['success']) || empty($result['data']['id'])) {
        $unknown = !empty($result['unknown']) || !in_array($result['code'] ?? 0, [400, 401, 403, 404, 422], true);
        $response = ['ok' => false, 'unknown' => $unknown, 'error' => $unknown ? 'Checkout requires provider reconciliation.' : 'Provider rejected checkout.'];
        record_transfer_result($pdo, $key, $response);
        return $response;
    }
    $session = $result['data'];
    $response = ['ok' => true, 'session_id' => $session['id'], 'checkout_url' => $session['attributes']['checkout_url'] ?? '', 'is_demo' => paymongo_is_demo()];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE invoices SET paymongo_session_id = ? WHERE id = ?')->execute([$session['id'], $invoiceId]);
        $pdo->prepare("UPDATE payment_attempts SET status = 'submitted', provider_id = ?, response = ? WHERE operation_key = ?")
            ->execute([$session['id'], json_encode($response, JSON_THROW_ON_ERROR), $key]);
        security_audit($pdo, 'invoice_checkout_submitted', 'invoice', $invoiceId);
        $pdo->commit();
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
    return $response;
}

/** Accept only a signed webhook resource or a resource read from the provider API. @param array<string,mixed> $session @return array<string,mixed> */
function reconcile_invoice_checkout(PDO $pdo, array $session): array
{
    $sessionId = (string)($session['id'] ?? '');
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_attempts WHERE entity_type = 'invoice_checkout' AND provider_id = ? FOR UPDATE");
        $stmt->execute([$sessionId]); $attempt = $stmt->fetch();
        if (!$attempt) throw new SecurityFault('PAYMENT_ATTEMPT_MISSING', 'Checkout requires a recorded payment attempt.', 409);
        $stmt = $pdo->prepare('SELECT i.*, s.name AS supplier_name, po.po_number FROM invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN purchase_orders po ON po.id = i.po_id WHERE i.id = ? FOR UPDATE');
        $stmt->execute([$attempt['entity_id']]); $invoice = $stmt->fetch();
        if (!$invoice) throw new SecurityFault('INVOICE_NOT_FOUND', 'Invoice not found.', 404);
        $stmt = $pdo->prepare('SELECT id FROM payments WHERE paymongo_checkout_id = ?'); $stmt->execute([$sessionId]);
        if ($paymentId = $stmt->fetchColumn()) {
            if ($ownTransaction) $pdo->commit();
            return ['ok' => true, 'payment_id' => (int)$paymentId, 'already_completed' => true];
        }
        $paid = 0; $paymentId = '';
        foreach ($session['attributes']['payments'] ?? [] as $payment) {
            $attributes = $payment['attributes'] ?? [];
            if (($attributes['status'] ?? '') !== 'paid') continue;
            if (($attributes['currency'] ?? '') !== 'PHP' || !is_int($attributes['amount'] ?? null)) throw new SecurityFault('PAYMENT_AMOUNT_INVALID', 'Invalid payment amount.', 409);
            $paid += $attributes['amount']; $paymentId = (string)($payment['id'] ?? '');
        }
        if ($paid === 0) {
            if (($session['attributes']['status'] ?? '') === 'expired') $pdo->prepare("UPDATE payment_attempts SET status = 'cancelled' WHERE id = ?")->execute([$attempt['id']]);
            if ($ownTransaction) $pdo->commit();
            return ['ok' => true, 'pending' => ($session['attributes']['status'] ?? '') !== 'expired'];
        }
        if ($paid !== (int)$attempt['amount_centavos'] || $paymentId === '') throw new SecurityFault('PAYMENT_AMOUNT_MISMATCH', 'Payment amount does not match.', 409);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'completed'"); $stmt->execute([$invoice['id']]);
        $paidBefore = money_centavos($stmt->fetchColumn());
        if (in_array($attempt['status'], ['cancelled', 'failed', 'rejected'], true) || $invoice['status'] === 'cancelled' || $paidBefore + $paid > money_centavos($invoice['total_amount'])) {
            $pdo->prepare("UPDATE payment_attempts SET status = 'reconciliation_required' WHERE id = ?")->execute([$attempt['id']]);
            security_audit($pdo, 'invoice_payment_requires_review', 'invoice', (int)$invoice['id'], ['reason_code' => 'LATE_OR_EXCESS_PAYMENT']);
            if ($ownTransaction) $pdo->commit();
            return ['ok' => true, 'reconciliation_required' => true];
        }
        require_once __DIR__ . '/procurement_helpers.php';
        $voucher = generate_paymongo_procurement_voucher((int)$invoice['id'], $invoice['invoice_number'], $invoice['po_number'], $invoice['supplier_name'], $paid / 100, 'PayMongo Checkout', 'Verified provider', $paymentId, 'Verified PayMongo Gateway');
        $pdo->prepare("INSERT INTO payments (invoice_id, po_id, amount, payment_date, payment_method, paying_account, reference_no, paymongo_checkout_id, paymongo_channel, receipt_attachment_path, receipt_file_name, receipt_file_size, receipt_file_type, notes, paid_by, status, supplier_confirmation_status, scheduled_at, completed_at) VALUES (?, ?, ?, CURDATE(), 'online', 'PayMongo', ?, ?, 'checkout_session', ?, ?, ?, ?, 'Verified gateway payment', ?, 'completed', 'awaiting_confirmation', NOW(), NOW())")
            ->execute([$invoice['id'], $invoice['po_id'], money_decimal($paid), $paymentId, $sessionId, $voucher['path'], $voucher['name'], $voucher['size'], $voucher['type'], $invoice['uploaded_by']]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$paidBefore + $paid === money_centavos($invoice['total_amount']) ? 'paid' : 'partially_paid', $invoice['id']]);
        $pdo->prepare("UPDATE payment_attempts SET status = 'paid' WHERE id = ?")->execute([$attempt['id']]);
        security_audit($pdo, 'invoice_payment_confirmed', 'payment', $id, ['status' => 'paid']);
        if ($ownTransaction) $pdo->commit();
        return ['ok' => true, 'payment_id' => $id, 'amount' => $paid / 100, 'message' => 'Online payment verified successfully!'];
    } catch (Throwable $exception) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}
