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
        header('Location: employee_dashboard.php?toast='
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
    'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer',
    'payroll_card' => 'Payroll Card', 'ewallet' => 'E-Wallet',
];
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
      <h1>Payslip</h1>
      <p><?= e($slip['period_label']) ?></p>
    </div>
    <div style="display:flex;gap:9px">
      <?php if (has_permission('payroll.view')): ?>
      <a class="btn-ghost" href="payroll_run.php?id=<?= (int)$slip['period_id'] ?>">
        <?= icon('chevron', 15) ?> <span>Back to Register</span>
      </a>
      <?php endif; ?>
      <?php if (has_permission('payroll.own')): ?>
      <a class="btn-ghost" href="my_payslips.php">
        <?= icon('file-text', 15) ?> <span>My Payslips</span>
      </a>
      <?php endif; ?>
      <button class="btn-add" onclick="window.print()">
        <?= icon('printer', 15) ?> <span>Print</span>
      </button>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav(has_permission('payroll.view') ? 'runs' : 'my_payslips', 'Payslip: ' . e($employee['firstname'] . ' ' . $employee['lastname']) . ' (' . e($slip['period_label']) . ')') ?>

    <div class="table-card pr-slip" style="padding:32px">

      <!-- Header -->
      <div class="pr-slip-head">
        <div>
          <div class="pr-slip-brand">Kofee Manila</div>
          <div class="pr-slip-sub">Employee Payslip</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:12px;font-weight:800;text-transform:uppercase;
                      letter-spacing:.05em;color:var(--text-muted)">Pay Period</div>
          <div style="font-size:14px;font-weight:700;margin-top:3px"><?= e($slip['period_label']) ?></div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
            Paid <?= e(date('F j, Y', strtotime($slip['pay_date']))) ?>
          </div>
        </div>
      </div>

      <!-- Employee meta -->
      <div class="pr-meta" style="margin-top:22px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Employee</div>
          <div class="pr-meta-value"><?= e($slip['firstname'] . ' ' . $slip['lastname']) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Employee Code</div>
          <div class="pr-meta-value"><?= e($slip['employee_code']) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Position</div>
          <div class="pr-meta-value"><?= e($slip['position']) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Branch</div>
          <div class="pr-meta-value"><?= e($slip['branch'] ?: 'Main') ?></div>
        </div>
      </div>

      <!-- Time summary -->
      <div class="pr-meta" style="margin-top:12px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:4px">
            <?= icon('calendar', 12) ?> <span>Days Worked</span>
          </div>
          <div class="pr-meta-value"><?= rtrim(rtrim(number_format((float)$slip['days_worked'], 1), '0'), '.') ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:4px">
            <?= icon('clock', 12) ?> <span>Regular Hours</span>
          </div>
          <div class="pr-meta-value"><?= number_format((float)$slip['regular_hours'], 2) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:4px">
            <?= icon('zap', 12) ?> <span>Overtime Hours</span>
          </div>
          <div class="pr-meta-value"><?= number_format((float)$slip['overtime_hours'], 2) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label" style="display:flex;align-items:center;gap:4px">
            <?= icon('leave', 12) ?> <span>Paid Leave</span>
          </div>
          <div class="pr-meta-value"><?= rtrim(rtrim(number_format((float)$slip['paid_leave_days'], 1), '0'), '.') ?> day(s)</div>
        </div>
      </div>

      <!-- Earnings / Deductions -->
      <div class="pr-slip-grid">
        <div class="pr-slip-section">
          <h3>Earnings</h3>
          <div class="pr-line"><span>Basic pay</span><span><?= peso((float)$slip['basic_pay']) ?></span></div>
          <?php if ((float)$slip['overtime_pay'] > 0): ?>
          <div class="pr-line"><span>Overtime</span><span><?= peso((float)$slip['overtime_pay']) ?></span></div>
          <?php endif; ?>
          <?php if ((float)$slip['holiday_pay'] > 0): ?>
          <div class="pr-line"><span>Holiday pay</span><span><?= peso((float)$slip['holiday_pay']) ?></span></div>
          <?php endif; ?>
          <?php if ((float)$slip['rest_day_pay'] > 0): ?>
          <div class="pr-line"><span>Rest-day pay</span><span><?= peso((float)$slip['rest_day_pay']) ?></span></div>
          <?php endif; ?>
          <?php if ((float)$slip['night_diff_pay'] > 0): ?>
          <div class="pr-line"><span>Night differential</span><span><?= peso((float)$slip['night_diff_pay']) ?></span></div>
          <?php endif; ?>
          <?php if ((float)$slip['commission'] > 0): ?>
          <div class="pr-line"><span>Sales commission</span><span><?= peso((float)$slip['commission']) ?></span></div>
          <?php endif; ?>
          <?php if ((float)$slip['tips'] > 0): ?>
          <div class="pr-line"><span>Tips and service charge</span><span><?= peso((float)$slip['tips']) ?></span></div>
          <?php endif; ?>

          <?php foreach ($adjustments as $a):
            if ($a['kind'] === 'deduction') continue; ?>
          <div class="pr-line"><span><?= e($a['label']) ?></span><span><?= peso((float)$a['amount']) ?></span></div>
          <?php endforeach; ?>

          <div class="pr-line-total">
            <span>Gross Pay</span><span><?= peso((float)$slip['gross_pay']) ?></span>
          </div>
        </div>

        <div class="pr-slip-section">
          <h3>Deductions</h3>
          <?php if ((float)$slip['late_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Lateness (<?= (int)$slip['late_minutes'] ?> min)</span>
            <span><?= peso((float)$slip['late_deduction']) ?></span>
          </div>
          <?php endif; ?>
          <?php if ((float)$slip['absence_deduction'] > 0): ?>
          <div class="pr-line">
            <span>Absences (<?= rtrim(rtrim(number_format((float)$slip['absent_days'], 1), '0'), '.') ?> day)</span>
            <span><?= peso((float)$slip['absence_deduction']) ?></span>
          </div>
          <?php endif; ?>
          <div class="pr-line"><span>SSS</span><span><?= peso((float)$slip['sss']) ?></span></div>
          <div class="pr-line"><span>PhilHealth</span><span><?= peso((float)$slip['philhealth']) ?></span></div>
          <div class="pr-line"><span>Pag-IBIG</span><span><?= peso((float)$slip['pagibig']) ?></span></div>
          <div class="pr-line"><span>Withholding tax</span><span><?= peso((float)$slip['withholding_tax']) ?></span></div>
          <?php if ((float)$slip['loan_deduction'] > 0): ?>
          <div class="pr-line"><span>Loan / cash advance</span><span><?= peso((float)$slip['loan_deduction']) ?></span></div>
          <?php endif; ?>

          <?php foreach ($adjustments as $a):
            if ($a['kind'] !== 'deduction') continue; ?>
          <div class="pr-line"><span><?= e($a['label']) ?></span><span><?= peso((float)$a['amount']) ?></span></div>
          <?php endforeach; ?>

          <div class="pr-line-total">
            <span>Total Deductions</span><span><?= peso((float)$slip['total_deductions']) ?></span>
          </div>
        </div>
      </div>

      <!-- Net -->
      <div class="pr-netbox">
        <span class="pr-netbox-label">Net Pay</span>
        <span class="pr-netbox-value"><?= peso((float)$slip['net_pay']) ?></span>
      </div>

      <!-- Payment -->
      <div class="pr-meta" style="margin-top:20px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Payment Method</div>
          <div class="pr-meta-value" style="display:flex;align-items:center;gap:6px">
            <?= match($slip['payment_method']) { 'bank_transfer' => icon('credit-card', 15), 'payroll_card' => icon('card', 15), 'ewallet' => icon('globe', 15), default => icon('coin', 15) } ?>
            <span><?= e($method_labels[$slip['payment_method']] ?? 'Cash') ?></span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Account</div>
          <div class="pr-meta-value" style="font-size:13.5px">
            <?= $slip['bank_account_last4']
                  ? e(($slip['bank_name'] ?: 'Account') . ' ending in ' . $slip['bank_account_last4'])
                  : 'Cash on Hand' ?>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Status</div>
          <div class="pr-meta-value">
            <span class="<?= $slip['payment_status'] === 'paid' ? 'pr-badge pr-badge-green' : 'pr-badge pr-badge-amber' ?>">
              <?= $slip['payment_status'] === 'paid' ? icon('check-circle', 11) : icon('clock', 11) ?>
              <span><?= ucfirst(e($slip['payment_status'])) ?></span>
            </span>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label">Payment Date</div>
          <div class="pr-meta-value" style="font-size:13.5px">
            <?= $slip['paid_at'] ? e(date('M j, Y', strtotime($slip['paid_at']))) : 'Pending' ?>
          </div>
        </div>
      </div>

      <!-- Signatures Block for Physical Payslip -->
      <div class="pr-signatures">
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Prepared By (HR)</div>
        </div>
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Approved By (Manager)</div>
        </div>
        <div class="pr-sign-block">
          <div class="pr-sign-line"></div>
          <div class="pr-sign-lbl">Received By (Employee)</div>
        </div>
      </div>

      <p style="margin-top:24px;font-size:11px;color:var(--text-muted);line-height:1.6;text-align:center">
        This payslip is generated from attendance, POS sales and payroll records on file.
        Raise any discrepancy with HR within five working days of the pay date.
      </p>

    </div>
  </div>
</div>

</body>
</html>