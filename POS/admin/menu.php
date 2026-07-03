<?php
require_once '../includes/auth_check.php';
require_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>POS System</title>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="stylesheet" href="../css/sidebar.css">
  <link rel="stylesheet" href="../css/menu.css">
  <link rel="stylesheet" href="../css/order-panel.css">
  <style>
    /* ── Receipt Modal ── */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(44, 26, 14, 0.45);
      z-index: 99999;
      backdrop-filter: blur(3px);
      /* must not inherit any transform from parent */
    }
    .modal-overlay.open {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .receipt-modal {
      background: #fff;
      border-radius: 24px;
      margin: auto;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 24px 64px rgba(44,26,14,.22);
      animation: popIn .25s cubic-bezier(.34,1.56,.64,1);
      overflow: hidden;
    }
    @keyframes popIn {
      from { opacity: 0; transform: scale(.88) translateY(20px); }
      to   { opacity: 1; transform: scale(1)  translateY(0); }
    }

    /* header strip */
    .receipt-head {
      background: linear-gradient(135deg, #fff3e0, #ffe0b2);
      padding: 32px 28px 24px;
      text-align: center;
      border-bottom: 1.5px solid #ecddc8;
    }
    .receipt-check {
      width: 64px; height: 64px;
      background: #c47d3e;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 30px;
      margin: 0 auto 14px;
      box-shadow: 0 4px 16px rgba(196,125,62,.3);
    }
    .receipt-head h2 {
      font-size: 20px; font-weight: 800;
      color: #2c1a0e; margin-bottom: 4px;
    }
    .receipt-head p {
      font-size: 13px; color: #9a7e65;
    }
    .receipt-order-num {
      display: inline-block;
      margin-top: 10px;
      padding: 5px 16px;
      background: #c47d3e;
      color: #fff;
      border-radius: 20px;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .04em;
    }

    /* body */
    .receipt-body { padding: 22px 28px; }

    .receipt-meta {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: #9a7e65;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 14px;
    }

    .receipt-items { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }

    .receipt-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 12px;
      background: #faf5ef;
      border-radius: 10px;
      border: 1px solid #ecddc8;
    }
    .ri-icon { font-size: 20px; flex-shrink: 0; }
    .ri-info { flex: 1; }
    .ri-name { font-size: 13px; font-weight: 700; color: #2c1a0e; }
    .ri-size { font-size: 10px; color: #9a7e65; }
    .ri-qty  { font-size: 11px; font-weight: 700; color: #9a7e65; background: #ecddc8; border-radius: 6px; padding: 2px 7px; flex-shrink: 0; }
    .ri-price{ font-size: 13px; font-weight: 800; color: #c47d3e; flex-shrink: 0; min-width: 52px; text-align: right; }

    /* totals */
    .receipt-totals {
      border-top: 1.5px dashed #ecddc8;
      padding-top: 14px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .rt-row { display: flex; justify-content: space-between; font-size: 12px; color: #9a7e65; }
    .rt-row.grand {
      font-size: 17px; font-weight: 800;
      color: #2c1a0e; margin-top: 6px;
      padding-top: 10px;
      border-top: 1.5px solid #ecddc8;
    }
    .rt-row.grand span:last-child { color: #c47d3e; }

    /* footer buttons */
    .receipt-footer {
      padding: 0 28px 24px;
      display: flex;
      gap: 10px;
    }
    .btn-print {
      flex: 1; padding: 12px;
      border: 1.5px solid #ecddc8;
      border-radius: 12px;
      background: none;
      font-family: 'Poppins', sans-serif;
      font-size: 13px; font-weight: 600;
      color: #9a7e65; cursor: pointer;
      transition: all .15s;
    }
    .btn-print:hover { border-color: #c47d3e; color: #c47d3e; }
    .btn-new-order {
      flex: 2; padding: 12px;
      background: #c47d3e; color: #fff;
      border: none; border-radius: 12px;
      font-family: 'Poppins', sans-serif;
      font-size: 13px; font-weight: 700;
      cursor: pointer; transition: background .15s;
    }
    .btn-new-order:hover { background: #7a4e2e; }
  </style>
</head>
<body>

<?php include('../includes/admin_sidebar.php'); ?>

<div class="pages">
  <div id="page-menu" class="page active">

    <div class="menu-left">
      <div class="category-tabs">
        <div class="cat-tab active" onclick="switchCat(this,'ice-coffee')">
          <span class="cat-icon">🧊</span>ICE COFFEE
        </div>
        <div class="cat-tab" onclick="switchCat(this,'hot-coffee')">
          <span class="cat-icon">☕</span>HOT COFFEE
        </div>
        <div class="cat-tab" onclick="switchCat(this,'milk-tea')">
          <span class="cat-icon">🧋</span>MILK TEA
        </div>
        <div class="cat-tab" onclick="switchCat(this,'fruit-tea')">
          <span class="cat-icon">🍹</span>FRUIT TEA
        </div>
      </div>

      <div class="size-bar">
        <button class="size-btn active" onclick="switchSize(this,'small')">Small</button>
        <button class="size-btn" onclick="switchSize(this,'large')">Large</button>
      </div>

      <div class="menu-grid-wrap">
        <div id="menu-grid" class="menu-grid"></div>
      </div>
    </div>

    <div class="order-panel">
      <div class="order-type-bar">
        <button class="order-type-btn active" onclick="switchOrderType(this,'dine')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2h18M3 6h18M21 12H3M3 16h10"/><circle cx="17" cy="18" r="3"/></svg>
          Dine In
        </button>
        <button class="order-type-btn" onclick="switchOrderType(this,'take')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
          Take Out
        </button>
        <button class="order-type-btn" onclick="switchOrderType(this,'delivery')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          Delivery
        </button>
      </div>

      <div id="order-items" class="order-items">
        <div class="order-empty">
          <div class="oe-icon">🧋</div>
          <p>No items yet</p>
          <small>Tap a drink to add it</small>
        </div>
      </div>

      <div class="order-footer">
        <div class="order-row"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
        <div class="order-row"><span>Tax (12%)</span><span id="tax">₱0.00</span></div>
        <div class="order-row total"><strong>Total</strong><strong id="total">₱0.00</strong></div>
        <button class="checkout-btn" onclick="checkout()">Place Order</button>
        <button class="clear-btn" onclick="clearOrder()">🗑️ Clear Order</button>
      </div>
    </div>

  </div>
</div>

<!-- ── Receipt Modal ── -->
<div class="modal-overlay" id="receipt-overlay">
  <div class="receipt-modal">

    <div class="receipt-head">
      <div class="receipt-check">✅</div>
      <h2>Order Placed!</h2>
      <p>Your order has been sent to the kitchen</p>
      <span class="receipt-order-num" id="r-order-num">#0001</span>
    </div>

    <div class="receipt-body">
      <div class="receipt-meta">
        <span id="r-type">Dine In</span>
        <span id="r-time"></span>
      </div>

      <div class="receipt-items" id="r-items"></div>

      <div class="receipt-totals">
        <div class="rt-row"><span>Subtotal</span><span id="r-subtotal">₱0.00</span></div>
        <div class="rt-row"><span>Tax (12%)</span><span id="r-tax">₱0.00</span></div>
        <div class="rt-row grand"><span>Total</span><span id="r-total">₱0.00</span></div>
      </div>
    </div>

    <div class="receipt-footer">
      <button class="btn-print" onclick="printReceipt()">🖨️ Print</button>
      <button class="btn-new-order" onclick="closeReceipt()">✅ New Order</button>
    </div>

  </div>
</div>

<script src="../js/menu.js"></script>
<script>
  // Move modals to <body> root so no parent stacking context clips them
  document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('receipt-overlay');
    if (el) document.body.appendChild(el);
  });
</script>
</body>
</html>