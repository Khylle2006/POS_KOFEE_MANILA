<?php
require_once __DIR__ . '/../includes/private_storage.php';
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/store_helpers.php';
require_once '../includes/paymongo_disbursement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

/**
 * Validates and securely saves payment receipt upload.
 */
function handle_payment_receipt_upload(?array $file, bool $required = true): array {
    if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        if ($required) {
            return ['ok' => false, 'error' => 'Mandatory proof of payment file is required to complete this payment.'];
        }
        return ['ok' => true, 'path' => null, 'name' => null, 'size' => 0, 'type' => null];
    }

    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/pjpeg'];

    $orig_name = basename($file['name']);
    $size = (int)$file['size'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts, true)) {
        return ['ok' => false, 'error' => 'Invalid receipt file type. Only PDF, JPG, and PNG files are allowed.'];
    }

    if ($size > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Receipt file exceeds the maximum 10MB limit.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes, true)) {
        return ['ok' => false, 'error' => 'Uploaded file mime type (' . htmlspecialchars($mime) . ') is not an allowed receipt format.'];
    }

    $upload_dir = private_upload_directory('receipts');
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $fname = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $upload_dir . '/' . $fname;

    if (!private_move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'Failed to save receipt file to storage.'];
    }

    return [
        'ok'   => true,
        'path' => 'uploads/receipts/' . $fname,
        'name' => $orig_name,
        'size' => $size,
        'type' => $mime,
    ];
}

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['complete', 'fail', 'cancel'], true)) {
        $gateway = $pdo->prepare('SELECT paymongo_payout_id, paymongo_checkout_id FROM payments WHERE id = ?');
        $gateway->execute([(int)($_POST['payment_id'] ?? $_POST['id'] ?? 0)]);
        $row = $gateway->fetch();
        if ($row && (!empty($row['paymongo_payout_id']) || !empty($row['paymongo_checkout_id']))) throw new SecurityFault('GATEWAY_RECONCILIATION_REQUIRED', 'Gateway payments require provider confirmation or reconciliation.', 409);
    }

    // Action: Automated PayMongo Disbursement Payout to Supplier
    if ($action === 'paymongo_disburse') {
        require_permission('procurement.payment.process');

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $notes      = trim($_POST['notes'] ?? '');
        $save_payout= !empty($_POST['save_to_supplier_profile']);

        $payout_params = [
            'payout_type'           => in_array($_POST['payout_type'] ?? '', ['bank','ewallet'], true) ? $_POST['payout_type'] : 'bank',
            'bank_name'             => trim($_POST['bank_name'] ?? ''),
            'bank_code'             => trim($_POST['bank_code'] ?? ''),
            'account_name'          => trim($_POST['account_name'] ?? ''),
            'account_number'        => trim($_POST['account_number'] ?? ''),
            'ewallet_provider'      => trim($_POST['ewallet_provider'] ?? ''),
            'ewallet_account_name'  => trim($_POST['ewallet_account_name'] ?? ''),
            'ewallet_mobile_number' => trim($_POST['ewallet_mobile_number'] ?? ''),
        ];

        // Optionally persist verified payout account to supplier profile
        if ($save_payout && $invoice_id > 0) {
            $sup_id_stmt = $pdo->prepare('SELECT supplier_id FROM invoices WHERE id = :id');
            $sup_id_stmt->execute([':id' => $invoice_id]);
            $sup_id = $sup_id_stmt->fetchColumn();
            if ($sup_id) {
                $upd_sup = $pdo->prepare('
                    UPDATE suppliers 
                    SET payout_type = :pt, bank_name = :bn, bank_code = :bc,
                        account_name = :an, account_number = :num,
                        ewallet_provider = :ep, ewallet_account_name = :ean, ewallet_mobile_number = :emn
                    WHERE id = :sid
                ');
                $upd_sup->execute([
                    ':pt'  => $payout_params['payout_type'],
                    ':bn'  => $payout_params['bank_name'] ?: null,
                    ':bc'  => $payout_params['bank_code'] ?: null,
                    ':an'  => $payout_params['account_name'] ?: null,
                    ':num' => $payout_params['account_number'] ?: null,
                    ':ep'  => $payout_params['ewallet_provider'] ?: null,
                    ':ean' => $payout_params['ewallet_account_name'] ?: null,
                    ':emn' => $payout_params['ewallet_mobile_number'] ?: null,
                    ':sid' => $sup_id,
                ]);
            }
        }

        $res = process_supplier_paymongo_disbursement($pdo, $invoice_id, $amount, $payout_params, (int)$user['id'], $notes);
        if ($res['ok']) {
            $toast = 'PayMongo disbursement successful! Ref: ' . $res['transfer_id'] . '. Official electronic voucher generated.';
            header('Location: payments.php?id=' . $res['payment_id'] . '&toast=' . urlencode($toast) . '&type=success');
            exit;
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
            header('Location: payments.php?new_for_invoice=' . $invoice_id . '&toast=' . urlencode($toast) . '&type=error');
            exit;
        }
    }

    // Action: PayMongo Online Checkout Session
    if ($action === 'paymongo_checkout') {
        require_permission('procurement.payment.process');

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);

        $base_url = app_url() . '/php';
        $success_url = $base_url . '/payments.php?paymongo_return=1&session_id={CHECKOUT_SESSION_ID}&invoice_id=' . $invoice_id;
        $cancel_url  = $base_url . '/payments.php?new_for_invoice=' . $invoice_id . '&toast=' . urlencode('Checkout session cancelled.');

        $res = create_supplier_invoice_paymongo_checkout($pdo, $invoice_id, $amount, (int)$user['id'], $success_url, $cancel_url);
        if ($res['ok']) {
            header('Location: ' . $res['checkout_url']);
            exit;
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
            header('Location: payments.php?new_for_invoice=' . $invoice_id . '&toast=' . urlencode($toast) . '&type=error');
            exit;
        }
    }

    // Action 1: Record and complete payment immediately
    // Action 2: Schedule payment for later
    if (in_array($action, ['record_complete', 'schedule'], true)) {
        require_permission('procurement.payment.process');

        $invoice_id     = (int)($_POST['invoice_id'] ?? 0);
        $amount         = (float)($_POST['amount'] ?? 0);
        $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $paying_account = trim($_POST['paying_account'] ?? 'BDO Operations (Account •••• 1042)');
        $method         = in_array($_POST['payment_method'] ?? '', ['bank_transfer','check','cash','ewallet','online'], true) ? $_POST['payment_method'] : 'bank_transfer';
        $reference      = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        $inv = $pdo->prepare('SELECT * FROM invoices WHERE id = :id'); 
        $inv->execute([':id' => $invoice_id]); 
        $invoice = $inv->fetch();

        // Calculate unpaid remaining balance
        $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id AND status = 'completed'");
        $paid_stmt->execute([':id' => $invoice_id]);
        $already_paid = (float)$paid_stmt->fetchColumn();
        $unpaid_balance = max(0, round((float)$invoice['total_amount'] - $already_paid, 2));

        if (!$invoice || !in_array($invoice['status'], ['approved', 'matched', 'partially_paid'], true)) {
            $toast = 'Only approved or partially paid invoices can receive payments.'; 
            $toast_type = 'error';
        } elseif ($amount <= 0) {
            $toast = 'Enter a valid payment amount greater than zero.'; 
            $toast_type = 'error';
        } elseif ($amount > $unpaid_balance + 0.001) {
            $toast = 'Payment amount (₱' . number_format($amount, 2) . ') exceeds remaining unpaid invoice balance of ₱' . number_format($unpaid_balance, 2) . '.';
            $toast_type = 'error';
        } elseif ($action === 'record_complete' && $payment_date > date('Y-m-d')) {
            $toast = 'Completed payment date cannot be set in the future.';
            $toast_type = 'error';
        } elseif ($method !== 'cash' && empty($reference)) {
            $toast = 'Reference number is mandatory for ' . ucwords(str_replace('_', ' ', $method)) . ' payments.';
            $toast_type = 'error';
        } else {
            // Check reference number uniqueness if provided
            if ($reference) {
                $ref_chk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference_no = :ref");
                $ref_chk->execute([':ref' => $reference]);
                if ((int)$ref_chk->fetchColumn() > 0) {
                    $toast = "Reference number '{$reference}' has already been used on another payment. Reference numbers must be unique.";
                    $toast_type = 'error';
                }
            }

            if (!$toast) {
                if ($action === 'record_complete') {
                    // Mandatory receipt upload
                    $receipt_res = handle_payment_receipt_upload($_FILES['receipt_file'] ?? null, true);
                    if (!$receipt_res['ok']) {
                        $toast = $receipt_res['error'];
                        $toast_type = 'error';
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $pdo->prepare('
                                INSERT INTO payments (
                                    invoice_id, po_id, amount, payment_date, payment_method, paying_account,
                                    reference_no, receipt_attachment_path, receipt_file_name, receipt_file_size, receipt_file_type,
                                    notes, paid_by, status, supplier_confirmation_status, scheduled_at, completed_at
                                ) VALUES (
                                    :inv, :po, :amt, :pdate, :m, :acct,
                                    :ref, :rpath, :rname, :rsize, :rtype,
                                    :n, :u, "completed", "awaiting_confirmation", NOW(), NOW()
                                )
                            ')->execute([
                                ':inv'   => $invoice_id,
                                ':po'    => $invoice['po_id'],
                                ':amt'   => $amount,
                                ':pdate' => $payment_date,
                                ':m'     => $method,
                                ':acct'  => $paying_account,
                                ':ref'   => $reference ?: null,
                                ':rpath' => $receipt_res['path'],
                                ':rname' => $receipt_res['name'],
                                ':rsize' => $receipt_res['size'],
                                ':rtype' => $receipt_res['type'],
                                ':n'     => $notes ?: null,
                                ':u'     => $user['id'],
                            ]);
                            $payment_id = (int)$pdo->lastInsertId();

                            // Synchronize invoice status
                            $new_total_paid = $already_paid + $amount;
                            $inv_status = ($new_total_paid >= (float)$invoice['total_amount'] - 0.009) ? 'paid' : 'partially_paid';
                            $pdo->prepare("UPDATE invoices SET status = :st WHERE id = :id")->execute([':st' => $inv_status, ':id' => $invoice_id]);

                            // Check PO paid status
                            $unpaid_chk = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE po_id = :po AND status != 'paid' AND status != 'cancelled'");
                            $unpaid_chk->execute([':po' => $invoice['po_id']]);
                            if (((int)$unpaid_chk->fetchColumn()) === 0) {
                                $pdo->prepare("UPDATE purchase_orders SET paid_at = NOW() WHERE id = :po AND paid_at IS NULL")
                                    ->execute([':po' => $invoice['po_id']]);
                            }

                            $pdo->commit();

                            audit_log('payment', $payment_id, 'completed', "Completed payment for Invoice {$invoice['invoice_number']} — " . php_currency($amount));
                            log_operations_activity(
                                $pdo,
                                module: 'finance',
                                action: 'supplier_payment_completed',
                                record_ref: "INV-{$invoice['invoice_number']}",
                                status: 'info',
                                user_id: $user['id'],
                                details: "Payment of " . number_format($amount, 2) . " PHP recorded via {$method} ({$paying_account}). Proof attached."
                            );

                            // Notify supplier
                            $sup_stmt = $pdo->prepare('SELECT s.user_id, s.name, i.invoice_number FROM invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.id = :id');
                            $sup_stmt->execute([':id' => $invoice_id]);
                            $sup = $sup_stmt->fetch();
                            if ($sup && $sup['user_id']) {
                                notify_user(
                                    (int)$sup['user_id'], 'payment_advice', 'Payment Sent — Awaiting Confirmation',
                                    'Payment of ' . php_currency($amount) . ' for Invoice ' . $sup['invoice_number'] . ' has been recorded with proof of payment. Please confirm receipt in your portal.',
                                    'supplier_portal.php?tab=invoices'
                                );
                            }

                            $toast = 'Payment completed successfully. Proof of payment recorded.';
                            header('Location: payments.php?id=' . $payment_id . '&toast=' . urlencode($toast) . '&type=success');
                            exit;
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $toast = 'Service temporarily unavailable.';
                            $toast_type = 'error';
                        }
                    }
                } else {
                    // Schedule payment for later
                    $pdo->prepare('
                        INSERT INTO payments (
                            invoice_id, po_id, amount, payment_date, payment_method, paying_account, reference_no, notes, paid_by, status
                        ) VALUES (
                            :inv, :po, :amt, :pdate, :m, :acct, :ref, :n, :u, "scheduled"
                        )
                    ')->execute([
                        ':inv'   => $invoice_id,
                        ':po'    => $invoice['po_id'],
                        ':amt'   => $amount,
                        ':pdate' => $payment_date,
                        ':m'     => $method,
                        ':acct'  => $paying_account,
                        ':ref'   => $reference ?: null,
                        ':n'     => $notes ?: null,
                        ':u'     => $user['id'],
                    ]);
                    $payment_id = (int)$pdo->lastInsertId();
                    audit_log('payment', $payment_id, 'scheduled', "Invoice {$invoice['invoice_number']} — " . php_currency($amount));
                    $toast = 'Payment scheduled for ' . date('M d, Y', strtotime($payment_date)) . '.';
                    header('Location: payments.php?id=' . $payment_id . '&toast=' . urlencode($toast) . '&type=success');
                    exit;
                }
            }
        }
    }

    // Action 3: Complete an existing scheduled payment
    if ($action === 'complete') {
        require_permission('procurement.payment.process');
        $id             = (int)($_POST['id'] ?? 0);
        $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $paying_account = trim($_POST['paying_account'] ?? 'BDO Operations (Account •••• 1042)');
        $reference      = trim($_POST['reference_no'] ?? '');

        $p = $pdo->prepare('SELECT p.*, i.invoice_number, i.total_amount, i.supplier_id FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.id = :id'); 
        $p->execute([':id' => $id]); 
        $payment = $p->fetch();

        if ($payment && $payment['status'] === 'scheduled') {
            if ($payment_date > date('Y-m-d')) {
                $toast = 'Completed payment date cannot be set in the future.';
                $toast_type = 'error';
            } elseif ($payment['payment_method'] !== 'cash' && empty($reference) && empty($payment['reference_no'])) {
                $toast = 'Reference number is mandatory for ' . ucwords(str_replace('_', ' ', $payment['payment_method'])) . ' payments.';
                $toast_type = 'error';
            } else {
                $ref_to_use = $reference ?: $payment['reference_no'];
                if ($ref_to_use) {
                    $ref_chk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference_no = :ref AND id != :id");
                    $ref_chk->execute([':ref' => $ref_to_use, ':id' => $id]);
                    if ((int)$ref_chk->fetchColumn() > 0) {
                        $toast = "Reference number '{$ref_to_use}' has already been used on another payment.";
                        $toast_type = 'error';
                    }
                }

                if (!$toast) {
                    // Mandatory receipt upload
                    $receipt_res = handle_payment_receipt_upload($_FILES['receipt_file'] ?? null, true);
                    if (!$receipt_res['ok']) {
                        $toast = $receipt_res['error'];
                        $toast_type = 'error';
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $pdo->prepare("
                                UPDATE payments 
                                SET status = 'completed', 
                                    completed_at = NOW(),
                                    payment_date = :pdate,
                                    paying_account = :acct,
                                    reference_no = :ref,
                                    receipt_attachment_path = :rpath,
                                    receipt_file_name = :rname,
                                    receipt_file_size = :rsize,
                                    receipt_file_type = :rtype,
                                    supplier_confirmation_status = 'awaiting_confirmation'
                                WHERE id = :id
                            ")->execute([
                                ':pdate' => $payment_date,
                                ':acct'  => $paying_account,
                                ':ref'   => $ref_to_use ?: null,
                                ':rpath' => $receipt_res['path'],
                                ':rname' => $receipt_res['name'],
                                ':rsize' => $receipt_res['size'],
                                ':rtype' => $receipt_res['type'],
                                ':id'    => $id,
                            ]);

                            // Recalculate invoice paid status
                            $paid_tot_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :iid AND status = 'completed'");
                            $paid_tot_stmt->execute([':iid' => $payment['invoice_id']]);
                            $paid_tot = (float)$paid_tot_stmt->fetchColumn();

                            $inv_status = ($paid_tot >= (float)$payment['total_amount'] - 0.009) ? 'paid' : 'partially_paid';
                            $pdo->prepare("UPDATE invoices SET status = :st WHERE id = :id")->execute([':st' => $inv_status, ':id' => $payment['invoice_id']]);

                            // Check PO
                            $unpaid_chk = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE po_id = :po AND status != 'paid' AND status != 'cancelled'");
                            $unpaid_chk->execute([':po' => $payment['po_id']]);
                            if (((int)$unpaid_chk->fetchColumn()) === 0) {
                                $pdo->prepare("UPDATE purchase_orders SET paid_at = NOW() WHERE id = :po AND paid_at IS NULL")
                                    ->execute([':po' => $payment['po_id']]);
                            }

                            $pdo->commit();

                            audit_log('payment', $id, 'completed', php_currency($payment['amount']) . " — proof attached.");
                            log_operations_activity(
                                $pdo,
                                module: 'finance',
                                action: 'supplier_payment_completed',
                                record_ref: "INV-{$payment['invoice_number']}",
                                status: 'info',
                                user_id: $user['id'],
                                details: "Payment of " . number_format($payment['amount'], 2) . " PHP completed for Invoice #{$payment['invoice_number']}."
                            );

                            // Notify supplier
                            $sup_stmt = $pdo->prepare('SELECT s.user_id FROM suppliers s WHERE s.id = :sid');
                            $sup_stmt->execute([':sid' => $payment['supplier_id']]);
                            $sup = $sup_stmt->fetch();
                            if ($sup && $sup['user_id']) {
                                notify_user(
                                    (int)$sup['user_id'], 'payment_advice', 'Payment Sent — Awaiting Confirmation',
                                    'Payment of ' . php_currency($payment['amount']) . ' for Invoice ' . $payment['invoice_number'] . ' has been completed with proof attached.',
                                    'supplier_portal.php?tab=invoices'
                                );
                            }

                            $toast = 'Payment marked completed with proof of payment attached.';
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $toast = 'Service temporarily unavailable.';
                            $toast_type = 'error';
                        }
                    }
                }
            }
        }
    }

    if ($action === 'fail') {
        require_permission('procurement.payment.process');
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['fail_reason'] ?? '');
        $pdo->prepare("UPDATE payments SET status='failed', notes = CONCAT(COALESCE(notes,''), ' | Failed: ', :r) WHERE id=:id AND status='scheduled'")
            ->execute([':r' => $reason ?: 'No reason given', ':id' => $id]);
        audit_log('payment', $id, 'failed', $reason);
        $toast = 'Payment marked failed. You can schedule a new attempt from the invoice.'; 
        $toast_type = 'error';
    }

    if ($action === 'cancel') {
        require_permission('procurement.payment.process');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE payments SET status='cancelled' WHERE id=:id AND status='scheduled'")->execute([':id' => $id]);
        audit_log('payment', $id, 'cancelled');
        $toast = 'Scheduled payment cancelled.';
    }

    $id_for_redirect = (int)($_POST['id'] ?? $_POST['invoice_id'] ?? 0);
    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: payments.php' . ($id_for_redirect ? '?id=' . $id_for_redirect : '') . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

