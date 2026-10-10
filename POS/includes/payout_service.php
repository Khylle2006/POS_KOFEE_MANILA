<?php
declare(strict_types=1);
require_once __DIR__ . '/order_service.php';

/** @param array<string,mixed> $payload @return array<string,mixed>|null */
function claim_transfer(PDO $pdo, string $key, string $entity, int $id, array $payload): ?array
{
    if ($pdo->inTransaction()) throw new LogicException('Transfer claims must commit before external requests.');
    $pdo->beginTransaction();
    try {
        $digest = hash('sha256', json_encode(canonical_payload($payload), JSON_THROW_ON_ERROR));
        $existing = $pdo->prepare('SELECT * FROM payment_attempts WHERE operation_key = ? FOR UPDATE');
        $existing->execute([$key]);
        $attempt = $existing->fetch();
        if ($attempt) {
            if ($attempt['entity_type'] !== $entity || (int)$attempt['entity_id'] !== $id || !hash_equals((string)$attempt['payload_hash'], $digest)) throw new SecurityFault('IDEMPOTENCY_CONFLICT', 'Transfer inputs changed. Review the existing payment.', 409);
            if ($attempt['response'] !== null) {
                $pdo->commit();
                return json_decode($attempt['response'], true, 64, JSON_THROW_ON_ERROR);
            }
            throw new SecurityFault('PAYMENT_OUTCOME_PENDING', 'This transfer needs reconciliation before another submission.', 409);
        }
        if ($entity === 'payslip') {
            $lock = $pdo->prepare('SELECT payment_status FROM payslips WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if ($lock->fetchColumn() === 'paid') throw new SecurityFault('PAYOUT_ALREADY_PAID', 'Payslip was already paid.', 409);
        } elseif (in_array($entity, ['invoice', 'invoice_checkout'], true)) {
            $lock = $pdo->prepare('SELECT total_amount, status FROM invoices WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $invoice = $lock->fetch();
            if (!$invoice || !in_array($invoice['status'], ['approved', 'matched', 'partially_paid'], true)) throw new SecurityFault('INVOICE_STATE_CONFLICT', 'Invoice is not payable.', 409);
            $existing = $pdo->prepare('SELECT 1 FROM payment_attempts WHERE operation_key = ?');
            $existing->execute([$key]);
            if (!$existing->fetchColumn()) {
                $paid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'completed'");
                $paid->execute([$id]);
                $held = $pdo->prepare("SELECT COALESCE(SUM(amount_centavos), 0) FROM payment_attempts WHERE entity_type IN ('invoice', 'invoice_checkout') AND entity_id = ? AND status IN ('processing', 'submitted', 'unknown')");
                $held->execute([$id]);
                if (($payload['amount_centavos'] ?? 0) > money_centavos($invoice['total_amount']) - money_centavos($paid->fetchColumn()) - (int)$held->fetchColumn()) throw new SecurityFault('INVOICE_BALANCE_CONFLICT', 'Invoice balance is paid or reserved by another transfer.', 409);
            }
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO payment_attempts (operation_key, entity_type, entity_id, status, payload_hash, amount_centavos) VALUES (?, ?, ?, 'processing', ?, ?)");
        $stmt->execute([$key, $entity, $id, $digest, $payload['amount_centavos'] ?? money_centavos($payload['amount'] ?? 0)]);
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare('SELECT * FROM payment_attempts WHERE operation_key = ? FOR UPDATE');
            $stmt->execute([$key]);
            $attempt = $stmt->fetch();
            if (!hash_equals((string)$attempt['payload_hash'], $digest)) throw new SecurityFault('IDEMPOTENCY_CONFLICT', 'Transfer inputs changed. Review the existing payment.', 409);
            if ($attempt['response'] !== null) {
                $pdo->commit();
                return json_decode($attempt['response'], true, 64, JSON_THROW_ON_ERROR);
            }
            throw new SecurityFault('PAYMENT_OUTCOME_PENDING', 'This transfer needs reconciliation before another submission.', 409);
        }
        security_audit($pdo, 'transfer_requested', $entity, $id, ['status' => 'processing']);
        $pdo->commit();
        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

/** @param array<string,mixed> $result */
function record_transfer_result(PDO $pdo, string $key, array $result): void
{
    $success = !empty($result['ok']);
    $unknown = !$success && !empty($result['unknown']);
    $status = $success ? (in_array($result['status'] ?? '', ['paid', 'succeeded'], true) ? 'paid' : 'submitted') : ($unknown ? 'unknown' : 'rejected');
    $pdo->prepare('UPDATE payment_attempts SET status = ?, provider_id = ?, response = ? WHERE operation_key = ?')
        ->execute([$status, $result['transfer_id'] ?? null, $unknown ? null : json_encode($result, JSON_THROW_ON_ERROR), $key]);
    security_audit($pdo, 'transfer_result', 'payment_attempt', null, ['status' => $status]);
}

/** @param array<string,mixed> $cfg @param array<string,mixed> $item @return array<string,mixed> */
function execute_paymongo_transfer_call(array $cfg, array $item): array
{
    $pdo = get_db();
    $id = (int)($item['payslip_id'] ?? 0);
    if ($id <= 0) throw new SecurityFault('PAYOUT_INVALID', 'A payslip is required for transfer.');
    $key = 'payslip-transfer-' . $id;
    require_once __DIR__ . '/private_credentials.php';
    $stmt = $pdo->prepare('SELECT account_number_encrypted FROM employee_payment_details WHERE employee_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$item['employee_id']]);
    if (($item['payout_destination'] ?? '') === 'bank') $item['account_number'] = decrypt_private_value((string)$stmt->fetchColumn());
    $item['idempotency_key'] = $key;
    $payload = array_intersect_key($item, array_flip(['amount', 'recipient_name', 'payout_destination', 'bank_code', 'account_number', 'ewallet_mobile_number']));
    $previous = claim_transfer($pdo, $key, 'payslip', $id, $payload);
    if ($previous !== null) return $previous;
    $result = perform_paymongo_transfer_call($cfg, $item);
    record_transfer_result($pdo, $key, $result);
    return $result;
}

/** @return array<string,mixed> */
function submit_paymongo_payout_batch(PDO $pdo, int $batch_id, int $approver_id): array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM paymongo_payout_batches WHERE id = ? FOR UPDATE');
        $stmt->execute([$batch_id]);
        $batch = $stmt->fetch();
        if (!$batch) throw new SecurityFault('BATCH_NOT_FOUND', 'Batch not found.', 404);
        if (in_array($batch['status'], ['paid', 'processing'], true)) {
            $pdo->commit();
            return ['ok' => true, 'batch_id' => $batch_id, 'status' => $batch['status'], 'message' => 'Batch has already been submitted.'];
        }
        $cfg = get_paymongo_disbursement_config($pdo);
        if (!$cfg['enabled']) throw new SecurityFault('PAYOUT_DISABLED', 'Disbursement is disabled.', 503);
        $pdo->prepare("UPDATE paymongo_payout_batches SET status = 'processing', approved_by = ?, approved_at = NOW(), submitted_at = NOW() WHERE id = ?")
            ->execute([$approver_id, $batch_id]);
        $stmt = $pdo->prepare("SELECT * FROM paymongo_payout_items WHERE batch_id = ? AND status <> 'paid' ORDER BY id");
        $stmt->execute([$batch_id]);
        $items = $stmt->fetchAll();
        security_audit($pdo, 'payout_batch_submitted', 'payout_batch', $batch_id);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    foreach ($items as $item) {
        $result = execute_paymongo_transfer_call($cfg, $item);
        $paid = !empty($result['ok']) && in_array($result['status'] ?? '', ['paid', 'succeeded'], true);
        $status = $paid ? 'paid' : (!empty($result['unknown']) || !empty($result['ok']) ? 'processing' : 'failed');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE paymongo_payout_items SET status = ?, paymongo_transfer_id = ?, error_message = ?, attempt_count = attempt_count + 1, last_attempt_at = NOW(), paid_at = ? WHERE id = ?')
                ->execute([$status, $result['transfer_id'] ?? null, $result['error'] ?? null, $paid ? date('Y-m-d H:i:s') : null, $item['id']]);
            $pdo->prepare('UPDATE payslips SET payment_status = ?, transfer_id = ?, released_by = ?, paid_at = ? WHERE id = ?')
                ->execute([$paid ? 'paid' : ($status === 'processing' ? 'pending' : 'failed'), $result['transfer_id'] ?? null, $approver_id, $paid ? date('Y-m-d H:i:s') : null, $item['payslip_id']]);
            if ($paid) apply_payslip_loan_repayments($pdo, $item);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM paymongo_payout_items WHERE batch_id = ? AND status <> 'paid'");
    $stmt->execute([$batch_id]);
    $status = (int)$stmt->fetchColumn() === 0 ? 'paid' : 'processing';
    $pdo->prepare('UPDATE paymongo_payout_batches SET status = ?, completed_at = ? WHERE id = ?')->execute([$status, $status === 'paid' ? date('Y-m-d H:i:s') : null, $batch_id]);
    sync_period_payment_status($pdo, (int)$batch['period_id']);
    return ['ok' => true, 'batch_id' => $batch_id, 'status' => $status, 'message' => 'Batch submitted. Pending outcomes will be confirmed by signed callbacks.'];
}
