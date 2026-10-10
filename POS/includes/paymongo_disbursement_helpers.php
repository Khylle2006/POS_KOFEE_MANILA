<?php
// ==============================================================================
// FILE: includes/paymongo_disbursement_helpers.php
// PayMongo Batch Transfer & Disbursement Service for Payroll
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payout_service.php';
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
    return ['mode' => payment_mode() === 'demo' ? 'sandbox' : payment_mode(),
        'secret_key' => app_setting('PAYMONGO_DISBURSEMENT_SECRET_KEY', app_setting('PAYMONGO_SECRET_KEY')),
        'public_key' => app_setting('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => app_setting('PAYMONGO_DISBURSEMENT_WEBHOOK_SECRET'),
        'enabled' => payment_mode() !== 'disabled'];
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
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Approve and dispatch a PayMongo batch payout.
 */


/**
 * Execute PayMongo API transfer call (supports Sandbox mock & Live API).
 */
function perform_paymongo_transfer_call(array $cfg, array $item): array {
    if (payment_mode() === 'disabled') throw new SecurityFault('PAYOUT_DISABLED', 'Disbursement is disabled.', 503);
    if (payment_mode() !== 'demo' && !str_starts_with($cfg['secret_key'], payment_mode() === 'live' ? 'sk_live_' : 'sk_test_')) throw new SecurityFault('PAYMENT_CONFIGURATION_INVALID', 'Disbursement key is not configured.', 503);
    $amount_cents = (int)round(((float)$item['amount']) * 100);

    // If sandbox mode and secret key is a demo placeholder, provide high-fidelity sandbox response
    if (payment_mode() === 'demo') {
        require_demo_payment();
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
                    'account_num' => $item['account_number'] ?? '',
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
        $curl_opts[CURLOPT_SSL_VERIFYPEER] = true;
            $curl_opts[CURLOPT_SSL_VERIFYHOST] = 2;
    }

    curl_setopt_array($ch, $curl_opts);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'unknown' => true, 'error' => 'Transfer outcome is unknown. Reconciliation is required.'];
    }

    $json = json_decode($resp, true);
    if ($http_code >= 200 && $http_code < 300 && !empty($json['data']['id'])) {
        $status = $json['data']['attributes']['status'] ?? 'processing';
        if (in_array($status, ['failed', 'cancelled', 'rejected'], true)) {
            return ['ok' => false, 'unknown' => false, 'transfer_id' => $json['data']['id'], 'status' => $status, 'error' => 'Provider rejected the transfer.'];
        }
        return [
            'ok'          => true,
            'transfer_id' => $json['data']['id'],
            'status'      => $status,
        ];
    }

    $err_msg = $json['errors'][0]['detail'] ?? ($json['errors'][0]['code'] ?? 'PayMongo API returned HTTP ' . $http_code);
    return ['ok' => false, 'unknown' => $http_code >= 500 || $http_code === 0, 'error' => 'Provider rejected the transfer or its outcome requires review.'];
}

/**
 * Handle incoming webhook notifications from PayMongo disbursements.
 */
function process_paymongo_disbursement_webhook(PDO $pdo, string $payload, string $signature_header): array {
    require_once __DIR__ . '/payment_events.php';
    return process_payment_webhook($pdo, $payload, $signature_header, true);
}

