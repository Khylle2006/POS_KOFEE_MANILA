<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
if (!has_permission('procurement.requisitions')
  && !has_permission('procurement.requisition.create')
  && !has_permission('procurement.requisition.review')) {
  header('Location: no_access.php?reason=forbidden');
  exit;
}


$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Current quarter label, e.g. "2026-Q3" — matches the seeded budget rows
// and keeps working correctly as time passes without hardcoding a year.
$period_label = date('Y') . '-Q' . (int)ceil((int)date('n') / 3);

$departments = ['manager' => 'Operations', 'crew' => 'Crew', 'finance' => 'Finance', 'hr' => 'HR', 'admin' => 'Admin'];

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        require_permission('procurement.requisition.create');

        $title = trim($_POST['title'] ?? '');
        $dept  = $_POST['department'] ?? ($user['role'] ?? 'crew');
        $notes = trim($_POST['notes'] ?? '');
        $items = json_decode($_POST['items'] ?? '[]', true) ?: [];

        if (!$title || empty($items)) {
            $toast = 'Title and at least one item are required.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $total = 0;
                foreach ($items as $it) {
                    $total += (float)($it['qty'] ?? 0) * (float)($it['price'] ?? 0);
                }

                $pdo->prepare('
                    INSERT INTO purchase_requisitions (requested_by, department, title, notes, estimated_total, status)
                    VALUES (:u, :d, :t, :n, :et, "pending")
                ')->execute([':u'=>$user['id'], ':d'=>$dept, ':t'=>$title, ':n'=>$notes, ':et'=>$total]);
                $req_id = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare('
                    INSERT INTO requisition_items (requisition_id, item_name, quantity, unit, est_unit_price)
                    VALUES (:r, :n, :q, :u, :p)
                ');
                foreach ($items as $it) {
                    $ins->execute([
                        ':r' => $req_id,
                        ':n' => trim($it['name'] ?? ''),
                        ':q' => (float)($it['qty'] ?? 1),
                        ':u' => trim($it['unit'] ?? 'pcs') ?: 'pcs',
                        ':p' => (float)($it['price'] ?? 0),
                    ]);
                }
                 $pdo->commit();

                notify_role_by_permission(
                    'procurement.requisition.review', 'requisition_filed',
                    'New requisition awaiting review',
                    htmlspecialchars($title) . ' — ' . php_currency($total),
                    'requisitions.php', $user['id']
                );

                $toast = 'Requisition submitted for review!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'review') {
        require_permission('procurement.requisition.review');

        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['review_notes'] ?? '');

        if ($id && in_array($status, ['approved','rejected'], true)) {
            $req = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id=:id');
            $req->execute([':id'=>$id]);
            $req = $req->fetch();

            if ($req && $req['status'] === 'pending') {
                $override_note = trim($_POST['budget_override_note'] ?? '');

                if ($status === 'approved') {
                    $budget_check = check_budget_availability($req['department'], (float)$req['estimated_total'], $period_label);

                    if (!$budget_check['ok'] && !$override_note) {
                        $toast = 'This exceeds the department\'s remaining budget ('
                               . php_currency($budget_check['remaining']) . ' left). '
                               . 'Add an override note to approve anyway, or reject / ask for reallocation.';
                        $toast_type = 'error';
                        header('Location: requisitions.php?toast=' . urlencode($toast) . '&type=error');
                        exit;
                    }

                    // Approve: just records the reviewer, timestamp, decision, and
                    // optional reason. Supplier selection, RFQ invitations, and any
                    // contract paperwork happen later, starting at the RFQ stage.
                    $pdo->prepare('
                        UPDATE purchase_requisitions
                        SET status=:s, reviewed_by=:u, reviewed_at=NOW(), review_notes=:n
                        WHERE id=:id
                    ')->execute([
                        ':s' => $status, ':u' => $user['id'], ':n' => $notes, ':id' => $id,
                    ]);

                    // Consume budget immediately
                    reserve_budget($req['department'], (float)$req['estimated_total'], $period_label);
                    if (!$budget_check['ok'] && $override_note) {
                        audit_log('requisition', $id, 'approved_over_budget', $override_note);
                    }

                    notify_role_by_permission(
                        'procurement.rfq.manage', 'requisition_approved',
                        'Requisition approved — ready for RFQ',
                        htmlspecialchars($req['title']) . ' can now go out for supplier quotes.',
                        'rfq.php?requisition_id=' . $id, $user['id']
                    );

                    $toast = 'Requisition approved. Start an RFQ to invite suppliers.';
                } else {
                    // Rejected
                    $pdo->prepare('
                        UPDATE purchase_requisitions
                        SET status=:s, reviewed_by=:u, reviewed_at=NOW(), review_notes=:n
                        WHERE id=:id
                    ')->execute([':s'=>$status, ':u'=>$user['id'], ':n'=>$notes, ':id'=>$id]);
                    $toast = 'Requisition rejected.';
                }
            }
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: requisitions.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Budget strip ───────────────────────────────
// Reviewers see every department; everyone else sees just their own.
$can_create = has_permission('procurement.requisition.create') || has_permission('procurement.requisitions');
$can_review = has_permission('procurement.requisition.review');
if ($can_review) {
    $budgets = $pdo->prepare('SELECT * FROM procurement_budgets WHERE period_label=:p ORDER BY department');
    $budgets->execute([':p'=>$period_label]);
} else {
    $budgets = $pdo->prepare('SELECT * FROM procurement_budgets WHERE period_label=:p AND department=:d');
    $budgets->execute([':p'=>$period_label, ':d'=>$user['role']]);
}
$budgets = $budgets->fetchAll();

// ── Requisition list ───────────────────────────
$filter = $_GET['status'] ?? 'all';
$where  = '1=1';
$params = [':p' => $period_label];
if (!$can_review) { $where .= ' AND pr.requested_by = :uid'; $params[':uid'] = $user['id']; }
if (in_array($filter, ['pending','approved','rejected','sourcing','awarded','closed'], true)) {
    $where .= ' AND pr.status = :st'; $params[':st'] = $filter;
}
unset($params[':p']); // not used in this query — keep param list clean

$stmt = $pdo->prepare("
    SELECT pr.*, u.firstname, u.lastname
    FROM purchase_requisitions pr
    JOIN users u ON u.id = pr.requested_by
    WHERE $where
    ORDER BY FIELD(pr.status,'pending','approved','sourcing','awarded','rejected','closed'), pr.created_at DESC
");
$stmt->execute($params);
$requisitions = $stmt->fetchAll();

// Pre-load items for the view/review modal (grouped by requisition_id)
$all_items = [];
if ($requisitions) {
    $ids = array_column($requisitions, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $itq = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id IN ($in)");
    $itq->execute($ids);
    foreach ($itq->fetchAll() as $it) {
        $all_items[$it['requisition_id']][] = $it;
    }
}
foreach ($requisitions as &$r) {
    $r['items'] = $all_items[$r['id']] ?? [];
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Purchase Requisitions — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
  <style>
    .item-row { display:grid; grid-template-columns:minmax(0,1fr) 90px 70px 120px 32px; gap:8px; margin-bottom:8px; align-items:center; }
    .item-row input { min-width:0; width:100%; }
    .item-row .rm-item { background:var(--red-lt); color:var(--red); border:none; border-radius:8px; width:32px; height:32px; flex-shrink:0; cursor:pointer; }
    @media (max-width:560px) {
      .item-row { grid-template-columns:minmax(0,1fr) 80px 32px; }
      .item-row .name { grid-column:1 / -1; }
      .item-row .qty { grid-column:1 / 2; grid-row:2; }
      .item-row .unit { grid-column:2 / 3; grid-row:2; }
      .item-row .price { grid-column:1 / 3; grid-row:3; }
      .item-row .rm-item { grid-column:3 / 4; grid-row:2; }
    }
    .budget-bar-wrap { background:#f2e6d6; border-radius:999px; height:8px; overflow:hidden; margin-top:6px; }
    .budget-bar-fill { height:100%; background:var(--accent, var(--caramel, #c47d3e)); }

    /* Offline status ribbon & badges */
    .offline-status-ribbon {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 18px;
      border-radius: var(--radius-sm, 10px);
      margin-bottom: 16px;
      font-size: 13px;
      font-weight: 600;
      transition: all .2s ease;
    }
    .offline-status-ribbon.online {
      background: #E8F5E9;
      border: 1px solid #C8E6C9;
      color: #2E7D32;
    }
    .offline-status-ribbon.offline {
      background: #FFF8E1;
      border: 1px solid #FFE082;
      color: #B78103;
    }
    .offline-status-ribbon.has-pending {
      background: #FFF3CD;
      border: 1px solid #FFEBAA;
      color: #856404;
    }
    .osr-left { display: flex; align-items: center; gap: 9px; }
    .osr-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }
    .osr-dot.dot-online { background: #2E7D32; box-shadow: 0 0 0 3px rgba(46,125,50,0.2); }
    .osr-dot.dot-offline { background: #B78103; box-shadow: 0 0 0 3px rgba(183,129,3,0.25); animation: osr-pulse 1.8s infinite; }
    @keyframes osr-pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(1.15); } }
    .osr-btn-sync {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      background: var(--caramel, #c47d3e);
      color: #fff;
      border: none;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: all .15s ease;
    }
    .osr-btn-sync:hover { opacity: 0.9; }
    .osr-btn-sync:disabled { opacity: 0.6; cursor: not-allowed; }
    .badge-offline {
      background: #FFF3CD;
      color: #856404;
      border: 1px solid #FFEBAA;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-requisitions" class="page active">
  <div class="page-header">
    <div>
      <h1>Purchase Requisitions</h1>
      <p>Request supplies and review budget-checked requests</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <?php if ($can_review || has_permission('procurement.budget.manage')): ?>
      <a href="finance_budgets.php" class="btn-cancel" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
        <?= icon('scale', 14) ?> <span>Budget Allocations</span>
      </a>
      <?php endif; ?>
      <?php if ($can_create): ?>
      <button type="button" class="btn-save" onclick="openCreate()" style="display:inline-flex;align-items:center;gap:6px">
        <?= icon('plus', 14) ?> <span>File Requisition</span>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Budget strip -->
    <div class="stat-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <?php if (empty($budgets)): ?>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea"><?= icon('coin', 18) ?></div><div><div class="mini-stat-val">—</div><div class="mini-stat-lbl">No budget set for <?= htmlspecialchars($period_label) ?></div></div></div>
      <?php else: foreach ($budgets as $b):
        $remaining = $b['allocated_amount'] - $b['used_amount'];
        $pct = $b['allocated_amount'] > 0 ? min(100, ($b['used_amount'] / $b['allocated_amount']) * 100) : 0;
      ?>
        <div class="mini-stat" style="flex-direction:column;align-items:stretch;gap:4px">
          <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase">
            <span><?= htmlspecialchars($departments[$b['department']] ?? $b['department']) ?></span>
            <span><?= htmlspecialchars($period_label) ?></span>
          </div>
          <div style="font-size:16px;font-weight:800;color:var(--espresso)">
            ₱<?= number_format($remaining,2) ?> <span style="font-size:11px;color:var(--text-muted);font-weight:600">left of ₱<?= number_format($b['allocated_amount'],2) ?></span>
          </div>
          <div class="budget-bar-wrap"><div class="budget-bar-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="filter-bar" style="padding:0">
      <a href="requisitions.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
      <a href="requisitions.php?status=pending" class="filter-pill <?= $filter==='pending'?'active':'' ?>">Pending</a>
      <a href="requisitions.php?status=approved" class="filter-pill <?= $filter==='approved'?'active':'' ?>">Approved</a>
      <a href="requisitions.php?status=rejected" class="filter-pill <?= $filter==='rejected'?'active':'' ?>">Rejected</a>
    </div>

    <div class="table-scroll-hint">
      <span><?= icon('chevron-right', 12) ?> Swipe to view all 6 columns</span>
    </div>

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr><th class="col-sticky">Title</th><th>Department</th><th>Requested By</th><th>Est. Total</th><th>Status</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody id="req-tbody">
        <?php if (empty($requisitions)): ?>
          <tr class="empty-row" id="req-empty-row"><td colspan="7"><?= icon('inbox', 18) ?> No requisitions found.</td></tr>
        <?php else: foreach ($requisitions as $r):
          $r_payload_json = htmlspecialchars(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
          <tr id="req-row-<?= $r['id'] ?>">
            <td class="col-sticky" style="font-weight:700"><?= htmlspecialchars($r['title']) ?></td>
            <td><?= htmlspecialchars($departments[$r['department']] ?? $r['department']) ?></td>
            <td><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?></td>
            <td style="font-weight:700">₱<?= number_format($r['estimated_total'],2) ?></td>
            <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
            <td class="muted-cell"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            <td>
              <div class="act-group">
                <button type="button" class="act-btn" data-req="<?= $r_payload_json ?>" onclick='openView(this)'><?= icon('eye', 13) ?> View</button>
                <?php if ($r['status'] === 'approved' && has_permission('procurement.rfq.manage')): ?>
                  <button type="button" class="act-btn act-activate" onclick="window.location.href='rfq.php?requisition_id=<?= $r['id'] ?>'"><?= icon('send', 13) ?> Start RFQ</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create modal -->
<div class="modal-overlay" id="create-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3><?= icon('plus', 16) ?> File Purchase Requisition</h3>
      <button class="modal-close" onclick="closeCreate()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="create-form">
      <input type="hidden" name="action" value="create"/>
      <input type="hidden" name="items" id="items-json"/>
      <div class="modal-body">
        <div id="create-form-error" style="display:none;margin:0 0 12px;padding:10px 14px;border-radius:var(--radius-sm);background:var(--red-lt);color:var(--red);font-size:12.5px;font-weight:600"></div>
        <div class="field-group">
          <label class="field-label">Title <span style="color:var(--red)">*</span></label>
          <input class="field-input" type="text" name="title" id="f-title" placeholder="e.g. Q3 espresso machine parts" required/>
        </div>
        <div class="field-group">
          <label class="field-label">Department</label>
          <select class="field-input" name="department">
            <?php foreach ($departments as $key => $label): ?>
              <option value="<?= $key ?>" <?= $key === ($user['role'] ?? '') ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group">
          <label class="field-label">Notes</label>
          <textarea class="field-input" name="notes" rows="2" placeholder="Optional context for the reviewer"></textarea>
        </div>
        <div class="field-group">
          <label class="field-label">Items <span style="color:var(--red)">*</span></label>
          <div id="item-rows"></div>
          <button type="button" class="act-btn" onclick="addItemRow()" style="margin-top:4px"><?= icon('plus', 13) ?> Add Item</button>
        </div>
        <div style="text-align:right;font-weight:800;font-size:15px;color:var(--espresso);margin-top:10px">
          Estimated Total: <span id="running-total">₱0.00</span>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeCreate()">Cancel</button>
        <button type="submit" class="btn-save" id="create-submit-btn"><?= icon('check', 14) ?> Submit for Review</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Review modal -->
<div class="modal-overlay" id="view-modal">
  <div class="modal" style="max-width:580px">
    <div class="modal-header">
      <h3 id="v-title">Requisition</h3>
      <button class="modal-close" onclick="closeView()"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal-body">
      <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:10px" id="v-meta"></p>
      <div id="v-items" style="border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:8px 0;margin-bottom:10px"></div>
      <p style="font-weight:800;text-align:right;margin-bottom:10px" id="v-total"></p>
      <p id="v-notes" style="font-size:12.5px;color:var(--text-muted);font-style:italic;margin-bottom:10px"></p>

      <div id="v-review-block" style="display:none">
        <p id="v-budget-info" style="font-size:12.5px;font-weight:700;margin-bottom:10px;display:none"></p>

        <div class="field-group">
          <label class="field-label">Review / Internal Notes</label>
          <textarea class="field-input" id="v-review-notes" rows="2" placeholder="Optional — reason for approval/rejection"></textarea>
        </div>
        <div class="field-group" id="v-override-group" style="display:none">
          <label class="field-label">Budget Override Note <span style="color:var(--red)">*</span></label>
          <input class="field-input" type="text" id="v-budget-override" placeholder="Required to approve — reason this can exceed the remaining budget"/>
        </div>
      </div>
      <div id="v-reviewed-block" style="display:none;font-size:12.5px;color:var(--text-muted)"></div>
    </div>
    <div class="modal-actions" id="v-actions"></div>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
const CAN_REVIEW = <?= json_encode($can_review) ?>;
const CAN_CREATE = <?= json_encode($can_create) ?>;
const DEPT_LABELS = <?= json_encode($departments) ?>;
const BUDGETS_BY_DEPT = <?= json_encode(array_column($budgets, null, 'department')) ?>;
const CURRENT_USER = <?= json_encode([
  'id'        => (int)$user['id'],
  'firstname' => $user['firstname'] ?? '',
  'lastname'  => $user['lastname'] ?? '',
  'role'      => $user['role'] ?? 'crew'
]) ?>;
const PERIOD_LABEL = <?= json_encode($period_label) ?>;

let currentReqNeedsOverride = false;

// ── Offline Storage & Synchronization Plumbing ──
function getOfflineQueue() {
  try {
    const raw = localStorage.getItem('kofee_offline_requisitions');
    return raw ? JSON.parse(raw) : [];
  } catch (e) {
    console.warn('Error reading offline queue:', e);
    return [];
  }
}

function saveOfflineQueue(queue) {
  try {
    localStorage.setItem('kofee_offline_requisitions', JSON.stringify(queue));
  } catch (e) {
    console.error('Error saving offline queue:', e);
  }
}

function updateNetworkStatus() {
  const isOnline = navigator.onLine;
  const ribbon = document.getElementById('offline-status-bar');
  const dot = document.getElementById('osr-dot');
  const msg = document.getElementById('osr-msg');
  const syncBtn = document.getElementById('osr-btn-sync');
  const queue = getOfflineQueue();

  if (!ribbon) return;
  ribbon.style.display = 'flex';

  if (!isOnline) {
    ribbon.className = 'offline-status-ribbon offline';
    if (dot) dot.className = 'osr-dot dot-offline';
    if (msg) msg.innerHTML = `<strong>Offline Mode (No WiFi)</strong> &mdash; You can still file and view requisitions. Data is stored safely on this device (${queue.length} queued).`;
    if (syncBtn) {
      syncBtn.style.display = queue.length > 0 ? 'inline-flex' : 'none';
      syncBtn.innerHTML = `<?= icon('refresh', 13) ?> <span>Sync Queued (${queue.length})</span>`;
    }
  } else {
    if (queue.length > 0) {
      ribbon.className = 'offline-status-ribbon has-pending';
      if (dot) dot.className = 'osr-dot dot-online';
      if (msg) msg.innerHTML = `<strong>Online &bull; ${queue.length} offline requisition(s) ready to sync</strong>`;
      if (syncBtn) {
        syncBtn.style.display = 'inline-flex';
        syncBtn.innerHTML = `<?= icon('refresh', 13) ?> <span>Sync All (${queue.length})</span>`;
      }
    } else {
      ribbon.className = 'offline-status-ribbon online';
      if (dot) dot.className = 'osr-dot dot-online';
      if (msg) msg.innerHTML = `<strong>Online</strong> &bull; Requisitions work seamlessly offline without WiFi`;
      if (syncBtn) syncBtn.style.display = 'none';
    }
  }
}

function renderOfflineRequisitions() {
  const tbody = document.getElementById('req-tbody');
  if (!tbody) return;

  // Remove existing rendered offline rows
  tbody.querySelectorAll('.offline-req-row').forEach(el => el.remove());

  const queue = getOfflineQueue();
  if (!queue.length) return;

  // Hide empty state if present
  const emptyRow = document.getElementById('req-empty-row');
  if (emptyRow) emptyRow.style.display = 'none';

  queue.forEach(r => {
    const tr = document.createElement('tr');
    tr.className = 'offline-req-row';
    tr.id = 'req-offline-' + r.temp_id;
    tr.style.background = '#FFFDF7';

    const rJson = esc(JSON.stringify(r));

    tr.innerHTML = `
      <td class="col-sticky" style="font-weight:700">
        ${esc(r.title)}
        <div style="margin-top:2px"><span class="badge-offline">⚡ Queued Offline</span></div>
      </td>
      <td>${esc(DEPT_LABELS[r.department] || r.department)}</td>
      <td>${esc(r.firstname || '')} ${esc(r.lastname || '')} <small style="color:var(--text-muted)">(Local Device)</small></td>
      <td style="font-weight:700">₱${parseFloat(r.estimated_total || 0).toFixed(2)}</td>
      <td><span class="badge-offline">Pending Sync</span></td>
      <td class="muted-cell">${esc(r.created_at || 'Just now')}</td>
      <td>
        <div class="act-group">
          <button type="button" class="act-btn" data-req="${rJson}" onclick="openView(this)"><?= icon('eye', 13) ?> View</button>
          <button type="button" class="act-btn" style="color:var(--red)" onclick="deleteOfflineRequisition('${r.temp_id}')" title="Delete offline draft"><?= icon('trash', 13) ?></button>
          <button type="button" class="act-btn act-activate" onclick="syncOfflineQueue()" title="Sync now"><?= icon('refresh', 13) ?></button>
        </div>
      </td>
    `;

    tbody.insertBefore(tr, tbody.firstChild);
  });
}

function saveToOfflineAndNotify(data) {
  const tempId = 'offline_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
  const nowStr = new Date().toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

  const entry = {
    temp_id: tempId,
    id: tempId,
    title: data.title,
    department: data.department,
    notes: data.notes,
    items: data.items,
    estimated_total: data.total,
    status: 'pending',
    is_offline: true,
    created_at: nowStr,
    firstname: CURRENT_USER.firstname || 'Crew',
    lastname: CURRENT_USER.lastname || 'Member'
  };

  const queue = getOfflineQueue();
  queue.unshift(entry);
  saveOfflineQueue(queue);

  closeCreate();
  renderOfflineRequisitions();
  updateNetworkStatus();

  Swal.fire({
    title: 'Saved Offline!',
    html: `<p>Your purchase requisition <strong>"${esc(data.title)}"</strong> was stored securely on this device without WiFi.</p><p style="font-size:12.5px;color:#7A4100;margin-top:8px;background:#FFF3CD;padding:8px 12px;border-radius:8px">It will automatically synchronize as soon as WiFi returns, or tap <strong>Sync All</strong> anytime.</p>`,
    icon: 'info',
    confirmButtonColor: '#C97B3D'
  });
}

async function syncOfflineQueue(isAuto = false) {
  const queue = getOfflineQueue();
  if (!queue.length) {
    if (!isAuto) {
      Swal.fire({ title: 'All Synced!', text: 'There are no pending offline requisitions.', icon: 'info', timer: 1400, showConfirmButton: false });
    }
    return;
  }

  if (!navigator.onLine) {
    if (!isAuto) {
      Swal.fire({ title: 'Still Offline', text: 'Please connect to WiFi before synchronizing.', icon: 'warning', confirmButtonColor: '#C97B3D' });
    }
    return;
  }

  const syncBtn = document.getElementById('osr-btn-sync');
  if (syncBtn) {
    syncBtn.disabled = true;
    syncBtn.innerHTML = `<?= icon('refresh', 13) ?> <span>Syncing…</span>`;
  }

  try {
    const res = await fetch('../api/requisitions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'batch_sync',
        requisitions: queue
      })
    });
    const result = await res.json();

    if (result.ok && Array.isArray(result.synced)) {
      const syncedTempIds = result.synced.map(s => s.temp_id);
      const remaining = queue.filter(q => !syncedTempIds.includes(q.temp_id));
      saveOfflineQueue(remaining);

      updateNetworkStatus();
      renderOfflineRequisitions();

      Swal.fire({
        title: 'Sync Complete!',
        text: `${result.synced.length} offline requisition(s) synchronized to the server!`,
        icon: 'success',
        confirmButtonColor: '#C97B3D',
        timer: 1800,
        showConfirmButton: false
      }).then(() => location.reload());
    } else {
      throw new Error(result.error || 'Failed to sync batch');
    }
  } catch (err) {
    console.error('Batch sync error:', err);
    if (!isAuto) {
      Swal.fire({
        title: 'Sync Notice',
        text: 'Unable to complete server upload right now. Your requisitions remain safely preserved on this device.',
        icon: 'error',
        confirmButtonColor: '#C97B3D'
      });
    }
  } finally {
    if (syncBtn) syncBtn.disabled = false;
    updateNetworkStatus();
  }
}

async function deleteOfflineRequisition(tempId) {
  const result = await Swal.fire({
    title: 'Remove Offline Draft?',
    text: 'Delete this pending offline requisition?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, Remove',
    confirmButtonColor: 'var(--red)',
    cancelButtonText: 'Keep'
  });
  if (result.isConfirmed) {
    const queue = getOfflineQueue().filter(q => q.temp_id !== tempId);
    saveOfflineQueue(queue);
    renderOfflineRequisitions();
    updateNetworkStatus();
  }
}

// ── Dynamic Item Rows in Create Modal ───────────
function addItemRow(vals = {}) {
  const wrap = document.getElementById('item-rows');
  const row = document.createElement('div');
  row.className = 'item-row';
  const name  = esc(vals.name ?? '');
  const qty   = esc(vals.qty ?? '');
  const unit  = esc(vals.unit ?? '');
  const price = esc(vals.price ?? '');
  row.innerHTML = `
    <input type="text" class="field-input name" placeholder="Item name" value="${name}" oninput="updateTotal()">
    <input type="number" class="field-input qty" placeholder="Qty" min="0.1" step="0.1" value="${qty}" oninput="updateTotal()">
    <input type="text" class="field-input unit" placeholder="Unit" value="${unit}">
    <input type="number" class="field-input price" placeholder="Est. ₱/unit" min="0" step="0.01" value="${price}" oninput="updateTotal()">
    <button type="button" class="rm-item" onclick="this.closest('.item-row').remove(); updateTotal();"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
  `;
  wrap.appendChild(row);
  updateTotal();
}

function updateTotal() {
  let total = 0;
  document.querySelectorAll('#item-rows .item-row').forEach(row => {
    const qty   = parseFloat(row.querySelector('.qty').value) || 0;
    const price = parseFloat(row.querySelector('.price').value) || 0;
    total += qty * price;
  });
  document.getElementById('running-total').textContent = '₱' + total.toFixed(2);
}

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── View / Review Modal ────────────────────────
function openView(param) {
  let r = null;
  if (param && param.getAttribute && param.getAttribute('data-req')) {
    try { r = JSON.parse(param.getAttribute('data-req')); } catch(e) { console.error(e); }
  } else if (typeof param === 'string') {
    try { r = JSON.parse(param); } catch(e) { console.error(e); }
  } else if (typeof param === 'object' && param !== null) {
    r = param;
  }
  if (!r) return;

  document.getElementById('v-title').textContent = r.title + (r.is_offline ? ' [Offline Draft]' : '');
  document.getElementById('v-meta').textContent =
    (DEPT_LABELS[r.department] || r.department) + ' · ' + (r.firstname || '') + ' ' + (r.lastname || '') +
    (r.is_offline ? ' · (Stored Locally on this device)' : ' · ' + (r.created_at || ''));

  const itemsList = r.items || [];
  document.getElementById('v-items').innerHTML = itemsList.map(i => {
    const qty = parseFloat(i.quantity ?? i.qty ?? 1);
    const unitPrice = parseFloat(i.est_unit_price ?? i.price ?? 0);
    const lineTotal = (qty * unitPrice).toFixed(2);
    return `
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
        <span>${esc(i.item_name || i.name)} × ${qty} ${esc(i.unit || 'pcs')}</span>
        <span>₱${lineTotal}</span>
      </div>`;
  }).join('') || '<span class="muted-cell">No items</span>';

  document.getElementById('v-total').textContent = 'Estimated Total: ₱' + parseFloat(r.estimated_total || 0).toFixed(2);
  document.getElementById('v-notes').textContent = r.notes ? '"' + r.notes + '"' : '';

  const reviewBlock    = document.getElementById('v-review-block');
  const reviewedBlock  = document.getElementById('v-reviewed-block');
  const actions        = document.getElementById('v-actions');
  const budgetInfo     = document.getElementById('v-budget-info');
  const overrideGroup  = document.getElementById('v-override-group');

  reviewBlock.style.display = 'none';
  reviewedBlock.style.display = 'none';
  budgetInfo.style.display = 'none';
  overrideGroup.style.display = 'none';
  document.getElementById('v-budget-override').value = '';
  currentReqNeedsOverride = false;
  actions.innerHTML = '';

  if (r.is_offline) {
    actions.innerHTML = `
      <button type="button" class="btn-cancel" onclick="closeView()">Close</button>
      <button type="button" class="btn-save" style="background:var(--red)" onclick="closeView(); deleteOfflineRequisition('${r.temp_id}')">Delete Draft</button>
      <button type="button" class="btn-save" onclick="syncOfflineQueue()">Sync Now</button>
    `;
  } else if (r.status === 'pending' && CAN_REVIEW) {
    reviewBlock.style.display = '';

    const budget = BUDGETS_BY_DEPT[r.department];
    const allocated = budget ? parseFloat(budget.allocated_amount) : 0;
    const used      = budget ? parseFloat(budget.used_amount) : 0;
    const remaining = allocated - used;
    currentReqNeedsOverride = parseFloat(r.estimated_total) > remaining;

    budgetInfo.style.display = '';
    budgetInfo.style.color = currentReqNeedsOverride ? 'var(--red)' : 'var(--espresso)';
    budgetInfo.textContent = (DEPT_LABELS[r.department] || r.department) + ' has ₱' + remaining.toFixed(2)
      + ' remaining this period' + (currentReqNeedsOverride ? ' — this exceeds it. An override note is required to approve.' : '.');

    overrideGroup.style.display = currentReqNeedsOverride ? '' : 'none';

    actions.innerHTML = `
      <button type="button" class="btn-cancel" onclick="closeView()">Close</button>
      <button type="button" class="btn-save" style="background:var(--red)" onclick="submitReview(${r.id}, 'rejected')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Reject</button>
      <button type="button" class="btn-save" onclick="submitReview(${r.id}, 'approved')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px"><polyline points="20 6 9 17 4 12"/></svg> Approve</button>
    `;
  } else if (r.reviewed_at) {
    reviewedBlock.style.display = '';
    reviewedBlock.textContent = 'Reviewed ' + r.reviewed_at + (r.review_notes ? ' — "' + r.review_notes + '"' : '');
    actions.innerHTML = `<button type="button" class="btn-cancel" onclick="closeView()">Close</button>`;
  } else {
    actions.innerHTML = `<button type="button" class="btn-cancel" onclick="closeView()">Close</button>`;
  }

  document.getElementById('view-modal').classList.add('open');
}

function closeView() { document.getElementById('view-modal').classList.remove('open'); }

function openCreate() {
  document.getElementById('create-modal').classList.add('open');
  const errBox = document.getElementById('create-form-error');
  if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
  if (!document.querySelectorAll('#item-rows .item-row').length) {
    addItemRow();
  }
}

function closeCreate() {
  document.getElementById('create-modal').classList.remove('open');
  if (window.KofeeValidator) {
    document.querySelectorAll('#create-form input, #create-form select, #create-form textarea').forEach(el => {
      KofeeValidator.clearError(el);
    });
  }
}

// ── Create Form Submission with Offline Interception ─────────────
document.getElementById('create-form')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const errBox = document.getElementById('create-form-error');
  if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

  const titleInput = document.getElementById('f-title');
  const titleVal   = titleInput.value.trim();
  if (!titleVal) {
    if (window.KofeeValidator) {
      KofeeValidator.showError(titleInput, 'Title is required.');
    }
    titleInput.focus();
    return;
  }

  const deptVal  = document.querySelector('#create-form select[name="department"]')?.value || CURRENT_USER.role;
  const notesVal = document.querySelector('#create-form textarea[name="notes"]')?.value.trim() || '';

  const items = [];
  let invalidQty = false;
  document.querySelectorAll('#item-rows .item-row').forEach(row => {
    const name  = row.querySelector('.name')?.value.trim() || '';
    const qty   = parseFloat(row.querySelector('.qty')?.value) || 0;
    const unit  = row.querySelector('.unit')?.value.trim() || 'pcs';
    const price = parseFloat(row.querySelector('.price')?.value) || 0;
    if (name) {
      if (qty <= 0) invalidQty = true;
      items.push({ name, qty, unit, price });
    }
  });

  if (!items.length) {
    if (errBox) {
      errBox.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Add at least one item with a name to file this requisition.';
      errBox.style.display = 'block';
    }
    return;
  }

  if (invalidQty) {
    if (errBox) {
      errBox.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> All items must have a quantity greater than 0.';
      errBox.style.display = 'block';
    }
    return;
  }

  const btn = document.getElementById('create-submit-btn');
  if (btn && window.KofeeValidator) {
    KofeeValidator.setLoading(btn, 'Submitting…');
  }

  const total = items.reduce((sum, it) => sum + (it.qty * it.price), 0);

  // If offline, save directly without network attempt
  if (!navigator.onLine) {
    if (btn && window.KofeeValidator) KofeeValidator.clearLoading(btn);
    saveToOfflineAndNotify({ title: titleVal, department: deptVal, notes: notesVal, items, total });
    return;
  }

  // Attempt online submission via API
  try {
    const response = await fetch('../api/requisitions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'create',
        title: titleVal,
        department: deptVal,
        notes: notesVal,
        items: items
      })
    });

    const result = await response.json();
    if (btn && window.KofeeValidator) KofeeValidator.clearLoading(btn);

    if (result.ok) {
      closeCreate();
      await Swal.fire({
        title: 'Requisition Filed!',
        text: `"${titleVal}" has been submitted for review.`,
        icon: 'success',
        confirmButtonColor: '#C97B3D',
        timer: 1600,
        showConfirmButton: false
      });
      location.reload();
    } else {
      throw new Error(result.error || 'Failed to submit requisition.');
    }
  } catch (err) {
    console.warn('Network error or server unreachable while filing requisition — saving offline:', err);
    if (btn && window.KofeeValidator) KofeeValidator.clearLoading(btn);
    saveToOfflineAndNotify({ title: titleVal, department: deptVal, notes: notesVal, items, total });
  }
});

function submitReview(id, status) {
  const overrideNote = document.getElementById('v-budget-override').value.trim();
  if (status === 'approved' && currentReqNeedsOverride && !overrideNote) {
    alert('This requisition exceeds the remaining budget — add an override note to approve anyway, or reject / ask for reallocation.');
    document.getElementById('v-budget-override').focus();
    return;
  }

  const fd = new FormData();
  fd.append('action', 'review');
  fd.append('id', id);
  fd.append('status', status);
  fd.append('review_notes', document.getElementById('v-review-notes').value);
  fd.append('budget_override_note', overrideNote);

  const form = document.createElement('form');
  form.method = 'POST';
  for (const [k,v] of fd.entries()) {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = k; inp.value = v;
    form.appendChild(inp);
  }
  document.body.appendChild(form);
  form.submit();
}

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) { closeCreate(); closeView(); } });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCreate(); closeView(); } });

// ── Lifecycle Initialization: Online / Offline Listeners & Service Worker ──
document.addEventListener('DOMContentLoaded', () => {
  updateNetworkStatus();
  renderOfflineRequisitions();

  window.addEventListener('online', () => {
    updateNetworkStatus();
    syncOfflineQueue(true);
  });
  window.addEventListener('offline', () => {
    updateNetworkStatus();
  });

  // Register Service Worker for offline PWA functionality
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('../sw.js', { scope: '../' }).catch(err => {
      console.warn('Service worker registration note:', err);
    });
  }
});
</script>
<script src="../js/validator.js"></script>
</body>
</html>