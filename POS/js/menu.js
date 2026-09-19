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
// ── Checkout ──────────────────────────────────
let pendingCheckout = null;
let selectedPaymentMethod = 'cash';

function selectPaymentMethod(method) {
    selectedPaymentMethod = method === 'paymongo' ? 'paymongo' : 'cash';
    const button = document.getElementById('confirm-order-btn');
    if (button) {
        button.innerHTML = selectedPaymentMethod === 'paymongo'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Continue to PayMongo'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Confirm & Place Order';
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

    const itemsListHtml = orderItems.map(o => `
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0">
            <span>${o.name} (${o.size}) ×${o.qty}</span>
            <span>₱${(o.price * o.qty).toFixed(2)}</span>
        </div>
    `).join('');

    document.getElementById('confirm-items-list').innerHTML = itemsListHtml;
    document.getElementById('confirm-total').textContent    = '₱' + total.toFixed(2);
    document.getElementById('confirm-type').textContent     = typeMap[orderType] || "Dine In";

    document.getElementById('confirm-order-btn').disabled = false;
    document.querySelector('input[name="checkout-payment"][value="cash"]').checked = true;
    selectPaymentMethod('cash');

    document.getElementById('confirm-overlay').classList.add('open');
}

function closeNoItems() {
    document.getElementById('noitems-overlay').classList.remove('open');
}

function closeConfirmOrder() {
    document.getElementById('confirm-overlay').classList.remove('open');
    pendingCheckout = null;
}

function submitConfirmedOrder() {
    if (!pendingCheckout) return;

    const { subtotal, vat, total, snapshot, typeMap } = pendingCheckout;
    const btn = document.getElementById('confirm-order-btn');
    btn.disabled = true;
    btn.textContent = selectedPaymentMethod === 'paymongo' ? 'Opening PayMongo…' : 'Placing order…';

    const payload = {
        total,
        payment_method: selectedPaymentMethod,
        order_type: typeMap[orderType] || "Dine In",
        items: snapshot.map(o => ({
            id:    o.id,
            qty:   o.qty,
            price: o.price,
            size:  o.size,
            name:  o.name
        }))
    };

    const endpoint = selectedPaymentMethod === 'paymongo'
        ? '../api/create_paymongo_checkout.php'
        : '../api/checkout.php';
    if (selectedPaymentMethod === 'paymongo') {
        sessionStorage.setItem('paymongo_pending', JSON.stringify({ subtotal, vat, total, snapshot, orderType }));
    }

    fetch(endpoint, {
        method:  "POST",
        headers: { "Content-Type": "application/json" },
        body:    JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            sessionStorage.removeItem('paymongo_pending');
            btn.disabled = false;
            selectPaymentMethod(selectedPaymentMethod);
            showSimpleError(res.error ?? "Unknown error");
            return;
        }

        if (selectedPaymentMethod === 'paymongo') {
            window.location.href = res.checkout_url;
            return;
        }

        closeConfirmOrder();
        orderSubmitted = true;
        showReceipt(res.order_id, subtotal, vat, total, snapshot);
    })
    .catch(err => {
        sessionStorage.removeItem('paymongo_pending');
        closeConfirmOrder();
        showSimpleError(err.message);
    });
}

function showSimpleError(message) {
    document.getElementById('confirm-total').textContent; // no-op safeguard
    Swal.fire({
        title: "Error!",
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
function showReceipt(orderId, subtotal, vat, total, items) {
    const typeLabels = { dine: "Dine In", take: "Take Out", delivery: "Delivery" };

    document.getElementById('r-order-num').textContent =
        '#' + String(orderId).padStart(4, '0');

    document.getElementById('r-type').textContent =
        typeLabels[orderType] || 'Dine In';

    const now = new Date();
    const timeStr = now.toLocaleString('en-PH', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
    const dateEl = document.getElementById('r-time');
    if (dateEl) dateEl.textContent = timeStr;

    document.getElementById('r-items').innerHTML = items.map(o => `
        <div class="receipt-item">
            <div class="ri-thumb">
                <img src="${escapeHtml(o.imageSrc || DEFAULT_DRINK_IMAGE)}" alt="" onerror="this.onerror=null;this.src='${DEFAULT_DRINK_IMAGE}'"/>
            </div>
            <div class="ri-info">
                <div class="ri-name">${escapeHtml(o.name)}</div>
                <div class="ri-size">${o.size.charAt(0).toUpperCase() + o.size.slice(1)}</div>
            </div>
            <span class="ri-qty">×${o.qty}</span>
            <div class="ri-price">₱${(o.price * o.qty).toFixed(2)}</div>
        </div>
    `).join('');

    document.getElementById('r-subtotal').textContent = '₱' + subtotal.toFixed(2);
    if (document.getElementById('r-tax'))
        document.getElementById('r-tax').textContent  = '₱' + vat.toFixed(2);
    document.getElementById('r-total').textContent    = '₱' + total.toFixed(2);

    document.getElementById('receipt-overlay').classList.add('open');

    orderItems = [];
    renderOrder();
}

function closeReceipt() {
    document.getElementById('receipt-overlay').classList.remove('open');
}

function printReceipt() {
    window.print();
}

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const orderId = params.get('order_id');
    const pending = sessionStorage.getItem('paymongo_pending');
    if (params.get('paymongo_success') === '1' && orderId && pending) {
        const receipt = JSON.parse(pending);
        sessionStorage.removeItem('paymongo_pending');
        fetch(`../api/check_paymongo_status.php?order_id=${encodeURIComponent(orderId)}`)
            .then(r => r.json())
            .then(res => {
                if (res.success && res.paid) {
                    orderSubmitted = true;
                    showReceipt(res.order_id, receipt.subtotal, receipt.vat, receipt.total, receipt.snapshot);
                } else {
                    showSimpleError('PayMongo has not confirmed this payment yet.');
                }
            })
            .catch(() => showSimpleError('Unable to verify the PayMongo payment.'));
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
    if (e.key === 'Escape') { closeReceipt(); closeConfirmOrder(); closeNoItems(); }
});


// ── Exports ───────────────────────────────────
window.closeConfirmOrder    = closeConfirmOrder;
window.submitConfirmedOrder = submitConfirmedOrder;
window.closeNoItems         = closeNoItems;
window.clearOrder      = clearOrder;
window.checkout        = checkout;
window.selectPaymentMethod = selectPaymentMethod;
window.closeReceipt    = closeReceipt;
window.printReceipt    = printReceipt;
window.addToOrder      = addToOrder;
window.switchCat       = switchCat;
window.switchSize      = switchSize;
window.switchOrderType = switchOrderType;
window.filterProducts  = filterProducts;
window.changeQty       = changeQty;
window.removeItem      = removeItem;
