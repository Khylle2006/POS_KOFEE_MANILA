<?php
// ==============================================================================
// FILE: includes/paymongo_disbursement_helpers.php
// PayMongo Batch Transfer & Disbursement Service for Payroll
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_helpers.php';
require_once __DIR__ . '/store_helpers.php';

/**
 * Supported Philippine Banks and E-Wallets for PayMongo Payouts.
 */
function get_supported_payout_destinations(): array {
    return [
        'banks' => [
            'bdo'        => ['name' => 'BDO Unibank', 'code' => 'BDO', 'min_len' => 10, 'max_len' => 12],
            'bpi'        => ['name' => 'Bank of the Philippine Islands (BPI)', 'code' => 'BPI', 'min_len' => 10, 'max_len' => 10],
            'metrobank'  => ['name' => 'Metropolitan Bank & Trust Co (Metrobank)', 'code' => 'MBTC', 'min_len' => 13, 'max_len' => 13],
            'unionbank'  => ['name' => 'Union Bank of the Philippines', 'code' => 'UBP', 'min_len' => 12, 'max_len' => 12],
            'rcbc'       => ['name' => 'Rizal Commercial Banking Corp (RCBC)', 'code' => 'RCBC', 'min_len' => 10, 'max_len' => 16],
            'security'   => ['name' => 'Security Bank Corporation', 'code' => 'SECB', 'min_len' => 13, 'max_len' => 13],
            'pnb'        => ['name' => 'Philippine National Bank (PNB)', 'code' => 'PNB', 'min_len' => 12, 'max_len' => 16],
            'landbank'   => ['name' => 'Land Bank of the Philippines', 'code' => 'LBP', 'min_len' => 10, 'max_len' => 14],
            'chinabank'  => ['name' => 'China Banking Corporation', 'code' => 'CHBK', 'min_len' => 10, 'max_len' => 12],
            'eastwest'   => ['name' => 'EastWest Banking Corporation', 'code' => 'EWBC', 'min_len' => 12, 'max_len' => 12],
        ],
        'ewallets' => [
            'gcash'   => ['name' => 'GCash', 'code' => 'GCASH', 'prefix' => '09', 'len' => 11],
            'paymaya' => ['name' => 'Maya (PayMaya)', 'code' => 'PAYMAYA', 'prefix' => '09', 'len' => 11],
            'grabpay' => ['name' => 'GrabPay', 'code' => 'GRABPAY', 'prefix' => '09', 'len' => 11],
        ],
    ];
}

/**
 * Retrieve PayMongo API credentials and configuration.
 */
function get_paymongo_disbursement_config(PDO $pdo): array {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM payroll_settings WHERE setting_key LIKE 'paymongo_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'mode'            => $settings['paymongo_mode'] ?? 'sandbox',
        'secret_key'      => trim($settings['paymongo_secret_key'] ?? ''),
        'public_key'      => trim($settings['paymongo_public_key'] ?? ''),
        'webhook_secret'  => trim($settings['paymongo_webhook_secret'] ?? ''),
        'enabled'         => ($settings['paymongo_disbursement_enabled'] ?? '1') === '1',
    ];
}

/**
 * Validate employee payout details against bank/ewallet criteria.
 */
function validate_payout_details(?array $details): array {
    if (!$details) {
        return [
            'valid'  => false,
            'reason' => 'No salary payment details on file for this employee.',
        ];
    }

    $type = $details['payout_type'] ?? 'bank';

    if ($type === 'bank') {
        if (empty($details['bank_name'])) {
            return ['valid' => false, 'reason' => 'Bank name is missing.'];
        }
        if (empty($details['account_name'])) {
            return ['valid' => false, 'reason' => 'Account holder name is missing.'];
        }
        if (empty($details['account_number_last4'])) {
            return ['valid' => false, 'reason' => 'Bank account number is missing.'];
        }
        return ['valid' => true, 'destination' => $details['bank_name'] . ' •••• ' . $details['account_number_last4']];
    } elseif ($type === 'ewallet') {
        if (empty($details['ewallet_provider'])) {
            return ['valid' => false, 'reason' => 'E-Wallet provider is missing.'];
        }
        $phone = preg_replace('/\D/', '', $details['ewallet_mobile_number'] ?? '');
        if (strlen($phone) !== 11 || !str_starts_with($phone, '09')) {
            return ['valid' => false, 'reason' => 'Invalid Philippine mobile number format (must be 09XXXXXXXXX).'];
        }
        if (empty($details['ewallet_account_name'])) {
            return ['valid' => false, 'reason' => 'E-Wallet account name is missing.'];
        }
        $masked_phone = substr($phone, 0, 4) . ' ••• ' . substr($phone, -4);
        return ['valid' => true, 'destination' => strtoupper($details['ewallet_provider']) . ' ' . $masked_phone];
    }

    return ['valid' => false, 'reason' => 'Unsupported payout type.'];
}

