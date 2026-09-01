<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'send') {
        require_permission('procurement.po.manage');
        $pdo->prepare("UPDATE purchase_orders SET status='sent' WHERE id=:id AND status='draft'")->execute([':id'=>$id]);
        $toast = '📨 Purchase Order sent to supplier.';
    }


    if ($action === 'record_invoice') {
        require_permission('procurement.invoice.match');
        $inv = trim($_POST['invoice_number'] ?? '');
        if (!$inv) {
            $toast = '⚠️ Invoice number is required.'; $toast_type = 'error';
        } else {
            $pdo->prepare('UPDATE purchase_orders SET invoice_number=:i WHERE id=:id')->execute([':i'=>$inv, ':id'=>$id]);
            $toast = '✅ Invoice matched to PO, Goods Receipt confirmed.';
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
if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT po.*, s.name AS supplier_name, s.contact_person, s.email, s.phone,
               pr.title AS req_title, pr.department
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE po.id = :id
    ');
    $stmt->execute([':id'=>$view_id]);
    $po = $stmt->fetch();
}

$filter = $_GET['status'] ?? 'all';
$where  = '1=1';
$params = [];
if (in_array($filter, ['draft','sent','acknowledged','delivered','closed','cancelled'], true)) {
    $where .= ' AND po.status = :st'; $params[':st'] = $filter;
}
$list_stmt = $pdo->prepare("
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE $where
    ORDER BY FIELD(po.status,'draft','sent','acknowledged','delivered','closed','cancelled'), po.created_at DESC
");
$list_stmt->execute($params);
$pos = $list_stmt->fetchAll();

include("../includes/sidebar.php");
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
    .po-step-label { font-size:13px; font-weight:600; }
    .po-step-action { margin-left:auto; }
  </style>
</head>
<body>

<div id="page-po" class="page active">
  <div class="page-header">
    <div>
      <h1>Purchase Orders</h1>
      <p>Track orders from award through delivery, invoicing, payment, and close</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($po): ?>
      <!-- ── PO detail ── -->
      <div class="table-card" style="padding:20px 22px;display:grid;grid-template-columns:1fr 1fr;gap:24px">
        <div>
          <h2><?= htmlspecialchars($po['req_title']) ?></h2>
          <p class="muted-cell" style="margin-bottom:14px">PO #<?= str_pad($po['id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($po['department']) ?></p>

          <p style="font-size:13px;margin-bottom:4px"><strong>Supplier:</strong> <?= htmlspecialchars($po['supplier_name']) ?></p>
          <p style="font-size:13px;margin-bottom:4px"><strong>Contact:</strong> <?= htmlspecialchars($po['contact_person'] ?: '—') ?> · <?= htmlspecialchars($po['email'] ?: '—') ?> · <?= htmlspecialchars($po['phone'] ?: '—') ?></p>
          <p style="font-size:20px;font-weight:800;color:var(--espresso);margin:10px 0">₱<?= number_format($po['total_amount'],2) ?></p>

          <?php if ($po['negotiation_notes']): ?>
            <p style="font-size:12.5px;color:var(--text-muted);font-style:italic;padding:10px;background:#FBF6EF;border-radius:10px">"<?= htmlspecialchars($po['negotiation_notes']) ?>"</p>
          <?php endif; ?>

          <?php if ($po['status']==='closed'): ?>
            <p style="margin-top:12px">
              <span class="status-badge status-approved">Closed</span>
              — rated <?= str_repeat('⭐', (int)$po['supplier_rating']) ?>
              <a href="supplier_performace.php?supplier_id=<?= $po['supplier_id'] ?>" style="margin-left:6px;font-size:12px;font-weight:700;color:var(--caramel)">View scorecard →</a>
            </p>
          <?php endif; ?>
        </div>

        <div>
          <h3 style="font-size:13.5px;margin-bottom:8px">Order Progress</h3>

          <div class="po-step">
            <div class="po-step-dot <?= $po['status']!=='draft'?'done':'' ?>"><?= $po['status']!=='draft'?'✓':'' ?></div>
            <div class="po-step-label">PO Sent to Supplier</div>
            <?php if ($po['status']==='draft' && has_permission('procurement.po.manage')): ?>
              <form method="POST" class="po-step-action"><input type="hidden" name="action" value="send"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/>
                <button type="submit" class="act-btn act-activate">📨 Send PO</button></form>
            <?php endif; ?>
          </div>

          <div class="po-step">
            <div class="po-step-dot <?= $po['shipped_at']?'done':'' ?>"><?= $po['shipped_at']?'✓':'' ?></div>
            <div class="po-step-label">Shipped by Supplier<?= $po['shipped_at'] ? ' (' . date('M d, Y', strtotime($po['shipped_at'])) . ')' : '' ?><?= $po['shipping_notes'] ? ' — ' . htmlspecialchars($po['shipping_notes']) : '' ?></div>
          </div>

          <div class="po-step">
            <div class="po-step-dot <?= in_array($po['status'],['delivered','closed'])?'done':'' ?>"><?= in_array($po['status'],['delivered','closed'])?'✓':'' ?></div>
            <div class="po-step-label">Goods Received</div>
            <?php if (in_array($po['status'],['sent','acknowledged'],true) && has_permission('procurement.receiving')): ?>
              <button type="button" class="act-btn act-activate" onclick="window.location.href='goods_receipts.php?po_id=<?= $po['id'] ?>'">📦 Record Receipt</button>
            <?php endif; ?>          </div>

          <div class="po-step">
            <div class="po-step-dot <?= $po['invoice_number']?'done':'' ?>"><?= $po['invoice_number']?'✓':'' ?></div>
            <div class="po-step-label">Invoice Matched <?= $po['invoice_number'] ? '(#' . htmlspecialchars($po['invoice_number']) . ')' : '' ?></div>
            <?php if ($po['status']==='delivered' && !$po['invoice_number'] && has_permission('procurement.invoice.match')): ?>
              <form method="POST" class="po-step-action" style="display:flex;gap:6px">
                <input type="hidden" name="action" value="record_invoice"/><input type="hidden" name="id" value="<?= $po['id'] ?>"/>
                <input class="field-input" type="text" name="invoice_number" placeholder="Invoice #" style="width:120px;padding:6px 10px"/>
                <button type="submit" class="act-btn act-activate">✔ Match</button></form>
            <?php endif; ?>
          </div>

          <div class="po-step">
            <div class="po-step-dot <?= $po['paid_at']?'done':'' ?>"><?= $po['paid_at']?'✓':'' ?></div>
            <div class="po-step-label">Payment Sent <?= $po['paid_at'] ? '(' . date('M d, Y', strtotime($po['paid_at'])) . ')' : '' ?></div>
            <?php if ($po['invoice_number'] && !$po['paid_at'] && has_permission('procurement.payment.process')): ?>
              <button type="button" class="act-btn act-activate" onclick="window.location.href='payments.php?po_id=<?= $po['id'] ?>'">💸 Go to Payments</button>
            <?php endif; ?>
          </div>

          <?php if ($po['paid_at'] && $po['status'] !== 'closed' && has_permission('procurement.performance.rate')): ?>
            <div style="margin-top:16px;padding-top:14px;border-top:1.5px solid var(--border)">
              <button class="btn-save" style="width:100%" onclick="window.location.href='supplier_performace.php'">🏁 Close Order &amp; Rate Supplier</button>
            </div>
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>
      <!-- ── PO list ── -->
      <div class="filter-bar" style="padding:0">
        <a href="purchase_orders.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="purchase_orders.php?status=sent" class="filter-pill <?= $filter==='sent'?'active':'' ?>">Sent</a>
        <a href="purchase_orders.php?status=delivered" class="filter-pill <?= $filter==='delivered'?'active':'' ?>">Delivered</a>
        <a href="purchase_orders.php?status=closed" class="filter-pill <?= $filter==='closed'?'active':'' ?>">Closed</a>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>PO #</th><th>Requisition</th><th>Supplier</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($pos)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No purchase orders yet — award a bid from an RFQ first.</td></tr>
          <?php else: foreach ($pos as $p): ?>
            <tr>
              <td style="font-weight:700">#<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($p['req_title']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700">₱<?= number_format($p['total_amount'],2) ?></td>
              <td><span class="status-badge status-<?= $p['status']==='closed'?'approved':($p['status']==='cancelled'?'rejected':'pending') ?>"><?= ucfirst($p['status']) ?></span></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
              <td><button class="act-btn" onclick="window.location.href='purchase_orders.php?id=<?= $p['id'] ?>'">👁 View</button></td>
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