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
    
    <script>
        // ── CATEGORY & SIZE ──────────────────────────────
        function switchCat(el, cat) {
        document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        currentCat = cat;
        renderGrid();
        }

        function switchSize(el, size) {
        document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
        currentSize = size;
        renderGrid();
        }

        function switchOrderType(el, type) {
        document.querySelectorAll('.order-type-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
        orderType = type;
        }

        // ── MENU GRID ──────────────────────────────────────
        function renderGrid() {
        const grid = document.getElementById('menu-grid');
        const items = menuData[currentCat] || [];
        if (items.length === 0) {
            grid.innerHTML = `<div class="empty-cat">
            <div class="empty-icon">🫙</div>
            <p>No items in this category yet</p>
            <small>Go to "Add" to create your first drink</small>
            </div>`;
            return;
        }
        grid.innerHTML = items.map(item => {
            const price = currentSize === 'small' ? item.priceSmall : item.priceLarge;
            return `<div class="menu-card" onclick="addToOrder('${item.id}')">
            <div class="item-img">${item.icon}</div>
            <div class="item-name">${item.name}</div>
            <div class="item-price">₱${price}</div>
            </div>`;
        }).join('');
        }

        // ── ORDER ──────────────────────────────────────────
        function addToOrder(itemId) {
        const item = menuData[currentCat].find(i => i.id === itemId);
        if (!item) return;
        const price = currentSize === 'small' ? item.priceSmall : item.priceLarge;
        const key = itemId + '_' + currentSize;
        const existing = orderItems.find(o => o.key === key);
        if (existing) {
            existing.qty++;
        } else {
            orderItems.push({ key, id: itemId, name: item.name, icon: item.icon, size: currentSize, price: Number(price), qty: 1 });
        }
        renderOrder();
        showToast('🧋', item.name + ' added!');
        }

        function renderOrder() {
        const container = document.getElementById('order-items');
        const empty = document.getElementById('order-empty');

        if (orderItems.length === 0) {
            container.innerHTML = '';
            container.appendChild(getEmptyEl());
            updateTotals();
            return;
        }

        let html = orderItems.map(o => `
            <div class="order-item-row">
            <div class="oi-icon">${o.icon}</div>
            <div class="oi-info">
                <div class="oi-name">${o.name}</div>
                <div class="oi-size">${o.size.charAt(0).toUpperCase() + o.size.slice(1)}</div>
            </div>
            <div class="oi-controls">
                <button class="qty-btn" onclick="changeQty('${o.key}',-1)">−</button>
                <span class="qty-num">${o.qty}</span>
                <button class="qty-btn" onclick="changeQty('${o.key}',1)">+</button>
            </div>
            <div class="oi-price">₱${(o.price * o.qty).toFixed(0)}</div>
            </div>
        `).join('');
        container.innerHTML = html;
        updateTotals();
        }

        function getEmptyEl() {
        const d = document.createElement('div');
        d.className = 'order-empty';
        d.id = 'order-empty';
        d.innerHTML = '<div class="oe-icon">🧋</div><p>No items yet</p><small>Tap a drink to add it to the order</small>';
        return d;
        }

        function changeQty(key, delta) {
        const idx = orderItems.findIndex(o => o.key === key);
        if (idx === -1) return;
        orderItems[idx].qty += delta;
        if (orderItems[idx].qty <= 0) orderItems.splice(idx, 1);
        renderOrder();
        }

        function updateTotals() {
        const sub = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
        const tax = sub * 0.12;
        document.getElementById('subtotal').textContent = '₱' + sub.toFixed(2);
        document.getElementById('tax').textContent = '₱' + tax.toFixed(2);
        document.getElementById('total').textContent = '₱' + (sub + tax).toFixed(2);
        }

        function clearOrder() {
        orderItems = [];
        renderOrder();
        }

        function checkout() {
        if (orderItems.length === 0) { showToast('⚠️', 'Add items first'); return; }
        const sub = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
        const total = (sub * 1.12).toFixed(2);
        const num = '#' + String(historyData.length + 1).padStart(4,'0');
        const typeMap = { dine:'Dine In', take:'Take Out', delivery:'Delivery' };
        const itemsStr = orderItems.map(o => `${o.name} x${o.qty}`).join(', ');
        const now = new Date();
        const dt = now.toLocaleString('en-PH',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
        historyData.unshift({ id: num, dt, items: itemsStr, type: typeMap[orderType], total: Number(total) });
        clearOrder();
        showToast('✅', 'Order ' + num + ' placed!');
        }
    </script>

</body>
</html>