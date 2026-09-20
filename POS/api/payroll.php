<?php
// ─────────────────────────────────────────────────────────────
//  api/payroll.php
//  Every payroll mutation. CSRF-protected, permission-gated,
//  and each state change is written to payroll_audit.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();

$raw_input = file_get_contents('php://input');
$json_data = [];
if ($raw_input !== '' && $raw_input !== false) {
    $decoded = json_decode($raw_input, true);
    if (is_array($decoded)) {
        $json_data = $decoded;
    }
}

// Safely merge parameters: JSON payload overrides POST, then GET
$data = array_merge($_GET, $_POST, $json_data);
$action = trim((string)($data['action'] ?? ''));

// Detect if this is a standard HTML browser form requesting a page redirect
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
$is_form = !$is_ajax && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && !empty($data['redirect']);

if (!$is_form && $action !== 'export_csv') {
    header('Content-Type: application/json; charset=utf-8');
}

require_csrf_json();

$pdo  = get_db();
$user = current_user();

/** Respond, honouring form-vs-fetch. */
function respond(array $payload, int $code = 200, string $redirect = '../php/payroll.php'): never {
    global $is_form;

    if ($is_form) {
        $msg  = $payload['ok'] ? ($payload['message'] ?? 'Done.') : ($payload['error'] ?? 'Action failed.');
        $type = $payload['ok'] ? 'success' : 'error';
        if (!str_starts_with($redirect, 'http') && !str_starts_with($redirect, '/') && !str_starts_with($redirect, '../')) {
            $redirect = '../php/' . $redirect;
        }
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . 'toast=' . urlencode($msg) . '&type=' . $type);
        exit;
    }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

