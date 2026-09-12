<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();

$period_label = procurement_current_period();
$departments  = ['manager' => 'Operations', 'crew' => 'Crew', 'finance' => 'Finance', 'hr' => 'HR', 'admin' => 'Admin'];

// ── Role-aware access flags — drives which KPI cards / sections render ──
$can = [
    'requisition_create' => has_permission('procurement.requisition.create'),
    'requisition_review' => has_permission('procurement.requisition.review'),
    'rfq'                => has_permission('procurement.rfq.manage'),
    'bid_review'         => has_permission('procurement.bidding.review'),
    'negotiation'        => has_permission('procurement.negotiation'),
    'po'                 => has_permission('procurement.po.manage'),
    'receiving'          => has_permission('procurement.receiving'),
    'grn_discrepancy'    => has_permission('procurement.grn.discrepancy.manage'),
    'invoice_create'     => has_permission('procurement.invoice.create'),
    'invoice_match'      => has_permission('procurement.invoice.match'),
    'payment'            => has_permission('procurement.payment.process'),
    'rate'               => has_permission('procurement.performance.rate'),
    'close'              => has_permission('procurement.close'),
    'budget'             => has_permission('procurement.budget.manage'),
    'audit'              => has_permission('procurement.audit.view'),
    'suppliers'          => has_permission('procurement.suppliers.manage'),
    'reports'            => has_permission('procurement.reports.view'),
    'supplier_portal'    => has_permission('procurement.supplier.portal'),
];

// ── Best-effort counts — never let a stat query break the dashboard ──
function dash_count(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('procurement_dashboard stat failed: ' . $e->getMessage());
        return 0;
    }
}
function dash_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('procurement_dashboard rows failed: ' . $e->getMessage());
        return [];
    }
}

// My own filed requisitions still awaiting a decision.
$my_pending_req = dash_count($pdo, "SELECT COUNT(*) FROM purchase_requisitions WHERE requested_by = :u AND status = 'pending'", [':u' => $user['id']]);

// Requisitions awaiting a budget/approval decision.
$reqs_awaiting_review = $can['requisition_review']
    ? dash_count($pdo, "SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'pending'")
    : 0;

