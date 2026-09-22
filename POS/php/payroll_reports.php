<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/payroll_helpers.php';
require_login();
require_permission('payroll.view');

send_security_headers();

$pdo  = get_db();
$user = current_user();

// Fetch all periods for selector
$periods = $pdo->query(
    "SELECT id, label, period_start, period_end, pay_date, status, headcount, net_total, gross_total
       FROM payroll_periods
      ORDER BY period_start DESC"
)->fetchAll();

$selected_period_id = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));

$period = null;
if ($selected_period_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = :id");
    $stmt->execute([':id' => $selected_period_id]);
    $period = $stmt->fetch();
}

$slips = [];
$totals = [
    'headcount' => 0, 'basic' => 0.0, 'ot' => 0.0, 'commission' => 0.0,
    'tips' => 0.0, 'gross' => 0.0, 'sss' => 0.0, 'philhealth' => 0.0,
    'pagibig' => 0.0, 'tax' => 0.0, 'loan' => 0.0, 'other_ded' => 0.0,
    'total_deductions' => 0.0, 'net' => 0.0
];

$by_method = ['cash' => [], 'bank_transfer' => [], 'payroll_card' => [], 'ewallet' => []];

if ($period) {
    $slips_stmt = $pdo->prepare(
        "SELECT s.*, e.firstname, e.lastname, e.employee_code, e.position, e.branch,
                e.payment_method AS emp_pm, e.bank_name, e.bank_account_last4
           FROM payslips s
           JOIN employees e ON e.id = s.employee_id
          WHERE s.period_id = :p
          ORDER BY e.lastname, e.firstname"
    );
    $slips_stmt->execute([':p' => $selected_period_id]);
    $slips = $slips_stmt->fetchAll();

    foreach ($slips as $s) {
        $totals['headcount']++;
        $totals['basic']            += (float)$s['basic_pay'];
        $totals['ot']               += (float)$s['overtime_pay'];
        $totals['commission']       += (float)$s['commission'];
        $totals['tips']             += (float)$s['tips'];
        $totals['gross']            += (float)$s['gross_pay'];
        $totals['sss']              += (float)$s['sss'];
        $totals['philhealth']       += (float)$s['philhealth'];
        $totals['pagibig']          += (float)$s['pagibig'];
        $totals['tax']              += (float)$s['withholding_tax'];
        $totals['loan']             += (float)$s['loan_deduction'];
        $totals['other_ded']        += (float)$s['other_deduction'] + (float)$s['late_deduction'] + (float)$s['absence_deduction'];
        $totals['total_deductions'] += (float)$s['total_deductions'];
        $totals['net']              += (float)$s['net_pay'];

        $pm = $s['payment_method'] ?: $s['emp_pm'] ?: 'cash';
        if (!isset($by_method[$pm])) $by_method[$pm] = [];
        $by_method[$pm][] = $s;
    }
}

