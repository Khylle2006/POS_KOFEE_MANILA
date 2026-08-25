<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('procurement.requisitions');


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
            $toast = '⚠️ Title and at least one item are required.'; $toast_type = 'error';
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
                $toast = '✅ Requisition submitted for review!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
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
                $pdo->prepare('
                    UPDATE purchase_requisitions
                    SET status=:s, reviewed_by=:u, reviewed_at=NOW(), review_notes=:n
                    WHERE id=:id
                ')->execute([':s'=>$status, ':u'=>$user['id'], ':n'=>$notes, ':id'=>$id]);

                // Approved requisitions consume budget immediately (reserved,
                // not just at payment time) so the budget strip stays honest.
                if ($status === 'approved') {
                    $pdo->prepare('
                        INSERT INTO procurement_budgets (department, period_label, allocated_amount, used_amount)
                        VALUES (:d, :p, 0, :amt)
                        ON DUPLICATE KEY UPDATE used_amount = used_amount + :amt2
                    ')->execute([
                        ':d'=>$req['department'], ':p'=>$period_label,
                        ':amt'=>$req['estimated_total'], ':amt2'=>$req['estimated_total'],
                    ]);
                }

                $toast = $status === 'approved' ? '✅ Requisition approved.' : '❌ Requisition rejected.';
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

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Purchase Requisitions — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .item-row { display:grid; grid-template-columns:minmax(0,1fr) 90px 70px 120px 32px; gap:8px; margin-bottom:8px; align-items:center; }
    .item-row input { min-width:0; width:100%; }
    .item-row .rm-item { background:var(--red-lt); color:var(--red); border:none; border-radius:8px; width:32px; height:32px; flex-shrink:0; }
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
  </style>
</head>
<body>

<div id="page-requisitions" class="page active">
  <div class="page-header">
    <div>
      <h1>Purchase Requisitions</h1>
      <p>Request supplies and review budget-checked requests</p>
    </div>
    <?php if (has_permission('procurement.requisition.create')): ?>
    <button class="btn-add" onclick="openCreate()">➕ File Requisition</button>
    <?php endif; ?>
  </div>

  <div class="page-body">

    <!-- Budget strip -->
    <div class="stat-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <?php if (empty($budgets)): ?>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea">💰</div><div><div class="mini-stat-val">—</div><div class="mini-stat-lbl">No budget set for <?= htmlspecialchars($period_label) ?></div></div></div>
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

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr><th>Title</th><th>Department</th><th>Requested By</th><th>Est. Total</th><th>Status</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($requisitions)): ?>
          <tr class="empty-row"><td colspan="7">🫙 No requisitions found.</td></tr>
        <?php else: foreach ($requisitions as $r): ?>
          <tr>
            <td style="font-weight:700"><?= htmlspecialchars($r['title']) ?></td>
            <td><?= htmlspecialchars($departments[$r['department']] ?? $r['department']) ?></td>
            <td><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?></td>
            <td style="font-weight:700">₱<?= number_format($r['estimated_total'],2) ?></td>
            <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
            <td class="muted-cell"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            <td>
              <div class="act-group">
                <button class="act-btn" onclick='openView(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>👁 View</button>
                <?php if ($r['status'] === 'approved' && has_permission('procurement.rfq.manage')): ?>
                  <button class="act-btn act-activate" onclick="window.location.href='rfq.php?requisition_id=<?= $r['id'] ?>'">📨 Start RFQ</button>
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
      <h3>➕ File Purchase Requisition</h3>
      <button class="modal-close" onclick="closeCreate()">✕</button>
    </div>
    <form method="POST" id="create-form">
      <input type="hidden" name="action" value="create"/>
      <input type="hidden" name="items" id="items-json"/>
      <div class="modal-body">
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
          <button type="button" class="act-btn" onclick="addItemRow()" style="margin-top:4px">➕ Add Item</button>
        </div>
        <div style="text-align:right;font-weight:800;font-size:15px;color:var(--espresso);margin-top:10px">
          Estimated Total: <span id="running-total">₱0.00</span>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeCreate()">Cancel</button>
        <button type="submit" class="btn-save">✔ Submit for Review</button>
      </div>
    </form>
  </div>
