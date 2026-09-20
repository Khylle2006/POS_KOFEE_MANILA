<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_login();
require_clocked_in_for_pos();
require_permission('orders.new');
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

<?php include("../includes/sidebar.php"); ?>

<div id="page-menu" class="page active">

    <!-- Mobile Drawer Backdrop for phone / tablet -->
    <div class="mobile-cart-backdrop" id="mobile-cart-backdrop" onclick="closeMobileCart()"></div>

    <div class="menu-left">
      <!-- Executive POS Header matching reference design -->
      <div class="pos-page-head">
        <div class="pos-head-title-wrap">
          <h1 class="pos-title">Point of Sale</h1>
          <div class="pos-breadcrumbs">
            <span class="p-crumb active">Catalog</span>
            <span class="p-sep">&rarr;</span>
            <span class="p-crumb">Size &amp; Add-ons</span>
            <span class="p-sep">&rarr;</span>
            <span class="p-crumb">Order Ticket</span>
            <span class="p-sep">&rarr;</span>
            <span class="p-crumb">Checkout &amp; Receipt</span>
          </div>
        </div>

        <div class="pos-head-actions">
          <div class="menu-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" id="menu-search" placeholder="Search drinks…" oninput="filterProducts(this.value)">
          </div>
          <div class="pos-shift-badge">
            <span class="pos-pulse-ring"></span>
            <span class="pos-shift-text">Active POS Counter</span>
          </div>
        </div>
      </div>

      <!-- POS Process Workflow Card (Inspired by reference design) -->
      <div class="pos-process-card">
        <div class="ppc-left">
          <div class="ppc-icon"><?= icon('coffee', 20) ?></div>
          <div class="ppc-info">
            <strong>Order Workflow</strong>
            <p>Tap drinks to add to ticket, choose size, review, and complete payment</p>
          </div>
        </div>
        <div class="ppc-steps">
          <div class="ppc-step is-active">
            <span class="ppc-step-icon"><?= icon('coffee', 13) ?></span>
            <span>1. Drinks</span>
            <span class="ppc-step-arrow">&rsaquo;</span>
          </div>
          <div class="ppc-step">
            <span class="ppc-step-icon"><?= icon('cup-tea', 13) ?></span>
            <span>2. Size</span>
            <span class="ppc-step-arrow">&rsaquo;</span>
          </div>
          <div class="ppc-step">
            <span class="ppc-step-icon"><?= icon('shopping-bag', 13) ?></span>
            <span>3. Ticket</span>
            <span class="ppc-step-arrow">&rsaquo;</span>
          </div>
          <div class="ppc-step">
            <span class="ppc-step-icon"><?= icon('credit-card', 13) ?></span>
            <span>4. Payment</span>
          </div>
        </div>
      </div>

      <div class="catalog-controls-bar">
        <div class="category-tabs">
          <button type="button" class="cat-tab active" onclick="switchCat(this,'ice-coffee')">
            <?= icon('ice-coffee', 14) ?>
            <span>Ice Coffee</span>
          </button>
          <button type="button" class="cat-tab" onclick="switchCat(this,'hot-coffee')">
            <?= icon('coffee', 14) ?>
            <span>Hot Coffee</span>
          </button>
          <button type="button" class="cat-tab" onclick="switchCat(this,'milk-tea')">
            <?= icon('cup-tea', 14) ?>
            <span>Milk Tea</span>
          </button>
          <button type="button" class="cat-tab" onclick="switchCat(this,'fruit-tea')">
            <?= icon('fruit', 14) ?>
            <span>Fruit Tea</span>
          </button>
        </div>

        <div class="size-bar">
          <button type="button" class="size-btn active" onclick="switchSize(this,'small')">
            <span>Regular</span> <small>(16oz)</small>
          </button>
          <button type="button" class="size-btn" onclick="switchSize(this,'large')">
            <span>Up Size</span> <small>(22oz)</small>
          </button>
        </div>
      </div>

      <div class="menu-grid-wrap">
        <div id="menu-grid" class="menu-grid"></div>
      </div>
    </div>

    <!-- Order Panel (Right side on Desktop, Bottom Sheet Drawer on Mobile) -->
    <div class="order-panel" id="order-panel">
      <!-- Mobile Drawer Handle & Header (Visible only on mobile/tablet) -->
      <div class="order-panel-mobile-bar">
        <div class="op-drag-handle"></div>
        <div class="op-mobile-head">
          <div class="op-mobile-title">
            <strong>Order Ticket</strong>
            <span class="op-mobile-count" id="mobile-ticket-count">0 items</span>
          </div>
          <button type="button" class="op-mobile-close-btn" onclick="closeMobileCart()">
            <?= icon('x', 16) ?> <span>Close</span>
          </button>
        </div>
      </div>

      <div class="order-type-bar">
        <button class="order-type-btn active" onclick="switchOrderType(this,'dine')">
          <?= icon('utensils', 15) ?>
          <span>Dine In</span>
        </button>
        <button class="order-type-btn" onclick="switchOrderType(this,'take')">
          <?= icon('shopping-bag', 15) ?>
          <span>Take Out</span>
        </button>
      </div>

      <div id="order-items" class="order-items">
        <div class="order-empty">
          <div class="oe-icon"><img src="../assets/milktea.png" alt="" style="width:48px;height:48px;opacity:0.6;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"/></div>
          <p>No items yet</p>
          <small>Tap a drink to add it</small>
        </div>
      </div>

      <div class="order-footer">
        <div class="order-row"><span>Subtotal (VAT-ex)</span><span id="subtotal">₱0.00</span></div>
        <div class="order-row"><span>VAT (12%)</span><span id="tax">₱0.00</span></div>
        <div class="order-row total"><strong>Total</strong><strong id="total">₱0.00</strong></div>
        <button class="checkout-btn" onclick="checkout()"><?= icon('credit-card', 16) ?> Place Order</button>
        <button class="clear-btn" onclick="clearOrder()"><?= icon('trash', 14) ?> Clear Order</button>
      </div>
    </div>

    <!-- Sticky Mobile Cart Bar for Phones & Tablets -->
    <div class="mobile-cart-bar" id="mobile-cart-bar" onclick="openMobileCart()">
      <div class="mcb-left">
        <div class="mcb-icon-wrap">
          <?= icon('cart', 20) ?>
          <span class="mcb-badge" id="mcb-count">0</span>
        </div>
        <div class="mcb-text">
          <span class="mcb-label">Order Total</span>
          <strong class="mcb-total" id="mcb-total">₱0.00</strong>
        </div>
      </div>
      <button type="button" class="mcb-btn">
        <span>View Ticket &amp; Pay</span>
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </div>

  </div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receipt-overlay">
  <div class="receipt-modal">

    <div class="receipt-head">
      <div class="receipt-check"><?= icon('check', 24) ?></div>
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
        <div class="rt-divider"></div>
        <div class="rt-row" id="r-row-payment"><span>Payment Method</span><span id="r-payment-method">Cash</span></div>
        <div class="rt-row" id="r-row-tendered"><span>Cash Tendered</span><span id="r-tendered">₱0.00</span></div>
        <div class="rt-row" id="r-row-change"><span>Change</span><span id="r-change">₱0.00</span></div>
        <div class="rt-row" id="r-row-ref" style="display:none;"><span>Reference / ID</span><span id="r-ref" class="r-ref-code">-</span></div>
      </div>
    </div>

    <div class="receipt-footer">
      <button class="btn-print" onclick="printReceipt()"><?= icon('printer', 14) ?> Print</button>
      <button class="btn-new-order" onclick="closeReceipt()"><?= icon('plus', 14) ?> New Order</button>
    </div>

  </div>
