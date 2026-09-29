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
    "SELECT COUNT(*) FROM payroll_periods WHERE status IN ('draft','calculated','pending_finance','approved','partially_paid')"
)->fetchColumn();

$ytd = $pdo->prepare(
    "SELECT COALESCE(SUM(net_total),0) FROM payroll_periods
      WHERE status = 'paid' AND YEAR(pay_date) = :y"
);
$ytd->execute([':y' => $filter_year]);
$ytd_paid = (float)$ytd->fetchColumn();

$next = $pdo->query(
    "SELECT id, label, pay_date, status, headcount, net_total, branch FROM payroll_periods
      WHERE status IN ('draft','calculated','pending_finance','approved','partially_paid')
      ORDER BY (status = 'approved') DESC, (status = 'partially_paid') DESC, (status = 'pending_finance') DESC, (status = 'calculated') DESC, pay_date ASC LIMIT 1"
)->fetch();

$active_branches = [];
try {
    $active_branches = $pdo->query("SELECT id, name, code FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {}

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
    <button class="btn-add" onclick="openPayrollModal()">
      <?= icon('plus', 16) ?> <span>New Pay Period</span>
    </button>
    <?php endif; ?>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('runs') ?>

    <!-- Next Scheduled Payout Hero Action Banner -->
    <?php if ($next): ?>
    <div class="pr-hero-payout">
      <div class="pr-hero-left">
        <div class="pr-cal-block">
          <span class="pr-cal-mo"><?= e(date('M', strtotime($next['pay_date']))) ?></span>
          <span class="pr-cal-day"><?= e(date('j', strtotime($next['pay_date']))) ?></span>
        </div>
        <div class="pr-hero-details">
          <h4>
            <span>Next Payout: <?= e($next['label']) ?></span>
            <?= period_status_badge($next['status']) ?>
          </h4>
          <p>
            Scheduled for <strong><?= e(date('F j, Y', strtotime($next['pay_date']))) ?></strong>
            &middot; <?= $next['branch'] ? e($next['branch']) : 'All Enrolled Staff' ?>
            &middot; Payout: <strong><?= peso((float)$next['net_total']) ?></strong> (<?= (int)$next['headcount'] ?> staff enrolled)
          </p>
        </div>
      </div>
      <div class="pr-hero-right">
        <?php if ($next['status'] === 'pending_finance'): ?>
          <a href="payroll_run.php?id=<?= (int)$next['id'] ?>" class="btn-act-approve">
            <?= icon('shield', 15) ?> <span>Finance Review</span>
          </a>
        <?php elseif ($next['status'] === 'approved'): ?>
          <a href="payroll_run.php?id=<?= (int)$next['id'] ?>" class="btn-act-release">
            <?= icon('credit-card', 15) ?> <span>Release Payout Now</span>
          </a>
        <?php elseif ($next['status'] === 'partially_paid'): ?>
          <a href="payroll_run.php?id=<?= (int)$next['id'] ?>" class="btn-act-release">
            <?= icon('credit-card', 15) ?> <span>Resume Release</span>
          </a>
        <?php elseif ($next['status'] === 'calculated'): ?>
          <a href="payroll_run.php?id=<?= (int)$next['id'] ?>" class="btn-act-approve">
            <?= icon('check-circle', 15) ?> <span>Review &amp; Approve</span>
          </a>
        <?php else: ?>
          <a href="payroll_run.php?id=<?= (int)$next['id'] ?>" class="btn-act-calculate">
            <?= icon('refresh', 15) ?> <span>Resume Pay Run</span>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Modern Metric KPI Cards -->
    <div class="stat-row-modern">
      <div class="pr-stat-card">
        <div class="pr-stat-top">
          <div class="pr-stat-icon-wrap" style="background:#EBF5FF;color:#2563EB">
            <?= icon('users', 20) ?>
          </div>
          <span style="font-size:11px;font-weight:700;color:#2563EB;background:#EFF6FF;padding:2px 8px;border-radius:12px">ACTIVE ROSTER</span>
        </div>
        <div class="pr-stat-val-group">
          <div class="pr-stat-number"><?= $active_employees ?></div>
          <div class="pr-stat-title">Employees on Active Payroll</div>
        </div>
      </div>

      <div class="pr-stat-card">
        <div class="pr-stat-top">
          <div class="pr-stat-icon-wrap" style="background:#FEF3C7;color:#D97706">
            <?= icon('clock', 20) ?>
          </div>
          <span style="font-size:11px;font-weight:700;color:#D97706;background:#FFFBEB;padding:2px 8px;border-radius:12px">IN PROGRESS</span>
        </div>
        <div class="pr-stat-val-group">
          <div class="pr-stat-number"><?= $pending ?></div>
          <div class="pr-stat-title">Periods Pending Review or Release</div>
        </div>
      </div>

      <div class="pr-stat-card">
        <div class="pr-stat-top">
          <div class="pr-stat-icon-wrap" style="background:#DCFCE7;color:#15803D">
            <?= icon('coin', 20) ?>
          </div>
          <span style="font-size:11px;font-weight:700;color:#15803D;background:#F0FDF4;padding:2px 8px;border-radius:12px"><?= $filter_year ?> YTD</span>
        </div>
        <div class="pr-stat-val-group">
          <div class="pr-stat-number"><?= peso($ytd_paid) ?></div>
          <div class="pr-stat-title">Net Take-Home Salary Released</div>
        </div>
      </div>

      <div class="pr-stat-card">
        <div class="pr-stat-top">
          <div class="pr-stat-icon-wrap" style="background:#FAF5EE;color:var(--caramel)">
            <?= icon('scale', 20) ?>
          </div>
          <span style="font-size:11px;font-weight:700;color:<?= $labour_pct <= 30 ? '#15803D' : ($labour_pct <= 40 ? '#D97706' : '#B91C1C') ?>;background:<?= $labour_pct <= 30 ? '#DCFCE7' : ($labour_pct <= 40 ? '#FEF3C7' : '#FEE2E2') ?>;padding:2px 8px;border-radius:12px">
            <?= $labour_pct <= 30 ? 'Healthy (&lt;30%)' : ($labour_pct <= 40 ? 'Moderate (30-40%)' : 'High Alert (&gt;40%)') ?>
          </span>
        </div>
        <div class="pr-stat-val-group">
          <div class="pr-stat-number"><?= number_format($labour_pct, 1) ?>%</div>
          <div class="pr-stat-title">Labor Cost vs Sales (Current Month)</div>
          <div class="pr-meter-bar">
            <div class="pr-meter-fill" style="width:<?= min(100, $labour_pct) ?>%;background:<?= $labour_pct <= 30 ? '#10B981' : ($labour_pct <= 40 ? '#F59E0B' : '#EF4444') ?>"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modern Interactive Filter & Search Bar -->
    <div class="pr-control-bar">
      <!-- Quick Status Filter Pills -->
      <div class="pr-filter-pills">
        <a href="payroll.php?year=<?= $filter_year ?>" class="pr-pill <?= $filter_status === 'all' ? 'active' : '' ?>">All Runs (<?= count($periods) ?>)</a>
        <a href="payroll.php?year=<?= $filter_year ?>&status=draft" class="pr-pill <?= $filter_status === 'draft' ? 'active' : '' ?>">Drafts</a>
        <a href="payroll.php?year=<?= $filter_year ?>&status=calculated" class="pr-pill <?= $filter_status === 'calculated' ? 'active' : '' ?>">Calculated</a>
        <a href="payroll.php?year=<?= $filter_year ?>&status=pending_finance" class="pr-pill <?= $filter_status === 'pending_finance' ? 'active' : '' ?>">Finance Review</a>
        <a href="payroll.php?year=<?= $filter_year ?>&status=approved" class="pr-pill <?= $filter_status === 'approved' ? 'active' : '' ?>">Approved</a>
        <a href="payroll.php?year=<?= $filter_year ?>&status=paid" class="pr-pill <?= $filter_status === 'paid' ? 'active' : '' ?>">Released</a>
      </div>

      <!-- Real-time Quick Search Box -->
      <div class="pr-search-wrap">
        <span class="pr-search-icon"><?= icon('history', 15) ?></span>
        <input type="text" id="period-search" class="pr-search-input" placeholder="Search periods, dates, branch..." oninput="filterPeriodsTable()">
      </div>

      <!-- Year Selector & Module Links -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <form method="GET" style="display:inline-flex;align-items:center;gap:6px;margin:0">
          <input type="hidden" name="status" value="<?= e($filter_status) ?>"/>
          <select name="year" onchange="this.form.submit()" style="padding:6px 12px;border-radius:10px;border:1.5px solid var(--border,#e8ded2);font-weight:700;font-size:12.5px;color:var(--espresso);background:#FAF8F5;cursor:pointer">
            <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y ?>" <?= $filter_year === (int)$y ? 'selected' : '' ?>>Year <?= (int)$y ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <a class="btn-ghost" href="payroll_reports.php" style="padding:7px 12px;font-size:12.5px"><?= icon('bar-chart', 14) ?> <span>Reports</span></a>
        <?php if (has_permission('payroll.settings')): ?>
        <a class="btn-ghost" href="payroll_settings.php" style="padding:7px 12px;font-size:12.5px"><?= icon('permissions', 14) ?> <span>Settings &amp; Loans</span></a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Pay Periods Register Table -->
    <div class="pr-table-card">
      <div class="table-scroll-wrapper" style="margin:0">
        <table class="pr-table" id="periods-table">
          <thead>
            <tr>
              <th style="min-width:260px">Pay Period</th>
              <th>Frequency</th>
              <th>Pay Date</th>
              <th>Status</th>
              <th class="pr-num">Staff</th>
              <th class="pr-num">Gross Pay</th>
              <th class="pr-num">Deductions</th>
              <th class="pr-num">Net Payout</th>
              <th style="text-align:right;min-width:140px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$periods): ?>
            <tr id="empty-row"><td colspan="9">
              <div class="pr-empty">
                <div class="pr-empty-icon"><?= icon('file-text', 22) ?></div>
                <h3>No pay periods found</h3>
                <p>Create a period to pull in attendance, POS sales, tips and
                   deductions, then review the register before releasing payment.</p>
                <?php if ($can_manage): ?>
                <button type="button" class="btn-add" style="margin-top:14px" onclick="openPayrollModal()">
                  <?= icon('plus', 15) ?> <span>Create First Pay Period</span>
                </button>
                <?php endif; ?>
              </div>
            </td></tr>
          <?php else: foreach ($periods as $p): 
            $search_str = strtolower($p['label'] . ' ' . $p['frequency'] . ' ' . ($p['branch'] ?? '') . ' ' . $p['status'] . ' ' . date('M j Y', strtotime($p['pay_date'])));
          ?>
            <tr class="period-row" data-search-text="<?= e($search_str) ?>" style="cursor:pointer" onclick="handlePeriodRowClick(event, 'payroll_run.php?id=<?= (int)$p['id'] ?>')">
              <td>
                <div class="pr-period-cell">
                  <div class="pr-period-cal">
                    <span class="pr-period-cal-mo"><?= e(date('M', strtotime($p['period_start']))) ?></span>
                    <span class="pr-period-cal-day"><?= e(date('j', strtotime($p['period_start']))) ?></span>
                  </div>
                  <div class="pr-period-info">
                    <div class="pr-period-title">
                      <a href="payroll_run.php?id=<?= (int)$p['id'] ?>" style="color:inherit;text-decoration:none">
                        <?= e($p['label']) ?>
                      </a>
                    </div>
                    <div class="pr-period-sub">
                      <?= e(date('M j', strtotime($p['period_start']))) ?> &ndash;
                      <?= e(date('M j, Y', strtotime($p['period_end']))) ?>
                      <?php if (!empty($p['branch'])): ?>
                        &middot; <span style="font-weight:600;color:var(--espresso)"><?= e($p['branch']) ?></span>
                      <?php else: ?>
                        &middot; <span style="color:var(--text-muted)">All Enrolled Staff</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>
              <td style="text-transform:capitalize;font-weight:600;color:var(--text-muted)"><?= e($p['frequency']) ?></td>
              <td style="font-weight:600"><?= e(date('M j, Y', strtotime($p['pay_date']))) ?></td>
              <td>
                <?= period_status_badge($p['status'], (int)$p['exceptions']) ?>
              </td>
              <td class="pr-num" style="font-weight:700"><?= (int)$p['headcount'] ?></td>
              <td class="pr-num"><?= peso((float)$p['gross_total']) ?></td>
              <td class="pr-num" style="color:var(--red)">&minus;<?= peso((float)$p['deduction_total']) ?></td>
              <td class="pr-num pr-net" style="font-size:14.5px"><?= peso((float)$p['net_total']) ?></td>
              <td style="text-align:right;white-space:nowrap">
                <?php if ($p['status'] === 'pending_finance'): ?>
                  <a class="btn-act-approve" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="Under Review by Finance">
                    <?= icon('shield', 13) ?> <span>Finance Review</span>
                  </a>
                <?php elseif ($p['status'] === 'approved'): ?>
                  <a class="btn-act-release" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="Disburse payment to employees">
                    <?= icon('credit-card', 13) ?> <span>Release Pay</span>
                  </a>
                <?php elseif ($p['status'] === 'partially_paid'): ?>
                  <a class="btn-act-release" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="Resume remaining employee releases">
                    <?= icon('credit-card', 13) ?> <span>Resume Release</span>
                  </a>
                <?php elseif ($p['status'] === 'calculated'): ?>
                  <a class="btn-act-approve" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="Review register and approve">
                    <?= icon('check-circle', 13) ?> <span>Review</span>
                  </a>
                <?php elseif ($p['status'] === 'draft'): ?>
                  <a class="btn-act-calculate" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="Open and calculate pay period">
                    <?= icon('refresh', 13) ?> <span>Calculate</span>
                  </a>
                <?php else: ?>
                  <a class="btn-ghost" href="payroll_run.php?id=<?= (int)$p['id'] ?>" title="View finalized register">
                    <?= icon('eye', 13) ?> <span>Open</span>
                  </a>
                <?php endif; ?>

                <?php if ($can_manage && !in_array($p['status'], ['paid', 'partially_paid'], true)): ?>
                <button type="button" class="btn-ghost" style="color:var(--red);padding:6px 8px;margin-left:4px" title="Delete Period"
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
<!-- Progressive Multi-Step "Create Payroll Run" Modal (Warm Cafe Theme) -->
<div id="progressive-payroll-modal" onclick="onPayrollBackdropClick(event)" class="prog-modal-overlay pointer-events-none opacity-0" style="display:none">
  <div id="progressive-payroll-dialog" class="prog-modal-dialog" style="max-width:980px;width:95vw;max-height:92vh;display:flex;flex-direction:column">
    
    <!-- Modal Header -->
    <div class="prog-modal-header">
      <div class="prog-header-left">
        <div class="prog-badge">
          <?= icon('coin', 22) ?>
        </div>
        <div>
          <h3 id="pay-modal-title" class="prog-header-title">Process Payroll Run</h3>
          <p id="pay-modal-subtitle" class="prog-header-subtitle">Step 1: Input/Edit Amounts &middot; Step 2: Select Employees &middot; Step 3: Payment Method &amp; Finance Review</p>
        </div>
      </div>
      <button type="button" onclick="closePayrollModal()" class="prog-close-btn" title="Close (Esc)"><?= icon('x', 16) ?></button>
    </div>

    <!-- Responsive Visual Step Tracker -->
    <div class="prog-step-tracker">
      <div class="prog-steps-container">
        <div class="prog-steps-line">
          <div id="pay-step-progress-bar" class="prog-steps-progress" style="width:0%"></div>
        </div>

        <!-- Step 1 Indicator -->
        <div class="prog-step-item" onclick="goToPayStep(1)">
          <div id="pay-step-ind-1" class="prog-step-circle active">1</div>
          <span id="pay-step-lbl-1" class="prog-step-label active">1. Period &amp; Amounts</span>
        </div>

        <!-- Step 2 Indicator -->
        <div class="prog-step-item" onclick="goToPayStep(2)">
          <div id="pay-step-ind-2" class="prog-step-circle">2</div>
          <span id="pay-step-lbl-2" class="prog-step-label">2. Select Employees</span>
        </div>

        <!-- Step 3 Indicator -->
        <div class="prog-step-item" onclick="goToPayStep(3)">
          <div id="pay-step-ind-3" class="prog-step-circle">3</div>
          <span id="pay-step-lbl-3" class="prog-step-label">3. Payment &amp; Finance</span>
        </div>
      </div>
    </div>

    <!-- Modal Body: Step Panels -->
    <div class="prog-modal-body" style="padding:20px 24px;overflow-y:auto;flex:1">

      <!-- STEP 1: PERIOD & EMPLOYEE AMOUNTS INPUT/EDIT -->
      <div id="pay-step-panel-1" class="pay-step-panel">
        <div class="pay-modal-callout">
          <span style="font-size:18px"><?= icon('calendar', 18) ?></span>
          <div>
            <strong>Step 1: Cutoff &amp; Individual Salary Customization</strong><br>
            <span style="font-size:12px;color:var(--text-muted)">Basic pay is locked to clocked shift attendance. Deductions automatically default to Philippine statutory standards (SSS, PhilHealth, Pag-IBIG, BIR tax). You can adjust bonuses or add custom deductions per employee.</span>
          </div>
        </div>

        <!-- Quick Presets -->
        <div>
          <label style="font-size:11px;font-weight:800;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:6px">Quick Cutoff Presets</label>
          <div class="pr-preset-chips">
            <button type="button" class="pr-chip-btn" onclick="applyPayPreset('first_half')"><?= icon('calendar', 13) ?> Current 1st Half (1st–15th)</button>
            <button type="button" class="pr-chip-btn" onclick="applyPayPreset('second_half')"><?= icon('calendar', 13) ?> Current 2nd Half (16th–EOM)</button>
            <button type="button" class="pr-chip-btn" onclick="applyPayPreset('next_cutoff')"><?= icon('clock', 13) ?> Upcoming Cutoff</button>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px">
          <div class="prog-form-group">
            <label class="prog-label" for="pay-freq">Frequency</label>
            <select id="pay-freq" class="prog-select" onchange="suggestPayDates()">
              <option value="semimonthly" selected>Semimonthly (1st&ndash;15th, 16th&ndash;EOM)</option>
              <option value="weekly">Weekly</option>
              <option value="biweekly">Biweekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div class="prog-form-group">
            <label class="prog-label" for="pay-start">Start Date <span class="req">*</span></label>
            <input type="date" id="pay-start" class="prog-input" value="<?= e($suggested['start']) ?>" onchange="reloadRosterCalculations()">
          </div>
          <div class="prog-form-group">
            <label class="prog-label" for="pay-end">End Date <span class="req">*</span></label>
            <input type="date" id="pay-end" class="prog-input" value="<?= e($suggested['end']) ?>" onchange="reloadRosterCalculations()">
          </div>
          <div class="prog-form-group">
            <label class="prog-label" for="pay-date">Pay Date <span class="req">*</span></label>
            <input type="date" id="pay-date" class="prog-input" value="<?= e($suggested['pay_date']) ?>">
          </div>
        </div>

        <!-- Editable Employee Amounts Table -->
        <div style="margin-top:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
            <label class="prog-label" style="margin:0">
              Employee Payroll Amounts
              <span class="prog-label-sub">&mdash; basic pay is locked to clocked hours; deductions default to Philippine statutory standards</span>
            </label>
            <input type="text" id="pay-step1-search" placeholder="Filter staff in list…" oninput="filterStep1Table()"
                   style="padding:5px 10px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px;width:200px">
          </div>

          <div class="pay-emp-table-wrapper">
            <table class="pay-emp-table" id="pay-step1-table">
              <thead>
                <tr>
                  <th style="min-width:180px">Employee</th>
                  <th style="min-width:110px">Attendance</th>
                  <th style="min-width:115px;text-align:right">Basic Pay (₱)</th>
                  <th style="min-width:115px;text-align:right">Bonus / Allow (₱)</th>
                  <th style="min-width:130px;text-align:right">
                    Deductions (₱)
                    <div style="font-size:10px;font-weight:500;color:var(--text-muted)">PH Standard (SSS, PhilHealth, HDMF)</div>
                  </th>
                  <th style="min-width:130px;text-align:right">Amount to Release (₱)</th>
                </tr>
              </thead>
              <tbody id="pay-step1-tbody">
                <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Loading employee roster…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- STEP 2: SELECT EMPLOYEES -->
      <div id="pay-step-panel-2" class="pay-step-panel" style="display:none">
        <div class="pay-modal-callout">
          <span style="font-size:18px"><?= icon('users', 18) ?></span>
          <div>
            <strong>Step 2: Select Employees to Enrol</strong><br>
            <span style="font-size:12px;color:var(--text-muted)">Select the individual employees who will receive payroll in this payout run. Company-wide selection without branch restrictions.</span>
          </div>
        </div>

        <!-- Selection Toolbar -->
        <div class="pay-selection-toolbar">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn-ghost" style="padding:5px 10px;font-size:12px" onclick="selectAllEmployees(true)">Select All (<span id="step2-total-avail">0</span>)</button>
            <button type="button" class="btn-ghost" style="padding:5px 10px;font-size:12px" onclick="selectAllEmployees(false)">Deselect All</button>
            <button type="button" class="btn-ghost" style="padding:5px 10px;font-size:12px" onclick="selectPositiveNetOnly()">Select Only &gt; ₱0</button>
          </div>
          <input type="text" id="pay-step2-search" placeholder="Search employee name, ID, position…" oninput="filterStep2Grid()"
                 style="padding:6px 12px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px;width:240px">
        </div>

        <!-- Grid of Selection Cards -->
        <div class="pay-selection-grid" id="pay-step2-grid">
          <!-- Populated dynamically -->
        </div>

        <!-- Sticky Counter Summary -->
        <div class="pay-sticky-summary">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="background:#8B4513;color:#ffffff;border-radius:50%;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;font-weight:700">
              <span id="step2-badge-count">0</span>
            </span>
            <div>
              <strong style="font-size:13.5px;color:#1E1224"><span id="step2-count-text">0 employees</span> selected for this run</strong>
              <div style="font-size:11.5px;color:var(--text-muted)">Unselected staff will not be enrolled in this payout run.</div>
            </div>
          </div>
          <div style="text-align:right">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Combined Net Release</div>
            <div style="font-size:17px;font-weight:800;color:#8B4513">₱<span id="step2-total-payout">0.00</span></div>
          </div>
        </div>
      </div>

      <!-- STEP 3: PAYMENT METHOD SETUP & FINANCE REVIEW -->
      <div id="pay-step-panel-3" class="pay-step-panel" style="display:none">
        <div class="pay-modal-callout">
          <span style="font-size:18px"><?= icon('credit-card', 18) ?></span>
          <div>
            <strong>Step 3: Payment Method Setup &amp; Finance Review</strong><br>
            <span style="font-size:12px;color:var(--text-muted)">Set up or confirm how each employee will be paid (PayMongo automated bank transfer, GCash/Maya e-wallet, or Cash), then send for review to Finance.</span>
          </div>
        </div>

        <!-- Selected Employees Payment List -->
        <div>
          <label class="prog-label" style="margin-bottom:8px">Disbursement Channel per Selected Employee</label>
          <div class="pay-method-list" id="pay-step3-list">
            <!-- Populated dynamically for selected employees -->
          </div>
        </div>

        <!-- Payout Summary & Finance Notes -->
        <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px;margin-top:10px">
          <div style="background:#FAF8F5;border:1.5px solid #DFCBB5;border-radius:12px;padding:14px 16px">
            <h4 style="margin:0 0 10px 0;font-size:13.5px;color:#1E1224;display:flex;align-items:center;gap:6px">
              <?= icon('coin', 15) ?> <span>Payout Summary</span>
            </h4>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:12.5px">
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-muted)">Enrolled Staff:</span>
                <strong id="step3-sum-count">0</strong>
              </div>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-muted)">Pay Period:</span>
                <strong id="step3-sum-period">&hellip;</strong>
              </div>
              <div style="display:flex;justify-content:space-between">
                <span style="color:var(--text-muted)">Scheduled Pay Date:</span>
                <strong id="step3-sum-paydate">&hellip;</strong>
              </div>
              <div style="display:flex;justify-content:space-between;border-top:1px dashed #DFCBB5;padding-top:6px;margin-top:2px">
                <span style="color:var(--text-muted)">Channel Distribution:</span>
                <span id="step3-sum-channels" style="font-weight:600;font-size:11.5px;color:#5C2D0C">&hellip;</span>
              </div>
              <div style="display:flex;justify-content:space-between;border-top:1.5px solid #DFCBB5;padding-top:6px;margin-top:2px;font-size:14px">
                <strong>Total Net Payout:</strong>
                <strong style="color:#8B4513">₱<span id="step3-sum-total">0.00</span></strong>
              </div>
            </div>
          </div>

          <div class="prog-form-group">
            <label class="prog-label" for="pay-finance-notes">
              Notes for Finance / Accounting
              <span class="prog-label-sub">(optional)</span>
            </label>
            <textarea id="pay-finance-notes" class="prog-textarea" rows="4" placeholder="e.g. Regular semimonthly payroll cutoff for store staff, verified with biometric attendance. Ready for fund allocation."></textarea>
          </div>
        </div>
      </div>

    </div>

    <!-- Modal Footer Actions -->
    <div class="prog-modal-footer">
      <button type="button" class="prog-btn-cancel" onclick="closePayrollModal()">Cancel</button>
      
      <div style="display:flex;gap:10px;align-items:center">
        <button type="button" id="pay-btn-prev" class="prog-btn-back" style="display:none" onclick="prevPayStep()">
          <?= icon('arrow-left', 14) ?> <span>Back</span>
        </button>
        
        <button type="button" id="pay-btn-next" class="prog-btn-next" onclick="nextPayStep()">
          <span>Next: Select Employees</span> <?= icon('arrow-right', 14) ?>
        </button>

        <button type="button" id="pay-btn-submit" class="prog-btn-save" style="display:none" onclick="submitPayrollToFinance()">
          <?= icon('shield', 15) ?> <span>Send for Review to Finance</span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($toast): ?>
