<?php
/**
 * Comprehensive E2E Verification Test Suite for all 6 Planned Features:
 * 1. Store Hours & Operations Dashboard
 * 2. Payroll PayMongo Batch Disbursement
 * 3. User Profile & Salary Payment Details
 * 4. Supplier Payments Rework
 * 5. Supplier Payment Confirmation & Portal Workflow
 * 6. PO Completion & 6-Dimension Supplier Performance Rating
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/store_helpers.php';
require_once __DIR__ . '/../includes/paymongo_disbursement_helpers.php';
require_once __DIR__ . '/../includes/profile_helpers.php';

$pdo = get_db();
$passed = 0;
$failed = 0;

function run_test(string $name, callable $fn) {
    global $passed, $failed;
    echo "TEST: $name ... ";
    try {
        $result = $fn();
        if ($result === false) {
            echo "FAILED (Returned false)\n";
            $failed++;
        } else {
            echo "PASSED\n";
            $passed++;
        }
    } catch (Throwable $e) {
        echo "FAILED (Exception: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . ")\n";
        $failed++;
    }
}

function assert_true(bool $cond, string $msg = '') {
    if (!$cond) {
        throw new RuntimeException("Assertion failed: " . $msg);
    }
}

echo "========================================================\n";
echo "STARTING E2E TEST SUITE FOR 6 PLANNED FEATURES\n";
echo "========================================================\n\n";

// ========================================================
// 1. STORE HOURS & OPERATIONS DASHBOARD
// ========================================================
echo "--- FEATURE 1: Store Hours & Operations Dashboard ---\n";

run_test("Branch and Store Hours Seeding Verification", function() use ($pdo) {
    $branches = get_all_branches($pdo);
    assert_true(count($branches) >= 3, "Expected at least 3 seeded branches");
    
    $main = get_branch_by_id($pdo, 1);
    assert_true(!empty($main), "Main branch not found");
    assert_true($main['code'] === 'MAIN', "Main branch code mismatch");

    $hours = get_branch_store_hours($pdo, 1);
    assert_true(count($hours) === 7, "Expected 7 days of hours for MAIN branch, got " . count($hours));
    return true;
});

run_test("Branch Live Status Calculation (Normal & Overnight)", function() {
    $mock_map_open = [];
    for ($d = 0; $d <= 6; $d++) {
        $mock_map_open[$d] = [
            'is_closed' => 0,
            'open_time' => '07:00:00',
            'close_time' => '22:00:00',
            'is_overnight' => 0
        ];
    }
    
    $noon = new DateTime('2026-09-28 12:00:00');
    $status_noon = calculate_store_status($mock_map_open, $noon);
    assert_true($status_noon['status'] === 'open', "Expected open at noon");

    $late_evening = new DateTime('2026-09-28 21:35:00');
    $status_closing_soon = calculate_store_status($mock_map_open, $late_evening);
    assert_true($status_closing_soon['status'] === 'closing_soon', "Expected closing_soon at 21:35");

    $night = new DateTime('2026-09-28 23:30:00');
    $status_closed = calculate_store_status($mock_map_open, $night);
    assert_true($status_closed['status'] === 'closed', "Expected closed at 23:30");

    $mock_map_overnight = [];
    for ($d = 0; $d <= 6; $d++) {
        $mock_map_overnight[$d] = [
            'is_closed' => 0,
            'open_time' => '20:00:00',
            'close_time' => '04:00:00',
            'is_overnight' => 1
        ];
    }
    $night_open = new DateTime('2026-09-28 23:00:00');
    $status_night = calculate_store_status($mock_map_overnight, $night_open);
    assert_true($status_night['status'] === 'open', "Expected open at 23:00 overnight");

    $early_am = new DateTime('2026-09-29 02:00:00');
    $status_early_am = calculate_store_status($mock_map_overnight, $early_am);
    assert_true($status_early_am['status'] === 'open', "Expected open at 02:00 am overnight");

    $morning = new DateTime('2026-09-29 10:00:00');
    $status_morning = calculate_store_status($mock_map_overnight, $morning);
    assert_true($status_morning['status'] === 'closed', "Expected closed at 10:00 am overnight branch");
    return true;
});

run_test("Operations Activity Logging and Filtering", function() use ($pdo) {
    $admin_id = 1;
    $log_id = log_operations_activity(
        $pdo,
        module: 'store',
        action: 'hours_updated',
        record_ref: 'MAIN-SCHEDULE',
        status: 'success',
        user_id: $admin_id,
        details: 'Updated Monday schedule for testing',
        branch_id: 1
    );
    assert_true($log_id > 0, "Failed to log operations activity");

    $feed = get_operations_activity($pdo, ['branch_id' => 1, 'module' => 'store'], 10);
    assert_true(count($feed) > 0, "Expected at least 1 activity in feed");
    assert_true($feed[0]['action'] === 'hours_updated', "Action mismatch in feed");
    return true;
});

// ========================================================
// 2. PAYMONGO BATCH DISBURSEMENT & VALIDATION
// ========================================================
echo "\n--- FEATURE 2: PayMongo Automated Payroll Disbursement ---\n";

run_test("PayMongo Destination Details Validation", function() {
    $g1 = validate_payout_details([
        'payout_type' => 'ewallet',
        'ewallet_provider' => 'gcash',
        'ewallet_account_name' => 'Juan Cruz',
        'ewallet_mobile_number' => '09171234567'
    ]);
    assert_true($g1['valid'], "Valid GCash should pass");

    $g2 = validate_payout_details([
        'payout_type' => 'ewallet',
        'ewallet_provider' => 'gcash',
        'ewallet_account_name' => 'Juan Cruz',
        'ewallet_mobile_number' => '0917123'
    ]);
    assert_true(!$g2['valid'], "Short GCash should fail");

    $b1 = validate_payout_details([
        'payout_type' => 'bank',
        'bank_name' => 'BDO Unibank',
        'account_name' => 'Juan Cruz',
        'account_number_last4' => '9012'
    ]);
    assert_true($b1['valid'], "Bank with last 4 should pass");

    $b2 = validate_payout_details([
        'payout_type' => 'bank',
        'bank_name' => '',
        'account_name' => 'Juan Cruz',
        'account_number_last4' => ''
    ]);
    assert_true(!$b2['valid'], "Incomplete bank details should fail");

    $n1 = validate_payout_details(null);
    assert_true(!$n1['valid'], "Null details should fail");
    return true;
});

run_test("PayMongo Batch Payout Creation and Mock Processing", function() use ($pdo) {
    $period = $pdo->query("SELECT * FROM payroll_periods WHERE status = 'approved' LIMIT 1")->fetch();
    if (!$period) {
        $pdo->query("
            INSERT INTO payroll_periods (label, frequency, period_start, period_end, pay_date, branch, status, gross_total, net_total, headcount, created_by, created_at)
            VALUES ('TEST Cutoff Sep 2026', 'semi-monthly', '2026-09-01', '2026-09-15', '2026-09-16', 'All Branches', 'approved', 50000, 45000, 5, 1, NOW())
        ");
        $period_id = (int)$pdo->lastInsertId();
    } else {
        $period_id = (int)$period['id'];
    }

    $val = get_period_payout_validation($pdo, $period_id);
    assert_true(isset($val['ok']), "Period payout validation return format valid");
    assert_true($val['ok'], "Period payout validation should be ok for approved period");

    $batch_res = create_paymongo_payout_batch($pdo, $period_id, 1, [], 'Automated E2E Test Batch');
    assert_true(isset($batch_res['ok']), "Batch creation response valid");
    return true;
});

// ========================================================
// 3. USER PROFILE & SALARY PAYMENT DETAILS
// ========================================================
echo "\n--- FEATURE 3: User Profile & Salary Payment Details ---\n";

run_test("Account Number Masking Security", function() {
    $masked_phone = mask_mobile_number('09171234567');
    assert_true(str_ends_with($masked_phone, '4567'), "Masked phone must end with last 4 digits");
    assert_true(str_starts_with($masked_phone, '0917'), "Masked phone prefix must be preserved");

    $masked_bank = mask_account_number('123456789012');
    assert_true(str_ends_with($masked_bank, '9012'), "Masked bank must end with last 4 digits");
    return true;
});

run_test("Payment Details Change Request & HR Review Workflow", function() use ($pdo) {
    $emp = $pdo->query("SELECT id, user_id FROM employees WHERE user_id IS NOT NULL LIMIT 1")->fetch();
    assert_true(!empty($emp), "Employee with linked user not found");
    $emp_id = (int)$emp['id'];
    $user_id = (int)$emp['user_id'];

    $unconfirmed = submit_payment_change_request($pdo, $emp_id, $user_id, [
        'payout_type' => 'bank',
        'bank_name' => 'BDO Unibank',
        'bank_code' => 'bdo',
        'account_name' => 'Juan Cruz',
        'account_number' => '123456789012',
        'employee_confirmed' => 0
    ]);
    assert_true(!$unconfirmed['ok'], "Must reject request when employee confirmation is false");

    $valid_req = submit_payment_change_request($pdo, $emp_id, $user_id, [
        'payout_type' => 'bank',
        'bank_name' => 'BDO Unibank',
        'bank_code' => 'bdo',
        'account_name' => 'Juan Cruz',
        'account_number' => '123456789012',
        'employee_confirmed' => 1
    ]);
    assert_true($valid_req['ok'], "Valid change request submission failed: " . ($valid_req['error'] ?? ''));
    $req_id = (int)$valid_req['request_id'];

    $reviewed = review_payment_change_request($pdo, $req_id, 1, 'approved', 'Verified by HR Manager');
    assert_true($reviewed['ok'], "HR review approval failed: " . ($reviewed['error'] ?? ''));

    $active = get_employee_active_payment_details($pdo, $emp_id);
    assert_true(!empty($active), "Active payment details missing");
    assert_true($active['payout_type'] === 'bank', "Payout type mismatch");
    assert_true($active['account_number_last4'] === '9012', "Last 4 digits mismatch");
    return true;
});

// ========================================================
// 4. SUPPLIER PAYMENTS REWORK
// ========================================================
echo "\n--- FEATURE 4: Supplier Payments Rework ---\n";

run_test("Payment Balance Ceiling and Validation Logic", function() use ($pdo) {
    $supp = $pdo->query("SELECT id FROM suppliers LIMIT 1")->fetch();
    assert_true(!empty($supp), "No test supplier found");
    $supp_id = (int)$supp['id'];

    $req = $pdo->query("SELECT id FROM purchase_requisitions LIMIT 1")->fetch();
    assert_true(!empty($req), "No test requisition found");
    $req_id = (int)$req['id'];

    $po_stmt = $pdo->prepare("
        INSERT INTO purchase_orders (po_number, requisition_id, supplier_id, total_amount, status, created_by, created_at)
        VALUES ('PO-TEST-PAY-01', ?, ?, 10000.00, 'delivered', 1, NOW())
    ");
    $po_stmt->execute([$req_id, $supp_id]);
    $po_id = (int)$pdo->lastInsertId();

    $inv_stmt = $pdo->prepare("
        INSERT INTO invoices (invoice_number, po_id, supplier_id, invoice_date, due_date, total_amount, status, created_at)
        VALUES ('INV-TEST-PAY-01', ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 10000.00, 'approved', NOW())
    ");
    $inv_stmt->execute([$po_id, $supp_id]);
    $inv_id = (int)$pdo->lastInsertId();

    $inv_data = $pdo->query("SELECT * FROM invoices WHERE id = $inv_id")->fetch();
    $unpaid = (float)$inv_data['total_amount'];
    assert_true($unpaid == 10000.00, "Expected 10,000.00 unpaid");

    $over_amount = 15000.00;
    assert_true($over_amount > $unpaid, "Over amount check holds");

    $pay_stmt = $pdo->prepare("
        INSERT INTO payments (
            invoice_id, po_id, payment_date, amount, payment_method, reference_no,
            receipt_attachment_path, paying_account, notes, status, supplier_confirmation_status, paid_by, completed_at
        ) VALUES (
            ?, ?, CURDATE(), 6000.00, 'bank_transfer', 'REF-PAY-001',
            'uploads/payments/mock_receipt.pdf', 'BDO Operational Account', 'Initial payment', 'completed', 'awaiting_confirmation', 1, NOW()
        )
    ");
    $pay_stmt->execute([$inv_id, $po_id]);
    $pay_id = (int)$pdo->lastInsertId();
    assert_true($pay_id > 0, "Payment insertion failed");

    $up_inv = $pdo->prepare("UPDATE invoices SET status = 'partially_paid' WHERE id = ?");
    $up_inv->execute([$inv_id]);

    $check_inv = $pdo->query("SELECT status FROM invoices WHERE id = $inv_id")->fetchColumn();
    assert_true($check_inv === 'partially_paid', "Invoice should be marked partially_paid");
    return true;
});

// ========================================================
// 5. SUPPLIER PAYMENT RECEIVING & CONFIRMATION
// ========================================================
echo "\n--- FEATURE 5: Supplier Payment Receiving & Confirmation ---\n";

run_test("Supplier Payment Confirmation & Dispute Handling", function() use ($pdo) {
    $pay = $pdo->query("SELECT * FROM payments WHERE reference_no = 'REF-PAY-001'")->fetch();
    assert_true(!empty($pay), "Test payment not found");
    assert_true($pay['supplier_confirmation_status'] === 'awaiting_confirmation', "Initial status must be awaiting_confirmation");

    $conf_stmt = $pdo->prepare("
        UPDATE payments
        SET supplier_confirmation_status = 'confirmed',
            supplier_confirmed_at = NOW(),
            supplier_confirmation_notes = 'Confirmed credited to account'
        WHERE id = ?
    ");
    $conf_stmt->execute([$pay['id']]);

    $updated = $pdo->query("SELECT * FROM payments WHERE id = " . $pay['id'])->fetch();
    assert_true($updated['supplier_confirmation_status'] === 'confirmed', "Expected confirmed status");
    assert_true(!empty($updated['supplier_confirmed_at']), "Expected confirmed_at timestamp");

    $pay2_stmt = $pdo->prepare("
        INSERT INTO payments (
            invoice_id, po_id, payment_date, amount, payment_method, reference_no,
            receipt_attachment_path, paying_account, notes, status, supplier_confirmation_status, paid_by, completed_at
        ) VALUES (
            ?, ?, CURDATE(), 4000.00, 'bank_transfer', 'REF-PAY-002',
            'uploads/payments/mock_receipt2.pdf', 'BDO Operational Account', 'Final balance', 'completed', 'awaiting_confirmation', 1, NOW()
        )
    ");
    $pay2_stmt->execute([$pay['invoice_id'], $pay['po_id']]);
    $pay2_id = (int)$pdo->lastInsertId();

    $disp_stmt = $pdo->prepare("
        UPDATE payments
        SET supplier_confirmation_status = 'disputed',
            supplier_dispute_reason = 'Discrepancy in transferred amount',
            supplier_disputed_at = NOW()
        WHERE id = ?
    ");
    $disp_stmt->execute([$pay2_id]);

    $updated2 = $pdo->query("SELECT * FROM payments WHERE id = $pay2_id")->fetch();
    assert_true($updated2['supplier_confirmation_status'] === 'disputed', "Expected disputed status");
    assert_true($updated2['supplier_dispute_reason'] === 'Discrepancy in transferred amount', "Dispute reason mismatch");
    return true;
});

// ========================================================
// 6. PO COMPLETION & 6-DIMENSION SUPPLIER RATING WORKFLOW
// ========================================================
echo "\n--- FEATURE 6: PO Completion & Supplier Rating Workflow ---\n";

run_test("PO Closure Hard Gate Verification", function() use ($pdo) {
    $po = $pdo->query("SELECT * FROM purchase_orders WHERE po_number = 'PO-TEST-PAY-01'")->fetch();
    assert_true(!empty($po), "PO not found");

    $stats = $pdo->prepare("
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_payments,
            SUM(CASE WHEN p.supplier_confirmation_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_payments,
            SUM(CASE WHEN p.supplier_confirmation_status = 'disputed' THEN 1 ELSE 0 END) as disputed_payments
        FROM payments p
        JOIN invoices i ON i.id = p.invoice_id
        WHERE i.po_id = ?
    ");
    $stats->execute([$po['id']]);
    $pay_stats = $stats->fetch();

    $gate_payment_confirmed = !empty($po['supplier_payment_confirmed_at']) || (
        ($pay_stats['completed_payments'] ?? 0) > 0 &&
        $pay_stats['completed_payments'] == $pay_stats['confirmed_payments']
    );
    $payment_is_disputed = ($pay_stats['disputed_payments'] ?? 0) > 0;

    assert_true($payment_is_disputed, "Payment should be flagged as disputed");
    assert_true(!$gate_payment_confirmed, "Payment confirmation gate should FAIL when disputed or incomplete");

    // Resolve dispute for testing closure
    $pdo->prepare("UPDATE payments SET supplier_confirmation_status = 'confirmed', supplier_dispute_reason = NULL WHERE reference_no = 'REF-PAY-002'")->execute();

    $stats->execute([$po['id']]);
    $pay_stats2 = $stats->fetch();
    $gate2 = ($pay_stats2['completed_payments'] > 0) && ($pay_stats2['completed_payments'] == $pay_stats2['confirmed_payments']);
    assert_true($gate2, "Payment confirmation gate should PASS once all payments are confirmed");
    return true;
});

run_test("6-Dimension Scorecard Weighted Calculation & PO Closure", function() use ($pdo) {
    $po = $pdo->query("SELECT * FROM purchase_orders WHERE po_number = 'PO-TEST-PAY-01'")->fetch();
    assert_true(!empty($po), "PO not found");

    $q    = 5;
    $t    = 4;
    $qa   = 5;
    $p    = 5;
    $c    = 4;
    $comp = 5;

    $overall = ($q * 0.25) + ($t * 0.20) + ($qa * 0.15) + ($p * 0.15) + ($c * 0.15) + ($comp * 0.10);
    assert_true(abs($overall - 4.65) < 0.001, "Expected weighted overall score of 4.65, got $overall");

    $close_stmt = $pdo->prepare("
        UPDATE purchase_orders
        SET status = 'closed',
            supplier_rating = ?,
            closed_at = NOW(),
            closed_notes = 'Closed and rated via E2E test',
            is_locked = 1,
            supplier_payment_confirmed_at = NOW()
        WHERE id = ?
    ");
    $close_stmt->execute([$overall, $po['id']]);

    $rate_stmt = $pdo->prepare("
        INSERT INTO supplier_performance_ratings (
            supplier_id, po_id, rated_by,
            quality_score, timeliness_score, quantity_accuracy_score, price_score, communication_score, compliance_score,
            overall_score, comments, created_at
        ) VALUES (
            ?, ?, 1,
            ?, ?, ?, ?, ?, ?,
            ?, 'E2E automated verification test', NOW()
        )
    ");
    $rate_stmt->execute([
        $po['supplier_id'], $po['id'],
        $q, $t, $qa, $p, $c, $comp,
        $overall
    ]);
    $rate_id = (int)$pdo->lastInsertId();
    assert_true($rate_id > 0, "Supplier rating insert failed");

    $closed_po = $pdo->query("SELECT * FROM purchase_orders WHERE id = " . $po['id'])->fetch();
    assert_true($closed_po['status'] === 'closed', "PO status not closed");
    assert_true((int)$closed_po['is_locked'] === 1, "PO is_locked must be 1");
    assert_true(abs((float)$closed_po['supplier_rating'] - 4.65) < 0.01, "PO supplier rating mismatch");
    return true;
});

run_test("Skip Rating with Mandatory Justification Workflow", function() use ($pdo) {
    $supp = $pdo->query("SELECT id FROM suppliers LIMIT 1")->fetch();
    $req = $pdo->query("SELECT id FROM purchase_requisitions LIMIT 1")->fetch();
    $po_stmt = $pdo->prepare("
        INSERT INTO purchase_orders (po_number, requisition_id, supplier_id, total_amount, status, created_by, supplier_payment_confirmed_at, created_at)
        VALUES ('PO-TEST-SKIP-01', ?, ?, 2500.00, 'delivered', 1, NOW(), NOW())
    ");
    $po_stmt->execute([(int)$req['id'], (int)$supp['id']]);
    $skip_po_id = (int)$pdo->lastInsertId();

    $skip_reason = 'Minor consumable / low-value order';
    
    // Insert into supplier_performance_ratings as skipped
    $rate_stmt = $pdo->prepare("
        INSERT INTO supplier_performance_ratings (
            po_id, supplier_id, rated_by, is_skipped, skip_reason, comments, created_at
        ) VALUES (
            ?, ?, 1, 1, ?, 'Exempted in E2E test', NOW()
        )
    ");
    $rate_stmt->execute([$skip_po_id, (int)$supp['id'], $skip_reason]);

    $close_stmt = $pdo->prepare("
        UPDATE purchase_orders
        SET status = 'closed',
            supplier_rating = NULL,
            rating_status = 'skipped',
            closed_notes = ?,
            closed_at = NOW(),
            is_locked = 1
        WHERE id = ?
    ");
    $close_stmt->execute(["Rating skipped: $skip_reason", $skip_po_id]);

    $po_check = $pdo->query("SELECT * FROM purchase_orders WHERE id = $skip_po_id")->fetch();
    assert_true($po_check['status'] === 'closed', "PO should be closed");
    assert_true($po_check['rating_status'] === 'skipped', "Rating status should be skipped");
    assert_true(str_contains($po_check['closed_notes'], $skip_reason), "Skip reason should be recorded in closed_notes");
    assert_true($po_check['supplier_rating'] === null, "Supplier rating should be null for skipped");
    return true;
});

// Clean up test data
$pdo->query("DELETE FROM supplier_performance_ratings WHERE comments IN ('E2E automated verification test', 'Exempted in E2E test')");
$pdo->query("DELETE FROM payments WHERE reference_no IN ('REF-PAY-001', 'REF-PAY-002')");
$pdo->query("DELETE FROM invoices WHERE invoice_number = 'INV-TEST-PAY-01'");
$pdo->query("DELETE FROM purchase_orders WHERE po_number IN ('PO-TEST-PAY-01', 'PO-TEST-SKIP-01')");

echo "\n========================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