</div>

<!-- Payment & Confirm Modal -->
<div class="modal-overlay" id="confirm-overlay">
  <div class="receipt-modal payment-modal">
    <div class="receipt-head payment-modal-head">
      <div class="receipt-check" style="background:var(--caramel);"><?= icon('credit-card', 22) ?></div>
      <h2>Payment & Confirmation</h2>
      <p>Review items and select payment method</p>
    </div>

    <div class="receipt-body payment-modal-body">
      <div class="checkout-summary-card">
        <div class="csc-left">
          <span class="csc-type" id="confirm-type">Dine In</span>
          <span class="csc-count" id="confirm-count">0 items</span>
        </div>
        <div class="csc-right">
          <span class="csc-label">Total to Pay</span>
          <span class="csc-total" id="confirm-total">₱0.00</span>
        </div>
      </div>

      <details class="checkout-items-toggle">
        <summary><span><?= icon('menu-lines', 13) ?> View Order Items</span></summary>
        <div id="confirm-items-list" class="checkout-items-dropdown"></div>
      </details>

      <div class="pm-section-title">Select Payment Method</div>
      <div class="payment-methods-grid">
        <button type="button" class="pay-method-card active" data-method="cash" onclick="selectPaymentMethod('cash')">
          <span class="pmc-icon"><?= icon('coin', 18) ?></span>
          <span class="pmc-name">Cash</span>
          <span class="pmc-desc">Cash & Change</span>
        </button>

        <button type="button" class="pay-method-card" data-method="paymongo" onclick="selectPaymentMethod('paymongo')">
          <span class="pmc-icon"><?= icon('qr', 18) ?></span>
          <span class="pmc-name">PayMongo QR</span>
          <span class="pmc-desc">GCash / Maya / Card</span>
        </button>

        <button type="button" class="pay-method-card" data-method="ewallet" onclick="selectPaymentMethod('ewallet')">
          <span class="pmc-icon"><?= icon('smartphone', 18) ?></span>
          <span class="pmc-name">Manual E-Wallet</span>
          <span class="pmc-desc">OTC GCash / Maya</span>
        </button>

        <button type="button" class="pay-method-card" data-method="card" onclick="selectPaymentMethod('card')">
          <span class="pmc-icon"><?= icon('card', 18) ?></span>
          <span class="pmc-name">Card Terminal</span>
          <span class="pmc-desc">POS Slip Approval</span>
        </button>
      </div>

      <!-- Detail Panels per Method -->
      <!-- 1. CASH -->
      <div id="pm-panel-cash" class="pm-panel active">
        <label class="pm-label" for="cash-tendered">Cash Tendered (₱)</label>
        <div class="tendered-input-wrap">
          <span class="t-prefix">₱</span>
          <input type="number" step="any" min="0" id="cash-tendered" placeholder="0.00" oninput="onTenderedInput(this.value)">
          <button type="button" class="btn-clear-t" onclick="clearTendered()" title="Clear">✕</button>
        </div>

        <div class="quick-bills-bar">
          <button type="button" class="quick-bill-pill exact" onclick="applyQuickBill('exact')">Exact</button>
          <button type="button" class="quick-bill-pill" onclick="applyQuickBill(100)">₱100</button>
          <button type="button" class="quick-bill-pill" onclick="applyQuickBill(200)">₱200</button>
          <button type="button" class="quick-bill-pill" onclick="applyQuickBill(500)">₱500</button>
          <button type="button" class="quick-bill-pill" onclick="applyQuickBill(1000)">₱1,000</button>
        </div>

        <div class="change-display-card" id="change-display-card">
          <div class="cdc-header">
            <span class="cdc-title">Change Due:</span>
            <span class="cdc-amount" id="cash-change">₱0.00</span>
          </div>
          <div class="cdc-note" id="cash-change-msg">Enter amount received from customer</div>
        </div>
      </div>

      <!-- 2. PAYMONGO -->
      <div id="pm-panel-paymongo" class="pm-panel">
        <div class="paymongo-box">
          <div id="pm-init-box" class="pm-init-state">
            <div class="pm-init-icon"><?= icon('qr', 36) ?></div>
            <h4>Dynamic PayMongo QR</h4>
            <p>Generate a secure PayMongo checkout session supporting GCash, Maya, and credit/debit cards.</p>
            <button type="button" class="btn-pm-generate" onclick="startPayMongoSession()">
              <?= icon('zap', 14) ?> Generate QR & Checkout
            </button>
          </div>

          <div id="pm-live-box" class="pm-live-state" style="display:none;">
            <div class="pm-qr-frame">
              <img id="pm-qr-img" src="" alt="Scan QR Code" />
              <div class="pm-qr-spinner-badge">
                <span class="pm-pulse-dot"></span> Waiting for payment...
              </div>
            </div>
            <div class="pm-live-details">
              <div class="pm-status-line" id="pm-status-text">Awaiting customer payment confirmation...</div>
              <div class="pm-actions-line">
                <a href="#" target="_blank" id="pm-ext-link" class="pm-btn-secondary">
                  <?= icon('external-link', 13) ?> Open Customer Checkout Screen
                </a>
                <button type="button" class="pm-btn-secondary" onclick="checkPayMongoStatusManual()">
                  <?= icon('refresh', 13) ?> Check Now
                </button>
              </div>
              <div id="pm-demo-actions" style="display:none; margin-top:8px;">
                <button type="button" class="pm-btn-simulate" onclick="simulatePayMongoPayment()">
                  <?= icon('shield-check', 13) ?> Simulate Customer Paid (Dev / Demo)
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. DIRECT E-WALLET -->
      <div id="pm-panel-ewallet" class="pm-panel">
        <div class="provider-pill-selector">
          <label class="provider-pill">
            <input type="radio" name="ewallet_vendor" value="GCash" checked onchange="setWalletVendor('GCash')">
            <span>GCash OTC</span>
          </label>
          <label class="provider-pill">
            <input type="radio" name="ewallet_vendor" value="Maya" onchange="setWalletVendor('Maya')">
            <span>Maya OTC</span>
          </label>
        </div>
        <label class="pm-label" for="ewallet-ref">Transaction / Reference Number <span style="color:#d9534f">*</span></label>
        <input type="text" id="ewallet-ref" class="pm-text-field" placeholder="Enter reference number (e.g. 1029384756)" maxlength="50">
        <div class="pm-field-hint">Customer scans your store QR code or sends payment directly. Enter the reference number here.</div>
      </div>

      <!-- 4. CARD TERMINAL -->
      <div id="pm-panel-card" class="pm-panel">
        <label class="pm-label" for="card-approval">Terminal Approval / Trace Number <span style="color:#d9534f">*</span></label>
        <input type="text" id="card-approval" class="pm-text-field" placeholder="Enter approval code (e.g. APP-9481)" maxlength="50">
        <div class="pm-field-hint">Swipe / tap customer card on physical terminal and input the approval code from the terminal printout.</div>
      </div>

    </div>

    <div class="receipt-footer payment-modal-footer">
      <button class="btn-print" onclick="closeConfirmOrder()">Cancel</button>
      <button class="btn-new-order" id="confirm-order-btn" onclick="submitConfirmedOrder()"><?= icon('check', 14) ?> Confirm & Complete</button>
    </div>
  </div>
</div>

<!-- No Items Modal -->
<div class="modal-overlay" id="noitems-overlay">
  <div class="receipt-modal" style="max-width:340px">
    <div class="receipt-head" style="padding:24px 24px 18px">
      <div class="receipt-check" style="background:#d4a056"><?= icon('alert-triangle', 24) ?></div>
      <h2>No Items!</h2>
      <p>Please add items first.</p>
    </div>
    <div class="receipt-footer" style="padding:0 24px 22px">
      <button class="btn-new-order" style="flex:1" onclick="closeNoItems()">OK</button>
    </div>
  </div>
</div>

<!-- SweetAlert2 first, then menu.js -->
<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="../js/menu.js?v=<?= filemtime(__DIR__.'/../js/menu.js') ?>"></script>

</body>
</html>