<div id="toast" class="toast <?= $toast_type ?>"><?= $toast ?></div>
<script>setTimeout(() => document.getElementById('toast')?.remove(), 4500);</script>
<?php endif; ?>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ── Multi-Step Payroll Modal State ──────────────
let currentPayStep = 1;
let rosterEmployees = [];
let customPayrollAmounts = {};  // employee_id -> { basic, bonus, deductions, net }
let selectedEmpIds = new Set();
let empPaymentMethods = {};     // employee_id -> { method, bank_name, last4, ewallet_prov, mobile }

function openPayrollModal() {
  const modal = document.getElementById('progressive-payroll-modal');
  if (!modal) return;
  modal.style.display = 'flex';
  requestAnimationFrame(() => {
    modal.classList.add('open');
    modal.classList.remove('pointer-events-none', 'opacity-0');
  });
  customPayrollAmounts = {};
  selectedEmpIds.clear();
  goToPayStep(1);
  reloadRosterCalculations();
}

function closePayrollModal() {
  const modal = document.getElementById('progressive-payroll-modal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.classList.add('pointer-events-none', 'opacity-0');
  setTimeout(() => {
    if (!modal.classList.contains('open')) {
      modal.style.display = 'none';
    }
  }, 220);
}

function handlePeriodRowClick(e, url) {
  if (e.target.closest('a, button, input, select, textarea')) return;
  window.location.href = url;
}

function onPayrollBackdropClick(e) {
  if (e.target.id === 'progressive-payroll-modal') {
    closePayrollModal();
  }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closePayrollModal();
});

