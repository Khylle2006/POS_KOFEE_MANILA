<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();
require_permission('payroll.view');

send_security_headers();

$pdo  = get_db();
$user = current_user();

$can_manage  = has_permission('payroll.manage');
$can_approve = has_permission('payroll.approve');
$can_release = has_permission('payroll.release');

$period_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM payroll_periods WHERE id = :id');
$stmt->execute([':id' => $period_id]);
$period = $stmt->fetch();

if (!$period) {
    header('Location: payroll.php?toast=' . urlencode('Pay period not found.') . '&type=error');
    exit;
}

// ── Register ──────────────────────────────────
$slips_stmt = $pdo->prepare(
    'SELECT s.*, e.firstname, e.lastname, e.employee_code, e.position, e.branch
       FROM payslips s
       JOIN employees e ON e.id = s.employee_id
      WHERE s.period_id = :p
      ORDER BY e.lastname, e.firstname'
);
$slips_stmt->execute([':p' => $period_id]);
$slips = $slips_stmt->fetchAll();

$exceptions = array_filter($slips, fn($s) => (int)$s['has_exception'] === 1);

$totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0,
           'commission' => 0.0, 'tips' => 0.0, 'overtime' => 0.0];
foreach ($slips as $s) {
    $totals['gross']      += (float)$s['gross_pay'];
    $totals['deductions'] += (float)$s['total_deductions'];
    $totals['net']        += (float)$s['net_pay'];
    $totals['commission'] += (float)$s['commission'];
    $totals['tips']       += (float)$s['tips'];
    $totals['overtime']   += (float)$s['overtime_pay'];
}

// ── Workflow state ────────────────────────────
$steps = [
    ['Period opened',  'Created ' . date('M j', strtotime($period['created_at']))],
    ['Calculated',     $period['calculated_at'] ? date('M j, g:i A', strtotime($period['calculated_at'])) : 'Not yet run'],
    ['Approved',       $period['approved_at']   ? date('M j, g:i A', strtotime($period['approved_at']))   : 'Awaiting review'],
    ['Released',       $period['paid_at']       ? date('M j, g:i A', strtotime($period['paid_at']))       : 'Not released'],
];
$step_index = match ($period['status']) {
    'draft'      => 1,
    'calculated' => 2,
    'approved'   => 3,
    'paid', 'locked' => 4,
    default      => 1,
};

$editable = in_array($period['status'], ['draft', 'calculated'], true);

