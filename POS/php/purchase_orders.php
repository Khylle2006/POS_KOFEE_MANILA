<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

ensure_procurement_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'send') {
        require_permission('procurement.po.manage');
        $pdo->prepare("UPDATE purchase_orders SET status='sent' WHERE id=:id AND status='draft'")->execute([':id'=>$id]);
        audit_log('po', $id, 'sent', 'Purchase order dispatched to supplier');
        $toast = 'Purchase Order sent to supplier.';
    }

    if ($action === 'resolve_issue') {
        require_permission('procurement.po.manage');
        $res_notes = trim($_POST['issue_resolution_notes'] ?? '');
        if (!$res_notes) {
            $toast = 'Please provide notes detailing the resolution before confirming.';
            $toast_type = 'error';
        } else {
            $po_stmt = $pdo->prepare('
                SELECT po.*, s.name AS supplier_name, s.user_id AS supplier_user_id
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.id = :id
            ');
            $po_stmt->execute([':id' => $id]);
            $target_po = $po_stmt->fetch();

            if (!$target_po) {
                $toast = 'Purchase order not found.';
                $toast_type = 'error';
            } else {
                $pdo->prepare("
                    UPDATE purchase_orders 
                    SET issue_status = 'resolved',
                        issue_resolution_notes = :notes,
                        issue_resolved_at = NOW(),
                        issue_resolved_by = :uid
                    WHERE id = :id
                ")->execute([
                    ':notes' => $res_notes,
                    ':uid'   => $user['id'],
                    ':id'    => $id,
                ]);

                $po_ref = $target_po['po_number'] ?: ('KM-PO-' . str_pad($id, 5, '0', STR_PAD_LEFT));
                audit_log('po', $id, 'issue_resolved', "Issue resolved by {$user['firstname']} {$user['lastname']}: {$res_notes}");

                if (!empty($target_po['supplier_user_id'])) {
                    notify_user(
                        (int)$target_po['supplier_user_id'],
                        'po_issue_resolved',
                        'Issue Resolved on PO ' . $po_ref,
                        "Kofee Manila management has resolved the reported concern on PO {$po_ref}: {$res_notes}",
                        'supplier_portal.php?tab=orders&po_id=' . $id
                    );
                }

                $toast = "Issue on PO {$po_ref} marked as resolved and supplier notified.";
            }
        }
    }

    if ($action === 'record_invoice') {
        require_permission('procurement.invoice.match');
        $inv = trim($_POST['invoice_number'] ?? '');
        if (!$inv) {
            $toast = 'Invoice number is required.'; $toast_type = 'error';
        } else {
            $pdo->prepare('UPDATE purchase_orders SET invoice_number=:i WHERE id=:id')->execute([':i'=>$inv, ':id'=>$id]);
            audit_log('po', $id, 'invoice_recorded', "Invoice #{$inv} linked to PO");
            $toast = 'Invoice matched to PO, Goods Receipt confirmed.';
        }
    }

    if ($action === 'close_and_rate') {
        require_permission('procurement.performance.rate');
        $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
        $po_stmt->execute([':id' => $id]);
        $target_po = $po_stmt->fetch();

        if (!$target_po) {
            $toast = 'Purchase Order not found.';
            $toast_type = 'error';
        } elseif ($target_po['status'] === 'closed') {
            $toast = 'This order is already closed.';
            $toast_type = 'error';
        } elseif ($target_po['status'] !== 'delivered') {
            $toast = 'Cannot close order: Goods have not been confirmed delivered yet.';
            $toast_type = 'error';
        } elseif ($target_po['issue_status'] === 'open') {
            $toast = 'Cannot close order: There is an active supplier issue under review that must be resolved first.';
            $toast_type = 'error';
        } else {
            $inv_paid = !empty($target_po['paid_at']);
            if (!$inv_paid) {
                $chk_inv = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE po_id = :p AND status = 'paid'");
                $chk_inv->execute([':p' => $id]);
                $inv_paid = ((int)$chk_inv->fetchColumn()) > 0;
            }

            if (!$inv_paid) {
                $toast = 'Cannot close order: Invoice has not been marked as paid yet.';
                $toast_type = 'error';
            } else {
                $quality   = max(1, min(5, (int)($_POST['quality_score'] ?? 5)));
                $timely    = max(1, min(5, (int)($_POST['timeliness_score'] ?? 5)));
                $price     = max(1, min(5, (int)($_POST['price_score'] ?? 5)));
                $comm      = max(1, min(5, (int)($_POST['communication_score'] ?? 5)));
                $comments  = trim($_POST['comments'] ?? '');

                try {
                    $pdo->beginTransaction();

                    $pdo->prepare('
                        INSERT INTO supplier_performance_ratings
                            (po_id, supplier_id, rated_by, quality_score, timeliness_score, price_score, communication_score, comments)
                        VALUES (:po, :sup, :u, :q, :t, :p, :c, :cm)
                    ')->execute([
                        ':po' => $id, ':sup' => $target_po['supplier_id'], ':u' => $user['id'],
                        ':q' => $quality, ':t' => $timely, ':p' => $price, ':c' => $comm, ':cm' => $comments ?: null,
                    ]);

                    $overall = round(($quality + $timely + $price + $comm) / 4, 2);

                    $pdo->prepare('
                        UPDATE suppliers
                        SET rating_avg = ROUND(((COALESCE(rating_avg, 0) * rating_count) + :o) / (rating_count + 1), 2),
                            rating_count = rating_count + 1
                        WHERE id = :sid
                    ')->execute([':o' => $overall, ':sid' => $target_po['supplier_id']]);

                    $pdo->prepare("UPDATE purchase_orders SET status = 'closed', closed_at = NOW(), supplier_rating = :r WHERE id = :id")
                        ->execute([':r' => round($overall), ':id' => $id]);

                    $pdo->prepare("UPDATE purchase_requisitions SET status = 'closed' WHERE id = :id")
                        ->execute([':id' => $target_po['requisition_id']]);

                    $pdo->commit();

                    audit_log('po', $id, 'closed', "Procurement cycle complete, overall rating {$overall}/5");
                    audit_log('supplier', $target_po['supplier_id'], 'performance_rated', "PO #$id — overall {$overall}/5");

                    $toast = "Order closed successfully — supplier rated {$overall}/5.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $toast = $e->getMessage();
                    $toast_type = 'error';
                }
            }
        }
    }

    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: purchase_orders.php?id=' . $id . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$view_id = (int)($_GET['id'] ?? 0);
