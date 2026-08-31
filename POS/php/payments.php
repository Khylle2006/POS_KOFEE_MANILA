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

    if ($action === 'schedule') {
        require_permission('procurement.payment.process');

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $method     = in_array($_POST['payment_method'] ?? '', ['bank_transfer','check','cash','online'], true) ? $_POST['payment_method'] : 'bank_transfer';
        $reference  = trim($_POST['reference_no'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        $inv = $pdo->prepare('SELECT * FROM invoices WHERE id = :id'); $inv->execute([':id' => $invoice_id]); $invoice = $inv->fetch();

        if (!$invoice || $invoice['status'] !== 'approved') {
            $toast = '⚠️ Only approved invoices can be scheduled for payment.'; $toast_type = 'error';
        } elseif ($amount <= 0) {
            $toast = '⚠️ Enter a valid payment amount.'; $toast_type = 'error';
        } else {
            $pdo->prepare(
                'INSERT INTO payments (invoice_id, po_id, amount, payment_method, reference_no, notes, paid_by)
                 VALUES (:inv, :po, :amt, :m, :ref, :n, :u)'
            )->execute([
                ':inv' => $invoice_id, ':po' => $invoice['po_id'], ':amt' => $amount,
                ':m' => $method, ':ref' => $reference ?: null, ':n' => $notes ?: null, ':u' => $user['id'],
            ]);
            $payment_id = (int)$pdo->lastInsertId();
            audit_log('payment', $payment_id, 'scheduled', "Invoice {$invoice['invoice_number']} — " . php_currency($amount));
            $toast = '🗓️ Payment scheduled.';
            header('Location: payments.php?id=' . $payment_id . '&toast=' . urlencode($toast) . '&type=success');
            exit;
        }
    }

    if ($action === 'complete') {
        require_permission('procurement.payment.process');
        $id = (int)($_POST['id'] ?? 0);

        $p = $pdo->prepare('SELECT * FROM payments WHERE id = :id'); $p->execute([':id' => $id]); $payment = $p->fetch();
        if ($payment && $payment['status'] === 'scheduled') {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE payments SET status='completed', completed_at=NOW() WHERE id=:id")->execute([':id' => $id]);
                $pdo->prepare("UPDATE invoices SET status='paid' WHERE id=:id")->execute([':id' => $payment['invoice_id']]);
                $pdo->prepare("UPDATE purchase_orders SET paid_at=NOW() WHERE id=:id")
                    ->execute([':id' => $payment['po_id']]);
                $pdo->commit();
                audit_log('payment', $id, 'completed', php_currency($payment['amount']));

                $sup_stmt = $pdo->prepare('
                    SELECT s.user_id, s.name, i.invoice_number
                    FROM invoices i JOIN suppliers s ON s.id = i.supplier_id
                    WHERE i.id = :id
                ');
                $sup_stmt->execute([':id' => $payment['invoice_id']]);
                $sup = $sup_stmt->fetch();
                if ($sup && $sup['user_id']) {
                    notify_user(
                        (int)$sup['user_id'], 'payment_advice', '💸 Payment sent',
                        'Payment of ' . php_currency($payment['amount']) . ' for Invoice ' . $sup['invoice_number'] . ' has been completed.',
                        'supplier_portal.php'
                    );
                }

                $toast = '💸 Payment completed — invoice marked paid.';
            } catch (Exception $e) {{
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    if ($action === 'fail') {
        require_permission('procurement.payment.process');
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['fail_reason'] ?? '');
        $pdo->prepare("UPDATE payments SET status='failed', notes = CONCAT(COALESCE(notes,''), ' | Failed: ', :r) WHERE id=:id AND status='scheduled'")
            ->execute([':r' => $reason ?: 'No reason given', ':id' => $id]);
        audit_log('payment', $id, 'failed', $reason);
        $toast = '❌ Payment marked failed. You can schedule a new attempt from the invoice.'; $toast_type = 'error';
    }

    if ($action === 'cancel') {
        require_permission('procurement.payment.process');
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE payments SET status='cancelled' WHERE id=:id AND status='scheduled'")->execute([':id' => $id]);
        audit_log('payment', $id, 'cancelled');
        $toast = '🚫 Scheduled payment cancelled.';
    }

    $id_for_redirect = (int)($_POST['id'] ?? $_POST['invoice_id'] ?? 0);
    $q = ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: payments.php' . ($_POST['action'] !== 'schedule' && $id_for_redirect ? '?id=' . $id_for_redirect : '') . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── "New payment" context ──────────────────────────────
// ── "New payment" context ──────────────────────────────
$new_invoice_id = (int)($_GET['new_for_invoice'] ?? 0);
$new_invoice = null;

// A po_id pre-select (linked from purchase_orders.php's "Go to Payments"
// button) resolves to that PO's approved-and-unpaid invoice, same pattern
// as goods_receipts.php's ?po_id= handling.
$po_id_param = (int)($_GET['po_id'] ?? 0);
$po_id_toast = null;
if ($po_id_param && !$new_invoice_id) {
    $po_inv_stmt = $pdo->prepare("
        SELECT id FROM invoices WHERE po_id = :po AND status = 'approved' ORDER BY id DESC LIMIT 1
    ");
    $po_inv_stmt->execute([':po' => $po_id_param]);
    $found_invoice_id = $po_inv_stmt->fetchColumn();
    if ($found_invoice_id) {
        $new_invoice_id = (int)$found_invoice_id;
    } else {
        $po_pay_stmt = $pdo->prepare('SELECT id FROM payments WHERE po_id = :po ORDER BY id DESC LIMIT 1');
        $po_pay_stmt->execute([':po' => $po_id_param]);
        $existing_payment_id = $po_pay_stmt->fetchColumn();
        if ($existing_payment_id) {
            header('Location: payments.php?id=' . $existing_payment_id);
            exit;
        }
        $po_id_toast = '⚠️ No approved invoice awaiting payment for this Purchase Order.';
    }
}

if ($new_invoice_id) {
    $stmt = $pdo->prepare('
        SELECT i.*, s.name AS supplier_name, po.id AS po_id
        FROM invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN purchase_orders po ON po.id = i.po_id
        WHERE i.id = :id
    ');
    $stmt->execute([':id' => $new_invoice_id]);
    $new_invoice = $stmt->fetch();
}
if ($po_id_toast && !$toast) {
    $toast = $po_id_toast; $toast_type = 'error';
}

// ── Detail view ──────────────────────────────
$view_id = (int)($_GET['id'] ?? 0);
$payment = null;
if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT p.*, i.invoice_number, s.name AS supplier_name
        FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN suppliers s ON s.id = i.supplier_id
        WHERE p.id = :id
    ');
    $stmt->execute([':id' => $view_id]);
    $payment = $stmt->fetch();
}

// ── Approved invoices awaiting payment (quick-pick) ──────────────────
$awaiting_stmt = $pdo->query("
    SELECT i.id, i.invoice_number, i.total_amount, s.name AS supplier_name
    FROM invoices i JOIN suppliers s ON s.id = i.supplier_id
    WHERE i.status = 'approved'
    ORDER BY i.due_date IS NULL, i.due_date ASC
");
$awaiting = $awaiting_stmt->fetchAll();

// ── List view ──────────────────────────────
$filter = $_GET['status'] ?? 'all';
$where  = '1=1'; $params = [];
if (in_array($filter, ['scheduled','completed','failed','cancelled'], true)) {
    $where .= ' AND p.status = :st'; $params[':st'] = $filter;
}
$list_stmt = $pdo->prepare("
    SELECT p.*, i.invoice_number, s.name AS supplier_name
    FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN suppliers s ON s.id = i.supplier_id
    WHERE $where ORDER BY p.scheduled_at DESC
");
$list_stmt->execute($params);
$payments = $list_stmt->fetchAll();

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Payments — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
</head>
<body>

<div id="page-payments" class="page active">
  <div class="page-header">
    <div>
      <h1>Payments</h1>
      <p>Schedule and track payments to suppliers for approved invoices</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($new_invoice): ?>
      <!-- ── Schedule payment form ── -->
      <div class="table-card" style="padding:20px 22px;max-width:520px">
        <h2>Schedule Payment</h2>
        <p class="muted-cell" style="margin-bottom:16px">Invoice <?= htmlspecialchars($new_invoice['invoice_number']) ?> · <?= htmlspecialchars($new_invoice['supplier_name']) ?> · PO #<?= str_pad($new_invoice['po_id'],5,'0',STR_PAD_LEFT) ?></p>

        <form method="POST">
          <input type="hidden" name="action" value="schedule"/>
          <input type="hidden" name="invoice_id" value="<?= $new_invoice['id'] ?>"/>

          <label style="font-size:12.5px;display:block;margin-bottom:12px">Amount (₱)
            <input class="field-input" type="number" step="0.01" min="0" name="amount" value="<?= $new_invoice['total_amount'] ?>" style="margin-top:4px"/>
          </label>
          <label style="font-size:12.5px;display:block;margin-bottom:12px">Payment Method
            <select class="field-input" name="payment_method" style="margin-top:4px">
              <option value="bank_transfer">Bank Transfer</option>
              <option value="check">Check</option>
              <option value="cash">Cash</option>
              <option value="online">Online</option>
            </select>
          </label>
          <label style="font-size:12.5px;display:block;margin-bottom:12px">Reference No.
            <input class="field-input" type="text" name="reference_no" placeholder="e.g. bank transaction ID" style="margin-top:4px"/>
          </label>
          <label style="font-size:12.5px;display:block;margin-bottom:16px">Notes
            <textarea class="field-input" name="notes" style="margin-top:4px;width:100%;min-height:60px"></textarea>
          </label>

          <div style="text-align:right;display:flex;gap:10px;justify-content:flex-end">
            <a href="payments.php" class="btn-cancel">Cancel</a>
            <button type="submit" class="btn-save">🗓️ Schedule Payment</button>
          </div>
        </form>
      </div>

    <?php elseif ($payment): ?>
      <!-- ── Payment detail ── -->
      <div class="table-card" style="padding:20px 22px;max-width:520px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <h2><?= php_currency($payment['amount']) ?></h2>
            <p class="muted-cell">Invoice <?= htmlspecialchars($payment['invoice_number']) ?> · <?= htmlspecialchars($payment['supplier_name']) ?></p>
          </div>
          <span class="status-badge status-<?= $payment['status']==='completed'?'approved':($payment['status']==='failed'?'rejected':'pending') ?>"><?= status_badge($payment['status']) ?></span>
        </div>

        <p style="font-size:13px;margin-top:12px"><strong>Method:</strong> <?= ucwords(str_replace('_',' ',$payment['payment_method'])) ?></p>
        <?php if ($payment['reference_no']): ?><p style="font-size:13px"><strong>Reference:</strong> <?= htmlspecialchars($payment['reference_no']) ?></p><?php endif; ?>
        <?php if ($payment['notes']): ?><p style="font-size:12.5px;color:var(--text-muted);margin-top:6px"><?= htmlspecialchars($payment['notes']) ?></p><?php endif; ?>
        <p class="muted-cell" style="margin-top:10px">Scheduled <?= date('M d, Y g:i A', strtotime($payment['scheduled_at'])) ?><?= $payment['completed_at'] ? ' · Completed ' . date('M d, Y g:i A', strtotime($payment['completed_at'])) : '' ?></p>

        <?php if ($payment['status'] === 'scheduled' && has_permission('procurement.payment.process')): ?>
        <div style="margin-top:18px;display:flex;gap:10px">
          <form method="POST"><input type="hidden" name="action" value="complete"/><input type="hidden" name="id" value="<?= $payment['id'] ?>"/>
            <button type="submit" class="btn-save">💸 Mark Completed</button></form>
          <form method="POST" onsubmit="return confirm('Cancel this scheduled payment?')"><input type="hidden" name="action" value="cancel"/><input type="hidden" name="id" value="<?= $payment['id'] ?>"/>
            <button type="submit" class="btn-cancel">Cancel</button></form>
        </div>
        <form method="POST" style="margin-top:10px;display:flex;gap:8px">
          <input type="hidden" name="action" value="fail"/><input type="hidden" name="id" value="<?= $payment['id'] ?>"/>
          <input class="field-input" type="text" name="fail_reason" placeholder="Reason payment failed (optional)" style="flex:1;padding:7px 10px"/>
          <button type="submit" class="act-btn">❌ Mark Failed</button>
        </form>
        <?php endif; ?>
      </div>
      <p style="margin-top:14px"><a href="payments.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back to Payments</a></p>

    <?php else: ?>
      <!-- ── List view ── -->
      <?php if (has_permission('procurement.payment.process')): ?>
      <div class="table-card" style="padding:16px 18px;margin-bottom:18px">
        <h3 style="font-size:13.5px;margin-bottom:10px">💸 Approved Invoices Awaiting Payment</h3>
        <?php if (empty($awaiting)): ?>
          <p class="muted-cell">Nothing awaiting payment right now.</p>
        <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach ($awaiting as $a): ?>
              <button class="act-btn" onclick="window.location.href='payments.php?new_for_invoice=<?= $a['id'] ?>'">
                <?= htmlspecialchars($a['invoice_number']) ?> — <?= htmlspecialchars($a['supplier_name']) ?> (<?= php_currency($a['total_amount']) ?>)
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="filter-bar" style="padding:0">
        <a href="payments.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
        <a href="payments.php?status=scheduled" class="filter-pill <?= $filter==='scheduled'?'active':'' ?>">Scheduled</a>
        <a href="payments.php?status=completed" class="filter-pill <?= $filter==='completed'?'active':'' ?>">Completed</a>
        <a href="payments.php?status=failed" class="filter-pill <?= $filter==='failed'?'active':'' ?>">Failed</a>
      </div>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>Invoice</th><th>Supplier</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($payments)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No payments recorded yet.</td></tr>
          <?php else: foreach ($payments as $p): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($p['invoice_number']) ?></td>
              <td><?= htmlspecialchars($p['supplier_name']) ?></td>
              <td style="font-weight:700"><?= php_currency($p['amount']) ?></td>
              <td><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></td>
              <td><span class="status-badge status-<?= $p['status']==='completed'?'approved':($p['status']==='failed'?'rejected':'pending') ?>"><?= status_badge($p['status']) ?></span></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($p['scheduled_at'])) ?></td>
              <td><button class="act-btn" onclick="window.location.href='payments.php?id=<?= $p['id'] ?>'">👁 View</button></td>
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