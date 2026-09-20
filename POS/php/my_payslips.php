<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();

send_security_headers();

$pdo  = get_db();
$user = current_user();

if (!has_permission('payroll.own') && !has_permission('payroll.view')) {
    header('Location: no_access.php?perm=payroll.own');
    exit;
}

// Find employee record linked to active user account
$emp_stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :u LIMIT 1');
$emp_stmt->execute([':u' => (int)$user['id']]);
$employee = $emp_stmt->fetch();

$payslips = [];
$ytd_gross = 0.0;
$ytd_deductions = 0.0;
$ytd_net = 0.0;
$active_loans = [];
$loan_balance = 0.0;

if ($employee) {
    // Only show payslips from released ('paid' or 'locked') pay periods
    $slips_stmt = $pdo->prepare(
        "SELECT s.*, p.label AS period_label, p.period_start, p.period_end, p.pay_date, p.status AS period_status
           FROM payslips s
           JOIN payroll_periods p ON p.id = s.period_id
          WHERE s.employee_id = :e
            AND p.status IN ('paid', 'locked')
          ORDER BY p.pay_date DESC"
    );
    $slips_stmt->execute([':e' => (int)$employee['id']]);
    $payslips = $slips_stmt->fetchAll();

    $current_year = (int)date('Y');
    foreach ($payslips as $ps) {
        if ((int)date('Y', strtotime($ps['pay_date'])) === $current_year) {
            $ytd_gross      += (float)$ps['gross_pay'];
            $ytd_deductions += (float)$ps['total_deductions'];
            $ytd_net        += (float)$ps['net_pay'];
        }
    }

    // Active loans / advances
    $loan_stmt = $pdo->prepare(
        "SELECT * FROM employee_loans
          WHERE employee_id = :e AND status = 'active'
          ORDER BY created_at DESC"
    );
    $loan_stmt->execute([':e' => (int)$employee['id']]);
    $active_loans = $loan_stmt->fetchAll();
    foreach ($active_loans as $al) {
        $loan_balance += (float)$al['balance'];
    }
}

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
<title>My Payslips — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-my-payslips" class="page active">
  <div class="page-header">
    <div>
      <h1>My Payslips</h1>
      <p>Personal earnings summary, released payslips, and loan balances</p>
    </div>
    <?php if (has_permission('payroll.view')): ?>
    <div style="display:flex;gap:9px">
      <a class="btn-ghost" href="payroll.php"><?= icon('coin', 15) ?> <span>All Payroll</span></a>
    </div>
    <?php endif; ?>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('my_payslips') ?>

    <?php if (!$employee): ?>
    <div class="table-card">
      <div class="pr-empty">
        <div class="pr-empty-icon"><?= icon('users', 22) ?></div>
        <h3>No Linked Employee Profile</h3>
        <p>Your user account (<strong><?= e($user['username']) ?></strong>) has not been linked to an employee record yet. Please contact your HR Manager or Administrator to link your profile.</p>
      </div>
    </div>
    <?php else: ?>

    <!-- Employee Compensation Profile Card -->
    <div class="pr-card" style="margin-bottom:16px">
      <div class="pr-slip-head" style="border-bottom:none;padding-bottom:0">
        <div style="display:flex;align-items:center;gap:14px">
          <div class="pr-emp-avatar" style="width:48px;height:48px;font-size:16px">
            <?= strtoupper(substr($employee['firstname'], 0, 1) . substr($employee['lastname'], 0, 1)) ?>
          </div>
          <div>
            <h2 style="font-size:18px;font-weight:800;color:var(--text-main)">
              <?= e($employee['firstname'] . ' ' . $employee['lastname']) ?>
            </h2>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px">
              <?= e($employee['employee_code']) ?> &middot; <?= e($employee['position']) ?> &middot; <?= e($employee['branch'] ?: 'Main Branch') ?>
            </div>
          </div>
        </div>

        <div style="text-align:right">
          <span class="pr-badge pr-badge-green"><?= icon('check-circle', 11) ?> <span>Active Employee</span></span>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
            Joined <?= e(date('F j, Y', strtotime($employee['hire_date'] ?: $employee['created_at']))) ?>
          </div>
        </div>
      </div>

      <div class="pr-meta" style="margin-top:20px">
        <div class="pr-meta-cell">
          <div class="pr-meta-label"><?= icon('calendar', 13) ?> <span>Pay Structure</span></div>
          <div class="pr-meta-value" style="text-transform:capitalize">
            <?= e($employee['pay_type']) ?> Rate
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label"><?= icon('coin', 13) ?> <span>Base Compensation</span></div>
          <div class="pr-meta-value"><?= peso((float)$employee['pay_rate']) ?></div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label"><?= icon('credit-card', 13) ?> <span>Payment Method</span></div>
          <div class="pr-meta-value">
            <?= e($method_labels[$employee['payment_method']] ?? 'Cash') ?>
          </div>
        </div>
        <div class="pr-meta-cell">
          <div class="pr-meta-label"><?= icon('card', 13) ?> <span>Disbursement Account</span></div>
          <div class="pr-meta-value" style="font-size:14px">
            <?= $employee['bank_account_last4'] ? ('Acct ending in ' . e($employee['bank_account_last4'])) : 'Cash on Hand' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Metrics Grid -->
    <div class="stat-row">
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--green-lt);color:var(--green)"><?= icon('coin', 18) ?></div>
        <div>
          <div class="mini-stat-val" style="color:var(--green)"><?= peso($ytd_net) ?></div>
          <div class="mini-stat-lbl">YTD Take-Home Pay (<?= date('Y') ?>)</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--blue-lt);color:var(--blue)"><?= icon('file-text', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= count($payslips) ?></div>
          <div class="mini-stat-lbl">Total Payslips Released</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--red-lt);color:var(--red)"><?= icon('scale', 18) ?></div>
        <div>
          <div class="mini-stat-val">&minus;<?= peso($ytd_deductions) ?></div>
          <div class="mini-stat-lbl">YTD Statutory &amp; Deductions</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--amber-lt);color:var(--amber)"><?= icon('credit-card', 18) ?></div>
        <div>
          <div class="mini-stat-val" style="color:<?= $loan_balance > 0 ? 'var(--caramel)' : 'var(--text-main)' ?>">
            <?= peso($loan_balance) ?>
          </div>
          <div class="mini-stat-lbl">Remaining Loan / Advance Balance</div>
        </div>
      </div>
    </div>

    <!-- Active Loan Notice if exists -->
    <?php if ($active_loans): ?>
    <div class="pr-alert pr-alert-warn" style="margin-top:16px">
      <span class="pr-alert-icon"><?= icon('alert-triangle', 18) ?></span>
      <div>
        <strong>You have <?= count($active_loans) ?> active cash advance / loan balance</strong>
        Remaining balance: <strong><?= peso($loan_balance) ?></strong>. Per-period deductions will automatically be applied to each upcoming paycheck until fully amortized.
      </div>
    </div>
    <?php endif; ?>

    <!-- Released Payslips Table -->
    <div class="table-card" style="margin-top:20px">
      <div class="table-scroll-wrapper">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Pay Period</th>
              <th>Pay Date</th>
              <th class="pr-num">Days Worked</th>
              <th class="pr-num">Reg Hours</th>
              <th class="pr-num">Gross Pay</th>
              <th class="pr-num">Deductions</th>
              <th class="pr-num">Net Take-Home</th>
              <th>Payment Status</th>
              <th style="text-align:right">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$payslips): ?>
            <tr><td colspan="9">
              <div class="pr-empty">
                <div class="pr-empty-icon"><?= icon('file-text', 22) ?></div>
                <h3>No released payslips yet</h3>
                <p>When payroll periods are reviewed and released by management, your itemized payslips will appear here for viewing and printing.</p>
              </div>
            </td></tr>
          <?php else: foreach ($payslips as $p): ?>
            <tr>
              <td>
                <div class="pr-emp-name"><?= e($p['period_label']) ?></div>
                <div class="pr-emp-meta">
                  <?= e(date('M j', strtotime($p['period_start']))) ?> &ndash;
                  <?= e(date('M j, Y', strtotime($p['period_end']))) ?>
                </div>
              </td>
              <td><?= e(date('F j, Y', strtotime($p['pay_date']))) ?></td>
              <td class="pr-num"><?= rtrim(rtrim(number_format((float)$p['days_worked'], 1), '0'), '.') ?></td>
              <td class="pr-num"><?= number_format((float)$p['regular_hours'], 1) ?></td>
              <td class="pr-num"><?= peso((float)$p['gross_pay']) ?></td>
              <td class="pr-num" style="color:var(--red)">&minus;<?= peso((float)$p['total_deductions']) ?></td>
              <td class="pr-num pr-net" style="font-size:15px"><?= peso((float)$p['net_pay']) ?></td>
              <td>
                <span class="pr-badge pr-badge-green"><?= icon('check-circle', 11) ?> <span>Paid</span></span>
              </td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-ghost" href="payslip.php?id=<?= (int)$p['id'] ?>" title="View / Print Full Payslip">
                  <?= icon('file-text', 15) ?> <span>View Payslip</span>
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

</body>
</html>