if (isset($_GET['paymongo_return'])) $toast = 'Payment confirmation is pending. Refresh after the gateway callback is processed.';

// ── "New payment" context ──────────────────────────────
$new_invoice_id = (int)($_GET['new_for_invoice'] ?? 0);
$new_invoice = null;

$po_id_param = (int)($_GET['po_id'] ?? 0);
$po_id_toast = null;
if ($po_id_param && !$new_invoice_id) {
    $po_inv_stmt = $pdo->prepare("
        SELECT id FROM invoices WHERE po_id = :po AND status IN ('approved', 'partially_paid') ORDER BY id DESC LIMIT 1
    ");
    $po_inv_stmt->execute([':po' => $po_id_param]);
    $found_invoice_id = $po_inv_stmt->fetchColumn();
    if ($found_invoice_id) {
        $new_invoice_id = (int)$found_invoice_id;
    } else {
        $po_pay_stmt = $pdo->prepare('SELECT id FROM payments WHERE po_id = :po ORDER BY id DESC LIMIT 1');
        $po_pay_stmt->execute([':po' => $po_id_param]);
        $existing_payment_id = $po_pay_stmt->fetchColumn();
        if ($existing_payment_id) {
            header('Location: payments.php?id=' . $existing_payment_id);
            exit;
        }
        $po_id_toast = 'No approved invoice awaiting payment for this Purchase Order.';
    }
}

if ($new_invoice_id) {
    $stmt = $pdo->prepare('
        SELECT i.*, s.name AS supplier_name, s.user_id AS supplier_user_id,
               s.payout_type, s.bank_name, s.bank_code, s.account_name, s.account_number,
               s.ewallet_provider, s.ewallet_account_name, s.ewallet_mobile_number,
               s.email AS supplier_email, s.phone AS supplier_phone,
               po.id AS po_id, po.po_number
        FROM invoices i 
        JOIN suppliers s ON s.id = i.supplier_id 
        JOIN purchase_orders po ON po.id = i.po_id
        WHERE i.id = :id
    ');
    $stmt->execute([':id' => $new_invoice_id]);
    $new_invoice = $stmt->fetch();
}
if ($po_id_toast && !$toast) {
    $toast = $po_id_toast; $toast_type = 'error';
}

$new_inv_paid = 0.0;
$new_inv_unpaid = 0.0;
if ($new_invoice) {
    $p_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id AND status = 'completed'");
    $p_stmt->execute([':id' => $new_invoice['id']]);
    $new_inv_paid = (float)$p_stmt->fetchColumn();
    $new_inv_unpaid = max(0, round((float)$new_invoice['total_amount'] - $new_inv_paid, 2));
}

// ── Detail view ──────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$payment = null;
if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT p.*, i.invoice_number, i.total_amount AS invoice_total, s.name AS supplier_name
        FROM payments p 
        JOIN invoices i ON i.id = p.invoice_id 
        JOIN suppliers s ON s.id = i.supplier_id
        WHERE p.id = :id
    ');
    $stmt->execute([':id' => $view_id]);
    $payment = $stmt->fetch();
}

// ── Approved & partially paid invoices awaiting payment ──────────────────
$awaiting_stmt = $pdo->query("
    SELECT i.id, i.invoice_number, i.total_amount, s.name AS supplier_name, i.due_date,
           (SELECT COALESCE(SUM(amount), 0) FROM payments py WHERE py.invoice_id = i.id AND py.status = 'completed') AS amount_paid
    FROM invoices i 
    JOIN suppliers s ON s.id = i.supplier_id
    WHERE i.status IN ('approved', 'partially_paid')
    ORDER BY i.due_date IS NULL, i.due_date ASC
");
$all_awaiting = $awaiting_stmt->fetchAll();
// Filter to only those with remaining unpaid balance
$awaiting = array_values(array_filter($all_awaiting, function($inv) {
    return round((float)$inv['total_amount'] - (float)$inv['amount_paid'], 2) > 0.01;
}));

// ── List view ──────────────────────────────
$filter = $_GET['status'] ?? 'all';
$where  = '1=1'; $params = [];
if (in_array($filter, ['scheduled','completed','failed','cancelled'], true)) {
    $where .= ' AND p.status = :st'; $params[':st'] = $filter;
}
$list_stmt = $pdo->prepare("
    SELECT p.*, i.invoice_number, s.name AS supplier_name
    FROM payments p 
    JOIN invoices i ON i.id = p.invoice_id 
    JOIN suppliers s ON s.id = i.supplier_id
    WHERE $where 
    ORDER BY p.scheduled_at DESC
");
$list_stmt->execute($params);
$payments = $list_stmt->fetchAll();

$store_paying_accounts = [
    'BDO Operations (Account •••• 1042)',
    'BPI Main (Account •••• 8820)',
    'Store Petty Cash (Cash On Hand)',
    'GCash Business (0917 •••• 890)',
    'Maya Business (0928 •••• 123)',
];
$paymongo_cfg = get_procurement_paymongo_config($pdo);
$payout_dests = get_supported_payout_destinations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Payments — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-payments" class="page active">
  <div class="page-header">
    <div>
      <h1>Supplier Payments</h1>
      <p>Record, schedule, and verify payments to suppliers with mandatory proof of payment</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:8px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($new_invoice): ?>
      <!-- ── Record / Pay Supplier Invoice ── -->
      <div class="table-card" style="padding:22px 24px;max-width:640px;margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div>
            <h2 style="margin:0 0 4px">Pay Supplier Invoice</h2>
            <p class="muted-cell" style="margin:0">
              Invoice <strong><?= htmlspecialchars($new_invoice['invoice_number']) ?></strong> &middot; <?= htmlspecialchars($new_invoice['supplier_name']) ?> &middot; PO #<?= str_pad($new_invoice['po_id'],5,'0',STR_PAD_LEFT) ?>
            </p>
          </div>
          <?php if (!empty($paymongo_cfg['enabled'])): ?>
            <span class="status-badge" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;font-size:11px">
              PayMongo <?= ucfirst($paymongo_cfg['mode']) ?> Active
            </span>
          <?php endif; ?>
        </div>

        <!-- Balance overview box -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px;padding:12px 14px;margin-bottom:18px;text-align:center">
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Invoice Total</div>
            <div style="font-size:15px;font-weight:800;color:var(--text-main);margin-top:2px"><?= php_currency($new_invoice['total_amount']) ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Already Paid</div>
            <div style="font-size:15px;font-weight:800;color:var(--green,#2e7d32);margin-top:2px"><?= php_currency($new_inv_paid) ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Unpaid Balance</div>
            <div style="font-size:15px;font-weight:800;color:var(--caramel,#8B4513);margin-top:2px"><?= php_currency($new_inv_unpaid) ?></div>
          </div>
        </div>

        <!-- Method selection tabs -->
        <div class="filter-bar" style="margin-bottom:16px;padding:0;display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="filter-pill active" id="tab-btn-disburse" onclick="switchPayTab('disburse')" style="cursor:pointer">
            <?= icon('dollar', 13) ?> PayMongo Disbursement (Auto-Payout)
          </button>
          <button type="button" class="filter-pill" id="tab-btn-checkout" onclick="switchPayTab('checkout')" style="cursor:pointer">
            <?= icon('credit-card', 13) ?> PayMongo Online Checkout
          </button>
          <button type="button" class="filter-pill" id="tab-btn-manual" onclick="switchPayTab('manual')" style="cursor:pointer">
            <?= icon('file-text', 13) ?> Manual Entry / Check
          </button>
        </div>

        <!-- TAB 1: PayMongo Automated Disbursement (Direct Bank or GCash Transfer) -->
        <div id="pane-pay-disburse">
          <form method="POST" id="form-paymongo-disburse">
            <input type="hidden" name="action" value="paymongo_disburse"/>
            <input type="hidden" name="invoice_id" value="<?= $new_invoice['id'] ?>"/>

            <div style="background:#F0FDF4;border:1.5px solid #86EFAC;border-radius:10px;padding:14px;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                <div style="width:22px;height:22px;border-radius:50%;background:#16A34A;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800">⚡</div>
                <strong style="color:#166534;font-size:13.5px">Automated PayMongo Payout Engine</strong>
              </div>
              <p style="margin:0;font-size:12px;color:#15803D;line-height:1.4">
                Instantly transfers funds from your account to the supplier's verified Bank or GCash/Maya wallet. An official electronic PayMongo proof voucher is automatically generated and recorded.
              </p>
            </div>

            <div style="margin-bottom:14px">
              <label style="font-size:12.5px;font-weight:700;color:var(--text-main);display:block;margin-bottom:6px">Disbursement Destination Channel <span style="color:var(--red)">*</span></label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid #E8DED2;border-radius:8px;cursor:pointer;background:#fff" id="lbl-dest-bank">
                  <input type="radio" name="payout_type" value="bank" <?= ($new_invoice['payout_type'] ?? 'bank') === 'bank' ? 'checked' : '' ?> onchange="toggleDisburseType(this.value)"/>
                  <span style="font-size:13px;font-weight:600">Philippine Bank Transfer</span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1.5px solid #E8DED2;border-radius:8px;cursor:pointer;background:#fff" id="lbl-dest-ewallet">
                  <input type="radio" name="payout_type" value="ewallet" <?= ($new_invoice['payout_type'] ?? '') === 'ewallet' ? 'checked' : '' ?> onchange="toggleDisburseType(this.value)"/>
                  <span style="font-size:13px;font-weight:600">E-Wallet (GCash / Maya)</span>
                </label>
              </div>
            </div>

            <!-- Bank Destination Fields -->
            <div id="fields-bank" style="display:<?= ($new_invoice['payout_type'] ?? 'bank') === 'bank' ? 'block' : 'none' ?>;margin-bottom:14px;background:#FAF7F2;border:1px solid #E8DED2;border-radius:8px;padding:12px">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
                <label style="font-size:12px;font-weight:600;display:block">Beneficiary Bank <span style="color:var(--red)">*</span>
                  <select class="field-input" name="bank_name" id="disb-bank-name" style="margin-top:4px" onchange="syncBankCode(this)">
                    <option value="">— Select Bank —</option>
                    <?php foreach ($payout_dests['banks'] as $bkey => $binfo): ?>
                      <option value="<?= htmlspecialchars($binfo['name']) ?>" data-code="<?= $binfo['code'] ?>" <?= (stripos($new_invoice['bank_name'] ?? '', $binfo['name']) !== false || ($new_invoice['bank_code'] ?? '') === $binfo['code']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($binfo['name']) ?> (<?= $binfo['code'] ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label style="font-size:12px;font-weight:600;display:block">Bank Code
                  <input class="field-input" type="text" name="bank_code" id="disb-bank-code" value="<?= htmlspecialchars($new_invoice['bank_code'] ?? 'BDO') ?>" style="margin-top:4px"/>
                </label>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <label style="font-size:12px;font-weight:600;display:block">Account Holder Name <span style="color:var(--red)">*</span>
                  <input class="field-input" type="text" name="account_name" value="<?= htmlspecialchars($new_invoice['account_name'] ?: $new_invoice['supplier_name']) ?>" required style="margin-top:4px"/>
                </label>
                <label style="font-size:12px;font-weight:600;display:block">Bank Account Number <span style="color:var(--red)">*</span>
                  <input class="field-input" type="text" name="account_number" value="<?= htmlspecialchars($new_invoice['account_number'] ?? '') ?>" placeholder="e.g. 1042889210" style="margin-top:4px"/>
                </label>
              </div>
            </div>

            <!-- E-Wallet Destination Fields -->
            <div id="fields-ewallet" style="display:<?= ($new_invoice['payout_type'] ?? '') === 'ewallet' ? 'block' : 'none' ?>;margin-bottom:14px;background:#FAF7F2;border:1px solid #E8DED2;border-radius:8px;padding:12px">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
                <label style="font-size:12px;font-weight:600;display:block">E-Wallet Provider <span style="color:var(--red)">*</span>
                  <select class="field-input" name="ewallet_provider" id="disb-ewallet-provider" style="margin-top:4px">
                    <?php foreach ($payout_dests['ewallets'] as $wkey => $winfo): ?>
                      <option value="<?= $wkey ?>" <?= (strtolower($new_invoice['ewallet_provider'] ?? '') === $wkey) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($winfo['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label style="font-size:12px;font-weight:600;display:block">Mobile Number (09XXXXXXXXX) <span style="color:var(--red)">*</span>
                  <input class="field-input" type="text" name="ewallet_mobile_number" value="<?= htmlspecialchars($new_invoice['ewallet_mobile_number'] ?: $new_invoice['supplier_phone']) ?>" placeholder="09171234567" style="margin-top:4px"/>
                </label>
              </div>
              <label style="font-size:12px;font-weight:600;display:block">Registered Account Name <span style="color:var(--red)">*</span>
                <input class="field-input" type="text" name="ewallet_account_name" value="<?= htmlspecialchars($new_invoice['ewallet_account_name'] ?: $new_invoice['supplier_name']) ?>" style="margin-top:4px"/>
              </label>
            </div>

            <!-- Save account checkbox -->
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-main);margin-bottom:14px;cursor:pointer">
              <input type="checkbox" name="save_to_supplier_profile" value="1" checked/>
              <span>Save / update this receiving account as default in <strong><?= htmlspecialchars($new_invoice['supplier_name']) ?></strong>'s profile</span>
            </label>

            <!-- Amount and Notes -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
              <label style="font-size:12.5px;font-weight:600;display:block">Disbursement Amount (₱) <span style="color:var(--red)">*</span>
                <input class="field-input" type="number" step="0.01" min="0.01" max="<?= $new_inv_unpaid ?>" name="amount" value="<?= $new_inv_unpaid ?>" required style="margin-top:4px"/>
              </label>
              <label style="font-size:12.5px;font-weight:600;display:block">Notes / Remarks
                <input class="field-input" type="text" name="notes" placeholder="Optional disbursement remarks" style="margin-top:4px"/>
              </label>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;flex-wrap:wrap">
              <a href="payments.php" class="btn-cancel">Cancel</a>
              <button type="submit" class="btn-save" style="background:#16a34a;border-color:#16a34a;padding:9px 18px">
                ⚡ Disburse via PayMongo (<?= php_currency($new_inv_unpaid) ?>)
              </button>
            </div>
          </form>
        </div>

        <!-- TAB 2: PayMongo Online Checkout Session -->
        <div id="pane-pay-checkout" style="display:none">
          <form method="POST" id="form-paymongo-checkout">
            <input type="hidden" name="action" value="paymongo_checkout"/>
            <input type="hidden" name="invoice_id" value="<?= $new_invoice['id'] ?>"/>

            <div style="background:#EFF6FF;border:1.5px solid #93C5FD;border-radius:10px;padding:14px;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                <div style="width:22px;height:22px;border-radius:50%;background:#2563EB;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800">💳</div>
                <strong style="color:#1E40AF;font-size:13.5px">PayMongo Online Hosted Checkout</strong>
              </div>
              <p style="margin:0;font-size:12px;color:#1D4ED8;line-height:1.4">
                Generates a secure PayMongo checkout session supporting instant <strong>GCash QR (QR Ph)</strong>, <strong>Maya</strong>, <strong>Credit/Debit Card</strong>, and direct online banking. Ideal for authorized card or QR wallet payments.
              </p>
            </div>

            <div style="margin-bottom:16px">
              <label style="font-size:12.5px;font-weight:600;display:block">Payment Amount (₱) <span style="color:var(--red)">*</span>
                <input class="field-input" type="number" step="0.01" min="0.01" max="<?= $new_inv_unpaid ?>" name="amount" value="<?= $new_inv_unpaid ?>" required style="margin-top:4px"/>
                <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Unpaid balance: <?= php_currency($new_inv_unpaid) ?></small>
              </label>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;flex-wrap:wrap">
              <a href="payments.php" class="btn-cancel">Cancel</a>
              <button type="submit" class="btn-save" style="background:#2563eb;border-color:#2563eb;padding:9px 18px">
                🚀 Launch PayMongo Checkout Session
              </button>
            </div>
          </form>
        </div>

        <!-- TAB 3: Manual Payment Entry (Existing) -->
        <div id="pane-pay-manual" style="display:none">
          <form method="POST" id="payment-entry-form" enctype="multipart/form-data">
            <input type="hidden" name="invoice_id" value="<?= $new_invoice['id'] ?>"/>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px">
              <label style="font-size:12.5px;font-weight:600;display:block">Amount to Pay (₱) <span style="color:var(--red)">*</span>
                <input class="field-input" type="number" step="0.01" min="0.01" max="<?= $new_inv_unpaid ?>" name="amount" id="pay-amount" value="<?= $new_inv_unpaid ?>" required style="margin-top:4px"/>
                <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Max payable: <?= php_currency($new_inv_unpaid) ?></small>
              </label>
              <label style="font-size:12.5px;font-weight:600;display:block">Payment Date <span style="color:var(--red)">*</span>
                <input class="field-input" type="date" name="payment_date" id="pay-date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required style="margin-top:4px"/>
                <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Cannot be future-dated for completion</small>
              </label>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px">
              <label style="font-size:12.5px;font-weight:600;display:block">Payment Method <span style="color:var(--red)">*</span>
                <select class="field-input" name="payment_method" id="pay-method" style="margin-top:4px" onchange="toggleRefReq(this.value)">
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="check">Check</option>
                  <option value="ewallet">E-Wallet (GCash / Maya)</option>
                  <option value="online">Online / Card</option>
                  <option value="cash">Cash (Over the counter)</option>
                </select>
              </label>
              <label style="font-size:12.5px;font-weight:600;display:block">Paying Account <span style="color:var(--red)">*</span>
                <select class="field-input" name="paying_account" style="margin-top:4px" required>
                  <?php foreach ($store_paying_accounts as $acct): ?>
                    <option value="<?= htmlspecialchars($acct) ?>"><?= htmlspecialchars($acct) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:12px">
              Reference / Transaction No. <span id="ref-req-star" style="color:var(--red)">*</span>
              <input class="field-input" type="text" name="reference_no" id="pay-ref" placeholder="e.g. Bank Ref, Check #, GCash Ref" style="margin-top:4px"/>
              <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Must be unique across all payment records.</small>
            </label>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:14px;background:#FEFAF4;padding:12px;border:1.5px dashed var(--caramel,#8B4513);border-radius:8px">
              <span style="display:flex;align-items:center;gap:6px">
                <?= icon('file-text', 15) ?>
                <span>Mandatory Proof of Payment (PDF / Image) <span style="color:var(--red)">*</span></span>
              </span>
              <input class="field-input" type="file" name="receipt_file" id="pay-receipt" accept=".pdf,.jpg,.jpeg,.png" style="margin-top:6px;background:#fff"/>
              <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:4px">Upload deposit slip, bank transfer advice, or signed official receipt (PDF, JPG, PNG &le; 10MB). Required to complete payment.</small>
            </label>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:18px">Notes / Remarks
              <textarea class="field-input" name="notes" placeholder="Optional notes for audit or supplier context" style="margin-top:4px;width:100%;min-height:55px"></textarea>
            </label>

            <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap">
              <a href="payments.php" class="btn-cancel">Cancel</a>
              <div style="display:flex;gap:8px">
                <button type="submit" name="action" value="schedule" class="btn-ghost">
                  <?= icon('calendar', 14) ?> Schedule Later
                </button>
                <button type="submit" name="action" value="record_complete" class="btn-save" style="background:var(--caramel,#8B4513);border-color:var(--caramel,#8B4513)">
                  <?= icon('check-circle', 14) ?> Record &amp; Complete Payment
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

    <?php elseif ($payment): ?>
      <!-- ── Payment detail view ── -->
      <div class="table-card" style="padding:22px 24px;max-width:580px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
          <div>
            <h2><?= php_currency($payment['amount']) ?></h2>
            <p class="muted-cell">
              Invoice <strong><?= htmlspecialchars($payment['invoice_number']) ?></strong> &middot; <?= htmlspecialchars($payment['supplier_name']) ?>
            </p>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
            <span class="status-badge status-<?= $payment['status']==='completed'?'approved':($payment['status']==='failed'?'rejected':'pending') ?>">
              <?= status_badge($payment['status']) ?>
            </span>
            <?php if ($payment['status'] === 'completed'): ?>
              <?php if ($payment['supplier_confirmation_status'] === 'confirmed'): ?>
                <span class="status-badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;font-size:11px">
                  <?= icon('check', 11) ?> Confirmed by Supplier
                </span>
              <?php elseif ($payment['supplier_confirmation_status'] === 'disputed'): ?>
                <span class="status-badge" style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;font-size:11px">
                  <?= icon('alert-triangle', 11) ?> Disputed by Supplier
                </span>
              <?php else: ?>
                <span class="status-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-size:11px">
                  <?= icon('clock', 11) ?> Awaiting Supplier Confirmation
                </span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
          <div><strong>Payment Date:</strong> <?= date('M d, Y', strtotime($payment['payment_date'] ?: $payment['completed_at'] ?: $payment['scheduled_at'])) ?></div>
          <div><strong>Method:</strong> <?= ucwords(str_replace('_',' ',$payment['payment_method'])) ?></div>
          <div><strong>Paying Account:</strong> <?= htmlspecialchars($payment['paying_account'] ?: 'Store Operations') ?></div>
          <div><strong>Reference No:</strong> <?= htmlspecialchars($payment['reference_no'] ?: '— (Cash)') ?></div>
        </div>

        <?php if (!empty($payment['paymongo_payout_id']) || !empty($payment['paymongo_checkout_id'])): ?>
          <div style="margin-top:14px;padding:12px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div style="display:flex;align-items:center;gap:10px">
              <div style="background:#16a34a;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:900;font-size:14px">
                ✓
              </div>
              <div>
                <div style="font-size:11px;font-weight:800;color:#166534;text-transform:uppercase;letter-spacing:0.04em">PayMongo Certified Gateway</div>
                <div style="font-size:13px;font-weight:600;color:#14532d">
                  <?= !empty($payment['paymongo_payout_id']) ? 'Automated Disbursement Payout' : 'Verified Online Checkout Session' ?>
                  &bull; <span style="font-family:monospace;font-size:12px"><?= htmlspecialchars($payment['paymongo_payout_id'] ?: $payment['paymongo_checkout_id']) ?></span>
                </div>
              </div>
            </div>
            <span style="background:#dcfce7;color:#166534;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700">
              Disbursed &bull; Verified
            </span>
          </div>
        <?php endif; ?>

        <!-- Proof of payment attachment -->
        <div style="margin-top:16px;padding:12px 14px;background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px">
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">Proof of Payment Document</div>
          <?php if (!empty($payment['receipt_attachment_path'])): 
            $is_voucher = str_contains($payment['receipt_attachment_path'], 'paymongo_voucher') || str_ends_with($payment['receipt_attachment_path'], '.html');
          ?>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
              <span style="font-size:13px;display:inline-flex;align-items:center;gap:6px">
                <?= icon('file-text', 16, '', 'color:var(--caramel)') ?>
                <strong><?= htmlspecialchars($payment['receipt_file_name'] ?: basename($payment['receipt_attachment_path'])) ?></strong>
                <span style="font-size:11px;color:var(--text-muted)">(<?= round(($payment['receipt_file_size'] ?: 0)/1024, 1) ?> KB)</span>
              </span>
              <a href="../<?= htmlspecialchars($payment['receipt_attachment_path']) ?>" target="_blank" class="btn-ghost" style="font-size:12px;padding:4px 10px;background:<?= $is_voucher ? '#16a34a' : 'transparent' ?>;color:<?= $is_voucher ? '#fff' : 'inherit' ?>">
                <?= icon('eye', 13) ?> <?= $is_voucher ? '⚡ View / Print Official Voucher' : 'View Document' ?>
              </a>
            </div>
          <?php else: ?>
            <p style="font-size:12.5px;color:var(--text-muted);margin:0">No receipt file uploaded.</p>
          <?php endif; ?>
        </div>

        <?php if ($payment['notes']): ?>
          <p style="font-size:12.5px;color:var(--text-muted);margin-top:12px;padding:8px 12px;background:#f8f9fa;border-radius:8px">
            <strong>Notes:</strong> <?= htmlspecialchars($payment['notes']) ?>
          </p>
        <?php endif; ?>

        <!-- Supplier Confirmation Log / Dispute Note -->
        <?php if ($payment['supplier_confirmation_status'] === 'confirmed'): ?>
          <div style="margin-top:14px;padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12.5px;color:#166534">
            <strong>Confirmed Received by Supplier:</strong>
            <div>Timestamp: <?= date('M d, Y g:i A', strtotime($payment['supplier_confirmed_at'])) ?></div>
            <?php if ($payment['supplier_confirmed_name']): ?>
              <div>Officer: <?= htmlspecialchars($payment['supplier_confirmed_name']) ?></div>
            <?php endif; ?>
            <?php if ($payment['supplier_confirmation_notes']): ?>
              <div style="margin-top:4px">Notes: <?= htmlspecialchars($payment['supplier_confirmation_notes']) ?></div>
            <?php endif; ?>
          </div>
        <?php elseif ($payment['supplier_confirmation_status'] === 'disputed'): ?>
          <div style="margin-top:14px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:12.5px;color:#991b1b">
            <strong>Disputed by Supplier:</strong>
            <div>Date: <?= date('M d, Y g:i A', strtotime($payment['supplier_disputed_at'])) ?></div>
            <div style="margin-top:2px">Reason: <strong><?= htmlspecialchars($payment['supplier_dispute_reason']) ?></strong></div>
            <?php if ($payment['supplier_confirmation_notes']): ?>
              <div style="margin-top:4px">Details: <?= htmlspecialchars($payment['supplier_confirmation_notes']) ?></div>
            <?php endif; ?>
            <?php if ($payment['supplier_dispute_attachment']): ?>
              <div style="margin-top:6px">
                <a href="../<?= htmlspecialchars($payment['supplier_dispute_attachment']) ?>" target="_blank" class="btn-ghost" style="font-size:11px;padding:2px 8px">
                  View Supplier Dispute Attachment
                </a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($payment['status'] === 'completed'): ?>
          <!-- Immutability Badge -->
          <div style="margin-top:18px;font-size:11.5px;color:var(--text-muted);display:flex;align-items:center;gap:6px">
            <?= icon('lock', 12) ?> <span>This payment is completed and permanently locked for accounting integrity.</span>
          </div>
        <?php elseif ($payment['status'] === 'scheduled' && has_permission('procurement.payment.process')): ?>
          <!-- Scheduled Actions -->
          <div style="margin-top:20px;border-top:1px solid var(--border,#e8ded2);padding-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" class="btn-save" onclick="openCompleteScheduledModal()">
              <?= icon('check-circle', 14) ?> Complete Payment (Upload Proof)
            </button>
            <form method="POST" onsubmit="return confirm('Cancel this scheduled payment?')">
              <input type="hidden" name="action" value="cancel"/>
              <input type="hidden" name="id" value="<?= $payment['id'] ?>"/>
              <button type="submit" class="btn-cancel">Cancel Schedule</button>
            </form>
          </div>
          <form method="POST" style="margin-top:10px;display:flex;gap:8px">
            <input type="hidden" name="action" value="fail"/>
            <input type="hidden" name="id" value="<?= $payment['id'] ?>"/>
            <input class="field-input" type="text" name="fail_reason" placeholder="Reason payment failed" style="flex:1;padding:7px 10px"/>
            <button type="submit" class="act-btn"><?= icon('x', 13) ?> Mark Failed</button>
          </form>
        <?php endif; ?>
      </div>
      <p style="margin-top:14px"><a href="payments.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">&larr; Back to Payments List</a></p>

      <!-- Complete Scheduled Payment Modal -->
      <?php if ($payment['status'] === 'scheduled'): ?>
      <div class="modal-bg" id="complete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
        <div class="modal" style="background:#fff;border-radius:12px;max-width:480px;width:92%;padding:22px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;color:var(--text-main)">Complete Scheduled Payment</h3>
            <button type="button" class="btn-cancel" onclick="closeCompleteScheduledModal()" style="padding:2px 8px">&times;</button>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="complete"/>
            <input type="hidden" name="id" value="<?= $payment['id'] ?>"/>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:10px">Payment Date <span style="color:var(--red)">*</span>
              <input class="field-input" type="date" name="payment_date" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required style="margin-top:4px"/>
            </label>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:10px">Paying Account <span style="color:var(--red)">*</span>
              <select class="field-input" name="paying_account" style="margin-top:4px" required>
                <?php foreach ($store_paying_accounts as $acct): ?>
                  <option value="<?= htmlspecialchars($acct) ?>" <?= ($payment['paying_account'] === $acct) ? 'selected' : '' ?>><?= htmlspecialchars($acct) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:10px">Reference No.
              <input class="field-input" type="text" name="reference_no" value="<?= htmlspecialchars($payment['reference_no'] ?? '') ?>" placeholder="Transaction / Cheque Reference" style="margin-top:4px"/>
            </label>

            <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:16px;background:#FEFAF4;padding:12px;border:1.5px dashed var(--caramel);border-radius:8px">
              Proof of Payment File (PDF / Image) <span style="color:var(--red)">*</span>
              <input class="field-input" type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png" required style="margin-top:4px;background:#fff"/>
              <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Mandatory: upload receipt before marking completed.</small>
            </label>

            <div style="display:flex;justify-content:flex-end;gap:8px">
              <button type="button" class="btn-cancel" onclick="closeCompleteScheduledModal()">Cancel</button>
              <button type="submit" class="btn-save"><?= icon('check', 14) ?> Confirm &amp; Complete</button>
            </div>
          </form>
        </div>
      </div>
      <script>
      function openCompleteScheduledModal() {
        const m = document.getElementById('complete-modal');
        if (m) { m.style.display = 'flex'; }
      }
      function closeCompleteScheduledModal() {
        const m = document.getElementById('complete-modal');
        if (m) { m.style.display = 'none'; }
      }
      </script>
      <?php endif; ?>

    <?php else: ?>
      <!-- ── List view ── -->
      <?php if (has_permission('procurement.payment.process')): ?>
      <div class="table-card" style="padding:0;margin-bottom:18px;overflow:hidden">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1.5px solid var(--border)">
          <h3 style="font-size:13.5px;font-weight:700;color:var(--espresso);display:flex;align-items:center;gap:6px;margin:0">
            <?= icon('invoice', 16, '', 'color:var(--caramel)') ?> Invoices Awaiting Payment
          </h3>
          <?php if (!empty($awaiting)): ?>
            <span class="count-badge" style="background:var(--amber-lt,#FEF3C7);color:var(--amber,#92400e)"><?= count($awaiting) ?></span>
          <?php endif; ?>
        </div>

        <?php if (empty($awaiting)): ?>
          <div style="padding:30px 18px;text-align:center;color:var(--text-muted);font-size:13px">
            <?= icon('check', 16, '', 'color:var(--green);vertical-align:middle;margin-right:4px') ?> No approved invoices currently awaiting payment.
          </div>
        <?php else: ?>
          <div>
            <?php foreach ($awaiting as $a): 
              $rem = round((float)$a['total_amount'] - (float)$a['amount_paid'], 2);
            ?>
              <div class="kf-invoice-row" onclick="window.location.href='payments.php?new_for_invoice=<?= $a['id'] ?>'">
                <div class="kf-invoice-icon"><?= icon('invoice', 18, '', 'color:var(--espresso)') ?></div>
                <div class="kf-invoice-info">
                  <div class="kf-invoice-num">
                    <?= htmlspecialchars($a['invoice_number']) ?>
                  </div>
                  <div class="kf-invoice-supplier">
                    <?= htmlspecialchars($a['supplier_name']) ?>
                    <?php if (!empty($a['due_date'])): ?>
                      &middot; Due <?= date('M d, Y', strtotime($a['due_date'])) ?>
                    <?php endif; ?>
                    <?php if ($a['amount_paid'] > 0): ?>
                      &middot; <span style="color:var(--green);font-weight:600">Paid: <?= php_currency($a['amount_paid']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="kf-invoice-amount" style="text-align:right">
                  <div><?= php_currency($rem) ?></div>
                  <small style="font-size:11px;color:var(--text-muted);font-weight:500">of <?= php_currency($a['total_amount']) ?></small>
                </div>
                <button class="act-btn act-activate" onclick="event.stopPropagation();window.location.href='payments.php?new_for_invoice=<?= $a['id'] ?>'">
                  Pay Now
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <style>
      .kf-invoice-row {
        display: flex; align-items: center; gap: 12px;
        padding: 13px 18px;
        border-bottom: 1px solid #F2E6D6;
        cursor: pointer;
        transition: background .12s ease;
      }
      .kf-invoice-row:last-child { border-bottom: none; }
      .kf-invoice-row:hover { background: #FEFAF4; }
      .kf-invoice-icon {
        width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
        background: var(--amber-lt, #FEF3C7);
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
      }
      .kf-invoice-info { flex: 1; min-width: 0; }
      .kf-invoice-num {
        font-size: 13px; font-weight: 700; color: var(--espresso);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .kf-invoice-supplier {
        font-size: 11.5px; color: var(--text-muted); margin-top: 1px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .kf-invoice-amount {
        font-size: 14px; font-weight: 800; color: var(--espresso);
        flex-shrink: 0; white-space: nowrap;
      }
      @media (max-width: 600px) {
        .kf-invoice-row { flex-wrap: wrap; }
        .kf-invoice-amount { order: 3; margin-left: 48px; }
        .kf-invoice-info { order: 2; }
      }
      </style>
      <?php endif; ?>

      <div class="filter-bar" style="padding:0">
        <a href="payments.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="payments.php?status=scheduled" class="filter-pill <?= $filter==='scheduled'?'active':'' ?>">Scheduled</a>
        <a href="payments.php?status=completed" class="filter-pill <?= $filter==='completed'?'active':'' ?>">Completed</a>
        <a href="payments.php?status=failed" class="filter-pill <?= $filter==='failed'?'active':'' ?>">Failed</a>
      </div>
      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view all 8 columns</span>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr>
              <th class="col-sticky">Invoice</th>
              <th>Supplier</th>
              <th>Amount</th>
              <th>Method &amp; Account</th>
              <th>Date</th>
              <th>Status</th>
              <th>Supplier Confirmation</th>
              <th>Proof</th>
              <th style="text-align:center;width:80px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($payments)): ?>
            <tr class="empty-row"><td colspan="9"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No payments recorded yet.</td></tr>
          <?php else: foreach ($payments as $p): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700"><?= htmlspecialchars($p['invoice_number']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700"><?= php_currency($p['amount']) ?></td>
              <td style="font-size:12px">
                <strong><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></strong>
                <?php if ($p['paying_account']): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($p['paying_account']) ?></div>
                <?php endif; ?>
              </td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($p['payment_date'] ?: $p['completed_at'] ?: $p['scheduled_at'])) ?></td>
              <td><span class="status-badge status-<?= $p['status']==='completed'?'approved':($p['status']==='failed'?'rejected':'pending') ?>"><?= status_badge($p['status']) ?></span></td>
              <td style="font-size:12px">
                <?php if ($p['status'] === 'completed'): ?>
                  <?php if ($p['supplier_confirmation_status'] === 'confirmed'): ?>
                    <span style="color:#16a34a;font-weight:700">● Confirmed</span>
                  <?php elseif ($p['supplier_confirmation_status'] === 'disputed'): ?>
                    <span style="color:#dc2626;font-weight:700">● Disputed</span>
                  <?php else: ?>
                    <span style="color:#d97706;font-weight:700">● Awaiting</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--text-muted)">&mdash;</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($p['receipt_attachment_path'])): ?>
                  <a href="../<?= htmlspecialchars($p['receipt_attachment_path']) ?>" target="_blank" title="View Proof" style="color:var(--caramel);display:inline-flex;align-items:center;gap:4px">
                    <?= icon('file-text', 14) ?> <span>View</span>
                  </a>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:11.5px">None</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center"><button class="act-btn" onclick="window.location.href='payments.php?id=<?= $p['id'] ?>'"><?= icon('eye', 13) ?> View</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
function switchPayTab(tab) {
  const pDisb = document.getElementById('pane-pay-disburse');
  const pCheck = document.getElementById('pane-pay-checkout');
  const pMan = document.getElementById('pane-pay-manual');
  if (pDisb) pDisb.style.display = (tab === 'disburse') ? 'block' : 'none';
  if (pCheck) pCheck.style.display = (tab === 'checkout') ? 'block' : 'none';
  if (pMan) pMan.style.display = (tab === 'manual') ? 'block' : 'none';

  const bDisb = document.getElementById('tab-btn-disburse');
  const bCheck = document.getElementById('tab-btn-checkout');
  const bMan = document.getElementById('tab-btn-manual');
  if (bDisb) bDisb.classList.toggle('active', tab === 'disburse');
  if (bCheck) bCheck.classList.toggle('active', tab === 'checkout');
  if (bMan) bMan.classList.toggle('active', tab === 'manual');
}

function toggleDisburseType(type) {
  const fBank = document.getElementById('fields-bank');
  const fEwallet = document.getElementById('fields-ewallet');
  if (fBank) fBank.style.display = (type === 'bank') ? 'block' : 'none';
  if (fEwallet) fEwallet.style.display = (type === 'ewallet') ? 'block' : 'none';
}

function syncBankCode(select) {
  const opt = select.options[select.selectedIndex];
  if (opt && opt.dataset.code) {
    const codeInp = document.getElementById('disb-bank-code');
    if (codeInp) codeInp.value = opt.dataset.code;
  }
}

function toggleRefReq(method) {
  const star = document.getElementById('ref-req-star');
  const refInput = document.getElementById('pay-ref');
  if (!star || !refInput) return;
  if (method === 'cash') {
    star.style.display = 'none';
    refInput.required = false;
  } else {
    star.style.display = 'inline';
    refInput.required = true;
  }
}

const payMethodSelect = document.getElementById('pay-method');
if (payMethodSelect) {
  toggleRefReq(payMethodSelect.value);
}
</script>
</body>
</html>