// Requisitions approved but not yet sourced (no RFQ started) — a queue for whoever manages RFQs.
$reqs_awaiting_rfq = $can['rfq']
    ? dash_count($pdo, "
        SELECT COUNT(*) FROM purchase_requisitions pr
        WHERE pr.status = 'approved'
          AND NOT EXISTS (SELECT 1 FROM rfqs r WHERE r.requisition_id = pr.id)
    ")
    : 0;

$open_rfqs = ($can['rfq'] || $can['bid_review'])
    ? dash_count($pdo, "SELECT COUNT(*) FROM rfqs WHERE status = 'open'")
    : 0;

$bids_awaiting_review = $can['bid_review']
    ? dash_count($pdo, "SELECT COUNT(*) FROM bids WHERE status = 'submitted'")
    : 0;

$pos_in_flight = ($can['po'] || $can['receiving'])
    ? dash_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','acknowledged')")
    : 0;

$deliveries_awaiting = $can['receiving']
    ? dash_count($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','acknowledged')")
    : 0;

$grn_discrepancies = $can['grn_discrepancy']
    ? dash_count($pdo, "SELECT COUNT(*) FROM goods_receipts WHERE status = 'discrepancy'")
    : 0;

$invoices_pending = ($can['invoice_match'] || $can['invoice_create'])
    ? dash_count($pdo, "SELECT COUNT(*) FROM invoices WHERE status = 'pending'")
    : 0;

$invoices_disputed = ($can['invoice_match'] || $can['invoice_create'])
    ? dash_count($pdo, "SELECT COUNT(*) FROM invoices WHERE status = 'disputed'")
    : 0;

$payments_scheduled = dash_rows($pdo, "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM payments WHERE status = 'scheduled'");
$payments_scheduled = $payments_scheduled[0] ?? ['cnt' => 0, 'total' => 0];
$show_payments = $can['payment'];

$orders_ready_to_close = ($can['rate'] || $can['close'])
    ? dash_count($pdo, "
        SELECT COUNT(*) FROM purchase_orders po
        WHERE po.status = 'delivered'
          AND EXISTS (SELECT 1 FROM invoices i WHERE i.po_id = po.id AND i.status = 'paid')
    ")
    : 0;

// Budget snapshot for the current period — every dept if budget.manage, else just the user's own dept.
$budget_rows = [];
if ($can['budget'] || $can['reports']) {
    $budget_rows = dash_rows($pdo, "SELECT * FROM procurement_budgets WHERE period_label = :p ORDER BY department", [':p' => $period_label]);
} else {
    $budget_rows = dash_rows($pdo, "SELECT * FROM procurement_budgets WHERE period_label = :p AND department = :d", [':p' => $period_label, ':d' => $user['role']]);
}

// Recent procurement activity.
$recent_activity = $can['audit']
    ? dash_rows($pdo, "
        SELECT l.*, u.firstname, u.lastname, u.username
        FROM procurement_audit_log l
        LEFT JOIN users u ON u.id = l.performed_by
        ORDER BY l.created_at DESC LIMIT 8
    ")
    : [];

// Supplier leaderboard (top 4 by rating).
$top_suppliers = ($can['suppliers'] || $can['rate'] || $can['po'])
    ? dash_rows($pdo, "SELECT * FROM suppliers WHERE rating_count > 0 ORDER BY rating_avg DESC, rating_count DESC LIMIT 4")
    : [];

// My own recent requisitions (requester view).
$my_recent_reqs = dash_rows($pdo, "
    SELECT id, title, department, estimated_total, status, created_at
    FROM purchase_requisitions WHERE requested_by = :u ORDER BY created_at DESC LIMIT 5
", [':u' => $user['id']]);

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Procurement Dashboard — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
    .kpi-grid .stat-card { position:relative; cursor:pointer; transition:transform .15s ease, border-color .15s ease; }
    .kpi-grid .stat-card:hover { transform:translateY(-2px); border-color:var(--caramel); }
    .kpi-flag { position:absolute; top:14px; right:14px; font-size:10.5px; font-weight:800; color:var(--red); background:var(--red-lt); padding:2px 8px; border-radius:999px; }
    .quick-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
    .quick-card {
      display:flex; flex-direction:column; align-items:flex-start; gap:6px;
      background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius);
      padding:14px 16px; box-shadow:var(--shadow-card); transition:all .15s ease;
    }
    .quick-card:hover { border-color:var(--caramel); transform:translateY(-2px); }
    .quick-card .qc-icon { font-size:19px; }
    .quick-card .qc-label { font-size:12.5px; font-weight:700; color:var(--espresso); }
    .quick-card .qc-sub { font-size:11px; color:var(--text-muted); }
    .split-2 { display:grid; grid-template-columns:1.3fr 1fr; gap:18px; align-items:start; }
    .budget-row { padding:12px 0; border-bottom:1px dashed var(--border); }
    .budget-row:last-child { border-bottom:none; }
    .budget-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:6px; }
    .budget-dept { font-size:12.5px; font-weight:700; color:var(--espresso); text-transform:capitalize; }
    .budget-amt { font-size:11.5px; color:var(--text-muted); }
    .budget-bar-wrap { background:#f2e6d6; border-radius:999px; height:8px; overflow:hidden; }
    .budget-bar-fill { height:100%; background:var(--caramel,#c47d3e); }
    .budget-bar-fill.over { background:var(--red,#c62828); }
    .activity-row { display:flex; gap:10px; padding:10px 0; border-bottom:1px dashed var(--border); font-size:12.5px; }
    .activity-row:last-child { border-bottom:none; }
    .activity-dot { width:7px; height:7px; border-radius:50%; background:var(--caramel); margin-top:5px; flex-shrink:0; }
    .activity-action { font-weight:700; text-transform:capitalize; color:var(--espresso); }
    .activity-meta { color:var(--text-muted); font-size:11px; margin-top:2px; }
    .leader-row { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px dashed var(--border); }
    .leader-row:last-child { border-bottom:none; }
    .leader-rank { width:22px; height:22px; border-radius:50%; background:var(--accent-lt); color:var(--caramel); font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    @media (max-width: 1100px) {
      .kpi-grid { grid-template-columns:repeat(2,1fr); }
      .quick-grid { grid-template-columns:repeat(2,1fr); }
      .split-2 { grid-template-columns:1fr; }
    }
  </style>
</head>
<body>

<div id="page-procurement-dashboard" class="page active">
  <div class="page-header">
    <div>
      <h1>Procurement Dashboard</h1>
      <p>Requisition → Sourcing → PO → Receiving → Invoice → Payment → Closure — <?= htmlspecialchars($period_label) ?> overview</p>
    </div>
  </div>

  <div class="page-body">

    <!-- ── KPI cards — role-aware ─────────────────────────── -->
    <div class="kpi-grid">

      <?php if ($can['requisition_review']): ?>
        <div class="stat-card" onclick="window.location.href='requisitions.php'">
          <?php if ($reqs_awaiting_review > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--amber-lt);color:var(--amber)">📝</div>
          <div class="stat-label">Awaiting Review</div>
          <div class="stat-value"><?= $reqs_awaiting_review ?></div>
          <div class="stat-sub">Requisitions pending a decision</div>
        </div>
      <?php endif; ?>

      <?php if ($can['requisition_create']): ?>
        <div class="stat-card" onclick="window.location.href='requisitions.php'">
          <div class="stat-icon" style="background:var(--accent-lt);color:var(--caramel)">📋</div>
          <div class="stat-label">My Requisitions</div>
          <div class="stat-value"><?= $my_pending_req ?></div>
          <div class="stat-sub">Still awaiting a decision</div>
        </div>
      <?php endif; ?>

      <?php if ($can['rfq']): ?>
        <div class="stat-card" onclick="window.location.href='requisitions.php'">
          <?php if ($reqs_awaiting_rfq > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue)">🔍</div>
          <div class="stat-label">Ready to Source</div>
          <div class="stat-value"><?= $reqs_awaiting_rfq ?></div>
          <div class="stat-sub">Approved, no RFQ started yet</div>
        </div>
      <?php endif; ?>

      <?php if ($can['rfq'] || $can['bid_review']): ?>
        <div class="stat-card" onclick="window.location.href='rfq.php'">
          <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue)">📤</div>
          <div class="stat-label">Open RFQs</div>
          <div class="stat-value"><?= $open_rfqs ?></div>
          <div class="stat-sub">Awaiting supplier quotes</div>
        </div>
      <?php endif; ?>

      <?php if ($can['bid_review']): ?>
        <div class="stat-card" onclick="window.location.href='rfq.php'">
          <?php if ($bids_awaiting_review > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--green-lt);color:var(--green)">🏷️</div>
          <div class="stat-label">Bids to Evaluate</div>
          <div class="stat-value"><?= $bids_awaiting_review ?></div>
          <div class="stat-sub">Submitted quotes, no decision yet</div>
        </div>
      <?php endif; ?>

      <?php if ($can['po'] || $can['receiving']): ?>
        <div class="stat-card" onclick="window.location.href='purchase_orders.php'">
          <div class="stat-icon" style="background:var(--accent-lt);color:var(--caramel)">📦</div>
          <div class="stat-label">POs in Flight</div>
          <div class="stat-value"><?= $pos_in_flight ?></div>
          <div class="stat-sub">Sent or acknowledged, not delivered</div>
        </div>
      <?php endif; ?>

      <?php if ($can['receiving']): ?>
        <div class="stat-card" onclick="window.location.href='goods_receipts.php'">
          <?php if ($deliveries_awaiting > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--green-lt);color:var(--green)">🚚</div>
          <div class="stat-label">Deliveries Due</div>
          <div class="stat-value"><?= $deliveries_awaiting ?></div>
          <div class="stat-sub">POs awaiting a Goods Receipt</div>
        </div>
      <?php endif; ?>

      <?php if ($can['grn_discrepancy']): ?>
        <div class="stat-card" onclick="window.location.href='goods_receipts.php'">
          <?php if ($grn_discrepancies > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--red-lt);color:var(--red)">⚠️</div>
          <div class="stat-label">GRN Discrepancies</div>
          <div class="stat-value"><?= $grn_discrepancies ?></div>
          <div class="stat-sub">Short, damaged, or rejected items</div>
        </div>
      <?php endif; ?>

      <?php if ($can['invoice_create'] || $can['invoice_match']): ?>
        <div class="stat-card" onclick="window.location.href='invoices.php'">
          <?php if ($invoices_pending > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--amber-lt);color:var(--amber)">🧾</div>
          <div class="stat-label">Invoices Pending</div>
          <div class="stat-value"><?= $invoices_pending ?></div>
          <div class="stat-sub">Logged, not yet matched</div>
        </div>
      <?php endif; ?>

      <?php if ($can['invoice_match']): ?>
        <div class="stat-card" onclick="window.location.href='three_way_match.php'">
          <?php if ($invoices_disputed > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--red-lt);color:var(--red)">⚖️</div>
          <div class="stat-label">Match Exceptions</div>
          <div class="stat-value"><?= $invoices_disputed ?></div>
          <div class="stat-sub">Disputed 3-way matches</div>
        </div>
      <?php endif; ?>

      <?php if ($show_payments): ?>
        <div class="stat-card" onclick="window.location.href='payments.php'">
          <?php if ((int)$payments_scheduled['cnt'] > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue)">💸</div>
          <div class="stat-label">Payments Scheduled</div>
          <div class="stat-value"><?= (int)$payments_scheduled['cnt'] ?></div>
          <div class="stat-sub"><?= php_currency((float)$payments_scheduled['total']) ?> queued</div>
        </div>
      <?php endif; ?>

      <?php if ($can['rate'] || $can['close']): ?>
        <div class="stat-card" onclick="window.location.href='supplier_performace.php'">
          <?php if ($orders_ready_to_close > 0): ?><span class="kpi-flag">Action needed</span><?php endif; ?>
          <div class="stat-icon" style="background:var(--green-lt);color:var(--green)">🏁</div>
          <div class="stat-label">Ready to Close</div>
          <div class="stat-value"><?= $orders_ready_to_close ?></div>
          <div class="stat-sub">Delivered &amp; paid, awaiting rating</div>
        </div>
      <?php endif; ?>

    </div>

    <!-- ── Quick access — role-aware shortcuts ─────────────── -->
    <div>
      <div class="section-title">Quick Access</div>
      <div class="quick-grid">
        <?php if ($can['requisition_create'] || $can['requisition_review']): ?>
          <a class="quick-card" href="requisitions.php"><span class="qc-icon">📋</span><span class="qc-label">Requisitions</span><span class="qc-sub">File &amp; review requests</span></a>
        <?php endif; ?>
        <?php if ($can['rfq'] || $can['bid_review']): ?>
          <a class="quick-card" href="rfq.php"><span class="qc-icon">📤</span><span class="qc-label">RFQ &amp; Bids</span><span class="qc-sub">Source &amp; evaluate quotes</span></a>
        <?php endif; ?>
        <?php if ($can['po'] || $can['receiving']): ?>
          <a class="quick-card" href="purchase_orders.php"><span class="qc-icon">📦</span><span class="qc-label">Purchase Orders</span><span class="qc-sub">Track order fulfillment</span></a>
        <?php endif; ?>
        <?php if ($can['receiving'] || $can['grn_discrepancy']): ?>
          <a class="quick-card" href="goods_receipts.php"><span class="qc-icon">🚚</span><span class="qc-label">Goods Receiving</span><span class="qc-sub">Log deliveries (GRN)</span></a>
        <?php endif; ?>
        <?php if ($can['invoice_create'] || $can['invoice_match']): ?>
          <a class="quick-card" href="invoices.php"><span class="qc-icon">🧾</span><span class="qc-label">Invoices</span><span class="qc-sub">Log &amp; match supplier bills</span></a>
        <?php endif; ?>
        <?php if ($can['invoice_match']): ?>
          <a class="quick-card" href="three_way_match.php"><span class="qc-icon">⚖️</span><span class="qc-label">3-Way Match</span><span class="qc-sub">PO ↔ GRN ↔ Invoice</span></a>
        <?php endif; ?>
        <?php if ($can['payment']): ?>
          <a class="quick-card" href="payments.php"><span class="qc-icon">💸</span><span class="qc-label">Payments</span><span class="qc-sub">Schedule &amp; complete</span></a>
        <?php endif; ?>
        <?php if ($can['rate'] || $can['close']): ?>
          <a class="quick-card" href="supplier_performace.php"><span class="qc-icon">🏁</span><span class="qc-label">Close &amp; Rate</span><span class="qc-sub">Score supplier performance</span></a>
        <?php endif; ?>
        <?php if ($can['suppliers']): ?>
          <a class="quick-card" href="suppliers.php"><span class="qc-icon">🏢</span><span class="qc-label">Suppliers</span><span class="qc-sub">Manage supplier records</span></a>
        <?php endif; ?>
        <?php if ($can['reports']): ?>
          <a class="quick-card" href="procurement_reports.php"><span class="qc-icon">📊</span><span class="qc-label">Reports</span><span class="qc-sub">Filter &amp; export data</span></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="split-2">
      <div>
        <!-- ── Budget utilization ────────────────────────── -->
        <?php if (!empty($budget_rows)): ?>
          <div class="table-card" style="padding:18px 20px;margin-bottom:18px">
            <div class="section-title" style="margin-bottom:14px">💰 Budget Utilization — <?= htmlspecialchars($period_label) ?></div>
            <?php foreach ($budget_rows as $b):
              $alloc = (float)$b['allocated_amount']; $used = (float)$b['used_amount'];
              $pct   = $alloc > 0 ? min(100, round($used / $alloc * 100)) : ($used > 0 ? 100 : 0);
              $over  = $used > $alloc;
            ?>
              <div class="budget-row">
                <div class="budget-head">
                  <span class="budget-dept"><?= htmlspecialchars($departments[$b['department']] ?? $b['department']) ?></span>
                  <span class="budget-amt"><?= php_currency($used) ?> / <?= $alloc > 0 ? php_currency($alloc) : 'No allocation' ?></span>
                </div>
                <div class="budget-bar-wrap"><div class="budget-bar-fill <?= $over ? 'over' : '' ?>" style="width:<?= $pct ?>%"></div></div>
                <?php if ($over): ?><div class="budget-amt" style="color:var(--red);margin-top:4px">⚠️ Over allocation</div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ── My recent requisitions (requester view) ─────── -->
        <?php if ($can['requisition_create'] && !empty($my_recent_reqs)): ?>
          <div class="table-scroll-wrapper" style="margin-bottom:18px">
            <table>
              <thead><tr><th colspan="4" style="background:#fff;font-size:13px;padding-top:14px">📋 My Recent Requisitions</th></tr>
              <tr><th>Title</th><th>Est. Total</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach ($my_recent_reqs as $r): ?>
                  <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= php_currency((float)$r['estimated_total']) ?></td>
                    <td><span class="status-badge status-<?= $r['status']==='approved'||$r['status']==='awarded'?'approved':($r['status']==='rejected'?'rejected':'pending') ?>"><?= status_badge($r['status']) ?></span></td>
                    <td class="muted-cell"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <!-- ── Recent activity / audit trail ───────────────── -->
        <?php if ($can['audit']): ?>
          <div class="table-card" style="padding:18px 20px">
            <div class="section-title" style="margin-bottom:6px">🕘 Recent Activity</div>
            <?php if (empty($recent_activity)): ?>
              <p class="muted-cell" style="padding:10px 0">No activity logged yet.</p>
            <?php else: foreach ($recent_activity as $a):
              $who = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')) ?: ($a['username'] ?? 'System');
            ?>
              <div class="activity-row">
                <div class="activity-dot"></div>
                <div>
                  <span class="activity-action"><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></span>
                  — <?= htmlspecialchars($a['entity_type']) ?> #<?= (int)$a['entity_id'] ?>
                  <div class="activity-meta"><?= htmlspecialchars($who) ?> · <?= date('M d, g:i A', strtotime($a['created_at'])) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <!-- ── Supplier leaderboard ─────────────────────────── -->
        <?php if (!empty($top_suppliers)): ?>
          <div class="table-card" style="padding:18px 20px">
            <div class="section-title" style="margin-bottom:6px">🏆 Top Suppliers</div>
            <?php foreach ($top_suppliers as $i => $s): ?>
              <div class="leader-row">
                <div style="display:flex;align-items:center;gap:10px">
                  <div class="leader-rank"><?= $i + 1 ?></div>
                  <div>
                    <div style="font-size:12.5px;font-weight:700;color:var(--espresso)"><?= htmlspecialchars($s['name']) ?></div>
                    <div class="muted-cell" style="font-size:11px"><?= $s['rating_count'] ?> rating(s)</div>
                  </div>
                </div>
                <div style="font-size:13px;font-weight:800;color:var(--caramel)"><?= number_format((float)$s['rating_avg'], 2) ?>/5</div>
              </div>
            <?php endforeach; ?>
            <?php if ($can['suppliers'] || $can['rate']): ?>
              <p style="margin-top:12px"><a href="supplier_performace.php" style="font-size:12px;color:var(--caramel);font-weight:600">View full leaderboard →</a></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

</body>
</html>