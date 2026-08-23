let allOrders = [];

// Current filter state — all filters combine (AND) against allOrders.
const filters = { search: '', type: '', status: '', date: '' };

// ── Load history ──────────────────────────────
fetch('get_history.php')
  .then(r => r.json())
  .then(data => {
    allOrders = data;
    applyFilters();
  })
  .catch(err => console.error("History load error:", err));

// ── Status label / class helpers ───────────────
function statusMeta(status) {
  const s = (status || 'pending').toLowerCase();
  if (s === 'completed' || s === 'complete') return { cls: 'status-completed', label: 'Done' };
  if (s === 'cancelled')                     return { cls: 'status-cancelled', label: 'Cancelled' };
  return { cls: 'status-pending', label: 'Pending' };
}

// ── Render table ──────────────────────────────
function renderHistory(orders) {
  const tbody = document.getElementById('history-tbody');

  if (!orders.length) {
    tbody.innerHTML = `<tr class="empty-row"><td colspan="7">🫙 No orders found</td></tr>`;
    return;
  }

  tbody.innerHTML = orders.map(o => {
    const pm      = o.payment_method || '—';
    const pmClass = pm.toLowerCase().includes('dine')     ? 'badge-dine'
                  : pm.toLowerCase().includes('take')     ? 'badge-take'
                  : pm.toLowerCase().includes('delivery') ? 'badge-delivery'
                  : 'badge-dine';
    const sm = statusMeta(o.status);

    return `
    <tr>
      <td class="strong-cell">#${String(o.id).padStart(4,'0')}</td>
      <td>${o.created_at}</td>
      <td class="muted-cell history-items-cell">${o.items || '—'}</td>
      <td><span class="badge ${pmClass}">${pm}</span></td>
      <td class="strong-cell">₱${Number(o.total_amount).toLocaleString()}</td>
      <td>
        <button class="act-btn" onclick='openReceipt(${JSON.stringify(o)})'>🧾 View</button>
      </td>
      <td>
        <span class="status-badge ${sm.cls}">${sm.label}</span>
      </td>
    </tr>`;
  }).join('');
}

// ── Combined filtering ─────────────────────────
function applyFilters() {
  const filtered = allOrders.filter(o => {
    const matchesSearch = !filters.search ||
      String(o.id).includes(filters.search) ||
      (o.items || '').toLowerCase().includes(filters.search);

    const matchesType = !filters.type ||
      (o.payment_method || '').toLowerCase().includes(filters.type.toLowerCase());

    const matchesStatus = !filters.status ||
      (o.status || 'pending').toLowerCase() === filters.status;

    const matchesDate = !filters.date ||
      String(o.created_at).slice(0, 10) === filters.date;

    return matchesSearch && matchesType && matchesStatus && matchesDate;
  });

  renderHistory(filtered);

  const countEl = document.getElementById('record-count');
  if (countEl) countEl.textContent = filtered.length + (filtered.length === 1 ? ' record' : ' records');

  return filtered;
}

function filterHistory(val) { filters.search = val.toLowerCase().trim(); applyFilters(); }
function filterType(val)    { filters.type   = val; applyFilters(); }
function filterStatus(val)  { filters.status = val; applyFilters(); }
function filterDate(val)    { filters.date   = val; applyFilters(); }

// ── Export (CSV of the currently filtered rows) ─
function exportHistory() {
  const rows = applyFilters();
  if (!rows.length) { alert('No records to export.'); return; }

  const header = ['Order #', 'Date', 'Items', 'Type', 'Total', 'Status'];
  const csvRows = rows.map(o => {
    const sm = statusMeta(o.status);
    const cells = [
      '#' + String(o.id).padStart(4, '0'),
      o.created_at,
      (o.items || '').replace(/"/g, '""'),
      o.payment_method || '',
      Number(o.total_amount).toFixed(2),
      sm.label,
    ];
    return cells.map(c => `"${c}"`).join(',');
  });

  const csv  = [header.map(h => `"${h}"`).join(','), ...csvRows].join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  const stamp = new Date().toISOString().slice(0, 10);

  a.href = url;
  a.download = `order-history-${stamp}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// ── Receipt modal ─────────────────────────────
function openReceipt(order) {
  const pm       = order.payment_method || 'Dine In';
  const pmIcon   = pm.toLowerCase().includes('dine')     ? '🍽️'
                 : pm.toLowerCase().includes('take')     ? '🛍️'
                 : pm.toLowerCase().includes('delivery') ? '🚗' : '🍽️';

  const itemsArr = (order.items_detail || '').split(';;').filter(Boolean);

  const itemRows = itemsArr.map(item => {
    const [name, qty, price] = item.split('||');
    return { name, qty: parseInt(qty), price: parseFloat(price) };
  });

  const sm = statusMeta(order.status);

  document.getElementById('r-order-num').textContent = '#' + String(order.id).padStart(4,'0');
  document.getElementById('r-date').textContent      = order.created_at;
  document.getElementById('r-type').textContent      = pmIcon + ' ' + pm;
  document.getElementById('r-status').textContent    = sm.label;
  document.getElementById('r-status').className      = 'status-badge ' + sm.cls;

  // Items list — same markup/classes as the New Order receipt
  document.getElementById('r-items').innerHTML = itemRows.map(i => `
    <div class="receipt-item">
      <div class="ri-icon">🧋</div>
      <div class="ri-info">
        <div class="ri-name">${i.name}</div>
        <div class="ri-qty">×${i.qty}</div>
      </div>
      <div class="ri-price">₱${(i.price * i.qty).toFixed(2)}</div>
    </div>
  `).join('');

  const total = Number(order.total_amount);
  const tax   = total / 1.12 * 0.12;
  const sub   = total - tax;

  document.getElementById('r-subtotal').textContent = '₱' + sub.toFixed(2);
  document.getElementById('r-tax').textContent      = '₱' + tax.toFixed(2);
  document.getElementById('r-total').textContent    = '₱' + total.toFixed(2);

  document.getElementById('receipt-overlay').classList.add('open');
}

function closeReceipt() {
  document.getElementById('receipt-overlay').classList.remove('open');
}

function printReceipt() {
  window.print();
}

// Close on backdrop click
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('receipt-overlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeReceipt();
  });
  // Move modal to body root
  const modal = document.getElementById('receipt-overlay');
  if (modal) document.body.appendChild(modal);
});

window.filterHistory = filterHistory;
window.filterType    = filterType;
window.filterStatus  = filterStatus;
window.filterDate    = filterDate;
window.exportHistory = exportHistory;
window.openReceipt   = openReceipt;
window.closeReceipt  = closeReceipt;
window.printReceipt  = printReceipt;