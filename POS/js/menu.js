console.log("menu.js loaded");
let menuData = {};

document.addEventListener("DOMContentLoaded", async () => {
    const res  = await fetch("get_menu.php");
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
            icon:       "🧋",
            priceSmall: parseFloat(item.price_small),
            priceLarge: parseFloat(item.price_large)
        });
    });

    renderGrid();
    renderOrder();
});

let currentCat  = "ice-coffee";
let currentSize = "small";
let orderItems  = [];
let orderType   = "dine";

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

// ── Menu Grid ─────────────────────────────────
function renderGrid() {
    const grid  = document.getElementById('menu-grid');
    const items = menuData[currentCat] || [];
    if (!grid) return;

    if (items.length === 0) {
        grid.innerHTML = `<div class="empty-cat">
            <div class="empty-icon">🫙</div>
            <p>No items in this category yet</p>
            <small>Add products in the menu manager</small>
        </div>`;
        return;
    }

    grid.innerHTML = items.map(item => {
        const price = currentSize === 'small' ? item.priceSmall : item.priceLarge;
        return `
        <div class="menu-card" onclick="addToOrder(${item.id})">
            <div class="item-img">${item.icon}</div>
            <div class="item-name">${item.name}</div>
            <div class="item-price">₱${parseFloat(price).toFixed(2)}</div>
        </div>`;
    }).join('');
}

// ── Order ─────────────────────────────────────
function addToOrder(itemId) {
    const item = menuData[currentCat].find(i => i.id == itemId);
    if (!item) { console.error("Item not found:", itemId); return; }

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

function updateTotals() {
    const total = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
    const el = id => document.getElementById(id);
    if (el('subtotal')) el('subtotal').textContent = '₱' + total.toFixed(2);
    if (el('total'))    el('total').textContent    = '₱' + total.toFixed(2);
}

function clearOrder() {
    orderItems = [];
    renderOrder();
}

// ── Checkout ──────────────────────────────────
//ALERTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTT
function checkout() {
    if (orderItems.length === 0) {
        Swal.fire({
            title: "No Items!",
            text: "Please add items first.",
            icon: "warning",
            confirmButtonText: "OK"
        });
        return;
    }

    const total = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
    const typeMap = { dine: "Dine In", take: "Take Out", delivery: "Delivery" };

    const payload = {
        total,
        payment_method: typeMap[orderType] || "Dine In",
        items: orderItems.map(o => ({
            id: o.id,
            qty: o.qty,
            price: o.price,
            size: o.size
        }))
    };

    fetch('checkout.php', {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
    if (!res.success) {
        Swal.fire({
            title: "Error!",
            text: res.error ?? "Unknown error",
            icon: "error"
        });
        return;
    }


  
    const completedOrder = [...orderItems];

    orderItems = [];
    renderOrder();


    Swal.fire({
        title: "Order Complete!",
        text: "Order #" + res.order_id + " has been saved.",
        icon: "success",
        confirmButtonText: "X Close"
    }).then(() => {
        showReceipt(res.order_id, total, completedOrder);
    });

        
    })
    .catch(err => {
        Swal.fire({
            title: "Request Failed!",
            text: err.message,
            icon: "error"
        });
    });
}
// ── Receipt Modal ─────────────────────────────
function showReceipt(orderId, total) {

    const receiptItems = [...orderItems];

    const typeLabels = {
        dine: "🍽️ Dine In",
        take: "🛍️ Take Out",
        delivery: "🚗 Delivery"
    };

    document.getElementById('r-order-num').textContent =
        '#' + String(orderId).padStart(4, '0');

    document.getElementById('r-type').textContent =
        typeLabels[orderType] || '🍽️ Dine In';


    document.getElementById('r-items').innerHTML = receiptItems.map(o => `
        <tr>
            <td>🧋 ${o.name} (${o.size})</td>
            <td style="text-align:center">×${o.qty}</td>
            <td style="text-align:right">
                ₱${(o.price * o.qty).toFixed(2)}
            </td>
        </tr>
    `).join('');


    document.getElementById('r-subtotal').textContent =
        '₱' + total.toFixed(2);

    document.getElementById('r-total').textContent =
        '₱' + total.toFixed(2);


    updateReceiptStatus("Pending");


    
    document.getElementById('receipt-overlay')
        .classList.add('open');


   
    orderItems = [];
    renderOrder();
}

function closeReceipt() {
    document.getElementById('receipt-overlay').classList.remove('open');
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('receipt-overlay')?.addEventListener('click', function(e) {
        if (e.target === this) closeReceipt();
    });
});

function printReceipt() {
    window.print();
}


document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('receipt-overlay');
    if (el) document.body.appendChild(el);
});

function updateReceiptStatus(status){

    const badge = document.getElementById("r-status");

    badge.textContent = status;

    badge.className = 
        "r-status-badge status-" + status.toLowerCase();

}


// ── Exports ───────────────────────────────────
window.clearOrder      = clearOrder;
window.checkout        = checkout;
window.closeReceipt    = closeReceipt;
window.printReceipt    = printReceipt;
window.addToOrder      = addToOrder;
window.switchCat       = switchCat;
window.switchSize      = switchSize;
window.switchOrderType = switchOrderType;
window.changeQty       = changeQty;
window.removeItem      = removeItem;