<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();
require_permission('payroll.view');

send_security_headers();

$pdo        = get_db();
$user       = current_user();
$can_manage = has_permission('payroll.manage');

$toast      = isset($_GET['toast']) ? e($_GET['toast']) : '';
$toast_type = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';

// ── Filters ───────────────────────────────────
$filter_status = $_GET['status'] ?? 'all';
$filter_year   = (int)($_GET['year'] ?? date('Y'));

$where  = 'YEAR(period_start) = :y';
$params = [':y' => $filter_year];
if ($filter_status !== 'all') {
    $where .= ' AND status = :st';
    $params[':st'] = $filter_status;
}

$stmt = $pdo->prepare(
    "SELECT p.*,
            (SELECT COUNT(*) FROM payslips s WHERE s.period_id = p.id AND s.has_exception = 1) AS exceptions
       FROM payroll_periods p
      WHERE $where
      ORDER BY period_start DESC"
);
$stmt->execute($params);
$periods = $stmt->fetchAll();

$years = $pdo->query(
    'SELECT DISTINCT YEAR(period_start) AS y FROM payroll_periods ORDER BY y DESC'
)->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), array_map('intval', $years), true)) {
    array_unshift($years, (int)date('Y'));
}

// ── Metrics ───────────────────────────────────
$active_employees = (int)$pdo->query(
    "SELECT COUNT(*) FROM employees WHERE status = 'active'"
)->fetchColumn();

$pending = (int)$pdo->query(
    "SELECT COUNT(*) FROM payroll_periods WHERE status IN ('draft','calculated','approved')"
)->fetchColumn();

$ytd = $pdo->prepare(
    "SELECT COALESCE(SUM(net_total),0) FROM payroll_periods
      WHERE status = 'paid' AND YEAR(pay_date) = :y"
);
$ytd->execute([':y' => $filter_year]);
$ytd_paid = (float)$ytd->fetchColumn();

$next = $pdo->query(
    "SELECT label, pay_date FROM payroll_periods
      WHERE status IN ('calculated','approved') AND pay_date >= CURDATE()
      ORDER BY pay_date LIMIT 1"
)->fetch();

// Labour cost vs sales for the current month.
$labour = $pdo->query(
    "SELECT COALESCE(SUM(gross_total),0) FROM payroll_periods
      WHERE status IN ('approved','paid')
        AND MONTH(period_end) = MONTH(CURDATE())
        AND YEAR(period_end)  = YEAR(CURDATE())"
)->fetchColumn();

$sales = $pdo->query(
    "SELECT COALESCE(SUM(total_amount),0) FROM orders
      WHERE status = 'completed'
        AND MONTH(placed_at) = MONTH(CURDATE())
        AND YEAR(placed_at)  = YEAR(CURDATE())"
)->fetchColumn();

$labour_pct = $sales > 0 ? ((float)$labour / (float)$sales) * 100 : 0;

