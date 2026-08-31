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
    $po_id  = (int)($_POST['po_id'] ?? 0);

    if ($action === 'record_receipt') {
        require_permission('procurement.receiving');

        $items = $_POST['items'] ?? []; // [requisition_item_id => ['received_qty'=>.., 'condition'=>.., 'notes'=>..]]
        $grn_notes = trim($_POST['grn_notes'] ?? '');

        $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
        $po_stmt->execute([':id' => $po_id]);
        $po = $po_stmt->fetch();

        if (!$po || !in_array($po['status'], ['sent', 'acknowledged'], true)) {
            $toast = '⚠️ This Purchase Order is not ready to receive.'; $toast_type = 'error';
        } elseif (empty($items)) {
            $toast = '⚠️ Enter received quantities for at least one item.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $has_discrepancy = false;
                $all_complete    = true;

                $grn_stmt = $pdo->prepare(
                    'INSERT INTO goods_receipts (po_id, received_by, status, notes) VALUES (:po, :u, :st, :n)'
                );
                // Placeholder status; corrected after we evaluate items below.
                $grn_stmt->execute([':po' => $po_id, ':u' => $user['id'], ':st' => 'pending', ':n' => $grn_notes]);
                $grn_id = (int)$pdo->lastInsertId();

                $item_stmt = $pdo->prepare(
                    'SELECT * FROM requisition_items WHERE id = :id'
                );
                $insert_item = $pdo->prepare(
                    'INSERT INTO goods_receipt_items
                        (grn_id, requisition_item_id, item_name, unit, ordered_qty, received_qty, item_condition, discrepancy_notes)
                     VALUES (:g, :ri, :n, :u, :oq, :rq, :c, :dn)'
                );
                // Sum of everything already received against this item across prior GRNs.
                $prior_stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(gri.received_qty),0)
                     FROM goods_receipt_items gri
                     JOIN goods_receipts g ON g.id = gri.grn_id
                     WHERE g.po_id = :po AND gri.requisition_item_id = :ri AND gri.item_condition = 'good'"
                );

                foreach ($items as $req_item_id => $data) {
                    $req_item_id = (int)$req_item_id;
                    $received    = (float)($data['received_qty'] ?? 0);
                    $condition   = in_array($data['condition'] ?? 'good', ['good','damaged','rejected'], true) ? $data['condition'] : 'good';
                    $notes       = trim($data['notes'] ?? '');

                    if ($received <= 0 && $condition === 'good') continue; // nothing entered for this line

                    $item_stmt->execute([':id' => $req_item_id]);
                    $ri = $item_stmt->fetch();
                    if (!$ri) continue;

                    $insert_item->execute([
                        ':g' => $grn_id, ':ri' => $req_item_id, ':n' => $ri['item_name'], ':u' => $ri['unit'],
                        ':oq' => $ri['quantity'], ':rq' => $received, ':c' => $condition, ':dn' => $notes ?: null,
                    ]);

                    $prior_stmt->execute([':po' => $po_id, ':ri' => $req_item_id]);
                    $already_good = (float)$prior_stmt->fetchColumn();
                    $total_good   = $already_good + ($condition === 'good' ? $received : 0);

                    if ($condition !== 'good' || $received != $ri['quantity']) {
                        $has_discrepancy = true;
                    }
                    if ($total_good < (float)$ri['quantity']) {
                        $all_complete = false;
                    }
                }

                $grn_status = $has_discrepancy ? 'discrepancy' : ($all_complete ? 'complete' : 'partial');
                $pdo->prepare('UPDATE goods_receipts SET status = :st WHERE id = :id')
                    ->execute([':st' => $grn_status, ':id' => $grn_id]);

                // If everything for the PO is now fully & cleanly received, advance the PO.
                if ($grn_status === 'complete') {
                    $pdo->prepare("UPDATE purchase_orders SET status='delivered', delivered_at=NOW() WHERE id=:id")
                        ->execute([':id' => $po_id]);
                }

                $pdo->commit();

                audit_log('grn', $grn_id, 'recorded', "PO #$po_id — status: $grn_status");

                if ($grn_status === 'discrepancy') {
                    notify_role_by_permission(
                        'procurement.grn.discrepancy.manage', 'grn_discrepancy',
                        "Delivery discrepancy on PO #$po_id",
                        'Received quantities/condition differ from what was ordered. Needs review.',
                        'goods_receipts.php?po_id=' . $po_id, $user['id']
                    );
                    $toast = '⚠️ Receipt recorded — discrepancy flagged for review.'; $toast_type = 'error';
                } elseif ($grn_status === 'partial') {
                    $toast = '📦 Partial receipt recorded. Remaining quantity still expected.';
                } else {
                    $toast = '✅ Delivery fully received — Purchase Order marked delivered.';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'resolve_discrepancy') {
        require_permission('procurement.grn.discrepancy.manage');

        $grn_id      = (int)($_POST['grn_id'] ?? 0);
        $resolution  = trim($_POST['resolution'] ?? '');
        $new_status  = in_array($_POST['new_status'] ?? '', ['complete','partial'], true) ? $_POST['new_status'] : 'partial';

        if (!$resolution) {
            $toast = '⚠️ Add a resolution note before closing this discrepancy.'; $toast_type = 'error';
        } else {
            $grn = $pdo->prepare('SELECT * FROM goods_receipts WHERE id = :id'); $grn->execute([':id' => $grn_id]); $grn = $grn->fetch();
            if ($grn) {
                $pdo->prepare("UPDATE goods_receipts SET status = :st, notes = CONCAT(COALESCE(notes,''), ' | Resolution: ', :r) WHERE id = :id")
                    ->execute([':st' => $new_status, ':r' => $resolution, ':id' => $grn_id]);

                if ($new_status === 'complete') {
                    $pdo->prepare("UPDATE purchase_orders SET status='delivered', delivered_at=COALESCE(delivered_at, NOW()) WHERE id=:id")
                        ->execute([':id' => $grn['po_id']]);
                }
                audit_log('grn', $grn_id, 'discrepancy_resolved', $resolution);
                $toast = '✅ Discrepancy resolved.';
            }
        }
    }

    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: goods_receipts.php?po_id=' . $po_id . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Detail view: one PO's receiving screen ──────────────────────────