function goToPayStep(step) {
  currentPayStep = step;

  // Update tracker circles & labels
  for (let i = 1; i <= 3; i++) {
    const circle = document.getElementById(`pay-step-ind-${i}`);
    const label  = document.getElementById(`pay-step-lbl-${i}`);
    const panel  = document.getElementById(`pay-step-panel-${i}`);

    if (panel) panel.style.display = (i === step) ? 'flex' : 'none';

    if (circle && label) {
      if (i < step) {
        circle.className = 'prog-step-circle done';
        circle.innerHTML = '&#10003;';
        label.className  = 'prog-step-label done';
      } else if (i === step) {
        circle.className = 'prog-step-circle active';
        circle.textContent = i;
        label.className  = 'prog-step-label active';
      } else {
        circle.className = 'prog-step-circle';
        circle.textContent = i;
        label.className  = 'prog-step-label';
      }
    }
  }

  // Update progress bar width
  const progressBar = document.getElementById('pay-step-progress-bar');
  if (progressBar) {
    progressBar.style.width = (step === 1 ? '0%' : (step === 2 ? '50%' : '100%'));
  }

  // Update footer buttons
  const prevBtn   = document.getElementById('pay-btn-prev');
  const nextBtn   = document.getElementById('pay-btn-next');
  const submitBtn = document.getElementById('pay-btn-submit');

  if (prevBtn) prevBtn.style.display = (step > 1) ? 'inline-flex' : 'none';

  if (step === 1) {
    if (nextBtn) {
      nextBtn.style.display = 'inline-flex';
      nextBtn.innerHTML = `<span>Next: Select Employees</span> <?= icon('arrow-right', 14) ?>`;
    }
    if (submitBtn) submitBtn.style.display = 'none';
  } else if (step === 2) {
    if (nextBtn) {
      nextBtn.style.display = 'inline-flex';
      nextBtn.innerHTML = `<span>Next: Payment &amp; Finance</span> <?= icon('arrow-right', 14) ?>`;
    }
    if (submitBtn) submitBtn.style.display = 'none';
    renderStep2Grid();
  } else if (step === 3) {
    if (nextBtn) nextBtn.style.display = 'none';
    if (submitBtn) submitBtn.style.display = 'inline-flex';
    renderStep3Setup();
  }
}