/** Apply only events already authenticated by the webhook boundary. */
function apply_paymongo_disbursement_event(PDO $pdo, array $data): array {
    $event_type = $data['data']['attributes']['type'] ?? '';
    $event_data = $data['data']['attributes']['data'] ?? [];
    $transfer_id = $event_data['id'] ?? null;
    $status = $event_data['attributes']['status'] ?? null;

    if (!$transfer_id) {
        return ['ok' => false, 'error' => 'No transfer ID in webhook data.'];
    }

    $item_stmt = $pdo->prepare("SELECT * FROM `paymongo_payout_items` WHERE `paymongo_transfer_id` = :tid FOR UPDATE");
    $item_stmt->execute([':tid' => $transfer_id]);
    $item = $item_stmt->fetch();

    if (!$item) {
        $attempt = $pdo->prepare("SELECT entity_id, amount_centavos FROM payment_attempts WHERE provider_id = ? AND entity_type = 'payslip' FOR UPDATE");
        $attempt->execute([$transfer_id]);
        $attemptRow = $attempt->fetch();
        if ($attemptRow) {
            $slipStmt = $pdo->prepare('SELECT id, employee_id, period_id, payment_status FROM payslips WHERE id = ? FOR UPDATE');
            $slipStmt->execute([$attemptRow['entity_id']]);
            $slip = $slipStmt->fetch();
            if (!$slip) return ['ok' => false, 'error' => 'Payout record is missing.'];
            if ($slip['payment_status'] === 'paid' && !in_array($status, ['paid', 'succeeded'], true)) return ['ok' => true, 'ignored' => true];
            $next = in_array($status, ['paid', 'succeeded'], true) ? 'paid' : (in_array($status, ['failed', 'cancelled'], true) ? 'failed' : 'pending');
            if ($next === 'paid') apply_payslip_loan_repayments($pdo, ['payslip_id' => $slip['id'], 'employee_id' => $slip['employee_id']]);
            $pdo->prepare('UPDATE payslips SET payment_status = ?, paid_at = ? WHERE id = ?')->execute([$next, $next === 'paid' ? date('Y-m-d H:i:s') : null, $slip['id']]);
            $pdo->prepare('UPDATE payment_attempts SET status = ? WHERE provider_id = ?')->execute([$next, $transfer_id]);
            security_audit($pdo, 'payout_confirmed', 'payslip', (int)$slip['id'], ['status' => $next]);
            sync_period_payment_status($pdo, (int)$slip['period_id']);
            return ['ok' => true, 'payslip_id' => $slip['id'], 'new_status' => $next];
        }
        // Check if this transfer belongs to a procurement supplier disbursement
        $pmt_stmt = $pdo->prepare("SELECT * FROM `payments` WHERE `paymongo_payout_id` = :tid FOR UPDATE");
        $pmt_stmt->execute([':tid' => $transfer_id]);
        $pmt = $pmt_stmt->fetch();
        if ($pmt) {
            $pdo->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([$pmt['invoice_id']]);
            if ($pmt['status'] === 'completed' && !in_array($status, ['paid', 'succeeded'], true)) return ['ok' => true, 'ignored' => true];
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

            $pdo->prepare('UPDATE payment_attempts SET status = ? WHERE provider_id = ?')->execute([$new_pmt_status === 'completed' ? 'paid' : $new_pmt_status, $transfer_id]);
            security_audit($pdo, 'supplier_payout_confirmed', 'payment', (int)$pmt['id'], ['status' => $new_pmt_status]);
            return ['ok' => true, 'procurement_payment_id' => $pmt['id'], 'new_status' => $new_pmt_status];
        }

        return ['ok' => false, 'error' => "No payout item found for transfer ID $transfer_id."];
    }

    if ($item['status'] === 'paid' && !in_array($status, ['paid', 'succeeded', 'reversed'], true)) return ['ok' => true, 'ignored' => true];
    $previous_status = $item['status'];
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

    if ($new_status === 'paid' && $previous_status !== 'paid') {
        apply_payslip_loan_repayments($pdo, $item);
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

    security_audit($pdo, 'payout_confirmed', 'payslip', (int)$item['payslip_id'], ['status' => $new_status]);
    $pdo->prepare('UPDATE payment_attempts SET status = ? WHERE operation_key = ?')->execute([$new_status, 'payslip-transfer-' . $item['payslip_id']]);
    return ['ok' => true, 'item_id' => $item['id'], 'new_status' => $new_status];
}

function apply_payslip_loan_repayments(PDO $pdo, array $item): void {
    // Confirmed money movement applies each payslip loan ledger once.
    if (!$pdo->inTransaction()) throw new LogicException('Loan posting requires a transaction.');
    $slip_check = $pdo->prepare('SELECT id, loan_deduction FROM payslips WHERE id = ? FOR UPDATE');
    $slip_check->execute([$item['payslip_id']]); $slip_row = $slip_check->fetch();
    $already = $pdo->prepare('SELECT 1 FROM loan_repayments WHERE payslip_id = ? LIMIT 1');
    $already->execute([$item['payslip_id']]);
    if ($already->fetchColumn()) return;
                if ($slip_row && (float)$slip_row['loan_deduction'] > 0) {
                    $remaining = (float)$slip_row['loan_deduction'];
                    $loan_stmt = $pdo->prepare(
                        "SELECT id, balance, per_period_amount FROM employee_loans
                          WHERE employee_id = :e AND status = 'active' AND balance > 0
                          ORDER BY id FOR UPDATE"
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


}
