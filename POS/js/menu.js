console.log("menu.js loaded");
let menuData = {};

// High-resolution real drink pictures mapped by category / drink profile
const CATEGORY_DEFAULT_IMAGES = {
    "ice-coffee": "../assets/menu/772270600_2085203345723099_8651165942499832865_n.jpg",
    "hot-coffee": "../assets/menu/773290708_1367203135549588_5632825919597585597_n.jpg",
    "milk-tea":   "../assets/menu/772170060_1768708697657907_585482959715212815_n.jpg",
    "fruit-tea":  "../assets/menu/772465737_1877177910354395_5215628384114883458_n.jpg"
};
const DEFAULT_DRINK_IMAGE = "../assets/menu/772270600_2085203345723099_8651165942499832865_n.jpg";

function getProductImage(item, categoryKey) {
    if (item.image_path && item.image_path.trim()) {
        const raw = item.image_path.trim().replace(/^\/+/, '');
        return raw.startsWith('assets/') ? `../${raw}` : `../assets/${raw}`;
    }
    const name = (item.name || '').toLowerCase();
    if (name.includes('melon') || name.includes('watermelon')) {
        return '../assets/menu/1.jpg';
    }
    if (name.includes('berry') || name.includes('straw')) {
        return '../assets/menu/772465737_1877177910354395_5215628384114883458_n.jpg';
    }
    if (name.includes('lychee')) {
        return '../assets/menu/3.jpg';
    }
    if (name.includes('choco')) {
        return '../assets/menu/775492503_2412138042606551_5333859346167873462_n.jpg';
    }
    if (name.includes('caramel') || name.includes('spanish')) {
        return '../assets/menu/772270600_2085203345723099_8651165942499832865_n.jpg';
    }
    if (name.includes('pearl') || name.includes('boba') || name.includes('milk tea')) {
        return '../assets/menu/772170060_1768708697657907_585482959715212815_n.jpg';
    }
    return CATEGORY_DEFAULT_IMAGES[categoryKey] || DEFAULT_DRINK_IMAGE;
}

document.addEventListener("DOMContentLoaded", async () => {
    const res  = await fetch("../api/get_menu.php");
    const data = await res.json();

    menuData = {
        "ice-coffee": [],
        "hot-coffee": [],
        "milk-tea":   [],
        "fruit-tea":  []
    };

    data.forEach(item => {
        const key = item.category_name.toLowerCase().replace(" ", "-");
        if (!menuData[key]) menuData[key] = [];
        menuData[key].push({
            id:         item.id,
            name:       item.name,
            imageSrc:   getProductImage(item, key),
            priceSmall: parseFloat(item.price_small),
            priceLarge: parseFloat(item.price_large),
            stock:      parseInt(item.stock, 10) || 0
        });
    });

    renderGrid();
    renderOrder();
});

let currentCat  = "ice-coffee";
let currentSize = "small";
let orderItems  = [];
let orderType   = "dine";
let searchTerm  = "";

// ── Category & Size ───────────────────────────
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

function filterProducts(value) {
    searchTerm = (value || "").trim().toLowerCase();
    renderGrid();
}

