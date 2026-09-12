<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.pending');

$user = current_user();

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pending Orders — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/pending_orders.css"/>
</head>
<body>

<div id="page-pending" class="page active">
  <div class="page-header">
    <div>
      <h1>Pending Orders</h1>
      <p>Manage and update order statuses</p>
    </div>
    <div class="pending-header-right">
      <button class="refresh-btn" onclick="loadOrders()"><span class="refresh-ic">⟳</span> Refresh</button>
      <div class="pending-meta" id="pending-meta">Loading…</div>
    </div>
  </div>

  <div class="filter-bar">
    <button class="filter-btn active" onclick="setFilter('all', this)">All</button>
    <button class="filter-btn" onclick="setFilter('pending', this)">Pending <span class="dot"></span></button>
    <button class="filter-btn" onclick="setFilter('completed', this)">Completed</button>
    <button class="filter-btn" onclick="setFilter('cancelled', this)">Cancelled</button>
  </div>

  <div class="orders-grid" id="orders-grid">
    <div class="loading-wrap">Loading orders…</div>
  </div>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirm-overlay">
  <div class="confirm-card">
    <div class="confirm-icon" id="conf-icon">❓</div>
    <h3 id="conf-title">Confirm Action</h3>
    <p id="conf-message">Are you sure?</p>
    <div class="confirm-actions">
      <button class="btn-conf-cancel" onclick="closeConfirm()">Cancel</button>
      <button class="btn-conf-ok" id="conf-ok" onclick="confirmAction()">Confirm</button>
    </div>
  </div>
</div>

<script>
let allOrders     = [];
let currentFilter = 'all';
let pendingAction = null; // { orderId, status }
let lastLoadTime  = null;

// Any single line item at/above this quantity flags the order as a
// "large order" (amber highlight) — tweak to taste.
const LARGE_ORDER_QTY = 15;

// ── Load orders ────────────────────────────────
async function loadOrders() {
  document.getElementById('orders-grid').innerHTML =
    '<div class="loading-wrap">Loading orders…</div>';
  try {
    const res  = await fetch('../api/get_orders.php');
    const data = await res.json();
    allOrders  = data;
    lastLoadTime = Date.now();
    renderOrders();
    updateMeta();
  } catch (e) {
    document.getElementById('orders-grid').innerHTML =
      '<div class="loading-wrap">⚠️ Failed to load orders</div>';
  }
}

// ── Filter ─────────────────────────────────────
function setFilter(f, el) {
  currentFilter = f;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  renderOrders();
}

// ── Header meta ("N pending · updated Xs ago") ──
function updateMeta() {
  const el = document.getElementById('pending-meta');
  if (!el) return;
  const pendingCount = allOrders.filter(o => o.status === 'pending').length;

  let ago = 'just now';
  if (lastLoadTime) {
    const diff = Math.floor((Date.now() - lastLoadTime) / 1000);
    if (diff < 5)          ago = 'just now';
    else if (diff < 60)    ago = diff + 's ago';
    else if (diff < 3600)  ago = Math.floor(diff / 60) + ' min ago';
    else                   ago = Math.floor(diff / 3600) + ' hr ago';
  }
  el.textContent = `${pendingCount} pending · updated ${ago}`;
}
setInterval(updateMeta, 15000);

// ── Helpers ─────────────────────────────────────
function parseItems(itemsStr) {
  if (!itemsStr) return [];
  return itemsStr.split(', ').map(i => {
    const idx = i.lastIndexOf(' x');
    const name = idx > -1 ? i.slice(0, idx) : i;
    const qty  = idx > -1 ? parseInt(i.slice(idx + 2), 10) || 1 : 1;
    return { name, qty };
  });
}

function isLargeOrder(items) {
  return items.some(i => i.qty >= LARGE_ORDER_QTY);
}