$po = null;
$po_items = [];
if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT po.*, s.name AS supplier_name, s.contact_person, s.email, s.phone, s.user_id AS supplier_user_id,
               pr.title AS req_title, pr.department,
               c.id AS contract_id, c.contract_ref, c.title AS contract_title, c.total_amount AS contract_total, c.status AS contract_status,
               u_res.firstname AS res_fname, u_res.lastname AS res_lname
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        LEFT JOIN purchase_contracts c ON c.id = po.contract_id
        LEFT JOIN users u_res ON u_res.id = po.issue_resolved_by
        WHERE po.id = :id
    ');
    $stmt->execute([':id'=>$view_id]);
    $po = $stmt->fetch();

    if ($po) {
        if (!empty($po['contract_id'])) {
            $ci_stmt = $pdo->prepare('SELECT * FROM purchase_contract_items WHERE contract_id = :cid');
            $ci_stmt->execute([':cid' => $po['contract_id']]);
            $po_items = $ci_stmt->fetchAll();
        }
        if (empty($po_items)) {
            $ri_stmt = $pdo->prepare('SELECT *, est_unit_price AS unit_price, (quantity * est_unit_price) AS line_total FROM requisition_items WHERE requisition_id = :rid');
            $ri_stmt->execute([':rid' => $po['requisition_id']]);
            $po_items = $ri_stmt->fetchAll();
        }

        // Linked invoice info
        $inv_stmt = $pdo->prepare("SELECT * FROM invoices WHERE po_id = :id AND status != 'cancelled' ORDER BY created_at DESC LIMIT 1");
        $inv_stmt->execute([':id' => $view_id]);
        $linked_invoice = $inv_stmt->fetch();

        // Linked GRN info
        $grn_stmt = $pdo->prepare("SELECT * FROM goods_receipts WHERE po_id = :id ORDER BY received_at DESC LIMIT 1");
        $grn_stmt->execute([':id' => $view_id]);
        $linked_grn = $grn_stmt->fetch();

        $gate_receipt_ok   = in_array($po['status'], ['delivered', 'closed'], true) || !empty($linked_grn);
        $gate_invoice_paid = !empty($po['paid_at']) || (!empty($linked_invoice) && $linked_invoice['status'] === 'paid');
        $gate_issue_ok     = ($po['issue_status'] !== 'open');
        $can_close         = ($gate_receipt_ok && $gate_invoice_paid && $gate_issue_ok && $po['status'] !== 'closed');
    }
}

