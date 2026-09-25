<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.supplier.portal');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// ── Resolve this login to a supplier profile ──────────────────
$sup_stmt = $pdo->prepare('SELECT * FROM suppliers WHERE user_id = :u');
$sup_stmt->execute([':u' => $user['id']]);
$supplier = $sup_stmt->fetch();

// ── POST actions (only meaningful once a supplier profile is linked) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supplier) {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_bid') {
        $rfq_id = (int)($_POST['rfq_id'] ?? 0);
        $total  = (float)($_POST['quoted_total'] ?? 0);
        $lead   = (int)($_POST['lead_time_days'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');

        // Confirm this supplier was actually invited to an open RFQ before accepting a quote.
        $chk = $pdo->prepare("
            SELECT rfqs.id FROM rfqs
            JOIN rfq_invites ri ON ri.rfq_id = rfqs.id
            WHERE rfqs.id = :r AND ri.supplier_id = :s AND rfqs.status = 'open'
        ");
        $chk->execute([':r' => $rfq_id, ':s' => $supplier['id']]);

        if (!$chk->fetch()) {
            $toast = 'This RFQ is no longer open for quotes.'; $toast_type = 'error';
        } elseif ($total <= 0) {
            $toast = 'Enter a valid quoted total.'; $toast_type = 'error';
        } else {
            $pdo->prepare('
                INSERT INTO bids (rfq_id, supplier_id, quoted_total, lead_time_days, notes)
                VALUES (:r,:s,:t,:l,:n)
                ON DUPLICATE KEY UPDATE quoted_total=:t2, lead_time_days=:l2, notes=:n2, status="submitted"
            ')->execute([
                ':r'=>$rfq_id, ':s'=>$supplier['id'], ':t'=>$total, ':l'=>$lead, ':n'=>$notes,
                ':t2'=>$total, ':l2'=>$lead, ':n2'=>$notes,
            ]);
            audit_log('bid', $rfq_id, 'quoted', $supplier['name'] . ' quoted ' . php_currency($total));
            notify_role_by_permission('procurement.bidding.review', 'bid_submitted', 'New quote submitted', $supplier['name'] . ' quoted ' . php_currency($total) . ' on RFQ #' . $rfq_id, 'rfq.php?id=' . $rfq_id);
            $toast = 'Quote submitted.';
        }
    }

    if ($action === 'acknowledge_letter') {
        $letter_id = (int)($_POST['letter_id'] ?? 0);
        $notes     = trim($_POST['acknowledgement_notes'] ?? '');
        $res       = acknowledge_procurement_letter($letter_id, (int)$supplier['id'], $notes);
        if ($res['ok']) {
            $toast = $res['message'] ?? 'Letter acknowledged.';
        } else {
            $toast = $res['error'] ?? 'Could not acknowledge letter.';
            $toast_type = 'error';
        }
    }

    if ($action === 'acknowledge_po') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $upd = $pdo->prepare("UPDATE purchase_orders SET status='acknowledged', acknowledged_at=NOW() WHERE id=:id AND supplier_id=:sid AND status='sent'");
        $upd->execute([':id' => $po_id, ':sid' => $supplier['id']]);
        if ($upd->rowCount()) {
            audit_log('po', $po_id, 'acknowledged', $supplier['name'] . ' acknowledged the order');
            $toast = 'Order acknowledged.';
        } else {
            $toast = 'Could not acknowledge — order may already be past that step.'; $toast_type = 'error';
        }
    }

    if ($action === 'mark_shipped') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $note  = trim($_POST['shipping_notes'] ?? '');
        $upd = $pdo->prepare("
            UPDATE purchase_orders
            SET shipped_at = NOW(), shipping_notes = :n
            WHERE id = :id AND supplier_id = :sid
              AND status IN ('sent','acknowledged') AND shipped_at IS NULL
        ");
        $upd->execute([':n' => $note ?: null, ':id' => $po_id, ':sid' => $supplier['id']]);
        if ($upd->rowCount()) {
            audit_log('po', $po_id, 'shipped', $supplier['name'] . ' marked the order shipped' . ($note ? " — {$note}" : ''));
            notify_role_by_permission('procurement.receiving', 'po_shipped', 'Order shipped', $supplier['name'] . ' shipped PO #' . $po_id, 'goods_receipts.php?po_id=' . $po_id);
            $toast = 'Marked as shipped.';
        } else {
            $toast = 'Could not mark shipped — order may already be past that step.'; $toast_type = 'error';
        }
    }

    if ($action === 'submit_invoice') {
        $po_id    = (int)($_POST['po_id'] ?? 0);
        $inv_num  = trim($_POST['invoice_number'] ?? '');
        $inv_date = $_POST['invoice_date'] ?: null;
        $due_date = $_POST['due_date'] ?: null;
        $tax      = (float)($_POST['tax_amount'] ?? 0);
        $lines    = $_POST['lines'] ?? []; // [requisition_item_id => ['qty'=>.., 'unit_price'=>..]]

        $po = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id AND supplier_id = :sid');
        $po->execute([':id' => $po_id, ':sid' => $supplier['id']]);
        $po = $po->fetch();

        $already = $pdo->prepare("SELECT id FROM invoices WHERE po_id = :id AND status != 'cancelled'");
        $already->execute([':id' => $po_id]);

        if (!$po) {
            $toast = 'Purchase Order not found.'; $toast_type = 'error';
        } elseif ($po['status'] !== 'delivered') {
            $toast = 'You can only invoice a PO after delivery is confirmed.'; $toast_type = 'error';
        } elseif ($already->fetch()) {
            $toast = 'An invoice has already been submitted for this order.'; $toast_type = 'error';
        } elseif (!$inv_num) {
            $toast = 'Invoice number is required.'; $toast_type = 'error';
        } elseif (empty($lines)) {
            $toast = 'Enter quantities for at least one line item.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $subtotal  = 0.0;
                $item_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE id = :id AND requisition_id = :rid');
                $line_data = [];

                foreach ($lines as $req_item_id => $data) {
                    $qty   = (float)($data['qty'] ?? 0);
                    $price = (float)($data['unit_price'] ?? 0);
                    if ($qty <= 0) continue;

                    $item_stmt->execute([':id' => (int)$req_item_id, ':rid' => $po['requisition_id']]);
                    $ri = $item_stmt->fetch();
                    if (!$ri) continue;

                    $line_total = round($qty * $price, 2);
                    $subtotal  += $line_total;
                    $line_data[] = [
                        'requisition_item_id' => (int)$req_item_id,
                        'item_name' => $ri['item_name'],
                        'qty' => $qty, 'unit_price' => $price, 'line_total' => $line_total,
                    ];
                }

                if (empty($line_data)) {
                    throw new Exception('Enter a quantity for at least one line item.');
                }

                $total = round($subtotal + $tax, 2);

                $inv_stmt = $pdo->prepare(
                    'INSERT INTO invoices (po_id, supplier_id, invoice_number, invoice_date, due_date, subtotal, tax_amount, total_amount, uploaded_by)
                     VALUES (:po, :sup, :num, :idate, :ddate, :sub, :tax, :tot, :u)'
                );
                $inv_stmt->execute([
                    ':po' => $po_id, ':sup' => $supplier['id'], ':num' => $inv_num, ':idate' => $inv_date, ':ddate' => $due_date,
                    ':sub' => $subtotal, ':tax' => $tax, ':tot' => $total, ':u' => $user['id'],
                ]);
                $invoice_id = (int)$pdo->lastInsertId();

                $li_stmt = $pdo->prepare(
                    'INSERT INTO invoice_items (invoice_id, requisition_item_id, item_name, qty, unit_price, line_total)
                     VALUES (:inv, :ri, :n, :q, :p, :lt)'
                );
                foreach ($line_data as $l) {
                    $li_stmt->execute([
                        ':inv' => $invoice_id, ':ri' => $l['requisition_item_id'], ':n' => $l['item_name'],
                        ':q' => $l['qty'], ':p' => $l['unit_price'], ':lt' => $l['line_total'],
                    ]);
                }

                $pdo->commit();
                audit_log('invoice', $invoice_id, 'created', $supplier['name'] . " submitted invoice {$inv_num} for PO #$po_id — " . php_currency($total));
                notify_role_by_permission(
                    'procurement.invoice.match', 'invoice_created',
                    "Invoice {$inv_num} submitted for PO #$po_id",
                    $supplier['name'] . ' submitted an invoice — ready for 3-way match.',
                    'three_way_match.php?invoice_id=' . $invoice_id
                );
                $toast = 'Invoice submitted — awaiting match and approval.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    header('Location: supplier_portal.php' . ($toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : ''));
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Data for the views below (only if this login has a supplier profile) ──
$open_invites = [];
$my_bids = [];
$my_pos = [];
$my_letters = [];
$breakdown = null;
$ratings = [];

if ($supplier) {
    // Official procurement letters issued to this supplier
    $let_stmt = $pdo->prepare("
        SELECT pl.*, pr.title AS req_title, pr.department, pr.estimated_total
        FROM procurement_letters pl
        JOIN purchase_requisitions pr ON pr.id = pl.requisition_id
        WHERE pl.supplier_id = :s
        ORDER BY FIELD(pl.status, 'sent', 'acknowledged'), pl.sent_at DESC
    ");
    $let_stmt->execute([':s' => $supplier['id']]);
    $my_letters = $let_stmt->fetchAll();

    // RFQs this supplier is invited to and still open for a quote
    $inv_stmt = $pdo->prepare("
        SELECT rfqs.id AS rfq_id, rfqs.due_date, rfqs.status AS rfq_status,
               pr.title AS req_title, pr.department, pr.estimated_total,
               b.id AS bid_id, b.quoted_total, b.lead_time_days, b.notes AS bid_notes, b.status AS bid_status
        FROM rfq_invites ri
        JOIN rfqs ON rfqs.id = ri.rfq_id
        JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        LEFT JOIN bids b ON b.rfq_id = rfqs.id AND b.supplier_id = ri.supplier_id
        WHERE ri.supplier_id = :s AND rfqs.status = 'open'
        ORDER BY rfqs.due_date IS NULL, rfqs.due_date ASC
    ");
    $inv_stmt->execute([':s' => $supplier['id']]);
    $open_invites = $inv_stmt->fetchAll();

    // Full bid history (open + closed RFQs)
    $bid_stmt = $pdo->prepare("
        SELECT b.*, rfqs.status AS rfq_status, pr.title AS req_title
        FROM bids b
        JOIN rfqs ON rfqs.id = b.rfq_id
        JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        WHERE b.supplier_id = :s
        ORDER BY b.submitted_at DESC
    ");
    $bid_stmt->execute([':s' => $supplier['id']]);
    $my_bids = $bid_stmt->fetchAll();

    // This supplier's purchase orders, with GRN / invoice / payment status folded in
    $po_stmt = $pdo->prepare("
        SELECT po.*, pr.title AS req_title, pr.department,
               (SELECT status FROM goods_receipts g WHERE g.po_id = po.id ORDER BY g.received_at DESC LIMIT 1) AS grn_status,
               (SELECT status FROM invoices i WHERE i.po_id = po.id AND i.status != 'cancelled' ORDER BY i.created_at DESC LIMIT 1) AS invoice_status,
               (SELECT id FROM invoices i WHERE i.po_id = po.id AND i.status != 'cancelled' ORDER BY i.created_at DESC LIMIT 1) AS invoice_id
        FROM purchase_orders po
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE po.supplier_id = :s
        ORDER BY FIELD(po.status,'sent','acknowledged','delivered','draft','closed','cancelled'), po.created_at DESC
    ");
    $po_stmt->execute([':s' => $supplier['id']]);
    $my_pos = $po_stmt->fetchAll();

    // Requisition line items for POs that are delivered and not yet invoiced —
    // that's the set the "Submit Invoice" form needs to build line items for.
    $invoiceable_items = [];
    foreach ($my_pos as $p) {
        if ($p['status'] === 'delivered' && !$p['invoice_id']) {
            $ri_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :rid');
            $ri_stmt->execute([':rid' => $p['requisition_id']]);
            $invoiceable_items[$p['id']] = $ri_stmt->fetchAll();
        }
    }

    // Own performance scorecard
    $b = $pdo->prepare('
        SELECT AVG(quality_score) AS quality, AVG(timeliness_score) AS timeliness,
               AVG(price_score) AS price, AVG(communication_score) AS communication
        FROM supplier_performance_ratings WHERE supplier_id = :s
    ');
    $b->execute([':s' => $supplier['id']]);
    $breakdown = $b->fetch();

    $r = $pdo->prepare('
        SELECT spr.*, po.id AS po_id
        FROM supplier_performance_ratings spr
        JOIN purchase_orders po ON po.id = spr.po_id
        WHERE spr.supplier_id = :s ORDER BY spr.created_at DESC LIMIT 10
    ');
    $r->execute([':s' => $supplier['id']]);
    $ratings = $r->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Supplier Portal — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .invite-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-bottom:12px; }
    .invite-card.quoted { border-color:var(--green); background:var(--green-lt); }
    .po-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:14px 18px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .score-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px dashed var(--border); }
    .score-row:last-child { border-bottom:none; }
    .breakdown-bar-wrap { background:#f2e6d6; border-radius:999px; height:8px; overflow:hidden; flex:1; margin:0 10px; }
    .breakdown-bar-fill { height:100%; background:var(--caramel, #c47d3e); }
    .portal-section-title { font-size:14px; font-weight:800; margin:24px 0 12px; }
    .portal-section-title:first-child { margin-top:0; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-supplier-portal" class="page active">
  <div class="page-header">
    <div>
      <h1>Supplier Portal</h1>
      <p><?= $supplier ? 'Welcome, ' . htmlspecialchars($supplier['name']) : 'Quote on RFQs, acknowledge orders, and track your standing' ?></p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if (!$supplier): ?>
      <div class="table-card" style="padding:22px">
        <p>Your account isn't linked to a supplier profile yet. Ask a Procurement Officer to connect your login on the <strong>Suppliers</strong> page before you can quote or track orders here.</p>
      </div>

    <?php else: ?>

      <!-- ── Official Procurement Letters & Orders ── -->
      <?php
      $pending_ack_count = count(array_filter($my_letters, fn($l) => $l['status'] === 'sent'));
      ?>
      <h3 class="portal-section-title" style="display:flex;align-items:center;justify-content:space-between">
        <span style="display:inline-flex;align-items:center;gap:6px">
          <?= icon('file-text', 16, '', 'color:var(--caramel)') ?>
          Official Procurement Letters &amp; Order Authorizations
          <?= count($my_letters) ? '(' . count($my_letters) . ')' : '' ?>
        </span>
        <?php if ($pending_ack_count > 0): ?>
          <span class="status-badge status-pending" style="font-size:11px">
            <?= $pending_ack_count ?> Awaiting Acknowledgment
          </span>
        <?php endif; ?>
      </h3>

      <?php if (empty($my_letters)): ?>
        <p class="muted-cell" style="margin-bottom:18px">No procurement letters issued to your account yet.</p>
      <?php else: foreach ($my_letters as $let): ?>
        <div class="po-card" style="border-color:<?= $let['status']==='sent' ? '#E67E22' : 'var(--border)' ?>;background:<?= $let['status']==='sent' ? '#FDF8F2' : '#FFFFFF' ?>;flex-direction:column;align-items:stretch;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <strong style="font-size:15px;color:var(--espresso)"><?= htmlspecialchars($let['req_title']) ?></strong>
                <span style="font-family:monospace;font-size:12px;font-weight:700;background:#EFE0CC;padding:2px 6px;border-radius:4px;color:var(--espresso)">
                  <?= htmlspecialchars($let['letter_ref']) ?>
                </span>
                <span class="status-badge <?= $let['status']==='acknowledged' ? 'status-approved' : 'status-pending' ?>">
                  <?= $let['status']==='acknowledged' ? 'Acknowledged' : 'Pending Acknowledgment' ?>
                </span>
              </div>

              <p class="muted-cell" style="margin:4px 0 0">
                Department: <?= htmlspecialchars($let['department']) ?> ·
                Authorized Amount: <strong style="color:var(--espresso)"><?= php_currency((float)$let['estimated_total']) ?></strong> ·
                Issued <?= date('M d, Y', strtotime($let['sent_at'])) ?> by <?= htmlspecialchars($let['approver_name']) ?> (<?= htmlspecialchars($let['approver_title']) ?>)
              </p>

              <?php if (!empty($let['delivery_terms'])): ?>
                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">
                  <strong>Delivery Terms:</strong> <?= htmlspecialchars($let['delivery_terms']) ?>
                </p>
              <?php endif; ?>

              <?php if ($let['status'] === 'acknowledged'): ?>
                <p style="font-size:12px;color:#27AE60;margin:6px 0 0;font-weight:600">
                  <?= icon('check', 12) ?> Formally acknowledged on <?= date('M d, Y H:i', strtotime($let['acknowledged_at'])) ?>
                  <?= !empty($let['acknowledgement_notes']) ? ' — "' . htmlspecialchars($let['acknowledgement_notes']) . '"' : '' ?>
                </p>
              <?php endif; ?>
            </div>

            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <a href="procurement_letter.php?id=<?= $let['id'] ?>" target="_blank" class="act-btn act-activate" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                <?= icon('eye', 13) ?> View Official Letter
              </a>
              <?php if ($let['status'] === 'sent'): ?>
                <button type="button" class="btn-save" style="padding:6px 12px;font-size:12px;background:#27AE60" onclick="toggleAckForm(<?= $let['id'] ?>)">
                  <?= icon('check', 13) ?> Acknowledge Letter
                </button>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($let['status'] === 'sent'): ?>
            <form method="POST" id="ack-form-<?= $let['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
              <input type="hidden" name="action" value="acknowledge_letter"/>
              <input type="hidden" name="letter_id" value="<?= $let['id'] ?>"/>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <div class="field-group" style="margin:0;flex:1;min-width:240px">
                  <label class="field-label">Confirmation / Estimated Delivery Note (optional)</label>
                  <input class="field-input" type="text" name="acknowledgement_notes" placeholder="e.g. Order received and scheduled for dispatch on Friday"/>
                </div>
                <button type="submit" class="btn-save" style="background:#27AE60">
                  <?= icon('check', 13) ?> Confirm Acknowledgment
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <!-- ── Open RFQ Invitations ── -->
      <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px"><?= icon('message', 16, '', 'color:var(--caramel)') ?> Open RFQ Invitations <?= count($open_invites) ? '(' . count($open_invites) . ')' : '' ?></h3>
      <?php if (empty($open_invites)): ?>
        <p class="muted-cell" style="margin-bottom:8px">No open RFQs waiting on a quote from you right now.</p>
      <?php else: foreach ($open_invites as $inv): ?>
        <div class="invite-card <?= $inv['bid_id'] ? 'quoted' : '' ?>">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;gap:8px">
            <div>
              <strong><?= htmlspecialchars($inv['req_title']) ?></strong>
              <p class="muted-cell"><?= htmlspecialchars($inv['department']) ?> · Est. <?= php_currency($inv['estimated_total']) ?> · Due <?= $inv['due_date'] ? date('M d, Y', strtotime($inv['due_date'])) : 'no deadline set' ?></p>
            </div>
            <?php if ($inv['bid_id']): ?>
              <span class="status-badge status-<?= $inv['bid_status']==='shortlisted'?'pending':'approved' ?>"><?= status_badge($inv['bid_status']) ?></span>
            <?php endif; ?>
          </div>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="action" value="submit_bid"/>
            <input type="hidden" name="rfq_id" value="<?= $inv['rfq_id'] ?>"/>
            <div class="field-group" style="margin:0;max-width:150px">
              <label class="field-label">Quoted Total (₱)</label>
              <input class="field-input" type="number" step="0.01" min="0.01" name="quoted_total" value="<?= $inv['quoted_total'] ?: '' ?>" required/>
            </div>
            <div class="field-group" style="margin:0;max-width:120px">
              <label class="field-label">Lead Time (days)</label>
              <input class="field-input" type="number" min="0" name="lead_time_days" value="<?= $inv['lead_time_days'] ?: 0 ?>"/>
            </div>
            <div class="field-group" style="margin:0;flex:1;min-width:160px">
              <label class="field-label">Notes</label>
              <input class="field-input" type="text" name="notes" value="<?= htmlspecialchars($inv['bid_notes'] ?? '') ?>" placeholder="Optional"/>
            </div>
            <button type="submit" class="btn-save"><?= $inv['bid_id'] ? icon('edit', 14) . ' Update Quote' : icon('send', 14) . ' Submit Quote' ?></button>
          </form>
        </div>
      <?php endforeach; endif; ?>

      <!-- ── My Purchase Orders ── -->
      <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px"><?= icon('package', 16, '', 'color:var(--caramel)') ?> My Purchase Orders</h3>
      <?php if (empty($my_pos)): ?>
        <p class="muted-cell" style="margin-bottom:8px">No purchase orders yet.</p>
      <?php else: foreach ($my_pos as $p): ?>
        <div class="po-card" style="flex-direction:column;align-items:stretch">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div>
              <strong>#<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($p['req_title']) ?></strong>
              <p class="muted-cell"><?= htmlspecialchars($p['department']) ?> · <?= php_currency($p['total_amount']) ?><?= $p['expected_delivery_date'] ? ' · Expected ' . date('M d, Y', strtotime($p['expected_delivery_date'])) : '' ?></p>
              <p style="margin-top:6px;font-size:12px">
                <span class="status-badge status-<?= in_array($p['status'],['closed','delivered'])?'approved':($p['status']==='cancelled'?'rejected':'pending') ?>"><?= status_badge($p['status']) ?></span>
                <?php if ($p['shipped_at']): ?><span class="status-badge status-pending" style="margin-left:6px;display:inline-flex;align-items:center;gap:4px"><?= icon('truck', 12) ?> Shipped <?= date('M d, Y', strtotime($p['shipped_at'])) ?></span><?php endif; ?>
                <?php if ($p['grn_status']): ?><span class="status-badge status-pending" style="margin-left:6px">GRN: <?= status_badge($p['grn_status']) ?></span><?php endif; ?>
                <?php if ($p['invoice_status']): ?><span class="status-badge status-pending" style="margin-left:6px">Invoice: <?= status_badge($p['invoice_status']) ?></span><?php endif; ?>
                <?php if ($p['paid_at']): ?><span class="status-badge status-approved" style="margin-left:6px;display:inline-flex;align-items:center;gap:4px"><?= icon('dollar', 12) ?> Paid <?= date('M d, Y', strtotime($p['paid_at'])) ?></span><?php endif; ?>
              </p>
              <?php if ($p['shipping_notes']): ?><p class="muted-cell" style="margin-top:4px;font-style:italic;display:flex;align-items:center;gap:4px"><?= icon('truck', 12) ?> "<?= htmlspecialchars($p['shipping_notes']) ?>"</p><?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <?php if ($p['status'] === 'sent'): ?>
                <form method="POST"><input type="hidden" name="action" value="acknowledge_po"/><input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
                  <button type="submit" class="act-btn act-activate"><?= icon('check', 13) ?> Acknowledge Order</button></form>
              <?php endif; ?>
              <?php if (in_array($p['status'],['sent','acknowledged'],true) && !$p['shipped_at']): ?>
                <button type="button" class="act-btn act-activate" onclick="toggleShipForm(<?= $p['id'] ?>)"><?= icon('truck', 13) ?> Mark as Shipped</button>
              <?php endif; ?>
              <?php if ($p['status'] === 'delivered' && !$p['invoice_id']): ?>
                <button type="button" class="act-btn act-activate" onclick="toggleInvoiceForm(<?= $p['id'] ?>)"><?= icon('invoice', 13) ?> Submit Invoice</button>
              <?php endif; ?>
            </div>
          </div>

          <?php if (in_array($p['status'],['sent','acknowledged'],true) && !$p['shipped_at']): ?>
          <form method="POST" id="ship-form-<?= $p['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="action" value="mark_shipped"/>
            <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
            <div class="field-group" style="margin:0;flex:1;min-width:200px">
              <label class="field-label">Tracking / Carrier Note (optional)</label>
              <input class="field-input" type="text" name="shipping_notes" placeholder="e.g. LBC, tracking #1234"/>
            </div>
            <button type="submit" class="btn-save"><?= icon('truck', 14) ?> Confirm Shipped</button>
          </form>
          <?php endif; ?>

          <?php if ($p['status'] === 'delivered' && !$p['invoice_id']): ?>
          <form method="POST" id="invoice-form-<?= $p['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
            <input type="hidden" name="action" value="submit_invoice"/>
            <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
              <div class="field-group" style="margin:0;max-width:160px">
                <label class="field-label">Invoice Number</label>
                <input class="field-input" type="text" name="invoice_number" required/>
              </div>
              <div class="field-group" style="margin:0;max-width:150px">
                <label class="field-label">Invoice Date</label>
                <input class="field-input" type="date" name="invoice_date"/>
              </div>
              <div class="field-group" style="margin:0;max-width:150px">
                <label class="field-label">Due Date</label>
                <input class="field-input" type="date" name="due_date"/>
              </div>
              <div class="field-group" style="margin:0;max-width:130px">
                <label class="field-label">Tax Amount (₱)</label>
                <input class="field-input" type="number" step="0.01" min="0" name="tax_amount" value="0"/>
              </div>
            </div>
            <div class="table-scroll-wrapper" style="margin-bottom:10px">
              <table style="width:100%;min-width:480px">
                <thead><tr><th style="text-align:left;font-size:11.5px">Item</th><th style="text-align:left;font-size:11.5px">Ordered</th><th style="text-align:left;font-size:11.5px">Qty Invoiced</th><th style="text-align:left;font-size:11.5px">Unit Price (₱)</th></tr></thead>
                <tbody>
                  <?php foreach (($invoiceable_items[$p['id']] ?? []) as $ri): ?>
                    <tr>
                      <td style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($ri['item_name']) ?></td>
                      <td style="font-size:12.5px" class="muted-cell"><?= number_format((float)$ri['quantity'],2) ?> <?= htmlspecialchars($ri['unit']) ?></td>
                      <td><input class="field-input" type="number" step="0.01" min="0" style="width:90px;padding:6px 8px" name="lines[<?= $ri['id'] ?>][qty]" value="<?= number_format((float)$ri['quantity'],2) ?>"/></td>
                      <td><input class="field-input" type="number" step="0.01" min="0" style="width:100px;padding:6px 8px" name="lines[<?= $ri['id'] ?>][unit_price]" value="<?= number_format((float)$ri['est_unit_price'],2) ?>"/></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div style="text-align:right"><button type="submit" class="btn-save"><?= icon('invoice', 14) ?> Submit Invoice</button></div>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <!-- ── My Bid History ── -->
      <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px"><?= icon('clipboard', 16, '', 'color:var(--caramel)') ?> My Bid History</h3>
      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view all 5 columns</span>
      </div>
      <div class="table-scroll-wrapper" style="margin-bottom:8px">
        <table>
          <thead><tr><th class="col-sticky">Requisition</th><th>Quoted</th><th>Lead Time</th><th>Status</th><th>Submitted</th></tr></thead>
          <tbody>
          <?php if (empty($my_bids)): ?>
            <tr class="empty-row"><td colspan="5"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No quotes submitted yet.</td></tr>
          <?php else: foreach ($my_bids as $b): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700"><?= htmlspecialchars($b['req_title']) ?></td>
              <td><?= php_currency($b['quoted_total']) ?></td>
              <td><?= $b['lead_time_days'] ?> day(s)</td>
              <td><span class="status-badge status-<?= $b['status']==='selected'?'approved':($b['status']==='rejected'?'rejected':'pending') ?>"><?= status_badge($b['status']) ?></span></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($b['submitted_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── My Performance ── -->
      <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px"><?= icon('star', 16, '', 'fill:currentColor;color:var(--amber,#b45309)') ?> My Performance</h3>
      <div class="table-card" style="padding:18px 20px;margin-bottom:8px">
        <p class="muted-cell" style="margin-bottom:12px"><?= $supplier['rating_count'] ?> rating(s) · Overall <?= $supplier['rating_avg'] ? number_format($supplier['rating_avg'],2) . '/5' : 'Not yet rated' ?></p>
        <?php if ($breakdown && $supplier['rating_count'] > 0): ?>
          <?php foreach (['quality'=>'Quality','timeliness'=>'Timeliness','price'=>'Price','communication'=>'Communication'] as $key => $label): $val = (float)$breakdown[$key]; ?>
            <div class="score-row">
              <span style="width:120px;font-size:12.5px;font-weight:600"><?= $label ?></span>
              <div class="breakdown-bar-wrap"><div class="breakdown-bar-fill" style="width:<?= $val/5*100 ?>%"></div></div>
              <span style="font-size:12.5px;font-weight:700"><?= number_format($val,1) ?>/5</span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="muted-cell">No ratings on file yet — they'll appear here once an order is closed out.</p>
        <?php endif; ?>
      </div>

    <?php endif; ?>

  </div>
</div>

<script>
function toggleAckForm(id) {
  const f = document.getElementById('ack-form-' + id);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
function toggleShipForm(id) {
  const f = document.getElementById('ship-form-' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'flex' : 'none';
}
function toggleInvoiceForm(id) {
  const f = document.getElementById('invoice-form-' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
</script>

</body>
</html>