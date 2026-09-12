<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_login();
require_permission('procurement.view');


$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_rfq') {
        require_permission('procurement.rfq.manage');

       $req_id    = (int)($_POST['requisition_id'] ?? 0);
        $due_date  = $_POST['due_date'] ?: null;
        $suppliers = array_map('intval', $_POST['suppliers'] ?? []);

        $req_stmt = $pdo->prepare('SELECT status FROM purchase_requisitions WHERE id = :id');
        $req_stmt->execute([':id' => $req_id]);
        $req_status = $req_stmt->fetchColumn();

        if (!$req_id || empty($suppliers)) {
            $toast = '⚠️ Invite at least one supplier.'; $toast_type = 'error';
        } elseif ($req_status !== 'approved') {
            $toast = '⚠️ Only approved requisitions can go out for RFQ.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO rfqs (requisition_id, created_by, due_date) VALUES (:r,:u,:d)')
                    ->execute([':r'=>$req_id, ':u'=>$user['id'], ':d'=>$due_date]);
                $rfq_id = (int)$pdo->lastInsertId();

              $ins = $pdo->prepare('INSERT IGNORE INTO rfq_invites (rfq_id, supplier_id) VALUES (:r,:s)');
                foreach ($suppliers as $sid) $ins->execute([':r'=>$rfq_id, ':s'=>$sid]);

                $sup_user_stmt = $pdo->prepare('SELECT user_id, name FROM suppliers WHERE id = :id');
                foreach ($suppliers as $sid) {
                    $sup_user_stmt->execute([':id' => $sid]);
                    $sup = $sup_user_stmt->fetch();
                    if ($sup && $sup['user_id']) {
                        notify_user(
                            (int)$sup['user_id'], 'rfq_invite', '📨 New RFQ invitation',
                            'You have been invited to quote on a new RFQ.',
                            'supplier_portal.php'
                        );
                    }
                }

                $pdo->prepare("UPDATE purchase_requisitions SET status='sourcing' WHERE id=:id")->execute([':id'=>$req_id]);                $pdo->commit();
                header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode('✅ RFQ created and sent to ' . count($suppliers) . ' supplier(s).'));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'record_bid') {
        require_permission('procurement.rfq.manage');

        $rfq_id  = (int)($_POST['rfq_id'] ?? 0);
        $sup_id  = (int)($_POST['supplier_id'] ?? 0);
        $total   = (float)($_POST['quoted_total'] ?? 0);
        $lead    = (int)($_POST['lead_time_days'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        if (!$rfq_id || !$sup_id || $total <= 0) {
            $toast = '⚠️ Select a supplier and enter a valid quote.'; $toast_type = 'error';
        } else {
            $pdo->prepare('
                INSERT INTO bids (rfq_id, supplier_id, quoted_total, lead_time_days, notes)
                VALUES (:r,:s,:t,:l,:n)
                ON DUPLICATE KEY UPDATE quoted_total=:t2, lead_time_days=:l2, notes=:n2
            ')->execute([
                ':r'=>$rfq_id, ':s'=>$sup_id, ':t'=>$total, ':l'=>$lead, ':n'=>$notes,
                ':t2'=>$total, ':l2'=>$lead, ':n2'=>$notes,
            ]);
            $toast = '✅ Bid recorded.';
        }
        header('Location: rfq.php?id=' . $rfq_id . ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : ''));
        exit;
    }

    if ($action === 'shortlist') {
        require_permission('procurement.bidding.review');
        $bid_id = (int)($_POST['bid_id'] ?? 0);
        $rfq_id = (int)($_POST['rfq_id'] ?? 0);
        $pdo->prepare("UPDATE bids SET status='shortlisted' WHERE id=:id")->execute([':id'=>$bid_id]);
        header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode('⭐ Bid shortlisted.'));
        exit;
    }

    if ($action === 'award') {
        require_permission('procurement.po.manage');

        $rfq_id  = (int)($_POST['rfq_id'] ?? 0);
        $bid_id  = (int)($_POST['bid_id'] ?? 0);
        $negotiation_notes = trim($_POST['negotiation_notes'] ?? '');

        try {
            $pdo->beginTransaction();

            $bid = $pdo->prepare('SELECT * FROM bids WHERE id=:id'); $bid->execute([':id'=>$bid_id]); $bid = $bid->fetch();
            $rfq = $pdo->prepare('SELECT * FROM rfqs WHERE id=:id'); $rfq->execute([':id'=>$rfq_id]); $rfq = $rfq->fetch();

            if (!$bid || !$rfq) throw new Exception('Bid or RFQ not found.');

            $pdo->prepare("UPDATE bids SET status='selected' WHERE id=:id")->execute([':id'=>$bid_id]);
            $pdo->prepare("UPDATE bids SET status='rejected' WHERE rfq_id=:r AND id != :id")->execute([':r'=>$rfq_id, ':id'=>$bid_id]);
            $pdo->prepare("UPDATE rfqs SET status='awarded' WHERE id=:id")->execute([':id'=>$rfq_id]);
            $pdo->prepare("UPDATE purchase_requisitions SET status='awarded' WHERE id=:id")->execute([':id'=>$rfq['requisition_id']]);

            $pdo->prepare('
                INSERT INTO purchase_orders (rfq_id, supplier_id, requisition_id, total_amount, status, negotiation_notes, created_by)
                VALUES (:rfq, :sup, :req, :amt, "draft", :notes, :u)
            ')->execute([
                ':rfq'=>$rfq_id, ':sup'=>$bid['supplier_id'], ':req'=>$rfq['requisition_id'],
                ':amt'=>$bid['quoted_total'], ':notes'=>$negotiation_notes, ':u'=>$user['id'],
            ]);
            $po_id = (int)$pdo->lastInsertId();

            $pdo->commit();
            header('Location: purchase_orders.php?id=' . $po_id . '&toast=' . urlencode('✅ Supplier Signed — Purchase Order created!'));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode($toast) . '&type=error');
            exit;
        }
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Route: requisition_id (new) vs id (existing RFQ) vs neither (list) ──
$rfq = null;
$requisition = null;

