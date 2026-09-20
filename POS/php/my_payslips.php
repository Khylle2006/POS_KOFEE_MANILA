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
$all_loans = [];
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

    // All loans / advances for this staff member (active, pending, completed, declined)
    $all_loans_stmt = $pdo->prepare(
        "SELECT l.*,
                (SELECT COALESCE(SUM(amount), 0) FROM loan_repayments WHERE loan_id = l.id) AS total_repaid
           FROM employee_loans l
          WHERE l.employee_id = :e
          ORDER BY (l.status = 'pending_approval') DESC, (l.status = 'active') DESC, l.created_at DESC"
    );
    $all_loans_stmt->execute([':e' => (int)$employee['id']]);
    $all_loans = $all_loans_stmt->fetchAll();

    foreach ($all_loans as $al) {
        if ($al['status'] === 'active') {
            $loan_balance += (float)$al['balance'];
            $active_loans[] = $al;
        }
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
<meta name="csrf-token" content="<?= e(csrf_token()) ?>"/>
<title>My Compensation &amp; Payslips — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-my-payslips" class="page active">
  <div class="page-header">
    <div>
      <h1>My Compensation &amp; Payslips</h1>
      <p>Personal earnings summary, released payslips, and loan &amp; advance ledger</p>
    </div>
    <div style="display:flex;gap:9px">
      <?php if ($employee && (has_permission('payroll.advance.request') || has_permission('payroll.own'))): ?>
      <button type="button" class="btn-add" onclick="openAdvanceModal()">
        <?= icon('plus', 15) ?> <span>Request Cash Advance</span>
      </button>
      <?php endif; ?>
      <?php if (has_permission('payroll.view')): ?>
      <a class="btn-ghost" href="payroll.php"><?= icon('coin', 15) ?> <span>Payroll Management</span></a>
      <?php endif; ?>
    </div>
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

    <!-- Personal Loans & Cash Advances Ledger Card -->
    <div class="table-card" style="margin-top:20px">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
          <h3 style="font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px">
            <?= icon('coin', 18) ?> <span>My Cash Advances &amp; Loans Ledger</span>
          </h3>
          <p style="font-size:12.5px;color:var(--text-muted);margin-top:2px">
            Track your cash advance requests, repayment progress, and per-cutoff payroll amortizations
          </p>
        </div>
        <?php if ($employee && (has_permission('payroll.advance.request') || has_permission('payroll.own'))): ?>
        <button type="button" class="btn-ghost" style="padding:6px 12px;font-size:13px;display:inline-flex;align-items:center;gap:6px" onclick="openAdvanceModal()">
          <?= icon('plus', 14) ?> <span>Apply for Advance</span>
        </button>
        <?php endif; ?>
      </div>

      <div class="table-scroll-wrapper">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Type &amp; Purpose</th>
              <th class="pr-num">Original Amount</th>
              <th class="pr-num">Repaid to Date</th>
              <th class="pr-num">Remaining Balance</th>
              <th class="pr-num">Cutoff Deduction</th>
              <th>Date / Started</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$all_loans): ?>
            <tr><td colspan="7">
              <div class="pr-empty" style="padding:32px 16px">
                <div class="pr-empty-icon"><?= icon('coin', 22) ?></div>
                <h3 style="font-size:15px">No loans or cash advances on record</h3>
                <p style="font-size:13px">You currently have no active advances or pending requests. If you need salary advances or emergency assistance, click "Apply for Advance".</p>
              </div>
            </td></tr>
          <?php else: foreach ($all_loans as $al): ?>
            <tr>
              <td>
                <strong style="color:var(--text-main)"><?= e($al['loan_type']) ?></strong>
                <?php if (!empty($al['notes'])): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= e($al['notes']) ?></div>
                <?php endif; ?>
              </td>
              <td class="pr-num"><?= peso((float)$al['principal']) ?></td>
              <td class="pr-num" style="color:var(--green);font-weight:600">
                <?= peso((float)$al['total_repaid']) ?>
              </td>
              <td class="pr-num" style="font-weight:700;color:<?= (float)$al['balance'] > 0 ? 'var(--caramel)' : 'var(--text-muted)' ?>">
                <?= peso((float)$al['balance']) ?>
              </td>
              <td class="pr-num">&minus;<?= peso((float)$al['per_period_amount']) ?></td>
              <td><?= e(date('M j, Y', strtotime($al['start_date'] ?: $al['created_at']))) ?></td>
              <td>
                <?php if ($al['status'] === 'pending_approval'): ?>
                  <span class="pr-badge pr-badge-amber"><?= icon('clock', 11) ?> <span>Pending Review</span></span>
                <?php elseif ($al['status'] === 'active'): ?>
                  <span class="pr-badge pr-badge-blue"><?= icon('check-circle', 11) ?> <span>Active</span></span>
                <?php elseif ($al['status'] === 'completed'): ?>
                  <span class="pr-badge pr-badge-green"><?= icon('check-circle', 11) ?> <span>Paid Off</span></span>
                <?php elseif ($al['status'] === 'declined'): ?>
                  <span class="pr-badge pr-badge-red"><?= icon('x-circle', 11) ?> <span>Declined</span></span>
                <?php else: ?>
                  <span class="pr-badge pr-badge-gray"><?= icon('x-circle', 11) ?> <span>Cancelled</span></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Released Payslips Table -->
    <div class="table-card" style="margin-top:20px">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
        <h3 style="font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px">
          <?= icon('file-text', 18) ?> <span>Released Payslips History</span>
        </h3>
        <p style="font-size:12.5px;color:var(--text-muted);margin-top:2px">
          View and download official itemized salary statements and statutory contribution breakdowns
        </p>
      </div>
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

