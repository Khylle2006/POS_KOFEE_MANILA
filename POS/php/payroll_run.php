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

$batch_stmt = $pdo->prepare("SELECT * FROM paymongo_payout_batches WHERE period_id = :p ORDER BY id DESC LIMIT 1");
$batch_stmt->execute([':p' => $period_id]);
$existing_payout_batch = $batch_stmt->fetch();

// ── Register ──────────────────────────────────
$slips_stmt = $pdo->prepare(
    'SELECT s.*, e.firstname, e.lastname, e.employee_code, e.position, e.branch,
            epd.bank_code, epd.bank_name, epd.account_name, epd.account_number_last4,
            epd.ewallet_provider, epd.ewallet_account_name, epd.ewallet_mobile_number
       FROM payslips s
       JOIN employees e ON e.id = s.employee_id
       LEFT JOIN employee_payment_details epd ON epd.employee_id = e.id AND epd.is_active = 1
      WHERE s.period_id = :p
      ORDER BY e.lastname, e.firstname'
);
$slips_stmt->execute([':p' => $period_id]);
$slips = $slips_stmt->fetchAll();

$exceptions   = array_filter($slips, fn($s) => (int)$s['has_exception'] === 1);
$paid_slips   = array_filter($slips, fn($s) => $s['payment_status'] === 'paid');
$failed_slips = array_filter($slips, fn($s) => $s['payment_status'] === 'failed');
$unpaid_slips = array_filter($slips, fn($s) => !in_array($s['payment_status'], ['paid'], true));

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
    ['Finance Review', $period['status'] === 'pending_finance' ? 'Awaiting Finance Sign-off' : ($period['calculated_at'] ? date('M j, g:i A', strtotime($period['calculated_at'])) : ($period['approved_at'] ? 'Reviewed' : 'Pending'))],
    ['Approved',       $period['approved_at'] ? date('M j, g:i A', strtotime($period['approved_at'])) : 'Awaiting review'],
    ['Released',       $period['paid_at'] ? date('M j, g:i A', strtotime($period['paid_at'])) : ($period['status'] === 'partially_paid' ? count($paid_slips) . ' of ' . count($slips) . ' released' : 'Not released')],
];
$step_index = match ($period['status']) {
    'draft'           => 1,
    'pending_finance' => 2,
    'calculated'      => 2,
    'approved'        => 3,
    'partially_paid'  => 4,
    'paid', 'locked'  => 5,
    default           => 1,
};

$editable = in_array($period['status'], ['draft', 'calculated', 'pending_finance'], true);

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

<?php 
include("../includes/sidebar.php"); 

$status_badge_map = [
    'draft'           => ['class' => 'pr-badge-gray',   'label' => 'Draft'],
    'pending_finance' => ['class' => 'pr-badge-amber',  'label' => 'Under Finance Review'],
    'calculated'      => ['class' => 'pr-badge-blue',   'label' => 'Calculated'],
    'approved'        => ['class' => 'pr-badge-green',  'label' => 'Approved (Ready to Release)'],
    'partially_paid'  => ['class' => 'pr-badge-blue',   'label' => 'Partially Released'],
    'paid'            => ['class' => 'pr-badge-green',  'label' => 'Released & Closed'],
    'locked'          => ['class' => 'pr-badge-purple', 'label' => 'Locked'],
];
$curr_badge = $status_badge_map[$period['status']] ?? ['class' => 'pr-badge-gray', 'label' => ucfirst(str_replace('_', ' ', $period['status']))];
?>