if (!empty($_GET['requisition_id'])) {
    $req_id = (int)$_GET['requisition_id'];
    $existing = $pdo->prepare('SELECT id FROM rfqs WHERE requisition_id=:r ORDER BY id DESC LIMIT 1');
    $existing->execute([':r'=>$req_id]);
    $existing_id = $existing->fetchColumn();
    if ($existing_id) {
        header('Location: rfq.php?id=' . $existing_id);
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id=:id AND status=\'approved\'');
    $stmt->execute([':id'=>$req_id]);
    $requisition = $stmt->fetch();
} elseif (!empty($_GET['id'])) {
    $rfq_id = (int)$_GET['id'];
    $stmt = $pdo->prepare('
        SELECT rfq.*, pr.title AS req_title, pr.department, pr.estimated_total AS req_estimated
        FROM rfqs rfq JOIN purchase_requisitions pr ON pr.id = rfq.requisition_id
        WHERE rfq.id = :id
    ');
    $stmt->execute([':id'=>$rfq_id]);
    $rfq = $stmt->fetch();
}

$active_suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();

$bids = [];
$invited = [];
if ($rfq) {
    $b = $pdo->prepare('
        SELECT bids.*, s.name AS supplier_name
        FROM bids JOIN suppliers s ON s.id = bids.supplier_id
        WHERE rfq_id = :id ORDER BY quoted_total ASC
    ');
    $b->execute([':id'=>$rfq['id']]);
    $bids = $b->fetchAll();

    $inv = $pdo->prepare('
        SELECT s.* FROM rfq_invites ri JOIN suppliers s ON s.id = ri.supplier_id WHERE ri.rfq_id = :id
    ');
    $inv->execute([':id'=>$rfq['id']]);
    $invited = $inv->fetchAll();
}

// ── List view when nothing is selected ─────────
$rfq_list = [];
if (!$rfq && !$requisition) {
    $rfq_list = $pdo->query("
        SELECT rfqs.*, pr.title AS req_title, pr.department,
               (SELECT COUNT(*) FROM bids WHERE bids.rfq_id = rfqs.id) AS bid_count
        FROM rfqs JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        ORDER BY FIELD(rfqs.status,'open','awarded','closed'), rfqs.created_at DESC
    ")->fetchAll();
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RFQ & Bidding — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .bid-row {
      display:flex; align-items:center; gap:12px; padding:12px 14px;
      border:1.5px solid var(--border); border-radius:12px; margin-bottom:8px; background:#fff;
    }
    .bid-row.top { border-color: var(--green); background:var(--green-lt); }
    .bid-rank { width:26px; height:26px; border-radius:50%; background:var(--accent-lt); color:var(--caramel); font-weight:800; font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .bid-info { flex:1; min-width:0; }
    .bid-supplier { font-weight:700; }
    .bid-meta { font-size:11.5px; color:var(--text-muted); }
    .bid-amount { font-weight:800; font-size:16px; color:var(--espresso); text-align:right; }
    .invite-checks { display:flex; flex-wrap:wrap; gap:8px; }
    .invite-opt { display:flex; align-items:center; gap:6px; border:1.5px solid var(--border); border-radius:999px; padding:6px 12px; font-size:12.5px; }
  </style>
</head>
<body>

<div id="page-rfq" class="page active">
  <div class="page-header">
    <div>
      <h1>RFQ &amp; Bidding</h1>
      <p>Invite suppliers to quote, then review and Accept the best offer</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($requisition): ?>
      <!-- ── Create RFQ for this requisition ── -->
      <div class="table-card" style="padding:20px 22px">
        <h2 style="margin-bottom:4px">Create RFQ for: <?= htmlspecialchars($requisition['title']) ?></h2>
        <p class="muted-cell" style="margin-bottom:16px">Estimated value: ₱<?= number_format($requisition['estimated_total'],2) ?></p>

        <?php if (empty($active_suppliers)): ?>
          <p class="muted-cell">No active suppliers yet. <a href="suppliers.php" style="color:var(--caramel);font-weight:700">Add one first →</a></p>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="create_rfq"/>
          <input type="hidden" name="requisition_id" value="<?= $requisition['id'] ?>"/>
          <div class="field-group mg-b">
            <label class="field-label">Quote Due Date</label>
            <input class="field-input" type="date" name="due_date" style="max-width:220px"/>
          </div>
          <div class="field-group mg-b">
            <label class="field-label">Invite Suppliers</label>
            <div class="invite-checks">
              <?php foreach ($active_suppliers as $s): ?>
                <label class="invite-opt"><input type="checkbox" name="suppliers[]" value="<?= $s['id'] ?>"/> <?= htmlspecialchars($s['name']) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="submit" class="btn-save">📨 Create &amp; Send RFQ</button>
        </form>
        <?php endif; ?>
      </div>

    <?php elseif ($rfq): ?>
      <!-- ── RFQ detail: bids, negotiation, award ── -->
      <div class="table-card" style="padding:20px 22px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
          <div>
            <h2><?= htmlspecialchars($rfq['req_title']) ?></h2>
            <p class="muted-cell">Requisition estimate: ₱<?= number_format($rfq['req_estimated'],2) ?> · Due: <?= $rfq['due_date'] ? date('M d, Y', strtotime($rfq['due_date'])) : '—' ?></p>
          </div>
          <span class="status-badge status-<?= $rfq['status']==='awarded'?'approved':'pending' ?>"><?= ucfirst($rfq['status']) ?></span>
        </div>
        <p class="muted-cell" style="margin-bottom:14px">Invited: <?= implode(', ', array_map(fn($s)=>htmlspecialchars($s['name']), $invited)) ?: '—' ?></p>

        <?php if (has_permission('procurement.rfq.manage') && $rfq['status'] === 'open'): ?>
        <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;padding:14px;background:#FBF6EF;border-radius:12px">
          <input type="hidden" name="action" value="record_bid"/>
          <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?>"/>
          <div class="field-group" style="margin:0"><label class="field-label">Supplier</label>
            <select class="field-input" name="supplier_id" required>
              <option value="">Select…</option>
              <?php foreach ($invited as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field-group" style="margin:0;max-width:140px"><label class="field-label">Quoted Total (₱)</label>
            <input class="field-input" type="number" step="0.01" min="0.01" name="quoted_total" required/></div>
          <div class="field-group" style="margin:0;max-width:110px"><label class="field-label">Lead Time (days)</label>
            <input class="field-input" type="number" min="0" name="lead_time_days" value="0"/></div>
          <div class="field-group" style="margin:0;flex:1;min-width:160px"><label class="field-label">Notes</label>
            <input class="field-input" type="text" name="notes" placeholder="Optional"/></div>
          <button type="submit" class="btn-save">➕ Record Offers</button>
        </form>
        <?php endif; ?>

        <?php if (count($bids) >= 2): $best_total = min(array_column($bids, 'quoted_total')); $best_lead = min(array_column($bids, 'lead_time_days')); ?>
        <h3 style="font-size:13.5px;margin-bottom:10px">⚖️ Compare Offers Side-by-Side</h3>
        <div class="table-scroll-wrapper" style="margin-bottom:22px">
          <table>
            <thead>
              <tr>
                <th style="width:140px">Criteria</th>
                <?php foreach ($bids as $b): ?>
                  <th><?= htmlspecialchars($b['supplier_name']) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="muted-cell" style="font-weight:600">Quoted Total</td>
                <?php foreach ($bids as $b): ?>
                  <td style="font-weight:800<?= $b['quoted_total']==$best_total ? ';color:var(--green)' : '' ?>">
                    ₱<?= number_format($b['quoted_total'],2) ?><?= $b['quoted_total']==$best_total ? ' 🏅' : '' ?>
                  </td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:600">Lead Time</td>
                <?php foreach ($bids as $b): ?>
                  <td style="font-weight:700<?= $b['lead_time_days']==$best_lead ? ';color:var(--green)' : '' ?>">
                    <?= $b['lead_time_days'] ?> day(s)<?= $b['lead_time_days']==$best_lead ? ' 🏅' : '' ?>
                  </td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:600">Notes</td>
                <?php foreach ($bids as $b): ?>
                  <td class="muted-cell"><?= $b['notes'] ? htmlspecialchars($b['notes']) : '—' ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:600">Status</td>
                <?php foreach ($bids as $b): ?>
                  <td>
                    <?php if ($b['status']==='selected'): ?><span class="status-badge status-approved">Accepted</span>
                    <?php elseif ($b['status']==='shortlisted'): ?><span class="status-badge status-pending">⭐ Shortlisted</span>
                    <?php elseif ($b['status']==='rejected'): ?><span class="status-badge status-rejected">Rejected</span>
                    <?php else: ?><span class="status-badge status-pending">Submitted</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <h3 style="font-size:13.5px;margin-bottom:10px">Offers <?= count($bids) ? '(' . count($bids) . ')' : '' ?></h3>
        <?php if (empty($bids)): ?>
          <p class="muted-cell">No offers recorded yet.</p>
        <?php else: foreach ($bids as $i => $b): ?>
          <div class="bid-row <?= $i < 3 ? 'top' : '' ?>">
            <div class="bid-rank">#<?= $i+1 ?></div>
            <div class="bid-info">
              <div class="bid-supplier"><?= htmlspecialchars($b['supplier_name']) ?>
                <?php if ($b['status']==='selected'): ?><span class="status-badge status-approved" style="margin-left:6px">Accepted Offer</span><?php endif; ?>
                <?php if ($b['status']==='shortlisted'): ?><span class="status-badge status-pending" style="margin-left:6px">⭐ Shortlisted</span><?php endif; ?>
              </div>
              <div class="bid-meta"><?= $b['lead_time_days'] ?> day lead time <?= $b['notes'] ? ' · "' . htmlspecialchars($b['notes']) . '"' : '' ?></div>
            </div>
            <div class="bid-amount">₱<?= number_format($b['quoted_total'],2) ?></div>
            <?php if ($rfq['status'] === 'open'): ?>
              <div class="act-group">
                <?php if (has_permission('procurement.bidding.review') && $b['status']==='submitted'): ?>
                  <form method="POST"><input type="hidden" name="action" value="shortlist"/><input type="hidden" name="bid_id" value="<?= $b['id'] ?>"/><input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?>"/>
                    <button type="submit" class="act-btn">⭐ Shortlist</button></form>
                <?php endif; ?>
                <?php if (has_permission('procurement.po.manage')): ?>
                  <button class="act-btn act-activate" onclick="openAward(<?= $b['id'] ?>, '<?= htmlspecialchars($b['supplier_name'], ENT_QUOTES) ?>')">Contract Signed</button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

    <?php else: ?>
      <!-- ── List of all RFQs ── -->
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>Requisition</th><th>Department</th><th>Offers</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (empty($rfq_list)): ?>
            <tr class="empty-row"><td colspan="5">🫙 No RFQs yet. Approve a requisition first, then click "Start RFQ".</td></tr>
          <?php else: foreach ($rfq_list as $r): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($r['req_title']) ?></td>
              <td><?= htmlspecialchars($r['department']) ?></td>
              <td><?= $r['bid_count'] ?></td>
              <td><span class="status-badge status-<?= $r['status']==='awarded'?'approved':'pending' ?>"><?= ucfirst($r['status']) ?></span></td>
              <td><button class="act-btn" onclick="window.location.href='rfq.php?id=<?= $r['id'] ?>'">👁 View</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- Award modal -->
<div class="modal-overlay" id="award-modal">
  <div class="modal">
    <div class="modal-header"><h3>🏆 Accept Offer & Create Purchase Order</h3><button class="modal-close" onclick="closeAward()">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="award"/>
      <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?? '' ?>"/>
      <input type="hidden" name="bid_id" id="award-bid-id"/>
      <div class="modal-body">
        <p style="margin-bottom:12px">Awarding to <strong id="award-supplier-name"></strong>. This creates a Purchase Order and closes the RFQ.</p>
        <?php if (has_permission('procurement.negotiation')): ?>
        <div class="field-group">
          <label class="field-label">Negotiation Notes</label>
          <textarea class="field-input" name="negotiation_notes" rows="3" placeholder="Final terms agreed with the supplier (price, payment terms, delivery)"></textarea>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeAward()">Cancel</button>
        <button type="submit" class="btn-save">✔ Accept Offer</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAward(bidId, supplierName) {
  document.getElementById('award-bid-id').value = bidId;
  document.getElementById('award-supplier-name').textContent = supplierName;
  document.getElementById('award-modal').classList.add('open');
}
function closeAward() { document.getElementById('award-modal').classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeAward(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAward(); });
</script>

</body>
</html>