$view_po_id = (int)($_GET['po_id'] ?? 0);
$po = null; $req_items = []; $grns = [];
if ($view_po_id) {
    $stmt = $pdo->prepare('
        SELECT po.*, s.name AS supplier_name, pr.title AS req_title, pr.department, pr.id AS requisition_id
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE po.id = :id
    ');
    $stmt->execute([':id' => $view_po_id]);
    $po = $stmt->fetch();

    if ($po) {
        $ri_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :rid');
        $ri_stmt->execute([':rid' => $po['requisition_id']]);
        $req_items = $ri_stmt->fetchAll();

        // received-so-far (good condition only) per item
        $recv_stmt = $pdo->prepare(
            "SELECT gri.requisition_item_id, SUM(gri.received_qty) AS qty
             FROM goods_receipt_items gri JOIN goods_receipts g ON g.id = gri.grn_id
             WHERE g.po_id = :po AND gri.item_condition = 'good'
             GROUP BY gri.requisition_item_id"
        );
        $recv_stmt->execute([':po' => $view_po_id]);
        $received_map = $recv_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($req_items as &$ri) {
            $ri['received_so_far'] = (float)($received_map[$ri['id']] ?? 0);
        }
        unset($ri);

        $grn_stmt = $pdo->prepare(
            "SELECT g.*, u.firstname, u.lastname FROM goods_receipts g
             LEFT JOIN users u ON u.id = g.received_by
             WHERE g.po_id = :po ORDER BY g.received_at DESC"
        );
        $grn_stmt->execute([':po' => $view_po_id]);
        $grns = $grn_stmt->fetchAll();
    }
}

// ── List view: POs ready to receive + recent GRNs ───────────────────
$ready_stmt = $pdo->query("
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE po.status IN ('sent','acknowledged')
    ORDER BY po.expected_delivery_date IS NULL, po.expected_delivery_date ASC, po.created_at DESC
");
$ready_pos = $ready_stmt->fetchAll();

$discrepancy_stmt = $pdo->query("
    SELECT g.*, po.id AS po_id, s.name AS supplier_name
    FROM goods_receipts g
    JOIN purchase_orders po ON po.id = g.po_id
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE g.status = 'discrepancy'
    ORDER BY g.received_at DESC
");
$open_discrepancies = $discrepancy_stmt->fetchAll();

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Goods Receipt — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .grn-item-row { display:grid; grid-template-columns: 1.6fr .8fr .8fr 1fr 1.4fr; gap:10px; align-items:center; padding:10px 0; border-bottom:1px dashed var(--border); }
    .grn-item-row:last-child { border-bottom:none; }
    .grn-item-name { font-weight:700; font-size:13px; }
    .grn-item-sub { font-size:11px; color:var(--text-muted); }
    .grn-hist-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:14px 16px; margin-bottom:10px; }
    .grn-hist-card.discrepancy { border-color:#e0a13a; background:#fdf6ea; }
  </style>
</head>
<body>

<div id="page-grn" class="page active">
  <div class="page-header">
    <div>
      <h1>Goods Receipt</h1>
      <p>Confirm deliveries against Purchase Orders and flag discrepancies</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($po): ?>
      <!-- ── Receive screen for one PO ── -->
      <div class="table-card" style="padding:20px 22px;margin-bottom:18px">
        <h2><?= htmlspecialchars($po['req_title']) ?></h2>
        <p class="muted-cell" style="margin-bottom:14px">PO #<?= str_pad($po['id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($po['supplier_name']) ?> · <?= htmlspecialchars($po['department']) ?></p>

        <?php if (in_array($po['status'], ['sent','acknowledged'], true) && has_permission('procurement.receiving')): ?>
        <form method="POST">
          <input type="hidden" name="action" value="record_receipt"/>
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>"/>

          <div class="grn-item-row" style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;border-bottom:1.5px solid var(--border)">
            <div>Item</div><div>Ordered</div><div>Remaining</div><div>Receiving Qty</div><div>Condition / Notes</div>
          </div>
          <?php foreach ($req_items as $ri): $remaining = max(0, $ri['quantity'] - $ri['received_so_far']); ?>
            <div class="grn-item-row">
              <div>
                <div class="grn-item-name"><?= htmlspecialchars($ri['item_name']) ?></div>
                <div class="grn-item-sub"><?= htmlspecialchars($ri['unit']) ?></div>
              </div>
              <div><?= number_format($ri['quantity'],2) ?></div>
              <div><?= number_format($remaining,2) ?></div>
              <div><input class="field-input" type="number" step="0.01" min="0" style="padding:6px 8px"
                     name="items[<?= $ri['id'] ?>][received_qty]" placeholder="0" <?= $remaining <= 0 ? 'disabled' : '' ?>/></div>
              <div style="display:flex;gap:6px">
                <select class="field-input" style="padding:6px 8px;width:110px" name="items[<?= $ri['id'] ?>][condition]">
                  <option value="good">Good</option>
                  <option value="damaged">Damaged</option>
                  <option value="rejected">Rejected</option>
                </select>
                <input class="field-input" type="text" style="padding:6px 8px" name="items[<?= $ri['id'] ?>][notes]" placeholder="Notes (optional)"/>
              </div>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:14px">
            <textarea class="field-input" name="grn_notes" placeholder="Overall receiving notes (optional)" style="width:100%;min-height:60px"></textarea>
          </div>
          <div style="margin-top:12px;text-align:right">
            <button type="submit" class="btn-save">📦 Record Receipt</button>
          </div>
        </form>
        <?php else: ?>
          <p class="muted-cell">This Purchase Order isn't awaiting receipt right now (status: <?= status_badge($po['status']) ?>).</p>
        <?php endif; ?>
      </div>

      <?php if (!empty($grns)): ?>
      <h3 style="font-size:14px;margin-bottom:10px">Receipt History</h3>
      <?php foreach ($grns as $g): ?>
        <div class="grn-hist-card <?= $g['status']==='discrepancy' ? 'discrepancy' : '' ?>">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <strong><?= status_badge($g['status']) ?></strong>
            <span class="muted-cell"><?= htmlspecialchars(trim(($g['firstname'] ?? '').' '.($g['lastname'] ?? ''))) ?: '—' ?> · <?= date('M d, Y g:i A', strtotime($g['received_at'])) ?></span>
          </div>
          <?php if ($g['notes']): ?><p style="font-size:12.5px;color:var(--text-muted)"><?= htmlspecialchars($g['notes']) ?></p><?php endif; ?>

          <?php if ($g['status']==='discrepancy' && has_permission('procurement.grn.discrepancy.manage')): ?>
            <form method="POST" style="margin-top:10px;display:flex;gap:8px;align-items:center">
              <input type="hidden" name="action" value="resolve_discrepancy"/>
              <input type="hidden" name="po_id" value="<?= $po['id'] ?>"/>
              <input type="hidden" name="grn_id" value="<?= $g['id'] ?>"/>
              <input class="field-input" type="text" name="resolution" placeholder="Resolution note (e.g. replacement requested)" style="flex:1;padding:7px 10px"/>
              <select class="field-input" name="new_status" style="width:120px;padding:7px 8px">
                <option value="partial">Mark Partial</option>
                <option value="complete">Mark Complete</option>
              </select>
              <button type="submit" class="act-btn act-activate">✔ Resolve</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <p style="margin-top:14px"><a href="goods_receipts.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Goods Receipt</a></p>

    <?php else: ?>
      <!-- ── List view ── -->
      <?php if (!empty($open_discrepancies)): ?>
      <h3 style="font-size:13.5px;margin-bottom:8px">⚠️ Open Discrepancies</h3>
      <div class="table-scroll-wrapper" style="margin-bottom:22px">
        <table>
          <thead><tr><th>PO #</th><th>Supplier</th><th>Flagged</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($open_discrepancies as $d): ?>
            <tr>
              <td style="font-weight:700">#<?= str_pad($d['po_id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($d['supplier_name']) ?></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($d['received_at'])) ?></td>
              <td><button class="act-btn" onclick="window.location.href='goods_receipts.php?po_id=<?= $d['po_id'] ?>'">👁 Review</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <h3 style="font-size:13.5px;margin-bottom:8px">📦 Awaiting Delivery</h3>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>PO #</th><th>Requisition</th><th>Supplier</th><th>Total</th><th>Status</th><th>Expected</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($ready_pos)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No Purchase Orders currently awaiting delivery.</td></tr>
          <?php else: foreach ($ready_pos as $p): ?>
            <tr>
              <td style="font-weight:700">#<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($p['req_title']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700">₱<?= number_format($p['total_amount'],2) ?></td>
              <td><span class="status-badge status-pending"><?= status_badge($p['status']) ?></span></td>
              <td class="muted-cell"><?= $p['expected_delivery_date'] ? date('M d, Y', strtotime($p['expected_delivery_date'])) : '—' ?></td>
              <td><button class="act-btn" onclick="window.location.href='goods_receipts.php?po_id=<?= $p['id'] ?>'">📦 Receive</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>