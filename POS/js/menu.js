console.log("menu.js loaded");
let menuData = {};

// Best-guess icon per product: match on name keywords first, then
// fall back to a sensible default for the category. Keeps the grid
// from showing the same bubble-tea emoji on every single card.
const CATEGORY_ICON = {
    "ice-coffee": "🧊",
    "hot-coffee": "☕",
    "milk-tea":   "🧋",
    "fruit-tea":  "🍹"
};
const NAME_ICON_RULES = [
    [/mocha|latte|cappuccino|espresso|americano|macchiato/i, "☕"],
    [/choco/i,   "🍫"],
    [/vanilla|caramel|custard|flan/i, "🍮"],
    [/matcha/i,  "🍵"],
    [/straw|berry/i, "🍓"],
    [/mango|pineapple|fruit/i, "🍹"],
    [/pearl|milk ?tea|taro/i, "🧋"],
    [/lemon|citrus/i, "🍋"]
];
function guessIcon(name, categoryKey) {
    const hit = NAME_ICON_RULES.find(([re]) => re.test(name));
    return hit ? hit[1] : (CATEGORY_ICON[categoryKey] || "🧋");
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
            icon:       guessIcon(item.name, key),
<<<<<<< HEAD
<<<<<<< HEAD
=======
            image:      item.image_path ? `../assets/${item.image_path}` : `../assets/menu/${item.id}.jpg`,
            imageFallback: `../assets/${item.id}.jpg`,
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
=======
            image:      item.image_path ? `../assets/${item.image_path}` : `../assets/menu/${item.id}.jpg`,
            imageFallback: `../assets/${item.id}.jpg`,
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
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
            <div class="empty-icon">🔍</div>
            <p>No drinks match "${escapeHtml(searchTerm)}"</p>
            <small>Try a different name or check another category</small>
        </div>` : `<div class="empty-cat">
            <div class="empty-icon">🫙</div>
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
<<<<<<< HEAD
<<<<<<< HEAD
            <div class="item-img"><span>${item.icon}</span></div>
=======
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
            <div class="item-img">
                <img src="${item.image}" alt="${escapeHtml(item.name)}" onerror="if (!this.dataset.fallback) { this.dataset.fallback='true'; this.src='${item.imageFallback}'; } else { this.hidden=true; this.nextElementSibling.hidden=false; }">
                <span hidden>${item.icon}</span>
            </div>
<<<<<<< HEAD
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
=======
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
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

// ── Order ─────────────────────────────────────
function addToOrder(itemId) {
    const item = Object.values(menuData).flat().find(i => i.id == itemId);
    if (!item) { console.error("Item not found:", itemId); return; }
    if (item.stock <= 0) return;

    const price    = currentSize === 'small' ? item.priceSmall : item.priceLarge;
    const key      = itemId + '_' + currentSize;
    const existing = orderItems.find(o => o.key === key);

    if (existing) {
        existing.qty++;
    } else {
        orderItems.push({
            key,
            id:    itemId,
            name:  item.name,
            icon:  item.icon,
            size:  currentSize,
            price: parseFloat(price),
            qty:   1
        });
    }
    renderOrder();
}

function renderOrder() {
    const container = document.getElementById('order-items');
    if (!container) return;

    if (orderItems.length === 0) {
        container.innerHTML = `
        <div class="order-empty">
            <div class="oe-icon">🧋</div>
            <p>No items yet</p>
            <small>Tap a drink to add it</small>
        </div>`;
        updateTotals();
        return;
    }

    container.innerHTML = orderItems.map((o, i) => `
        <div class="order-item-row">
            <div class="oi-icon">${o.icon}</div>
            <div class="oi-info">
                <div class="oi-name">${o.name}</div>
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

function changeQty(index, delta) {
    orderItems[index].qty += delta;
    if (orderItems[index].qty <= 0) orderItems.splice(index, 1);
    renderOrder();
}

function removeItem(index) {
    orderItems.splice(index, 1);
    renderOrder();
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

function clearOrder() {
    orderItems = [];
    renderOrder();
}

// ── Checkout ──────────────────────────────────
// ── Checkout ──────────────────────────────────
let pendingCheckout = null;

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
    document.getElementById('confirm-order-btn').textContent = '✅ Confirm & Place Order';

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
    btn.textContent = 'Placing order…';

    const payload = {
        total,
        payment_method: typeMap[orderType] || "Dine In",
        items: snapshot.map(o => ({
            id:    o.id,
            qty:   o.qty,
            price: o.price,
            size:  o.size
        }))
    };

    fetch('../api/checkout.php', {
        method:  "POST",
        headers: { "Content-Type": "application/json" },
        body:    JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        closeConfirmOrder();

        if (!res.success) {
            showSimpleError(res.error ?? "Unknown error");
            return;
        }

        showReceipt(res.order_id, subtotal, vat, total, snapshot);
    })
    .catch(err => {
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
    const typeLabels = { dine: "🍽️ Dine In", take: "🛍️ Take Out", delivery: "🚗 Delivery" };

    document.getElementById('r-order-num').textContent =
        '#' + String(orderId).padStart(4, '0');

    document.getElementById('r-type').textContent =
        typeLabels[orderType] || '🍽️ Dine In';

    const now = new Date();
    const timeStr = now.toLocaleString('en-PH', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', hour12: true
    });
    const dateEl = document.getElementById('r-time');
    if (dateEl) dateEl.textContent = timeStr;

    document.getElementById('r-items').innerHTML = items.map(o => `
        <div class="receipt-item">
            <div class="ri-icon">${o.icon}</div>
            <div class="ri-info">
                <div class="ri-name">${o.name}</div>
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
window.closeReceipt    = closeReceipt;
window.printReceipt    = printReceipt;
window.addToOrder      = addToOrder;
window.switchCat       = switchCat;
window.switchSize      = switchSize;
window.switchOrderType = switchOrderType;
window.filterProducts  = filterProducts;
window.changeQty       = changeQty;
window.removeItem      = removeItem;
