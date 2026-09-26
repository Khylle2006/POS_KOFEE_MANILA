<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.invoice.match');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Tolerances from configurable procurement_settings table
$price_tol = (float)get_procurement_setting('three_way_match_price_tolerance_pct', 3.0);
$qty_tol   = (float)get_procurement_setting('three_way_match_qty_tolerance_units', 0.0);

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    $inv_stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id'); $inv_stmt->execute([':id' => $invoice_id]); $invoice = $inv_stmt->fetch();
    $po = null;
    if ($invoice) {
        $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $po_stmt->execute([':id' => $invoice['po_id']]); $po = $po_stmt->fetch();
    }

    if (!$invoice || !$po) {
        $toast = 'Invoice or Purchase Order not found.'; $toast_type = 'error';
    } elseif ($action === 'confirm_match') {
        $result = run_three_way_match($pdo, $invoice, $po, $price_tol, $qty_tol);
        $summary = $result['passed']
            ? sprintf('Matched clean: invoice %s vs PO %s (%.1f%% variance).', php_currency($result['inv_total']), php_currency($result['po_total']), $result['variance_pct'])
            : implode(' | ', $result['exceptions']);

        $new_status = $result['passed'] ? 'matched' : 'disputed';
        $pdo->prepare('UPDATE invoices SET status = :st, match_notes = :n WHERE id = :id')
            ->execute([':st' => $new_status, ':n' => $summary, ':id' => $invoice_id]);

        audit_log('invoice', $invoice_id, '3way_match_run', "$new_status — $summary");

        if (!$result['passed']) {
            notify_role_by_permission(
                'procurement.invoice.match', 'invoice_exception',
                "3-way match exception on Invoice {$invoice['invoice_number']}",
                $summary, 'three_way_match.php?invoice_id=' . $invoice_id, $user['id']
            );
            $toast = 'Match exceptions found — invoice marked disputed.'; $toast_type = 'error';
        } else {
            $toast = '3-way match passed — invoice ready for approval.';
        }
    } elseif ($action === 'request_correction') {
        require_permission('procurement.invoice.match');
        $correction_notes = trim($_POST['correction_notes'] ?? '');
        if (!$correction_notes) {
            $toast = 'Please provide details on what needs correction.'; $toast_type = 'error';
        } else {
            $pdo->prepare("UPDATE invoices SET status='needs_correction', correction_notes = :cn WHERE id = :id")
                ->execute([':cn' => $correction_notes, ':id' => $invoice_id]);
            audit_log('invoice', $invoice_id, 'correction_requested', $correction_notes);

            // Notify supplier
            $sup_stmt = $pdo->prepare('SELECT user_id, name FROM suppliers WHERE id = :sid');
            $sup_stmt->execute([':sid' => $invoice['supplier_id']]);
            $sup = $sup_stmt->fetch();
            if (!empty($sup['user_id'])) {
                notify_user(
                    (int)$sup['user_id'],
                    'invoice_correction_needed',
                    "Invoice {$invoice['invoice_number']} Needs Correction",
                    "Finance requested a correction: \"{$correction_notes}\". Please update and resubmit.",
                    "supplier_portal.php?tab=invoices&invoice_id={$invoice_id}"
                );
            }

            $toast = 'Correction request sent to supplier. Invoice marked as needs correction.';
        }
    } elseif ($action === 'force_approve') {
        require_permission('procurement.invoice.match');
        $override_note = trim($_POST['override_note'] ?? '');
        if (!$override_note) {
            $toast = 'An override justification is required to force-approve a disputed invoice.'; $toast_type = 'error';
        } else {
            $pdo->prepare("UPDATE invoices SET status='approved', match_notes = CONCAT(COALESCE(match_notes,''), ' | Override: ', :n) WHERE id = :id")
                ->execute([':n' => $override_note, ':id' => $invoice_id]);
            audit_log('invoice', $invoice_id, 'force_approved', $override_note);
            $toast = 'Invoice force-approved with override note.';
        }
    } elseif ($action === 'approve') {
        if ($invoice['status'] !== 'matched') {
            $toast = 'Only a cleanly matched invoice can be approved this way.'; $toast_type = 'error';
        } else {
            $pdo->prepare("UPDATE invoices SET status='approved' WHERE id = :id")->execute([':id' => $invoice_id]);
            audit_log('invoice', $invoice_id, 'approved');
            $toast = 'Invoice approved for payment.';
        }
    }

    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: three_way_match.php?invoice_id=' . $invoice_id . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── View ──────────────────────────────
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT i.*, s.name AS supplier_name, po.total_amount AS po_total, po.id AS po_id, pr.title AS req_title
    FROM invoices i
    JOIN suppliers s ON s.id = i.supplier_id
    JOIN purchase_orders po ON po.id = i.po_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE i.id = :id
