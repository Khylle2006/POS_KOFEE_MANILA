console.log("menu.js loaded");
let menuData = {};

document.addEventListener("DOMContentLoaded", async () => {

    const res = await fetch("../admin/get_menu.php");
    const data = await res.json();

    menuData = {
        "ice-coffee": [],
        "hot-coffee": [],
        "milk-tea": [],
        "fruit-tea": []
    };

    data.forEach(item => {
        const key = item.category_name.toLowerCase().replace(" ", "-");

        if (!menuData[key]) menuData[key] = [];

        menuData[key].push({
            id: item.id,
            name: item.name,
            icon: "☕",
            priceSmall: item.price_small,
            priceLarge: item.price_large
        });
    });

    renderGrid();
    renderOrder();
});

let currentCat = "ice-coffee";
let currentSize = "small";
let orderItems = [];
let orderType = "dine";


document.addEventListener("DOMContentLoaded", () => {
    renderGrid();
    renderOrder();
});


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


function renderGrid() {
    const grid = document.getElementById('menu-grid');
    const items = menuData[currentCat] || [];

    if (!grid) return;

    grid.innerHTML = items.map(item => {
        const price = currentSize === 'small'
            ? item.priceSmall
            : item.priceLarge;

        return `
        <div class="menu-card" onclick="addToOrder(${item.id})">
            <div class="item-img">${item.icon}</div>
            <div class="item-name">${item.name}</div>
            <div class="item-price">₱${price}</div>
        </div>`;
    }).join('');
}


function addToOrder(itemId) {

    console.log("CLICKED:", itemId);

    const item = menuData[currentCat].find(i => i.id == itemId);

    if (!item) {
        console.error("NOT FOUND:", itemId);
        return;
    }

    const price = currentSize === 'small'
        ? item.priceSmall
        : item.priceLarge;

    const key = itemId + '_' + currentSize;

    const existing = orderItems.find(o => o.key === key);

    if (existing) {
        existing.qty++;
    } else {
        orderItems.push({
            key,
            id: itemId,
            name: item.name,
            icon: item.icon,
            size: currentSize,
            price,
            qty: 1
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
        </div>`;

        updateTotals(); 
        return;
    }

    container.innerHTML = orderItems.map(o => `
        <div class="order-item-row">
            <div>${o.icon}</div>
            <div>${o.name}</div>
            <div>${o.qty}</div>
            <div>₱${(o.price * o.qty).toFixed(2)}</div>
        </div>
    `).join('');

    updateTotals(); 
}

function updateTotals() {
    const sub = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
    const tax = sub * 0.12;

    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax');
    const totalEl = document.getElementById('total');

    if (subtotalEl) subtotalEl.textContent = '₱' + sub.toFixed(2);
    if (taxEl) taxEl.textContent = '₱' + tax.toFixed(2);
    if (totalEl) totalEl.textContent = '₱' + (sub + tax).toFixed(2);
}

function clearOrder() {
    orderItems = [];
    renderOrder();
    updateTotals();
}

function checkout() {

    if (orderItems.length === 0) {
        alert("Add items first");
        return;
    }

    const sub = orderItems.reduce((s, o) => s + o.price * o.qty, 0);

    const typeMap = {
        dine: "Dine In",
        take: "Take Out",
        delivery: "Delivery"
    };

    const payload = {
        total: sub,
        payment_method: typeMap[orderType] || "Dine In",
        items: orderItems
    };

    fetch('http://localhost/POS_KOFEE_MANILA-main%20(2)/POS_KOFEE_MANILA-main/POS/admin/checkout.php', {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {

        if (!res.success) {
            console.error(res);
            alert("Save error");
            return;
        }

        alert("Order saved: #" + res.order_id);

        orderItems = [];
        renderOrder();
        updateTotals();
    })
    .catch(err => {
        console.error("REQUEST FAILED:", err);
        alert("Request failed");
    });
}



window.clearOrder = clearOrder;
window.checkout = checkout;
window.addToOrder = addToOrder;
window.switchCat = switchCat;
window.switchSize = switchSize;