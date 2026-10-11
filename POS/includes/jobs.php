<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** @param array<string, mixed> $payload */
function enqueue_job(PDO $pdo, string $key, string $type, array $payload): void
{
    $pdo->prepare('INSERT IGNORE INTO background_jobs (job_key, job_type, payload) VALUES (?, ?, ?)')
        ->execute([$key, $type, encode_job_payload($type, $payload)]);
}

/** @param array<string, mixed> $job */
function perform_background_job(PDO $pdo, array $job): void
{
    $payload = decode_job_payload($job['job_type'], $job['payload']);
    if ($job['job_type'] === 'email') {
        require_once __DIR__ . '/mailer.php';
        $result = send_kofee_email((string)$payload['to'], (string)($payload['name'] ?? ''), (string)$payload['subject'], (string)$payload['html'], (string)($payload['alt'] ?? ''));
        if (empty($result['sent'])) throw new SecurityFault('EMAIL_DELIVERY_FAILED', 'Email delivery failed.', 503);
    } elseif ($job['job_type'] === 'reconcile_order') {
        require_once __DIR__ . '/payment_events.php';
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$payload['order_id']]);
        $order = $stmt->fetch();
        if (!$order || $order['payment_status'] !== 'pending') return;
        if (empty($order['paymongo_session_id'])) throw new SecurityFault('MANUAL_RECONCILIATION_REQUIRED', 'Provider session requires manual review.', 503);
        $result = paymongo_request('checkout_sessions/' . rawurlencode($order['paymongo_session_id']));
        if (!$result['success']) throw new SecurityFault('PROVIDER_UNAVAILABLE', 'Provider unavailable.', 503);
        $pdo->beginTransaction();
        try {
            reconcile_checkout_session($pdo, (int)$order['id'], $result['data']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        invalidate_order_caches();
        $stmt = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        if ($stmt->fetchColumn() === 'pending') throw new SecurityFault('PAYMENT_STILL_PENDING', 'Payment is still pending.', 503);
    } elseif ($job['job_type'] === 'reconcile_transfer') {
        require_once __DIR__ . '/paymongo_disbursement_helpers.php';
        $stmt = $pdo->prepare('SELECT * FROM payment_attempts WHERE id = ?');
        $stmt->execute([$payload['attempt_id']]);
        $attempt = $stmt->fetch();
        if (!$attempt || in_array($attempt['status'], ['paid', 'failed', 'cancelled', 'rejected'], true)) return;
        if (empty($attempt['provider_id'])) throw new SecurityFault('MANUAL_RECONCILIATION_REQUIRED', 'Transfer requires provider-ledger review.', 503);
        $cfg = get_paymongo_disbursement_config($pdo);
        $curl = curl_init('https://api.paymongo.com/v1/disbursements/' . rawurlencode($attempt['provider_id']));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($cfg['secret_key'] . ':')]]);
        $raw = curl_exec($curl); $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($raw === false || $http < 200 || $http >= 300) throw new SecurityFault('PROVIDER_UNAVAILABLE', 'Provider reconciliation is unavailable.', 503);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR)['data'] ?? [];
        if (($data['id'] ?? '') !== $attempt['provider_id'] || ($data['attributes']['currency'] ?? '') !== 'PHP'
            || (int)($data['attributes']['amount'] ?? -1) !== (int)$attempt['amount_centavos']) throw new SecurityFault('MANUAL_RECONCILIATION_REQUIRED', 'Transfer data requires review.', 503);
        $pdo->beginTransaction();
        try {
            $result = apply_paymongo_disbursement_event($pdo, ['data' => ['attributes' => ['type' => 'disbursement.reconciled', 'data' => $data]]]);
            if (empty($result['ok'])) throw new SecurityFault('MANUAL_RECONCILIATION_REQUIRED', 'Transfer data requires review.', 503);
            $pdo->commit();
        } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
        if (!in_array($data['attributes']['status'] ?? '', ['paid', 'succeeded', 'failed', 'cancelled'], true)) throw new SecurityFault('PAYMENT_STILL_PENDING', 'Transfer is still pending.', 503);
    } elseif ($job['job_type'] === 'reconcile_invoice_checkout') {
        require_once __DIR__ . '/invoice_payment_service.php';
        $stmt = $pdo->prepare('SELECT * FROM payment_attempts WHERE id = ?'); $stmt->execute([$payload['attempt_id']]); $attempt = $stmt->fetch();
        if (!$attempt || in_array($attempt['status'], ['paid', 'cancelled', 'failed', 'rejected', 'reconciliation_required'], true)) return;
        if (empty($attempt['provider_id'])) throw new SecurityFault('MANUAL_RECONCILIATION_REQUIRED', 'Checkout needs provider-ledger review.', 503);
        $result = paymongo_request('checkout_sessions/' . rawurlencode($attempt['provider_id']));
        if (empty($result['success'])) throw new SecurityFault('PROVIDER_UNAVAILABLE', 'Provider is unavailable.', 503);
        $result = reconcile_invoice_checkout($pdo, $result['data']);
        if (!empty($result['pending'])) throw new SecurityFault('PAYMENT_STILL_PENDING', 'Payment is still pending.', 503);
    } elseif ($job['job_type'] === 'inventory_reorder') {
        require_once __DIR__ . '/inventory_helpers.php';
        $stmt = $pdo->prepare('SELECT DISTINCT ingredient_id FROM ingredient_usage_log WHERE order_id = ? ORDER BY ingredient_id');
        $stmt->execute([$payload['order_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ingredientId) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('SELECT id FROM ingredients WHERE id = ? FOR UPDATE')->execute([$ingredientId]);
                check_and_trigger_reorder((int)$ingredientId, (int)$payload['actor_id']);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
        }
    } else {
        throw new SecurityFault('JOB_TYPE_INVALID', 'Unknown job type.', 503);
    }
}

function recover_payment_jobs(PDO $pdo): void
{
    // Recover the commit-to-dispatch crash window without ever submitting another transfer.
    foreach ($pdo->query("SELECT id, entity_type, entity_id FROM payment_attempts WHERE status IN ('processing', 'submitted', 'unknown') ORDER BY id LIMIT 1000")->fetchAll() as $attempt) {
        if ($attempt['entity_type'] === 'order') enqueue_job($pdo, 'reconcile-order-' . $attempt['entity_id'], 'reconcile_order', ['order_id' => (int)$attempt['entity_id']]);
        elseif ($attempt['entity_type'] === 'invoice_checkout') enqueue_job($pdo, 'reconcile-invoice-checkout-' . $attempt['id'], 'reconcile_invoice_checkout', ['attempt_id' => (int)$attempt['id']]);
        else enqueue_job($pdo, 'reconcile-transfer-' . $attempt['id'], 'reconcile_transfer', ['attempt_id' => (int)$attempt['id']]);
    }
}

/** @param array<string,mixed> $payload */
function encode_job_payload(string $type, array $payload): string {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    if ($type !== 'email') return $json;
    $key = base64_decode(app_setting('JOB_ENCRYPTION_KEY'), true);
    if ($key === false || strlen($key) !== 32) throw new SecurityFault('JOB_KEY_NOT_CONFIGURED', 'Email delivery is unavailable.', 503);
    $iv = random_bytes(12);
    $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) throw new SecurityFault('JOB_ENCRYPTION_FAILED', 'Email delivery is unavailable.', 503);
    return base64_encode($iv . $tag . $ciphertext);
}
/** @return array<string,mixed> */
function decode_job_payload(string $type, string $encoded): array {
    if ($type === 'email') {
        $key = base64_decode(app_setting('JOB_ENCRYPTION_KEY'), true);
        $raw = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32 || $raw === false || strlen($raw) < 28) throw new SecurityFault('JOB_DECRYPTION_FAILED', 'Job cannot be decrypted.', 503);
        $encoded = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($encoded === false) throw new SecurityFault('JOB_DECRYPTION_FAILED', 'Job cannot be decrypted.', 503);
    }
    $payload = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new SecurityFault('JOB_INVALID', 'Job payload is invalid.', 503);
    return $payload;
}
