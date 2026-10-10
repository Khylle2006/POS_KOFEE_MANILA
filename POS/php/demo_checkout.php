<?php
// ==============================================================================
// FILE: php/demo_checkout.php
// Local PayMongo Interactive Checkout Simulator for Kofee Manila POS
// Provides a real customer checkout screen for demo & local sandbox sessions.
// ==============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_demo_payment();
require_permission('orders.new');
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/ingredient_deduction.php';

$pdo = get_db();
paymongo_ensure_order_columns($pdo);

$session_id = trim($_GET['session'] ?? $_POST['session'] ?? '');
$order_id   = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

// Look up target order
$order = null;
if ($session_id !== '') {
    $st = $pdo->prepare('SELECT * FROM orders WHERE paymongo_session_id = :sid ORDER BY id DESC LIMIT 1');
    $st->execute([':sid' => $session_id]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$order && $order_id > 0) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $st->execute([':id' => $order_id]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
}

if ($order && ((int)$order['user_id'] !== (int)$_SESSION['user_id'] || !str_starts_with((string)$order['paymongo_session_id'], 'cs_demo_'))) {
    throw new SecurityFault('PERMISSION_DENIED', 'Permission denied.', 403);
}

// ── Handle AJAX Payment Authorization ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'authorize_payment') {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found for this checkout session.']);
        exit;
    }

    $method = trim($_POST['payment_method'] ?? 'gcash');
    $phone  = trim($_POST['phone_number'] ?? '09170000000');

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT status, payment_status FROM orders WHERE id = ? FOR UPDATE');
        $lock->execute([$order['id']]);
        $locked = $lock->fetch();
        if ($locked['status'] === 'cancelled') throw new SecurityFault('ORDER_CANCELLED', 'Order is cancelled.', 409);

        $payment_id = 'pay_demo_' . bin2hex(random_bytes(6));
        $ref_number = 'PM-' . date('Ymd') . '-' . strtoupper(substr(hash('sha256', ($session_id ?: (string)$order['id']) . microtime()), 0, 8));

        $upd = $pdo->prepare("
            UPDATE orders
            SET payment_status = 'paid',
                status = 'pending',
                payment_method = 'paymongo',
                amount_tendered = total_amount,
                change_amount = 0.00,
                paymongo_payment_id = :pid,
                payment_reference = :ref
            WHERE id = :id AND payment_status = 'pending'
        ");
        $upd->execute([
            ':pid' => $payment_id,
            ':ref' => $ref_number,
            ':id'  => $order['id'],
        ]);

        security_audit($pdo, 'demo_payment', 'order', (int)$order['id']);

        $pdo->commit();

        echo json_encode([
            'success'        => true,
            'order_id'       => (int)$order['id'],
            'reference_no'   => $ref_number,
            'payment_id'     => $payment_id,
            'amount'         => (float)$order['total_amount'],
            'method'         => strtoupper($method),
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Service temporarily unavailable.']);
        exit;
    }
}

// ── Fetch order items for display ────────────────────────────
$items = [];
$computed_subtotal = 0.0;
if ($order) {
    $it_st = $pdo->prepare("
        SELECT oi.*, p.name AS product_name, p.image_path
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = :oid
    ");
    $it_st->execute([':oid' => $order['id']]);
    $items = $it_st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $it) {
        $computed_subtotal += (float)$it['subtotal'];
    }
}

$total_amount = $order ? (float)$order['total_amount'] : 0.0;
$vat_amount = round($total_amount - ($total_amount / 1.12), 2);
$net_amount = round($total_amount - $vat_amount, 2);
$is_already_paid = $order && ($order['payment_status'] === 'paid' || $order['status'] === 'completed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>PayMongo Checkout — Kofee Manila</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --pm-green: #00c07f;
      --pm-green-dark: #009e67;
      --pm-green-light: #e6f9f2;
      --pm-bg: #f7faf9;
      --pm-card: #ffffff;
      --pm-text: #1a202c;
      --pm-muted: #718096;
      --pm-border: #e2e8f0;
      --gcash-blue: #0057e7;
      --maya-green: #00d664;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
      background: var(--pm-bg);
      color: var(--pm-text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px 48px;
    }

    .checkout-wrap {
      width: 100%;
      max-width: 520px;
      margin: 0 auto;
    }

    /* Top Brand Bar */
    .brand-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 18px;
      padding: 0 4px;
    }
    .brand-logo {
      display: flex;
      align-items: center;
      gap: 8px;
      font-family: 'Space Grotesk', sans-serif;
      font-weight: 700;
      font-size: 20px;
      color: #0f172a;
      letter-spacing: -0.5px;
    }
    .brand-icon {
      width: 32px;
      height: 32px;
      background: var(--pm-green);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 800;
      font-size: 18px;
      box-shadow: 0 4px 12px rgba(0, 192, 127, 0.35);
    }
    .sandbox-pill {
      background: #fef3c7;
      border: 1px solid #fde047;
      color: #854d0e;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .sandbox-pill::before {
      content: "";
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #eab308;
    }

    /* Main Checkout Card */
    .card {
      background: var(--pm-card);
      border-radius: 18px;
      border: 1px solid var(--pm-border);
      box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05), 0 20px 25px -5px rgba(0, 0, 0, 0.02);
      overflow: hidden;
      margin-bottom: 20px;
      transition: all 0.3s ease;
    }

    /* Merchant & Total Header */
    .card-header {
      padding: 24px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfdfc 100%);
      border-bottom: 1px solid var(--pm-border);
      text-align: center;
      position: relative;
    }
    .merchant-avatar {
      width: 48px;
      height: 48px;
      margin: 0 auto 10px;
      background: #3c2415;
      color: #fff;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      box-shadow: 0 4px 10px rgba(60, 36, 21, 0.2);
    }
    .merchant-title {
      font-size: 14px;
      color: var(--pm-muted);
      font-weight: 600;
    }
    .merchant-name {
      font-size: 16px;
      font-weight: 700;
      color: var(--pm-text);
      margin-bottom: 8px;
    }
    .amount-display {
      font-size: 36px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -1px;
      margin-top: 4px;
    }
    .amount-currency {
      font-size: 20px;
      font-weight: 600;
      color: var(--pm-muted);
      vertical-align: middle;
      margin-right: 4px;
    }

    /* Order Summary Accordion */
    .order-summary-bar {
      padding: 12px 20px;
      background: #f8fafc;
      border-bottom: 1px solid var(--pm-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      user-select: none;
    }
    .order-summary-bar:hover {
      background: #f1f5f9;
    }
    .order-summary-items {
      padding: 16px 20px;
      background: #fafcfb;
      border-bottom: 1px solid var(--pm-border);
      display: none;
      font-size: 13px;
    }
    .order-summary-items.open {
      display: block;
    }
    .summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
      border-bottom: 1px dashed #e2e8f0;
    }
    .summary-row:last-child {
      border-bottom: none;
    }
    .summary-item-name {
      font-weight: 600;
      color: var(--pm-text);
    }
    .summary-item-qty {
      color: var(--pm-muted);
      font-size: 12px;
      margin-left: 6px;
    }

    /* Payment Methods Tabs */
    .pm-body {
      padding: 24px;
    }
    .method-tabs {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin-bottom: 20px;
    }
    .tab-btn {
      border: 1.5px solid var(--pm-border);
      border-radius: 12px;
      padding: 10px 8px;
      background: #fff;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      font-size: 12px;
      font-weight: 700;
      color: var(--pm-muted);
    }
    .tab-btn:hover {
      border-color: #cbd5e1;
      background: #f8fafc;
    }
    .tab-btn.active {
      border-color: var(--pm-green);
      background: var(--pm-green-light);
      color: #065f46;
      box-shadow: 0 0 0 1px var(--pm-green);
    }
    .tab-btn svg, .tab-btn img {
      width: 24px;
      height: 24px;
    }

    /* Payment Details Panels */
    .method-panel {
      display: none;
      animation: fadeIn 0.25s ease;
    }
    .method-panel.active {
      display: block;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .form-group {
      margin-bottom: 16px;
    }
    .form-label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      margin-bottom: 6px;
      color: #334155;
    }
    .form-input {
      width: 100%;
      padding: 12px 14px;
      font-size: 14.5px;
      border: 1.5px solid var(--pm-border);
      border-radius: 10px;
      font-family: inherit;
      color: var(--pm-text);
      outline: none;
      transition: border 0.2s ease;
    }
    .form-input:focus {
      border-color: var(--pm-green);
      box-shadow: 0 0 0 3px rgba(0, 192, 127, 0.15);
    }

    .info-callout {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 12.5px;
      color: #475569;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 18px;
    }

    /* Pay Button */
    .btn-pay {
      width: 100%;
      background: var(--pm-green);
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      padding: 15px;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 14px rgba(0, 192, 127, 0.35);
    }
    .btn-pay:hover:not(:disabled) {
      background: var(--pm-green-dark);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(0, 192, 127, 0.4);
    }
    .btn-pay:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .spinner {
      width: 20px;
      height: 20px;
      border: 2.5px solid rgba(255, 255, 255, 0.3);
      border-top-color: #ffffff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* Success Screen */
    .success-view {
      display: none;
      padding: 36px 24px;
      text-align: center;
      animation: scaleIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes scaleIn {
      from { opacity: 0; transform: scale(0.92); }
      to { opacity: 1; transform: scale(1); }
    }
    .check-circle {
      width: 72px;
      height: 72px;
      background: #dcfce7;
      color: #16a34a;
      border-radius: 50%;
      margin: 0 auto 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 36px;
      box-shadow: 0 10px 25px rgba(22, 163, 74, 0.25);
    }
    .success-title {
      font-size: 22px;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 6px;
    }
    .success-desc {
      font-size: 13.5px;
      color: var(--pm-muted);
      margin-bottom: 20px;
      line-height: 1.5;
    }
    .receipt-box {
      background: #f8fafc;
      border: 1px solid var(--pm-border);
      border-radius: 12px;
      padding: 16px;
      text-align: left;
      font-size: 13px;
      margin-bottom: 24px;
    }
    .receipt-row {
      display: flex;
      justify-content: space-between;
      padding: 5px 0;
    }
    .btn-return {
      display: inline-block;
      width: 100%;
      background: #0f172a;
      color: #fff;
      text-decoration: none;
      font-size: 14.5px;
      font-weight: 700;
      padding: 13px;
      border-radius: 10px;
      text-align: center;
      transition: background 0.2s ease;
    }
    .btn-return:hover {
      background: #1e293b;
    }

    /* Security Footer */
    .security-footer {
      text-align: center;
      font-size: 12px;
      color: #94a3b8;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
    }
    .sec-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      color: #64748b;
    }
  </style>
</head>
<body>

<div class="checkout-wrap">
  <!-- Brand Header -->
  <div class="brand-bar">
    <div class="brand-logo">
      <div class="brand-icon">P</div>
      <span>PayMongo</span>
    </div>
    <div class="sandbox-pill">
      <span>Demo Sandbox</span>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card" id="main-card">
    <div class="card-header">
      <div class="merchant-avatar">☕</div>
      <div class="merchant-title">Merchant Checkout</div>
      <div class="merchant-name">Kofee Manila POS</div>

      <div class="amount-display">
        <span class="amount-currency">PHP</span><?= number_format($total_amount, 2) ?>
      </div>
      <div style="font-size: 12px; color: var(--pm-muted); margin-top: 4px;">
        Order #<?= $order ? htmlspecialchars((string)$order['id']) : 'Pending' ?>
        <?php if ($order && !empty($order['placed_at'])): ?>
          · <?= date('M d, Y · h:i A', strtotime($order['placed_at'])) ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Accordion Order Summary -->
    <?php if (!empty($items)): ?>
      <div class="order-summary-bar" onclick="toggleSummary()">
        <span>Order Details (<?= count($items) ?> items)</span>
        <span id="summary-caret">▼</span>
      </div>
      <div class="order-summary-items" id="summary-content">
        <?php foreach ($items as $it): ?>
          <div class="summary-row">
            <div>
              <span class="summary-item-name"><?= htmlspecialchars($it['product_name'] ?: 'Item') ?></span>
              <span style="font-size:11px;color:#64748b;text-transform:capitalize;">(<?= htmlspecialchars($it['size'] ?: 'regular') ?>)</span>
              <span class="summary-item-qty">× <?= (int)$it['quantity'] ?></span>
            </div>
            <div style="font-weight:700">₱<?= number_format((float)$it['subtotal'], 2) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="summary-row" style="margin-top:8px;padding-top:8px;border-top:1px solid #cbd5e1;color:#64748b">
          <div>VAT Included (12%)</div>
          <div>₱<?= number_format($vat_amount, 2) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Checkout Body -->
    <?php if ($is_already_paid): ?>
      <!-- Already Paid View -->
      <div class="success-view" style="display:block;">
        <div class="check-circle">✓</div>
        <div class="success-title">Order Already Paid</div>
        <div class="success-desc">This transaction has already been settled and verified on the POS terminal.</div>
        <div class="receipt-box">
          <div class="receipt-row"><span style="color:#64748b">Order ID:</span><strong>#<?= (int)$order['id'] ?></strong></div>
          <div class="receipt-row"><span style="color:#64748b">Amount:</span><strong>PHP <?= number_format((float)$order['total_amount'], 2) ?></strong></div>
          <div class="receipt-row"><span style="color:#64748b">Reference:</span><strong style="font-family:monospace;"><?= htmlspecialchars($order['payment_reference'] ?: 'PM-COMPLETED') ?></strong></div>
          <div class="receipt-row"><span style="color:#64748b">Status:</span><span style="color:#16a34a;font-weight:700">Paid & Verified</span></div>
        </div>
        <a href="menu.php?paymongo_success=1&order_id=<?= (int)$order['id'] ?>" class="btn-return">Return to POS Register</a>
      </div>
    <?php else: ?>
      <!-- Payment Interactive Form -->
      <div class="pm-body" id="payment-form-body">
        <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:10px;text-transform:uppercase;letter-spacing:0.5px">Select Payment Channel</div>

        <!-- Channel Tabs -->
        <div class="method-tabs">
          <button type="button" class="tab-btn active" data-tab="gcash" onclick="selectTab('gcash')">
            <svg viewBox="0 0 24 24" fill="#0057e7"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
            <span>GCash</span>
          </button>
          <button type="button" class="tab-btn" data-tab="maya" onclick="selectTab('maya')">
            <svg viewBox="0 0 24 24" fill="#00d664"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm0 4v10h16V8H4zm4 3h8v2H8v-2z"/></svg>
            <span>Maya</span>
          </button>
          <button type="button" class="tab-btn" data-tab="qrph" onclick="selectTab('qrph')">
            <svg viewBox="0 0 24 24" fill="#0f172a"><path d="M3 3h8v8H3V3zm2 2v4h4V5H5zm8-2h8v8h-8V3zm2 2v4h4V5h-4zM3 13h8v8H3v-8zm2 2v4h4v-4H5zm13-2h3v2h-3v-2zm-5 5h3v3h-3v-3zm3 0h3v3h-3v-3zm2-3h3v2h-3v-2zm-5-2h2v2h-2v-2z"/></svg>
            <span>QR Ph</span>
          </button>
        </div>

        <!-- 1. GCash Tab -->
        <div id="panel-gcash" class="method-panel active">
          <div class="info-callout">
            <span style="font-size:18px">📱</span>
            <div>Pay instantly with your GCash wallet balance. Fast and zero transaction fees.</div>
          </div>
          <div class="form-group">
            <label class="form-label">GCash Registered Mobile Number</label>
            <input type="text" class="form-input" id="gcash-mobile" value="0917 •••• 582" placeholder="0917XXXXXXX"/>
          </div>
        </div>

        <!-- 2. Maya Tab -->
        <div id="panel-maya" class="method-panel">
          <div class="info-callout">
            <span style="font-size:18px">💚</span>
            <div>Authenticate with your Maya account (formerly PayMaya) to approve payment.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Maya Registered Mobile Number</label>
            <input type="text" class="form-input" id="maya-mobile" value="0918 •••• 910" placeholder="0918XXXXXXX"/>
          </div>
        </div>

        <!-- 3. QR Ph Tab -->
        <div id="panel-qrph" class="method-panel">
          <div style="text-align:center;padding:12px 0 16px;">
            <div style="width:180px;height:180px;margin:0 auto 12px;background:#fff;border:2px dashed #cbd5e1;border-radius:12px;padding:8px;display:flex;align-items:center;justify-content:center;">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=164x164&margin=2&data=<?= urlencode('https://paymongo.com/qrph_demo_' . $session_id) ?>" alt="QR Ph" style="width:100%;height:100%;"/>
            </div>
            <div style="font-size:12.5px;color:#64748b;">Scan with any banking app (BDO, BPI, UnionBank) or e-wallet (GCash, Maya).</div>
          </div>
        </div>

        <!-- Pay Action Button -->
        <button type="button" class="btn-pay" id="btn-submit-pay" onclick="submitAuthorizedPayment()">
          <span id="btn-text">Authorize Payment • PHP <?= number_format($total_amount, 2) ?></span>
        </button>

        <div style="text-align:center;margin-top:14px;">
          <a href="menu.php?paymongo_cancel=1&order_id=<?= $order ? (int)$order['id'] : '' ?>" style="color:#94a3b8;font-size:12.5px;text-decoration:none;">Cancel & Return to POS</a>
        </div>
      </div>

      <!-- Success Result Screen -->
      <div class="success-view" id="success-screen">
        <div class="check-circle">✓</div>
        <div class="success-title">Payment Successful!</div>
        <div class="success-desc">Funds authorized and settled. Your order has been dispatched to the barista display.</div>

        <div class="receipt-box">
          <div class="receipt-row"><span style="color:#64748b">Order ID:</span><strong id="res-order-id">#<?= $order ? (int)$order['id'] : '1' ?></strong></div>
          <div class="receipt-row"><span style="color:#64748b">Amount Settled:</span><strong id="res-amount">PHP <?= number_format($total_amount, 2) ?></strong></div>
          <div class="receipt-row"><span style="color:#64748b">Method:</span><strong id="res-method">GCASH</strong></div>
          <div class="receipt-row"><span style="color:#64748b">Reference Number:</span><strong style="font-family:monospace;color:#0f172a;" id="res-ref">PM-20261006-XXXXXX</strong></div>
          <div class="receipt-row"><span style="color:#64748b">Terminal Status:</span><span style="color:#16a34a;font-weight:700;">Synced with POS Terminal</span></div>
        </div>

        <div style="font-size:12px;color:#94a3b8;margin-bottom:14px;">
          Auto-closing in <span id="countdown">4</span> seconds...
        </div>

        <a href="menu.php?paymongo_success=1&order_id=<?= $order ? (int)$order['id'] : '' ?>" class="btn-return" id="btn-return-link">Return to POS Register</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Security Footer -->
  <div class="security-footer">
    <div class="sec-badge">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
      <span>256-Bit SSL Encrypted &amp; PCI-DSS Level 1 Compliant</span>
    </div>
    <div>PayMongo Payments Philippines, Inc. • Regulated by the Bangko Sentral ng Pilipinas</div>
  </div>
</div>

<script>
let currentChannel = 'gcash';

function selectTab(tab) {
  currentChannel = tab;
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
  });
  document.querySelectorAll('.method-panel').forEach(p => p.classList.remove('active'));
  const target = document.getElementById('panel-' + tab);
  if (target) target.classList.add('active');
}