// ── Menu Grid ─────────────────────────────────
function renderGrid() {
    const grid = document.getElementById('menu-grid');
    if (!grid) return;

    // Searching looks across every category so staff can find a drink
    // without first guessing which tab it lives under.
    const pool = searchTerm
        ? Object.values(menuData).flat()
        : (menuData[currentCat] || []);

    const items = searchTerm
        ? pool.filter(i => i.name.toLowerCase().includes(searchTerm))
        : pool;

    if (items.length === 0) {
        grid.innerHTML = searchTerm ? `<div class="empty-cat">
            <div class="empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
            <p>No drinks match "${escapeHtml(searchTerm)}"</p>
            <small>Try a different name or check another category</small>
        </div>` : `<div class="empty-cat">
            <div class="empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg></div>
            <p>No items in this category yet</p>
            <small>Add products in the menu manager</small>
        </div>`;
        return;
    }

    grid.innerHTML = items.map(item => {
        const price   = currentSize === 'small' ? item.priceSmall : item.priceLarge;
        const soldOut = item.stock <= 0;
        return `
        <div class="menu-card${soldOut ? ' sold-out' : ''}" ${soldOut ? '' : `onclick="addToOrder(${item.id})"`}>
            <div class="item-img">
                <img src="${escapeHtml(item.imageSrc)}" alt="${escapeHtml(item.name)}" onerror="this.onerror=null;this.src='${DEFAULT_DRINK_IMAGE}'"/>
            </div>
            <div class="item-name">${escapeHtml(item.name)}</div>
            ${soldOut
                ? `<div class="item-soldout">Sold out</div>`
                : `<div class="item-price">₱${parseFloat(price).toFixed(2)}</div>`}
        </div>`;
    }).join('');
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Order & Auto Storage Deduction ───────────
let cartBusy = false;
let orderSubmitted = false;

window.addEventListener('beforeunload', () => {
    if (orderItems.length > 0 && !orderSubmitted) {
        const body = JSON.stringify({
            action: 'refund_batch',
            items: orderItems
        });
        if (navigator.sendBeacon) {
            navigator.sendBeacon('../api/cart_stock.php', new Blob([body], { type: 'application/json' }));
        }
    }
});

async function addToOrder(itemId) {
    if (cartBusy) return;
    const item = Object.values(menuData).flat().find(i => i.id == itemId);
    if (!item) { console.error("Item not found:", itemId); return; }
    if (item.stock <= 0) return;

    cartBusy = true;
    try {
        const res = await fetch('../api/cart_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'deduct',
                product_id: itemId,
                size: currentSize,
                qty: 1
            })
        });
        const data = await res.json();
        if (!data.success) {
            Swal.fire({
                title: "Out of Stock!",
                text: data.error || "Cannot add item due to insufficient ingredients in storage.",
                icon: "warning",
                confirmButtonColor: '#C97B3D'
            });
            return;
        }

        const price    = currentSize === 'small' ? item.priceSmall : item.priceLarge;
        const key      = itemId + '_' + currentSize;
        const existing = orderItems.find(o => o.key === key);

        if (existing) {
            existing.qty++;
        } else {
            orderItems.push({
                key,
                id:       itemId,
                name:     item.name,
                imageSrc: item.imageSrc,
                size:     currentSize,
                price:    parseFloat(price),
                qty:      1
            });
        }
        renderOrder();
    } catch (err) {
        console.error("Storage deduction error:", err);
        Swal.fire({
            title: "Error!",
            text: "Failed to communicate with inventory storage.",
            icon: "error",
            confirmButtonColor: '#C97B3D'
        });
    } finally {
        cartBusy = false;
    }
}

function renderOrder() {
    const container = document.getElementById('order-items');
    if (!container) return;

    if (orderItems.length === 0) {
        container.innerHTML = `
        <div class="order-empty">
            <div class="oe-icon"><img src="../assets/milktea.png" alt="" style="width:48px;height:48px;opacity:0.6;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"/></div>
            <p>No items yet</p>
            <small>Tap a drink to add it</small>
        </div>`;
        updateTotals();
        return;
    }

    container.innerHTML = orderItems.map((o, i) => `
        <div class="order-item-row">
            <div class="oi-thumb">
                <img src="${escapeHtml(o.imageSrc)}" alt="${escapeHtml(o.name)}" onerror="this.onerror=null;this.src='${DEFAULT_DRINK_IMAGE}'"/>
            </div>
            <div class="oi-info">
                <div class="oi-name">${escapeHtml(o.name)}</div>
                <div class="oi-size">${o.size.charAt(0).toUpperCase() + o.size.slice(1)}</div>
            </div>
            <div class="oi-controls">
                <button class="qty-btn" onclick="changeQty(${i}, -1)">−</button>
                <span class="qty-num">${o.qty}</span>
                <button class="qty-btn" onclick="changeQty(${i}, 1)">+</button>
            </div>
            <div class="oi-price">₱${(o.price * o.qty).toFixed(2)}</div>
        </div>
    `).join('');

    updateTotals();
}