// ── Render ─────────────────────────────────────
function renderOrders() {
  const grid = document.getElementById('orders-grid');
  const filtered = currentFilter === 'all'
    ? allOrders
    : allOrders.filter(o => o.status === currentFilter);

  if (!filtered.length) {
    grid.innerHTML = `<div class="empty-state">
      <div class="empty-icon">🫙</div>
      <p>No orders found</p>
      <small>Try a different filter</small>
    </div>`;
    return;
  }

  const typeIcon = { 'Dine In': '🍽️', 'Take Out': '🥡', 'Delivery': '🚗' };

  grid.innerHTML = filtered.map(o => {
    const parsed = parseItems(o.items);
    const large  = isLargeOrder(parsed);

    const itemsHtml = parsed.length
      ? parsed.map(i => `<div class="card-item-row"><span>${i.name}</span><span>x${i.qty}</span></div>`).join('')
      : '<div class="card-item-row"><span>No items</span></div>';

    const time = new Date(o.created_at).toLocaleString('en-PH', {
      month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });

    const icon = typeIcon[o.payment_method] || '📋';
    const s = o.status || 'pending';

    const actions = s === 'pending' ? `
      <div class="card-actions">
        <button class="action-btn btn-complete" onclick="askConfirm(${o.id}, 'completed')">✓ Done</button>
        <button class="action-btn btn-cancel" onclick="askConfirm(${o.id}, 'cancelled')">✕ Cancel</button>
      </div>` : '';

    return `
    <div class="order-card ${large ? 'large-order' : ''}" id="card-${o.id}">
      <div class="card-head">
        <span class="card-order-num">#${String(o.id).padStart(4,'0')}</span>
        <span class="status-badge status-${s}">${statusLabel(s)}</span>
      </div>
      <div class="card-type">${icon} ${o.payment_method || 'Dine In'}${large ? '<span class="large-tag"> · large order</span>' : ''}</div>
      <div class="card-items">${itemsHtml}</div>
      <div class="card-total-row">
        <span>Total</span>
        <span>${time}</span>
      </div>
      <div class="card-total-amount">₱${parseFloat(o.total_amount).toFixed(0)}</div>
      ${actions}
    </div>`;
  }).join('');
}

function statusLabel(s) {
  return s === 'pending' ? 'Pending'
       : s === 'completed' ? 'Done'
       : 'Cancelled';
}

// ── Confirm Modal ──────────────────────────────
const configs = {
  pending:   { icon:'⏳', title:'Mark as Pending',   msg:'Move this order back to pending?',        cls:'ok-pending',  label:'Mark Pending'   },
  completed: { icon:'✅', title:'Complete Order',     msg:'Mark this order as completed?',           cls:'ok-complete', label:'Complete'        },
  cancelled: { icon:'❌', title:'Cancel Order',       msg:'Are you sure you want to cancel this order? This can be undone.', cls:'ok-cancel', label:'Yes, Cancel' },
};

function askConfirm(orderId, status) {
  pendingAction = { orderId, status };
  const c = configs[status];
  document.getElementById('conf-icon').textContent    = c.icon;
  document.getElementById('conf-title').textContent   = c.title;
  document.getElementById('conf-message').textContent = c.msg;
  const ok = document.getElementById('conf-ok');
  ok.className = 'btn-conf-ok ' + c.cls;
  ok.textContent = c.label;
  document.getElementById('confirm-overlay').classList.add('open');
}

function closeConfirm() {
  document.getElementById('confirm-overlay').classList.remove('open');
  pendingAction = null;
}

async function confirmAction() {
  if (!pendingAction) return;
  const { orderId, status } = pendingAction;
  closeConfirm();

  try {
    const res  = await fetch('../api/updated_order_status.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ order_id: orderId, status })
    });
    const data = await res.json();
    if (data.success) {
      // Update locally without full reload
      const order = allOrders.find(o => o.id == orderId);
      if (order) order.status = status;
      renderOrders();
      updateMeta();
    } else {
      alert('Error: ' + (data.error || 'Could not update order'));
    }
  } catch (e) {
    alert('Request failed: ' + e.message);
  }
}

// Close modal on backdrop click
document.getElementById('confirm-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeConfirm();
});

loadOrders();
</script>

</body>
</html>
