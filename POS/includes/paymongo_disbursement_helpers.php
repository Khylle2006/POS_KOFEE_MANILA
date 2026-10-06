<?php
// ==============================================================================
// FILE: includes/paymongo_disbursement_helpers.php
// PayMongo Batch Transfer & Disbursement Service for Payroll
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payroll_helpers.php';
require_once __DIR__ . '/store_helpers.php';
if (file_exists(__DIR__ . '/notify.php')) {
    require_once __DIR__ . '/notify.php';
}

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
        'total_net'     => $total_payable,
        'valid_count'   => $valid_count,
        'invalid_count' => $invalid_count,
        'can_submit'    => ($valid_count > 0),
        'can_dispatch'  => ($valid_count > 0),
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

    $own_trans = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $own_trans = true;
        }

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

        if ($own_trans && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return ['ok' => true, 'batch_id' => $batch_id, 'batch_ref' => $batch_ref, 'count' => $count, 'total' => $total_amount];
    } catch (Exception $e) {
        if ($own_trans && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

    $own_trans = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $own_trans = true;
        }

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
            SET `payment_status` = 'paid',
                `paid_at` = NOW(),
                `transfer_id` = :tid,
                `transfer_error` = NULL,
                `transfer_channel` = 'paymongo',
                `payout_account_info` = :info,
                `released_by` = :uid
            WHERE `id` = :id
        ");

        $mark_slip_failed = $pdo->prepare("
            UPDATE `payslips`
            SET `payment_status` = 'failed',
                `transfer_error` = :err,
                `transfer_channel` = 'paymongo',
                `payout_account_info` = :info
            WHERE `id` = :id
        ");

        $all_paid = true;
        $processed_count = 0;
        $failed_count = 0;
        $notifications_to_send = [];

        foreach ($items as $item) {
            $dest_display = ($item['payout_destination'] === 'ewallet')
                ? strtoupper($item['ewallet_provider'] ?: 'E-WALLET') . ' ' . ($item['ewallet_mobile_number'] ?: '')
                : ($item['bank_name'] ?: 'BANK') . ' •••• ' . ($item['account_number_last4'] ?: '');

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
                $mark_slip_paid->execute([
                    ':tid'  => $transfer_id,
                    ':info' => $dest_display,
                    ':uid'  => $approver_id,
                    ':id'   => $item['payslip_id'],
                ]);

                // Loan deduction amortization if applicable
                $slip_check = $pdo->prepare("SELECT id, loan_deduction FROM `payslips` WHERE id = :id");
                $slip_check->execute([':id' => $item['payslip_id']]);
                $slip_row = $slip_check->fetch();

                if ($slip_row && (float)$slip_row['loan_deduction'] > 0) {
                    $remaining = (float)$slip_row['loan_deduction'];
                    $loan_stmt = $pdo->prepare(
                        "SELECT id, balance, per_period_amount FROM employee_loans
                          WHERE employee_id = :e AND status = 'active' AND balance > 0
                          ORDER BY start_date"
                    );
                    $loan_stmt->execute([':e' => (int)$item['employee_id']]);
                    $repay_ins = $pdo->prepare(
                        'INSERT INTO loan_repayments (loan_id, payslip_id, amount, paid_on)
                         VALUES (:l, :p, :a, :d)'
                    );
                    $loan_upd = $pdo->prepare(
                        "UPDATE employee_loans
                            SET balance = GREATEST(0, balance - :a),
                                status = CASE WHEN balance - :a2 <= 0 THEN 'completed' ELSE status END
                          WHERE id = :id"
                    );

                    foreach ($loan_stmt->fetchAll() as $loan) {
                        if ($remaining <= 0) break;
                        $take = min($remaining, (float)$loan['per_period_amount'], (float)$loan['balance']);
                        if ($take <= 0) continue;

                        $repay_ins->execute([
                            ':l' => $loan['id'], ':p' => $item['payslip_id'],
                            ':a' => $take, ':d' => date('Y-m-d'),
                        ]);
                        $loan_upd->execute([':a' => $take, ':a2' => $take, ':id' => $loan['id']]);
                        $remaining -= $take;
                    }
                }

                // Stage notification for employee
                $emp_usr = $pdo->prepare("SELECT user_id FROM `employees` WHERE id = :eid");
                $emp_usr->execute([':eid' => $item['employee_id']]);
                $usr_row = $emp_usr->fetch();
                if ($usr_row && !empty($usr_row['user_id'])) {
                    $notifications_to_send[] = [
                        'user_id' => (int)$usr_row['user_id'],
                        'amount'  => (float)$item['amount'],
                        'dest'    => $dest_display,
                        'ref'     => $transfer_id,
                    ];
                }

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

                // Update payslip to failed status
                $mark_slip_failed->execute([
                    ':err'  => $payout_res['error'],
                    ':info' => $dest_display,
                    ':id'   => $item['payslip_id'],
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

        // Synchronize payroll period payment status
        if (function_exists('sync_period_payment_status')) {
            sync_period_payment_status($pdo, (int)$batch['period_id']);
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

        if (function_exists('payroll_audit')) {
            payroll_audit(
                (int)$batch['period_id'],
                null,
                'paymongo_disbursed',
                "Batch {$batch['batch_reference']} processed: {$processed_count} paid, {$failed_count} failed via PayMongo.",
                $approver_id
            );
        }

        if ($own_trans && $pdo->inTransaction()) {
            $pdo->commit();
        }

        // Send employee notifications after transaction commits
        if (function_exists('notify_user')) {
            foreach ($notifications_to_send as $n) {
                try {
                    notify_user(
                        $n['user_id'],
                        'payroll_payout',
                        'Salary Disbursed via PayMongo',
                        "Your salary payout of ₱" . number_format($n['amount'], 2) . " has been sent to your account ({$n['dest']}). Ref: {$n['ref']}",
                        'my_payslips.php'
                    );
                } catch (Throwable $e) {}
            }
        }

        return [
            'ok'              => true,
            'status'          => $final_batch_status,
            'processed_count' => $processed_count,
            'failed_count'    => $failed_count,
            'message'         => "Disbursement processed: {$processed_count} released" . ($failed_count > 0 ? ", {$failed_count} failed" : " successfully.")
        ];
    } catch (Exception $e) {
        if ($own_trans && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Execute PayMongo API transfer call (supports Sandbox mock & Live API).
 */
function execute_paymongo_transfer_call(array $cfg, array $item): array {
    $amount_cents = (int)round(((float)$item['amount']) * 100);

    // If sandbox mode and secret key is a demo placeholder, provide high-fidelity sandbox response
    if ($cfg['mode'] === 'sandbox' && (str_contains($cfg['secret_key'], 'demo') || empty($cfg['secret_key']) || !str_starts_with($cfg['secret_key'], 'sk_'))) {
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
    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($cfg['secret_key'] . ':'),
            'Idempotency-Key: ' . $item['idempotency_key'],
        ],
        CURLOPT_TIMEOUT        => 30,
    ];

    $caBundle = getenv('CURL_CA_BUNDLE') ?: 'C:/xampp/apache/bin/curl-ca-bundle.crt';
    if (is_file($caBundle)) {
        $curl_opts[CURLOPT_CAINFO] = $caBundle;
    } else {
        $curl_opts[CURLOPT_SSL_VERIFYPEER] = false;
    }

    curl_setopt_array($ch, $curl_opts);

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
        // Check if this transfer belongs to a procurement supplier disbursement
        $pmt_stmt = $pdo->prepare("SELECT * FROM `payments` WHERE `paymongo_payout_id` = :tid");
        $pmt_stmt->execute([':tid' => $transfer_id]);
        $pmt = $pmt_stmt->fetch();
        if ($pmt) {
            $new_pmt_status = match ($status) {
                'paid', 'succeeded' => 'completed',
                'failed'            => 'failed',
                'cancelled'         => 'cancelled',
                default             => 'scheduled',
            };
            if ($new_pmt_status === 'completed') {
                $pdo->prepare("UPDATE `payments` SET `status` = 'completed', `completed_at` = NOW() WHERE `id` = :id")
                    ->execute([':id' => $pmt['id']]);
            } else {
                $pdo->prepare("UPDATE `payments` SET `status` = :st WHERE `id` = :id")
                    ->execute([':st' => $new_pmt_status, ':id' => $pmt['id']]);
            }

            // Re-evaluate invoice status
            $paid_sum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :iid AND status = 'completed'");
            $paid_sum->execute([':iid' => $pmt['invoice_id']]);
            $inv_paid = (float)$paid_sum->fetchColumn();

            $inv_tot_stmt = $pdo->prepare("SELECT total_amount FROM invoices WHERE id = :iid");
            $inv_tot_stmt->execute([':iid' => $pmt['invoice_id']]);
            $inv_tot = (float)$inv_tot_stmt->fetchColumn();

            $inv_st = ($inv_paid >= $inv_tot - 0.009) ? 'paid' : ($inv_paid > 0 ? 'partially_paid' : 'approved');
            $pdo->prepare("UPDATE invoices SET status = :st WHERE id = :iid")->execute([':st' => $inv_st, ':iid' => $pmt['invoice_id']]);

            return ['ok' => true, 'procurement_payment_id' => $pmt['id'], 'new_status' => $new_pmt_status];
        }

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