async function changeQty(index, delta) {
    if (cartBusy) return;
    const item = orderItems[index];
    if (!item) return;

    cartBusy = true;
    try {
        if (delta > 0) {
            const res = await fetch('../api/cart_stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'deduct',
                    product_id: item.id,
                    size: item.size,
                    qty: 1
                })
            });
            const data = await res.json();
            if (!data.success) {
                Swal.fire({
                    title: "Out of Stock!",
                    text: data.error || "Cannot add more due to shortage in storage.",
                    icon: "warning",
                    confirmButtonColor: '#C97B3D'
                });
                return;
            }
            item.qty += 1;
        } else if (delta < 0) {
            const res = await fetch('../api/cart_stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'refund',
                    product_id: item.id,
                    size: item.size,
                    qty: 1
                })
            });
            const data = await res.json();
            if (data.success) {
                item.qty -= 1;
                if (item.qty <= 0) {
                    orderItems.splice(index, 1);
                }
            }
        }
        renderOrder();
    } catch (err) {
        console.error("Cart stock change error:", err);
    } finally {
        cartBusy = false;
    }
}

async function removeItem(index) {
    if (cartBusy) return;
    const item = orderItems[index];
    if (!item) return;

    cartBusy = true;
    try {
        await fetch('../api/cart_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'refund',
                product_id: item.id,
                size: item.size,
                qty: item.qty
            })
        });
        orderItems.splice(index, 1);
        renderOrder();
    } catch (err) {
        console.error("Remove item error:", err);
    } finally {
        cartBusy = false;
    }
}

function calcVAT(gross) {
    // gross = VAT-inclusive price (what customer pays)
    // subtotal (VAT-exclusive) = gross / 1.12
    // vat = gross - subtotal
    const subtotal = gross / 1.12;
    const vat      = gross - subtotal;
    return { subtotal, vat, total: gross };
}

function updateTotals() {
    const gross = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
    const { subtotal, vat, total } = calcVAT(gross);

    const el = id => document.getElementById(id);
    if (el('subtotal')) el('subtotal').textContent = '₱' + subtotal.toFixed(2);
    if (el('tax'))      el('tax').textContent      = '₱' + vat.toFixed(2);
    if (el('total'))    el('total').textContent    = '₱' + total.toFixed(2);
}

async function clearOrder() {
    if (orderItems.length === 0 || cartBusy) return;
    const itemsToRefund = [...orderItems];
    orderItems = [];
    renderOrder();

    try {
        await fetch('../api/cart_stock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'refund_batch',
                items: itemsToRefund
            })
        });
    } catch (err) {
        console.error("Clear order refund error:", err);
    }
}

// ── Checkout ──────────────────────────────────
// ── Checkout & Modern Payments ────────────────
let pendingCheckout = null;
let selectedPaymentMethod = 'cash';
let payMongoOrderId = null;
let payMongoPollTimer = null;
let currentWalletVendor = 'GCash';

function selectPaymentMethod(method) {
    selectedPaymentMethod = ['cash', 'paymongo', 'ewallet', 'card'].includes(method) ? method : 'cash';

    // Update active state on method selection cards
    document.querySelectorAll('.pay-method-card').forEach(card => {
        card.classList.toggle('active', card.dataset.method === selectedPaymentMethod);
    });

    // Toggle panels
    document.querySelectorAll('.pm-panel').forEach(panel => {
        panel.classList.remove('active');
    });
    const activePanel = document.getElementById(`pm-panel-${selectedPaymentMethod}`);
    if (activePanel) activePanel.classList.add('active');

    // Update confirm button label
    const btn = document.getElementById('confirm-order-btn');
    if (!btn) return;

    if (selectedPaymentMethod === 'cash') {
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Complete Cash Order';
        setTimeout(() => document.getElementById('cash-tendered')?.focus(), 100);
    } else if (selectedPaymentMethod === 'paymongo') {
        btn.innerHTML = payMongoOrderId
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/><polyline points="23 4 23 10 17 10"/></svg> Check Payment Status'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> Generate PayMongo QR';
    } else if (selectedPaymentMethod === 'ewallet') {
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Confirm E-Wallet Payment';
        setTimeout(() => document.getElementById('ewallet-ref')?.focus(), 100);
    } else if (selectedPaymentMethod === 'card') {
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Confirm Card Payment';
        setTimeout(() => document.getElementById('card-approval')?.focus(), 100);
    }
}