function nextPayStep() {
  if (currentPayStep === 1) {
    const start = document.getElementById('pay-start').value;
    const end   = document.getElementById('pay-end').value;
    const pay   = document.getElementById('pay-date').value;
    if (!start || !end || !pay) {
      Swal.fire({ icon: 'warning', title: 'Dates Required', text: 'Please specify the period start, end, and scheduled pay dates.' });
      return;
    }
    goToPayStep(2);
  } else if (currentPayStep === 2) {
    if (selectedEmpIds.size === 0) {
      Swal.fire({ icon: 'warning', title: 'No Employees Selected', text: 'Please select at least one employee for this payroll run.' });
      return;
    }
    goToPayStep(3);
  }
}

function prevPayStep() {
  if (currentPayStep > 1) {
    goToPayStep(currentPayStep - 1);
  }
}

// Quick Cutoff Presets
function applyPayPreset(preset) {
  const today = new Date();
  const y = today.getFullYear();
  const m = today.getMonth();
  const iso = d => d.toISOString().slice(0, 10);
  
  document.getElementById('pay-freq').value = 'semimonthly';
  let start, end, pay;

  if (preset === 'first_half') {
    start = new Date(y, m, 1);
    end   = new Date(y, m, 15);
    pay   = new Date(y, m, 18);
  } else if (preset === 'second_half') {
    start = new Date(y, m, 16);
    end   = new Date(y, m + 1, 0);
    pay   = new Date(y, m + 1, 3);
  } else if (preset === 'next_cutoff') {
    if (today.getDate() <= 15) {
      start = new Date(y, m, 16);
      end   = new Date(y, m + 1, 0);
      pay   = new Date(y, m + 1, 3);
    } else {
      start = new Date(y, m + 1, 1);
      end   = new Date(y, m + 1, 15);
      pay   = new Date(y, m + 1, 18);
    }
  }

  if (start && end && pay) {
    document.getElementById('pay-start').value = iso(start);
    document.getElementById('pay-end').value   = iso(end);
    document.getElementById('pay-date').value  = iso(pay);
    reloadRosterCalculations();
  }
}

