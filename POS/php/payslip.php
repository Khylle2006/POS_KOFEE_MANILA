<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();

send_security_headers();

$pdo  = get_db();
$user = current_user();

$payslip_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT s.*, e.firstname, e.lastname, e.employee_code, e.position, e.department,
            e.branch, e.hire_date, e.bank_name, e.bank_account_last4, e.user_id,
            p.label AS period_label, p.period_start, p.period_end, p.pay_date,
            p.frequency, p.status AS period_status
       FROM payslips s
       JOIN employees e        ON e.id = s.employee_id
       JOIN payroll_periods p  ON p.id = s.period_id
      WHERE s.id = :id'
);
$stmt->execute([':id' => $payslip_id]);
$slip = $stmt->fetch();

if (!$slip) {
    header('Location: payroll.php?toast=' . urlencode('Payslip not found.') . '&type=error');
    exit;
}

// ── Access control ────────────────────────────
// Payroll staff see everyone. Everyone else sees only their own,
// and only once the period has actually been released.
$is_own = (int)$slip['user_id'] === (int)$user['id'];

if (!has_permission('payroll.view')) {
    if (!$is_own || !has_permission('payroll.own')) {
        header('Location: no_access.php?perm=payroll.view');
        exit;
    }
    if (!in_array($slip['period_status'], ['paid', 'locked'], true)) {
        header('Location: my_payslips.php?toast='
             . urlencode('That payslip has not been released yet.') . '&type=error');
        exit;
    }
}

$adj_stmt = $pdo->prepare(
    'SELECT kind, label, amount FROM payslip_adjustments
      WHERE payslip_id = :id ORDER BY kind, id'
);
$adj_stmt->execute([':id' => $payslip_id]);
$adjustments = $adj_stmt->fetchAll();

$method_labels = [
    'cash' => 'Cash on Hand',
    'bank_transfer' => 'Bank Transfer',
    'payroll_card' => 'Payroll Card',
    'ewallet' => 'E-Wallet Transfer',
];

$dest_display = 'Cash Disbursement';
if ($slip['payment_method'] === 'bank_transfer') {
    $bank = $slip['bank_name'] ?: 'Bank Account';
    $dest_display = $slip['bank_account_last4']
        ? $bank . ' (•••• ' . $slip['bank_account_last4'] . ')'
        : $bank;
} elseif ($slip['payment_method'] === 'ewallet') {
    $dest_display = $slip['bank_name'] ?: 'E-Wallet';
    if ($slip['bank_account_last4']) {
        $dest_display .= ' (•••• ' . $slip['bank_account_last4'] . ')';
    }
} elseif ($slip['payment_method'] === 'payroll_card') {
    $dest_display = 'Card ending in ' . ($slip['bank_account_last4'] ?: '••••');
}

