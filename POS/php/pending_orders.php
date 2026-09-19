<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.pending');

$user = current_user();
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

<?php include("../includes/sidebar.php"); ?>

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
    <div class="confirm-icon" id="conf-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
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
      '<div class="loading-wrap"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:6px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Failed to load orders</div>';
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
      <div class="empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></div>
      <p>No orders found</p>
      <small>Try a different filter</small>
    </div>`;
    return;
  }

  const typeIcon = {
    'Dine In': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle"><path d="M18 2v20M21 2v6a3 3 0 0 1-3 3M14 2v6a3 3 0 0 0 3 3M3 2v7c0 1.1.9 2 2 2h2c1.1 0 2-.9 2-2V2M6 11v11"/></svg>',
    'Take Out': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
    'Delivery': '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle"><rect x="1" y="7" width="22" height="11" rx="2"/><path d="M5 7l2-4h10l2 4"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>'
  };
  const defaultTypeIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>';

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

    const icon = typeIcon[o.payment_method] || defaultTypeIcon;
    const s = o.status || 'pending';

    const actions = s === 'pending' ? `
      <div class="card-actions">
        <button class="action-btn btn-complete" onclick="askConfirm(${o.id}, 'completed')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle"><polyline points="20 6 9 17 4 12"/></svg> Done</button>
        <button class="action-btn btn-cancel" onclick="askConfirm(${o.id}, 'cancelled')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Cancel</button>
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
  pending:   { icon:'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>', title:'Mark as Pending',   msg:'Move this order back to pending?', cls:'ok-pending', label:'Mark Pending' },
  completed: { icon:'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>', title:'Complete Order', msg:'Mark this order as completed?', cls:'ok-complete', label:'Complete' },
  cancelled: { icon:'<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>', title:'Cancel Order', msg:'Are you sure you want to cancel this order? This can be undone.', cls:'ok-cancel', label:'Yes, Cancel' },
};

function askConfirm(orderId, status) {
  pendingAction = { orderId, status };
  const c = configs[status];
  document.getElementById('conf-icon').innerHTML      = c.icon;
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