$toast      = isset($_GET['toast']) ? e($_GET['toast']) : '';
$toast_type = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<?= csrf_meta() ?>
<title><?= e($period['label']) ?> — Payroll — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-payroll-run" class="page active">
  <div class="page-header">
    <div>
      <h1><?= e($period['label']) ?></h1>
      <p>
        <?= e(date('F j', strtotime($period['period_start']))) ?> &ndash;
        <?= e(date('F j, Y', strtotime($period['period_end']))) ?>
        &middot; pay date <?= e(date('F j, Y', strtotime($period['pay_date']))) ?>
      </p>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap">
      <a class="btn-ghost" href="payroll.php"><?= icon('chevron', 15) ?> <span>All Periods</span></a>

      <?php if ($slips): ?>
      <a class="btn-ghost" href="../api/payroll.php?action=export_csv&period_id=<?= (int)$period_id ?>&type=bank_advice">
        <?= icon('credit-card', 15) ?> <span>Bank CSV</span>
      </a>
      <a class="btn-ghost" href="../api/payroll.php?action=export_csv&period_id=<?= (int)$period_id ?>&type=register">
        <?= icon('download', 15) ?> <span>Register CSV</span>
      </a>
      <?php endif; ?>

      <?php if ($can_manage && $editable): ?>
      <button class="btn-ghost" onclick="runAction('calculate', 'Recalculate this period? Any figures already on screen will be replaced. Manual allowances, bonuses and deductions are kept.')">
        <?= icon('refresh', 15) ?> <span><?= $period['status'] === 'draft' ? 'Calculate' : 'Recalculate' ?></span>
      </button>
      <button class="btn-ghost" style="color:var(--red)" onclick="confirmDeletePeriod()">
        <?= icon('trash', 15) ?> <span>Delete</span>
      </button>
      <?php endif; ?>

      <?php if ($can_approve && $period['status'] === 'calculated'): ?>
      <button class="btn-add" onclick="openApprove()">
        <?= icon('check-circle', 15) ?> <span>Approve</span>
      </button>
      <?php endif; ?>

      <?php if ($can_release && $period['status'] === 'approved'): ?>
      <button class="btn-add" onclick="openRelease()">
        <?= icon('credit-card', 15) ?> <span>Release Payment</span>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('runs', $period['label']) ?>

    <!-- Workflow -->
    <div class="pr-steps">
      <?php foreach ($steps as $i => [$title, $sub]):
        $n = $i + 1;
        $cls = $n < $step_index ? 'is-done' : ($n === $step_index ? 'is-active' : '');
      ?>
      <div class="pr-step <?= $cls ?>">
        <span class="pr-step-num"><?= $n < $step_index ? icon('check', 14) : $n ?></span>
        <span class="pr-step-text">
          <span class="pr-step-title"><?= e($title) ?></span>
          <span class="pr-step-sub"><?= e($sub) ?></span>
        </span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Exceptions -->
    <?php if ($exceptions && $period['status'] !== 'paid'): ?>
    <div class="pr-alert pr-alert-warn" style="margin-top:16px">
      <span class="pr-alert-icon"><?= icon('alert-triangle', 18) ?></span>
      <div>
        <strong><?= count($exceptions) ?> payslip<?= count($exceptions) === 1 ? '' : 's' ?> need a look before approval</strong>
        Highlighted rows below have missing clock-outs, unapproved attendance,
        no configured rate, or a negative net. Approving anyway records your
        decision in the audit trail.
      </div>
    </div>
    <?php endif; ?>

    <?php if ($period['status'] === 'paid'): ?>
    <div class="pr-alert pr-alert-info" style="margin-top:16px">
      <span class="pr-alert-icon"><?= icon('lock', 18) ?></span>
      <div>
        <strong>This period is closed</strong>
        Released on <?= e(date('F j, Y \a\t g:i A', strtotime($period['paid_at']))) ?>.
        Figures are frozen; later attendance edits will not change these payslips.
      </div>
    </div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="pr-meta" style="margin-top:16px">
      <div class="pr-meta-cell">
        <div class="pr-meta-label">Headcount</div>
        <div class="pr-meta-value"><?= count($slips) ?></div>
      </div>
      <div class="pr-meta-cell">
        <div class="pr-meta-label">Gross Pay</div>
        <div class="pr-meta-value"><?= peso($totals['gross']) ?></div>
      </div>
      <div class="pr-meta-cell">
        <div class="pr-meta-label">Total Deductions</div>
        <div class="pr-meta-value"><?= peso($totals['deductions']) ?></div>
      </div>
      <div class="pr-meta-cell">
        <div class="pr-meta-label">Net Payout</div>
        <div class="pr-meta-value" style="color:var(--caramel)"><?= peso($totals['net']) ?></div>
      </div>
    </div>

    <!-- Register -->
    <div class="table-card" style="margin-top:16px">
      <div class="table-scroll-wrapper">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th class="pr-num">Days</th>
              <th class="pr-num">Reg Hrs</th>
              <th class="pr-num">OT Hrs</th>
              <th class="pr-num">Basic</th>
              <th class="pr-num">OT</th>
              <th class="pr-num">Comm.</th>
              <th class="pr-num">Tips</th>
              <th class="pr-num">Gross</th>
              <th class="pr-num">Deductions</th>
              <th class="pr-num">Net Pay</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$slips): ?>
            <tr><td colspan="12">
              <div class="pr-empty">
                <div class="pr-empty-icon"><?= icon('clipboard', 22) ?></div>
                <h3>Nothing calculated yet</h3>
                <p>Run the calculation to pull approved attendance, POS sales,
                   tips and active loan repayments into this register.</p>
                <?php if ($can_manage && $editable): ?>
                <button class="btn-add" style="margin-top:16px"
                        onclick="runAction('calculate', 'Run the payroll calculation for this period?')">
                  <?= icon('refresh', 15) ?> <span>Calculate Now</span>
                </button>
                <?php endif; ?>
              </div>
            </td></tr>
          <?php else: foreach ($slips as $s):
            $initials = strtoupper(substr($s['firstname'], 0, 1) . substr($s['lastname'], 0, 1));
          ?>
            <tr class="<?= (int)$s['has_exception'] === 1 ? 'has-exception' : '' ?>">
              <td>
                <div class="pr-emp">
                  <span class="pr-emp-avatar"><?= e($initials) ?></span>
                  <span style="min-width:0">
                    <span class="pr-emp-name"><?= e($s['lastname'] . ', ' . $s['firstname']) ?></span>
                    <span class="pr-emp-meta">
                      <?= e($s['employee_code']) ?> &middot; <?= e($s['position']) ?>
                    </span>
                  </span>
                </div>
                <?php if ($s['exception_note']): ?>
                <div style="margin-top:6px;font-size:11.5px;color:var(--amber);font-weight:600">
                  <?= e($s['exception_note']) ?>
                </div>
                <?php endif; ?>
              </td>
              <td class="pr-num"><?= rtrim(rtrim(number_format((float)$s['days_worked'], 1), '0'), '.') ?></td>
              <td class="pr-num"><?= number_format((float)$s['regular_hours'], 1) ?></td>
              <td class="pr-num <?= (float)$s['overtime_hours'] == 0 ? 'pr-zero' : '' ?>">
                <?= number_format((float)$s['overtime_hours'], 1) ?>
              </td>
              <td class="pr-num"><?= peso((float)$s['basic_pay']) ?></td>
              <td class="pr-num <?= (float)$s['overtime_pay'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['overtime_pay']) ?></td>
              <td class="pr-num <?= (float)$s['commission'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['commission']) ?></td>
              <td class="pr-num <?= (float)$s['tips'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['tips']) ?></td>
              <td class="pr-num"><?= peso((float)$s['gross_pay']) ?></td>
              <td class="pr-num" style="color:var(--red)">&minus;<?= peso((float)$s['total_deductions']) ?></td>
              <td class="pr-num pr-net"><?= peso((float)$s['net_pay']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-ghost" href="payslip.php?id=<?= (int)$s['id'] ?>" title="View payslip">
                  <?= icon('file-text', 15) ?>
                </a>
                <?php if ($can_manage && $editable): ?>
                <button class="btn-ghost" title="Adjustments"
                        onclick="openAdjust(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['firstname'] . ' ' . $s['lastname']), ENT_QUOTES, 'UTF-8') ?>)">
                  <?= icon('edit', 15) ?>
                </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4">Totals &mdash; <?= count($slips) ?> employee<?= count($slips) === 1 ? '' : 's' ?></td>
              <td class="pr-num"></td>
              <td class="pr-num"><?= peso($totals['overtime']) ?></td>
              <td class="pr-num"><?= peso($totals['commission']) ?></td>
              <td class="pr-num"><?= peso($totals['tips']) ?></td>
              <td class="pr-num"><?= peso($totals['gross']) ?></td>
              <td class="pr-num" style="color:var(--red)">&minus;<?= peso($totals['deductions']) ?></td>
              <td class="pr-num" style="color:var(--caramel)"><?= peso($totals['net']) ?></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- Approve modal -->
<div class="modal-bg" id="approve-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <h3><?= icon('shield', 18) ?> <span>Approve Payroll</span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13.5px;line-height:1.6;color:var(--text-main)">
        Approving locks the calculation for
        <strong><?= count($slips) ?></strong> employee<?= count($slips) === 1 ? '' : 's' ?>,
        totalling <strong><?= peso($totals['net']) ?></strong> net payout.
        Recalculations will be locked after approval.
      </p>
      <?php if ($exceptions): ?>
      <div class="pr-alert pr-alert-warn" style="margin-top:14px">
        <span class="pr-alert-icon"><?= icon('alert-triangle', 17) ?></span>
        <div><strong><?= count($exceptions) ?> unresolved exception<?= count($exceptions) === 1 ? '' : 's' ?></strong>
             Your managerial approval will be logged against them.</div>
      </div>
      <?php endif; ?>
      <div class="field" style="margin-top:14px">
        <label for="approve-note">Approval Note <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
        <input type="text" id="approve-note" maxlength="300" placeholder="e.g. Exceptions reviewed with HR">
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-ghost" onclick="closeModals()"><?= icon('x', 14) ?> <span>Cancel</span></button>
      <button type="button" class="btn-add"
              onclick="runAction('approve', null, { note: document.getElementById('approve-note').value })">
        <?= icon('check-circle', 15) ?> <span>Approve Payroll</span>
      </button>
    </div>
  </div>
</div>

<!-- Release modal -->
<div class="modal-bg" id="release-modal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <h3><?= icon('credit-card', 18) ?> <span>Release Payment</span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13.5px;line-height:1.6;color:var(--text-main)">
        This marks every payslip as paid, automatically updates active loan amortization balances, and archives the period permanently.
      </p>
      <div class="pr-netbox" style="margin-top:16px">
        <span class="pr-netbox-label">Total Payout Amount</span>
        <span class="pr-netbox-value"><?= peso($totals['net']) ?></span>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:14px;line-height:1.55">
        Ensure bank transfer batch or cash disbursements have been prepared. This confirms system records and releases payslips to employee portals.
      </p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-ghost" onclick="closeModals()"><?= icon('x', 14) ?> <span>Cancel</span></button>
      <button type="button" class="btn-add" onclick="runAction('release')">
        <?= icon('credit-card', 15) ?> <span>Confirm Release</span>
      </button>
    </div>
  </div>
</div>

<!-- Adjustments modal -->
<div class="modal-bg" id="adjust-modal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h3><?= icon('file-text', 18) ?> <span>Adjustments &mdash; <span id="adj-name" style="font-weight:600"></span></span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <div class="pr-adj-row">
        <div class="field" style="margin-bottom:0">
          <label for="adj-kind">Type</label>
          <select id="adj-kind">
            <option value="allowance">Allowance</option>
            <option value="bonus">Bonus</option>
            <option value="reimbursement">Reimbursement</option>
            <option value="deduction">Deduction</option>
          </select>
        </div>
        <div class="field" style="margin-bottom:0">
          <label for="adj-label">Description</label>
          <input type="text" id="adj-label" maxlength="120" placeholder="e.g. Transport allowance">
        </div>
        <div class="field" style="margin-bottom:0">
          <label for="adj-amount">Amount (&#8369;)</label>
          <input type="number" id="adj-amount" step="0.01" min="0" placeholder="0.00">
        </div>
        <button type="button" class="btn-add" style="height:38px;padding:0 14px" onclick="addAdjustment()">
          <?= icon('plus', 14) ?> <span>Add</span>
        </button>
      </div>

      <div class="pr-adj-list" id="adj-list" style="margin-top:16px">
        <p style="font-size:12.5px;color:var(--text-muted)">Loading&hellip;</p>
      </div>

      <p style="font-size:12px;color:var(--text-muted);margin-top:14px;line-height:1.55">
        Adjustments survive recalculations. Allowances and bonuses will fold into gross pay before statutory formulas and tax calculations are applied.
      </p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-add" onclick="location.reload()">
        <?= icon('check', 14) ?> <span>Done</span>
      </button>
    </div>
  </div>
</div>

<?php if ($toast): ?>
<div id="toast" class="toast <?= $toast_type ?>"><?= $toast ?></div>
<script>setTimeout(() => document.getElementById('toast')?.remove(), 4500);</script>
<?php endif; ?>

<script>
const PERIOD_ID = <?= (int)$period_id ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let currentPayslipId = null;

function closeModals() {
  document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open'));
}
function openApprove() { document.getElementById('approve-modal').classList.add('open'); }
function openRelease() { document.getElementById('release-modal').classList.add('open'); }

document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) closeModals(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

async function post(payload) {
  const res = await fetch('../api/payroll.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!res.ok || !data.ok) throw new Error(data.error || 'Action failed.');
  return data;
}

async function runAction(action, confirmText, extra = {}) {
  if (confirmText) {
    const res = await Swal.fire({
      title: 'Confirm Action',
      text: confirmText,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#8B4513',
      cancelButtonColor: '#52434F',
      confirmButtonText: 'Yes, proceed'
    });
    if (!res.isConfirmed) return;
  }

  document.querySelectorAll('button').forEach(b => b.disabled = true);
  Swal.fire({
    title: 'Processing...',
    text: 'Please wait while payroll records are updated.',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const data = await post({ action, period_id: PERIOD_ID, ...extra });
    await Swal.fire({
      icon: 'success',
      title: 'Success',
      text: data.message || 'Operation completed.',
      timer: 1500,
      showConfirmButton: false
    });
    location.reload();
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'Action Failed', text: e.message });
    document.querySelectorAll('button').forEach(b => b.disabled = false);
  }
}

async function confirmDeletePeriod() {
  const result = await Swal.fire({
    title: 'Delete Pay Period?',
    text: 'This will permanently remove this pay period and all calculated payslips. This action cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#c62828',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, delete it'
  });

  if (!result.isConfirmed) return;

  try {
    await post({ action: 'delete_period', period_id: PERIOD_ID });
    await Swal.fire({
      icon: 'success',
      title: 'Deleted',
      text: 'Pay period has been deleted.',
      timer: 1500,
      showConfirmButton: false
    });
    window.location.href = 'payroll.php';
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'Error', text: e.message });
  }
}