function toggleSummary() {
  const content = document.getElementById('summary-content');
  const caret = document.getElementById('summary-caret');
  if (content) {
    content.classList.toggle('open');
    if (caret) caret.textContent = content.classList.contains('open') ? '▲' : '▼';
  }
}

async function submitAuthorizedPayment() {
  const btn = document.getElementById('btn-submit-pay');
  const btnText = document.getElementById('btn-text');
  if (btn) btn.disabled = true;
  if (btnText) btnText.innerHTML = '<span class="spinner"></span> Connecting to PayMongo...';

  const orderId = <?= $order ? (int)$order['id'] : 0 ?>;
  const sessionId = '<?= htmlspecialchars($session_id, ENT_QUOTES) ?>';
  const phone = currentChannel === 'gcash' ? document.getElementById('gcash-mobile').value : (currentChannel === 'maya' ? document.getElementById('maya-mobile').value : 'QRPH');

  // Step 1: Simulated verification delay
  await new Promise(r => setTimeout(r, 600));
  if (btnText) btnText.innerHTML = '<span class="spinner"></span> Authorizing Payment...';

  try {
    const formData = new FormData();
    formData.append('action', 'authorize_payment');
    formData.append('order_id', orderId);
    formData.append('session', sessionId);
    formData.append('payment_method', currentChannel);
    formData.append('phone_number', phone);

    const resp = await fetch('demo_checkout.php', {
      method: 'POST',
      body: formData
    });
    const data = await resp.json();

    if (!data.success) {
      alert('Payment authorization failed: ' + (data.error || 'Unknown error'));
      if (btn) btn.disabled = false;
      if (btnText) btnText.textContent = 'Retry Payment';
      return;
    }

    // Step 2: Show animated success screen
    document.getElementById('payment-form-body').style.display = 'none';
    const sScreen = document.getElementById('success-screen');
    sScreen.style.display = 'block';

    document.getElementById('res-order-id').textContent = '#' + data.order_id;
    document.getElementById('res-amount').textContent = 'PHP ' + Number(data.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('res-method').textContent = data.method;
    document.getElementById('res-ref').textContent = data.reference_no;

    // Countdown and automatic redirect / close
    let secs = 4;
    const cEl = document.getElementById('countdown');
    const timer = setInterval(() => {
      secs--;
      if (cEl) cEl.textContent = secs;
      if (secs <= 0) {
        clearInterval(timer);
        // Try closing this customer tab if opened via window.open, otherwise redirect
        try {
          if (window.opener && !window.opener.closed) {
            window.close();
            return;
          }
        } catch (e) {}
        window.location.href = 'menu.php?paymongo_success=1&order_id=' + data.order_id;
      }
    }, 1000);

  } catch (err) {
    console.error('Payment error:', err);
    alert('An unexpected network error occurred. Please try again.');
    if (btn) btn.disabled = false;
    if (btnText) btnText.textContent = 'Authorize Payment';
  }
}
</script>

</body>
</html>
