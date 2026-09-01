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

    if ($action === 'create') {
        require_permission('procurement.invoice.create');

        $po_id     = (int)($_POST['po_id'] ?? 0);
        $inv_num   = trim($_POST['invoice_number'] ?? '');
        $inv_date  = $_POST['invoice_date'] ?: null;
        $due_date  = $_POST['due_date'] ?: null;
        $tax       = (float)($_POST['tax_amount'] ?? 0);
        $lines     = $_POST['lines'] ?? []; // [requisition_item_id => ['qty'=>.., 'unit_price'=>..]]

        $po = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $po->execute([':id' => $po_id]); $po = $po->fetch();

        if (!$po) {
            $toast = '⚠️ Purchase Order not found.'; $toast_type = 'error';
        } elseif (!$inv_num) {
            $toast = '⚠️ Invoice number is required.'; $toast_type = 'error';
        } elseif (empty($lines)) {
            $toast = '⚠️ Add at least one line item.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $subtotal = 0.0;
                $item_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE id = :id');
                $line_data = [];

                foreach ($lines as $req_item_id => $data) {
                    $qty   = (float)($data['qty'] ?? 0);
                    $price = (float)($data['unit_price'] ?? 0);
                    if ($qty <= 0) continue;

                    $item_stmt->execute([':id' => (int)$req_item_id]);
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
                    ':po' => $po_id, ':sup' => $po['supplier_id'], ':num' => $inv_num, ':idate' => $inv_date, ':ddate' => $due_date,
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
                audit_log('invoice', $invoice_id, 'created', "PO #$po_id — {$inv_num} — " . php_currency($total));

                notify_role_by_permission(
                    'procurement.invoice.match', 'invoice_created',
                    "Invoice {$inv_num} logged for PO #$po_id",
                    'Ready for 3-way match against PO and Goods Receipt.',
                    'three_way_match.php?invoice_id=' . $invoice_id, $user['id']
                );

                $toast = '✅ Invoice logged. Ready for 3-way matching.';
                header('Location: invoices.php?id=' . $invoice_id . '&toast=' . urlencode($toast) . '&type=success');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'cancel') {
        require_permission('procurement.invoice.create');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=:id AND status IN ('pending','disputed')")->execute([':id' => $id]);
        audit_log('invoice', $id, 'cancelled');
        $toast = '🚫 Invoice cancelled.';
        header('Location: invoices.php?id=' . $id . '&toast=' . urlencode($toast) . '&type=success');
        exit;
    }

    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: invoices.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Detail view ──────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$invoice = null; $invoice_items = [];
if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT i.*, s.name AS supplier_name, po.total_amount AS po_total, pr.title AS req_title, pr.department
        FROM invoices i
        JOIN suppliers s ON s.id = i.supplier_id
        JOIN purchase_orders po ON po.id = i.po_id
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE i.id = :id
    ');
    $stmt->execute([':id' => $view_id]);
    $invoice = $stmt->fetch();

    if ($invoice) {
        $li = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
        $li->execute([':id' => $view_id]);
        $invoice_items = $li->fetchAll();
    }
}

// ── "New invoice" form context: POs eligible for invoicing ──────────
$new_po_id = (int)($_GET['new_for_po'] ?? 0);
$new_po = null; $new_po_items = [];
if ($new_po_id) {
    $stmt = $pdo->prepare('
        SELECT po.*, s.name AS supplier_name, pr.title AS req_title, pr.id AS requisition_id
        FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE po.id = :id
    ');
    $stmt->execute([':id' => $new_po_id]);
    $new_po = $stmt->fetch();
    if ($new_po) {
        $ri = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :rid');
        $ri->execute([':rid' => $new_po['requisition_id']]);
        $new_po_items = $ri->fetchAll();
    }
}

$eligible_stmt = $pdo->query("
    SELECT po.id, po.total_amount, s.name AS supplier_name, pr.title AS req_title
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE po.status IN ('delivered','closed')
      AND po.id NOT IN (SELECT po_id FROM invoices WHERE status != 'cancelled')
    ORDER BY po.delivered_at DESC
");
$eligible_pos = $eligible_stmt->fetchAll();

$filter = $_GET['status'] ?? 'all';
$where  = '1=1'; $params = [];
if (in_array($filter, ['pending','matched','disputed','approved','paid','cancelled'], true)) {
    $where .= ' AND i.status = :st'; $params[':st'] = $filter;
}
$list_stmt = $pdo->prepare("
    SELECT i.*, s.name AS supplier_name, po.id AS po_id
    FROM invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN purchase_orders po ON po.id = i.po_id
    WHERE $where ORDER BY i.created_at DESC
");
$list_stmt->execute($params);
$invoices = $list_stmt->fetchAll();

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Invoices — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .inv-line-row { display:grid; grid-template-columns: 1.6fr .8fr .8fr .9fr; gap:10px; align-items:center; padding:9px 0; border-bottom:1px dashed var(--border); font-size:13px; }
    .inv-line-row:last-child { border-bottom:none; }
    .inv-total-strip { display:flex; justify-content:flex-end; gap:24px; padding-top:12px; margin-top:6px; border-top:1.5px solid var(--border); font-size:13px; }
  </style>
</head>
<body>

<div id="page-invoices" class="page active">
  <div class="page-header">
    <div>
      <h1>Invoices</h1>
      <p>Log supplier invoices and prepare them for 3-way matching</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($new_po): ?>
      <!-- ── New invoice form ── -->
      <div class="table-card" style="padding:20px 22px">
        <h2>New Invoice — <?= htmlspecialchars($new_po['req_title']) ?></h2>
        <p class="muted-cell" style="margin-bottom:16px">PO #<?= str_pad($new_po['id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($new_po['supplier_name']) ?> · PO Total: <?= php_currency($new_po['total_amount']) ?></p>

        <form method="POST">
          <input type="hidden" name="action" value="create"/>
          <input type="hidden" name="po_id" value="<?= $new_po['id'] ?>"/>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px">
            <input class="field-input" type="text" name="invoice_number" placeholder="Invoice Number *" required/>
            <input class="field-input" type="date" name="invoice_date" placeholder="Invoice Date"/>
            <input class="field-input" type="date" name="due_date" placeholder="Due Date"/>
          </div>

          <div class="inv-line-row" style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;border-bottom:1.5px solid var(--border)">
            <div>Item</div><div>Qty</div><div>Unit Price</div><div>Line Total</div>
          </div>
          <?php foreach ($new_po_items as $ri): ?>
            <div class="inv-line-row">
              <div><?= htmlspecialchars($ri['item_name']) ?> <span class="muted-cell">(<?= htmlspecialchars($ri['unit']) ?>)</span></div>
              <div><input class="field-input" type="number" step="0.01" min="0" style="padding:6px 8px" name="lines[<?= $ri['id'] ?>][qty]" value="<?= $ri['quantity'] ?>"/></div>
              <div><input class="field-input" type="number" step="0.01" min="0" style="padding:6px 8px" name="lines[<?= $ri['id'] ?>][unit_price]" value="<?= $ri['est_unit_price'] ?>"/></div>
              <div class="muted-cell">computed on save</div>
            </div>
          <?php endforeach; ?>

          <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;margin-top:14px">
            <label style="font-size:12.5px">Tax / other charges (₱)
              <input class="field-input" type="number" step="0.01" min="0" name="tax_amount" value="0" style="margin-top:4px"/>
            </label>
          </div>

          <div style="margin-top:16px;text-align:right;display:flex;gap:10px;justify-content:flex-end">
            <a href="invoices.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">🧾 Log Invoice</button>
          </div>
        </form>
      </div>

    <?php elseif ($invoice): ?>
      <!-- ── Invoice detail ── -->
      <div class="table-card" style="padding:20px 22px;margin-bottom:18px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <h2><?= htmlspecialchars($invoice['invoice_number']) ?></h2>
            <p class="muted-cell">PO #<?= str_pad($invoice['po_id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($invoice['supplier_name']) ?> · <?= htmlspecialchars($invoice['req_title']) ?></p>
          </div>
          <span class="status-badge status-<?= in_array($invoice['status'],['approved','matched','paid'])?'approved':($invoice['status']==='disputed'?'rejected':'pending') ?>"><?= status_badge($invoice['status']) ?></span>
        </div>

        <div class="inv-line-row" style="font-size:11px;color:var(--text-muted);font-weight:700;text-transform:uppercase;border-bottom:1.5px solid var(--border);margin-top:16px">
          <div>Item</div><div>Qty</div><div>Unit Price</div><div>Line Total</div>
        </div>
        <?php foreach ($invoice_items as $li): ?>
          <div class="inv-line-row">
            <div><?= htmlspecialchars($li['item_name']) ?></div>
            <div><?= number_format($li['qty'],2) ?></div>
            <div><?= php_currency($li['unit_price']) ?></div>
            <div style="font-weight:700"><?= php_currency($li['line_total']) ?></div>
          </div>
        <?php endforeach; ?>

        <div class="inv-total-strip">
          <div>Subtotal: <strong><?= php_currency($invoice['subtotal']) ?></strong></div>
          <div>Tax: <strong><?= php_currency($invoice['tax_amount']) ?></strong></div>
          <div>Total: <strong style="color:var(--espresso)"><?= php_currency($invoice['total_amount']) ?></strong></div>
        </div>

        <?php if ($invoice['match_notes']): ?>
          <p style="margin-top:12px;font-size:12.5px;color:var(--text-muted);font-style:italic;padding:10px;background:#FBF6EF;border-radius:10px">Match notes: <?= htmlspecialchars($invoice['match_notes']) ?></p>
        <?php endif; ?>

        <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
          <?php if (in_array($invoice['status'], ['pending','disputed'], true) && has_permission('procurement.invoice.create')): ?>
            <form method="POST" onsubmit="return confirm('Cancel this invoice?')">
              <input type="hidden" name="action" value="cancel"/><input type="hidden" name="id" value="<?= $invoice['id'] ?>"/>
              <button type="submit" class="btn-cancel">🚫 Cancel Invoice</button>
            </form>
          <?php endif; ?>
          <?php if (in_array($invoice['status'], ['pending','disputed'], true) && has_permission('procurement.invoice.match')): ?>
            <a href="three_way_match.php?invoice_id=<?= $invoice['id'] ?>" class="btn-save">🔗 Run 3-Way Match</a>
          <?php endif; ?>
          <?php if ($invoice['status'] === 'approved' && has_permission('procurement.payment.process')): ?>
            <a href="payments.php?new_for_invoice=<?= $invoice['id'] ?>" class="btn-save">💸 Schedule Payment</a>
          <?php endif; ?>
        </div>
      </div>
      <p><a href="invoices.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Invoices</a></p>

    <?php else: ?>
      <!-- ── List view ── -->
      <?php if (has_permission('procurement.invoice.create')): ?>
      <div class="table-card" style="padding:16px 18px;margin-bottom:18px">
        <h3 style="font-size:13.5px;margin-bottom:10px">🧾 Log a New Invoice</h3>
        <?php if (empty($eligible_pos)): ?>
          <p class="muted-cell">No delivered Purchase Orders awaiting invoicing right now.</p>
        <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach ($eligible_pos as $ep): ?>
              <button class="act-btn" onclick="window.location.href='invoices.php?new_for_po=<?= $ep['id'] ?>'">
                #<?= str_pad($ep['id'],5,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($ep['supplier_name']) ?> (<?= php_currency($ep['total_amount']) ?>)
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="filter-bar" style="padding:0">
        <a href="invoices.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="invoices.php?status=pending" class="filter-pill <?= $filter==='pending'?'active':'' ?>">Pending</a>
        <a href="invoices.php?status=matched" class="filter-pill <?= $filter==='matched'?'active':'' ?>">Matched</a>
        <a href="invoices.php?status=disputed" class="filter-pill <?= $filter==='disputed'?'active':'' ?>">Disputed</a>
        <a href="invoices.php?status=paid" class="filter-pill <?= $filter==='paid'?'active':'' ?>">Paid</a>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>Invoice #</th><th>PO #</th><th>Supplier</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($invoices)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No invoices logged yet.</td></tr>
          <?php else: foreach ($invoices as $i): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($i['invoice_number']) ?></td>
              <td>#<?= str_pad($i['po_id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= htmlspecialchars($i['supplier_name']) ?></td>
              <td style="font-weight:700"><?= php_currency($i['total_amount']) ?></td>
              <td><span class="status-badge status-<?= in_array($i['status'],['approved','matched','paid'])?'approved':($i['status']==='disputed'?'rejected':'pending') ?>"><?= status_badge($i['status']) ?></span></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($i['created_at'])) ?></td>
              <td><button class="act-btn" onclick="window.location.href='invoices.php?id=<?= $i['id'] ?>'">👁 View</button></td>
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