</div>

<!-- View / Review modal -->
<div class="modal-overlay" id="view-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 id="v-title">Requisition</h3>
      <button class="modal-close" onclick="closeView()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:10px" id="v-meta"></p>
      <div id="v-items" style="border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:8px 0;margin-bottom:10px"></div>
      <p style="font-weight:800;text-align:right;margin-bottom:10px" id="v-total"></p>
      <p id="v-notes" style="font-size:12.5px;color:var(--text-muted);font-style:italic;margin-bottom:10px"></p>

      <div id="v-review-block" style="display:none">
        <div class="field-group">
          <label class="field-label">Review Notes</label>
          <textarea class="field-input" id="v-review-notes" rows="2" placeholder="Optional — reason for approval/rejection"></textarea>
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
const DEPT_LABELS = <?= json_encode($departments) ?>;

// ── Create modal: dynamic item rows ────────────
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
    <button type="button" class="rm-item" onclick="this.closest('.item-row').remove(); updateTotal();">✕</button>
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

function openCreate() {
  document.getElementById('f-title').value = '';
  document.getElementById('item-rows').innerHTML = '';
  addItemRow();
  document.getElementById('create-modal').classList.add('open');
}
function closeCreate() { document.getElementById('create-modal').classList.remove('open'); }

document.getElementById('create-form').addEventListener('submit', function(e) {
  const items = [];
  document.querySelectorAll('#item-rows .item-row').forEach(row => {
    const name  = row.querySelector('.name').value.trim();
    const qty   = row.querySelector('.qty').value;
    const unit  = row.querySelector('.unit').value.trim();
    const price = row.querySelector('.price').value;
    if (name) items.push({ name, qty, unit, price });
  });
  if (!items.length) {
    e.preventDefault();
    alert('Add at least one item.');
    return;
  }
  document.getElementById('items-json').value = JSON.stringify(items);
});

// ── View / Review modal ────────────────────────
function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function openView(r) {
  document.getElementById('v-title').textContent = r.title;
  document.getElementById('v-meta').textContent =
    (DEPT_LABELS[r.department] || r.department) + ' · ' + r.firstname + ' ' + r.lastname + ' · ' + r.created_at;

  document.getElementById('v-items').innerHTML = (r.items || []).map(i => `
    <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
      <span>${esc(i.item_name)} × ${parseFloat(i.quantity)} ${esc(i.unit)}</span>
      <span>₱${(parseFloat(i.quantity) * parseFloat(i.est_unit_price)).toFixed(2)}</span>
    </div>`).join('') || '<span class="muted-cell">No items</span>';

  document.getElementById('v-total').textContent = 'Estimated Total: ₱' + parseFloat(r.estimated_total).toFixed(2);
  document.getElementById('v-notes').textContent = r.notes ? '"' + r.notes + '"' : '';

  const reviewBlock   = document.getElementById('v-review-block');
  const reviewedBlock = document.getElementById('v-reviewed-block');
  const actions       = document.getElementById('v-actions');
  reviewBlock.style.display = 'none';
  reviewedBlock.style.display = 'none';
  actions.innerHTML = '';

  if (r.status === 'pending' && CAN_REVIEW) {
    reviewBlock.style.display = '';
    actions.innerHTML = `
      <button type="button" class="btn-cancel" onclick="closeView()">Close</button>
      <button type="button" class="btn-save" style="background:var(--red)" onclick="submitReview(${r.id}, 'rejected')">❌ Reject</button>
      <button type="button" class="btn-save" onclick="submitReview(${r.id}, 'approved')">✅ Approve</button>
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

function submitReview(id, status) {
  const fd = new FormData();
  fd.append('action', 'review');
  fd.append('id', id);
  fd.append('status', status);
  fd.append('review_notes', document.getElementById('v-review-notes').value);
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
</script>

</body>
</html>