function setWalletVendor(vendor) {
    currentWalletVendor = vendor || 'GCash';
}

function onTenderedInput(val) {
    if (!pendingCheckout) return;
    const total = pendingCheckout.total;
    const tendered = parseFloat(val) || 0;
    const change = tendered - total;

    const changeCard = document.getElementById('change-display-card');
    const changeAmt = document.getElementById('cash-change');
    const changeMsg = document.getElementById('cash-change-msg');

    if (val === '' || isNaN(parseFloat(val))) {
        changeCard.classList.remove('insufficient');
        changeAmt.textContent = '₱0.00';
        changeMsg.textContent = 'Enter amount received from customer';
        return;
    }

    if (change >= 0) {
        changeCard.classList.remove('insufficient');
        changeAmt.textContent = '₱' + change.toFixed(2);
        changeMsg.textContent = change === 0 ? 'Exact amount received.' : `Change due: ₱${change.toFixed(2)}`;
    } else {
        changeCard.classList.add('insufficient');
        changeAmt.textContent = '-₱' + Math.abs(change).toFixed(2);
        changeMsg.textContent = `Insufficient by ₱${Math.abs(change).toFixed(2)}`;
    }
}

function applyQuickBill(amount) {
    if (!pendingCheckout) return;
    const input = document.getElementById('cash-tendered');
    if (!input) return;

    if (amount === 'exact') {
        input.value = pendingCheckout.total.toFixed(2);
    } else {
        input.value = Number(amount).toFixed(2);
    }
    onTenderedInput(input.value);
}

function clearTendered() {
    const input = document.getElementById('cash-tendered');
    if (input) {
        input.value = '';
        onTenderedInput('');
        input.focus();
    }
}

function checkout() {
    if (orderItems.length === 0) {
        document.getElementById('noitems-overlay').classList.add('open');
        return;
    }

    const gross    = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
    const { subtotal, vat, total } = calcVAT(gross);
    const typeMap  = { dine: "Dine In", take: "Take Out", delivery: "Delivery" };
    const snapshot = [...orderItems];

    pendingCheckout = { subtotal, vat, total, snapshot, typeMap };

    // Render preview item list
    const itemsListHtml = orderItems.map(o => `
        <div class="cid-row">
            <span><strong>${escapeHtml(o.name)}</strong> (${o.size.charAt(0).toUpperCase() + o.size.slice(1)}) ×${o.qty}</span>
            <span>₱${(o.price * o.qty).toFixed(2)}</span>
        </div>
    `).join('');

    const itemsCount = orderItems.reduce((sum, it) => sum + it.qty, 0);
    document.getElementById('confirm-items-list').innerHTML = itemsListHtml;
    document.getElementById('confirm-total').textContent    = '₱' + total.toFixed(2);
    document.getElementById('confirm-type').textContent     = typeMap[orderType] || "Dine In";
    document.getElementById('confirm-count').textContent    = `${itemsCount} item${itemsCount > 1 ? 's' : ''}`;

    // Reset payment states
    clearTendered();
    const ewalletRef = document.getElementById('ewallet-ref');
    if (ewalletRef) ewalletRef.value = '';
    const cardAppr = document.getElementById('card-approval');
    if (cardAppr) cardAppr.value = '';

    // Reset PayMongo state
    document.getElementById('pm-live-box').style.display = 'none';
    document.getElementById('pm-init-box').style.display = 'block';
    if (payMongoPollTimer) {
        clearInterval(payMongoPollTimer);
        payMongoPollTimer = null;
    }
    payMongoOrderId = null;

    // Default to cash
    selectPaymentMethod('cash');

    document.getElementById('confirm-overlay').classList.add('open');
}

function closeNoItems() {
    document.getElementById('noitems-overlay').classList.remove('open');
}

function closeConfirmOrder(cancelPendingServerOrder = true) {
    if (payMongoPollTimer) {
        clearInterval(payMongoPollTimer);
        payMongoPollTimer = null;
    }

    if (cancelPendingServerOrder && payMongoOrderId) {
        fetch('../api/cancel_paymongo_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: payMongoOrderId })
        }).catch(err => console.warn('Cancel order error:', err));
    }

    payMongoOrderId = null;
    pendingCheckout = null;
    document.getElementById('confirm-overlay').classList.remove('open');
}

