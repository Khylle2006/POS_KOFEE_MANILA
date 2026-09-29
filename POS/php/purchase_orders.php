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
        } elseif (!in_array($target_po['status'], ['delivered', 'pending_rating'], true)) {
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

            // Gating: Supplier must have confirmed receipt of payment
            $payment_confirmed = !empty($target_po['supplier_payment_confirmed_at']);
            if (!$payment_confirmed) {
                $chk_conf = $pdo->prepare("
                    SELECT COUNT(*) FROM payments 
                    WHERE po_id = :p AND status = 'completed' AND supplier_confirmation_status != 'confirmed'
                ");
                $chk_conf->execute([':p' => $id]);
                $unconf_cnt = (int)$chk_conf->fetchColumn();

                $chk_any = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE po_id = :p AND status = 'completed'");
                $chk_any->execute([':p' => $id]);
                $any_completed = (int)$chk_any->fetchColumn();

                if ($any_completed > 0 && $unconf_cnt === 0) {
                    $payment_confirmed = true;
                }
            }

            if (!$inv_paid) {
                $toast = 'Cannot close order: Invoice has not been marked as paid yet.';
                $toast_type = 'error';
            } elseif (!$payment_confirmed) {
                $toast = 'Cannot close order: Supplier has not confirmed receipt of payment in their portal.';
                $toast_type = 'error';
            } else {
                $is_skipped  = !empty($_POST['is_skipped']) || !empty($_POST['skip_rating']);
                $skip_reason = trim($_POST['skip_reason'] ?? '');
                $comments    = trim($_POST['comments'] ?? '');

                if ($is_skipped) {
                    if (!$skip_reason) {
                        $toast = 'Please provide an explicit reason for skipping the supplier evaluation.';
                        $toast_type = 'error';
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $pdo->prepare('
                                INSERT INTO supplier_performance_ratings
                                    (po_id, supplier_id, rated_by, is_skipped, skip_reason, comments)
                                VALUES (:po, :sup, :u, 1, :sr, :cm)
                            ')->execute([
                                ':po' => $id, ':sup' => $target_po['supplier_id'], ':u' => $user['id'],
                                ':sr' => $skip_reason, ':cm' => $comments ?: null,
                            ]);

                            $pdo->prepare("
                                UPDATE purchase_orders 
                                SET status = 'closed', rating_status = 'skipped', is_locked = 1, closed_at = NOW(), closed_notes = :cn 
                                WHERE id = :id
                            ")->execute([':cn' => "Rating skipped: {$skip_reason}", ':id' => $id]);

                            $pdo->prepare("UPDATE purchase_requisitions SET status = 'closed' WHERE id = :id")
                                ->execute([':id' => $target_po['requisition_id']]);

                            $pdo->commit();

                            audit_log('po', $id, 'closed', "Order closed with rating skipped ({$skip_reason})");
                            $toast = "Order closed successfully with supplier rating skipped.";
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $toast = $e->getMessage();
                            $toast_type = 'error';
                        }
                    }
                } else {
                    // 6 Dimensions evaluation:
                    $quality   = max(1, min(5, (int)($_POST['quality_score'] ?? 5)));
                    $timely    = max(1, min(5, (int)($_POST['timeliness_score'] ?? 5)));
                    $qty_acc   = max(1, min(5, (int)($_POST['quantity_accuracy_score'] ?? 5)));
                    $price     = max(1, min(5, (int)($_POST['price_score'] ?? 5)));
                    $comm      = max(1, min(5, (int)($_POST['communication_score'] ?? 5)));
                    $comp      = max(1, min(5, (int)($_POST['compliance_score'] ?? 5)));

                    // Weighted overall calculation:
                    $overall = round(
                        ($quality * 0.25) +
                        ($timely * 0.20) +
                        ($qty_acc * 0.15) +
                        ($price * 0.15) +
                        ($comm * 0.15) +
                        ($comp * 0.10),
                        2
                    );

                    try {
                        $pdo->beginTransaction();

                        $pdo->prepare('
                            INSERT INTO supplier_performance_ratings
                                (po_id, supplier_id, rated_by, quality_score, timeliness_score, quantity_accuracy_score, price_score, communication_score, compliance_score, overall_score, comments)
                            VALUES (:po, :sup, :u, :q, :t, :qa, :p, :c, :comp, :ov, :cm)
                        ')->execute([
                            ':po'   => $id, 
                            ':sup'  => $target_po['supplier_id'], 
                            ':u'    => $user['id'],
                            ':q'    => $quality, 
                            ':t'    => $timely, 
                            ':qa'   => $qty_acc,
                            ':p'    => $price, 
                            ':c'    => $comm, 
                            ':comp' => $comp,
                            ':ov'   => $overall,
                            ':cm'   => $comments ?: null,
                        ]);

                        // Update supplier historical rating
                        $pdo->prepare('
                            UPDATE suppliers
                            SET rating_avg = ROUND(((COALESCE(rating_avg, 0) * rating_count) + :o) / (rating_count + 1), 2),
                                rating_count = rating_count + 1
                            WHERE id = :sid
                        ')->execute([':o' => $overall, ':sid' => $target_po['supplier_id']]);

                        $pdo->prepare("
                            UPDATE purchase_orders 
                            SET status = 'closed', rating_status = 'rated', is_locked = 1, closed_at = NOW(), supplier_rating = :r 
                            WHERE id = :id
                        ")->execute([':r' => round($overall), ':id' => $id]);

                        $pdo->prepare("UPDATE purchase_requisitions SET status = 'closed' WHERE id = :id")
                            ->execute([':id' => $target_po['requisition_id']]);

                        $pdo->commit();

                        audit_log('po', $id, 'closed', "Procurement cycle complete, overall 6-dim rating {$overall}/5");
                        audit_log('supplier', $target_po['supplier_id'], 'performance_rated', "PO #$id — 6-dimension evaluation {$overall}/5");

                        $toast = "Order closed successfully — supplier evaluated at {$overall}/5.0.";
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $toast = $e->getMessage();
                        $toast_type = 'error';
                    }
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

        // Linked payments & confirmation info
        $pay_conf_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_payments,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_payments,
                SUM(CASE WHEN supplier_confirmation_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_payments,
                SUM(CASE WHEN supplier_confirmation_status = 'disputed' THEN 1 ELSE 0 END) AS disputed_payments
            FROM payments WHERE po_id = :id
        ");
        $pay_conf_stmt->execute([':id' => $view_id]);
        $pay_stats = $pay_conf_stmt->fetch() ?: ['total_payments' => 0, 'completed_payments' => 0, 'confirmed_payments' => 0, 'disputed_payments' => 0];

        $gate_receipt_ok        = in_array($po['status'], ['delivered', 'closed', 'pending_rating'], true) || !empty($linked_grn);
        $gate_invoice_paid      = !empty($po['paid_at']) || (!empty($linked_invoice) && $linked_invoice['status'] === 'paid');
        $gate_payment_confirmed = !empty($po['supplier_payment_confirmed_at']) || 
            (($pay_stats['completed_payments'] > 0) && ($pay_stats['completed_payments'] == $pay_stats['confirmed_payments']));
        $payment_is_disputed    = ($pay_stats['disputed_payments'] > 0);
        $gate_issue_ok          = ($po['issue_status'] !== 'open');
        $can_close              = ($gate_receipt_ok && $gate_invoice_paid && $gate_payment_confirmed && !$payment_is_disputed && $gate_issue_ok && $po['status'] !== 'closed');
    }
}

function get_po_lifecycle_state(array $po, ?array $pay_stats = [], ?array $linked_grn = null, ?array $linked_invoice = null): array {
    $pay_stats = $pay_stats ?? [];
    if ($po['status'] === 'closed') {
        return ['code' => 'closed', 'label' => 'Closed', 'step' => 9, 'badge_class' => 'status-approved'];
    }
    if ($po['status'] === 'cancelled') {
        return ['code' => 'cancelled', 'label' => 'Cancelled', 'step' => 0, 'badge_class' => 'status-rejected'];
    }
    if (($pay_stats['disputed_payments'] ?? 0) > 0) {
        return ['code' => 'disputed', 'label' => 'Payment Disputed', 'step' => 7, 'badge_class' => 'status-rejected'];
    }
    if (!empty($po['supplier_payment_confirmed_at']) || (($pay_stats['completed_payments'] ?? 0) > 0 && $pay_stats['completed_payments'] == $pay_stats['confirmed_payments'])) {
        return ['code' => 'pending_rating', 'label' => 'Pending Supplier Rating', 'step' => 8, 'badge_class' => 'status-pending'];
    }
    if (!empty($po['paid_at']) || (($pay_stats['completed_payments'] ?? 0) > 0)) {
        return ['code' => 'awaiting_confirmation', 'label' => 'Awaiting Supplier Confirmation', 'step' => 7, 'badge_class' => 'status-pending'];
    }
    if (!empty($linked_invoice) && in_array($linked_invoice['status'], ['approved', 'matched'], true)) {
        return ['code' => 'pending_payment', 'label' => 'Pending Payment', 'step' => 6, 'badge_class' => 'status-pending'];
    }
    if (in_array($po['status'], ['delivered'], true) || !empty($linked_grn)) {
        return ['code' => 'delivered', 'label' => 'Fully Received', 'step' => 4, 'badge_class' => 'status-approved'];
    }
    if ($po['acknowledged_at'] || $po['status'] === 'acknowledged') {
        return ['code' => 'acknowledged', 'label' => 'Acknowledged', 'step' => 3, 'badge_class' => 'status-pending'];
    }
    if ($po['status'] === 'sent') {
        return ['code' => 'sent', 'label' => 'Sent to Supplier', 'step' => 2, 'badge_class' => 'status-pending'];
    }
    return ['code' => 'draft', 'label' => 'Draft', 'step' => 1, 'badge_class' => 'status-pending'];
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
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title, c.contract_ref,
           (SELECT COUNT(*) FROM payments p WHERE p.po_id = po.id AND p.status = 'completed') AS completed_payments_count,
           (SELECT COUNT(*) FROM payments p WHERE p.po_id = po.id AND p.status = 'completed' AND p.supplier_confirmation_status = 'confirmed') AS confirmed_payments_count,
           (SELECT COUNT(*) FROM payments p WHERE p.po_id = po.id AND p.status = 'completed' AND p.supplier_confirmation_status = 'disputed') AS disputed_payments_count,
           (SELECT i.status FROM invoices i WHERE i.po_id = po.id ORDER BY i.id DESC LIMIT 1) AS inv_status,
           (SELECT grn.id FROM goods_receipts grn WHERE grn.po_id = po.id ORDER BY grn.id DESC LIMIT 1) AS grn_id
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

          <!-- Step 6: Payment Disbursed -->
          <div class="po-step">
            <div class="po-step-dot <?= $gate_invoice_paid ? 'done' : '' ?>"><?= $gate_invoice_paid ? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              Payment Disbursed <?= $po['paid_at'] ? '(' . date('M d, Y', strtotime($po['paid_at'])) . ')' : '' ?>
            </div>
            <?php if (!$gate_invoice_paid && !empty($linked_invoice) && in_array($linked_invoice['status'], ['approved', 'matched', 'partially_paid'], true) && has_permission('procurement.payment.process')): ?>
              <div class="po-step-action">
                <a href="payments.php?new_for_invoice=<?= $linked_invoice['id'] ?>" class="act-btn act-activate"><?= icon('dollar', 12) ?> Schedule Payment</a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Step 7: Supplier Payment Confirmation -->
          <div class="po-step">
            <div class="po-step-dot <?= $gate_payment_confirmed ? 'done' : ($payment_is_disputed ? 'warning' : '') ?>">
              <?= $gate_payment_confirmed ? icon('check', 11) : ($payment_is_disputed ? icon('alert-triangle', 11) : '') ?>
            </div>
            <div class="po-step-label">
              <?php if ($gate_payment_confirmed): ?>
                Supplier Confirmed Payment <?= $po['supplier_payment_confirmed_at'] ? '(' . date('M d, Y', strtotime($po['supplier_payment_confirmed_at'])) . ')' : '' ?>
              <?php elseif ($payment_is_disputed): ?>
                <span style="color:var(--red);font-weight:700">⚠️ Payment Disputed by Supplier</span>
              <?php elseif ($gate_invoice_paid): ?>
                <span class="muted-cell">Awaiting Supplier Confirmation (Portal)</span>
              <?php else: ?>
                <span class="muted-cell">Supplier Payment Confirmation</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Step 8: Order Closure & Supplier Rating -->
          <div class="po-step" style="border-bottom:none">
            <div class="po-step-dot <?= $po['status'] === 'closed' ? 'done' : '' ?>"><?= $po['status'] === 'closed' ? icon('check', 11) : '' ?></div>
            <div class="po-step-label">
              <?php if ($po['status'] === 'closed'): ?>
                Order Closed <?= $po['closed_at'] ? '(' . date('M d, Y', strtotime($po['closed_at'])) . ')' : '' ?>
                <?php if ($po['rating_status'] === 'skipped'): ?>
                  <span class="muted-cell">— Rating Skipped (<?= htmlspecialchars($po['skip_reason'] ?? 'Exempt') ?>)</span>
                <?php elseif ($po['supplier_rating']): ?>
                  — <strong><?= number_format((float)$po['supplier_rating'], 2) ?>/5.00 Stars</strong>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted-cell">Order Closure &amp; Supplier Rating</span>
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
                  <span>1. Goods Delivery: <strong><?= $gate_receipt_ok ? 'Delivered & Received' : 'Pending Receipt' ?></strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_invoice_paid ? '#15803D' : '#991B1B' ?>">
                  <?= $gate_invoice_paid ? icon('check', 13) : icon('x', 13) ?>
                  <span>2. Payment Disbursement: <strong><?= $gate_invoice_paid ? 'Paid' : 'Pending Payment' ?></strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_payment_confirmed ? '#15803D' : ($payment_is_disputed ? '#B91C1C' : '#991B1B') ?>">
                  <?= $gate_payment_confirmed ? icon('check', 13) : ($payment_is_disputed ? icon('alert-triangle', 13) : icon('x', 13)) ?>
                  <span>3. Supplier Confirmation: <strong><?= $gate_payment_confirmed ? 'Confirmed Received' : ($payment_is_disputed ? 'Disputed by Supplier' : 'Awaiting Supplier Confirmation') ?></strong></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;color:<?= $gate_issue_ok ? '#15803D' : '#991B1B' ?>">
                  <?= $gate_issue_ok ? icon('check', 13) : icon('x', 13) ?>
                  <span>4. Supplier Issues: <strong><?= $gate_issue_ok ? 'None / Resolved' : 'Active Issue Under Review' ?></strong></span>
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
          <?php else: ?>
            <div style="margin-top:16px;padding:12px 14px;background:#F1F5F9;border:1.5px solid #CBD5E1;border-radius:10px;font-size:12.5px;color:#334155;display:flex;align-items:center;gap:8px">
              <?= icon('lock', 16, '', 'color:#64748B') ?>
              <div>
                <strong>PO Finalized &amp; Locked:</strong> Closed on <?= date('M d, Y', strtotime($po['closed_at'])) ?> by <?= htmlspecialchars($po['closed_by_name'] ?? 'Authorized Officer') ?>.
                <?php if ($po['rating_status'] === 'skipped'): ?>
                  <span style="display:block;margin-top:2px;color:#64748B">Performance rating was exempted/skipped. Reason: <?= htmlspecialchars($po['skip_reason'] ?? 'Not specified') ?>.</span>
                <?php elseif ($po['supplier_rating']): ?>
                  <span style="display:block;margin-top:2px;color:#059669;font-weight:600">Rated <?= number_format((float)$po['supplier_rating'], 2) ?>/5.00 Stars.</span>
                <?php endif; ?>
              </div>
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
        <div class="modal" style="max-width:580px">
          <div class="modal-header">
            <h3 style="display:flex;align-items:center;gap:6px">
              <?= icon('flag', 16, '', 'color:var(--caramel)') ?> Close Purchase Order &amp; Evaluate Supplier
            </h3>
            <button class="modal-close" onclick="closeCloseRateModal()"><?= icon('x', 14) ?></button>
          </div>
          <form method="POST" id="close-rate-form">
            <input type="hidden" name="action" value="close_and_rate"/>
            <input type="hidden" name="id" value="<?= $po['id'] ?>"/>
            <div class="modal-body" style="padding:16px 20px;max-height:75vh;overflow-y:auto">
              <p style="margin:0 0 12px;font-size:12.5px;color:var(--text-muted)">
                Formal closure of PO #<?= str_pad($po['id'], 5, '0', STR_PAD_LEFT) ?> for <strong><?= htmlspecialchars($po['supplier_name']) ?></strong>. All items were confirmed delivered and invoice payment confirmed. Evaluate supplier performance across the 6 procurement dimensions or record an authorized exemption:
              </p>

              <!-- Live Weighted Score Card -->
              <div style="background:#FAF5EE;border:1.5px solid #E5D5C5;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between" id="scorecard-summary-card">
                <div>
                  <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Calculated Overall Score</span>
                  <div style="font-size:22px;font-weight:800;color:var(--espresso);display:flex;align-items:baseline;gap:4px">
                    <span id="live-weighted-score">5.00</span>
                    <span style="font-size:13px;font-weight:600;color:var(--text-muted)">/ 5.00 Stars</span>
                  </div>
                </div>
                <div id="live-score-badge" style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;background:#DCFCE7;color:#15803D">
                  ★ Excellent Partner
                </div>
              </div>

              <!-- Skip Rating Toggle -->
              <div style="margin-bottom:14px;padding:10px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px">
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:var(--espresso);cursor:pointer;margin:0">
                  <input type="checkbox" id="skip_rating_toggle" name="skip_rating" value="1" onchange="toggleSkipRating(this.checked)" style="width:16px;height:16px;accent-color:var(--caramel)">
                  <span>Skip Supplier Performance Rating for this PO</span>
                </label>
                <div id="skip_reason_container" style="display:none;margin-top:10px">
                  <label class="field-label" style="font-size:12px">Mandatory Exemption Reason <span style="color:var(--red)">*</span></label>
                  <select name="skip_reason" id="skip_reason_input" class="field-input">
                    <option value="">-- Select Reason for Exemption --</option>
                    <option value="Minor consumable / low-value order">Minor consumable / low-value order</option>
                    <option value="Recurring utility / standard service">Recurring utility / standard service</option>
                    <option value="Supplier exempt from performance rating">Supplier exempt from performance rating</option>
                    <option value="One-time emergency procurement exception">One-time emergency procurement exception</option>
                    <option value="Other documented justification">Other documented justification</option>
                  </select>
                </div>
              </div>

              <!-- 6 Dimensions Scorecard Inputs -->
              <div id="scorecard-inputs-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">1. Quality &amp; Specs (25%)</label>
                  <select class="field-input score-input" name="quality_score" id="score_quality" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — Exceptional (Zero defects)</option>
                    <option value="4">4 — Good (Minor acceptable variances)</option>
                    <option value="3">3 — Standard (Meets specifications)</option>
                    <option value="2">2 — Marginal (Notable flaws/spoilage)</option>
                    <option value="1">1 — Unsatisfactory (Rejected/unusable)</option>
                  </select>
                </div>

                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">2. Timeliness (20%)</label>
                  <select class="field-input score-input" name="timeliness_score" id="score_timeliness" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — On Time / Early</option>
                    <option value="4">4 — Minor Delay (&lt; 24h)</option>
                    <option value="3">3 — Moderate Delay (1-2 days)</option>
                    <option value="2">2 — Significant Delay (&gt; 3 days)</option>
                    <option value="1">1 — Unacceptable Delay / No Show</option>
                  </select>
                </div>

                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">3. Quantity Accuracy (15%)</label>
                  <select class="field-input score-input" name="quantity_accuracy_score" id="score_quantity" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — 100% Exact Count &amp; Pack</option>
                    <option value="4">4 — Minor discrepancy resolved</option>
                    <option value="3">3 — Acceptable count tolerance</option>
                    <option value="2">2 — Frequent short shipment</option>
                    <option value="1">1 — Severe quantity mismatch</option>
                  </select>
                </div>

                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">4. Price Accuracy (15%)</label>
                  <select class="field-input score-input" name="pricing_accuracy_score" id="score_price" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — Exact Match with PO</option>
                    <option value="4">4 — Minor billing variance fixed</option>
                    <option value="3">3 — Standard billing accuracy</option>
                    <option value="2">2 — Frequent pricing errors</option>
                    <option value="1">1 — Unauthorized price hikes</option>
                  </select>
                </div>

                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">5. Communication (15%)</label>
                  <select class="field-input score-input" name="communication_score" id="score_communication" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — Proactive &amp; Instant</option>
                    <option value="4">4 — Responsive &amp; Helpful</option>
                    <option value="3">3 — Standard Response Time</option>
                    <option value="2">2 — Slow / Poor Coordination</option>
                    <option value="1">1 — Completely Unresponsive</option>
                  </select>
                </div>

                <div class="field-group" style="margin:0">
                  <label class="field-label" style="font-size:12px">6. Food Safety/Cert (10%)</label>
                  <select class="field-input score-input" name="compliance_score" id="score_compliance" onchange="calculateWeightedScore()" required>
                    <option value="5" selected>5 — Full Certs &amp; Clean Cold Chain</option>
                    <option value="4">4 — Compliant Documentation</option>
                    <option value="3">3 — Meets Minimum Safety Rules</option>
                    <option value="2">2 — Missing Certs / Incomplete Docs</option>
                    <option value="1">1 — Safety/Hygienic Violations</option>
                  </select>
                </div>
              </div>

              <div class="field-group" style="margin:0">
                <label class="field-label" style="font-size:12px">Evaluation &amp; Performance Comments (optional)</label>
                <textarea class="field-input" name="comments" rows="2" placeholder="e.g. Pristine bean roasting quality, prompt courier delivery and accurate billing."></textarea>
              </div>
            </div>
            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px;border-top:1px solid var(--border)">
              <button type="button" class="btn-cancel" onclick="closeCloseRateModal()">Cancel</button>
              <button type="submit" class="btn-save" id="close-rate-submit-btn"><?= icon('check', 13) ?> Confirm Rating &amp; Close PO</button>
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
                <?php
                  $lifecycle = get_po_lifecycle_state(
                      $p,
                      [
                          'completed_payments' => (int)($p['completed_payments_count'] ?? 0),
                          'confirmed_payments' => (int)($p['confirmed_payments_count'] ?? 0),
                          'disputed_payments'  => (int)($p['disputed_payments_count'] ?? 0)
                      ],
                      !empty($p['grn_id']) ? ['id' => $p['grn_id']] : null,
                      !empty($p['inv_status']) ? ['status' => $p['inv_status']] : null
                  );
                ?>
                <span class="status-badge <?= htmlspecialchars($lifecycle['badge_class']) ?>">
                  <?= htmlspecialchars($lifecycle['label']) ?>
                </span>
                <?php if ($p['status'] === 'closed'): ?>
                  <?php if (!empty($p['supplier_rating'])): ?>
                    <span class="status-badge" style="background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;font-weight:700;margin-left:4px">
                      ★ <?= number_format((float)$p['supplier_rating'], 1) ?>
                    </span>
                  <?php elseif (($p['rating_status'] ?? '') === 'skipped'): ?>
                    <span class="status-badge" style="background:#F1F5F9;color:#64748B;margin-left:4px" title="Rating skipped: <?= htmlspecialchars($p['skip_reason'] ?? 'Exempt') ?>">
                      Exempt
                    </span>
                  <?php endif; ?>
                <?php endif; ?>
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
  if (m) {
    m.classList.add('open');
    calculateWeightedScore();
  }
}
function closeCloseRateModal() {
  const m = document.getElementById('modal-close-rate');
  if (m) m.classList.remove('open');
}

function calculateWeightedScore() {
  const q = parseFloat(document.getElementById('score_quality')?.value || 5);
  const t = parseFloat(document.getElementById('score_timeliness')?.value || 5);
  const qa = parseFloat(document.getElementById('score_quantity')?.value || 5);
  const p = parseFloat(document.getElementById('score_price')?.value || 5);
  const c = parseFloat(document.getElementById('score_communication')?.value || 5);
  const comp = parseFloat(document.getElementById('score_compliance')?.value || 5);

  const weighted = (q * 0.25) + (t * 0.20) + (qa * 0.15) + (p * 0.15) + (c * 0.15) + (comp * 0.10);
  const rounded = weighted.toFixed(2);

  const el = document.getElementById('live-weighted-score');
  if (el) el.textContent = rounded;

  const badge = document.getElementById('live-score-badge');
  if (badge) {
    if (weighted >= 4.5) {
      badge.textContent = '★ Excellent Partner';
      badge.style.background = '#DCFCE7';
      badge.style.color = '#15803D';
    } else if (weighted >= 3.5) {
      badge.textContent = '★ Good / Reliable';
      badge.style.background = '#E0F2FE';
      badge.style.color = '#0369A1';
    } else if (weighted >= 2.5) {
      badge.textContent = '★ Standard / Meets Spec';
      badge.style.background = '#FEF3C7';
      badge.style.color = '#92400E';
    } else {
      badge.textContent = '⚠️ Needs Improvement';
      badge.style.background = '#FEE2E2';
      badge.style.color = '#991B1B';
    }
  }
}

function toggleSkipRating(isSkipped) {
  const scorecardSection = document.getElementById('scorecard-inputs-grid');
  const summaryCard = document.getElementById('scorecard-summary-card');
  const reasonBox = document.getElementById('skip_reason_container');
  const reasonInput = document.getElementById('skip_reason_input');
  const submitBtn = document.getElementById('close-rate-submit-btn');

  if (isSkipped) {
    if (scorecardSection) scorecardSection.style.display = 'none';
    if (summaryCard) summaryCard.style.display = 'none';
    if (reasonBox) reasonBox.style.display = 'block';
    if (reasonInput) reasonInput.required = true;
    if (submitBtn) submitBtn.innerHTML = '<?= icon("check", 13) ?> Confirm Order Closure (Rating Skipped)';
    document.querySelectorAll('.score-input').forEach(el => el.required = false);
  } else {
    if (scorecardSection) scorecardSection.style.display = 'grid';
    if (summaryCard) summaryCard.style.display = 'flex';
    if (reasonBox) reasonBox.style.display = 'none';
    if (reasonInput) {
      reasonInput.required = false;
      reasonInput.value = '';
    }
    if (submitBtn) submitBtn.innerHTML = '<?= icon("check", 13) ?> Confirm Rating &amp; Close PO';
    document.querySelectorAll('.score-input').forEach(el => el.required = true);
    calculateWeightedScore();
  }
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