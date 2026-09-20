<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();
require_permission('payroll.settings');

send_security_headers();

$pdo  = get_db();
$user = current_user();

$toast      = isset($_GET['toast']) ? e($_GET['toast']) : '';
$toast_type = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';

// Fetch current settings
$settings = payroll_settings();

// Defaults if not set in DB
$std_hours     = (float)($settings['standard_hours_per_day'] ?? 8);
$working_days  = (float)($settings['working_days_per_month'] ?? 26);
$grace_minutes = (int)($settings['grace_period_minutes'] ?? 15);
$ot_mult       = (float)($settings['overtime_multiplier'] ?? 1.25);
$rd_mult       = (float)($settings['rest_day_multiplier'] ?? 1.30);
$hol_mult      = (float)($settings['holiday_multiplier'] ?? 2.00);
$night_mult    = (float)($settings['night_diff_multiplier'] ?? 1.10);
$tip_mode      = $settings['tip_pool_mode'] ?? 'hours';
$auto_attend   = (int)($settings['auto_approve_attendance'] ?? 0);

// Fetch active employees for loan creation
$employees = $pdo->query(
    "SELECT id, employee_code, firstname, lastname, position, base_salary
       FROM employees
      WHERE status = 'active'
      ORDER BY lastname, firstname"
)->fetchAll();

// Fetch all loans
$loans = $pdo->query(
    "SELECT l.*, e.firstname, e.lastname, e.employee_code, e.position
       FROM employee_loans l
       JOIN employees e ON e.id = l.employee_id
      ORDER BY l.status = 'active' DESC, l.created_at DESC"
)->fetchAll();