// ── PayMongo Dynamic QR & Real-time Status Polling ──────────
async function startPayMongoSession() {
    if (!pendingCheckout) return;

    const { total, snapshot, typeMap } = pendingCheckout;
    const btn = document.getElementById('btn-create-paymongo') || document.getElementById('confirm-order-btn');
    if (btn) btn.disabled = true;

    try {
        const payload = {
            total,
            order_type: typeMap[orderType] || "Dine In",
            items: snapshot.map(o => ({
                id:    o.id,
                qty:   o.qty,
                price: o.price,
                size:  o.size,
                name:  o.name
            }))
        };

        const res = await fetch('../api/create_paymongo_checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (!data.success) {
            showSimpleError(data.error || 'Failed to initialize PayMongo checkout.');
            return;
        }

        payMongoOrderId = data.order_id;
        const checkoutUrl = data.checkout_url;

        // Render QR Code using reliable SVG/PNG QR Generator
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=6&data=${encodeURIComponent(checkoutUrl)}`;
        const qrImg = document.getElementById('pm-qr-img');
        if (qrImg) qrImg.src = qrUrl;

        const extLink = document.getElementById('pm-ext-link');
        if (extLink) extLink.href = checkoutUrl;

        // Switch panel states
        document.getElementById('pm-init-box').style.display = 'none';
        document.getElementById('pm-live-box').style.display = 'block';

        // Show demo simulation button if in demo or dev environment
        const demoBox = document.getElementById('pm-demo-actions');
        if (demoBox) {
            demoBox.style.display = (data.is_demo || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') ? 'block' : 'none';
        }

        selectPaymentMethod('paymongo');

        // Start live polling every 2.5s
        startPayMongoPolling(payMongoOrderId);

    } catch (err) {
        console.error('PayMongo creation error:', err);
        showSimpleError('Unable to connect to payment processor. Please check your connection.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function startPayMongoPolling(orderId) {
    if (payMongoPollTimer) clearInterval(payMongoPollTimer);

    const checkStatus = async () => {
        if (!payMongoOrderId || payMongoOrderId !== orderId) {
            clearInterval(payMongoPollTimer);
            return;
        }

        try {
            const res = await fetch(`../api/check_paymongo_status.php?order_id=${encodeURIComponent(orderId)}`);
            const data = await res.json();

            if (data.success && data.paid) {
                clearInterval(payMongoPollTimer);
                payMongoPollTimer = null;
                const paidOrder = data.order || {};

                orderSubmitted = true;
                closeConfirmOrder(false); // Do not cancel in DB

                Swal.fire({
                    title: 'Payment Received!',
                    text: `PayMongo transaction confirmed for Order #${orderId}.`,
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false,
                    customClass: {
                        popup: 'swal-cafe-popup',
                        title: 'swal-cafe-title',
                        htmlContainer: 'swal-cafe-text'
                    }
                });

                showReceipt(
                    paidOrder.id || orderId,
                    paidOrder.total ? (paidOrder.total / 1.12) : pendingCheckout?.subtotal,
                    paidOrder.total ? (paidOrder.total - (paidOrder.total / 1.12)) : pendingCheckout?.vat,
                    paidOrder.total || pendingCheckout?.total,
                    paidOrder.items || pendingCheckout?.snapshot,
                    {
                        method: 'PayMongo QR (GCash/Maya/Card)',
                        reference: paidOrder.payment_reference || ('PM-' + orderId)
                    }
                );
            }
        } catch (err) {
            console.warn('PayMongo status poll error:', err);
        }
    };

    payMongoPollTimer = setInterval(checkStatus, 2500);
}