try {
    // ── Create a pay period ───────────────────
    if ($action === 'create_period') {
        require_permission_json('payroll.manage');

        $frequency = in_array($data['frequency'] ?? '', ['weekly','biweekly','semimonthly','monthly'], true)
                   ? $data['frequency'] : 'semimonthly';
        $start  = trim($data['period_start'] ?? '');
        $end    = trim($data['period_end']   ?? '');
        $pay    = trim($data['pay_date']     ?? '');
        $branch = trim($data['branch'] ?? '') ?: null;

        foreach ([$start, $end, $pay] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                respond(['ok' => false, 'error' => 'All three dates are required in YYYY-MM-DD format.'], 422);
            }
        }
        if (strtotime($end) < strtotime($start)) {
            respond(['ok' => false, 'error' => 'The period end cannot fall before the start.'], 422);
        }
        if (strtotime($pay) < strtotime($end)) {
            respond(['ok' => false, 'error' => 'The pay date cannot fall before the period ends.'], 422);
        }

        // Overlapping periods in the same branch would double-pay staff.
        $clash = $pdo->prepare(
            "SELECT label FROM payroll_periods
              WHERE status <> 'locked'
                AND (branch <=> :b)
                AND period_start <= :e AND period_end >= :s
              LIMIT 1"
        );
        $clash->execute([':b' => $branch, ':e' => $end, ':s' => $start]);
        if ($existing = $clash->fetchColumn()) {
            respond(['ok' => false, 'error' => 'These dates overlap an existing period: ' . $existing], 409);
        }

        $label = date('M j', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));

        $pdo->prepare(
            'INSERT INTO payroll_periods
                (label, frequency, period_start, period_end, pay_date, branch, created_by)
             VALUES (:l, :f, :s, :e, :p, :b, :u)'
        )->execute([
            ':l' => $label, ':f' => $frequency, ':s' => $start,
            ':e' => $end, ':p' => $pay, ':b' => $branch, ':u' => (int)$user['id'],
        ]);

        $new_id = (int)$pdo->lastInsertId();
        payroll_audit($new_id, null, 'created', 'Period opened: ' . $label, (int)$user['id']);

        respond(['ok' => true, 'period_id' => $new_id, 'message' => 'Pay period created.'],
                200, '../php/payroll_run.php?id=' . $new_id);
    }

    // ── Calculate ─────────────────────────────
    if ($action === 'calculate') {
        require_permission_json('payroll.manage');

        $period_id = (int)($data['period_id'] ?? 0);
        $result = run_payroll_calculation($period_id, (int)$user['id']);

        if (!$result['ok']) respond($result, 422, 'payroll_run.php?id=' . $period_id);

        respond([
            'ok' => true,
            'message' => $result['count'] . ' payslip(s) calculated'
                       . ($result['exceptions'] ? ', ' . $result['exceptions'] . ' need review.' : '.'),
        ], 200, 'payroll_run.php?id=' . $period_id);
    }

    // ── Approve ───────────────────────────────
    if ($action === 'approve') {
        require_permission_json('payroll.approve');

        $period_id = (int)($data['period_id'] ?? 0);
        $note      = trim((string)($data['note'] ?? ''));

        $stmt = $pdo->prepare('SELECT * FROM payroll_periods WHERE id = :id');
        $stmt->execute([':id' => $period_id]);
        $period = $stmt->fetch();

        if (!$period) {
            respond(['ok' => false, 'error' => 'Period not found.'], 404);
        }
        if ($period['status'] !== 'calculated') {
            respond(['ok' => false, 'error' => 'Only a calculated period can be approved.'], 409);
        }

        // Separation of duties: whoever ran the calculation should not
        // also be the one approving it.
        if ((int)$period['created_by'] === (int)$user['id'] && !has_permission('payroll.release')) {
            respond(['ok' => false,
                     'error' => 'You created this period, so someone else must approve it.'], 403);
        }

        $exceptions = (int)$pdo->query(
            'SELECT COUNT(*) FROM payslips WHERE period_id = ' . $period_id . ' AND has_exception = 1'
        )->fetchColumn();

        $pdo->prepare(
            "UPDATE payroll_periods
                SET status = 'approved', approved_by = :u, approved_at = NOW(),
                    notes = COALESCE(NULLIF(:n, ''), notes)
              WHERE id = :id"
        )->execute([':u' => (int)$user['id'], ':n' => $note, ':id' => $period_id]);

        payroll_audit($period_id, null, 'approved',
            'Approved with ' . $exceptions . ' exception(s).' . ($note ? ' Note: ' . $note : ''),
            (int)$user['id']);

        respond(['ok' => true, 'message' => 'Payroll approved.'], 200, 'payroll_run.php?id=' . $period_id);
    }

    // ── Release ───────────────────────────────
    if ($action === 'release') {
        require_permission_json('payroll.release');

        $period_id = (int)($data['period_id'] ?? 0);
        $result = release_payroll($period_id, (int)$user['id']);

        if (!$result['ok']) respond($result, 422, 'payroll_run.php?id=' . $period_id);
        respond(['ok' => true, 'message' => 'Payroll released and period closed.'],
                200, 'payroll_run.php?id=' . $period_id);
    }

    // ── Adjustments ───────────────────────────
    if ($action === 'list_adjustments') {
        require_permission_json('payroll.view');

        $payslip_id = (int)($data['payslip_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id, kind, label, amount, taxable
               FROM payslip_adjustments WHERE payslip_id = :p ORDER BY kind, id'
        );
        $stmt->execute([':p' => $payslip_id]);

        respond(['ok' => true, 'adjustments' => $stmt->fetchAll()]);
    }

    if ($action === 'add_adjustment') {
        require_permission_json('payroll.manage');

        $payslip_id = (int)($data['payslip_id'] ?? 0);
        $kind       = $data['kind'] ?? '';
        $label      = trim((string)($data['label'] ?? ''));
        $amount     = round((float)($data['amount'] ?? 0), 2);

        if (!in_array($kind, ['allowance','bonus','deduction','reimbursement'], true)) {
            respond(['ok' => false, 'error' => 'Invalid adjustment type.'], 422);
        }
        if ($label === '' || $amount <= 0) {
            respond(['ok' => false, 'error' => 'A description and a positive amount are required.'], 422);
        }

        // Only an open period may be adjusted.
        $chk = $pdo->prepare(
            'SELECT p.status FROM payslips s JOIN payroll_periods p ON p.id = s.period_id
              WHERE s.id = :id'
        );
        $chk->execute([':id' => $payslip_id]);
        $status = $chk->fetchColumn();

        if (!$status) {
            respond(['ok' => false, 'error' => 'Payslip not found.'], 404);
        }
        if (!in_array($status, ['draft', 'calculated'], true)) {
            respond(['ok' => false, 'error' => 'This period is ' . $status . ' and can no longer be adjusted.'], 409);
        }

        $pdo->prepare(
            'INSERT INTO payslip_adjustments (payslip_id, kind, label, amount, taxable, created_by)
             VALUES (:p, :k, :l, :a, :t, :u)'
        )->execute([
            ':p' => $payslip_id, ':k' => $kind, ':l' => mb_substr($label, 0, 120),
            ':a' => $amount, ':t' => $kind === 'reimbursement' ? 0 : 1, ':u' => (int)$user['id'],
        ]);

        payroll_audit(null, $payslip_id, 'adjustment_added',
            ucfirst($kind) . ': ' . $label . ' = ' . number_format($amount, 2), (int)$user['id']);

        // Re-apply totals immediately so the register stays truthful.
        recompute_payslip_totals($pdo, $payslip_id);

        respond(['ok' => true, 'message' => 'Adjustment added.']);
    }

    if ($action === 'remove_adjustment') {
        require_permission_json('payroll.manage');

        $adj_id = (int)($data['adjustment_id'] ?? 0);

        $chk = $pdo->prepare(
            'SELECT a.payslip_id, a.label, p.status
               FROM payslip_adjustments a
               JOIN payslips s        ON s.id = a.payslip_id
               JOIN payroll_periods p ON p.id = s.period_id
              WHERE a.id = :id'
        );
        $chk->execute([':id' => $adj_id]);
        $row = $chk->fetch();

        if (!$row) {
            respond(['ok' => false, 'error' => 'Adjustment not found.'], 404);
        }
        if (!in_array($row['status'], ['draft', 'calculated'], true)) {
            respond(['ok' => false, 'error' => 'This period can no longer be adjusted.'], 409);
        }

        $pdo->prepare('DELETE FROM payslip_adjustments WHERE id = :id')->execute([':id' => $adj_id]);

        payroll_audit(null, (int)$row['payslip_id'], 'adjustment_removed',
            'Removed: ' . $row['label'], (int)$user['id']);

        recompute_payslip_totals($pdo, (int)$row['payslip_id']);

        respond(['ok' => true, 'message' => 'Adjustment removed.']);
    }

    // ── Loans ─────────────────────────────────
    if ($action === 'add_loan' || $action === 'issue_loan') {
        require_permission_json('payroll.manage');

        $employee_id = (int)($data['employee_id'] ?? 0);
        $principal   = round((float)($data['principal'] ?? 0), 2);
        $per_period  = round((float)($data['per_period_amount'] ?? 0), 2);
        $type        = trim((string)($data['loan_type'] ?? 'Cash Advance'));
        $start       = trim((string)($data['start_date'] ?? date('Y-m-d')));

        if ($employee_id <= 0 || $principal <= 0 || $per_period <= 0) {
            respond(['ok' => false, 'error' => 'Employee, principal and per-period amount are all required.'], 422);
        }
        if ($per_period > $principal) {
            respond(['ok' => false, 'error' => 'The per-period amount cannot exceed the principal.'], 422);
        }

        $pdo->prepare(
            'INSERT INTO employee_loans
                (employee_id, loan_type, principal, balance, per_period_amount, start_date, created_by)
             VALUES (:e, :t, :p, :p2, :pp, :s, :u)'
        )->execute([
            ':e' => $employee_id, ':t' => mb_substr($type, 0, 60), ':p' => $principal,
            ':p2' => $principal, ':pp' => $per_period, ':s' => $start, ':u' => (int)$user['id'],
        ]);

        payroll_audit(null, null, 'loan_created',
            'Loan for employee #' . $employee_id . ': ' . number_format($principal, 2), (int)$user['id']);

        respond(['ok' => true, 'message' => 'Loan recorded.']);
    }

    // ── Update Loan Status ────────────────────
    if ($action === 'update_loan_status') {
        require_permission_json('payroll.manage');

        $loan_id = (int)($data['loan_id'] ?? 0);
        $status  = trim((string)($data['status'] ?? ''));

        if (!in_array($status, ['active', 'completed', 'cancelled'], true)) {
            respond(['ok' => false, 'error' => 'Invalid loan status.'], 422);
        }

        $stmt = $pdo->prepare('UPDATE employee_loans SET status = :st WHERE id = :id');
        $stmt->execute([':st' => $status, ':id' => $loan_id]);

        payroll_audit(null, null, 'loan_status_updated', 'Loan #' . $loan_id . ' status changed to ' . $status, (int)$user['id']);

        respond(['ok' => true, 'message' => 'Loan status updated.']);
    }

    // ── Update Settings ───────────────────────
    if ($action === 'update_settings') {
        require_permission_json('payroll.settings');

        $allowed_keys = [
            'standard_hours_per_day',
            'working_days_per_month',
            'overtime_multiplier',
            'rest_day_multiplier',
            'holiday_multiplier',
            'night_diff_multiplier',
            'grace_period_minutes',
            'tip_pool_mode',
            'auto_approve_attendance'
        ];

        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            respond(['ok' => false, 'error' => 'Invalid settings payload.'], 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO payroll_settings (setting_key, setting_value, updated_at)
             VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );

        foreach ($settings as $key => $val) {
            if (!in_array($key, $allowed_keys, true)) continue;

            $val = trim((string)$val);
            if ($key === 'tip_pool_mode') {
                if (!in_array($val, ['hours', 'equal'], true)) $val = 'hours';
            } elseif ($key === 'auto_approve_attendance') {
                $val = ($val === '1' || $val === 'true') ? '1' : '0';
            } else {
                if (!is_numeric($val)) continue;
                $f = (float)$val;
                if ($key === 'standard_hours_per_day') $val = (string)max(1, min(24, $f));
                elseif ($key === 'working_days_per_month') $val = (string)max(1, min(31, $f));
                elseif ($key === 'grace_period_minutes') $val = (string)max(0, (int)$f);
                elseif ($f < 0) $val = '0';
            }

            $stmt->execute([':k' => $key, ':v' => $val]);
        }

        payroll_audit(null, null, 'settings_updated', 'Payroll settings updated', (int)$user['id']);

        respond(['ok' => true, 'message' => 'Settings saved successfully.'], 200, 'payroll_settings.php');
    }

    // ── Delete Pay Period ─────────────────────
    if ($action === 'delete_period') {
        require_permission_json('payroll.manage');

        $period_id = (int)($data['period_id'] ?? 0);

        $chk = $pdo->prepare('SELECT id, label, status FROM payroll_periods WHERE id = :id');
        $chk->execute([':id' => $period_id]);
        $period = $chk->fetch();

        if (!$period) {
            respond(['ok' => false, 'error' => 'Pay period not found.'], 404);
        }
        if (in_array($period['status'], ['approved', 'paid', 'locked'], true)) {
            respond(['ok' => false, 'error' => 'Cannot delete an approved or released period.'], 409);
        }

        $pdo->beginTransaction();

        // Remove adjustments for payslips in this period
        $pdo->prepare(
            'DELETE a FROM payslip_adjustments a
               JOIN payslips s ON s.id = a.payslip_id
              WHERE s.period_id = :p'
        )->execute([':p' => $period_id]);

        // Remove loan repayments tied to payslips in this period
        $pdo->prepare(
            'DELETE r FROM loan_repayments r
               JOIN payslips s ON s.id = r.payslip_id
              WHERE s.period_id = :p'
        )->execute([':p' => $period_id]);

        // Remove payslips
        $pdo->prepare('DELETE FROM payslips WHERE period_id = :p')->execute([':p' => $period_id]);

        // Remove period
        $pdo->prepare('DELETE FROM payroll_periods WHERE id = :p')->execute([':p' => $period_id]);

        payroll_audit($period_id, null, 'period_deleted', 'Deleted pay period: ' . $period['label'], (int)$user['id']);

        $pdo->commit();

        respond(['ok' => true, 'message' => 'Pay period deleted successfully.'], 200, 'payroll.php');
    }

    // ── Export CSV ────────────────────────────
    if ($action === 'export_csv') {
        require_permission('payroll.view');

        $period_id = (int)($data['period_id'] ?? 0);
        $type      = trim((string)($data['type'] ?? 'register'));

        $stmt = $pdo->prepare('SELECT * FROM payroll_periods WHERE id = :id');
        $stmt->execute([':id' => $period_id]);
        $period = $stmt->fetch();

        if (!$period) {
            respond(['ok' => false, 'error' => 'Pay period not found.'], 404);
        }

        $slips_stmt = $pdo->prepare(
            'SELECT s.*, e.firstname, e.lastname, e.employee_code, e.position, e.branch,
                    e.payment_method AS emp_pm, e.bank_name, e.bank_account_last4
               FROM payslips s
               JOIN employees e ON e.id = s.employee_id
              WHERE s.period_id = :p
              ORDER BY e.lastname, e.firstname'
        );
        $slips_stmt->execute([':p' => $period_id]);
        $slips = $slips_stmt->fetchAll();

        $filename_label = preg_replace('/[^a-zA-Z0-9_-]/', '_', $period['label']);

        header('Content-Type: text/csv; charset=utf-8');

        if ($type === 'bank_advice') {
            header('Content-Disposition: attachment; filename="bank_advice_' . $filename_label . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee Code', 'Full Name', 'Payment Method', 'Bank Name', 'Account / Last 4', 'Net Pay (PHP)', 'Payment Status']);

            foreach ($slips as $s) {
                fputcsv($out, [
                    $s['employee_code'],
                    $s['lastname'] . ', ' . $s['firstname'],
                    ucwords(str_replace('_', ' ', $s['payment_method'] ?: $s['emp_pm'] ?: 'cash')),
                    $s['bank_name'] ?: 'N/A',
                    $s['bank_account_last4'] ? 'Ending in ' . $s['bank_account_last4'] : 'N/A',
                    number_format((float)$s['net_pay'], 2, '.', ''),
                    ucfirst($s['payment_status']),
                ]);
            }
            fclose($out);
            exit;
        }

        if ($type === 'statutory') {
            header('Content-Disposition: attachment; filename="statutory_contributions_' . $filename_label . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee Code', 'Full Name', 'Gross Pay', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Total Statutory (PHP)']);

            foreach ($slips as $s) {
                $stat_total = (float)$s['sss'] + (float)$s['philhealth'] + (float)$s['pagibig'] + (float)$s['withholding_tax'];
                fputcsv($out, [
                    $s['employee_code'],
                    $s['lastname'] . ', ' . $s['firstname'],
                    number_format((float)$s['gross_pay'], 2, '.', ''),
                    number_format((float)$s['sss'], 2, '.', ''),
                    number_format((float)$s['philhealth'], 2, '.', ''),
                    number_format((float)$s['pagibig'], 2, '.', ''),
                    number_format((float)$s['withholding_tax'], 2, '.', ''),
                    number_format($stat_total, 2, '.', ''),
                ]);
            }
            fclose($out);
            exit;
        }

        // Default: Full payroll register
        header('Content-Disposition: attachment; filename="payroll_register_' . $filename_label . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Employee Code', 'Last Name', 'First Name', 'Position', 'Branch',
            'Days Worked', 'Regular Hours', 'OT Hours',
            'Basic Pay', 'OT Pay', 'Holiday Pay', 'Rest Day Pay', 'Night Diff',
            'Sales Commission', 'Tips & Service Charge', 'Allowances', 'Bonus', 'Gross Pay',
            'Lateness Deduction', 'Absence Deduction', 'SSS', 'PhilHealth', 'Pag-IBIG',
            'Withholding Tax', 'Loan Deduction', 'Other Deductions', 'Total Deductions',
            'Net Pay (PHP)', 'Payment Method', 'Payment Status'
        ]);

        foreach ($slips as $s) {
            fputcsv($out, [
                $s['employee_code'],
                $s['lastname'],
                $s['firstname'],
                $s['position'],
                $s['branch'] ?: 'Main',
                number_format((float)$s['days_worked'], 1, '.', ''),
                number_format((float)$s['regular_hours'], 2, '.', ''),
                number_format((float)$s['overtime_hours'], 2, '.', ''),
                number_format((float)$s['basic_pay'], 2, '.', ''),
                number_format((float)$s['overtime_pay'], 2, '.', ''),
                number_format((float)$s['holiday_pay'], 2, '.', ''),
                number_format((float)$s['rest_day_pay'], 2, '.', ''),
                number_format((float)$s['night_diff_pay'], 2, '.', ''),
                number_format((float)$s['commission'], 2, '.', ''),
                number_format((float)$s['tips'], 2, '.', ''),
                number_format((float)$s['allowances'], 2, '.', ''),
                number_format((float)$s['bonus'], 2, '.', ''),
                number_format((float)$s['gross_pay'], 2, '.', ''),
                number_format((float)$s['late_deduction'], 2, '.', ''),
                number_format((float)$s['absence_deduction'], 2, '.', ''),
                number_format((float)$s['sss'], 2, '.', ''),
                number_format((float)$s['philhealth'], 2, '.', ''),
                number_format((float)$s['pagibig'], 2, '.', ''),
                number_format((float)$s['withholding_tax'], 2, '.', ''),
                number_format((float)$s['loan_deduction'], 2, '.', ''),
                number_format((float)$s['other_deduction'], 2, '.', ''),
                number_format((float)$s['total_deductions'], 2, '.', ''),
                number_format((float)$s['net_pay'], 2, '.', ''),
                ucwords(str_replace('_', ' ', $s['payment_method'] ?: 'cash')),
                ucfirst($s['payment_status']),
            ]);
        }
        fclose($out);
        exit;
    }

    respond(['ok' => false, 'error' => 'Unknown payroll action.'], 422);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('payroll api error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'A server error occurred. Check the error log.'], 500);
}