<!-- Request Cash Advance Modal -->
<div class="modal-bg" id="advance-modal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3><?= icon('coin', 18) ?> <span>Request Cash / Salary Advance</span></h3>
      <button type="button" class="modal-close" onclick="closeAdvanceModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <form id="advance-form" onsubmit="submitAdvanceRequest(event)">
      <div class="modal-body">
        <div class="pr-alert pr-alert-info" style="margin-bottom:16px">
          <span class="pr-alert-icon"><?= icon('info', 16) ?></span>
          <div style="font-size:12.5px;line-height:1.5">
            Advance requests are reviewed and approved by management. Once approved, equal deductions will be automatically scheduled on your upcoming paychecks.
          </div>
        </div>

        <div class="field">
          <label for="adv-type">Advance Type</label>
          <select id="adv-type" name="loan_type">
            <option value="Cash Advance">Cash Advance (Standard)</option>
            <option value="Salary Advance">Salary Advance (Earned Wages)</option>
            <option value="Emergency Assistance">Emergency Financial Assistance</option>
          </select>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="adv-amount">Requested Amount (&#8369;) <span class="req">*</span></label>
            <input type="number" id="adv-amount" name="principal" step="50" min="100" max="50000" required placeholder="e.g. 3000" oninput="calculatePerCutoff()">
          </div>
          <div class="field">
            <label for="adv-terms">Repayment Schedule <span class="req">*</span></label>
            <select id="adv-terms" name="installments" onchange="calculatePerCutoff()">
              <option value="1">1 Cutoff (Next Paycheck)</option>
              <option value="2" selected>2 Cutoffs (1 Month)</option>
              <option value="3">3 Cutoffs (1.5 Months)</option>
              <option value="4">4 Cutoffs (2 Months)</option>
              <option value="6">6 Cutoffs (3 Months)</option>
            </select>
          </div>
        </div>

        <div class="field" style="background:var(--cream, #fcfaf7);border:1px dashed var(--latte, #efe0cc);padding:10px 14px;border-radius:8px">
          <div style="font-size:12px;color:var(--text-muted)">Estimated Deduction per Cutoff:</div>
          <div id="adv-estimate" style="font-size:18px;font-weight:800;color:var(--caramel);margin-top:2px">&#8369;0.00 / cutoff</div>
        </div>

        <div class="field" style="margin-top:14px">
          <label for="adv-notes">Purpose / Reason for Advance <span class="req">*</span></label>
          <textarea id="adv-notes" name="notes" rows="3" required placeholder="Briefly describe the purpose of this advance request (e.g. medical emergency, tuition payment, family assistance)..." style="resize:vertical"></textarea>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="closeAdvanceModal()"><?= icon('x', 14) ?> <span>Cancel</span></button>
        <button type="submit" class="btn-add" id="btn-submit-advance">
          <?= icon('send', 15) ?> <span>Submit Request</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

function openAdvanceModal() {
  document.getElementById('advance-modal').classList.add('open');
  calculatePerCutoff();
}

function closeAdvanceModal() {
  document.getElementById('advance-modal').classList.remove('open');
}

document.getElementById('advance-modal')?.addEventListener('click', e => {
  if (e.target.id === 'advance-modal') closeAdvanceModal();
});

function calculatePerCutoff() {
  const amount = parseFloat(document.getElementById('adv-amount')?.value) || 0;
  const terms = parseInt(document.getElementById('adv-terms')?.value) || 1;
  const estimateEl = document.getElementById('adv-estimate');
  if (estimateEl) {
    if (amount > 0 && terms > 0) {
      const perCutoff = (amount / terms).toFixed(2);
      estimateEl.textContent = '₱' + Number(perCutoff).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' / cutoff (' + terms + ' installments)';
    } else {
      estimateEl.textContent = '₱0.00 / cutoff';
    }
  }
}

async function submitAdvanceRequest(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-advance');
  btn.disabled = true;

  const form = document.getElementById('advance-form');
  const formData = new FormData(form);
  const payload = { action: 'request_advance' };
  formData.forEach((val, key) => payload[key] = val);

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to submit advance request.');

    Swal.fire({
      icon: 'success',
      title: 'Request Submitted',
      text: 'Your cash advance request has been forwarded to management for review.',
      timer: 2000,
      showConfirmButton: false
    }).then(() => location.reload());
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Submission Failed', text: err.message });
  } finally {
    btn.disabled = false;
  }
}
</script>

</body>
</html>