function suggestPayDates() {
  const freq = document.getElementById('pay-freq').value;
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

  document.getElementById('pay-start').value = iso(start);
  document.getElementById('pay-end').value   = iso(end);
  document.getElementById('pay-date').value  = iso(pay);
  reloadRosterCalculations();
}

// Fetch employee roster with calculated payroll baseline
async function reloadRosterCalculations() {
  const tbody = document.getElementById('pay-step1-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">Loading employee payroll estimates…</td></tr>';

  const start = document.getElementById('pay-start').value;
  const end   = document.getElementById('pay-end').value;
  const freq  = document.getElementById('pay-freq')?.value || 'semimonthly';

  try {
    const res = await fetch(`../api/payroll.php?action=get_employee_roster_preview&period_start=${encodeURIComponent(start)}&period_end=${encodeURIComponent(end)}&frequency=${encodeURIComponent(freq)}`, {
      headers: { 'X-CSRF-Token': CSRF }
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to load roster.');

    rosterEmployees = data.employees || [];
    
    // Initialize custom amounts & payment methods with synced attendance baseline
    rosterEmployees.forEach(emp => {
      const eid = emp.id;
      const syncedBasic = Number(emp.basic_pay || 0);

      if (!customPayrollAmounts[eid]) {
        customPayrollAmounts[eid] = {
          basic: syncedBasic,
          bonus: Number(emp.bonus || 0),
          deductions: Number(emp.total_deductions || 0),
          net: Number(emp.net_pay || 0),
          userModifiedDeductions: false
        };
      } else {
        // Always refresh basic pay from newly aggregated shift clock-ins
        customPayrollAmounts[eid].basic = syncedBasic;
        if (!customPayrollAmounts[eid].userModifiedDeductions) {
          customPayrollAmounts[eid].deductions = Number(emp.total_deductions || 0);
        }
        const bon = Number(customPayrollAmounts[eid].bonus || 0);
        const ded = Number(customPayrollAmounts[eid].deductions || 0);
        customPayrollAmounts[eid].net = Math.max(0, Math.round((syncedBasic + bon - ded) * 100) / 100);
      }

      if (!empPaymentMethods[eid]) {
        empPaymentMethods[eid] = {
          method: emp.payment_method || 'cash',
          bank_name: emp.bank_name || '',
          bank_code: emp.bank_code || '',
          last4: emp.account_number_last4 || '',
          ewallet_prov: emp.ewallet_provider || 'gcash',
          mobile: emp.ewallet_mobile_number || ''
        };
      }
      // By default select all employees with net > 0
      if (!selectedEmpIds.size) {
        if (customPayrollAmounts[eid].net > 0) selectedEmpIds.add(eid);
      }
    });

    renderStep1Table();
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--red);padding:20px">${err.message}</td></tr>`;
  }
}

// Render Step 1 Table
function renderStep1Table() {
  const tbody = document.getElementById('pay-step1-tbody');
  if (!tbody) return;

  if (!rosterEmployees.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted)">No active employees found.</td></tr>';
    return;
  }

  tbody.innerHTML = rosterEmployees.map(emp => {
    const eid = emp.id;
    const amt = customPayrollAmounts[eid] || { basic: emp.basic_pay, bonus: 0, deductions: emp.total_deductions, net: emp.net_pay };
    const initials = (emp.firstname.charAt(0) + emp.lastname.charAt(0)).toUpperCase();

    const sss  = Number(emp.sss || 0);
    const phic = Number(emp.philhealth || 0);
    const hdmf = Number(emp.pagibig || 0);
    const tax  = Number(emp.withholding_tax || 0);
    const loan = Number(emp.loan_deduction || 0);

    const statParts = [];
    if (sss > 0)  statParts.push(`SSS: ₱${sss.toFixed(2)}`);
    if (phic > 0) statParts.push(`PhilHealth: ₱${phic.toFixed(2)}`);
    if (hdmf > 0) statParts.push(`Pag-IBIG: ₱${hdmf.toFixed(2)}`);
    if (tax > 0)  statParts.push(`Tax: ₱${tax.toFixed(2)}`);
    if (loan > 0) statParts.push(`Loan: ₱${loan.toFixed(2)}`);
    const breakdownTitle = statParts.length ? statParts.join(' • ') : 'PH Statutory Standard (₱0.00 if no clocked hours)';

    return `
      <tr class="step1-emp-row" data-search="${(emp.name + ' ' + emp.employee_code + ' ' + emp.position).toLowerCase()}">
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <span class="pay-card-avatar" style="width:30px;height:30px;font-size:11px">${initials}</span>
            <div>
              <strong style="color:#1E1224">${esc(emp.name)}</strong>
              <div style="font-size:11px;color:var(--text-muted)">${esc(emp.employee_code)} &middot; ${esc(emp.position)}</div>
            </div>
          </div>
        </td>
        <td style="font-size:11.5px;color:var(--text-main)">
          <strong>${Number(emp.days_worked || 0).toFixed(1)}d</strong> (${Number(emp.regular_hours || 0).toFixed(1)}h)
          ${emp.overtime_hours > 0 ? `<div style="color:#b45309;font-weight:700;font-size:11px">+${Number(emp.overtime_hours).toFixed(1)}h OT</div>` : ''}
        </td>
        <td style="text-align:right">
          <div style="font-weight:800;color:#1E1224;font-size:13.5px">
            ₱${Number(amt.basic).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
          </div>
          <div style="font-size:10px;color:#059669;display:flex;align-items:center;justify-content:flex-end;gap:3px;margin-top:2px;font-weight:600">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
        </td>
        <td style="text-align:right">
          <input type="number" step="0.01" min="0" class="pay-num-input" id="step1-bonus-${eid}" value="${Number(amt.bonus).toFixed(2)}"
                 placeholder="0.00" onchange="onStep1AmountChange(${eid}, 'bonus', this.value)">
        </td>
        <td style="text-align:right">
          <input type="number" step="0.01" min="0" class="pay-num-input" id="step1-ded-${eid}" style="color:var(--red)" value="${Number(amt.deductions).toFixed(2)}"
                 placeholder="0.00" onchange="onStep1AmountChange(${eid}, 'deductions', this.value)"
                 title="${esc(breakdownTitle)}">
          <div style="font-size:10px;display:flex;align-items:center;justify-content:flex-end;gap:3px;margin-top:2px;cursor:help" title="${esc(breakdownTitle)}">
            ${loan > 0 ? `<span style="color:#b45309;font-weight:600">+Loan</span>` : ''}
          </div>
        </td>
        <td style="text-align:right">
          <div class="pay-net-display" id="step1-net-${eid}" style="font-weight:800;color:#8B4513;font-size:14.5px">
            ₱${Number(amt.net).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function onStep1AmountChange(eid, field, val) {
  const v = Math.max(0, parseFloat(val) || 0);
  if (!customPayrollAmounts[eid]) customPayrollAmounts[eid] = { basic:0, bonus:0, deductions:0, net:0, userModifiedDeductions:false };
  customPayrollAmounts[eid][field] = v;
  if (field === 'deductions') {
    customPayrollAmounts[eid].userModifiedDeductions = true;
  }

  // Auto calculate net strictly from synced basic + bonus - deductions
  const b   = Number(customPayrollAmounts[eid].basic || 0);
  const bon = Number(customPayrollAmounts[eid].bonus || 0);
  const d   = Number(customPayrollAmounts[eid].deductions || 0);
  const net = Math.max(0, Math.round((b + bon - d) * 100) / 100);
  customPayrollAmounts[eid].net = net;

  const netElem = document.getElementById(`step1-net-${eid}`);
  if (netElem) {
    netElem.textContent = '₱' + net.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }
}

function filterStep1Table() {
  const query = (document.getElementById('pay-step1-search')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.step1-emp-row').forEach(row => {
    const text = row.getAttribute('data-search') || '';
    row.style.display = (!query || text.includes(query)) ? '' : 'none';
  });
}

// ── STEP 2: Render Selection Grid ─────────────────
function renderStep2Grid() {
  const grid = document.getElementById('pay-step2-grid');
  if (!grid) return;

  document.getElementById('step2-total-avail').textContent = rosterEmployees.length;

  grid.innerHTML = rosterEmployees.map(emp => {
    const eid = emp.id;
    const isSelected = selectedEmpIds.has(eid);
    const amt = customPayrollAmounts[eid]?.net ?? emp.net_pay;
    const initials = (emp.firstname.charAt(0) + emp.lastname.charAt(0)).toUpperCase();

    return `
      <div class="pay-select-card ${isSelected ? 'selected' : ''}" id="step2-card-${eid}" onclick="toggleEmployeeSelect(${eid}, event)">
        <input type="checkbox" id="chk-emp-${eid}" ${isSelected ? 'checked' : ''} onclick="event.stopPropagation(); toggleEmployeeSelect(${eid}, event)">
        <span class="pay-card-avatar">${initials}</span>
        <div class="pay-card-body">
          <div class="pay-card-name">${esc(emp.name)}</div>
          <div class="pay-card-meta">${esc(emp.employee_code)} &middot; ${esc(emp.position)}</div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
            <span class="pay-card-amount">₱${Number(amt).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
            <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;background:#FAF5EE;color:#5C2D0C;border:1px solid #DFCBB5">
              ${esc(emp.destination_display || 'Cash')}
            </span>
          </div>
        </div>
      </div>
    `;
  }).join('');

  updateStep2Summary();
}

function toggleEmployeeSelect(eid, e) {
  if (selectedEmpIds.has(eid)) {
    selectedEmpIds.delete(eid);
  } else {
    selectedEmpIds.add(eid);
  }

  const card = document.getElementById(`step2-card-${eid}`);
  const chk  = document.getElementById(`chk-emp-${eid}`);
  if (card) card.classList.toggle('selected', selectedEmpIds.has(eid));
  if (chk) chk.checked = selectedEmpIds.has(eid);

  updateStep2Summary();
}

function selectAllEmployees(select) {
  if (select) {
    rosterEmployees.forEach(e => selectedEmpIds.add(e.id));
  } else {
    selectedEmpIds.clear();
  }
  renderStep2Grid();
}

function selectPositiveNetOnly() {
  selectedEmpIds.clear();
  rosterEmployees.forEach(e => {
    const net = customPayrollAmounts[e.id]?.net ?? e.net_pay;
    if (net > 0) selectedEmpIds.add(e.id);
  });
  renderStep2Grid();
}

function updateStep2Summary() {
  let count = 0;
  let total = 0;
  selectedEmpIds.forEach(id => {
    count++;
    total += (customPayrollAmounts[id]?.net || 0);
  });

  const countBadge = document.getElementById('step2-badge-count');
  const countText  = document.getElementById('step2-count-text');
  const totalElem  = document.getElementById('step2-total-payout');

  if (countBadge) countBadge.textContent = count;
  if (countText)  countText.textContent = `${count} employee${count === 1 ? '' : 's'}`;
  if (totalElem)  totalElem.textContent = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function filterStep2Grid() {
  const query = (document.getElementById('pay-step2-search')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.pay-select-card').forEach(card => {
    const text = card.textContent.toLowerCase();
    card.style.display = (!query || text.includes(query)) ? 'flex' : 'none';
  });
}

// ── STEP 3: Setup Payment Methods & Review ────────
function renderStep3Setup() {
  const list = document.getElementById('pay-step3-list');
  if (!list) return;

  const selectedList = rosterEmployees.filter(e => selectedEmpIds.has(e.id));
  let total = 0;
  const channelCounts = { bank: 0, ewallet: 0, cash: 0, cheque: 0 };

  list.innerHTML = selectedList.map(emp => {
    const eid = emp.id;
    const amt = customPayrollAmounts[eid]?.net || 0;
    total += amt;

    const pm = empPaymentMethods[eid] || { method: emp.payment_method || 'cash', bank_name: emp.bank_name || '', last4: emp.account_number_last4 || '', ewallet_prov: 'gcash', mobile: emp.ewallet_mobile_number || '' };
    const method = pm.method;
    channelCounts[method] = (channelCounts[method] || 0) + 1;

    const initials = (emp.firstname.charAt(0) + emp.lastname.charAt(0)).toUpperCase();

    return `
      <div class="pay-method-card" id="step3-card-${eid}">
        <div style="display:flex;align-items:center;gap:10px;min-width:200px">
          <span class="pay-card-avatar" style="width:34px;height:34px;font-size:12px">${initials}</span>
          <div>
            <strong style="color:#1E1224">${esc(emp.name)}</strong>
            <div style="font-size:11px;color:var(--text-muted)">${esc(emp.employee_code)} &middot; ${esc(emp.position)}</div>
          </div>
        </div>

        <div style="text-align:right;min-width:110px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted)">Amount to Release</div>
          <strong style="font-size:15px;color:#8B4513">₱${Number(amt).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong>
        </div>

        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <select class="pay-method-select" onchange="onStep3MethodChange(${eid}, this.value)">
            <option value="bank_transfer" ${method === 'bank_transfer' ? 'selected' : ''}>Bank Transfer (PayMongo)</option>
            <option value="ewallet" ${method === 'ewallet' ? 'selected' : ''}>GCash / Maya (E-Wallet)</option>
            <option value="cash" ${method === 'cash' ? 'selected' : ''}>Cash / Manual Release</option>
            <option value="cheque" ${method === 'cheque' ? 'selected' : ''}>Corporate Cheque</option>
          </select>

          <!-- Dynamic inputs based on method -->
          <div id="step3-details-${eid}" style="display:flex;gap:6px;align-items:center">
            ${renderStep3DetailInputs(eid, method, pm)}
          </div>
        </div>
      </div>
    `;
  }).join('');

  // Update summary card
  const start   = document.getElementById('pay-start').value;
  const end     = document.getElementById('pay-end').value;
  const payDate = document.getElementById('pay-date').value;

  document.getElementById('step3-sum-count').textContent = selectedList.length + ' employee(s)';
  document.getElementById('step3-sum-period').textContent = `${start} to ${end}`;
  document.getElementById('step3-sum-paydate').textContent = payDate;
  document.getElementById('step3-sum-total').textContent = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

  const distStrings = [];
  if (channelCounts.bank_transfer) distStrings.push(`${channelCounts.bank_transfer} Bank`);
  if (channelCounts.ewallet) distStrings.push(`${channelCounts.ewallet} E-Wallet`);
  if (channelCounts.cash) distStrings.push(`${channelCounts.cash} Cash`);
  if (channelCounts.cheque) distStrings.push(`${channelCounts.cheque} Cheque`);
  document.getElementById('step3-sum-channels').textContent = distStrings.join(' &middot; ') || 'None';
}

function renderStep3DetailInputs(eid, method, pm) {
  if (method === 'bank_transfer') {
    return `
      <input type="text" placeholder="Bank (e.g. BDO, BPI)" value="${esc(pm.bank_name || '')}"
             style="padding:6px 10px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px;width:110px"
             onchange="updateEmpPaymentParam(${eid}, 'bank_name', this.value)">
      <input type="text" placeholder="Account / Last 4" value="${esc(pm.last4 || '')}"
             style="padding:6px 10px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px;width:100px"
             onchange="updateEmpPaymentParam(${eid}, 'last4', this.value)">
    `;
  } else if (method === 'ewallet') {
    return `
      <select style="padding:6px 8px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px"
              onchange="updateEmpPaymentParam(${eid}, 'ewallet_prov', this.value)">
        <option value="gcash" ${pm.ewallet_prov === 'gcash' ? 'selected' : ''}>GCash</option>
        <option value="paymaya" ${pm.ewallet_prov === 'paymaya' ? 'selected' : ''}>Maya</option>
        <option value="grabpay" ${pm.ewallet_prov === 'grabpay' ? 'selected' : ''}>GrabPay</option>
      </select>
      <input type="text" placeholder="09XXXXXXXXX" value="${esc(pm.mobile || '')}"
             style="padding:6px 10px;border-radius:8px;border:1px solid #DFCBB5;font-size:12px;width:110px"
             onchange="updateEmpPaymentParam(${eid}, 'mobile', this.value)">
    `;
  }
  return `<span style="font-size:11.5px;color:var(--text-muted);font-weight:600">Cash Envelope / Physical</span>`;
}

function onStep3MethodChange(eid, newMethod) {
  if (!empPaymentMethods[eid]) empPaymentMethods[eid] = {};
  empPaymentMethods[eid].method = newMethod;

  const container = document.getElementById(`step3-details-${eid}`);
  if (container) {
    container.innerHTML = renderStep3DetailInputs(eid, newMethod, empPaymentMethods[eid]);
  }
}

function updateEmpPaymentParam(eid, param, val) {
  if (!empPaymentMethods[eid]) empPaymentMethods[eid] = {};
  empPaymentMethods[eid][param] = val;
}

// ── Submit for Finance Review ─────────────────────
async function submitPayrollToFinance() {
  const submitBtn = document.getElementById('pay-btn-submit');
  const originalHtml = submitBtn.innerHTML;

  if (selectedEmpIds.size === 0) {
    Swal.fire({ icon: 'warning', title: 'No Staff Selected', text: 'Please select at least one employee for payout.' });
    return;
  }

  const start   = document.getElementById('pay-start').value;
  const end     = document.getElementById('pay-end').value;
  const payDate = document.getElementById('pay-date').value;
  const freq    = document.getElementById('pay-freq').value;
  const notes   = document.getElementById('pay-finance-notes').value.trim();

  // Prepare payload
  const employeesPayload = [];
  selectedEmpIds.forEach(eid => {
    const amt = customPayrollAmounts[eid] || {};
    const pm  = empPaymentMethods[eid] || {};

    let payoutInfo = 'Cash Envelope';
    if (pm.method === 'bank_transfer') {
      payoutInfo = (pm.bank_name || 'Bank') + ' •••• ' + (pm.last4 || '0000');
    } else if (pm.method === 'ewallet') {
      payoutInfo = (pm.ewallet_prov ? pm.ewallet_prov.toUpperCase() : 'GCASH') + ' ' + (pm.mobile || '09XXXXXXXXX');
    } else if (pm.method === 'cheque') {
      payoutInfo = 'Corporate Cheque';
    }

    const empObj = rosterEmployees.find(e => e.id === eid) || {};
    employeesPayload.push({
      employee_id: eid,
      basic_pay: amt.basic || 0,
      bonus: amt.bonus || 0,
      deductions: amt.deductions || 0,
      sss: empObj.sss || 0,
      philhealth: empObj.philhealth || 0,
      pagibig: empObj.pagibig || 0,
      withholding_tax: empObj.withholding_tax || 0,
      loan_deduction: empObj.loan_deduction || 0,
      net_pay: amt.net || 0,
      payment_method: pm.method || 'cash',
      payout_account_info: payoutInfo
    });
  });

  const confirmRes = await Swal.fire({
    title: 'Submit for Finance Review?',
    html: `You are submitting payroll for <strong>${employeesPayload.length}</strong> employee(s) totalling <strong>₱${document.getElementById('step3-sum-total').textContent}</strong>.<br><br><small style="color:#6b7280">This will notify Finance officers for review and fund release approval.</small>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#8B4513',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, Send to Finance'
  });

  if (!confirmRes.isConfirmed) return;

  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span>Submitting to Finance…</span>';

  Swal.fire({
    title: 'Submitting to Finance...',
    text: 'Please wait while the payroll run is recorded.',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await fetch('../api/payroll.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({
        action: 'create_step_payroll_run',
        period_meta: {
          period_start: start,
          period_end: end,
          pay_date: payDate,
          frequency: freq,
          notes: notes
        },
        employees: employeesPayload
      })
    });

    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Failed to create payroll run.');

    closePayrollModal();
    await Swal.fire({
      icon: 'success',
      title: 'Submitted to Finance',
      text: data.message || 'Payroll run has been submitted for Finance review.',
      timer: 1500,
      showConfirmButton: false
    });

    window.location.href = 'payroll_run.php?id=' + data.period_id;
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Submission Failed', text: err.message });
  } finally {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalHtml;
  }
}

function filterPeriodsTable() {
  const query = (document.getElementById('period-search')?.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('.period-row');
  let matchCount = 0;

  rows.forEach(r => {
    const text = r.getAttribute('data-search-text') || '';
    if (!query || text.includes(query)) {
      r.style.display = '';
      matchCount++;
    } else {
      r.style.display = 'none';
    }
  });

  const emptyRow = document.getElementById('empty-row');
  if (emptyRow) {
    emptyRow.style.display = (matchCount === 0 && rows.length > 0) ? '' : (rows.length === 0 ? '' : 'none');
  }
}

async function deletePeriod(periodId, label) {
  const result = await Swal.fire({
    title: 'Delete Pay Period?',
    text: `Are you sure you want to delete "${label}"?`,
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

function esc(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
</body>
</html>