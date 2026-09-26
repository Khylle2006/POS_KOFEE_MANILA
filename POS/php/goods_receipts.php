<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/inventory_helpers.php';   // ← NEW
require_once '../includes/shift_guard.php';         // ← NEW
require_once '../includes/icons.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// ── POST actions ──────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $po_id  = (int)($_POST['po_id'] ?? 0);

    if ($action === 'record_receipt') {
        require_permission('procurement.receiving');

        $items = $_POST['items'] ?? []; // [requisition_item_id => ['received_qty'=>.., 'condition'=>.., 'notes'=>..]]
        $grn_notes = trim($_POST['grn_notes'] ?? '');
        $dn_id = !empty($_POST['delivery_notice_id']) ? (int)$_POST['delivery_notice_id'] : null;

        $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
        $po_stmt->execute([':id' => $po_id]);
        $po = $po_stmt->fetch();

        if (!$po || !in_array($po['status'], ['sent', 'acknowledged'], true)) {
            $toast = 'This Purchase Order is not ready to receive.'; $toast_type = 'error';
        } elseif (empty($items)) {
            $toast = 'Enter received quantities for at least one item.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $has_discrepancy = false;
                $all_complete    = true;

                $grn_stmt = $pdo->prepare(
                    'INSERT INTO goods_receipts (po_id, delivery_notice_id, received_by, status, notes) VALUES (:po, :dn, :u, :st, :n)'
                );
                // Placeholder status; corrected after we evaluate items below.
                $grn_stmt->execute([':po' => $po_id, ':dn' => $dn_id, ':u' => $user['id'], ':st' => 'pending', ':n' => $grn_notes]);
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

                // Match a received line item to an existing Inventory ingredient
                // by name (case/whitespace-insensitive). Requisition items are
                // free-typed text with no formal link to ingredients.id, so this
                // is a best-effort match, not a guaranteed one.
                // Prefer the formal requisition_items.ingredient_id link added by
                // the migration; fall back to name matching for legacy rows.
                $ing_by_id = $pdo->prepare(
                    'SELECT id, quantity, unit FROM ingredients WHERE id = :id AND archived_at IS NULL'
                );
                $ing_lookup = $pdo->prepare(
                    'SELECT id, quantity, unit FROM ingredients
                     WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n)) AND archived_at IS NULL
                     LIMIT 1'
                );
                $ing_restock = $pdo->prepare(
                    'UPDATE ingredients SET quantity = quantity + :q WHERE id = :id'
                );
                $ing_log = $pdo->prepare(
                    'INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i, :q, :u)'
                );
                $unmatched_items = []; // item names received but not found in Inventory
                $batch_ids               = []; // batches created by this receipt
                $received_ingredient_ids = []; // items whose stock moved — for reorder re-check

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

                    // Only "good" condition stock actually gets added to Inventory —
                    // damaged/rejected items were received but aren't usable.
                    if ($condition === 'good' && $received > 0) {
                        $ing = null;
                        if (!empty($ri['ingredient_id'])) {
                            $ing_by_id->execute([':id' => (int)$ri['ingredient_id']]);
                            $ing = $ing_by_id->fetch();
                        }
                        if (!$ing) {
                            $ing_lookup->execute([':n' => $ri['item_name']]);
                            $ing = $ing_lookup->fetch();
                        }

                        if ($ing) {
                            $ing_restock->execute([':q' => $received, ':id' => $ing['id']]);
                            $ing_log->execute([':i' => $ing['id'], ':q' => $received, ':u' => $user['id']]);

                            // ── PROCUREMENT RECEIPT HOOK ──
                            // Every received line becomes a tracked batch with a
                            // delivery timestamp and a computed expiry date.
                            $batch_ids[] = record_ingredient_batch(
                                (int)$ing['id'],
                                $received,
                                [
                                    'grn_id'      => $grn_id,
                                    'po_id'       => $po_id,
                                    'supplier_id' => (int)$po['supplier_id'],
                                    'batch_ref'   => 'GRN-' . $grn_id . '-' . $req_item_id,
                                    'unit'        => $ri['unit'] ?: ($ing['unit'] ?? 'pcs'),
                                    'notes'       => 'Received against PO #' . $po_id,
                                    'recorded_by' => (int)$user['id'],
                                ]
                            );

                            // Backfill the formal link so future receipts are exact.
                            if (empty($ri['ingredient_id'])) {
                                $pdo->prepare('UPDATE requisition_items SET ingredient_id = :i WHERE id = :r')
                                    ->execute([':i' => (int)$ing['id'], ':r' => $req_item_id]);
                            }

                            $received_ingredient_ids[] = (int)$ing['id'];
                        } else {
                            $unmatched_items[] = $ri['item_name'];
                        }
                    }

                    // $prior_stmt was executed after $insert_item, so it contains all good receipts including this line
                    $prior_stmt->execute([':po' => $po_id, ':ri' => $req_item_id]);
                    $total_good = (float)$prior_stmt->fetchColumn();

                    // Discrepancy occurs if item condition is damaged/rejected or total good received exceeds ordered quantity
                    if ($condition !== 'good' || $total_good > (float)$ri['quantity']) {
                        $has_discrepancy = true;
                    }
                }

                // Verify whether ALL items on this requisition are now completely received in good condition
                $check_all_stmt = $pdo->prepare("
                    SELECT ri.id, ri.quantity,
                           COALESCE((
                               SELECT SUM(gri.received_qty)
                               FROM goods_receipt_items gri
                               JOIN goods_receipts g ON g.id = gri.grn_id
                               WHERE g.po_id = :po AND gri.requisition_item_id = ri.id AND gri.item_condition = 'good'
                           ), 0) AS total_good
                    FROM requisition_items ri
                    WHERE ri.requisition_id = :rid
                ");
                $check_all_stmt->execute([':po' => $po_id, ':rid' => $po['requisition_id']]);
                $all_po_items = $check_all_stmt->fetchAll();

                $all_complete = true;
                foreach ($all_po_items as $api) {
                    if ((float)$api['total_good'] < (float)$api['quantity']) {
                        $all_complete = false;
                        break;
                    }
                }

                $grn_status = $has_discrepancy ? 'discrepancy' : ($all_complete ? 'complete' : 'partial');
                $pdo->prepare('UPDATE goods_receipts SET status = :st WHERE id = :id')
                    ->execute([':st' => $grn_status, ':id' => $grn_id]);

                // If everything for the PO is now fully & cleanly received, advance the PO.
                if ($all_complete && !$has_discrepancy) {
                    $pdo->prepare("UPDATE purchase_orders SET status='delivered', fulfillment_status='delivered', delivered_at=COALESCE(delivered_at, NOW()) WHERE id=:id")
                        ->execute([':id' => $po_id]);
                    if ($dn_id) {
                        $pdo->prepare("UPDATE delivery_notices SET fulfillment_status='delivered' WHERE id=:id")
                            ->execute([':id' => $dn_id]);
                    }
                } elseif ($grn_status === 'partial') {
                    $pdo->prepare("UPDATE purchase_orders SET fulfillment_status='partially_shipped' WHERE id=:id AND fulfillment_status != 'delivered'")
                        ->execute([':id' => $po_id]);
                }

                $pdo->commit();

                // ── POST-COMMIT SIDE EFFECTS ──
                // Receiving stock can lift an item back above its threshold, so
                // re-evaluate. Run outside the transaction so a reorder failure
                // can never roll back a valid goods receipt.
                foreach (array_unique($received_ingredient_ids) as $ing_id) {
                    check_and_trigger_reorder($ing_id, (int)$user['id']);
                }

                $batch_count = count(array_filter($batch_ids));
                if ($batch_count > 0) {
                    notify_event(
                        action_type: 'INVENTORY_RESTOCK',
                        perm_key:    'inventory.view',
                        title:       'Inventory restocked from PO #' . $po_id,
                        message:     $batch_count . ' batch(es) recorded with delivery and expiry dates.',
                        target_url:  'inventory.php',
                        entity_type: 'grn',
                        entity_id:   $grn_id
                    );
                }

                audit_log('grn', $grn_id, 'recorded', "PO #$po_id — status: $grn_status" . ($dn_id ? " (ASN #$dn_id)" : ''));

                // Notify supplier portal user if one is linked
                $s_stmt = $pdo->prepare('SELECT user_id, name FROM suppliers WHERE id = :sid');
                $s_stmt->execute([':sid' => $po['supplier_id']]);
                $sup_row = $s_stmt->fetch();
                if (!empty($sup_row['user_id'])) {
                    if ($grn_status === 'discrepancy') {
                        notify_user(
                            (int)$sup_row['user_id'],
                            'grn_discrepancy',
                            "Delivery Discrepancy Flagged on PO #{$po['id']}",
                            "Kofee Manila warehouse flagged an issue or discrepancy while receiving items for PO #{$po['id']}. Please check your portal.",
                            "supplier_portal.php?tab=orders&po_id={$po_id}"
                        );
                    } else {
                        notify_user(
                            (int)$sup_row['user_id'],
                            'grn_confirmed',
                            "Goods Receipt Confirmed on PO #{$po['id']}",
                            "Delivery items for PO #{$po['id']}" . ($dn_id ? " (ASN linked)" : "") . " have been physically received and confirmed at Kofee Manila warehouse.",
                            "supplier_portal.php?tab=orders&po_id={$po_id}"
                        );
                    }
                }

                if ($grn_status === 'discrepancy') {
                    notify_role_by_permission(
                        'procurement.grn.discrepancy.manage', 'grn_discrepancy',
                        "Delivery discrepancy on PO #$po_id",
                        'Received quantities/condition differ from what was ordered. Needs review.',
                        'goods_receipts.php?po_id=' . $po_id, $user['id']
                    );
                    $toast = 'Receipt recorded — discrepancy flagged for review.'; $toast_type = 'error';
                } elseif ($grn_status === 'partial') {
                    $toast = 'Partial receipt recorded. Remaining quantity still expected.';
                } else {
                    $toast = 'Delivery fully received — Purchase Order marked delivered. Inventory updated.';
                }

                if (!empty($unmatched_items)) {
                    $toast .= ' No matching Inventory item for: ' . implode(', ', array_unique($unmatched_items)) . ' — add it in Inventory, then restock it manually.';
                    $toast_type = 'error';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'resolve_discrepancy') {
        require_permission('procurement.grn.discrepancy.manage');

        $grn_id      = (int)($_POST['grn_id'] ?? 0);
        $resolution  = trim($_POST['resolution'] ?? '');
        $new_status  = in_array($_POST['new_status'] ?? '', ['complete','partial'], true) ? $_POST['new_status'] : 'partial';

        if (!$resolution) {
            $toast = 'Add a resolution note before closing this discrepancy.'; $toast_type = 'error';
        } else {
            $grn = $pdo->prepare('SELECT * FROM goods_receipts WHERE id = :id'); $grn->execute([':id' => $grn_id]); $grn = $grn->fetch();
            if ($grn) {
                $pdo->prepare("UPDATE goods_receipts SET status = :st, notes = CONCAT(COALESCE(notes,''), ' | Resolution: ', :r) WHERE id = :id")
                    ->execute([':st' => $new_status, ':r' => $resolution, ':id' => $grn_id]);

                if ($new_status === 'complete') {
                    $pdo->prepare("UPDATE purchase_orders SET status='delivered', fulfillment_status='delivered', delivered_at=COALESCE(delivered_at, NOW()) WHERE id=:id")
                        ->execute([':id' => $grn['po_id']]);
                }
                audit_log('grn', $grn_id, 'discrepancy_resolved', $resolution);
                $toast = 'Discrepancy resolved.';
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
$selected_asn_id = (int)($_GET['asn_id'] ?? 0);
$po = null; $req_items = []; $grns = [];
$delivery_notices = []; $dn_items_map = [];
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

        $total_remaining = 0;
        foreach ($req_items as &$ri) {
            $ri['received_so_far'] = (float)($received_map[$ri['id']] ?? 0);
            $total_remaining += max(0, (float)$ri['quantity'] - $ri['received_so_far']);
        }
        unset($ri);

        // Auto-sync: If all items for this PO have already been received, ensure PO status is 'delivered'
        if ($total_remaining <= 0 && count($req_items) > 0 && in_array($po['status'], ['sent', 'acknowledged', 'partially_shipped'], true)) {
            $pdo->prepare("UPDATE purchase_orders SET status='delivered', fulfillment_status='delivered', delivered_at=COALESCE(delivered_at, NOW()) WHERE id=:id")
                ->execute([':id' => $view_po_id]);
            $po['status'] = 'delivered';
            $po['fulfillment_status'] = 'delivered';
        }

        // Fetch delivery notices / ASNs for this PO
        $dn_stmt = $pdo->prepare('
            SELECT dn.*, 
                   (SELECT COUNT(*) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS item_count,
                   (SELECT COALESCE(SUM(dni.shipped_qty), 0) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS total_shipped_qty
            FROM delivery_notices dn
            WHERE dn.po_id = :po
            ORDER BY dn.shipped_date DESC, dn.created_at DESC
        ');
        $dn_stmt->execute([':po' => $view_po_id]);
        $delivery_notices = $dn_stmt->fetchAll();

        if (!empty($delivery_notices)) {
            $dni_stmt = $pdo->prepare('
                SELECT dni.* 
                FROM delivery_notice_items dni 
                JOIN delivery_notices dn ON dn.id = dni.delivery_notice_id
                WHERE dn.po_id = :po
            ');
            $dni_stmt->execute([':po' => $view_po_id]);
            $all_dni = $dni_stmt->fetchAll();
            foreach ($all_dni as $di) {
                $dn_items_map[$di['delivery_notice_id']][$di['requisition_item_id']] = (float)$di['shipped_qty'];
            }

            if (!$selected_asn_id) {
                foreach ($delivery_notices as $dn_cand) {
                    if ($dn_cand['fulfillment_status'] !== 'delivered') {
                        $selected_asn_id = (int)$dn_cand['id'];
                        break;
                    }
                }
                if (!$selected_asn_id) {
                    $selected_asn_id = (int)$delivery_notices[0]['id'];
                }
            }
        }

        $grn_stmt = $pdo->prepare(
            "SELECT g.*, u.firstname, u.lastname, dn.notice_ref AS asn_ref, dn.carrier_name 
             FROM goods_receipts g
             LEFT JOIN users u ON u.id = g.received_by
             LEFT JOIN delivery_notices dn ON dn.id = g.delivery_notice_id
             WHERE g.po_id = :po ORDER BY g.received_at DESC"
        );
        $grn_stmt->execute([':po' => $view_po_id]);
        $grns = $grn_stmt->fetchAll();
    }
}

// ── List view: POs ready to receive + recent GRNs + incoming ASNs ────
// Auto-sync any POs whose items have been 100% received in good condition
$pdo->exec("
    UPDATE purchase_orders po
    SET po.status = 'delivered',
        po.fulfillment_status = 'delivered',
        po.delivered_at = COALESCE(po.delivered_at, NOW())
    WHERE po.status IN ('sent', 'acknowledged', 'partially_shipped')
      AND (
          SELECT COALESCE(SUM(ri.quantity), 0)
          FROM requisition_items ri
          WHERE ri.requisition_id = po.requisition_id
      ) > 0
      AND (
          SELECT COALESCE(SUM(ri.quantity), 0)
          FROM requisition_items ri
          WHERE ri.requisition_id = po.requisition_id
      ) <= (
          SELECT COALESCE(SUM(gri.received_qty), 0)
          FROM goods_receipt_items gri
          JOIN goods_receipts g ON g.id = gri.grn_id
          WHERE g.po_id = po.id AND gri.item_condition = 'good'
      )
");

$ready_stmt = $pdo->query("
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE po.status IN ('sent','acknowledged','partially_shipped')
      AND (
          SELECT COALESCE(SUM(ri.quantity), 0) FROM requisition_items ri WHERE ri.requisition_id = po.requisition_id
      ) > (
          SELECT COALESCE(SUM(gri.received_qty), 0)
          FROM goods_receipt_items gri
          JOIN goods_receipts g ON g.id = gri.grn_id
          WHERE g.po_id = po.id AND gri.item_condition = 'good'
      )
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

$asn_list_stmt = $pdo->query("
    SELECT dn.*, 
           po.po_number, po.status AS po_status,
           pr.title AS req_title,
           s.name AS supplier_name,
           (SELECT COUNT(*) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS item_count,
           (SELECT COALESCE(SUM(dni.shipped_qty), 0) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS total_shipped_qty
    FROM delivery_notices dn
    JOIN purchase_orders po ON po.id = dn.po_id
    JOIN suppliers s ON s.id = dn.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE dn.fulfillment_status != 'delivered' AND po.status IN ('sent','acknowledged')
    ORDER BY dn.shipped_date DESC, dn.created_at DESC
");
$incoming_asns = $asn_list_stmt->fetchAll();
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
<?php include("../includes/sidebar.php"); ?>

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

        <?php if ($total_remaining <= 0): ?>
          <div style="background:var(--green-lt, #edf7ed);border:1.5px solid var(--green, #2e7d32);border-radius:var(--radius, 10px);padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div style="display:flex;align-items:center;gap:12px">
              <?= icon('check-circle', 22, '', 'color:var(--green, #2e7d32)') ?>
              <div>
                <strong style="color:var(--espresso, #2D1810);font-size:14px">All items under this Purchase Order have been fully received (0.00 remaining).</strong>
                <div style="font-size:12px;color:var(--text-muted, #718096)">Fulfillment is complete. Detailed receipts and batch entries are listed in the history below.</div>
              </div>
            </div>
            <a href="goods_receipts.php" class="act-btn act-activate" style="text-decoration:none;padding:7px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px">
              <?= icon('arrow-left', 13) ?> Back to Receipts
            </a>
          </div>

          <div class="grn-item-row" style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;border-bottom:1.5px solid var(--border)">
            <div>Item</div><div>Ordered</div><div>Received</div><div>Remaining</div><div>Status</div>
          </div>
          <?php foreach ($req_items as $ri): $remaining = max(0, $ri['quantity'] - $ri['received_so_far']); ?>
            <div class="grn-item-row">
              <div>
                <div class="grn-item-name"><?= htmlspecialchars($ri['item_name']) ?></div>
                <div class="grn-item-sub"><?= htmlspecialchars($ri['unit']) ?></div>
              </div>
              <div><?= number_format($ri['quantity'],2) ?></div>
              <div><strong><?= number_format($ri['received_so_far'],2) ?></strong></div>
              <div><?= number_format($remaining,2) ?></div>
              <div><span class="status-badge status-approved"><?= icon('check', 11) ?> Fully Received</span></div>
            </div>
          <?php endforeach; ?>

        <?php elseif (in_array($po['status'], ['sent','acknowledged','partially_shipped'], true) && has_permission('procurement.receiving')): ?>
        <form method="POST" id="receipt-form">
          <input type="hidden" name="action" value="record_receipt"/>
          <input type="hidden" name="po_id" value="<?= $po['id'] ?>"/>

          <div id="receipt-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:var(--radius-sm);background:var(--red-lt);color:var(--red);font-size:12.5px;font-weight:600"></div>

          <?php if (!empty($delivery_notices)): ?>
          <!-- Incoming ASNs / Delivery Notices -->
          <div style="background:#F7FAFC;border:1.5px solid #CBD5E0;border-radius:10px;padding:14px 16px;margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
              <strong style="color:var(--espresso);font-size:13px;display:flex;align-items:center;gap:6px">
                <?= icon('truck', 15, '', 'color:var(--caramel)') ?> Incoming Advance Shipping Notices (ASNs)
              </strong>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <label for="select-asn-id" style="font-size:12px;font-weight:600;color:var(--text-muted)">Select ASN:</label>
                <select id="select-asn-id" name="delivery_notice_id" class="field-input" style="padding:4px 10px;font-size:12px;font-weight:600" onchange="onAsnSelectChange(this.value)">
                  <option value="">-- No ASN (Direct Walk-in / Unscheduled) --</option>
                  <?php foreach ($delivery_notices as $dn): ?>
                    <option value="<?= $dn['id'] ?>" <?= ($selected_asn_id == $dn['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($dn['notice_ref']) ?> (<?= htmlspecialchars($dn['carrier_name'] ?: 'Carrier N/A') ?> — <?= number_format((float)$dn['total_shipped_qty'], 2) ?> units)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="act-btn act-activate" style="padding:5px 12px;font-size:12px" onclick="prefillFromSelectedAsn()">
                  <?= icon('check', 13) ?> Pre-fill Quantities from this ASN
                </button>
              </div>
            </div>

            <!-- Details of selected ASN -->
            <?php foreach ($delivery_notices as $dn): ?>
            <div class="asn-detail-box" id="asn-card-<?= $dn['id'] ?>" style="display:none;background:#FFF;border:1px solid #E2E8F0;border-radius:8px;padding:10px 14px;font-size:12px">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                <div>
                  <strong style="color:var(--caramel);font-size:13px"><?= htmlspecialchars($dn['notice_ref']) ?></strong>
                  <span style="margin-left:8px">Carrier: <strong><?= htmlspecialchars($dn['carrier_name'] ?: 'N/A') ?></strong></span>
                  <?php if (!empty($dn['tracking_number'])): ?>
                    <span style="margin-left:8px">Tracking: <code><?= htmlspecialchars($dn['tracking_number']) ?></code></span>
                  <?php endif; ?>
                  <span style="margin-left:8px;color:var(--text-muted)">Shipped Date: <strong><?= date('M d, Y', strtotime($dn['shipped_date'])) ?></strong></span>
                  <?php if (!empty($dn['expected_arrival_date'])): ?>
                    <span style="margin-left:8px;color:var(--text-muted)">ETA: <strong><?= date('M d, Y', strtotime($dn['expected_arrival_date'])) ?></strong></span>
                  <?php endif; ?>
                </div>
                <div>
                  <span class="status-badge status-<?= $dn['fulfillment_status'] === 'delivered' ? 'approved' : 'pending' ?>">
                    <?= ucwords(str_replace('_', ' ', $dn['fulfillment_status'])) ?>
                  </span>
                </div>
              </div>
              <?php if (!empty($dn['notes'])): ?>
                <div style="margin-top:6px;color:var(--text-muted);font-style:italic">"<?= htmlspecialchars($dn['notes']) ?>"</div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="grn-item-row" style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;border-bottom:1.5px solid var(--border)">
            <div>Item</div><div>Ordered</div><div>Remaining</div><div>Receiving Qty</div><div>Condition / Notes</div>
          </div>
          <?php foreach ($req_items as $ri): $remaining = max(0, $ri['quantity'] - $ri['received_so_far']); ?>
            <div class="grn-item-row" data-req-id="<?= $ri['id'] ?>">
              <div>
                <div class="grn-item-name"><?= htmlspecialchars($ri['item_name']) ?></div>
                <div class="grn-item-sub"><?= htmlspecialchars($ri['unit']) ?></div>
              </div>
              <div><?= number_format($ri['quantity'],2) ?></div>
              <div><?= number_format($remaining,2) ?></div>
              <div>
                <?php if ($remaining <= 0): ?>
                  <span class="status-badge status-approved" style="font-size:11px"><?= icon('check', 11) ?> Complete</span>
                  <input type="hidden" name="items[<?= $ri['id'] ?>][received_qty]" value="0"/>
                  <input type="hidden" name="items[<?= $ri['id'] ?>][condition]" value="good"/>
                <?php else: ?>
                  <input class="field-input rec-qty" id="rec-qty-<?= $ri['id'] ?>" data-remaining="<?= $remaining ?>" type="number" step="0.01" min="0" max="<?= $remaining ?>" style="padding:6px 8px;transition:all 0.3s ease"
                         name="items[<?= $ri['id'] ?>][received_qty]" value="<?= $remaining ?>" placeholder="<?= $remaining ?>"/>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:6px">
                <?php if ($remaining <= 0): ?>
                  <span class="muted-cell" style="font-size:12px;display:flex;align-items:center">—</span>
                <?php else: ?>
                  <select class="field-input" style="padding:6px 8px;width:110px" name="items[<?= $ri['id'] ?>][condition]">
                    <option value="good">Good</option>
                    <option value="damaged">Damaged</option>
                    <option value="rejected">Rejected</option>
                  </select>
                  <input class="field-input" type="text" style="padding:6px 8px" name="items[<?= $ri['id'] ?>][notes]" placeholder="Notes (optional)"/>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div style="margin-top:14px">
            <textarea class="field-input" name="grn_notes" placeholder="Overall receiving notes (optional)" style="width:100%;min-height:60px"></textarea>
          </div>
          <div style="margin-top:12px;text-align:right">
            <button type="submit" class="btn-save" id="receipt-submit-btn"><?= icon('package', 14) ?> Record Receipt</button>
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
            <div style="display:flex;align-items:center;gap:6px">
              <strong><?= status_badge($g['status']) ?></strong>
              <?php if (!empty($g['asn_ref'])): ?>
                <span class="status-badge" style="background:#EBF8FF;color:#2B6CB0;font-size:11px;display:inline-flex;align-items:center;gap:3px">
                  <?= icon('truck', 11) ?> ASN: <?= htmlspecialchars($g['asn_ref']) ?>
                </span>
              <?php endif; ?>
            </div>
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
              <button type="submit" class="act-btn act-activate"><?= icon('check', 13) ?> Resolve</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <p style="margin-top:14px"><a href="goods_receipts.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Goods Receipt</a></p>

    <?php else: ?>
      <!-- ── List view ── -->
      <?php if (!empty($open_discrepancies)): ?>
      <h3 style="font-size:13.5px;margin-bottom:8px"><?= icon('alert-triangle', 16) ?> Open Discrepancies</h3>
      <div class="table-scroll-wrapper" style="margin-bottom:22px">
        <table>
          <thead><tr><th class="col-sticky">PO #</th><th>Supplier</th><th>Flagged</th><th style="text-align:center;width:95px">Action</th></tr></thead>
          <tbody>
          <?php foreach ($open_discrepancies as $d): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700">#<?= str_pad($d['po_id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($d['supplier_name']) ?></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($d['received_at'])) ?></td>
              <td style="text-align:center"><button class="act-btn" onclick="window.location.href='goods_receipts.php?po_id=<?= $d['po_id'] ?>'"><?= icon('eye', 13) ?> Review</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if (!empty($incoming_asns)): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="font-size:13.5px;margin:0;display:flex;align-items:center;gap:6px">
          <?= icon('truck', 16, '', 'color:var(--caramel)') ?> Incoming Shipments &amp; Advance Shipping Notices (ASNs)
        </h3>
        <span class="status-badge status-pending" style="font-size:11.5px"><?= count($incoming_asns) ?> In Transit</span>
      </div>
      <div class="table-scroll-wrapper" style="margin-bottom:22px">
        <table>
          <thead>
            <tr>
              <th class="col-sticky">ASN Ref</th>
              <th>PO #</th>
              <th>Requisition / Item</th>
              <th>Supplier</th>
              <th>Carrier &amp; Tracking</th>
              <th>Shipped / ETA</th>
              <th>Units</th>
              <th style="text-align:center;width:130px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($incoming_asns as $asn): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700;color:var(--caramel)"><?= htmlspecialchars($asn['notice_ref']) ?></td>
              <td style="font-weight:600"><?= htmlspecialchars($asn['po_number'] ?: ('#' . str_pad($asn['po_id'],5,'0',STR_PAD_LEFT))) ?></td>
              <td><?= htmlspecialchars($asn['req_title']) ?></td>
              <td><?= htmlspecialchars($asn['supplier_name']) ?></td>
              <td>
                <div><strong><?= htmlspecialchars($asn['carrier_name'] ?: 'N/A') ?></strong></div>
                <?php if (!empty($asn['tracking_number'])): ?>
                  <div style="font-size:11px;color:var(--text-muted)"><code><?= htmlspecialchars($asn['tracking_number']) ?></code></div>
                <?php endif; ?>
              </td>
              <td class="muted-cell">
                <div>Shipped: <?= date('M d, Y', strtotime($asn['shipped_date'])) ?></div>
                <?php if (!empty($asn['expected_arrival_date'])): ?>
                  <div style="font-size:11px;color:var(--text)">ETA: <?= date('M d, Y', strtotime($asn['expected_arrival_date'])) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-badge" style="font-size:11px;background:#EDF2F7;color:#4A5568"><?= number_format((float)$asn['total_shipped_qty'], 2) ?> units</span>
              </td>
              <td style="text-align:center">
                <button class="act-btn act-activate" onclick="window.location.href='goods_receipts.php?po_id=<?= $asn['po_id'] ?>&asn_id=<?= $asn['id'] ?>'">
                  <?= icon('package', 13) ?> Receive from ASN
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <h3 style="font-size:13.5px;margin-bottom:8px"><?= icon('package', 16) ?> Awaiting Delivery</h3>
      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view all 7 columns</span>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th class="col-sticky">PO #</th><th>Requisition</th><th>Supplier</th><th>Total</th><th>Status</th><th>Expected</th><th style="text-align:center;width:95px">Action</th></tr></thead>
          <tbody>
          <?php if (empty($ready_pos)): ?>
            <tr class="empty-row"><td colspan="7"><?= icon('inbox', 18) ?> No Purchase Orders currently awaiting delivery.</td></tr>
          <?php else: foreach ($ready_pos as $p): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700">#<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($p['req_title']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700">₱<?= number_format($p['total_amount'],2) ?></td>
              <td><span class="status-badge status-pending"><?= status_badge($p['status']) ?></span></td>
              <td class="muted-cell"><?= $p['expected_delivery_date'] ? date('M d, Y', strtotime($p['expected_delivery_date'])) : '—' ?></td>
              <td style="text-align:center"><button class="act-btn" onclick="window.location.href='goods_receipts.php?po_id=<?= $p['id'] ?>'"><?= icon('package', 13) ?> Receive</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>
<script src="../js/validator.js"></script>
<script>
const ASN_ITEMS = <?= json_encode($dn_items_map ?? []) ?>;

function onAsnSelectChange(asnId) {
  document.querySelectorAll('.asn-detail-box').forEach(el => el.style.display = 'none');
  if (asnId) {
    const card = document.getElementById('asn-card-' + asnId);
    if (card) card.style.display = 'block';
  }
}

function prefillFromSelectedAsn() {
  const sel = document.getElementById('select-asn-id');
  if (!sel || !sel.value) {
    alert('Please select an Advance Shipping Notice (ASN) to prefill from.');
    return;
  }
  const asnId = sel.value;
  const items = ASN_ITEMS[asnId];
  if (!items) {
    alert('No line item breakdown found for this ASN.');
    return;
  }
  for (const reqId in items) {
    const inp = document.getElementById('rec-qty-' + reqId);
    if (inp && !inp.disabled) {
      const shipped = parseFloat(items[reqId]) || 0;
      const remaining = parseFloat(inp.getAttribute('data-remaining') || '999999');
      inp.value = Math.min(shipped, remaining);
      inp.style.background = '#EBF8FF';
      inp.style.borderColor = '#3182CE';
      setTimeout(() => {
        inp.style.background = '';
        inp.style.borderColor = '';
      }, 2500);
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('select-asn-id');
  if (sel && sel.value) {
    onAsnSelectChange(sel.value);
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('asn_id')) {
      prefillFromSelectedAsn();
    }
  }
});

document.getElementById('receipt-form')?.addEventListener('submit', function(e) {
  const errBox = document.getElementById('receipt-error');
  if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

  const qtyInputs = this.querySelectorAll('.rec-qty');
  let hasValidQty = false;
  let hasNegative = false;

  qtyInputs.forEach(inp => {
    if (!inp.disabled) {
      const val = parseFloat(inp.value) || 0;
      if (val < 0) hasNegative = true;
      if (val > 0) hasValidQty = true;
    }
  });

  if (hasNegative) {
    e.preventDefault();
    if (errBox) {
      errBox.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Receiving quantities cannot be negative.';
      errBox.style.display = 'block';
    }
    return;
  }

  if (!hasValidQty) {
    e.preventDefault();
    if (errBox) {
      errBox.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Enter a receiving quantity greater than 0 for at least one item.';
      errBox.style.display = 'block';
    }
    return;
  }

  const btn = document.getElementById('receipt-submit-btn');
  if (btn && window.KofeeValidator) {
    KofeeValidator.setLoading(btn, 'Recording…');
  }
});

// Loading state on discrepancy resolution
document.querySelectorAll('form').forEach(f => {
  if (f.querySelector('[name="action"][value="resolve_discrepancy"]')) {
    f.addEventListener('submit', function() {
      const btn = f.querySelector('button[type="submit"]');
      if (btn && window.KofeeValidator) {
        KofeeValidator.setLoading(btn, '…');
      }
    });
  }
});
</script>
</body>
</html>