$filter = $_GET['status'] ?? 'all';
$where  = '1=1';
$params = [];
if ($filter === 'issues') {
    $where .= " AND po.issue_status = 'open'";
} elseif (in_array($filter, ['draft','sent','acknowledged','delivered','closed','cancelled'], true)) {
    $where .= ' AND po.status = :st'; $params[':st'] = $filter;
}

// Count open issues for badge
$open_issues_count = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE issue_status = 'open'")->fetchColumn();

$list_stmt = $pdo->prepare("
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title, c.contract_ref
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    LEFT JOIN purchase_contracts c ON c.id = po.contract_id
    WHERE $where
    ORDER BY (po.issue_status = 'open') DESC, FIELD(po.status,'sent','acknowledged','delivered','draft','closed','cancelled'), po.created_at DESC
");
$list_stmt->execute($params);
$pos = $list_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Purchase Orders — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .po-step { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px dashed var(--border); }
    .po-step:last-child { border-bottom:none; }
    .po-step-dot { width:22px; height:22px; border-radius:50%; background:var(--border); flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:11px; color:#fff; }
    .po-step-dot.done { background:var(--green); }
    .po-step-dot.warning { background:var(--red); }
    .po-step-label { font-size:13px; font-weight:600; }
    .po-step-action { margin-left:auto; }

    /* Modal styling */
    .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:9999; }
    .modal-overlay.open { display:flex; }
    .modal { background:#fff; border-radius:12px; width:92%; max-width:540px; box-shadow:0 10px 30px rgba(0,0,0,0.25); overflow:hidden; }
    .modal-header { padding:14px 20px; background:#FAF5EE; border-bottom:1px solid #E5D5C5; display:flex; justify-content:space-between; align-items:center; }
    .modal-header h3 { margin:0; font-size:15px; color:var(--espresso); }
    .modal-close { background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-muted); }
    .modal-body { padding:16px 20px; }
    .modal-footer { padding:12px 20px; background:#FAF8F5; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:8px; }
    .btn-cancel { padding:8px 14px; background:#E2E8F0; color:#4A5568; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-po" class="page active">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <h1>Purchase Orders</h1>
      <p>Track orders from contract execution through delivery, invoicing, payment, and closure</p>
    </div>
    <?php if ($po): ?>
      <a href="purchase_orders.php" class="btn-cancel" style="display:inline-flex;align-items:center;gap:6px">
        <?= icon('chevron-left', 14) ?> Back to PO List
      </a>
    <?php endif; ?>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:12px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($po): ?>
      <!-- ── PO detail view ── -->
      <?php if ($po['issue_status'] === 'open'): ?>
        <!-- High-visibility Issue Alert Banner -->
        <div style="background:#FFF5F5;border:1.5px solid #FEB2B2;border-left:5px solid var(--red);padding:14px 18px;border-radius:10px;margin-bottom:18px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
            <div>
              <h3 style="color:#C53030;font-size:15px;margin:0 0 4px;display:flex;align-items:center;gap:6px">
                <?= icon('alert-triangle', 16) ?> Issue Raised by Supplier
              </h3>
              <p style="font-size:12px;color:var(--text-muted);margin:0 0 8px">
                Reported on <?= date('M d, Y g:i A', strtotime($po['issue_raised_at'])) ?>
              </p>
              <div style="font-size:13.5px;color:#2D3748;background:#FFF;padding:10px 14px;border-radius:8px;border:1px solid #FED7D7;line-height:1.5">
                <?= nl2br(htmlspecialchars($po['issue_notes'])) ?>
              </div>
            </div>
            <?php if (has_permission('procurement.po.manage')): ?>
              <button type="button" class="btn-save" style="background:var(--green);border-color:var(--green);padding:8px 16px;font-size:13px;display:inline-flex;align-items:center;gap:6px" onclick="openResolveModal()">
                <?= icon('check', 14) ?> Resolve Issue
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($po['issue_status'] === 'resolved'): ?>
        <!-- Issue Resolved Banner -->
        <div style="background:#F0FFF4;border:1px solid #C6F6D5;border-left:5px solid var(--green);padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
            <strong style="color:#22543D;display:flex;align-items:center;gap:6px">
              <?= icon('check', 14) ?> Supplier Issue Resolved
            </strong>
            <span style="font-size:11.5px;color:var(--text-muted)">
              Resolved on <?= date('M d, Y g:i A', strtotime($po['issue_resolved_at'])) ?>
              <?php if (!empty($po['res_fname'])): ?>
                by <?= htmlspecialchars($po['res_fname'] . ' ' . $po['res_lname']) ?>
              <?php endif; ?>
            </span>
          </div>
          <p style="margin:6px 0 0;color:#2D3748;line-height:1.4">
            <?= nl2br(htmlspecialchars($po['issue_resolution_notes'] ?: 'Management addressed the reported concern.')) ?>
          </p>
        </div>
      <?php endif; ?>

      <div class="table-card" style="padding:22px;display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px">
        <div>
          <h2><?= htmlspecialchars($po['req_title']) ?></h2>
          <p class="muted-cell" style="margin-bottom:14px">
            <strong><?= htmlspecialchars($po['po_number'] ?: ('KM-PO-' . str_pad($po['id'],5,'0',STR_PAD_LEFT))) ?></strong> · <?= htmlspecialchars($po['department']) ?>
          </p>

          <p style="font-size:13px;margin-bottom:4px">
            <strong>Supplier:</strong> <?= htmlspecialchars($po['supplier_name']) ?>
          </p>
          <p style="font-size:13px;margin-bottom:4px">
            <strong>Contact:</strong> <?= htmlspecialchars($po['contact_person'] ?: '—') ?> · <?= htmlspecialchars($po['email'] ?: '—') ?> · <?= htmlspecialchars($po['phone'] ?: '—') ?>
          </p>

          <?php if (!empty($po['contract_ref'])): ?>
            <p style="font-size:13px;margin-top:6px;display:flex;align-items:center;gap:6px">
              <strong>Governing Contract:</strong>
              <a href="purchase_contracts.php?id=<?= $po['contract_id'] ?>" style="color:var(--caramel);font-weight:700;display:inline-flex;align-items:center;gap:4px">
                <?= icon('file-text', 13) ?> <?= htmlspecialchars($po['contract_ref']) ?>
              </a>
            </p>
          <?php endif; ?>

          <p style="font-size:22px;font-weight:800;color:var(--espresso);margin:12px 0">
            ₱<?= number_format($po['total_amount'],2) ?>
          </p>

          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <span class="status-badge status-<?= in_array($po['status'],['closed','delivered'])?'approved':($po['status']==='cancelled'?'rejected':'pending') ?>">
              <?= ucfirst($po['status']) ?>
            </span>
            <?php if ($po['issue_status'] === 'open'): ?>
              <span class="status-badge" style="background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;font-weight:700">
                <?= icon('alert-triangle', 11) ?> Issue Under Review
              </span>
            <?php elseif ($po['issue_status'] === 'resolved'): ?>
              <span class="status-badge" style="background:#DEF7EC;color:#03543F;border:1px solid #BCF0DA;font-weight:700">
                <?= icon('check', 11) ?> Issue Resolved
              </span>
            <?php endif; ?>
          </div>

          <?php if ($po['negotiation_notes']): ?>
            <p style="font-size:12.5px;color:var(--text-muted);font-style:italic;padding:10px;background:#FBF6EF;border-radius:10px">"<?= htmlspecialchars($po['negotiation_notes']) ?>"</p>
          <?php endif; ?>

          <?php if ($po['status']==='closed'): ?>
            <p style="margin-top:12px">
              <span class="status-badge status-approved">Closed</span>
              — rated <?= str_repeat(icon('star', 13, '', 'vertical-align:middle;color:var(--amber);fill:currentColor;'), max(1, min(5, (int)$po['supplier_rating']))) ?>
              <a href="supplier_performace.php?supplier_id=<?= $po['supplier_id'] ?>" style="margin-left:6px;font-size:12px;font-weight:700;color:var(--caramel)">View scorecard →</a>
            </p>
          <?php endif; ?>
        </div>

        <div>
          <h3 style="font-size:13.5px;margin-bottom:8px">Order Progress &amp; Fulfillment Stepper</h3>

          <!-- Step 1: PO Sent -->
          <div class="po-step">
            <div class="po-step-dot <?= $po['status']!=='draft'?'done':'' ?>"><?= $po['status']!=='draft'? icon('check', 11) : '' ?></div>
            <div class="po-step-label">PO Sent to Supplier</div>
            <?php if ($po['status']==='draft' && has_permission('procurement.po.manage')): ?>
              <form method="POST" class="po-step-action"><input type="hidden" name="action" value="send"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/>
                <button type="submit" class="act-btn act-activate"><?= icon('send', 13) ?> Send PO</button></form>
            <?php endif; ?>
          </div>

          <!-- Step 2: Supplier Acknowledgment & Issue Status -->
          <div class="po-step">
            <div class="po-step-dot <?= $po['acknowledged_at'] ? 'done' : ($po['issue_status']==='open' ? 'warning' : '') ?>">
              <?= $po['acknowledged_at'] ? icon('check', 11) : ($po['issue_status']==='open' ? icon('alert-triangle', 11) : '') ?>
            </div>
            <div class="po-step-label">
              <?php if ($po['acknowledged_at']): ?>
                Supplier Acknowledged (<?= date('M d, Y', strtotime($po['acknowledged_at'])) ?>)
              <?php elseif ($po['issue_status'] === 'open'): ?>
                <span style="color:var(--red);font-weight:700">⚠️ Issue Raised (Awaiting Resolution)</span>
              <?php else: ?>
                <span class="muted-cell">Awaiting Supplier Acknowledgment</span>
              <?php endif; ?>
            </div>
            <?php if ($po['issue_status'] === 'open' && has_permission('procurement.po.manage')): ?>
              <div class="po-step-action">
                <button type="button" class="act-btn act-activate" style="color:var(--green);border-color:var(--green)" onclick="openResolveModal()">
                  <?= icon('check', 12) ?> Resolve
                </button>
              </div>
            <?php endif; ?>
          </div>

          <!-- Step 3: Shipped -->
          <div class="po-step">
            <div class="po-step-dot <?= $po['shipped_at']?'done':'' ?>"><?= $po['shipped_at']? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              Shipped by Supplier<?= $po['shipped_at'] ? ' (' . date('M d, Y', strtotime($po['shipped_at'])) . ')' : '' ?>
              <?= $po['shipping_notes'] ? ' — ' . htmlspecialchars($po['shipping_notes']) : '' ?>
            </div>
          </div>

          <!-- Step 4: Goods Receipt -->
          <div class="po-step">
            <div class="po-step-dot <?= in_array($po['status'],['delivered','closed'])?'done':'' ?>"><?= in_array($po['status'],['delivered','closed'])? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              Goods Received
              <?php if (in_array($po['status'],['delivered','closed']) && !empty($po['delivered_at'])): ?>
                (<?= date('M d, Y', strtotime($po['delivered_at'])) ?>)
              <?php elseif (!empty($linked_grn)): ?>
                (GRN #<?= str_pad($linked_grn['id'], 5, '0', STR_PAD_LEFT) ?>)
              <?php endif; ?>
            </div>
            <?php if (in_array($po['status'],['sent','acknowledged'],true) && has_permission('procurement.receiving')): ?>
              <div class="po-step-action">
                <button type="button" class="act-btn act-activate" onclick="window.location.href='goods_receipts.php?po_id=<?= $po['id'] ?>'"><?= icon('package', 13) ?> Record Receipt</button>
              </div>
            <?php endif; ?>
          </div>

          <!-- Step 5: Invoicing -->
          <?php
            $inv_matched = !empty($po['invoice_number']) || (!empty($linked_invoice) && in_array($linked_invoice['status'], ['matched','approved','paid'], true));
            $inv_display_num = $po['invoice_number'] ?: ($linked_invoice['invoice_number'] ?? '');
          ?>
          <div class="po-step">
            <div class="po-step-dot <?= $inv_matched ? 'done' : (!empty($linked_invoice) && $linked_invoice['status'] === 'needs_correction' ? 'warning' : '') ?>">
              <?= $inv_matched ? icon('check', 11) : (!empty($linked_invoice) && $linked_invoice['status'] === 'needs_correction' ? icon('alert-triangle', 11) : '') ?>
            </div>
            <div class="po-step-label">
              <?php if ($inv_matched): ?>
                Invoice Matched <?= $inv_display_num ? '(#' . htmlspecialchars($inv_display_num) . ')' : '' ?>
              <?php elseif (!empty($linked_invoice)): ?>
                Invoice <?= htmlspecialchars($linked_invoice['invoice_number']) ?> (<?= status_badge($linked_invoice['status']) ?>)
              <?php else: ?>
                <span class="muted-cell">Invoice Matched</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($linked_invoice)): ?>
              <div class="po-step-action">
                <a href="invoices.php?id=<?= $linked_invoice['id'] ?>" class="act-btn"><?= icon('eye', 12) ?> View Invoice</a>
              </div>
            <?php elseif ($po['status']==='delivered' && has_permission('procurement.invoice.create')): ?>
              <div class="po-step-action">
                <a href="invoices.php?new_for_po=<?= $po['id'] ?>" class="act-btn act-activate"><?= icon('invoice', 12) ?> Log Invoice</a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Step 6: Payment -->
          <div class="po-step">
            <div class="po-step-dot <?= $gate_invoice_paid ? 'done' : '' ?>"><?= $gate_invoice_paid ? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              Payment Sent <?= $po['paid_at'] ? '(' . date('M d, Y', strtotime($po['paid_at'])) . ')' : '' ?>
            </div>
            <?php if (!$gate_invoice_paid && !empty($linked_invoice) && in_array($linked_invoice['status'], ['approved', 'matched'], true) && has_permission('procurement.payment.process')): ?>
              <div class="po-step-action">
                <a href="payments.php?new_for_invoice=<?= $linked_invoice['id'] ?>" class="act-btn act-activate"><?= icon('dollar', 12) ?> Schedule Payment</a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Step 7: Order Closure & Supplier Rating -->
          <div class="po-step" style="border-bottom:none">
            <div class="po-step-dot <?= $po['status'] === 'closed' ? 'done' : '' ?>"><?= $po['status'] === 'closed' ? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              <?php if ($po['status'] === 'closed'): ?>
                Order Closed &amp; Rated <?= $po['closed_at'] ? '(' . date('M d, Y', strtotime($po['closed_at'])) . ')' : '' ?>
                <?= $po['supplier_rating'] ? ' — ' . $po['supplier_rating'] . '/5 Stars' : '' ?>
              <?php else: ?>
                <span class="muted-cell">Order Closure &amp; Rating</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Closure Gate Readiness Panel -->
          <?php if ($po['status'] !== 'closed'): ?>
            <div style="margin-top:16px;padding:14px 16px;background:<?= $can_close ? '#F0FDF4' : '#FEFAF4' ?>;border:1.5px solid <?= $can_close ? '#BBF7D0' : '#FDE68A' ?>;border-radius:10px;font-size:12.5px">
              <div style="font-weight:700;color:var(--espresso);margin-bottom:8px;display:flex;align-items:center;gap:6px">
                <?= $can_close ? icon('check-circle', 16, '', 'color:#15803D') : icon('lock', 15, '', 'color:#B45309') ?>
                Closure Gate Requirements
              </div>
              <div style="display:grid;grid-template-columns:1fr;gap:6px;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_receipt_ok ? '#15803D' : '#991B1B' ?>">
                  <?= $gate_receipt_ok ? icon('check', 13) : icon('x', 13) ?>
                  <span>Goods Delivery: <strong><?= $gate_receipt_ok ? 'Delivered & Received' : 'Pending Receipt' ?></strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_invoice_paid ? '#15803D' : '#991B1B' ?>">
                  <?= $gate_invoice_paid ? icon('check', 13) : icon('x', 13) ?>
                  <span>Invoice &amp; Payment: <strong><?= $gate_invoice_paid ? 'Paid' : 'Pending Payment' ?></strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_issue_ok ? '#15803D' : '#991B1B' ?>">
                  <?= $gate_issue_ok ? icon('check', 13) : icon('x', 13) ?>
                  <span>Supplier Issues: <strong><?= $gate_issue_ok ? 'None / Resolved' : 'Active Issue Under Review' ?></strong></span>
                </div>
              </div>

              <?php if (has_permission('procurement.performance.rate')): ?>
                <?php if ($can_close): ?>
                  <button type="button" class="btn-save" style="width:100%" onclick="openCloseRateModal()">
                    <?= icon('flag', 14) ?> Close Order &amp; Rate Supplier
                  </button>
                <?php else: ?>
                  <button type="button" class="btn-save" disabled style="width:100%;opacity:0.55;cursor:not-allowed;background:#9CA3AF">
                    <?= icon('lock', 14) ?> Close Order (Gated — Complete Requirements Above)
                  </button>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Line Items Schedule Table -->
      <div class="table-card" style="padding:20px;margin-bottom:20px">
        <h3 style="font-size:14px;margin-bottom:12px;display:flex;align-items:center;gap:6px">
          <?= icon('clipboard', 14) ?> Agreed Line Items Schedule
        </h3>
        <div class="table-scroll-wrapper" style="margin:0">
          <table style="width:100%">
            <thead>
              <tr style="background:#FAF8F5">
                <th style="text-align:left">Item Description</th>
                <th style="text-align:right">Quantity</th>
                <th style="text-align:left">Unit</th>
                <th style="text-align:right">Unit Price</th>
                <th style="text-align:right">Line Total</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $subtot = 0;
              foreach ($po_items as $it): 
                $lt = (float)($it['line_total'] ?? ((float)$it['quantity'] * (float)$it['unit_price']));
                $subtot += $lt;
              ?>
                <tr>
                  <td style="font-weight:600"><?= htmlspecialchars($it['item_name']) ?></td>
                  <td style="text-align:right"><?= number_format((float)$it['quantity'], 2) ?></td>
                  <td><?= htmlspecialchars($it['unit'] ?? 'pcs') ?></td>
                  <td style="text-align:right">₱<?= number_format((float)$it['unit_price'], 2) ?></td>
                  <td style="text-align:right;font-weight:700">₱<?= number_format($lt, 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="font-weight:700;border-top:1.5px solid var(--border)">
                <td colspan="4" style="text-align:right">Agreed Order Total:</td>
                <td style="text-align:right;color:var(--espresso);font-size:15px">₱<?= number_format($po['total_amount'], 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Resolve Issue Modal -->
      <div class="modal-overlay" id="modal-resolve-issue">
        <div class="modal">
          <div class="modal-header">
            <h3 style="display:flex;align-items:center;gap:6px">
              <?= icon('check', 16, '', 'color:var(--green)') ?> Resolve Supplier Issue
            </h3>
            <button class="modal-close" onclick="closeResolveModal()"><?= icon('x', 14) ?></button>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="resolve_issue"/>
            <input type="hidden" name="id" value="<?= $po['id'] ?>"/>
            <div class="modal-body">
              <div style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12.5px">
                <strong style="color:#C53030">Supplier Issue Note:</strong>
                <p style="margin:4px 0 0;color:#2D3748"><?= nl2br(htmlspecialchars($po['issue_notes'])) ?></p>
              </div>
              <div class="field-group">
                <label class="field-label">Resolution Details &amp; Feedback for Supplier <span style="color:var(--red)">*</span></label>
                <textarea name="issue_resolution_notes" class="field-input" rows="4" placeholder="Detail how the issue was addressed (e.g., terms clarified, adjusted delivery schedule agreed)..." required style="resize:vertical"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-cancel" onclick="closeResolveModal()">Cancel</button>
              <button type="submit" class="btn-save" style="background:var(--green);border-color:var(--green)">
                <?= icon('check', 14) ?> Confirm Resolution
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Close & Rate Supplier Modal -->
      <?php if ($po && $can_close && has_permission('procurement.performance.rate')): ?>
      <div class="modal-overlay" id="modal-close-rate">
        <div class="modal" style="max-width:520px">
          <div class="modal-header">
            <h3 style="display:flex;align-items:center;gap:6px">
              <?= icon('flag', 16, '', 'color:var(--caramel)') ?> Close Order &amp; Rate Supplier
            </h3>
            <button class="modal-close" onclick="closeCloseRateModal()"><?= icon('x', 14) ?></button>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="close_and_rate"/>
            <input type="hidden" name="id" value="<?= $po['id'] ?>"/>
            <div class="modal-body" style="padding:16px 20px">
              <p style="margin:0 0 14px;font-size:12.5px;color:var(--text-muted)">
                Formal closure of PO #<?= str_pad($po['id'], 5, '0', STR_PAD_LEFT) ?> for <strong><?= htmlspecialchars($po['supplier_name']) ?></strong>. All items were confirmed delivered and invoice paid. Rate the supplier's performance on this delivery:
              </p>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div class="field-group" style="margin:0">
                  <label class="field-label">Quality of Goods (1–5)</label>
                  <select class="field-input" name="quality_score" required>
                    <option value="5" selected>5 — Excellent</option>
                    <option value="4">4 — Good</option>
                    <option value="3">3 — Acceptable</option>
                    <option value="2">2 — Poor</option>
                    <option value="1">1 — Very Poor</option>
                  </select>
                </div>
                <div class="field-group" style="margin:0">
                  <label class="field-label">On-Time Delivery (1–5)</label>
                  <select class="field-input" name="timeliness_score" required>
                    <option value="5" selected>5 — On Time / Early</option>
                    <option value="4">4 — Minor Delay</option>
                    <option value="3">3 — Moderate Delay</option>
                    <option value="2">2 — Major Delay</option>
                    <option value="1">1 — Unacceptable</option>
                  </select>
                </div>
                <div class="field-group" style="margin:0">
                  <label class="field-label">Price Fairness (1–5)</label>
                  <select class="field-input" name="price_score" required>
                    <option value="5" selected>5 — Highly Competitive</option>
                    <option value="4">4 — Fair</option>
                    <option value="3">3 — Standard</option>
                    <option value="2">2 — Higher Than Expected</option>
                    <option value="1">1 — Overpriced</option>
                  </select>
                </div>
                <div class="field-group" style="margin:0">
                  <label class="field-label">Responsiveness (1–5)</label>
                  <select class="field-input" name="communication_score" required>
                    <option value="5" selected>5 — Fast &amp; Helpful</option>
                    <option value="4">4 — Responsive</option>
                    <option value="3">3 — Slow Response</option>
                    <option value="2">2 — Poor Communication</option>
                    <option value="1">1 — Unresponsive</option>
                  </select>
                </div>
              </div>

              <div class="field-group" style="margin:0">
                <label class="field-label">Review / Evaluation Notes (optional)</label>
                <textarea class="field-input" name="comments" rows="2" placeholder="e.g. Excellent packaging, goods fresh and delivered right on schedule."></textarea>
              </div>
            </div>
            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid var(--border)">
              <button type="button" class="btn-cancel" onclick="closeCloseRateModal()">Cancel</button>
              <button type="submit" class="btn-save"><?= icon('check', 13) ?> Confirm Closure &amp; Rate</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ── PO list view ── -->
      <div class="filter-bar" style="padding:0">
        <a href="purchase_orders.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="purchase_orders.php?status=sent" class="filter-pill <?= $filter==='sent'?'active':'' ?>">Sent</a>
        <a href="purchase_orders.php?status=acknowledged" class="filter-pill <?= $filter==='acknowledged'?'active':'' ?>">Acknowledged</a>
        <a href="purchase_orders.php?status=delivered" class="filter-pill <?= $filter==='delivered'?'active':'' ?>">Delivered</a>
        <a href="purchase_orders.php?status=closed" class="filter-pill <?= $filter==='closed'?'active':'' ?>">Closed</a>
        <a href="purchase_orders.php?status=issues" class="filter-pill <?= $filter==='issues'?'active':'' ?>" style="<?= $open_issues_count > 0 ? 'background:#FEE2E2;color:#991B1B;border-color:#FCA5A5;' : '' ?>">
          <?= icon('alert-triangle', 12) ?> Open Issues (<?= $open_issues_count ?>)
        </a>
      </div>
      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view all columns</span>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr>
              <th class="col-sticky">PO #</th>
              <th>Contract</th>
              <th>Requisition</th>
              <th>Supplier</th>
              <th>Total</th>
              <th>Status</th>
              <th>Date</th>
              <th style="text-align:center;width:95px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($pos)): ?>
            <tr class="empty-row"><td colspan="8"><?= icon('inbox', 18) ?> No purchase orders found for this view.</td></tr>
          <?php else: foreach ($pos as $p): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700">
                <?= htmlspecialchars($p['po_number'] ?: ('# ' . str_pad($p['id'],5,'0',STR_PAD_LEFT))) ?>
              </td>
              <td>
                <?php if (!empty($p['contract_ref'])): ?>
                  <span style="font-family:monospace;font-size:12px;font-weight:700;color:var(--espresso)">
                    <?= htmlspecialchars($p['contract_ref']) ?>
                  </span>
                <?php else: ?>
                  <span class="muted-cell">—</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($p['req_title']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700">₱<?= number_format($p['total_amount'],2) ?></td>
              <td>
                <span class="status-badge status-<?= $p['status']==='closed'?'approved':($p['status']==='cancelled'?'rejected':'pending') ?>">
                  <?= ucfirst($p['status']) ?>
                </span>
                <?php if ($p['issue_status'] === 'open'): ?>
                  <span class="status-badge" style="background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;font-weight:700;margin-left:4px;display:inline-flex;align-items:center;gap:3px">
                    <?= icon('alert-triangle', 10) ?> Issue Open
                  </span>
                <?php endif; ?>
              </td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
              <td style="text-align:center">
                <button class="act-btn" onclick="window.location.href='purchase_orders.php?id=<?= $p['id'] ?>'"><?= icon('eye', 13) ?> View</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
function openResolveModal() {
  const m = document.getElementById('modal-resolve-issue');
  if (m) m.classList.add('open');
}
function closeResolveModal() {
  const m = document.getElementById('modal-resolve-issue');
  if (m) m.classList.remove('open');
}
function openCloseRateModal() {
  const m = document.getElementById('modal-close-rate');
  if (m) m.classList.add('open');
}
function closeCloseRateModal() {
  const m = document.getElementById('modal-close-rate');
  if (m) m.classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { 
    if (e.target === el) {
      closeResolveModal();
      closeCloseRateModal();
    }
  });
});
document.addEventListener('keydown', e => { 
  if (e.key === 'Escape') {
    closeResolveModal();
    closeCloseRateModal();
  }
});
</script>

</body>
</html>