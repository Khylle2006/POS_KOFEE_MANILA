<?php
require_once '../includes/auth_check.php';
require_role('staff');
?>

<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/menu.css">
    <link rel="stylesheet" href="../css/order-panel.css">
</head>
<body>

    <?php include('../includes/staff_sidebar.php'); ?>

    <div class="pages">
        <!-- ══ MENU / ORDER ══ -->
        <div id="page-menu" class="page active">
            <div class="menu-left">
            <!-- Category tabs -->
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

            <!-- Size bar -->
            <div class="size-bar">
                <button class="size-btn active" onclick="switchSize(this,'small')">Small</button>
                <button class="size-btn" onclick="switchSize(this,'large')">Large</button>
            </div>

            <!-- Menu grid -->
            <div class="menu-grid-wrap">
                <div class="menu-grid" id="menu-grid">
                <!-- Populated by JS -->
                </div>
            </div>
            </div>

            <!-- Order Panel -->
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

            <div class="order-items" id="order-items">
                <div class="order-empty" id="order-empty">
                <div class="oe-icon">🧋</div>
                <p>No items yet</p>
                <small>Tap a drink to add it to the order</small>
                </div>
            </div>

            <div class="order-footer">
                <div class="order-row"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
                <div class="order-row"><span>Tax (12%)</span><span id="tax">₱0.00</span></div>
                <div class="order-row total"><span>Total</span><span id="total">₱0.00</span></div>
                <button class="checkout-btn" onclick="checkout()">Place Order</button>
                <button class="clear-btn" onclick="clearOrder()">Clear Order</button>
            </div>
            </div>
        </div>
    </div>
    
    <script src="../js/menu.js" ></script>

</body>
</html>