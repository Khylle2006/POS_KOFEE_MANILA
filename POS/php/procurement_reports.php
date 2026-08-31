<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_login();
require_permission('procurement.reports.view');

$pdo  = get_db();
$user = current_user();

// ── Filters (GET-driven, self-contained — this page is read-only) ──
$report      = $_GET['report'] ?? 'requisitions';
$valid_reports = ['requisitions','rfq_bids','purchase_orders','goods_receipts','invoices','payments','suppliers'];
if (!in_array($report, $valid_reports, true)) $report = 'requisitions';

$status      = trim($_GET['status'] ?? '');
$department  = trim($_GET['department'] ?? '');
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
$method      = trim($_GET['method'] ?? '');
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to'] ?? '');

$departments = ['manager' => 'Operations', 'crew' => 'Crew', 'finance' => 'Finance', 'hr' => 'HR', 'admin' => 'Admin'];
$suppliers_list = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll();

function apply_date_range(string &$where, array &$params, string $col, string $from, string $to): void {
    if ($from !== '') { $where .= " AND $col >= :date_from"; $params[':date_from'] = $from . ' 00:00:00'; }
    if ($to   !== '') { $where .= " AND $col <= :date_to";   $params[':date_to']   = $to   . ' 23:59:59'; }
}

$columns = [];   // [key => label] for the table + CSV header, in order
$rows    = [];