<div id="page-payroll-run" class="page active">
  <div class="page-header">
    <div>
      <a class="pr-breadcrumb" href="payroll.php">
        <?= icon('chevron', 13) ?> <span>Back to Payroll Dashboard</span>
      </a>
      <div class="pr-header-title-row">
        <h1 style="margin:0;font-size:22px"><?= e($period['label']) ?></h1>
        <span class="pr-badge <?= $curr_badge['class'] ?>"><?= e($curr_badge['label']) ?></span>
      </div>
      <p style="margin-top:4px">
        <?= e(date('F j', strtotime($period['period_start']))) ?> &ndash;
        <?= e(date('F j, Y', strtotime($period['period_end']))) ?>
        &middot; Pay Date: <strong><?= e(date('F j, Y', strtotime($period['pay_date']))) ?></strong>
        <?php if (!empty($period['branch'])): ?>
          &middot; Branch: <strong><?= e($period['branch']) ?></strong>
        <?php endif; ?>
      </p>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <?php if ($slips): ?>
      <a class="btn-ghost" href="../api/payroll.php?action=export_csv&period_id=<?= (int)$period_id ?>&type=register" title="Download Register CSV">
        <?= icon('download', 15) ?> <span>Register CSV</span>
      </a>
      <a class="btn-ghost" href="../api/payroll.php?action=export_csv&period_id=<?= (int)$period_id ?>&type=bank_advice" title="Download Bank Advice CSV">
        <?= icon('credit-card', 15) ?> <span>Bank CSV</span>
      </a>
      <?php endif; ?>

      <?php if ($can_manage && $editable): ?>
        <?php if ($period['status'] === 'draft'): ?>
        <button class="btn-add btn-act-calculate" onclick="runAction('calculate', 'Run the payroll calculation for this period?')">
          <?= icon('refresh', 15) ?> <span>Calculate Payroll</span>
        </button>
        <?php else: ?>
        <button class="btn-ghost" onclick="runAction('calculate', 'Recalculate this period? Any figures already on screen will be replaced. Manual adjustments will be preserved.')">
          <?= icon('refresh', 14) ?> <span>Recalculate</span>
        </button>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($can_approve && in_array($period['status'], ['pending_finance', 'calculated'], true)): ?>
      <button class="btn-add btn-act-approve" onclick="openFinanceModal()">
        <?= icon('shield', 15) ?> <span><?= $period['status'] === 'pending_finance' ? 'Finance Sign-Off / Review' : 'Review & Approve' ?></span>
      </button>
      <?php endif; ?>

      <?php if ($can_release && in_array($period['status'], ['approved', 'partially_paid'], true)): ?>
      <button class="btn-add btn-act-release" onclick="openRelease('paymongo')">
        <?= icon('credit-card', 15) ?> <span>Disbursement Gateway</span>
      </button>
      <?php elseif ($existing_payout_batch): ?>
      <button class="btn-ghost" onclick="openRelease('paymongo')">
        <?= icon('credit-card', 15) ?> <span>PayMongo Batch (<?= strtoupper(e($existing_payout_batch['status'])) ?>)</span>
      </button>
      <?php endif; ?>

      <?php if ($can_manage && $editable): ?>
      <button class="btn-ghost" style="color:var(--red);border-color:rgba(198,40,40,0.25)" onclick="confirmDeletePeriod()" title="Delete this pay period">
        <?= icon('trash', 14) ?> <span>Delete</span>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('runs', $period['label']) ?>

    <!-- Workflow Stepper -->
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

    <!-- Status Banners -->
    <?php if ($period['status'] === 'pending_finance'): ?>
    <div class="pr-alert pr-alert-warn" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#fffbeb;border:1px solid #fcd34d">
      <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:280px">
        <span style="font-size:24px">📋</span>
        <div>
          <strong style="color:#92400e;font-size:14px">Awaiting Finance Sign-Off</strong>
          <div style="font-size:12.5px;color:#78350f">
            This payroll run has custom employee earnings and net pay amounts submitted for review.
            Finance sign-off is required before disbursement release.
          </div>
        </div>
      </div>
      <?php if ($can_approve): ?>
      <button type="button" class="btn-add" style="background:#b45309;box-shadow:none" onclick="openFinanceModal()">
        <?= icon('shield', 14) ?> <span>Review &amp; Sign-Off</span>
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($period['status'] === 'partially_paid'): ?>
    <div class="pr-alert pr-alert-info" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#eff6ff;border:1px solid #93c5fd">
      <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:24px">💳</span>
        <div>
          <strong style="color:#1e40af;font-size:14px">Partially Released Payroll (<?= count($paid_slips) ?> of <?= count($slips) ?> Staff Paid)</strong>
          <div style="font-size:12.5px;color:#1e3a8a">
            Each employee's payment transfer is tracked separately. You can release remaining or failed staff individually or in bulk below.
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Exceptions Callout -->
    <?php if ($exceptions && !in_array($period['status'], ['paid', 'locked'], true)): ?>
    <div class="pr-alert pr-alert-warn" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div style="display:flex;align-items:flex-start;gap:12px;flex:1;min-width:280px">
        <span class="pr-alert-icon"><?= icon('alert-triangle', 20) ?></span>
        <div>
          <strong><?= count($exceptions) ?> payslip<?= count($exceptions) === 1 ? '' : 's' ?> need review before approval</strong>
          <span style="font-size:12.5px;color:#52434F">
            Employees have missing clock-outs, unapproved attendance, unconfigured pay rates, or negative net pay.
          </span>
        </div>
      </div>
      <div>
        <button type="button" class="btn-isolate-exceptions" id="btn-filter-exceptions" onclick="toggleExceptionFilter()">
          <?= icon('alert-triangle', 14) ?> <span>Isolate Exceptions (<?= count($exceptions) ?>)</span>
        </button>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($period['status'] === 'paid'): ?>
    <div class="pr-alert pr-alert-info" style="margin-top:16px">
      <span class="pr-alert-icon"><?= icon('lock', 18) ?></span>
      <div>
        <strong>This pay period is closed and released</strong>
        Disbursed on <?= e(date('F j, Y \a\t g:i A', strtotime($period['paid_at']))) ?>.
        All figures and attendance snapshots are locked for audit compliance.
      </div>
    </div>
    <?php endif; ?>

    <!-- Summary KPI Cards -->
    <div class="stat-row-modern" style="margin-top:16px">
      <div class="pr-stat-card">
        <div class="pr-stat-header">
          <span class="pr-stat-tag">Headcount</span>
          <div class="pr-stat-icon-wrap"><?= icon('users', 16) ?></div>
        </div>
        <div class="pr-stat-main">
          <div class="pr-stat-number"><?= count($slips) ?> <span style="font-size:13px;font-weight:600;color:var(--text-muted)">Staff</span></div>
          <div class="pr-stat-subtext"><?= e(!empty($period['branch']) ? $period['branch'] : 'Enrolled Roster') ?></div>
        </div>
      </div>

      <div class="pr-stat-card">
        <div class="pr-stat-header">
          <span class="pr-stat-tag">Gross Earnings</span>
          <div class="pr-stat-icon-wrap" style="background:#ecfdf5;color:#047857"><?= icon('dollar-sign', 16) ?></div>
        </div>
        <div class="pr-stat-main">
          <div class="pr-stat-number"><?= peso($totals['gross']) ?></div>
          <div class="pr-stat-subtext">Basic + OT (<?= peso($totals['overtime']) ?>) + Comm &amp; Tips</div>
        </div>
      </div>

      <div class="pr-stat-card">
        <div class="pr-stat-header">
          <span class="pr-stat-tag">Total Deductions</span>
          <div class="pr-stat-icon-wrap" style="background:#fef2f2;color:#b91c1c"><?= icon('trending-down', 16) ?></div>
        </div>
        <div class="pr-stat-main">
          <div class="pr-stat-number" style="color:var(--red)">&minus;<?= peso($totals['deductions']) ?></div>
          <div class="pr-stat-subtext">SSS, PhilHealth, Pag-IBIG, Tax &amp; Loans</div>
        </div>
      </div>

      <div class="pr-stat-card highlight">
        <div class="pr-stat-header">
          <span class="pr-stat-tag">Net Take-Home</span>
          <div class="pr-stat-icon-wrap" style="background:var(--caramel,#8B4513);color:#fff"><?= icon('credit-card', 16) ?></div>
        </div>
        <div class="pr-stat-main">
          <div class="pr-stat-number" style="color:var(--caramel,#8B4513)"><?= peso($totals['net']) ?></div>
          <div class="pr-stat-subtext">
            <?php if ($paid_slips): ?>
              <span style="color:#15803d;font-weight:700"><?= count($paid_slips) ?></span> of <?= count($slips) ?> released
              <?php if ($failed_slips): ?>
                &middot; <span style="color:var(--red);font-weight:700"><?= count($failed_slips) ?> failed</span>
              <?php endif; ?>
            <?php else: ?>
              Payable across <?= count($slips) ?> employee accounts
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Register Toolbar with Tabs & Instant Search -->
    <?php if ($slips): ?>
    <div class="pr-control-bar" style="margin-top:20px;margin-bottom:12px">
      <div class="pr-pills" id="reg-filter-pills">
        <button type="button" class="pr-pill active" onclick="setRegisterFilter('all', this)">
          <span>All Staff</span>
          <span class="pr-pill-count"><?= count($slips) ?></span>
        </button>
        <?php if ($unpaid_slips && $paid_slips): ?>
        <button type="button" class="pr-pill" onclick="setRegisterFilter('unreleased', this)">
          <span>⏳ Unreleased</span>
          <span class="pr-pill-count"><?= count($unpaid_slips) ?></span>
        </button>
        <?php endif; ?>
        <?php if ($paid_slips): ?>
        <button type="button" class="pr-pill" onclick="setRegisterFilter('released', this)">
          <span>✓ Released</span>
          <span class="pr-pill-count" style="background:#dcfce7;color:#15803d"><?= count($paid_slips) ?></span>
        </button>
        <?php endif; ?>
        <?php if ($failed_slips): ?>
        <button type="button" class="pr-pill" onclick="setRegisterFilter('failed', this)">
          <span>⚠️ Failed Transfers</span>
          <span class="pr-pill-count" style="background:#fee2e2;color:#b91c1c"><?= count($failed_slips) ?></span>
        </button>
        <?php endif; ?>
        <?php if ($exceptions): ?>
        <button type="button" class="pr-pill" id="pill-filter-exceptions" onclick="setRegisterFilter('exceptions', this)">
          <span>⚠️ Needs Review</span>
          <span class="pr-pill-count" style="background:#fef3c7;color:#b45309"><?= count($exceptions) ?></span>
        </button>
        <?php endif; ?>
        <?php 
          $ot_count = count(array_filter($slips, fn($s) => (float)$s['overtime_hours'] > 0));
          if ($ot_count > 0): 
        ?>
        <button type="button" class="pr-pill" onclick="setRegisterFilter('overtime', this)">
          <span>With Overtime</span>
          <span class="pr-pill-count"><?= $ot_count ?></span>
        </button>
        <?php endif; ?>
        <?php 
          $ded_count = count(array_filter($slips, fn($s) => (float)$s['total_deductions'] > 0));
          if ($ded_count > 0): 
        ?>
        <button type="button" class="pr-pill" onclick="setRegisterFilter('deductions', this)">
          <span>With Deductions</span>
          <span class="pr-pill-count"><?= $ded_count ?></span>
        </button>
        <?php endif; ?>
      </div>

      <div class="pr-search-wrap">
        <span class="pr-search-icon"><?= icon('search', 15) ?></span>
        <input type="text" id="reg-search-input" class="pr-search-input"
               placeholder="Search employee, ID, position..."
               oninput="filterRegisterTable()" />
      </div>
    </div>
    <?php endif; ?>

    <!-- Register Table Card -->
    <div class="table-card" style="margin-top:10px">
      <div class="table-scroll-wrapper">
        <table class="pr-table" id="register-table">
          <thead>
            <tr>
              <th style="width:36px;text-align:center">
                <input type="checkbox" id="th-select-all" onchange="toggleSelectAllSlips(this)" title="Select all unreleased staff" />
              </th>
              <th class="col-sticky-emp">Employee</th>
              <th class="pr-num">Days</th>
              <th class="pr-num">Reg Hrs</th>
              <th class="pr-num">OT Hrs</th>
              <th class="pr-num">Basic</th>
              <th class="pr-num">OT Pay</th>
              <th class="pr-num">Comm.</th>
              <th class="pr-num">Tips</th>
              <th class="pr-num">Gross</th>
              <th class="pr-num">Deductions</th>
              <th class="pr-num">Net Pay</th>
              <th>Destination</th>
              <th>Transfer Status</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="register-tbody">
          <?php if (!$slips): ?>
            <tr><td colspan="15">
              <div class="pr-empty">
                <div class="pr-empty-icon"><?= icon('clipboard', 22) ?></div>
                <h3>Nothing enrolled yet</h3>
                <p>No employee records found in this payroll period.</p>
                <?php if ($can_manage && $editable): ?>
                <button class="btn-add btn-act-calculate" style="margin-top:16px"
                        onclick="runAction('calculate', 'Run the payroll calculation for this period?')">
                  <?= icon('refresh', 15) ?> <span>Calculate Now</span>
                </button>
                <?php endif; ?>
              </div>
            </td></tr>
          <?php else: foreach ($slips as $s):
            $initials = strtoupper(substr($s['firstname'], 0, 1) . substr($s['lastname'], 0, 1));
            $fullName = $s['firstname'] . ' ' . $s['lastname'];
            $searchText = strtolower($fullName . ' ' . $s['employee_code'] . ' ' . $s['position'] . ' ' . ($s['branch'] ?? ''));
            $isPaid   = ($s['payment_status'] === 'paid');
            $isFailed = ($s['payment_status'] === 'failed');

            // Format payment destination
            $dest = $s['payout_account_info'] ?: '';
            if (!$dest) {
              if ($s['bank_name']) {
                $dest = $s['bank_name'] . ' •• ' . ($s['account_number_last4'] ?: '0000');
              } elseif ($s['ewallet_provider']) {
                $dest = strtoupper($s['ewallet_provider']) . ' ' . ($s['ewallet_mobile_number'] ?: '');
              } else {
                $dest = 'Cash / OTC Envelope';
              }
            }
          ?>
            <tr class="pr-reg-row <?= (int)$s['has_exception'] === 1 ? 'has-exception' : '' ?> <?= $isFailed ? 'has-failed-transfer' : '' ?>"
                data-search-text="<?= e($searchText) ?>"
                data-has-exception="<?= (int)$s['has_exception'] ?>"
                data-has-ot="<?= (float)$s['overtime_hours'] > 0 ? 1 : 0 ?>"
                data-has-deductions="<?= (float)$s['total_deductions'] > 0 ? 1 : 0 ?>"
                data-status="<?= e($s['payment_status']) ?>">
              <td style="text-align:center">
                <input type="checkbox" class="slip-checkbox" value="<?= (int)$s['id'] ?>"
                       data-name="<?= e($fullName) ?>"
                       data-net="<?= (float)$s['net_pay'] ?>"
                       <?= $isPaid ? 'disabled title="Already released"' : 'onchange="updateSelectedCount()"' ?> />
              </td>
              <td class="col-sticky-emp">
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
                <div style="margin-top:6px;font-size:11.5px;color:var(--amber);font-weight:600;display:flex;align-items:center;gap:4px">
                  <?= icon('alert-triangle', 13) ?>
                  <span><?= e($s['exception_note']) ?></span>
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
              <td style="font-size:12px;color:var(--text-main);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($dest) ?>">
                <span style="display:inline-flex;align-items:center;gap:5px">
                  <?php if (str_contains(strtolower($s['payment_method'] ?? ''), 'bank')): ?>
                    <?= icon('credit-card', 13) ?>
                  <?php elseif (str_contains(strtolower($s['payment_method'] ?? ''), 'ewallet')): ?>
                    <?= icon('dollar-sign', 13) ?>
                  <?php else: ?>
                    <?= icon('clipboard', 13) ?>
                  <?php endif; ?>
                  <span><?= e($dest) ?></span>
                </span>
              </td>
              <td style="font-size:12px;white-space:nowrap">
                <?php if ($s['payment_status'] === 'paid'): ?>
                  <span class="transfer-status-badge paid"><?= icon('check', 12) ?> Released</span>
                  <?php if ($s['transfer_id']): ?>
                    <div style="font-size:10px;color:var(--text-muted);font-family:monospace;margin-top:2px"><?= e($s['transfer_id']) ?></div>
                  <?php endif; ?>
                <?php elseif ($s['payment_status'] === 'failed'): ?>
                  <span class="transfer-status-badge failed" title="<?= e($s['transfer_error']) ?>"><?= icon('alert-triangle', 12) ?> Failed</span>
                  <div style="font-size:10.5px;color:var(--red);margin-top:2px;max-width:140px;overflow:hidden;text-overflow:ellipsis" title="<?= e($s['transfer_error']) ?>">
                    <?= e($s['transfer_error'] ?: 'Transfer error') ?>
                  </div>
                <?php elseif ($s['payment_status'] === 'pending_finance'): ?>
                  <span class="transfer-status-badge pending_finance"><?= icon('clock', 12) ?> Under Review</span>
                <?php elseif ($s['payment_status'] === 'approved'): ?>
                  <span class="transfer-status-badge approved"><?= icon('check-circle', 12) ?> Approved</span>
                <?php else: ?>
                  <span class="transfer-status-badge unpaid"><?= icon('clock', 12) ?> Unpaid</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-ghost" href="payslip.php?id=<?= (int)$s['id'] ?>" title="View payslip">
                  <?= icon('file-text', 15) ?>
                </a>
                <?php if ($can_release && in_array($period['status'], ['approved', 'partially_paid'], true) && !$isPaid): ?>
                  <?php if ($isFailed): ?>
                    <button type="button" class="btn-ind-retry" onclick="retrySinglePayslip(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8') ?>, <?= (float)$s['net_pay'] ?>)" title="Retry transfer">
                      <?= icon('refresh', 12) ?> <span>Retry</span>
                    </button>
                    <button type="button" class="btn-ind-cash" onclick="releaseSinglePayslipCash(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8') ?>, <?= (float)$s['net_pay'] ?>)" title="Release via Cash">
                      Cash
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn-ind-release" onclick="openIndividualReleaseModal(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8') ?>, <?= (float)$s['net_pay'] ?>, <?= htmlspecialchars(json_encode($s['payment_method'] ?: 'cash'), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($dest), ENT_QUOTES, 'UTF-8') ?>)" title="Release this employee's pay individually">
                      <?= icon('credit-card', 13) ?> <span>Release</span>
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($can_manage && $editable): ?>
                <button class="btn-ghost" title="Adjustments"
                        onclick="openAdjust(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($fullName), ENT_QUOTES, 'UTF-8') ?>)">
                  <?= icon('edit', 15) ?>
                </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
            <tr id="reg-no-results-row" style="display:none">
              <td colspan="15" class="pr-empty-table-search">
                <div style="font-size:24px;margin-bottom:6px">🔍</div>
                <strong>No staff found matching filter or search</strong>
                <div style="font-size:12px;margin-top:4px">Try adjusting your search query or reset the filter pill.</div>
              </td>
            </tr>
          </tbody>
          <tfoot id="reg-tfoot">
            <tr>
              <td colspan="5" class="col-sticky-emp">
                <span id="reg-summary-count">Totals &mdash; <?= count($slips) ?> employee<?= count($slips) === 1 ? '' : 's' ?></span>
              </td>
              <td class="pr-num"><?= peso(array_sum(array_column($slips, 'basic_pay'))) ?></td>
              <td class="pr-num"><?= peso($totals['overtime']) ?></td>
              <td class="pr-num"><?= peso($totals['commission']) ?></td>
              <td class="pr-num"><?= peso($totals['tips']) ?></td>
              <td class="pr-num"><?= peso($totals['gross']) ?></td>
              <td class="pr-num" style="color:var(--red)">&minus;<?= peso($totals['deductions']) ?></td>
              <td class="pr-num" style="color:var(--caramel)"><?= peso($totals['net']) ?></td>
              <td></td>
              <td></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Floating Batch Release Bar -->
    <div id="batch-action-bar" style="display:none;position:sticky;bottom:20px;z-index:90;background:#1E1224;color:#ffffff;padding:12px 20px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.3);margin-top:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div style="display:flex;align-items:center;gap:12px">
        <span style="font-size:20px">👥</span>
        <div>
          <strong id="batch-bar-count">0 employees selected</strong>
          <div style="font-size:12px;color:rgba(255,255,255,0.7)">Total Net Payout: <span id="batch-bar-total" style="font-weight:700;color:#F8EFE3">&#8369;0.00</span></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <button type="button" class="btn-ghost" style="color:#ffffff;border-color:rgba(255,255,255,0.3)" onclick="clearAllSelectedSlips()">Deselect All</button>
        <?php if ($can_release && in_array($period['status'], ['approved', 'partially_paid'], true)): ?>
        <button type="button" class="btn-ind-release" style="background:var(--caramel,#8B4513);padding:8px 18px;font-size:13px" onclick="batchReleaseChecked()">
          <?= icon('credit-card', 15) ?> <span>Release Selected Staff</span>
        </button>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Finance Review & Sign-Off Modal -->
<div class="modal-bg" id="finance-review-modal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <h3><?= icon('shield', 18) ?> <span>Finance Sign-off &amp; Review</span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <div style="background:var(--cream,#FAF7F2);border:1px solid var(--border,#DFCBB5);border-radius:12px;padding:14px;margin-bottom:14px">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Payroll Run Summary</div>
        <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:13px">
          <span>Enrolled Staff:</span>
          <strong><?= count($slips) ?> employees</strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:13px">
          <span>Gross Earnings:</span>
          <strong><?= peso($totals['gross']) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:13px">
          <span>Total Deductions:</span>
          <strong style="color:var(--red)">&minus;<?= peso($totals['deductions']) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px;padding-top:6px;border-top:1px dashed var(--border,#DFCBB5);font-size:14px">
          <span>Total Net Payout:</span>
          <strong style="color:var(--caramel,#8B4513);font-size:16px"><?= peso($totals['net']) ?></strong>
        </div>
      </div>

      <?php if ($exceptions): ?>
      <div class="pr-alert pr-alert-warn" style="margin-bottom:14px">
        <span class="pr-alert-icon"><?= icon('alert-triangle', 17) ?></span>
        <div><strong><?= count($exceptions) ?> unresolved exception(s)</strong>
             Approval notes will be logged in the audit trail.</div>
      </div>
      <?php endif; ?>

      <div class="field" style="margin-bottom:0">
        <label for="finance-review-note">Finance Review Remarks <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
        <textarea id="finance-review-note" rows="3" style="width:100%;border-radius:8px;border:1px solid var(--border,#DFCBB5);padding:8px 10px;font-family:inherit;font-size:13px;resize:vertical" placeholder="Enter review remarks, authorization note, or reason for revision..."></textarea>
      </div>
    </div>
    <div class="modal-actions" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <button type="button" class="btn-ghost" style="color:var(--red,#c62828);border-color:rgba(198,40,40,0.3)" onclick="submitFinanceReview('reject')">
        <?= icon('x', 14) ?> <span>Return for Revision</span>
      </button>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn-ghost" onclick="closeModals()">Cancel</button>
        <button type="button" class="btn-add" style="background:#2e7d32" onclick="submitFinanceReview('approve')">
          <?= icon('check-circle', 15) ?> <span>Approve for Payout</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Individual Employee Release Modal -->
<div class="modal-bg" id="individual-release-modal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <h3><?= icon('credit-card', 18) ?> <span>Release Pay &mdash; <span id="ind-emp-name" style="font-weight:700"></span></span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>
    <div class="modal-body">
      <div class="pr-netbox" style="margin-bottom:16px">
        <span class="pr-netbox-label">Net Payable Amount</span>
        <span class="pr-netbox-value" id="ind-net-amount">&#8369;0.00</span>
      </div>

      <div class="field">
        <label for="ind-method-select">Disbursement Channel</label>
        <select id="ind-method-select" onchange="toggleIndAccountField()" style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border,#DFCBB5);font-size:13px;font-weight:600;color:var(--text-main);background:#fff">
          <option value="bank_transfer">🏦 Bank Transfer (PayMongo Automated / Direct)</option>
          <option value="ewallet">📱 E-Wallet (GCash / Maya via PayMongo)</option>
          <option value="cash">💵 Cash / Over-The-Counter Envelope</option>
          <option value="cheque">📄 Corporate Cheque</option>
        </select>
      </div>

      <div class="field" id="ind-account-wrap">
        <label id="ind-account-label" for="ind-account-info">Destination Account / Mobile</label>
        <input type="text" id="ind-account-info" style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border,#DFCBB5);font-size:13px" placeholder="e.g. BDO •••• 1234 or 09171234567" />
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Payment will be recorded directly for this employee.</div>
      </div>

      <div class="pr-alert pr-alert-info" style="margin-top:12px;font-size:12px">
        <span>ℹ️ Releasing pay tracks this employee's transfer separately, deducts scheduled loan repayments, and updates the period's overall completion status.</span>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-ghost" onclick="closeModals()"><?= icon('x', 14) ?> <span>Cancel</span></button>
      <button type="button" class="btn-add" id="btn-confirm-ind-release" onclick="submitIndividualRelease()">
        <?= icon('check', 15) ?> <span>Confirm &amp; Disburse</span>
      </button>
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

<!-- Release modal with PayMongo Batch Transfer & Manual Fallback -->
<div class="modal-bg" id="release-modal">
  <div class="modal" style="max-width:820px;width:95vw;max-height:90vh;display:flex;flex-direction:column">
    <div class="modal-header">
      <h3><?= icon('credit-card', 18) ?> <span>Release Payroll &amp; Disbursement</span></h3>
      <button type="button" class="modal-close" onclick="closeModals()" aria-label="Close"><?= icon('x', 16) ?></button>
    </div>

    <!-- Tabs Navigation -->
    <div style="display:flex;gap:12px;border-bottom:1px solid var(--border,#e8ded2);padding:0 22px;background:var(--bg-card,#fff)">
      <button type="button" id="tab-btn-paymongo" class="rel-tab-btn active" onclick="switchReleaseTab('paymongo')" style="padding:11px 16px;border:none;background:none;font-weight:700;font-size:13.5px;cursor:pointer;border-bottom:2px solid var(--caramel,#8B4513);color:var(--caramel,#8B4513);display:inline-flex;align-items:center;gap:6px">
        <?= icon('credit-card', 15) ?> <span>PayMongo Batch Transfer</span>
      </button>
      <button type="button" id="tab-btn-manual" class="rel-tab-btn" onclick="switchReleaseTab('manual')" style="padding:11px 16px;border:none;background:none;font-weight:600;font-size:13.5px;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-muted,#7a6e78);display:inline-flex;align-items:center;gap:6px">
        <?= icon('check-circle', 15) ?> <span>Manual / Cash Release</span>
      </button>
    </div>

    <!-- Tab 1: PayMongo Batch Disbursement -->
    <div id="rel-tab-paymongo-pane" class="modal-body" style="overflow-y:auto;flex:1;padding:20px 22px">
      <!-- Top Metrics -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:12px;margin-bottom:16px">
        <div style="background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Total Payable</div>
          <div id="pm-total-amount" style="font-size:18px;font-weight:800;color:var(--text-main);margin-top:2px">&#8369;<?= number_format($totals['net'], 2) ?></div>
        </div>
        <div style="background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Valid Recipients</div>
          <div id="pm-valid-count" style="font-size:18px;font-weight:800;color:var(--green,#2e7d32);margin-top:2px">&hellip;</div>
        </div>
        <div style="background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Missing / Blocked</div>
          <div id="pm-invalid-count" style="font-size:18px;font-weight:800;color:var(--red,#c62828);margin-top:2px">&hellip;</div>
        </div>
        <div style="background:var(--cream,#FAF7F2);border:1px solid var(--border,#e8ded2);border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">Gateway Mode</div>
          <div id="pm-gateway-mode" style="font-size:14px;font-weight:700;color:var(--caramel,#8B4513);margin-top:4px">SANDBOX</div>
        </div>
      </div>

      <!-- Pre-flight Status Alert -->
      <div id="pm-alert-container" style="margin-bottom:14px">
        <div style="font-size:13px;color:var(--text-muted)">Validating employee payout accounts&hellip;</div>
      </div>

      <!-- Existing Batch Info (if applicable) -->
      <div id="pm-batch-card" style="display:none;background:#f3e8ff;border:1px solid #d8b4fe;border-radius:10px;padding:12px 16px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
          <div>
            <span style="font-size:12px;font-weight:700;color:#6b21a8">DISBURSEMENT BATCH:</span>
            <strong id="pm-batch-ref" style="font-family:monospace;font-size:13px;margin-left:6px;color:#3b0764"></strong>
          </div>
          <div>
            <span id="pm-batch-badge" class="badge" style="font-size:11px;text-transform:uppercase"></span>
          </div>
        </div>
      </div>

      <!-- Pre-submission table -->
      <div style="border:1px solid var(--border,#e8ded2);border-radius:10px;overflow:hidden">
        <div style="max-height:260px;overflow-y:auto">
          <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:left">
            <thead style="background:var(--cream,#FAF7F2);position:sticky;top:0;z-index:2;border-bottom:1px solid var(--border,#e8ded2)">
              <tr>
                <th style="padding:9px 12px;font-weight:700;color:var(--text-muted)">Employee</th>
                <th style="padding:9px 12px;font-weight:700;color:var(--text-muted)">Destination / Masked Account</th>
                <th style="padding:9px 12px;font-weight:700;color:var(--text-muted);text-align:right">Net Pay</th>
                <th style="padding:9px 12px;font-weight:700;color:var(--text-muted)">Status</th>
                <th style="padding:9px 12px;font-weight:700;color:var(--text-muted);text-align:center">Action</th>
              </tr>
            </thead>
            <tbody id="pm-items-tbody">
              <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted)">Loading recipient data&hellip;</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 2: Manual / Cash Release -->
    <div id="rel-tab-manual-pane" class="modal-body" style="display:none;padding:22px">
      <p style="font-size:13.5px;line-height:1.6;color:var(--text-main)">
        Manual release confirms payment distribution outside the automated PayMongo gateway (e.g. cash envelopes, manual bank cheques, or direct OTC deposit).
      </p>
      <div class="pr-netbox" style="margin-top:16px">
        <span class="pr-netbox-label">Total Payout Amount</span>
        <span class="pr-netbox-value"><?= peso($totals['net']) ?></span>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:14px;line-height:1.55">
        Clicking confirm will mark all employee payslips in this period as <strong>Paid</strong>, record loan amortization deductions, and advance the period to Released state.
      </p>
    </div>

    <!-- Modal Footer Actions -->
    <div class="modal-actions" style="border-top:1px solid var(--border,#e8ded2);padding:14px 22px">
      <button type="button" class="btn-ghost" onclick="closeModals()"><?= icon('x', 14) ?> <span>Close</span></button>
      
      <span id="pm-actions-wrapper">
        <button type="button" id="btn-pm-dispatch" class="btn-add" onclick="dispatchPaymongoPayout()" disabled>
          <?= icon('credit-card', 15) ?> <span>Dispatch PayMongo Payout</span>
        </button>
      </span>
      
      <button type="button" id="btn-manual-confirm" class="btn-add" onclick="runAction('release')" style="display:none">
        <?= icon('check', 15) ?> <span>Confirm Manual Release</span>
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

let currentRegFilter = 'all';

function setRegisterFilter(filter, el) {
  currentRegFilter = filter;
  document.querySelectorAll('#reg-filter-pills .pr-pill').forEach(p => p.classList.remove('active'));
  if (el) el.classList.add('active');
  
  const excBtn = document.getElementById('btn-filter-exceptions');
  if (excBtn) {
    if (filter === 'exceptions') {
      excBtn.classList.add('active');
      excBtn.innerHTML = `<?= icon('check', 14) ?> <span>Showing Exceptions Only</span>`;
    } else {
      excBtn.classList.remove('active');
      excBtn.innerHTML = `<?= icon('alert-triangle', 14) ?> <span>Isolate Exceptions (<?= count($exceptions) ?>)</span>`;
    }
  }
  filterRegisterTable();
}

function toggleExceptionFilter() {
  if (currentRegFilter === 'exceptions') {
    const allPill = document.querySelector('#reg-filter-pills .pr-pill');
    setRegisterFilter('all', allPill);
  } else {
    const excPill = document.getElementById('pill-filter-exceptions');
    setRegisterFilter('exceptions', excPill);
    document.getElementById('register-table')?.scrollIntoView({ behavior: 'smooth' });
  }
}

function filterRegisterTable() {
  const query = (document.getElementById('reg-search-input')?.value || '').trim().toLowerCase();
  const rows = document.querySelectorAll('.pr-reg-row');
  let visibleCount = 0;

  rows.forEach(row => {
    const text = row.getAttribute('data-search-text') || '';
    const hasExc = row.getAttribute('data-has-exception') === '1';
    const hasOt = row.getAttribute('data-has-ot') === '1';
    const hasDed = row.getAttribute('data-has-deductions') === '1';
    const status = row.getAttribute('data-status') || '';

    let matchesFilter = true;
    if (currentRegFilter === 'exceptions' && !hasExc) matchesFilter = false;
    if (currentRegFilter === 'overtime' && !hasOt) matchesFilter = false;
    if (currentRegFilter === 'deductions' && !hasDed) matchesFilter = false;
    if (currentRegFilter === 'unreleased' && status === 'paid') matchesFilter = false;
    if (currentRegFilter === 'released' && status !== 'paid') matchesFilter = false;
    if (currentRegFilter === 'failed' && status !== 'failed') matchesFilter = false;

    let matchesSearch = true;
    if (query && !text.includes(query)) matchesSearch = false;

    if (matchesFilter && matchesSearch) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  const emptyRow = document.getElementById('reg-no-results-row');
  if (emptyRow) {
    emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
  }

  const countSummary = document.getElementById('reg-summary-count');
  if (countSummary) {
    countSummary.textContent = (visibleCount === rows.length)
      ? `Totals — ${rows.length} employee${rows.length === 1 ? '' : 's'}`
      : `Showing ${visibleCount} of ${rows.length} employee${rows.length === 1 ? '' : 's'}`;
  }
}

function closeModals() {
  document.querySelectorAll('.modal-bg.open').forEach(m => m.classList.remove('open'));
}
function openApprove() { document.getElementById('approve-modal').classList.add('open'); }
function openFinanceModal() { document.getElementById('finance-review-modal').classList.add('open'); }
function openRelease(tab = 'paymongo') { 
  document.getElementById('release-modal').classList.add('open'); 
  switchReleaseTab(tab);
}

// ── Individual & Batch Release Handlers ─────────
let currentIndPayslip = null;

function openIndividualReleaseModal(id, name, net, method, info) {
  currentIndPayslip = { id, name, net, method, info };
  document.getElementById('ind-emp-name').textContent = name;
  document.getElementById('ind-net-amount').textContent = '₱' + Number(net).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  
  const mSelect = document.getElementById('ind-method-select');
  if (mSelect) mSelect.value = method || 'cash';
  
  const accInput = document.getElementById('ind-account-info');
  if (accInput) accInput.value = info || '';
  
  toggleIndAccountField();
  document.getElementById('individual-release-modal').classList.add('open');
}

function toggleIndAccountField() {
  const method = document.getElementById('ind-method-select')?.value;
  const wrap = document.getElementById('ind-account-wrap');
  const label = document.getElementById('ind-account-label');
  if (!wrap || !label) return;

  if (method === 'cash') {
    wrap.style.display = 'none';
  } else {
    wrap.style.display = 'block';
    if (method === 'bank_transfer') {
      label.textContent = 'Bank Name & Account Number';
    } else if (method === 'ewallet') {
      label.textContent = 'E-Wallet Provider & Mobile Number';
    } else {
      label.textContent = 'Cheque Details / Reference';
    }
  }
}

async function submitIndividualRelease() {
  if (!currentIndPayslip) return;
  const method = document.getElementById('ind-method-select')?.value || 'cash';
  const info   = (document.getElementById('ind-account-info')?.value || '').trim();

  closeModals();

  Swal.fire({
    title: 'Disbursing Payment...',
    text: `Processing payment of ₱${Number(currentIndPayslip.net).toFixed(2)} to ${currentIndPayslip.name}.`,
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await post({
      action: 'release_individual',
      payslip_id: currentIndPayslip.id,
      payment_method: method,
      payment_details: {
        account_info: info,
        account_name: currentIndPayslip.name,
      }
    });

    await Swal.fire({
      icon: 'success',
      title: 'Payment Released!',
      html: `<strong>${esc(res.message || 'Payment released successfully.')}</strong><br><small style="color:#6b7280">Transfer Reference: ${esc(res.transfer_id || 'N/A')}</small>`,
      confirmButtonColor: '#8B4513'
    });
    location.reload();
  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Disbursement Failed',
      text: err.message || 'Transfer failed.',
      confirmButtonColor: '#8B4513'
    }).then(() => location.reload());
  }
}

async function retrySinglePayslip(id, name, net) {
  const confirm = await Swal.fire({
    title: 'Retry Transfer?',
    text: `Retry payout disbursement of ₱${Number(net).toFixed(2)} to ${name}?`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#8B4513',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, Retry'
  });
  if (!confirm.isConfirmed) return;

  Swal.fire({
    title: 'Retrying Disbursement...',
    text: 'Contacting payout gateway...',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await post({ action: 'release_individual', payslip_id: id });
    await Swal.fire({
      icon: 'success',
      title: 'Transfer Succeeded',
      text: res.message || 'Payment transferred successfully.',
      confirmButtonColor: '#8B4513'
    });
    location.reload();
  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Retry Failed',
      text: err.message || 'Transfer attempt failed.',
      confirmButtonColor: '#8B4513'
    }).then(() => location.reload());
  }
}

async function releaseSinglePayslipCash(id, name, net) {
  const confirm = await Swal.fire({
    title: 'Release via Cash?',
    html: `Confirm Over-The-Counter Cash release of <strong>₱${Number(net).toFixed(2)}</strong> to <strong>${esc(name)}</strong>?<br><br><small style="color:#6b7280">This will mark the employee as Paid with a Cash reference and post any loan deductions.</small>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#2e7d32',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Confirm Cash Release'
  });
  if (!confirm.isConfirmed) return;

  Swal.fire({
    title: 'Recording Cash Release...',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await post({ action: 'release_individual', payslip_id: id, payment_method: 'cash' });
    await Swal.fire({
      icon: 'success',
      title: 'Released via Cash',
      text: res.message || 'Payment recorded successfully.',
      confirmButtonColor: '#2e7d32'
    });
    location.reload();
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Error', text: err.message });
  }
}

// ── Multi-Select Checkboxes & Batch Release ─────
function toggleSelectAllSlips(master) {
  const checkboxes = document.querySelectorAll('.slip-checkbox:not(:disabled)');
  checkboxes.forEach(cb => {
    const row = cb.closest('tr');
    if (row && row.style.display !== 'none') {
      cb.checked = master.checked;
    }
  });
  updateSelectedCount();
}

function updateSelectedCount() {
  const checked = document.querySelectorAll('.slip-checkbox:checked');
  const bar = document.getElementById('batch-action-bar');
  const countEl = document.getElementById('batch-bar-count');
  const totalEl = document.getElementById('batch-bar-total');
  const master = document.getElementById('th-select-all');

  let totalNet = 0;
  checked.forEach(cb => {
    totalNet += parseFloat(cb.dataset.net || 0);
  });

  if (checked.length > 0) {
    if (bar) bar.style.display = 'flex';
    if (countEl) countEl.textContent = `${checked.length} employee${checked.length === 1 ? '' : 's'} selected`;
    if (totalEl) totalEl.textContent = '₱' + totalNet.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
  } else {
    if (bar) bar.style.display = 'none';
  }

  const allAvailable = document.querySelectorAll('.slip-checkbox:not(:disabled)');
  if (master) {
    master.checked = allAvailable.length > 0 && checked.length === allAvailable.length;
    master.indeterminate = checked.length > 0 && checked.length < allAvailable.length;
  }
}

function clearAllSelectedSlips() {
  document.querySelectorAll('.slip-checkbox').forEach(cb => cb.checked = false);
  const master = document.getElementById('th-select-all');
  if (master) { master.checked = false; master.indeterminate = false; }
  updateSelectedCount();
}

async function batchReleaseChecked() {
  const checked = Array.from(document.querySelectorAll('.slip-checkbox:checked')).map(cb => ({
    id: parseInt(cb.value, 10),
    name: cb.dataset.name,
    net: parseFloat(cb.dataset.net || 0)
  }));

  if (!checked.length) {
    Swal.fire({ icon: 'info', title: 'No Selection', text: 'Please select at least one employee to release.' });
    return;
  }

  const total = checked.reduce((acc, c) => acc + c.net, 0);

  const confirm = await Swal.fire({
    title: 'Confirm Batch Release',
    html: `Are you sure you want to release payouts for <strong>${checked.length} staff</strong> totalling <strong>₱${total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</strong>?<br><br><small style="color:#6b7280">Each employee payment will be processed separately. Any individual failure will not block other employees from getting paid.</small>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#8B4513',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, Release Payouts'
  });

  if (!confirm.isConfirmed) return;

  Swal.fire({
    title: 'Executing Payouts...',
    html: `Processing transfers for ${checked.length} employee(s)...<br><small style="color:#6b7280">Please keep this page open.</small>`,
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await post({
      action: 'batch_release_selected',
      period_id: PERIOD_ID,
      payslip_ids: checked.map(c => c.id)
    });

    let resultsHtml = `<div style="max-height:220px;overflow-y:auto;text-align:left;font-size:12.5px;margin-top:12px;border:1px solid #e5e7eb;border-radius:8px;padding:8px">`;
    if (res.results && res.results.length) {
      res.results.forEach(r => {
        if (r.status === 'paid') {
          resultsHtml += `<div style="padding:4px 0;color:#15803d;border-bottom:1px solid #f3f4f6">✓ <strong>${esc(r.employee_name)}:</strong> Released (${esc(r.transfer_id || 'Paid')})</div>`;
        } else {
          resultsHtml += `<div style="padding:4px 0;color:#b91c1c;border-bottom:1px solid #f3f4f6">✕ <strong>${esc(r.employee_name)}:</strong> Failed (${esc(r.error || 'Error')})</div>`;
        }
      });
    }
    resultsHtml += `</div>`;

    await Swal.fire({
      icon: res.failed_count > 0 ? 'warning' : 'success',
      title: 'Batch Release Complete',
      html: `<strong>${esc(res.message || '')}</strong>${resultsHtml}`,
      confirmButtonColor: '#8B4513'
    });
    location.reload();
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Batch Release Error', text: err.message });
  }
}

// ── Finance Review Action ───────────────────────
async function submitFinanceReview(decision) {
  const note = (document.getElementById('finance-review-note')?.value || '').trim();
  const verb = decision === 'approve' ? 'Approve' : 'Return for Revision';

  const confirm = await Swal.fire({
    title: `${verb} Payroll?`,
    text: decision === 'approve'
      ? 'Approving signs-off on all amounts and unlocks payout release.'
      : 'Returning for revision moves this payroll run back to draft for manager corrections.',
    icon: decision === 'approve' ? 'question' : 'warning',
    showCancelButton: true,
    confirmButtonColor: decision === 'approve' ? '#2e7d32' : '#c62828',
    cancelButtonColor: '#52434F',
    confirmButtonText: `Yes, ${verb}`
  });

  if (!confirm.isConfirmed) return;

  closeModals();

  Swal.fire({
    title: 'Submitting Review...',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const res = await post({
      action: 'finance_review',
      period_id: PERIOD_ID,
      decision,
      note
    });

    await Swal.fire({
      icon: 'success',
      title: decision === 'approve' ? 'Approved by Finance' : 'Returned for Revision',
      text: res.message || 'Finance review recorded.',
      confirmButtonColor: '#8B4513'
    });
    location.reload();
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Action Failed', text: err.message });
  }
}

let pmAuditData = null;

function switchReleaseTab(tab) {
  const pmBtn = document.getElementById('tab-btn-paymongo');
  const manBtn = document.getElementById('tab-btn-manual');
  const pmPane = document.getElementById('rel-tab-paymongo-pane');
  const manPane = document.getElementById('rel-tab-manual-pane');
  const pmActions = document.getElementById('pm-actions-wrapper');
  const manAction = document.getElementById('btn-manual-confirm');

  if (!pmBtn || !manBtn) return;

  if (tab === 'paymongo') {
    pmBtn.style.borderBottomColor = 'var(--caramel, #8B4513)';
    pmBtn.style.color = 'var(--caramel, #8B4513)';
    pmBtn.style.fontWeight = '700';
    manBtn.style.borderBottomColor = 'transparent';
    manBtn.style.color = 'var(--text-muted, #7a6e78)';
    manBtn.style.fontWeight = '600';
    pmPane.style.display = 'block';
    manPane.style.display = 'none';
    pmActions.style.display = 'inline-flex';
    manAction.style.display = 'none';
    loadPaymongoAudit();
  } else {
    manBtn.style.borderBottomColor = 'var(--caramel, #8B4513)';
    manBtn.style.color = 'var(--caramel, #8B4513)';
    manBtn.style.fontWeight = '700';
    pmBtn.style.borderBottomColor = 'transparent';
    pmBtn.style.color = 'var(--text-muted, #7a6e78)';
    pmBtn.style.fontWeight = '600';
    manPane.style.display = 'block';
    pmPane.style.display = 'none';
    pmActions.style.display = 'none';
    manAction.style.display = 'inline-block';
  }
}

async function loadPaymongoAudit() {
  const tbody = document.getElementById('pm-items-tbody');
  const alertBox = document.getElementById('pm-alert-container');
  const dispatchBtn = document.getElementById('btn-pm-dispatch');
  const batchCard = document.getElementById('pm-batch-card');

  if (!tbody || !alertBox || !dispatchBtn) return;

  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted)">Validating beneficiary accounts...</td></tr>';

  try {
    const data = await post({ action: 'paymongo_validate_payout', period_id: PERIOD_ID });
    pmAuditData = data;

    const totalPayable = Number(data.total_net ?? data.total_payable ?? 0);
    const validCount   = Number(data.valid_count || 0);
    const invalidCount = Number(data.invalid_count || 0);

    document.getElementById('pm-total-amount').textContent = '₱' + totalPayable.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('pm-valid-count').textContent = validCount + ' / ' + (validCount + invalidCount);
    document.getElementById('pm-invalid-count').textContent = invalidCount;
    document.getElementById('pm-gateway-mode').textContent = data.config?.mode || 'SANDBOX';

    // Existing batch check
    if (data.existing_batch) {
      batchCard.style.display = 'block';
      document.getElementById('pm-batch-ref').textContent = data.existing_batch.batch_reference;
      const bBadge = document.getElementById('pm-batch-badge');
      bBadge.textContent = data.existing_batch.status;
      bBadge.className = 'badge ' + (data.existing_batch.status === 'paid' ? 'badge-success' : (data.existing_batch.status === 'processing' ? 'badge-warning' : 'badge-danger'));
    } else {
      batchCard.style.display = 'none';
    }

    // Alert banner & dispatch button gating
    if (validCount === 0) {
      alertBox.innerHTML = `
        <div style="background:#fee2e2;border:1px solid #f87171;color:#991b1b;border-radius:8px;padding:11px 14px;font-size:12.5px;display:flex;align-items:center;gap:10px">
          <span>⚠️ <strong>No Valid Payment Accounts:</strong> None of the payable employees have approved bank or e-wallet destinations on file. Please have staff enter details in Profile or use Manual Release.</span>
        </div>`;
      dispatchBtn.disabled = true;
    } else if (invalidCount > 0) {
      alertBox.innerHTML = `
        <div style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:8px;padding:11px 14px;font-size:12.5px;display:flex;align-items:center;gap:10px">
          <span>⚠️ <strong>Partial Batch Transfer Ready:</strong> <strong>${validCount}</strong> verified employee(s) (₱${totalPayable.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}) will be disbursed via PayMongo. <strong>${invalidCount}</strong> employee(s) lack complete payment details and will not be charged; they can be released manually or once updated.</span>
        </div>`;
      dispatchBtn.disabled = (data.existing_batch && data.existing_batch.status === 'paid');
      dispatchBtn.innerHTML = `<span><?= icon('credit-card', 15) ?> Dispatch PayMongo Payout (${validCount} Staff)</span>`;
    } else {
      alertBox.innerHTML = `
        <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:8px;padding:11px 14px;font-size:12.5px">
          <span>✅ <strong>All ${validCount} recipients verified:</strong> Payout batch will disburse <strong>₱${totalPayable.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> via PayMongo API with automated idempotency protection.</span>
        </div>`;
      dispatchBtn.disabled = (data.existing_batch && data.existing_batch.status === 'paid');
      dispatchBtn.innerHTML = `<span><?= icon('credit-card', 15) ?> Dispatch PayMongo Payout (${validCount} Staff)</span>`;
    }

    // Render items table
    if (!data.items || !data.items.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted)">No employee payslips found in this period.</td></tr>';
      return;
    }

    const batchItemMap = {};
    if (data.existing_batch && data.existing_batch.items) {
      data.existing_batch.items.forEach(it => { batchItemMap[it.payslip_id] = it; });
    }

    tbody.innerHTML = data.items.map(item => {
      const bItem = batchItemMap[item.payslip_id];
      let statusHtml = '';
      let actionHtml = '&mdash;';

      if (bItem) {
        const itemSt = bItem.status;
        if (itemSt === 'paid') {
          statusHtml = '<span style="color:#16a34a;font-weight:700">● Transferred</span>';
        } else if (itemSt === 'failed') {
          statusHtml = `<span style="color:#dc2626;font-weight:700" title="${esc(bItem.error_message)}">● Failed: ${esc(bItem.error_message || 'API error')}</span>`;
          actionHtml = `<button type="button" class="btn-ghost" style="padding:3px 8px;font-size:11px" onclick="retryPaymongoItem(${bItem.id})">Retry</button>`;
        } else {
          statusHtml = `<span style="color:#d97706;font-weight:700">● ${esc(itemSt)}</span>`;
        }
      } else if (item.is_valid) {
        statusHtml = '<span style="color:#16a34a;font-weight:700">✓ Ready</span>';
      } else {
        statusHtml = `<span style="color:#dc2626;font-weight:700">✕ ${esc(item.validation_error || 'Invalid')}</span>`;
      }

      return `
        <tr style="border-bottom:1px solid var(--border,#e8ded2)">
          <td style="padding:9px 12px">
            <strong style="color:var(--text-main)">${esc(item.employee_name)}</strong>
            <div style="font-size:11px;color:var(--text-muted)">${esc(item.employee_code)}</div>
          </td>
          <td style="padding:9px 12px;font-size:12px;color:var(--text-main)">
            ${esc(item.destination_display)}
          </td>
          <td style="padding:9px 12px;text-align:right;font-variant-numeric:tabular-nums;font-weight:700;color:var(--text-main)">
            ₱${Number(item.net_pay).toFixed(2)}
          </td>
          <td style="padding:9px 12px;font-size:12px">
            ${statusHtml}
          </td>
          <td style="padding:9px 12px;text-align:center">
            ${actionHtml}
          </td>
        </tr>
      `;
    }).join('');

  } catch (err) {
    alertBox.innerHTML = `<div style="color:var(--red);font-size:13px">Error loading audit: ${esc(err.message)}</div>`;
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--red);padding:20px">${esc(err.message)}</td></tr>`;
  }
}

async function dispatchPaymongoPayout() {
  const canDispatch = pmAuditData && (pmAuditData.can_dispatch || pmAuditData.can_submit || (pmAuditData.valid_count > 0));
  if (!canDispatch) {
    Swal.fire({ icon: 'warning', title: 'Action Blocked', text: 'No verified employee records are ready for PayMongo disbursement in this period.' });
    return;
  }

  const totalAmount = Number(pmAuditData.total_net ?? pmAuditData.total_payable ?? 0);
  const count = Number(pmAuditData.valid_count || 0);

  const res = await Swal.fire({
    title: 'Confirm Automated Payout',
    html: `Are you sure you want to dispatch <strong>₱${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong> to <strong>${count}</strong> employees via PayMongo?<br><br><small style="color:#6b7280">This will execute batch bank and e-wallet transfers, notify staff, and mark verified records as paid.</small>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#8B4513',
    cancelButtonColor: '#52434F',
    confirmButtonText: 'Yes, Dispatch Payout'
  });

  if (!res.isConfirmed) return;

  Swal.fire({
    title: 'Disbursing...',
    text: 'Connecting to PayMongo API and processing batch transfers.',
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading()
  });

  try {
    const data = await post({ action: 'paymongo_dispatch_payout', period_id: PERIOD_ID });
    await Swal.fire({
      icon: 'success',
      title: 'Disbursement Initiated',
      text: data.message || 'PayMongo batch payout executed.',
      confirmButtonColor: '#8B4513'
    });
    location.reload();
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Disbursement Failed', text: err.message });
  }
}

async function retryPaymongoItem(itemId) {
  try {
    Swal.fire({ title: 'Retrying item...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const data = await post({ action: 'paymongo_retry_item', payout_item_id: itemId });
    await Swal.fire({ icon: 'success', title: 'Retried', text: data.message || 'Item updated.' });
    await loadPaymongoAudit();
  } catch (err) {
    Swal.fire({ icon: 'error', title: 'Retry Failed', text: err.message });
  }
}

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