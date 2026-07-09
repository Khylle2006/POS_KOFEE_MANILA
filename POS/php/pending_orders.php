<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$user = current_user();
$sidebar = $user['role'] === 'admin' ? 'admin_sidebar' : 'staff_sidebar';
include("../includes/$sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pending Orders — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: #fdf6ee; color: #2c1a0e; }

    #page-pending { padding: 32px 32px 32px 280px; min-height: 100vh; }

    .page-header { margin-bottom: 24px; }
    .page-header h1 { font-size: 24px; font-weight: 800; color: #2c1a0e; }
    .page-header p  { font-size: 13px; color: #9a7e65; margin-top: 3px; }

    /* ── Filter bar ── */
    .filter-bar {
      display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;
    }
    .filter-btn {
      padding: 7px 18px; border-radius: 20px; border: 1.5px solid #ecddc8;
      background: #fff; font-family: 'Poppins', sans-serif;
      font-size: 12px; font-weight: 600; color: #9a7e65;
      cursor: pointer; transition: all .15s;
    }
    .filter-btn.active, .filter-btn:hover {
      background: #c47d3e; border-color: #c47d3e; color: #fff;
    }
    .refresh-btn {
      margin-left: auto; padding: 7px 18px; border-radius: 20px;
      border: 1.5px solid #ecddc8; background: #fff;
      font-family: 'Poppins', sans-serif; font-size: 12px;
      font-weight: 600; color: #9a7e65; cursor: pointer; transition: all .15s;
    }
    .refresh-btn:hover { border-color: #c47d3e; color: #c47d3e; }

    /* ── Orders grid ── */
    .orders-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
      gap: 16px;
    }

    /* ── Order card ── */
    .order-card {
      background: #fff;
      border-radius: 16px;
      border: 1.5px solid #ecddc8;
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(196,125,62,.07);
      transition: box-shadow .2s;
    }
    .order-card:hover { box-shadow: 0 6px 24px rgba(196,125,62,.13); }

    .card-head {
      padding: 14px 16px 10px;
      display: flex; justify-content: space-between; align-items: center;
      border-bottom: 1px solid #f5ede0;
    }
    .card-order-num { font-size: 15px; font-weight: 800; color: #2c1a0e; }
    .card-time      { font-size: 11px; color: #9a7e65; }

    .status-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 10px; border-radius: 20px;
      font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    }
    .status-pending   { background: #fff3cd; color: #856404; }
    .status-completed { background: #d4edda; color: #155724; }
    .status-cancelled { background: #f8d7da; color: #721c24; }

    .card-type {
      font-size: 11px; color: #9a7e65; padding: 8px 16px 0;
      font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    }

    .card-items { padding: 8px 16px 12px; }
    .card-item-row {
      display: flex; justify-content: space-between;
      font-size: 13px; padding: 4px 0;
      border-bottom: 1px dashed #f5ede0;
      color: #4a3020;
    }
    .card-item-row:last-child { border: none; }
    .card-item-row span:last-child { color: #c47d3e; font-weight: 700; }

    .card-total {
      display: flex; justify-content: space-between; align-items: center;
      padding: 10px 16px; border-top: 1.5px solid #ecddc8;
      font-size: 14px; font-weight: 700; color: #2c1a0e;
    }
    .card-total span:last-child { color: #c47d3e; font-size: 16px; }

    .card-actions {
      padding: 10px 16px 14px;
      display: flex; gap: 8px;
    }
    .action-btn {
      flex: 1; padding: 8px 6px; border-radius: 10px;
      border: 1.5px solid #ecddc8; background: #fff;
      font-family: 'Poppins', sans-serif; font-size: 11px; font-weight: 700;
      cursor: pointer; transition: all .15s; text-transform: uppercase; letter-spacing: .04em;
    }
    .action-btn:disabled { opacity: .35; cursor: not-allowed; }
    .btn-complete { border-color: #c3e6cb; color: #155724; }
    .btn-complete:not(:disabled):hover { background: #155724; color: #fff; border-color: #155724; }
    .btn-pending  { border-color: #ffeeba; color: #856404; }
    .btn-pending:not(:disabled):hover  { background: #856404; color: #fff; border-color: #856404; }
    .btn-cancel   { border-color: #f5c6cb; color: #721c24; }
    .btn-cancel:not(:disabled):hover   { background: #721c24; color: #fff; border-color: #721c24; }

    /* ── Empty state ── */
    .empty-state {
      grid-column: 1/-1; text-align: center;
      padding: 64px 20px; color: #9a7e65;
    }
    .empty-icon { font-size: 48px; margin-bottom: 12px; }
    .empty-state p { font-size: 15px; font-weight: 600; }
    .empty-state small { font-size: 12px; }

    /* ── Confirm Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(44,26,14,.45); z-index: 99999;
      backdrop-filter: blur(3px);
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }

    .confirm-card {
      background: #fff; border-radius: 20px; padding: 32px 28px;
      width: 100%; max-width: 360px; margin: 20px;
      box-shadow: 0 24px 64px rgba(44,26,14,.22);
      animation: popIn .2s cubic-bezier(.34,1.56,.64,1);
      text-align: center;
    }
    @keyframes popIn {
      from { opacity:0; transform:scale(.9); }
      to   { opacity:1; transform:scale(1); }
    }
    .confirm-icon { font-size: 42px; margin-bottom: 12px; }
    .confirm-card h3 { font-size: 18px; font-weight: 800; color: #2c1a0e; margin-bottom: 6px; }
    .confirm-card p  { font-size: 13px; color: #9a7e65; margin-bottom: 24px; line-height: 1.5; }
    .confirm-actions { display: flex; gap: 10px; }
    .btn-conf-cancel {
      flex: 1; padding: 11px; border-radius: 12px;
      border: 1.5px solid #ecddc8; background: #fff;
      font-family: 'Poppins', sans-serif; font-size: 13px;
      font-weight: 600; color: #9a7e65; cursor: pointer;
    }
    .btn-conf-ok {
      flex: 2; padding: 11px; border-radius: 12px; border: none;
      font-family: 'Poppins', sans-serif; font-size: 13px;
      font-weight: 700; color: #fff; cursor: pointer;
    }
    .btn-conf-ok.ok-complete  { background: #2e7d32; }
    .btn-conf-ok.ok-pending   { background: #856404; }
    .btn-conf-ok.ok-cancel    { background: #c62828; }

    /* loading spinner */
    .loading-wrap { grid-column:1/-1; text-align:center; padding:60px; color:#9a7e65; font-size:14px; }
  </style>
</head>
<body>

<div id="page-pending" class="page active">
  <div class="page-header">
    <div>
      <h1>Pending Orders</h1>
      <p>Manage and update order statuses</p>
    </div>
  </div>

  <div class="filter-bar">
    <button class="filter-btn active" onclick="setFilter('all', this)">All</button>
    <button class="filter-btn" onclick="setFilter('pending', this)">⏳ Pending</button>
    <button class="filter-btn" onclick="setFilter('completed', this)">✅ Completed</button>
    <button class="filter-btn" onclick="setFilter('cancelled', this)">❌ Cancelled</button>
    <button class="refresh-btn" onclick="loadOrders()">🔄 Refresh</button>
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
let allOrders    = [];
let currentFilter = 'all';
let pendingAction = null; // { orderId, status }

// ── Load orders ────────────────────────────────
async function loadOrders() {
  document.getElementById('orders-grid').innerHTML =
    '<div class="loading-wrap">Loading orders…</div>';
  try {
    const res  = await fetch('get_orders.php');
    const data = await res.json();
    allOrders  = data;
    renderOrders();
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

  const typeIcon = { 'Dine In': '🍽️', 'Take Out': '🛍️', 'Delivery': '🚗' };

  grid.innerHTML = filtered.map(o => {
    const items = o.items
      ? o.items.split(', ').map(i => {
          const [name, qty] = i.split(' x');
          return `<div class="card-item-row"><span>${name}</span><span>×${qty}</span></div>`;
        }).join('')
      : '<div class="card-item-row"><span>No items</span></div>';

    const time = new Date(o.created_at).toLocaleString('en-PH', {
      month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });

    const icon = typeIcon[o.payment_method] || '📋';
    const s = o.status || 'pending';

    return `
    <div class="order-card" id="card-${o.id}">
      <div class="card-head">
        <span class="card-order-num">#${String(o.id).padStart(4,'0')}</span>
        <span class="status-badge status-${s}">${statusLabel(s)}</span>
      </div>
      <div class="card-type">${icon} ${o.payment_method || 'Dine In'}</div>
      <div class="card-items">${items}</div>
      <div class="card-total">
        <span>Total</span>
        <span>₱${parseFloat(o.total_amount).toFixed(2)}</span>
      </div>
      <div class="card-time" style="padding:0 16px 6px;font-size:11px;color:#9a7e65">${time}</div>
      <div class="card-actions">
        <button class="action-btn btn-pending"
          ${s === 'pending' ? 'disabled' : ''}
          onclick="askConfirm(${o.id}, 'pending')">⏳ Pending</button>
        <button class="action-btn btn-complete"
          ${s === 'completed' ? 'disabled' : ''}
          onclick="askConfirm(${o.id}, 'completed')">✅ Done</button>
        <button class="action-btn btn-cancel"
          ${s === 'cancelled' ? 'disabled' : ''}
          onclick="askConfirm(${o.id}, 'cancelled')">❌ Cancel</button>
      </div>
    </div>`;
  }).join('');
}

function statusLabel(s) {
  return s === 'pending' ? '⏳ Pending'
       : s === 'completed' ? '✅ Completed'
       : '❌ Cancelled';
}

// ── Confirm Modal ──────────────────────────────
const configs = {
  pending:   { icon:'⏳', title:'Mark as Pending',   msg:'Move this order back to pending?',        cls:'ok-pending',  label:'Mark Pending'   },
  completed: { icon:'✅', title:'Complete Order',     msg:'Mark this order as completed?',           cls:'ok-complete', label:'Complete'        },
  cancelled: { icon:'❌', title:'Cancel Order',       msg:'Are you sure you want to cancel this order? This cannot be undone.', cls:'ok-cancel', label:'Yes, Cancel' },
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
    const res  = await fetch('updated_order_status.php', {
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