function checkPayMongoStatusManual() {
    if (!payMongoOrderId) {
        startPayMongoSession();
        return;
    }
    const statusText = document.getElementById('pm-status-text');
    if (statusText) statusText.textContent = 'Checking payment status...';

    fetch(`../api/check_paymongo_status.php?order_id=${encodeURIComponent(payMongoOrderId)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.paid) {
                if (payMongoPollTimer) clearInterval(payMongoPollTimer);
                orderSubmitted = true;
                closeConfirmOrder(false);
                const o = data.order || {};
                showReceipt(
                    o.id || payMongoOrderId,
                    o.total ? (o.total / 1.12) : pendingCheckout?.subtotal,
                    o.total ? (o.total - (o.total / 1.12)) : pendingCheckout?.vat,
                    o.total || pendingCheckout?.total,
                    o.items || pendingCheckout?.snapshot,
                    {
                        method: 'PayMongo QR',
                        reference: o.payment_reference || ('PM-' + payMongoOrderId)
                    }
                );
            } else {
                if (statusText) statusText.textContent = 'Payment not received yet. Waiting for customer...';
            }
        })
        .catch(() => {
            if (statusText) statusText.textContent = 'Network check failed. Re-trying...';
        });
}

function simulatePayMongoPayment() {
    if (!payMongoOrderId) return;
    const statusText = document.getElementById('pm-status-text');
    if (statusText) statusText.textContent = 'Simulating customer payment...';

    fetch(`../api/check_paymongo_status.php?order_id=${encodeURIComponent(payMongoOrderId)}&simulate=1`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.paid) {
                if (payMongoPollTimer) clearInterval(payMongoPollTimer);
                orderSubmitted = true;
                closeConfirmOrder(false);
                const o = data.order || {};
                showReceipt(
                    o.id || payMongoOrderId,
                    o.total ? (o.total / 1.12) : pendingCheckout?.subtotal,
                    o.total ? (o.total - (o.total / 1.12)) : pendingCheckout?.vat,
                    o.total || pendingCheckout?.total,
                    o.items || pendingCheckout?.snapshot,
                    {
                        method: 'PayMongo QR (Demo Simulated)',
                        reference: o.payment_reference || ('DEMO-' + payMongoOrderId)
                    }
                );
            } else {
                showSimpleError(data.error || 'Failed to simulate payment.');
            }
        })
        .catch(err => showSimpleError(err.message));
}

// ── Submit Confirmed Order ────────────────────
async function submitConfirmedOrder() {
    if (!pendingCheckout) return;

    if (selectedPaymentMethod === 'paymongo') {
        if (!payMongoOrderId) {
            await startPayMongoSession();
        } else {
            checkPayMongoStatusManual();
        }
        return;
    }

    const { subtotal, vat, total, snapshot, typeMap } = pendingCheckout;
    const btn = document.getElementById('confirm-order-btn');
    let tendered = null;
    let change = null;
    let paymentRef = '';

    if (selectedPaymentMethod === 'cash') {
        const inputVal = document.getElementById('cash-tendered')?.value || '';
        tendered = parseFloat(inputVal) || 0;
        if (tendered < total) {
            Swal.fire({
                title: 'Insufficient Cash',
                text: `Amount tendered (₱${tendered.toFixed(2)}) is less than the total (₱${total.toFixed(2)}).`,
                icon: 'warning',
                confirmButtonColor: '#C97B3D'
            });
            document.getElementById('cash-tendered')?.focus();
            return;
        }
        change = tendered - total;
    } else if (selectedPaymentMethod === 'ewallet') {
        const refVal = (document.getElementById('ewallet-ref')?.value || '').trim();
        if (!refVal) {
            Swal.fire({
                title: 'Reference Code Required',
                text: `Please enter the transaction reference number from the customer's ${currentWalletVendor} payment.`,
                icon: 'warning',
                confirmButtonColor: '#C97B3D'
            });
            document.getElementById('ewallet-ref')?.focus();
            return;
        }
        tendered = total;
        change = 0;
        paymentRef = `${currentWalletVendor} Ref: ${refVal}`;
    } else if (selectedPaymentMethod === 'card') {
        const approvalVal = (document.getElementById('card-approval')?.value || '').trim();
        if (!approvalVal) {
            Swal.fire({
                title: 'Approval Code Required',
                text: 'Please enter the terminal approval code from the POS slip.',
                icon: 'warning',
                confirmButtonColor: '#C97B3D'
            });
            document.getElementById('card-approval')?.focus();
            return;
        }
        tendered = total;
        change = 0;
        paymentRef = `Card Appr: ${approvalVal}`;
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Processing Order…';
    }

    const payload = {
        total,
        payment_method: selectedPaymentMethod,
        amount_tendered: tendered,
        change_amount: change,
        payment_reference: paymentRef,
        order_type: typeMap[orderType] || "Dine In",
        items: snapshot.map(o => ({
            id:    o.id,
            qty:   o.qty,
            price: o.price,
            size:  o.size,
            name:  o.name
        }))
    };

    try {
        const res = await fetch('../api/checkout.php', {
            method:  "POST",
            headers: { "Content-Type": "application/json" },
            body:    JSON.stringify(payload)
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('Server raw output:', text);
            throw new Error('Server returned an invalid response.');
        }

        if (!data.success) {
            if (btn) btn.disabled = false;
            showSimpleError(data.error ?? "Failed to complete order.");
            return;
        }

        orderSubmitted = true;
        closeConfirmOrder(false);

        showReceipt(data.order_id, subtotal, vat, total, snapshot, {
            method: selectedPaymentMethod === 'cash' ? 'Cash' : (selectedPaymentMethod === 'ewallet' ? currentWalletVendor : 'Card POS'),
            tendered,
            change,
            reference: paymentRef
        });

    } catch (err) {
        console.error('Checkout error:', err);
        showSimpleError(err.message || 'Error communicating with server.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function showSimpleError(message) {
    Swal.fire({
        title: "Notice",
        text: message,
        icon: "error",
        customClass: {
            popup: 'swal-cafe-popup',
            title: 'swal-cafe-title',
            htmlContainer: 'swal-cafe-text',
            confirmButton: 'swal-cafe-confirm',
            icon: 'swal-cafe-icon-error'
        },
        buttonsStyling: false
    });
}

// ── Receipt Modal ─────────────────────────────
function showReceipt(orderId, subtotal, vat, total, items, paymentInfo = {}) {
    const typeLabels = { dine: "Dine In", take: "Take Out", delivery: "Delivery" };

    const orderNumEl = document.getElementById('r-order-num');
    if (orderNumEl) orderNumEl.textContent = '#' + String(orderId).padStart(4, '0');

    const typeEl = document.getElementById('r-type');
    if (typeEl) typeEl.textContent = typeLabels[orderType] || 'Dine In';

    const now = new Date();
    const timeStr = now.toLocaleString('en-PH', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
    const dateEl = document.getElementById('r-time');
    if (dateEl) dateEl.textContent = timeStr;

    // Itemized receipt rows
    const itemsContainer = document.getElementById('r-items');
    if (itemsContainer) {
        itemsContainer.innerHTML = items.map(o => `
            <div class="receipt-item">
                <div class="ri-thumb">
                    <img src="${escapeHtml(o.imageSrc || DEFAULT_DRINK_IMAGE)}" alt="" onerror="this.onerror=null;this.src='${DEFAULT_DRINK_IMAGE}'"/>
                </div>
                <div class="ri-info">
                    <div class="ri-name">${escapeHtml(o.name)}</div>
                    <div class="ri-size">${o.size ? (o.size.charAt(0).toUpperCase() + o.size.slice(1)) : 'Regular'}</div>
                </div>
                <span class="ri-qty">×${o.qty}</span>
                <div class="ri-price">₱${(parseFloat(o.price) * o.qty).toFixed(2)}</div>
            </div>
        `).join('');
    }

    const subtotalEl = document.getElementById('r-subtotal');
    if (subtotalEl) subtotalEl.textContent = '₱' + parseFloat(subtotal).toFixed(2);

    const taxEl = document.getElementById('r-tax');
    if (taxEl) taxEl.textContent = '₱' + parseFloat(vat).toFixed(2);

    const totalEl = document.getElementById('r-total');
    if (totalEl) totalEl.textContent = '₱' + parseFloat(total).toFixed(2);

    // Payment details in receipt
    const pMethodEl   = document.getElementById('r-payment-method');
    const rowTendered = document.getElementById('r-row-tendered');
    const tenderedEl  = document.getElementById('r-tendered');
    const rowChange   = document.getElementById('r-row-change');
    const changeEl    = document.getElementById('r-change');
    const rowRef      = document.getElementById('r-row-ref');
    const refEl       = document.getElementById('r-ref');

    const method = paymentInfo.method || 'Cash';
    if (pMethodEl) pMethodEl.textContent = method;

    if (method.toLowerCase().includes('cash') && paymentInfo.tendered !== undefined && paymentInfo.tendered !== null) {
        if (rowTendered) rowTendered.style.display = 'flex';
        if (tenderedEl)  tenderedEl.textContent = '₱' + parseFloat(paymentInfo.tendered).toFixed(2);
        if (rowChange)   rowChange.style.display = 'flex';
        if (changeEl)    changeEl.textContent = '₱' + parseFloat(paymentInfo.change || 0).toFixed(2);
        if (rowRef)      rowRef.style.display = 'none';
    } else {
        if (rowTendered) rowTendered.style.display = 'none';
        if (rowChange)   rowChange.style.display = 'none';
        if (paymentInfo.reference) {
            if (rowRef) rowRef.style.display = 'flex';
            if (refEl)  refEl.textContent = paymentInfo.reference;
        } else {
            if (rowRef) rowRef.style.display = 'none';
        }
    }

    document.getElementById('receipt-overlay')?.classList.add('open');

    // Cart reset
    orderItems = [];
    renderOrder();
}

function closeReceipt() {
    document.getElementById('receipt-overlay')?.classList.remove('open');
}

function printReceipt() {
    window.print();
}

document.addEventListener('DOMContentLoaded', () => {
    // If redirected back from PayMongo browser payment (legacy or full-page fallback)
    const params = new URLSearchParams(window.location.search);
    const orderId = params.get('order_id');
    if (params.get('paymongo_success') === '1' && orderId) {
        fetch(`../api/check_paymongo_status.php?order_id=${encodeURIComponent(orderId)}`)
            .then(r => r.json())
            .then(res => {
                if (res.success && res.paid) {
                    orderSubmitted = true;
                    const o = res.order || {};
                    showReceipt(
                        o.id || orderId,
                        o.total ? (o.total / 1.12) : 0,
                        o.total ? (o.total - (o.total / 1.12)) : 0,
                        o.total || 0,
                        o.items || [],
                        {
                            method: 'PayMongo QR',
                            reference: o.payment_reference || ('PM-' + orderId)
                        }
                    );
                    // Clean URL query params without reloading
                    window.history.replaceState({}, document.title, window.location.pathname);
                } else {
                    showSimpleError('Payment is pending confirmation. Please check with customer.');
                }
            })
            .catch(() => showSimpleError('Unable to verify PayMongo payment status.'));
    }

    document.getElementById('receipt-overlay')?.addEventListener('click', function(e) {
        if (e.target === this) closeReceipt();
    });
    document.getElementById('confirm-overlay')?.addEventListener('click', function(e) {
        if (e.target === this) closeConfirmOrder();
    });
    document.getElementById('noitems-overlay')?.addEventListener('click', function(e) {
        if (e.target === this) closeNoItems();
    });

    const el = document.getElementById('receipt-overlay');
    if (el) document.body.appendChild(el);
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeReceipt();
        closeConfirmOrder();
        closeNoItems();
    }
});

// ── Window Exports ────────────────────────────
window.closeConfirmOrder          = closeConfirmOrder;
window.submitConfirmedOrder       = submitConfirmedOrder;
window.closeNoItems               = closeNoItems;
window.clearOrder                 = clearOrder;
window.checkout                   = checkout;
window.selectPaymentMethod        = selectPaymentMethod;
window.setWalletVendor            = setWalletVendor;
window.onTenderedInput            = onTenderedInput;
window.applyQuickBill             = applyQuickBill;
window.clearTendered              = clearTendered;
window.startPayMongoSession       = startPayMongoSession;
window.checkPayMongoStatusManual  = checkPayMongoStatusManual;
window.simulatePayMongoPayment    = simulatePayMongoPayment;
window.closeReceipt               = closeReceipt;
window.printReceipt               = printReceipt;
window.addToOrder                 = addToOrder;
window.switchCat                  = switchCat;
window.switchSize                 = switchSize;
window.switchOrderType            = switchOrderType;
window.filterProducts             = filterProducts;
window.changeQty                  = changeQty;
window.removeItem                 = removeItem;