/**
 * Fold the current adjustments back into a payslip's totals.
 * Deductions never push net pay below zero; the shortfall is
 * left on the record as an exception for a human to resolve.
 */
function recompute_payslip_totals(PDO $pdo, int $payslip_id): void {
    $stmt = $pdo->prepare('SELECT * FROM payslips WHERE id = :id');
    $stmt->execute([':id' => $payslip_id]);
    $slip = $stmt->fetch();
    if (!$slip) return;

    $adj = $pdo->prepare(
        'SELECT kind, COALESCE(SUM(amount),0) AS total
           FROM payslip_adjustments WHERE payslip_id = :id GROUP BY kind'
    );
    $adj->execute([':id' => $payslip_id]);

    $by_kind = [];
    foreach ($adj->fetchAll() as $r) $by_kind[$r['kind']] = (float)$r['total'];

    $allowances = round(($by_kind['allowance'] ?? 0) + ($by_kind['reimbursement'] ?? 0), 2);
    $bonus      = round($by_kind['bonus'] ?? 0, 2);
    $other_ded  = round($by_kind['deduction'] ?? 0, 2);

    // Rebuild gross from its components so repeated edits never compound.
    $gross = round(
        (float)$slip['basic_pay'] + (float)$slip['overtime_pay'] + (float)$slip['holiday_pay']
      + (float)$slip['rest_day_pay'] + (float)$slip['night_diff_pay']
      + (float)$slip['commission'] + (float)$slip['tips'] + $allowances + $bonus, 2);

    $deductions = round(
        (float)$slip['late_deduction'] + (float)$slip['absence_deduction']
      + (float)$slip['sss'] + (float)$slip['philhealth'] + (float)$slip['pagibig']
      + (float)$slip['withholding_tax'] + (float)$slip['loan_deduction'] + $other_ded, 2);

    $net = round($gross - $deductions, 2);

    $pdo->prepare(
        'UPDATE payslips
            SET allowances = :a, bonus = :b, other_deduction = :o,
                gross_pay = :g, total_deductions = :d, net_pay = :n,
                has_exception = CASE WHEN :n2 < 0 THEN 1 ELSE has_exception END
          WHERE id = :id'
    )->execute([
        ':a' => $allowances, ':b' => $bonus, ':o' => $other_ded,
        ':g' => $gross, ':d' => $deductions, ':n' => $net, ':n2' => $net, ':id' => $payslip_id,
    ]);

    // Keep the period header in step with its payslips.
    $pdo->prepare(
        'UPDATE payroll_periods p
            SET gross_total     = (SELECT COALESCE(SUM(gross_pay),0)        FROM payslips WHERE period_id = p.id),
                deduction_total = (SELECT COALESCE(SUM(total_deductions),0) FROM payslips WHERE period_id = p.id),
                net_total       = (SELECT COALESCE(SUM(net_pay),0)          FROM payslips WHERE period_id = p.id)
          WHERE p.id = :pid'
    )->execute([':pid' => (int)$slip['period_id']]);
}