// POS Sales during period
$sales_in_period = 0.0;
if ($period) {
    $sales_stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount),0) FROM orders
          WHERE status = 'completed'
            AND DATE(placed_at) BETWEEN :s AND :e"
    );
    $sales_stmt->execute([':s' => $period['period_start'], ':e' => $period['period_end']]);
    $sales_in_period = (float)$sales_stmt->fetchColumn();
}
$labour_pct = $sales_in_period > 0 ? ($totals['gross'] / $sales_in_period) * 100 : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Payroll Reports — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/payroll.css"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-payroll-reports" class="page active">
  <div class="page-header no-print">
    <div>
      <h1>Payroll Reports &amp; Analytics</h1>
      <p>Summary registers, bank advice disbursements, statutory schedules, and CSV exports</p>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap">
      <button class="btn-ghost" onclick="window.print()"><?= icon('printer', 15) ?> <span>Print Report</span></button>
      <?php if ($period): ?>
      <div style="display:inline-flex;gap:6px">
        <a class="btn-add" href="../api/payroll.php?action=export_csv&period_id=<?= (int)$period['id'] ?>&type=register">
          <?= icon('download', 15) ?> <span>Export Register CSV</span>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">
    <?= render_payroll_subnav('reports') ?>

    <!-- Period Selector Toolbar -->
    <div class="pr-toolbar no-print">
      <form method="GET" class="pr-toolbar-group" style="flex:1">
        <label for="period-select">Pay Period</label>
        <select id="period-select" name="period_id" onchange="this.form.submit()" style="min-width:280px">
          <?php if (!$periods): ?>
          <option value="">No pay periods found</option>
          <?php else: foreach ($periods as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $selected_period_id === (int)$p['id'] ? 'selected' : '' ?>>
            <?= e($p['label']) ?> (Pay Date: <?= date('M j, Y', strtotime($p['pay_date'])) ?> &middot; <?= ucfirst($p['status']) ?>)
          </option>
          <?php endforeach; endif; ?>
        </select>
      </form>

      <?php if ($period): ?>
      <div class="pr-toolbar-group">
        <span class="pr-badge pr-badge-<?= $period['status'] === 'paid' ? 'green' : ($period['status'] === 'approved' ? 'blue' : 'amber') ?>">
          <?= icon($period['status'] === 'paid' ? 'check-circle' : ($period['status'] === 'approved' ? 'shield' : 'clock'), 11) ?>
          <span>Status: <?= ucfirst($period['status']) ?></span>
        </span>
        <span style="font-size:12.5px;color:var(--text-muted)">
          <?= date('M j', strtotime($period['period_start'])) ?> &ndash; <?= date('M j, Y', strtotime($period['period_end'])) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!$period): ?>
    <div class="table-card" style="margin-top:16px">
      <div class="pr-empty">
        <div class="pr-empty-icon"><?= icon('bar-chart', 22) ?></div>
        <h3>No Pay Period Selected</h3>
        <p>Please select an existing pay period or create a new one from the payroll dashboard.</p>
      </div>
    </div>
    <?php else: ?>

    <!-- Print Only Header -->
    <div class="pr-slip-head" style="display:none;margin-bottom:20px" id="print-header">
      <div>
        <div class="pr-slip-brand">Kofee Manila</div>
        <div class="pr-slip-sub">Payroll Summary Report</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:14px;font-weight:700"><?= e($period['label']) ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Pay Date: <?= e(date('F j, Y', strtotime($period['pay_date']))) ?></div>
      </div>
    </div>
    <style>
      @media print {
        #print-header { display: flex !important; }
      }
    </style>

    <!-- KPI Summary Grid -->
    <div class="stat-row">
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--blue-lt);color:var(--blue)"><?= icon('users', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $totals['headcount'] ?></div>
          <div class="mini-stat-lbl">Staff Headcount</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--accent-lt);color:var(--caramel)"><?= icon('coin', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= peso($totals['gross']) ?></div>
          <div class="mini-stat-lbl">Total Gross Pay</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--red-lt);color:var(--red)"><?= icon('scale', 18) ?></div>
        <div>
          <div class="mini-stat-val">&minus;<?= peso($totals['total_deductions']) ?></div>
          <div class="mini-stat-lbl">Total Deductions</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--green-lt);color:var(--green)"><?= icon('check-circle', 18) ?></div>
        <div>
          <div class="mini-stat-val" style="color:var(--green)"><?= peso($totals['net']) ?></div>
          <div class="mini-stat-lbl">Net Payout</div>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="pr-tabs no-print" style="margin-top:20px">
      <button type="button" class="pr-tab-btn is-active" onclick="switchReportTab('rpt-register', this)">
        <?= icon('file-text', 16) ?> <span>Payroll Register</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchReportTab('rpt-bank', this)">
        <?= icon('credit-card', 16) ?> <span>Bank Advice &amp; Payouts</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchReportTab('rpt-statutory', this)">
        <?= icon('scale', 16) ?> <span>Statutory Remittance (BIR/SSS/HDMF)</span>
      </button>
      <button type="button" class="pr-tab-btn" onclick="switchReportTab('rpt-analytics', this)">
        <?= icon('bar-chart', 16) ?> <span>Cost Analysis</span>
      </button>
    </div>

    <!-- TAB 1: Register Summary -->
    <div id="rpt-register" class="pr-tab-pane is-active">
      <div class="table-card">
        <div class="table-scroll-wrapper">
          <table class="pr-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th class="pr-num">Days</th>
                <th class="pr-num">Reg Hrs</th>
                <th class="pr-num">Basic Pay</th>
                <th class="pr-num">Overtime</th>
                <th class="pr-num">Comm &amp; Tips</th>
                <th class="pr-num">Gross Pay</th>
                <th class="pr-num">SSS</th>
                <th class="pr-num">PhilHealth</th>
                <th class="pr-num">Pag-IBIG</th>
                <th class="pr-num">Tax</th>
                <th class="pr-num">Loans / Other</th>
                <th class="pr-num">Total Ded.</th>
                <th class="pr-num">Net Payout</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$slips): ?>
              <tr><td colspan="14" style="text-align:center;padding:32px;color:var(--text-muted)">
                No payslips calculated for this period yet.
              </td></tr>
            <?php else: foreach ($slips as $s): ?>
              <tr>
                <td>
                  <div class="pr-emp-name"><?= e($s['lastname'] . ', ' . $s['firstname']) ?></div>
                  <div class="pr-emp-meta"><?= e($s['employee_code']) ?> &middot; <?= e($s['position']) ?></div>
                </td>
                <td class="pr-num"><?= rtrim(rtrim(number_format((float)$s['days_worked'], 1), '0'), '.') ?></td>
                <td class="pr-num"><?= number_format((float)$s['regular_hours'], 1) ?></td>
                <td class="pr-num"><?= peso((float)$s['basic_pay']) ?></td>
                <td class="pr-num <?= (float)$s['overtime_pay'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['overtime_pay']) ?></td>
                <td class="pr-num <?= ((float)$s['commission'] + (float)$s['tips']) == 0 ? 'pr-zero' : '' ?>">
                  <?= peso((float)$s['commission'] + (float)$s['tips']) ?>
                </td>
                <td class="pr-num" style="font-weight:700"><?= peso((float)$s['gross_pay']) ?></td>
                <td class="pr-num <?= (float)$s['sss'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['sss']) ?></td>
                <td class="pr-num <?= (float)$s['philhealth'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['philhealth']) ?></td>
                <td class="pr-num <?= (float)$s['pagibig'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['pagibig']) ?></td>
                <td class="pr-num <?= (float)$s['withholding_tax'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['withholding_tax']) ?></td>
                <td class="pr-num">
                  <?= peso((float)$s['loan_deduction'] + (float)$s['other_deduction'] + (float)$s['late_deduction'] + (float)$s['absence_deduction']) ?>
                </td>
                <td class="pr-num" style="color:var(--red)">&minus;<?= peso((float)$s['total_deductions']) ?></td>
                <td class="pr-num pr-net"><?= peso((float)$s['net_pay']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3">Totals (<?= $totals['headcount'] ?> Staff)</td>
                <td class="pr-num"><?= peso($totals['basic']) ?></td>
                <td class="pr-num"><?= peso($totals['ot']) ?></td>
                <td class="pr-num"><?= peso($totals['commission'] + $totals['tips']) ?></td>
                <td class="pr-num"><?= peso($totals['gross']) ?></td>
                <td class="pr-num"><?= peso($totals['sss']) ?></td>
                <td class="pr-num"><?= peso($totals['philhealth']) ?></td>
                <td class="pr-num"><?= peso($totals['pagibig']) ?></td>
                <td class="pr-num"><?= peso($totals['tax']) ?></td>
                <td class="pr-num"><?= peso($totals['loan'] + $totals['other_ded']) ?></td>
                <td class="pr-num" style="color:var(--red)">&minus;<?= peso($totals['total_deductions']) ?></td>
                <td class="pr-num" style="color:var(--caramel)"><?= peso($totals['net']) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 2: Bank Advice & Payouts -->
    <div id="rpt-bank" class="pr-tab-pane">
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;margin-bottom:16px">
        <?php 
        $method_configs = [
          'bank_transfer' => ['name' => 'Bank Transfer', 'icon' => 'credit-card', 'bg' => 'var(--blue-lt)', 'col' => 'var(--blue)'],
          'cash'          => ['name' => 'Cash', 'icon' => 'coin', 'bg' => 'var(--accent-lt)', 'col' => 'var(--caramel)'],
          'ewallet'       => ['name' => 'E-Wallet', 'icon' => 'globe', 'bg' => 'var(--purple-lt, rgba(139,92,246,0.12))', 'col' => 'var(--purple, #8b5cf6)'],
          'payroll_card'  => ['name' => 'Payroll Card', 'icon' => 'card', 'bg' => 'var(--green-lt)', 'col' => 'var(--green)']
        ];
        foreach ($method_configs as $mk => $cfg):
          $mcount = count($by_method[$mk] ?? []);
          $msum   = array_sum(array_map(fn($s) => (float)$s['net_pay'], $by_method[$mk] ?? []));
        ?>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['col'] ?>">
            <?= icon($cfg['icon'], 18) ?>
          </div>
          <div>
            <div class="mini-stat-val"><?= peso($msum) ?></div>
            <div class="mini-stat-lbl"><?= $cfg['name'] ?> (<?= $mcount ?> staff)</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="table-card">
        <div class="table-scroll-wrapper">
          <table class="pr-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Payment Mode</th>
                <th>Bank Name</th>
                <th>Account / Reference</th>
                <th class="pr-num">Net Amount to Credit</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$slips): ?>
              <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted)">
                No disbursement data available.
              </td></tr>
            <?php else: foreach ($slips as $s):
              $pm = $s['payment_method'] ?: $s['emp_pm'] ?: 'cash';
              $pmIcon = match($pm) {
                'bank_transfer' => 'credit-card',
                'cash' => 'coin',
                'ewallet' => 'globe',
                'payroll_card' => 'card',
                default => 'card'
              };
            ?>
              <tr>
                <td>
                  <div class="pr-emp-name"><?= e($s['lastname'] . ', ' . $s['firstname']) ?></div>
                  <div class="pr-emp-meta"><?= e($s['employee_code']) ?> &middot; <?= e($s['position']) ?></div>
                </td>
                <td>
                  <span class="pr-badge pr-badge-<?= $pm === 'bank_transfer' ? 'blue' : ($pm === 'cash' ? 'amber' : 'purple') ?>">
                    <?= icon($pmIcon, 11) ?> <span><?= ucwords(str_replace('_', ' ', $pm)) ?></span>
                  </span>
                </td>
                <td><strong><?= e($s['bank_name'] ?: '—') ?></strong></td>
                <td>
                  <?= $s['bank_account_last4'] ? ('Ending in ' . e($s['bank_account_last4'])) : '&mdash;' ?>
                </td>
                <td class="pr-num pr-net" style="font-size:15px"><?= peso((float)$s['net_pay']) ?></td>
                <td>
                  <span class="pr-badge pr-badge-<?= $s['payment_status'] === 'paid' ? 'green' : 'amber' ?>">
                    <?= icon($s['payment_status'] === 'paid' ? 'check-circle' : 'clock', 11) ?>
                    <span><?= ucfirst($s['payment_status']) ?></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4">Total Payout Required</td>
                <td class="pr-num" style="color:var(--caramel);font-size:16px"><?= peso($totals['net']) ?></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 3: Statutory Remittance -->
    <div id="rpt-statutory" class="pr-tab-pane">
      <div class="pr-alert pr-alert-info" style="margin-bottom:16px">
        <span class="pr-alert-icon"><?= icon('scale', 18) ?></span>
        <div>
          <strong>Statutory Contributions Summary Schedule</strong>
          Use these figures when filing monthly remittances with SSS, PhilHealth, Pag-IBIG (HDMF), and BIR 1601-C.
        </div>
      </div>

      <div class="table-card">
        <div class="table-scroll-wrapper">
          <table class="pr-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th class="pr-num">Gross Pay</th>
                <th class="pr-num">SSS (EE)</th>
                <th class="pr-num">PhilHealth (EE)</th>
                <th class="pr-num">Pag-IBIG (EE)</th>
                <th class="pr-num">Withholding Tax</th>
                <th class="pr-num">Total Remittance</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$slips): ?>
              <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted)">
                No statutory data available.
              </td></tr>
            <?php else: foreach ($slips as $s):
              $remit = (float)$s['sss'] + (float)$s['philhealth'] + (float)$s['pagibig'] + (float)$s['withholding_tax'];
            ?>
              <tr>
                <td>
                  <div class="pr-emp-name"><?= e($s['lastname'] . ', ' . $s['firstname']) ?></div>
                  <div class="pr-emp-meta"><?= e($s['employee_code']) ?></div>
                </td>
                <td class="pr-num"><?= peso((float)$s['gross_pay']) ?></td>
                <td class="pr-num <?= (float)$s['sss'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['sss']) ?></td>
                <td class="pr-num <?= (float)$s['philhealth'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['philhealth']) ?></td>
                <td class="pr-num <?= (float)$s['pagibig'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['pagibig']) ?></td>
                <td class="pr-num <?= (float)$s['withholding_tax'] == 0 ? 'pr-zero' : '' ?>"><?= peso((float)$s['withholding_tax']) ?></td>
                <td class="pr-num" style="font-weight:700;color:var(--text-main)"><?= peso($remit) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td>Totals</td>
                <td class="pr-num"><?= peso($totals['gross']) ?></td>
                <td class="pr-num"><?= peso($totals['sss']) ?></td>
                <td class="pr-num"><?= peso($totals['philhealth']) ?></td>
                <td class="pr-num"><?= peso($totals['pagibig']) ?></td>
                <td class="pr-num"><?= peso($totals['tax']) ?></td>
                <td class="pr-num" style="color:var(--caramel)">
                  <?= peso($totals['sss'] + $totals['philhealth'] + $totals['pagibig'] + $totals['tax']) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 4: Cost Analysis -->
    <div id="rpt-analytics" class="pr-tab-pane">
      <div class="pr-settings-grid">
        <div class="pr-card">
          <div class="pr-card-header">
            <div class="pr-card-title"><?= icon('bar-chart', 18) ?> Labour Efficiency</div>
          </div>
          <div class="stat-row" style="margin-top:0">
            <div class="mini-stat" style="border:none;box-shadow:none;background:var(--bg)">
              <div>
                <div class="mini-stat-val"><?= peso($sales_in_period) ?></div>
                <div class="mini-stat-lbl">Sales in Period</div>
              </div>
            </div>
            <div class="mini-stat" style="border:none;box-shadow:none;background:var(--bg)">
              <div>
                <div class="mini-stat-val"><?= number_format($labour_pct, 1) ?>%</div>
                <div class="mini-stat-lbl">Labour Cost %</div>
              </div>
            </div>
          </div>
          <p style="font-size:12.5px;color:var(--text-muted);margin-top:16px;line-height:1.55">
            Target benchmark for coffee shops is typically <strong>20% &ndash; 30%</strong> of gross sales.
            <?= $labour_pct > 35 ? '<span style="color:var(--red);font-weight:600">Current labour cost is elevated relative to sales.</span>' : '<span style="color:var(--green);font-weight:600">Labour cost is healthy and within optimal range.</span>' ?>
          </p>
        </div>

        <div class="pr-card">
          <div class="pr-card-header">
            <div class="pr-card-title"><?= icon('users', 18) ?> Average Compensation</div>
          </div>
          <div class="stat-row" style="margin-top:0">
            <div class="mini-stat" style="border:none;box-shadow:none;background:var(--bg)">
              <div>
                <div class="mini-stat-val">
                  <?= peso($totals['headcount'] > 0 ? ($totals['gross'] / $totals['headcount']) : 0) ?>
                </div>
                <div class="mini-stat-lbl">Avg Gross per Employee</div>
              </div>
            </div>
            <div class="mini-stat" style="border:none;box-shadow:none;background:var(--bg)">
              <div>
                <div class="mini-stat-val">
                  <?= peso($totals['headcount'] > 0 ? ($totals['net'] / $totals['headcount']) : 0) ?>
                </div>
                <div class="mini-stat-lbl">Avg Take-Home Pay</div>
              </div>
            </div>
          </div>
          <p style="font-size:12.5px;color:var(--text-muted);margin-top:16px;line-height:1.55">
            Based on <?= $totals['headcount'] ?> active employees calculated in period <strong><?= e($period['label']) ?></strong>.
          </p>
        </div>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

<script>
function switchReportTab(paneId, btn) {
  document.querySelectorAll('.pr-tab-pane').forEach(p => p.classList.remove('is-active'));
  document.querySelectorAll('.pr-tab-btn').forEach(b => b.classList.remove('is-active'));
  document.getElementById(paneId).classList.add('is-active');
  btn.classList.add('is-active');
}
</script>
</body>
</html>

