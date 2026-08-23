<?php
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();
require_permission('orders.new');

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>POS System</title>
  <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__.'/../css/style.css') ?>">
  <link rel="stylesheet" href="../css/sidebar.css?v=<?= filemtime(__DIR__.'/../css/sidebar.css') ?>">
  <link rel="stylesheet" href="../css/menu.css?v=<?= filemtime(__DIR__.'/../css/menu.css') ?>">
  <link rel="stylesheet" href="../css/order-panel.css?v=<?= filemtime(__DIR__.'/../css/order-panel.css') ?>">
  <link rel="stylesheet" href="../css/receipt_modal.css?v=<?= filemtime(__DIR__.'/../css/receipt_modal.css') ?>">
</head>
<body>

<div id="page-menu" class="page active">

    <div class="menu-left">
      <div class="menu-left-head">
        <div>
          <h1>New Order</h1>
          <p>Tap a drink to add it to the cart</p>
        </div>
        <div class="menu-search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input type="search" id="menu-search" placeholder="Search drinks…" oninput="filterProducts(this.value)">
        </div>
      </div>

      <div class="category-tabs">
        <div class="cat-tab active" onclick="switchCat(this,'ice-coffee')">
          <span class="cat-icon">🧊</span>Ice Coffee
        </div>
        <div class="cat-tab" onclick="switchCat(this,'hot-coffee')">
          <span class="cat-icon">☕</span>Hot Coffee
        </div>
        <div class="cat-tab" onclick="switchCat(this,'milk-tea')">
          <span class="cat-icon">🧋</span>Milk Tea
        </div>
        <div class="cat-tab" onclick="switchCat(this,'fruit-tea')">
          <span class="cat-icon">🍹</span>Fruit Tea
        </div>
      </div>

      <div class="size-bar">
        <button class="size-btn active" onclick="switchSize(this,'small')">Regular</button>
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
        <div class="order-row"><span>Subtotal (VAT-ex)</span><span id="subtotal">₱0.00</span></div>
        <div class="order-row"><span>VAT (12%)</span><span id="tax">₱0.00</span></div>
        <div class="order-row total"><strong>Total</strong><strong id="total">₱0.00</strong></div>
        <button class="checkout-btn" onclick="checkout()">Place Order</button>
        <button class="clear-btn" onclick="clearOrder()">🗑️ Clear Order</button>
      </div>
    </div>

  </div>

<!-- Receipt Modal -->
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
        <div class="rt-row"><span>Subtotal (VAT-ex)</span><span id="r-subtotal">₱0.00</span></div>
        <div class="rt-row"><span>VAT (12%)</span><span id="r-tax">₱0.00</span></div>
        <div class="rt-row grand"><span>Total</span><span id="r-total">₱0.00</span></div>
      </div>
    </div>

    <div class="receipt-footer">
      <button class="btn-print" onclick="printReceipt()">🖨️ Print</button>
      <button class="btn-new-order" onclick="closeReceipt()">✅ New Order</button>
    </div>

  </div>
</div>

<div class="modal-overlay" id="confirm-overlay">
  <div class="receipt-modal" style="max-width:360px">
    <div class="receipt-head" style="padding:24px 24px 18px">
      <div class="receipt-check" style="background:#8B5E3C">❓</div>
      <h2>Confirm Order?</h2>
    </div>
    <div class="receipt-body" style="padding:18px 24px">
      <div id="confirm-items-list" style="border-top:1px solid #ecddc8;border-bottom:1px solid #ecddc8;padding:8px 0;margin-bottom:12px;max-height:200px;overflow-y:auto"></div>
      <div style="display:flex;justify-content:space-between;font-weight:800;font-size:16px;color:#2c1a0e">
        <span>Total</span><span id="confirm-total" style="color:#c47d3e">₱0.00</span>
      </div>
      <div style="font-size:12px;color:#9a7e65;margin-top:6px" id="confirm-type"></div>
    </div>
    <div class="receipt-footer">
      <button class="btn-print" onclick="closeConfirmOrder()">Cancel</button>
      <button class="btn-new-order" id="confirm-order-btn" onclick="submitConfirmedOrder()">✅ Confirm & Place Order</button>
    </div>
  </div>
</div>

<!-- No Items Modal -->
<div class="modal-overlay" id="noitems-overlay">
  <div class="receipt-modal" style="max-width:340px">
    <div class="receipt-head" style="padding:24px 24px 18px">
      <div class="receipt-check" style="background:#d4a056">⚠️</div>
      <h2>No Items!</h2>
      <p>Please add items first.</p>
    </div>
    <div class="receipt-footer" style="padding:0 24px 22px">
      <button class="btn-new-order" style="flex:1" onclick="closeNoItems()">OK</button>
    </div>
  </div>
</div>

<!-- SweetAlert2 first, then menu.js -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../js/menu.js?v=<?= filemtime(__DIR__.'/../js/menu.js') ?>"></script>

</body>
</html>