/**
 * Prepare and generate pre-submission validation list for a payroll period.
 */
function get_period_payout_validation(PDO $pdo, int $period_id): array {
    $p_stmt = $pdo->prepare("SELECT * FROM `payroll_periods` WHERE `id` = :id");
    $p_stmt->execute([':id' => $period_id]);
    $period = $p_stmt->fetch();
    if (!$period) {
        return ['ok' => false, 'error' => 'Payroll period not found.'];
    }

    // Only approved and unlocked periods can be sent for payout
    if ($period['status'] !== 'approved') {
        return [
            'ok'    => false,
            'error' => "Only approved payroll periods can be sent for payout (current status: {$period['status']}).",
        ];
    }

    $slips_stmt = $pdo->prepare("
        SELECT s.*, e.firstname, e.lastname, e.employee_code, e.contact_number,
               epd.payout_type, epd.bank_code, epd.bank_name, epd.account_name,
               epd.account_number_last4, epd.ewallet_provider, epd.ewallet_account_name, epd.ewallet_mobile_number
        FROM `payslips` s
        JOIN `employees` e ON e.id = s.employee_id
        LEFT JOIN `employee_payment_details` epd ON epd.employee_id = e.id AND epd.is_active = 1
        WHERE s.period_id = :pid
        ORDER BY e.lastname, e.firstname
    ");
    $slips_stmt->execute([':pid' => $period_id]);
    $slips = $slips_stmt->fetchAll();

    $items = [];
    $total_payable = 0.0;
    $valid_count = 0;
    $invalid_count = 0;

    foreach ($slips as $s) {
        $net = (float)$s['net_pay'];
        $val = validate_payout_details($s);

        $is_valid = $val['valid'] && $net > 0 && $s['payment_status'] !== 'paid';

        if ($is_valid) {
            $valid_count++;
            $total_payable += $net;
        } else {
            $invalid_count++;
        }

        $items[] = [
            'payslip_id'          => (int)$s['id'],
            'employee_id'         => (int)$s['employee_id'],
            'employee_code'       => $s['employee_code'],
            'employee_name'       => trim($s['firstname'] . ' ' . $s['lastname']),
            'net_pay'             => $net,
            'payment_status'      => $s['payment_status'],
            'payout_type'         => $s['payout_type'] ?: 'bank',
            'destination_display' => $val['destination'] ?? 'Missing Details',
            'is_valid'            => $is_valid,
            'validation_error'    => $val['reason'] ?? ($net <= 0 ? 'Net pay is zero or negative.' : ($s['payment_status'] === 'paid' ? 'Already marked paid.' : null)),
        ];
    }

    return [
        'ok'            => true,
        'period'        => $period,
        'items'         => $items,
        'total_payable' => $total_payable,
        'valid_count'   => $valid_count,
        'invalid_count' => $invalid_count,
        'can_submit'    => ($valid_count > 0),
    ];
}

/**
 * Create a new PayMongo Payout Batch.
 */
function create_paymongo_payout_batch(PDO $pdo, int $period_id, int $user_id, array $selected_payslip_ids = [], string $notes = ''): array {
    $val = get_period_payout_validation($pdo, $period_id);
    if (!$val['ok']) {
        return $val;
    }

    // Filter to selected valid items
    $eligible_items = [];
    foreach ($val['items'] as $item) {
        if (!$item['is_valid']) continue;
        if (!empty($selected_payslip_ids) && !in_array($item['payslip_id'], $selected_payslip_ids, true)) {
            continue;
        }
        $eligible_items[] = $item;
    }

    if (empty($eligible_items)) {
        return ['ok' => false, 'error' => 'No eligible, valid employee records selected for disbursement.'];
    }

    $total_amount = array_sum(array_column($eligible_items, 'net_pay'));
    $count = count($eligible_items);

    // Generate unique batch reference & batch idempotency key
    $batch_ref = 'DISB-' . date('Ymd') . '-' . str_pad((string)$period_id, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $batch_idempotency = 'batch_' . hash('sha256', "period_{$period_id}_{$total_amount}_{$count}_" . time());

    try {
        $pdo->beginTransaction();

        $batch_stmt = $pdo->prepare("
            INSERT INTO `paymongo_payout_batches`
                (`period_id`, `batch_reference`, `idempotency_key`, `total_amount`, `recipient_count`, `status`, `created_by`, `notes`, `created_at`)
            VALUES
                (:pid, :bref, :ik, :tot, :cnt, 'draft', :uid, :notes, NOW())
        ");
        $batch_stmt->execute([
            ':pid'   => $period_id,
            ':bref'  => $batch_ref,
            ':ik'    => $batch_idempotency,
            ':tot'   => $total_amount,
            ':cnt'   => $count,
            ':uid'   => $user_id,
            ':notes' => $notes ?: "PayMongo batch transfer for {$val['period']['label']}",
        ]);
        $batch_id = (int)$pdo->lastInsertId();

        $item_stmt = $pdo->prepare("
            INSERT INTO `paymongo_payout_items`
                (`batch_id`, `payslip_id`, `employee_id`, `amount`, `payout_destination`, `recipient_name`,
                 `bank_code`, `bank_name`, `account_number_last4`, `ewallet_provider`, `ewallet_account_name`,
                 `ewallet_mobile_number`, `idempotency_key`, `status`, `created_at`)
            VALUES
                (:bid, :psid, :eid, :amt, :pdest, :rname, :bcode, :bname, :last4, :ewprov, :ewname, :ewphone, :ik, 'pending_approval', NOW())
        ");

        $item_details_stmt = $pdo->prepare("
            SELECT epd.* FROM `employee_payment_details` epd WHERE epd.employee_id = :eid AND epd.is_active = 1
        ");

        foreach ($eligible_items as $item) {
            $item_details_stmt->execute([':eid' => $item['employee_id']]);
            $dtl = $item_details_stmt->fetch() ?: [];

            // Item-level idempotency key ensures no double payment
            $item_idempotency = 'pay_' . hash('sha256', "ps_{$item['payslip_id']}_amt_{$item['net_pay']}_{$batch_ref}");

            $item_stmt->execute([
                ':bid'     => $batch_id,
                ':psid'    => $item['payslip_id'],
                ':eid'     => $item['employee_id'],
                ':amt'     => $item['net_pay'],
                ':pdest'   => $dtl['payout_type'] ?? 'bank',
                ':rname'   => $item['employee_name'],
                ':bcode'   => $dtl['bank_code'] ?? null,
                ':bname'   => $dtl['bank_name'] ?? null,
                ':last4'   => $dtl['account_number_last4'] ?? null,
                ':ewprov'  => $dtl['ewallet_provider'] ?? null,
                ':ewname'  => $dtl['ewallet_account_name'] ?? null,
                ':ewphone' => $dtl['ewallet_mobile_number'] ?? null,
                ':ik'      => $item_idempotency,
            ]);
        }

        log_operations_activity(
            $pdo,
            module: 'payroll',
            action: 'disbursement_batch_drafted',
            record_ref: $batch_ref,
            status: 'info',
            user_id: $user_id,
            details: "Drafted PayMongo payout batch for period #{$period_id} ({$count} staff, " . number_format($total_amount, 2) . " PHP)."
        );

        $pdo->commit();
        return ['ok' => true, 'batch_id' => $batch_id, 'batch_ref' => $batch_ref, 'count' => $count, 'total' => $total_amount];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Approve and dispatch a PayMongo batch payout.
 */
function submit_paymongo_payout_batch(PDO $pdo, int $batch_id, int $approver_id): array {
    $b_stmt = $pdo->prepare("SELECT * FROM `paymongo_payout_batches` WHERE `id` = :id");
    $b_stmt->execute([':id' => $batch_id]);
    $batch = $b_stmt->fetch();

    if (!$batch) {
        return ['ok' => false, 'error' => 'Disbursement batch not found.'];
    }

    if (in_array($batch['status'], ['paid', 'processing'], true)) {
        return ['ok' => false, 'error' => "Batch is already in {$batch['status']} state."];
    }

    $cfg = get_paymongo_disbursement_config($pdo);
    if (!$cfg['enabled']) {
        return ['ok' => false, 'error' => 'PayMongo automated disbursement is currently disabled in Payroll Settings.'];
    }

    // Fetch batch items
    $items_stmt = $pdo->prepare("SELECT * FROM `paymongo_payout_items` WHERE `batch_id` = :bid AND `status` != 'paid'");
    $items_stmt->execute([':bid' => $batch_id]);
    $items = $items_stmt->fetchAll();

    if (empty($items)) {
        return ['ok' => false, 'error' => 'No pending items found in this payout batch.'];
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE `paymongo_payout_batches`
            SET `status` = 'processing', `approved_by` = :uid, `approved_at` = NOW(), `submitted_at` = NOW()
            WHERE `id` = :id
        ")->execute([':uid' => $approver_id, ':id' => $batch_id]);

        $update_item = $pdo->prepare("
            UPDATE `paymongo_payout_items`
            SET `status` = :st, `paymongo_transfer_id` = :tid, `error_message` = :err,
                `attempt_count` = `attempt_count` + 1, `last_attempt_at` = NOW(),
                `paid_at` = :paid_at
            WHERE `id` = :id
        ");

        $mark_slip_paid = $pdo->prepare("
            UPDATE `payslips`
            SET `payment_status` = 'paid', `paid_at` = NOW()
            WHERE `id` = :id
        ");

        $all_paid = true;
        $processed_count = 0;
        $failed_count = 0;

        foreach ($items as $item) {
            // Call PayMongo Payout Transfer API
            // In Sandbox / Test environment, we simulate successful PayMongo transfer creation
            // unless error test pattern is provided.
            $payout_res = execute_paymongo_transfer_call($cfg, $item);

            if ($payout_res['ok']) {
                $transfer_id = $payout_res['transfer_id'];
                $update_item->execute([
                    ':st'      => 'paid',
                    ':tid'     => $transfer_id,
                    ':err'     => null,
                    ':paid_at' => date('Y-m-d H:i:s'),
                    ':id'      => $item['id'],
                ]);

                // Update payslip to paid
                $mark_slip_paid->execute([':id' => $item['payslip_id']]);
                $processed_count++;
            } else {
                $all_paid = false;
                $failed_count++;
                $update_item->execute([
                    ':st'      => 'failed',
                    ':tid'     => null,
                    ':err'     => $payout_res['error'],
                    ':paid_at' => null,
                    ':id'      => $item['id'],
                ]);
            }
        }

        // Finalize batch status
        $final_batch_status = $all_paid ? 'paid' : ($processed_count > 0 ? 'processing' : 'failed');
        $pdo->prepare("
            UPDATE `paymongo_payout_batches`
            SET `status` = :st, `completed_at` = :comp
            WHERE `id` = :id
        ")->execute([
            ':st'   => $final_batch_status,
            ':comp' => $all_paid ? date('Y-m-d H:i:s') : null,
            ':id'   => $batch_id,
        ]);

        // If batch is fully paid, advance payroll period to 'paid'
        if ($all_paid) {
            $pdo->prepare("
                UPDATE `payroll_periods`
                SET `status` = 'paid', `paid_at` = NOW()
                WHERE `id` = :pid AND `status` != 'paid'
            ")->execute([':pid' => $batch['period_id']]);
        }

        log_operations_activity(
            $pdo,
            module: 'payroll',
            action: 'disbursement_batch_processed',
            record_ref: $batch['batch_reference'],
            status: $all_paid ? 'success' : 'warning',
            user_id: $approver_id,
            details: "PayMongo disbursement processed: {$processed_count} paid, {$failed_count} failed."
        );

        $pdo->commit();
        return [
            'ok'              => true,
            'status'          => $final_batch_status,
            'processed_count' => $processed_count,
            'failed_count'    => $failed_count,
            'message'         => "Disbursement processed: {$processed_count} released" . ($failed_count > 0 ? ", {$failed_count} failed" : " successfully.")
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Execute PayMongo API transfer call (supports Sandbox mock & Live API).
 */
function execute_paymongo_transfer_call(array $cfg, array $item): array {
    $amount_cents = (int)round(((float)$item['amount']) * 100);

    // If sandbox mode and secret key is a demo placeholder, provide high-fidelity sandbox response
    if ($cfg['mode'] === 'sandbox' && (str_contains($cfg['secret_key'], 'demo') || empty($cfg['secret_key']))) {
        // Test failure trigger: if recipient name contains 'FAIL_TEST'
        if (str_contains($item['recipient_name'], 'FAIL_TEST')) {
            return ['ok' => false, 'error' => 'PayMongo Sandbox: Beneficiary account invalid or restricted.'];
        }
        $mock_transfer_id = 'tr_sbx_' . substr(hash('sha256', $item['idempotency_key']), 0, 16);
        return [
            'ok'          => true,
            'transfer_id' => $mock_transfer_id,
            'status'      => 'paid',
        ];
    }

    // Live or real Sandbox HTTP call to PayMongo Disbursements API
    $endpoint = 'https://api.paymongo.com/v1/disbursements';
    $payload = [
        'data' => [
            'attributes' => [
                'amount'          => $amount_cents,
                'currency'        => 'PHP',
                'description'     => 'Kofee Manila Payroll Payout - ' . $item['recipient_name'],
                'recipient'       => [
                    'name'        => $item['recipient_name'],
                    'type'        => $item['payout_destination'],
                    'bank_code'   => $item['bank_code'],
                    'account_num' => $item['account_number_last4'],
                    'mobile_num'  => $item['ewallet_mobile_number'],
                ],
                'idempotency_key' => $item['idempotency_key'],
            ]
        ]
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($cfg['secret_key'] . ':'),
            'Idempotency-Key: ' . $item['idempotency_key'],
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'error' => "Network error: $curl_err"];
    }

    $json = json_decode($resp, true);
    if ($http_code >= 200 && $http_code < 300 && !empty($json['data']['id'])) {
        return [
            'ok'          => true,
            'transfer_id' => $json['data']['id'],
            'status'      => $json['data']['attributes']['status'] ?? 'paid',
        ];
    }

    $err_msg = $json['errors'][0]['detail'] ?? ($json['errors'][0]['code'] ?? 'PayMongo API returned HTTP ' . $http_code);
    return ['ok' => false, 'error' => $err_msg];
}

/**
 * Handle incoming webhook notifications from PayMongo disbursements.
 */
function process_paymongo_disbursement_webhook(PDO $pdo, string $payload, string $signature_header): array {
    $cfg = get_paymongo_disbursement_config($pdo);
    $data = json_decode($payload, true);

    if (!$data || empty($data['data'])) {
        return ['ok' => false, 'error' => 'Invalid JSON payload.'];
    }

    // Verify signature if webhook secret is configured
    if (!empty($cfg['webhook_secret']) && !empty($signature_header)) {
        // PayMongo signature verification logic: t=timestamp,te=test_sig,li=live_sig
        $parts = [];
        foreach (explode(',', $signature_header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
        }
        if (!empty($parts['t'])) {
            $to_sign = $parts['t'] . '.' . $payload;
            $expected_sig = hash_hmac('sha256', $to_sign, $cfg['webhook_secret']);
            $received_sig = $parts['te'] ?? ($parts['li'] ?? '');
            if (!hash_equals($expected_sig, $received_sig) && !str_contains($cfg['webhook_secret'], 'demo')) {
                return ['ok' => false, 'error' => 'Webhook signature mismatch.'];
            }
        }
    }

    $event_type = $data['data']['attributes']['type'] ?? '';
    $event_data = $data['data']['attributes']['data'] ?? [];
    $transfer_id = $event_data['id'] ?? null;
    $status = $event_data['attributes']['status'] ?? null;

    if (!$transfer_id) {
        return ['ok' => false, 'error' => 'No transfer ID in webhook data.'];
    }

    $item_stmt = $pdo->prepare("SELECT * FROM `paymongo_payout_items` WHERE `paymongo_transfer_id` = :tid");
    $item_stmt->execute([':tid' => $transfer_id]);
    $item = $item_stmt->fetch();

    if (!$item) {
        return ['ok' => false, 'error' => "No payout item found for transfer ID $transfer_id."];
    }

    $new_status = match ($status) {
        'paid', 'succeeded'   => 'paid',
        'failed'              => 'failed',
        'cancelled'           => 'cancelled',
        'reversed'            => 'reversed',
        default               => 'processing',
    };

    $pdo->prepare("
        UPDATE `paymongo_payout_items`
        SET `status` = :st, `updated_at` = NOW()
        WHERE `id` = :id
    ")->execute([':st' => $new_status, ':id' => $item['id']]);

    if ($new_status === 'paid') {
        $pdo->prepare("UPDATE `payslips` SET `payment_status` = 'paid', `paid_at` = NOW() WHERE `id` = :id")
            ->execute([':id' => $item['payslip_id']]);
    }

    // Check if batch is now complete
    $chk_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM `paymongo_payout_items` WHERE `batch_id` = :bid AND `status` != 'paid'
    ");
    $chk_stmt->execute([':bid' => $item['batch_id']]);
    $unpaid_count = (int)$chk_stmt->fetchColumn();

    if ($unpaid_count === 0) {
        $pdo->prepare("UPDATE `paymongo_payout_batches` SET `status` = 'paid', `completed_at` = NOW() WHERE `id` = :bid")
            ->execute([':bid' => $item['batch_id']]);
    }

    return ['ok' => true, 'item_id' => $item['id'], 'new_status' => $new_status];
}
