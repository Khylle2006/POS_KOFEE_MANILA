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
