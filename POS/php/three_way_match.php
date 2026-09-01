<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_login();
require_permission('procurement.invoice.match');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Tolerances — small variances (rounding, minor price drift) shouldn't
// block payment; anything beyond this is flagged as an exception.
const PRICE_TOLERANCE_PCT = 3.0;   // invoice total vs PO total
const QTY_TOLERANCE_UNITS = 0.0;   // invoiced qty may not exceed received qty at all

/**
 * Run the 3-way match for one invoice. Pure computation — does not
 * write to the DB. Returns a structured result the UI (and the POST
 * handler below) both use.
 */
function run_three_way_match(PDO $pdo, array $invoice, array $po): array {
    $exceptions = [];

    // ── Line-level: invoiced qty vs received (good-condition) qty ──
    $li_stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
    $li_stmt->execute([':id' => $invoice['id']]);
    $lines = $li_stmt->fetchAll();

    $recv_stmt = $pdo->prepare(
        "SELECT gri.requisition_item_id, SUM(gri.received_qty) AS qty
         FROM goods_receipt_items gri JOIN goods_receipts g ON g.id = gri.grn_id
         WHERE g.po_id = :po AND gri.item_condition = 'good'
         GROUP BY gri.requisition_item_id"
    );
    $recv_stmt->execute([':po' => $po['id']]);
    $received_map = $recv_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $has_any_grn = $pdo->prepare("SELECT COUNT(*) FROM goods_receipts WHERE po_id = :po AND status != 'discrepancy'");
    $has_any_grn->execute([':po' => $po['id']]);
    $grn_exists = (int)$has_any_grn->fetchColumn() > 0;

    $line_results = [];
    foreach ($lines as $l) {
        $received = (float)($received_map[$l['requisition_item_id']] ?? 0);
        $over     = $l['qty'] - $received > QTY_TOLERANCE_UNITS;
        $line_results[] = [
            'item_name'   => $l['item_name'],
            'invoiced_qty'=> (float)$l['qty'],
            'received_qty'=> $received,
            'over_billed' => $over,
        ];
        if ($over) {
            $exceptions[] = "\"{$l['item_name']}\" invoiced for {$l['qty']} but only {$received} received.";
        }
    }

    if (!$grn_exists) {
        $exceptions[] = 'No completed Goods Receipt found for this Purchase Order.';
    }

    // ── Header-level: invoice total vs PO (awarded) total ──
    $po_total  = (float)$po['total_amount'];
    $inv_total = (float)$invoice['total_amount'];
    $variance  = $po_total > 0 ? abs($inv_total - $po_total) / $po_total * 100 : ($inv_total > 0 ? 100 : 0);
    $price_ok  = $variance <= PRICE_TOLERANCE_PCT;

    if (!$price_ok) {
        $exceptions[] = sprintf(
            'Invoice total %s differs from PO total %s by %.1f%% (tolerance is %.1f%%).',
            php_currency($inv_total), php_currency($po_total), $variance, PRICE_TOLERANCE_PCT
        );
    }

    return [
        'passed'       => empty($exceptions),
        'exceptions'   => $exceptions,
        'line_results' => $line_results,
        'po_total'     => $po_total,
        'inv_total'    => $inv_total,
        'variance_pct' => round($variance, 2),
    ];
}

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
        $toast = '⚠️ Invoice or Purchase Order not found.'; $toast_type = 'error';
    } elseif ($action === 'confirm_match') {
        $result = run_three_way_match($pdo, $invoice, $po);
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
            $toast = '⚠️ Match exceptions found — invoice marked disputed.'; $toast_type = 'error';
        } else {
            $toast = '✅ 3-way match passed — invoice ready for approval.';
        }
    } elseif ($action === 'force_approve') {
        require_permission('procurement.invoice.match');
        $override_note = trim($_POST['override_note'] ?? '');
        if (!$override_note) {
            $toast = '⚠️ An override justification is required to force-approve a disputed invoice.'; $toast_type = 'error';
        } else {
            $pdo->prepare("UPDATE invoices SET status='approved', match_notes = CONCAT(COALESCE(match_notes,''), ' | Override: ', :n) WHERE id = :id")
                ->execute([':n' => $override_note, ':id' => $invoice_id]);
            audit_log('invoice', $invoice_id, 'force_approved', $override_note);
            $toast = '✅ Invoice force-approved with override note.';
        }
    } elseif ($action === 'approve') {
        if ($invoice['status'] !== 'matched') {
            $toast = '⚠️ Only a cleanly matched invoice can be approved this way.'; $toast_type = 'error';
        } else {
            $pdo->prepare("UPDATE invoices SET status='approved' WHERE id = :id")->execute([':id' => $invoice_id]);
            audit_log('invoice', $invoice_id, 'approved');
            $toast = '✅ Invoice approved for payment.';
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
    $result  = run_three_way_match($pdo, $invoice, $po);
}

include("../includes/sidebar.php");
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
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <h2>Invoice <?= htmlspecialchars($invoice['invoice_number']) ?></h2>
            <p class="muted-cell">PO #<?= str_pad($invoice['po_id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($invoice['supplier_name']) ?> · <?= htmlspecialchars($invoice['req_title']) ?></p>
          </div>
          <span class="status-badge status-<?= in_array($invoice['status'],['approved','matched','paid'])?'approved':($invoice['status']==='disputed'?'rejected':'pending') ?>"><?= status_badge($invoice['status']) ?></span>
        </div>

        <div class="match-col">
          <div class="match-box"><h4>📋 Purchase Order</h4><div class="amt"><?= php_currency($result['po_total']) ?></div><p class="muted-cell">Awarded total</p></div>
          <div class="match-box"><h4>📦 Goods Receipt</h4><div class="amt"><?= count($result['line_results']) ?></div><p class="muted-cell">line item(s) checked against received qty</p></div>
          <div class="match-box"><h4>🧾 Invoice</h4><div class="amt"><?= php_currency($result['inv_total']) ?></div><p class="muted-cell"><?= $result['variance_pct'] ?>% variance vs PO</p></div>
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
            <div><?= $lr['over_billed'] ? '⚠️ Over-billed' : '✅ OK' ?></div>
          </div>
        <?php endforeach; ?>

        <?php if (!$result['passed']): ?>
          <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin:18px 0 6px">Exceptions</h4>
          <ul class="exception-list">
            <?php foreach ($result['exceptions'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p style="margin-top:16px;padding:10px 12px;background:#eef8ef;border-left:3px solid var(--green,#3a9d4f);border-radius:6px;font-size:12.5px">✅ No exceptions — PO, Goods Receipt, and Invoice all reconcile within tolerance.</p>
        <?php endif; ?>

        <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
          <?php if (in_array($invoice['status'], ['pending','disputed'], true)): ?>
            <form method="POST"><input type="hidden" name="action" value="confirm_match"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
              <button type="submit" class="btn-save">🔗 Run Match</button></form>
          <?php endif; ?>

          <?php if ($invoice['status'] === 'matched'): ?>
            <form method="POST"><input type="hidden" name="action" value="approve"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
              <button type="submit" class="btn-save">✅ Approve for Payment</button></form>
          <?php endif; ?>

          <?php if ($invoice['status'] === 'disputed'): ?>
            <button type="button" class="act-btn" onclick="document.getElementById('override-form').classList.toggle('open-inline')">⚠️ Override & Force-Approve</button>
          <?php endif; ?>
        </div>

        <?php if ($invoice['status'] === 'disputed'): ?>
        <form method="POST" id="override-form" style="margin-top:12px;display:none;gap:8px" class="override-form">
          <input type="hidden" name="action" value="force_approve"/><input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>"/>
          <textarea class="field-input" name="override_note" placeholder="Justify why this exception is being overridden (required, goes in the audit log)" style="width:100%;min-height:60px;margin-bottom:8px"></textarea>
          <div style="text-align:right"><button type="submit" class="btn-save">Confirm Override</button></div>
        </form>
        <style>.override-form.open-inline{display:block!important}</style>
        <?php endif; ?>
      </div>

      <p style="margin-top:14px"><a href="invoices.php?id=<?= $invoice['id'] ?>" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Invoice</a></p>
    <?php endif; ?>

  </div>
</div>

</body>
</html>