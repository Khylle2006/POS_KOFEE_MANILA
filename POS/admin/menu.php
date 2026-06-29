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
</head>

<body>

<?php include('../includes/admin_sidebar.php'); ?>

<div class="pages">

    <!-- MENU PAGE -->
    <div id="page-menu" class="page active">

        <div class="menu-left">

            <!-- CATEGORY -->
            <div class="category-tabs">
                <div class="cat-tab active" onclick="switchCat(this,'ice-coffee')">🧊 ICE COFFEE</div>
                <div class="cat-tab" onclick="switchCat(this,'hot-coffee')">☕ HOT COFFEE</div>
                <div class="cat-tab" onclick="switchCat(this,'milk-tea')">🧋 MILK TEA</div>
                <div class="cat-tab" onclick="switchCat(this,'fruit-tea')">🍹 FRUIT TEA</div>
            </div>

            <!-- SIZE -->
            <div class="size-bar">
                <button class="size-btn active" onclick="switchSize(this,'small')">Small</button>
                <button class="size-btn" onclick="switchSize(this,'large')">Large</button>
            </div>

            <!-- MENU GRID -->
            <div class="menu-grid-wrap">
                <div id="menu-grid" class="menu-grid"></div>
            </div>

        </div>

        <!-- ORDER PANEL -->
        <div class="order-panel">

            <div class="order-type-bar">
                <button class="order-type-btn active" onclick="switchOrderType(this,'dine')">Dine In</button>
                <button class="order-type-btn" onclick="switchOrderType(this,'take')">Take Out</button>
                <button class="order-type-btn" onclick="switchOrderType(this,'delivery')">Delivery</button>
            </div>

            <div id="order-items" class="order-items">
                <div class="order-empty">
                    <div class="oe-icon">🧋</div>
                    <p>No items yet</p>
                    <small>Tap a drink to add it</small>
                </div>
            </div>

            <div class="order-footer">
                <div><span>Subtotal</span> <span id="subtotal">₱0.00</span></div>
                <div><span>Tax</span> <span id="tax">₱0.00</span></div>
                <div><strong>Total</strong> <strong id="total">₱0.00</strong></div>

                <button class="checkout-btn" onclick="checkout()">Place Order</button>
                <button class="clear-btn" onclick="clearOrder()">Clear</button>
            </div>

        </div>

    </div>

</div>

<!-- IMPORTANT: JS MUST BE LOADED LAST -->
<script src="../js/menu.js"></script>

</body>
</html>