$suggested = suggest_payroll_period('semimonthly');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<?= csrf_meta() ?>
<title>Payroll — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-payroll" class="page active">
  <div class="page-header">
    <div>
      <h1>Payroll</h1>
      <p>Pay periods, calculations, and release</p>
    </div>
    <?php if ($can_manage): ?>
    <button class="btn-add" onclick="openPeriodModal()">
      <?= icon('plus', 16) ?> <span>New Pay Period</span>
    </button>
    <?php endif; ?>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('runs') ?>

    <!-- Metrics -->
    <div class="stat-row">
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--blue-lt);color:var(--blue)"><?= icon('users', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $active_employees ?></div>
          <div class="mini-stat-lbl">Active Employees</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--amber-lt);color:var(--amber)"><?= icon('clock', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $pending ?></div>
          <div class="mini-stat-lbl">Periods In Progress</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--green-lt);color:var(--green)"><?= icon('coin', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= peso($ytd_paid) ?></div>
          <div class="mini-stat-lbl">Net Released (<?= $filter_year ?>)</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--accent-lt);color:var(--caramel)"><?= icon('scale', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= number_format($labour_pct, 1) ?>%</div>
          <div class="mini-stat-lbl">Labour Cost vs Sales</div>
        </div>
      </div>
    </div>

    <?php if ($next): ?>
    <div class="pr-alert pr-alert-info" style="margin-top:16px">
      <span class="pr-alert-icon"><?= icon('calendar', 18) ?></span>
      <div>
        <strong>Next payout: <?= e($next['label']) ?></strong>
        Scheduled for <?= e(date('F j, Y', strtotime($next['pay_date']))) ?>.
      </div>
    </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="pr-toolbar" style="margin-top:16px">
      <form method="GET" class="pr-toolbar-group">
        <label for="f-year">Year</label>
        <select id="f-year" name="year" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= $filter_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>

        <label for="f-status">Status</label>
        <select id="f-status" name="status" onchange="this.form.submit()">
          <?php foreach ([
              'all' => 'All', 'draft' => 'Draft', 'calculated' => 'Calculated',
              'approved' => 'Approved', 'paid' => 'Paid', 'locked' => 'Locked',
          ] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <div class="pr-toolbar-group">
        <a class="btn-ghost" href="payroll_reports.php"><?= icon('bar-chart', 15) ?> <span>Reports</span></a>
        <?php if (has_permission('payroll.settings')): ?>
        <a class="btn-ghost" href="payroll_settings.php"><?= icon('permissions', 15) ?> <span>Settings</span></a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Periods -->
    <div class="table-card" style="margin-top:16px">
      <div class="table-scroll-wrapper">
        <table class="pr-table">
          <thead>
            <tr>
              <th>Pay Period</th>
              <th>Frequency</th>
              <th>Pay Date</th>
              <th>Status</th>
              <th class="pr-num">Staff</th>
              <th class="pr-num">Gross</th>
              <th class="pr-num">Deductions</th>
              <th class="pr-num">Net</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$periods): ?>
            <tr><td colspan="9">
              <div class="pr-empty">
                <div class="pr-empty-icon"><?= icon('file-text', 22) ?></div>
                <h3>No pay periods yet</h3>
                <p>Create a period to pull in attendance, POS sales, tips and
                   deductions, then review the register before releasing payment.</p>
              </div>
            </td></tr>
          <?php else: foreach ($periods as $p): ?>
            <tr>
              <td>
                <div class="pr-emp-name"><?= e($p['label']) ?></div>
                <div class="pr-emp-meta">
                  <?= e(date('M j', strtotime($p['period_start']))) ?> &ndash;
                  <?= e(date('M j, Y', strtotime($p['period_end']))) ?>
                  <?= $p['branch'] ? ' &middot; ' . e($p['branch']) : '' ?>
                </div>
              </td>
              <td style="text-transform:capitalize"><?= e($p['frequency']) ?></td>
              <td><?= e(date('M j, Y', strtotime($p['pay_date']))) ?></td>
              <td>
                <?= period_status_badge($p['status'], (int)$p['exceptions']) ?>
              </td>
              <td class="pr-num"><?= (int)$p['headcount'] ?></td>
              <td class="pr-num"><?= peso((float)$p['gross_total']) ?></td>
              <td class="pr-num"><?= peso((float)$p['deduction_total']) ?></td>
              <td class="pr-num pr-net"><?= peso((float)$p['net_total']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-ghost" href="payroll_run.php?id=<?= (int)$p['id'] ?>">
                  <?= icon('eye', 15) ?> <span>Open</span>
                </a>
                <?php if ($can_manage && in_array($p['status'], ['draft', 'calculated'], true)): ?>
                <button type="button" class="btn-ghost" style="color:var(--red);padding:6px 9px" title="Delete Period"
                        onclick="deletePeriod(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($p['label']), ENT_QUOTES, 'UTF-8') ?>)">
                  <?= icon('trash', 14) ?>
                </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php if ($can_manage): ?>
<!-- New period modal -->
<div class="modal-bg" id="period-modal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3><?= icon('plus', 18) ?> <span>New Pay Period</span></h3>
      <button type="button" class="modal-close" onclick="closePeriodModal()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <form id="create-period-form" onsubmit="createPeriod(event)">
      <div class="modal-body">
        <div class="field">
          <label for="p-freq">Frequency</label>
          <select id="p-freq" name="frequency" onchange="suggestDates()">
            <option value="semimonthly" selected>Semimonthly (1st&ndash;15th, 16th&ndash;EOM)</option>
            <option value="weekly">Weekly</option>
            <option value="biweekly">Biweekly</option>
            <option value="monthly">Monthly</option>
          </select>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="p-start">Period start</label>
            <input type="date" id="p-start" name="period_start" required
                   value="<?= e($suggested['start']) ?>">
          </div>
          <div class="field">
            <label for="p-end">Period end</label>
            <input type="date" id="p-end" name="period_end" required
                   value="<?= e($suggested['end']) ?>">
          </div>
        </div>

        <div class="field">
          <label for="p-pay">Pay date</label>
          <input type="date" id="p-pay" name="pay_date" required value="<?= e($suggested['pay_date']) ?>">
        </div>

        <div class="field">
          <label for="p-branch">Branch <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
          <input type="text" id="p-branch" name="branch" placeholder="Leave blank for all branches">
        </div>

        <p style="font-size:12px;color:var(--text-muted);line-height:1.55;margin-top:6px">
          Creating a period opens the calculation register. You can inspect hours, adjustments, and exceptions before approving and releasing payment.
        </p>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="closePeriodModal()"><?= icon('x', 14) ?> <span>Cancel</span></button>
        <button type="submit" class="btn-add" id="btn-create-period"><?= icon('plus', 15) ?> <span>Create Period</span></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($toast): ?>
<div id="toast" class="toast <?= $toast_type ?>"><?= $toast ?></div>
<script>setTimeout(() => document.getElementById('toast')?.remove(), 4500);</script>
<?php endif; ?>

<script>
function openPeriodModal()  { document.getElementById('period-modal').classList.add('open'); }
function closePeriodModal() { document.getElementById('period-modal').classList.remove('open'); }

document.getElementById('period-modal')?.addEventListener('click', e => {
  if (e.target.id === 'period-modal') closePeriodModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closePeriodModal();
});

async function createPeriod(e) {
  e.preventDefault();
  const form = e.target;
  const submitBtn = document.getElementById('btn-create-period');
  const originalHtml = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span>Creating...</span>';

  const formData = new FormData(form);
  const payload = { action: 'create_period' };
  formData.forEach((val, key) => payload[key] = val);

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': CSRF
      },
      body: JSON.stringify(payload)
    });

    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Failed to create pay period.');
    }

    closePeriodModal();
    Swal.fire({
      icon: 'success',
      title: 'Pay Period Created',
      text: data.message || 'Opening pay period workspace…',
      timer: 1400,
      showConfirmButton: false
    }).then(() => {
      window.location.href = 'payroll_run.php?id=' + data.period_id;
    });
  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Error Creating Period',
      text: err.message
    });
  } finally {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalHtml;
  }
}