try {
    switch ($report) {

        case 'requisitions':
            $where = '1=1'; $params = [];
            if ($status !== '')     { $where .= ' AND pr.status = :st'; $params[':st'] = $status; }
            if ($department !== '') { $where .= ' AND pr.department = :d'; $params[':d'] = $department; }
            apply_date_range($where, $params, 'pr.created_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT pr.id, pr.title, pr.department, u.firstname, u.lastname, u.username,
                       pr.estimated_total, pr.status, pr.created_at, pr.reviewed_at
                FROM purchase_requisitions pr
                LEFT JOIN users u ON u.id = pr.requested_by
                WHERE $where ORDER BY pr.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'ID','title'=>'Title','department'=>'Department','requester'=>'Requester','estimated_total'=>'Est. Total','status'=>'Status','created_at'=>'Filed','reviewed_at'=>'Reviewed'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['department'] = $departments[$r['department']] ?? $r['department'];
                $r['requester'] = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: ($r['username'] ?? '—');
                $r['estimated_total'] = php_currency((float)$r['estimated_total']);
                $r['created_at'] = date('M d, Y', strtotime($r['created_at']));
                $r['reviewed_at'] = $r['reviewed_at'] ? date('M d, Y', strtotime($r['reviewed_at'])) : '—';
            }
            unset($r);
            break;

        case 'rfq_bids':
            $where = '1=1'; $params = [];
            if ($status !== '') { $where .= ' AND rf.status = :st'; $params[':st'] = $status; }
            apply_date_range($where, $params, 'rf.created_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT rf.id, pr.title AS req_title, rf.status, rf.due_date, rf.created_at,
                       COUNT(b.id) AS bid_count, MIN(b.quoted_total) AS lowest_bid, MAX(b.quoted_total) AS highest_bid
                FROM rfqs rf
                JOIN purchase_requisitions pr ON pr.id = rf.requisition_id
                LEFT JOIN bids b ON b.rfq_id = rf.id
                WHERE $where
                GROUP BY rf.id
                ORDER BY rf.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'RFQ #','req_title'=>'Requisition','status'=>'Status','bid_count'=>'Bids','lowest_bid'=>'Lowest Quote','highest_bid'=>'Highest Quote','due_date'=>'Due Date','created_at'=>'Created'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['lowest_bid']  = $r['lowest_bid']  !== null ? php_currency((float)$r['lowest_bid'])  : '—';
                $r['highest_bid'] = $r['highest_bid'] !== null ? php_currency((float)$r['highest_bid']) : '—';
                $r['due_date']   = $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : '—';
                $r['created_at'] = date('M d, Y', strtotime($r['created_at']));
            }
            unset($r);
            break;

        case 'purchase_orders':
            $where = '1=1'; $params = [];
            if ($status !== '')      { $where .= ' AND po.status = :st'; $params[':st'] = $status; }
            if ($supplier_id > 0)    { $where .= ' AND po.supplier_id = :sid'; $params[':sid'] = $supplier_id; }
            apply_date_range($where, $params, 'po.created_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT po.id, pr.title AS req_title, s.name AS supplier_name, po.total_amount,
                       po.status, po.created_at, po.expected_delivery_date, po.delivered_at, po.closed_at, po.supplier_rating
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                JOIN purchase_requisitions pr ON pr.id = po.requisition_id
                WHERE $where ORDER BY po.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'PO #','req_title'=>'Requisition','supplier_name'=>'Supplier','total_amount'=>'Total','status'=>'Status','created_at'=>'Created','delivered_at'=>'Delivered','closed_at'=>'Closed','supplier_rating'=>'Rating'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['total_amount'] = php_currency((float)$r['total_amount']);
                $r['created_at']   = date('M d, Y', strtotime($r['created_at']));
                $r['delivered_at'] = $r['delivered_at'] ? date('M d, Y', strtotime($r['delivered_at'])) : '—';
                $r['closed_at']    = $r['closed_at'] ? date('M d, Y', strtotime($r['closed_at'])) : '—';
                $r['supplier_rating'] = $r['supplier_rating'] ? $r['supplier_rating'] . '/5' : '—';
            }
            unset($r);
            break;

        case 'goods_receipts':
            $where = '1=1'; $params = [];
            if ($status !== '') { $where .= ' AND g.status = :st'; $params[':st'] = $status; }
            apply_date_range($where, $params, 'g.received_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT g.id, g.po_id, s.name AS supplier_name, u.firstname, u.lastname, u.username,
                       g.status, g.received_at,
                       COUNT(gi.id) AS item_count,
                       SUM(CASE WHEN gi.item_condition <> 'good' THEN 1 ELSE 0 END) AS discrepancy_count
                FROM goods_receipts g
                JOIN purchase_orders po ON po.id = g.po_id
                JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN users u ON u.id = g.received_by
                LEFT JOIN goods_receipt_items gi ON gi.grn_id = g.id
                WHERE $where GROUP BY g.id ORDER BY g.received_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'GRN #','po_id'=>'PO #','supplier_name'=>'Supplier','received_by'=>'Received By','item_count'=>'Items','discrepancy_count'=>'Discrepancies','status'=>'Status','received_at'=>'Received'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['po_id'] = '#' . str_pad($r['po_id'], 5, '0', STR_PAD_LEFT);
                $r['received_by'] = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: ($r['username'] ?? '—');
                $r['received_at'] = date('M d, Y', strtotime($r['received_at']));
            }
            unset($r);
            break;

        case 'invoices':
            $where = '1=1'; $params = [];
            if ($status !== '')   { $where .= ' AND i.status = :st'; $params[':st'] = $status; }
            if ($supplier_id > 0) { $where .= ' AND i.supplier_id = :sid'; $params[':sid'] = $supplier_id; }
            apply_date_range($where, $params, 'i.created_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT i.id, i.po_id, s.name AS supplier_name, i.invoice_number, i.invoice_date, i.due_date,
                       i.total_amount, i.status, i.created_at
                FROM invoices i
                JOIN suppliers s ON s.id = i.supplier_id
                WHERE $where ORDER BY i.created_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'Invoice','po_id'=>'PO #','supplier_name'=>'Supplier','invoice_number'=>'Invoice #','total_amount'=>'Total','status'=>'Status','invoice_date'=>'Invoice Date','due_date'=>'Due Date'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['po_id'] = '#' . str_pad($r['po_id'], 5, '0', STR_PAD_LEFT);
                $r['total_amount'] = php_currency((float)$r['total_amount']);
                $r['invoice_date'] = $r['invoice_date'] ? date('M d, Y', strtotime($r['invoice_date'])) : '—';
                $r['due_date']     = $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : '—';
            }
            unset($r);
            break;

        case 'payments':
            $where = '1=1'; $params = [];
            if ($status !== '') { $where .= ' AND p.status = :st'; $params[':st'] = $status; }
            if ($method !== '') { $where .= ' AND p.payment_method = :m'; $params[':m'] = $method; }
            apply_date_range($where, $params, 'p.scheduled_at', $date_from, $date_to);
            $stmt = $pdo->prepare("
                SELECT p.id, p.po_id, p.invoice_id, s.name AS supplier_name, p.amount, p.payment_method,
                       p.reference_no, p.status, p.scheduled_at, p.completed_at
                FROM payments p
                JOIN purchase_orders po ON po.id = p.po_id
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE $where ORDER BY p.scheduled_at DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $columns = ['id'=>'Payment','po_id'=>'PO #','supplier_name'=>'Supplier','amount'=>'Amount','payment_method'=>'Method','reference_no'=>'Reference','status'=>'Status','scheduled_at'=>'Scheduled','completed_at'=>'Completed'];
            foreach ($rows as &$r) {
                $r['id'] = '#' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
                $r['po_id'] = '#' . str_pad($r['po_id'], 5, '0', STR_PAD_LEFT);
                $r['amount'] = php_currency((float)$r['amount']);
                $r['payment_method'] = ucwords(str_replace('_', ' ', $r['payment_method']));
                $r['reference_no'] = $r['reference_no'] ?: '—';
                $r['scheduled_at'] = date('M d, Y', strtotime($r['scheduled_at']));
                $r['completed_at'] = $r['completed_at'] ? date('M d, Y', strtotime($r['completed_at'])) : '—';
            }
            unset($r);
            break;

        case 'suppliers':
            $stmt = $pdo->query("
                SELECT s.id, s.name, s.status, s.rating_avg, s.rating_count,
                       COUNT(DISTINCT po.id) AS po_count,
                       COALESCE(SUM(CASE WHEN po.status IN ('delivered','closed') THEN po.total_amount ELSE 0 END),0) AS total_spend
                FROM suppliers s
                LEFT JOIN purchase_orders po ON po.supplier_id = s.id
                GROUP BY s.id ORDER BY total_spend DESC
            ");
            $rows = $stmt->fetchAll();
            $columns = ['name'=>'Supplier','status'=>'Status','rating_avg'=>'Avg Rating','rating_count'=>'Ratings','po_count'=>'POs','total_spend'=>'Total Spend'];
            foreach ($rows as &$r) {
                $r['rating_avg'] = $r['rating_avg'] !== null ? number_format((float)$r['rating_avg'], 2) . '/5' : '—';
                $r['total_spend'] = php_currency((float)$r['total_spend']);
                $r['status'] = ucfirst($r['status']);
            }
            unset($r);
            break;
    }
} catch (Throwable $e) {
    error_log('procurement_reports query failed: ' . $e->getMessage());
    $rows = []; $columns = $columns ?: ['error' => 'Error'];
}

// ── Top summary strip (independent of the active report/filter) ──
$summary = [
    'open_requisitions' => dash_count_safe($pdo, "SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'pending'"),
    'active_pos'        => dash_count_safe($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','acknowledged','delivered')"),
    'po_value_open'     => dash_count_safe($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE status NOT IN ('closed','cancelled')", true),
    'paid_this_period'  => dash_count_safe($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'completed' AND scheduled_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)", true),
];
function dash_count_safe(PDO $pdo, string $sql, bool $float = false) {
    try {
        $v = $pdo->query($sql)->fetchColumn();
        return $float ? (float)$v : (int)$v;
    } catch (Throwable $e) { return $float ? 0.0 : 0; }
}

$report_labels = [
    'requisitions'    => ['📋 Requisitions', 'All filed purchase requisitions'],
    'rfq_bids'        => ['📤 RFQ & Bids', 'Requests for quotation and supplier bids received'],
    'purchase_orders' => ['📦 Purchase Orders', 'All purchase orders across the lifecycle'],
    'goods_receipts'  => ['🚚 Goods Receipts', 'Delivery / GRN log with discrepancy counts'],
    'invoices'        => ['🧾 Invoices', 'Supplier invoices and their match status'],
    'payments'        => ['💸 Payments', 'Scheduled and completed supplier payments'],
    'suppliers'       => ['🏢 Supplier Spend', 'Total spend and rating per supplier'],
];
$status_options = [
    'requisitions'    => ['pending','approved','rejected','sourcing','awarded','closed'],
    'rfq_bids'        => ['open','closed','awarded'],
    'purchase_orders' => ['draft','sent','acknowledged','delivered','closed','cancelled'],
    'goods_receipts'  => ['pending','partial','complete','discrepancy'],
    'invoices'        => ['pending','matched','disputed','approved','paid','cancelled'],
    'payments'        => ['scheduled','completed','failed','cancelled'],
    'suppliers'       => [],
];

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) { if ($v === '' || $v === null) unset($params[$k]); }
    return htmlspecialchars('?' . http_build_query($params));
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Procurement Reports — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .report-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:2px; }
    .filter-form { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; padding:16px 20px; background:var(--card-bg); border:1.5px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-card); }
    .filter-group { display:flex; flex-direction:column; gap:4px; }
    .filter-group label { font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; }
    .filter-group select, .filter-group input { padding:8px 10px; }
    .filter-actions { display:flex; gap:8px; margin-left:auto; }
    .mini-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
    @media (max-width: 900px) { .mini-grid { grid-template-columns:repeat(2,1fr); } }
  </style>
</head>
<body>

<div id="page-procurement-reports" class="page active">
  <div class="page-header">
    <div>
      <h1>Procurement Reports</h1>
      <p>Filter and export data across the full procurement lifecycle</p>
    </div>
  </div>

  <div class="page-body">

    <!-- ── Summary strip ─────────────────────────────── -->
    <div class="mini-grid">
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--amber-lt);color:var(--amber)">📝</div>
        <div><div class="mini-stat-val"><?= $summary['open_requisitions'] ?></div><div class="mini-stat-lbl">Open Requisitions</div></div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--blue-lt);color:var(--blue)">📦</div>
        <div><div class="mini-stat-val"><?= $summary['active_pos'] ?></div><div class="mini-stat-lbl">Active POs</div></div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--accent-lt);color:var(--caramel)">💰</div>
        <div><div class="mini-stat-val" style="font-size:15px"><?= php_currency($summary['po_value_open']) ?></div><div class="mini-stat-lbl">Open PO Value</div></div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:var(--green-lt);color:var(--green)">✅</div>
        <div><div class="mini-stat-val" style="font-size:15px"><?= php_currency($summary['paid_this_period']) ?></div><div class="mini-stat-lbl">Paid (last 90 days)</div></div>
      </div>
    </div>

    <!-- ── Report type tabs ──────────────────────────── -->
    <div class="report-tabs">
      <?php foreach ($report_labels as $key => [$label, $desc]): ?>
        <a href="procurement_reports.php?report=<?= $key ?>" class="filter-pill <?= $report===$key?'active':'' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <p class="muted-cell" style="margin-top:-10px"><?= $report_labels[$report][1] ?></p>

    <!-- ── Filters ────────────────────────────────────── -->
    <form class="filter-form" method="GET">
      <input type="hidden" name="report" value="<?= htmlspecialchars($report) ?>"/>

      <?php if (!empty($status_options[$report])): ?>
        <div class="filter-group">
          <label>Status</label>
          <select name="status" class="field-input">
            <option value="">All</option>
            <?php foreach ($status_options[$report] as $opt): ?>
              <option value="<?= $opt ?>" <?= $status===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if ($report === 'requisitions'): ?>
        <div class="filter-group">
          <label>Department</label>
          <select name="department" class="field-input">
            <option value="">All</option>
            <?php foreach ($departments as $key => $label): ?>
              <option value="<?= $key ?>" <?= $department===$key?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if (in_array($report, ['purchase_orders','invoices'], true)): ?>
        <div class="filter-group">
          <label>Supplier</label>
          <select name="supplier_id" class="field-input">
            <option value="0">All</option>
            <?php foreach ($suppliers_list as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $supplier_id===(int)$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if ($report === 'payments'): ?>
        <div class="filter-group">
          <label>Method</label>
          <select name="method" class="field-input">
            <option value="">All</option>
            <?php foreach (['bank_transfer','check','cash','online'] as $m): ?>
              <option value="<?= $m ?>" <?= $method===$m?'selected':'' ?>><?= ucwords(str_replace('_',' ',$m)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <?php if ($report !== 'suppliers'): ?>
        <div class="filter-group">
          <label>From</label>
          <input type="date" class="field-input" name="date_from" value="<?= htmlspecialchars($date_from) ?>"/>
        </div>
        <div class="filter-group">
          <label>To</label>
          <input type="date" class="field-input" name="date_to" value="<?= htmlspecialchars($date_to) ?>"/>
        </div>
      <?php endif; ?>

      <div class="filter-actions">
        <a href="procurement_reports.php?report=<?= $report ?>" class="btn-cancel" style="text-decoration:none;text-align:center">Reset</a>
        <button type="submit" class="btn-save">Apply Filters</button>
      </div>
    </form>

    <!-- ── Report table ──────────────────────────────── -->
    <div class="table-scroll-wrapper">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px 0">
        <span class="muted-cell" style="font-size:12px"><?= count($rows) ?> record(s)</span>
        <button class="act-btn" onclick="exportReportCSV()">⬇ Export CSV</button>
      </div>
      <table id="report-table">
        <thead>
          <tr><?php foreach ($columns as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="<?= count($columns) ?>">🫙 No records match these filters.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <?php foreach (array_keys($columns) as $key): ?>
                <td>
                  <?php if ($key === 'status'): ?>
                    <span class="status-badge status-<?= in_array($r[$key], ['approved','awarded','closed','matched','paid','completed','complete'], true) ? 'approved' : (in_array($r[$key], ['rejected','cancelled','failed','discrepancy','disputed'], true) ? 'rejected' : 'pending') ?>"><?= status_badge(strtolower($r[$key])) ?></span>
                  <?php else: ?>
                    <?= htmlspecialchars((string)$r[$key]) ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<script>
function exportReportCSV() {
  const table = document.getElementById('report-table');
  const rows = Array.from(table.querySelectorAll('tr')).filter(tr => !tr.querySelector('.empty-row, td[colspan]'));
  if (rows.length <= 1) { alert('No records to export.'); return; }

  const csv = rows.map(tr => {
    const cells = Array.from(tr.querySelectorAll('th,td')).map(td => {
      const text = td.innerText.trim().replace(/"/g, '""');
      return `"${text}"`;
    });
    return cells.join(',');
  }).join('\r\n');

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'procurement_<?= $report ?>_<?= date('Y-m-d') ?>.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

</body>
</html>