// ── Adjustments ──
async function openAdjust(payslipId, name) {
  currentPayslipId = payslipId;
  document.getElementById('adj-name').textContent = name;
  document.getElementById('adjust-modal').classList.add('open');
  await loadAdjustments();
}

async function loadAdjustments() {
  const list = document.getElementById('adj-list');
  list.innerHTML = '<p style="font-size:12.5px;color:var(--text-muted)">Loading&hellip;</p>';

  try {
    const data = await post({ action: 'list_adjustments', payslip_id: currentPayslipId });

    if (!data.adjustments.length) {
      list.innerHTML = '<p style="font-size:12.5px;color:var(--text-muted)">No adjustments on this payslip yet.</p>';
      return;
    }

    list.innerHTML = data.adjustments.map(a => `
      <div class="pr-adj-item">
        <span>
          <strong style="text-transform:capitalize">${esc(a.kind)}</strong> &middot; ${esc(a.label)}
        </span>
        <span style="display:flex;align-items:center;gap:10px">
          <span style="font-variant-numeric:tabular-nums;font-weight:700;
                       color:${a.kind === 'deduction' ? 'var(--red)' : 'var(--green)'}">
            ${a.kind === 'deduction' ? '&minus;' : '+'}&#8369;${Number(a.amount).toFixed(2)}
          </span>
          <button type="button" class="pr-adj-del" style="display:inline-flex;align-items:center;gap:4px" onclick="removeAdjustment(${a.id})">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <span>Remove</span>
          </button>
        </span>
      </div>`).join('');
  } catch (e) {
    list.innerHTML = `<p style="font-size:12.5px;color:var(--red)">${esc(e.message)}</p>`;
  }
}

async function addAdjustment() {
  const kind   = document.getElementById('adj-kind').value;
  const label  = document.getElementById('adj-label').value.trim();
  const amount = parseFloat(document.getElementById('adj-amount').value);

  if (!label)                      return Swal.fire({ icon: 'info', title: 'Missing Info', text: 'Enter a description.' });
  if (!amount || amount <= 0)      return Swal.fire({ icon: 'info', title: 'Invalid Amount', text: 'Enter an amount greater than zero.' });

  try {
    await post({ action: 'add_adjustment', payslip_id: currentPayslipId, kind, label, amount });
    document.getElementById('adj-label').value  = '';
    document.getElementById('adj-amount').value = '';
    await loadAdjustments();
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'Error', text: e.message });
  }
}

async function removeAdjustment(id) {
  try {
    await post({ action: 'remove_adjustment', adjustment_id: id });
    await loadAdjustments();
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'Error', text: e.message });
  }
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
</script>
</body>
</html>