// Fetch recent audit events
$audits = $pdo->query(
    "SELECT a.*, u.username, u.firstname, u.lastname
       FROM payroll_audit a
       LEFT JOIN users u ON u.id = a.actor_id
      ORDER BY a.created_at DESC
      LIMIT 20"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<?= csrf_meta() ?>
<title>Payroll Settings — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-payroll-settings" class="page active">
  <div class="page-header">
    <div>
      <h1>Payroll Settings</h1>
      <p>Working standards, rate multipliers, statutory reference, and loan management</p>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap">
      <a class="btn-ghost" href="payroll.php"><?= icon('chevron', 15) ?> <span>Back to Payroll</span></a>
      <a class="btn-ghost" href="payroll_reports.php"><?= icon('bar-chart', 15) ?> <span>Reports</span></a>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('settings') ?>

    <!-- Tabs Header -->
    <div class="pr-tabs">
      <button type="button" class="pr-tab-btn is-active" onclick="switchTab('tab-general', this)">
        <?= icon('permissions', 16) ?> <span>General &amp; Multipliers</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchTab('tab-loans', this)">
        <?= icon('coin', 16) ?> <span>Cash Advances &amp; Loans (<?= count($loans) ?>)</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchTab('tab-statutory', this)">
        <?= icon('file-text', 16) ?> <span>Statutory Schedules (Reference)</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchTab('tab-audit', this)">
        <?= icon('history', 16) ?> <span>Audit Trail</span>
      </button>
    </div>

    <!-- TAB 1: General & Multipliers -->
    <div id="tab-general" class="pr-tab-pane is-active">
      <form id="settings-form" onsubmit="saveSettings(event)">
        <div class="pr-settings-grid">

          <!-- Work Standards Card -->
          <div class="pr-card">
            <div class="pr-card-header">
              <div>
                <div class="pr-card-title"><?= icon('clock', 18) ?> Work Standards</div>
                <div class="pr-card-sub">Baseline hours and attendance grace periods</div>
              </div>
            </div>

            <div class="field">
              <label for="f-hours">Standard Hours per Day</label>
              <input type="number" id="f-hours" name="standard_hours_per_day" step="0.5" min="1" max="24" required
                     value="<?= htmlspecialchars($std_hours) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Hours beyond this threshold on a normal workday count as Overtime.
              </small>
            </div>

            <div class="field" style="margin-top:14px">
              <label for="f-days">Working Days per Month</label>
              <input type="number" id="f-days" name="working_days_per_month" step="0.5" min="1" max="31" required
                     value="<?= htmlspecialchars($working_days) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Used to compute daily and hourly rates from monthly base salaries (Rate = Monthly / Days).
              </small>
            </div>

            <div class="field" style="margin-top:14px">
              <label for="f-grace">Lateness Grace Period (Minutes)</label>
              <input type="number" id="f-grace" name="grace_period_minutes" step="1" min="0" max="120" required
                     value="<?= htmlspecialchars($grace_minutes) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Clock-ins within this window after shift start will not incur a late deduction.
              </small>
            </div>
          </div>

          <!-- Multipliers Card -->
          <div class="pr-card">
            <div class="pr-card-header">
              <div>
                <div class="pr-card-title"><?= icon('scale', 18) ?> Pay Multipliers</div>
                <div class="pr-card-sub">Overtime, holiday, rest-day, and night rates</div>
              </div>
            </div>

            <div class="field">
              <label for="f-ot">Overtime Multiplier (Regular Day)</label>
              <input type="number" id="f-ot" name="overtime_multiplier" step="0.05" min="1.0" max="5.0" required
                     value="<?= htmlspecialchars($ot_mult) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Philippine standard is 1.25 (125% of hourly rate).
              </small>
            </div>

            <div class="field" style="margin-top:14px">
              <label for="f-rd">Rest-Day Multiplier</label>
              <input type="number" id="f-rd" name="rest_day_multiplier" step="0.05" min="1.0" max="5.0" required
                     value="<?= htmlspecialchars($rd_mult) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Philippine standard is 1.30 (130% of hourly rate).
              </small>
            </div>

            <div class="field" style="margin-top:14px">
              <label for="f-hol">Regular Holiday Multiplier</label>
              <input type="number" id="f-hol" name="holiday_multiplier" step="0.05" min="1.0" max="5.0" required
                     value="<?= htmlspecialchars($hol_mult) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Philippine standard is 2.00 (200% double pay).
              </small>
            </div>

            <div class="field" style="margin-top:14px">
              <label for="f-night">Night Differential Multiplier (22:00 - 06:00)</label>
              <input type="number" id="f-night" name="night_diff_multiplier" step="0.05" min="1.0" max="3.0" required
                     value="<?= htmlspecialchars($night_mult) ?>">
              <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                Philippine standard is 1.10 (additional 10% premium).
              </small>
            </div>
          </div>

          <!-- Rules & Distribution Card -->
          <div class="pr-card" style="grid-column: 1 / -1">
            <div class="pr-card-header">
              <div>
                <div class="pr-card-title"><?= icon('coin', 18) ?> Rules &amp; Distribution</div>
                <div class="pr-card-sub">Attendance inclusion and tip pool distribution method</div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:20px">
              <div class="field">
                <label for="f-tip">Tip &amp; Service Charge Pool Mode</label>
                <select id="f-tip" name="tip_pool_mode">
                  <option value="hours" <?= $tip_mode === 'hours' ? 'selected' : '' ?>>
                    Proportional to hours worked (Fair for variable shifts)
                  </option>
                  <option value="equal" <?= $tip_mode === 'equal' ? 'selected' : '' ?>>
                    Equal split among all tip-eligible active staff
                  </option>
                </select>
                <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                  Calculates pooled tips and service charges collected via POS inside the period.
                </small>
              </div>

              <div class="field">
                <label for="f-attend">Attendance Inclusion Policy</label>
                <select id="f-attend" name="auto_approve_attendance">
                  <option value="0" <?= $auto_attend === 0 ? 'selected' : '' ?>>
                    Only calculate attendance records approved by HR/Manager (Recommended)
                  </option>
                  <option value="1" <?= $auto_attend === 1 ? 'selected' : '' ?>>
                    Include all recorded clock-ins even if pending approval
                  </option>
                </select>
                <small style="font-size:11.5px;color:var(--text-muted);display:block;margin-top:4px">
                  When set to recommended, unapproved attendance flags a review exception instead of quietly paying.
                </small>
              </div>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:24px;padding-top:16px;border-top:1px solid var(--border)">
              <button type="submit" class="btn-add" id="btn-save-settings">
                <?= icon('check-circle', 16) ?> <span>Save Settings</span>
              </button>
            </div>
          </div>

        </div>
      </form>
    </div>

    <!-- TAB 2: Cash Advances & Loans -->
    <div id="tab-loans" class="pr-tab-pane">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <div>
          <h2 style="font-size:18px;font-weight:700">Employee Loans &amp; Cash Advances</h2>
          <p style="font-size:13px;color:var(--text-muted)">Automated per-period deductions are applied on every payroll run.</p>
        </div>
        <button type="button" class="btn-add" onclick="openLoanModal()">
          <?= icon('plus', 16) ?> <span>Issue New Loan / Advance</span>
        </button>
      </div>

      <div class="table-card">
        <div class="table-scroll-wrapper">
          <table class="pr-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Type</th>
                <th class="pr-num">Principal</th>
                <th class="pr-num">Balance</th>
                <th class="pr-num">Deduction / Period</th>
                <th>Start Date</th>
                <th>Status</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$loans): ?>
              <tr><td colspan="8">
                <div class="pr-empty">
                  <div class="pr-empty-icon"><?= icon('coin', 22) ?></div>
                  <h3>No active loans or cash advances</h3>
                  <p>Issue an advance to an employee, and it will be automatically deducted in installments across payroll runs.</p>
                </div>
              </td></tr>
            <?php else: foreach ($loans as $l): ?>
              <tr>
                <td>
                  <div class="pr-emp-name"><?= e($l['lastname'] . ', ' . $l['firstname']) ?></div>
                  <div class="pr-emp-meta"><?= e($l['employee_code']) ?> &middot; <?= e($l['position']) ?></div>
                </td>
                <td><strong><?= e($l['loan_type']) ?></strong></td>
                <td class="pr-num"><?= peso((float)$l['principal']) ?></td>
                <td class="pr-num" style="color:<?= (float)$l['balance'] > 0 ? 'var(--caramel)' : 'var(--green)' ?>;font-weight:700">
                  <?= peso((float)$l['balance']) ?>
                </td>
                <td class="pr-num">&minus;<?= peso((float)$l['per_period_amount']) ?></td>
                <td><?= e(date('M j, Y', strtotime($l['start_date']))) ?></td>
                <td>
                  <?php if ($l['status'] === 'active'): ?>
                    <span class="pr-badge pr-badge-amber"><?= icon('clock', 11) ?> <span>Active</span></span>
                  <?php elseif ($l['status'] === 'completed'): ?>
                    <span class="pr-badge pr-badge-green"><?= icon('check-circle', 11) ?> <span>Completed</span></span>
                  <?php else: ?>
                    <span class="pr-badge pr-badge-gray"><?= icon('x-circle', 11) ?> <span>Cancelled</span></span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap">
                  <?php if ($l['status'] === 'active'): ?>
                  <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:12px;display:inline-flex;align-items:center;gap:4px"
                          onclick="updateLoanStatus(<?= (int)$l['id'] ?>, 'completed')">
                    <?= icon('check', 12) ?> <span>Mark Done</span>
                  </button>
                  <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:12px;color:var(--red);display:inline-flex;align-items:center;gap:4px"
                          onclick="updateLoanStatus(<?= (int)$l['id'] ?>, 'cancelled')">
                    <?= icon('x', 12) ?> <span>Cancel</span>
                  </button>
                  <?php else: ?>
                  <span style="font-size:12px;color:var(--text-muted)">&mdash;</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 3: Statutory Reference -->
    <div id="tab-statutory" class="pr-tab-pane">
      <div class="pr-alert pr-alert-info" style="margin-bottom:20px">
        <span class="pr-alert-icon"><?= icon('scale', 18) ?></span>
        <div>
          <strong>Philippine Statutory Contribution Guidelines (Reference Schedule)</strong>
          The calculations in <code>includes/payroll_helpers.php</code> implement standard Republic Acts (SSS, PhilHealth, Pag-IBIG, and TRAIN Law Withholding Tax).
        </div>
      </div>

      <div class="pr-settings-grid">
        <!-- SSS -->
        <div class="pr-card">
          <div class="pr-card-header">
            <div>
              <div class="pr-card-title">SSS (Social Security System)</div>
              <div class="pr-card-sub">Republic Act No. 11199</div>
            </div>
            <span class="pr-badge pr-badge-blue"><?= icon('shield', 11) ?> <span>4.5% Employee</span></span>
          </div>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.6">
            Employee contribution is calculated at 4.5% of the monthly salary credit (MSC), with a minimum MSC of &#8369;4,000 (&#8369;180/mo) up to maximum MSC of &#8369;30,000 (&#8369;1,350/mo). For semimonthly/weekly pay, the deduction is distributed proportionally.
          </p>
        </div>

        <!-- PhilHealth -->
        <div class="pr-card">
          <div class="pr-card-header">
            <div>
              <div class="pr-card-title">PhilHealth</div>
              <div class="pr-card-sub">Universal Health Care Act</div>
            </div>
            <span class="pr-badge pr-badge-green"><?= icon('check-circle', 11) ?> <span>2.5% Employee</span></span>
          </div>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.6">
            Current contribution rate is 5.0% divided equally between employer (2.5%) and employee (2.5%), with income floor of &#8369;10,000 and income ceiling of &#8369;100,000.
          </p>
        </div>

        <!-- Pag-IBIG -->
        <div class="pr-card">
          <div class="pr-card-header">
            <div>
              <div class="pr-card-title">Pag-IBIG Fund (HDMF)</div>
              <div class="pr-card-sub">Republic Act No. 9679</div>
            </div>
            <span class="pr-badge pr-badge-purple"><?= icon('home', 11) ?> <span>2% Employee</span></span>
          </div>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.6">
            Employee contribution is 2% of monthly basic compensation, capped at maximum statutory ceiling of &#8369;200 per month (or &#8369;100 per semimonthly pay).
          </p>
        </div>

        <!-- BIR Tax -->
        <div class="pr-card">
          <div class="pr-card-header">
            <div>
              <div class="pr-card-title">BIR Withholding Tax</div>
              <div class="pr-card-sub">TRAIN Law (R.A. 10963)</div>
            </div>
            <span class="pr-badge pr-badge-amber"><?= icon('scale', 11) ?> <span>Graduated Rates</span></span>
          </div>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.6">
            Taxable compensation after deducting non-taxable contributions (SSS, PhilHealth, Pag-IBIG). Net taxable income up to &#8369;20,833/month (&#8369;250,000/year) is <strong>0% Tax Exempt</strong>. Higher brackets follow 15%, 20%, 25%, 30%, and 35% graduated steps.
          </p>
        </div>
      </div>
    </div>

    <!-- TAB 4: Audit Trail -->
    <div id="tab-audit" class="pr-tab-pane">
      <div class="table-card">
        <div class="table-scroll-wrapper">
          <table class="pr-table">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$audits): ?>
              <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--text-muted)">
                No audit entries recorded yet.
              </td></tr>
            <?php else: foreach ($audits as $a): ?>
              <tr>
                <td style="white-space:nowrap;font-size:12.5px;color:var(--text-muted)">
                  <?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?>
                </td>
                <td>
                  <strong><?= e($a['firstname'] ? ($a['firstname'] . ' ' . $a['lastname']) : ($a['username'] ?: 'System')) ?></strong>
                </td>
                <td>
                  <span class="pr-badge pr-badge-blue"><?= e(str_replace('_', ' ', $a['action'])) ?></span>
                </td>
                <td style="font-size:13px;color:var(--text-main)"><?= e($a['detail']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- New Loan Modal -->
<div class="modal-bg" id="loan-modal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3><?= icon('coin', 18) ?> <span>Issue Cash Advance / Loan</span></h3>
      <button type="button" class="modal-close" onclick="closeLoanModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <form id="loan-form" onsubmit="saveLoan(event)">
      <div class="modal-body">
        <div class="field">
          <label for="l-emp">Employee</label>
          <select id="l-emp" name="employee_id" required>
            <option value="">-- Select Employee --</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>">
              <?= e($emp['lastname'] . ', ' . $emp['firstname']) ?> (<?= e($emp['position']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="l-type">Loan Type</label>
          <select id="l-type" name="loan_type">
            <option value="Cash Advance">Cash Advance</option>
            <option value="Salary Loan">Salary Loan</option>
            <option value="Emergency Assistance">Emergency Assistance</option>
            <option value="Uniform / Tool Deposit">Uniform / Equipment Advance</option>
          </select>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="l-principal">Principal Amount (&#8369;)</label>
            <input type="number" id="l-principal" name="principal" step="0.01" min="1" required placeholder="5000.00">
          </div>
          <div class="field">
            <label for="l-period">Deduction per Period (&#8369;)</label>
            <input type="number" id="l-period" name="per_period_amount" step="0.01" min="1" required placeholder="500.00">
          </div>
        </div>

        <div class="field">
          <label for="l-start">Start Date</label>
          <input type="date" id="l-start" name="start_date" required value="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="closeLoanModal()"><?= icon('x', 14) ?> <span>Cancel</span></button>
        <button type="submit" class="btn-add"><?= icon('plus', 15) ?> <span>Save Loan</span></button>
      </div>
    </form>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function switchTab(paneId, btn) {
  document.querySelectorAll('.pr-tab-pane').forEach(p => p.classList.remove('is-active'));
  document.querySelectorAll('.pr-tab-btn').forEach(b => b.classList.remove('is-active'));
  document.getElementById(paneId).classList.add('is-active');
  btn.classList.add('is-active');
}

function openLoanModal()  { document.getElementById('loan-modal').classList.add('open'); }
function closeLoanModal() { document.getElementById('loan-modal').classList.remove('open'); }

document.getElementById('loan-modal')?.addEventListener('click', e => {
  if (e.target.id === 'loan-modal') closeLoanModal();
});

async function saveSettings(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-save-settings');
  btn.disabled = true;

  const form = document.getElementById('settings-form');
  const formData = new FormData(form);
  const settingsObj = {};
  formData.forEach((val, key) => settingsObj[key] = val);

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ action: 'update_settings', settings: settingsObj })
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to save settings.');

    Swal.fire({
      icon: 'success',
      title: 'Settings Saved',
      text: 'Payroll configuration has been updated successfully.',
      timer: 1800,
      showConfirmButton: false
    });
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message });
  } finally {
    btn.disabled = false;
  }
}

async function saveLoan(e) {
  e.preventDefault();
  const form = document.getElementById('loan-form');
  const formData = new FormData(form);
  const payload = { action: 'add_loan' };
  formData.forEach((val, key) => payload[key] = val);

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to record loan.');

    Swal.fire({
      icon: 'success',
      title: 'Loan Recorded',
      text: 'Cash advance / loan was created successfully.',
      timer: 1500,
      showConfirmButton: false
    }).then(() => location.reload());
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message });
  }
}

async function updateLoanStatus(loanId, status) {
  const actionText = status === 'completed' ? 'mark this loan as completed' : 'cancel this loan';
  const result = await Swal.fire({
    title: 'Are you sure?',
    text: `Do you want to ${actionText}? Future payroll runs will no longer deduct for this loan.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#8B4513',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, proceed'
  });

  if (!result.isConfirmed) return;

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ action: 'update_loan_status', loan_id: loanId, status: status })
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to update loan.');

    Swal.fire({
      icon: 'success',
      title: 'Updated',
      text: 'Loan status updated.',
      timer: 1400,
      showConfirmButton: false
    }).then(() => location.reload());
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message });
  }
}
</script>
</body>
</html>