$initials = strtoupper(substr($slip['firstname'], 0, 1) . substr($slip['lastname'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Payslip — <?= e($slip['lastname'] . ', ' . $slip['firstname']) ?> — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-payslip" class="page active">
  <div class="page-header no-print">
    <div>
      <?php if (has_permission('payroll.view')): ?>
      <a class="pr-breadcrumb" href="payroll_run.php?id=<?= (int)$slip['period_id'] ?>">
        <?= icon('chevron', 13) ?> <span>Back to <?= e($slip['period_label']) ?> Register</span>
      </a>
      <?php else: ?>
      <a class="pr-breadcrumb" href="my_payslips.php">
        <?= icon('chevron', 13) ?> <span>Back to My Payslips</span>
      </a>
      <?php endif; ?>

      <div class="pr-header-title-row">
        <h1 style="margin:0;font-size:22px">Payslip &mdash; <?= e($slip['lastname'] . ', ' . $slip['firstname']) ?></h1>
        <span class="<?= $slip['payment_status'] === 'paid' ? 'pr-badge pr-badge-green' : 'pr-badge pr-badge-amber' ?>">
          <?= $slip['payment_status'] === 'paid' ? icon('check-circle', 12) : icon('clock', 12) ?>
          <span><?= $slip['payment_status'] === 'paid' ? 'Paid / Released' : 'Pending Payment' ?></span>
        </span>
      </div>
      <p style="margin-top:4px">
        <?= e($slip['period_label']) ?> &middot;
        Pay Date: <strong><?= e(date('F j, Y', strtotime($slip['pay_date']))) ?></strong>
        &middot; Employee ID: <strong><?= e($slip['employee_code']) ?></strong>
      </p>
    </div>
    <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap">
      <?php if (has_permission('payroll.view')): ?>
      <a class="btn-ghost" href="payroll_run.php?id=<?= (int)$slip['period_id'] ?>">
        <?= icon('users', 15) ?> <span>Period Register</span>
      </a>
      <?php endif; ?>
      <?php if (has_permission('payroll.own')): ?>
      <a class="btn-ghost" href="my_payslips.php">
        <?= icon('file-text', 15) ?> <span>My Payslips</span>
      </a>
      <?php endif; ?>
      <button class="btn-add btn-act-calculate" onclick="window.print()">
        <?= icon('printer', 15) ?> <span>Print / Save PDF</span>
      </button>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav(has_permission('payroll.view') ? 'runs' : 'my_payslips', 'Payslip: ' . e($slip['firstname'] . ' ' . $slip['lastname']) . ' (' . e($slip['period_label']) . ')') ?>

    <?php if ((int)$slip['has_exception'] === 1 && $slip['exception_note']): ?>
    <div class="pr-alert pr-alert-warn no-print" style="margin-bottom:20px">
      <span class="pr-alert-icon"><?= icon('alert-triangle', 18) ?></span>
      <div>
        <strong>Payroll Audit Notice:</strong>
        <span><?= e($slip['exception_note']) ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div class="table-card pr-slip" style="padding:32px">

      <!-- Header with Branding & Pay Period Metadata -->
      <div class="pr-slip-head">
        <div>
          <div class="pr-slip-brand">Kofee Manila</div>
          <div class="pr-slip-sub">Specialty Coffee &bull; Employee Payslip</div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">
            Branch: <strong><?= e($slip['branch'] ?: 'Main Store') ?></strong> &middot; Reference #PS-<?= str_pad((string)$slip['id'], 6, '0', STR_PAD_LEFT) ?>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">
            Payroll Cutoff
          </div>
          <div style="font-size:14.5px;font-weight:700;color:var(--espresso);margin-top:2px">
            <?= e(date('M j', strtotime($slip['period_start']))) ?> &ndash; <?= e(date('M j, Y', strtotime($slip['period_end']))) ?>
          </div>
          <div style="font-size:12px;color:var(--caramel);font-weight:600;margin-top:2px">
            Disbursed <?= e(date('F j, Y', strtotime($slip['pay_date']))) ?>
          </div>
        </div>
      </div>

      <!-- Employee Information Grid -->
      <div class="pr-meta" style="margin-top:22px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Employee Name</div>
          <div class="pr-meta-value" style="display:flex;align-items:center;gap:8px">
            <span class="pr-emp-avatar" style="width:28px;height:28px;font-size:11px"><?= e($initials) ?></span>
            <span><?= e($slip['firstname'] . ' ' . $slip['lastname']) ?></span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Employee Code</div>
          <div class="pr-meta-value"><?= e($slip['employee_code']) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Position / Department</div>
          <div class="pr-meta-value" style="font-size:13.5px">
            <?= e($slip['position']) ?>
            <span style="font-size:11px;font-weight:600;color:var(--text-muted)"> &middot; <?= e($slip['department'] ?: 'Operations') ?></span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Store Branch</div>
          <div class="pr-meta-value"><?= e($slip['branch'] ?: 'Main Branch') ?></div>
        </div>
      </div>

      <!-- Attendance & Time Summary Grid -->
      <div class="pr-meta" style="margin-top:12px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:5px">
            <?= icon('calendar', 13) ?> <span>Days Worked</span>
          </div>
          <div class="pr-meta-value">
            <?= rtrim(rtrim(number_format((float)$slip['days_worked'], 1), '0'), '.') ?>
            <span style="font-size:12px;font-weight:600;color:var(--text-muted)">Days</span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:5px">
            <?= icon('clock', 13) ?> <span>Regular Hours</span>
          </div>
          <div class="pr-meta-value">
            <?= number_format((float)$slip['regular_hours'], 2) ?>
            <span style="font-size:12px;font-weight:600;color:var(--text-muted)">Hrs</span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:5px">
            <?= icon('zap', 13) ?> <span>Overtime Hours</span>
          </div>
          <div class="pr-meta-value <?= (float)$slip['overtime_hours'] > 0 ? '' : 'pr-zero' ?>">
            <?= number_format((float)$slip['overtime_hours'], 2) ?>
            <span style="font-size:12px;font-weight:600;color:var(--text-muted)">Hrs</span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:5px">
            <?= icon('leave', 13) ?> <span>Paid Leave</span>
          </div>
          <div class="pr-meta-value <?= (float)$slip['paid_leave_days'] > 0 ? '' : 'pr-zero' ?>">
            <?= rtrim(rtrim(number_format((float)$slip['paid_leave_days'], 1), '0'), '.') ?>
            <span style="font-size:12px;font-weight:600;color:var(--text-muted)">Day(s)</span>
          </div>
        </div>
      </div>

      <!-- Itemized Earnings & Deductions Breakdown -->
      <div class="pr-slip-grid">
        <!-- Earnings Section -->
        <div class="pr-slip-section">
          <h3><?= icon('dollar-sign', 14) ?> <span>Earnings</span></h3>
          <div class="pr-line">
            <span>Basic Salary</span>
            <span><?= peso((float)$slip['basic_pay']) ?></span>
          </div>
          <?php if ((float)$slip['overtime_pay'] > 0): ?>
          <div class="pr-line">
            <span>Overtime Pay (<?= number_format((float)$slip['overtime_hours'], 1) ?>h)</span>
            <span><?= peso((float)$slip['overtime_pay']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['holiday_pay'] > 0): ?>
          <div class="pr-line">
            <span>Holiday Premium</span>
            <span><?= peso((float)$slip['holiday_pay']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['rest_day_pay'] > 0): ?>
          <div class="pr-line">
            <span>Rest Day Work</span>
            <span><?= peso((float)$slip['rest_day_pay']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['night_diff_pay'] > 0): ?>
          <div class="pr-line">
            <span>Night Differential</span>
            <span><?= peso((float)$slip['night_diff_pay']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['commission'] > 0): ?>
          <div class="pr-line">
            <span>Sales Commission</span>
            <span><?= peso((float)$slip['commission']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['tips'] > 0): ?>
          <div class="pr-line">
            <span>Tips &amp; Service Charge</span>
            <span><?= peso((float)$slip['tips']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['allowances'] > 0): ?>
          <div class="pr-line">
            <span>Allowances</span>
            <span><?= peso((float)$slip['allowances']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['bonus'] > 0): ?>
          <div class="pr-line">
            <span>Bonus &amp; Incentives</span>
            <span><?= peso((float)$slip['bonus']) ?></span>
          </div>
          <?php endif; ?>

          <?php foreach ($adjustments as $a):
            if ($a['kind'] === 'deduction') continue; ?>
          <div class="pr-line">
            <span><?= e($a['label']) ?> (<?= ucfirst(e($a['kind'])) ?>)</span>
            <span style="color:var(--green)">+<?= peso((float)$a['amount']) ?></span>
          </div>
          <?php endforeach; ?>

          <div class="pr-line-total">
            <span>Total Gross Pay</span>
            <span style="color:var(--espresso)"><?= peso((float)$slip['gross_pay']) ?></span>
          </div>
        </div>

        <!-- Deductions Section -->
        <div class="pr-slip-section">
          <h3><?= icon('trending-down', 14) ?> <span>Deductions</span></h3>
          <?php if ((float)$slip['late_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Tardiness / Lateness (<?= (int)$slip['late_minutes'] ?> min)</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['late_deduction']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['absence_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Absences (<?= rtrim(rtrim(number_format((float)$slip['absent_days'], 1), '0'), '.') ?> day)</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['absence_deduction']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['sss'] > 0): ?>
          <div class="pr-line">
            <span>SSS Contribution</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['sss']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['philhealth'] > 0): ?>
          <div class="pr-line">
            <span>PhilHealth Premium</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['philhealth']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['pagibig'] > 0): ?>
          <div class="pr-line">
            <span>Pag-IBIG Contribution</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['pagibig']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['withholding_tax'] > 0): ?>
          <div class="pr-line">
            <span>Withholding Tax (BIR)</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['withholding_tax']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['loan_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Company Loan Amortization</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['loan_deduction']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['other_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Other Deductions</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['other_deduction']) ?></span>
          </div>
          <?php endif; ?>

          <?php foreach ($adjustments as $a):
            if ($a['kind'] !== 'deduction') continue; ?>
          <div class="pr-line">
            <span><?= e($a['label']) ?></span>
            <span style="color:var(--red)">&minus;<?= peso((float)$a['amount']) ?></span>
          </div>
          <?php endforeach; ?>

          <div class="pr-line-total">
            <span>Total Deductions</span>
            <span style="color:var(--red)">&minus;<?= peso((float)$slip['total_deductions']) ?></span>
          </div>
        </div>
      </div>

      <!-- Net Payout Hero Box -->
      <div class="pr-netbox">
        <div>
          <span class="pr-netbox-label">Total Net Pay (Take-Home)</span>
          <div style="font-size:11.5px;color:var(--latte);margin-top:3px">
            Official net compensation disbursed to employee
          </div>
        </div>
        <span class="pr-netbox-value"><?= peso((float)$slip['net_pay']) ?></span>
      </div>

      <!-- Payment & Disbursement Information -->
      <div class="pr-meta" style="margin-top:20px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Payment Channel</div>
          <div class="pr-meta-value" style="display:flex;align-items:center;gap:6px;font-size:13.5px">
            <?= icon('credit-card', 15) ?>
            <span><?= e($method_labels[$slip['payment_method']] ?? 'Cash') ?></span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Destination Account</div>
          <div class="pr-meta-value" style="font-size:13px">
            <?= e($dest_display) ?>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Disbursement Status</div>
          <div class="pr-meta-value">
            <span class="<?= $slip['payment_status'] === 'paid' ? 'pr-badge pr-badge-green' : 'pr-badge pr-badge-amber' ?>">
              <?= $slip['payment_status'] === 'paid' ? icon('check-circle', 11) : icon('clock', 11) ?>
              <span><?= ucfirst(e($slip['payment_status'])) ?></span>
            </span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Release Date</div>
          <div class="pr-meta-value" style="font-size:13px">
            <?= $slip['paid_at'] ? e(date('M j, Y, g:i A', strtotime($slip['paid_at']))) : 'Pending Approval / Release' ?>
          </div>
        </div>
      </div>

      <!-- Signatures Block for Physical Records & Printing -->
      <div class="pr-signatures">
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Prepared By (HR / Payroll)</div>
        </div>
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Approved By (Store Manager)</div>
        </div>
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Acknowledged By (Employee)</div>
        </div>
      </div>

      <div style="margin-top:24px;border-top:1px solid var(--border);padding-top:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:11px;color:var(--text-muted)">
        <div>
          Generated on <?= date('F j, Y, g:i A') ?> &bull; Kofee Manila POS &amp; Enterprise Management
        </div>
        <div>
          Confidential document. Any discrepancies should be filed with HR within 5 working days.
        </div>
      </div>

    </div>
  </div>
</div>

</body>
</html>