// Keep the suggested dates in step with the chosen frequency.
function suggestDates() {
  const freq  = document.getElementById('p-freq').value;
  const today = new Date();
  let start, end;

  if (freq === 'weekly') {
    const day = (today.getDay() + 6) % 7;
    start = new Date(today); start.setDate(today.getDate() - day);
    end   = new Date(start); end.setDate(start.getDate() + 6);
  } else if (freq === 'biweekly') {
    const day = (today.getDay() + 6) % 7;
    start = new Date(today); start.setDate(today.getDate() - day - 7);
    end   = new Date(start); end.setDate(start.getDate() + 13);
  } else if (freq === 'monthly') {
    start = new Date(today.getFullYear(), today.getMonth(), 1);
    end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
  } else {
    if (today.getDate() <= 15) {
      start = new Date(today.getFullYear(), today.getMonth(), 1);
      end   = new Date(today.getFullYear(), today.getMonth(), 15);
    } else {
      start = new Date(today.getFullYear(), today.getMonth(), 16);
      end   = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    }
  }

  const pay = new Date(end); pay.setDate(end.getDate() + 3);
  const iso = d => d.toISOString().slice(0, 10);

  document.getElementById('p-start').value = iso(start);
  document.getElementById('p-end').value   = iso(end);
  document.getElementById('p-pay').value   = iso(pay);
}

const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function deletePeriod(periodId, label) {
  const result = await Swal.fire({
    title: 'Delete Pay Period?',
    text: `Are you sure you want to delete "${label}"? This will remove all provisional payslips in this period.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#c62828',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, delete it'
  });

  if (!result.isConfirmed) return;

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ action: 'delete_period', period_id: periodId })
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to delete period.');

    Swal.fire({
      icon: 'success',
      title: 'Deleted',
      text: 'Pay period deleted successfully.',
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