');
$stmt->execute([':id' => $invoice_id]);
$invoice = $stmt->fetch();

$result = null; $po = null;
if ($invoice) {
    $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $po_stmt->execute([':id' => $invoice['po_id']]); $po = $po_stmt->fetch();
    $result  = run_three_way_match($pdo, $invoice, $po, $price_tol, $qty_tol);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>3-Way Match — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .match-col { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px; margin:16px 0; }
    .match-box { border:1.5px solid var(--border); border-radius:var(--radius); padding:14px 16px; }
    .match-box h4 { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:8px; }
    .match-box .amt { font-size:20px; font-weight:800; color:var(--espresso); }
    .exception-list { list-style:none; padding:0; margin:0; }
    .exception-list li { padding:8px 10px; background:#fdf3ea; border-left:3px solid #d9822b; border-radius:6px; font-size:12.5px; margin-bottom:6px; }
    .line-check-row { display:grid; grid-template-columns:1.6fr 1fr 1fr .8fr; gap:10px; padding:8px 0; border-bottom:1px dashed var(--border); font-size:13px; align-items:center; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-3way" class="page active">
  <div class="page-header">
    <div>
      <h1>3-Way Match</h1>
      <p>Automatically reconcile Purchase Order, Goods Receipt, and Invoice</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if (!$invoice): ?>
      <div class="table-card" style="padding:20px 22px">
        <p class="muted-cell">Open this from an invoice's detail page to run its match. <a href="invoices.php" style="color:var(--caramel);font-weight:600">Go to Invoices →</a></p>
      </div>
    <?php else: ?>

      <div class="table-card" style="padding:20px 22px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
          <div>
            <div style="display:flex;align-items:center;gap:8px">
              <h2 style="margin:0">Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></h2>
              <?php if (!empty($invoice['version']) && $invoice['version'] > 1): ?>
                <span class="status-badge" style="background:#EDF2F7;color:#4A5568;font-weight:700">v<?= (int)$invoice['version'] ?></span>
              <?php endif; ?>
            </div>
            <p class="muted-cell" style="margin-top:4px">PO #<?= str_pad($invoice['po_id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($invoice['supplier_name']) ?> · <?= htmlspecialchars($invoice['req_title']) ?></p>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
            <span class="status-badge status-<?= in_array($invoice['status'],['approved','matched','paid'])?'approved':($invoice['status']==='disputed'?'rejected':'pending') ?>"><?= status_badge($invoice['status']) ?></span>
            <?php if (!empty($invoice['attachment_path'])): ?>
              <a href="../<?= htmlspecialchars($invoice['attachment_path']) ?>" target="_blank" class="act-btn" style="font-size:11.5px">
                <?= icon('paperclip', 12) ?> View Invoice File
              </a>
            <?php endif; ?>
          </div>
        </div>

        <div style="background:#FAF5EE;border:1px solid #E5D5C5;border-radius:8px;padding:8px 14px;margin-top:12px;font-size:12px;display:flex;gap:16px;flex-wrap:wrap;color:var(--espresso)">
          <span>Price Variance Tolerance: <strong>±<?= number_format($price_tol, 1) ?>%</strong></span>
          <span>Quantity Variance Tolerance: <strong><?= number_format($qty_tol, 2) ?> units</strong></span>
        </div>

        <?php if ($invoice['status'] === 'needs_correction' && !empty($invoice['correction_notes'])): ?>
          <div style="margin-top:12px;background:#FFF5F5;border:1px solid #FEB2B2;border-left:4px solid var(--red);padding:10px 14px;border-radius:8px;font-size:12.5px">
            <strong style="color:#C53030;display:flex;align-items:center;gap:4px"><?= icon('alert-triangle', 13) ?> Correction Requested from Supplier:</strong>
            <p style="margin:4px 0 0;color:var(--text);font-size:12px;background:#FFF;padding:8px 12px;border-radius:6px;border:1px solid #FED7D7"><?= nl2br(htmlspecialchars($invoice['correction_notes'])) ?></p>
          </div>
        <?php endif; ?>

        <div class="match-col">
          <div class="match-box"><h4><?= icon('clipboard', 14, '', 'vertical-align:middle;margin-right:4px') ?> Purchase Order</h4><div class="amt"><?= php_currency($result['po_total']) ?></div><p class="muted-cell">Awarded total</p></div>
          <div class="match-box"><h4><?= icon('package', 14, '', 'vertical-align:middle;margin-right:4px') ?> Goods Receipt</h4><div class="amt"><?= count($result['line_results']) ?></div><p class="muted-cell">line item(s) checked against received qty</p></div>
          <div class="match-box"><h4><?= icon('invoice', 14, '', 'vertical-align:middle;margin-right:4px') ?> Invoice</h4><div class="amt"><?= php_currency($result['inv_total']) ?></div><p class="muted-cell"><?= $result['variance_pct'] ?>% variance vs PO</p></div>
        </div>

        <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin:18px 0 6px">Line-Level Check (Invoiced vs Received)</h4>
        <div class="line-check-row" style="font-weight:700;color:var(--text-muted);font-size:11px;text-transform:uppercase;border-bottom:1.5px solid var(--border)">
          <div>Item</div><div>Invoiced Qty</div><div>Received Qty</div><div>Result</div>
        </div>
        <?php foreach ($result['line_results'] as $lr): ?>
          <div class="line-check-row">
            <div><?= htmlspecialchars($lr['item_name']) ?></div>
            <div><?= number_format($lr['invoiced_qty'],2) ?></div>
            <div><?= number_format($lr['received_qty'],2) ?></div>
            <div><?= $lr['over_billed'] ? '<span style="color:var(--red,#c53030);display:inline-flex;align-items:center;gap:4px">' . icon('alert-triangle', 13) . ' Over-billed</span>' : '<span style="color:var(--green,#2f855a);display:inline-flex;align-items:center;gap:4px">' . icon('check', 13) . ' OK</span>' ?></div>
          </div>
        <?php endforeach; ?>

        <?php if (!$result['passed']): ?>
          <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin:18px 0 6px">Exceptions</h4>
          <ul class="exception-list">
            <?php foreach ($result['exceptions'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p style="margin-top:16px;padding:10px 12px;background:#eef8ef;border-left:3px solid var(--green,#3a9d4f);border-radius:6px;font-size:12.5px;display:flex;align-items:center;gap:6px"><?= icon('check-circle', 16, '', 'color:var(--green,#3a9d4f);flex-shrink:0') ?> No exceptions — PO, Goods Receipt, and Invoice all reconcile within tolerance.</p>
        <?php endif; ?>

        <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
          <?php if (in_array($invoice['status'], ['pending','disputed','submitted','under_review'], true)): ?>
            <form method="POST"><input type="hidden" name="action" value="confirm_match"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
              <button type="submit" class="btn-save"><?= icon('check', 14) ?> Run Match</button></form>
          <?php endif; ?>

          <?php if ($invoice['status'] === 'matched'): ?>
            <form method="POST"><input type="hidden" name="action" value="approve"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
              <button type="submit" class="btn-save"><?= icon('check', 14) ?> Approve for Payment</button></form>
          <?php endif; ?>

          <?php if (in_array($invoice['status'], ['disputed','submitted','pending','under_review'], true)): ?>
            <button type="button" class="act-btn act-suspend" onclick="document.getElementById('correction-form').classList.toggle('open-inline');document.getElementById('override-form')?.classList.remove('open-inline');">
              <?= icon('message', 13) ?> Request Correction from Supplier
            </button>
          <?php endif; ?>

          <?php if ($invoice['status'] === 'disputed'): ?>
            <button type="button" class="act-btn" onclick="document.getElementById('override-form').classList.toggle('open-inline');document.getElementById('correction-form')?.classList.remove('open-inline');">
              <?= icon('alert-triangle', 13) ?> Override &amp; Force-Approve
            </button>
          <?php endif; ?>
        </div>

        <form method="POST" id="correction-form" style="margin-top:12px;display:none;background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:12px" class="inline-toggle-form">
          <input type="hidden" name="action" value="request_correction"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
          <strong style="font-size:12px;color:#9B2C2C;display:block;margin-bottom:6px">Request Invoice Correction from Supplier</strong>
          <textarea class="field-input" name="correction_notes" placeholder="Explain the discrepancy (e.g. price exceeds contract rate, missing tax breakdown, invoiced quantity exceeds received goods)..." required style="width:100%;min-height:70px;margin-bottom:8px"></textarea>
          <div style="text-align:right">
            <button type="submit" class="btn-save" style="background:var(--red);border-color:var(--red)">Send Correction Request to Supplier</button>
          </div>
        </form>

        <?php if ($invoice['status'] === 'disputed'): ?>
        <form method="POST" id="override-form" style="margin-top:12px;display:none" class="inline-toggle-form">
          <input type="hidden" name="action" value="force_approve"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
          <textarea class="field-input" name="override_note" placeholder="Justify why this exception is being overridden (required, goes in the audit log)" style="width:100%;min-height:60px;margin-bottom:8px" required></textarea>
          <div style="text-align:right"><button type="submit" class="btn-save">Confirm Override</button></div>
        </form>
        <?php endif; ?>
        <style>.inline-toggle-form.open-inline{display:block!important}</style>
      </div>

      <p style="margin-top:14px"><a href="invoices.php?id=<?= $invoice['id'] ?>" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Invoice</a></p>
    <?php endif; ?>

  </div>
</div>

</body>
</html>