<?php
require_once '../includes/auth_check.php';
require_role(); 

$user = current_user();
$sidebar = $user['role'] === 'admin' ? 'admin_sidebar' : 'staff_sidebar';
include("../includes/$sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>POS System</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="menu.js"></script>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="stylesheet" href="../css/sidebar.css">
  <link rel="stylesheet" href="../css/menu.css">
  <link rel="stylesheet" href="../css/order-panel.css">
  <link rel="stylesheet" href="../css/receipt_modal.css">
</head>
<body>

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
        <button class="size-btn active" onclick="switchSize(this,'small')">Regular Size</button>
        <button class="size-btn" onclick="switchSize(this,'large')">Up Size</button>
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

</body>
</html>