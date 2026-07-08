let allOrders = [];

// ── Load history ──────────────────────────────
fetch('get_history.php')
  .then(r => r.json())
  .then(data => {
    allOrders = data;
    renderHistory(data);
  })
  .catch(err => console.error("History load error:", err));

// ── Render table ──────────────────────────────
function renderHistory(orders) {
  const tbody = document.getElementById('history-tbody');

  if (!orders.length) {
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:#9a7e65">🫙 No orders found</td></tr>`;
    return;
  }

  tbody.innerHTML = orders.map(o => {
    const pm      = o.payment_method || '—';
    const pmClass = pm.toLowerCase().includes('dine')     ? 'badge-dine'
                  : pm.toLowerCase().includes('take')     ? 'badge-take'
                  : pm.toLowerCase().includes('delivery') ? 'badge-delivery'
                  : 'badge-dine';

    return `
    <tr>
      <td style="font-weight:700">#${String(o.id).padStart(4,'0')}</td>
      <td>${o.created_at}</td>
      <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#9a7e65">
        ${o.items || '—'}
      </td>
      <td><span class="pm-badge ${pmClass}">${pm}</span></td>
      <td style="font-weight:700">₱${Number(o.total_amount).toLocaleString()}</td>
      <td>
        <button class="receipt-btn" onclick='openReceipt(${JSON.stringify(o)})'>🧾 View</button>
      </td>

      <td>
  <span class="status-badge status-${(o.status || 'pending').toLowerCase()}">
    ${(o.status || 'Pending')}
  </span>

  
</td>
    </tr>`;
  }).join('');
}

// ── Filter by search ──────────────────────────
function filterHistory(val) {
  const q = val.toLowerCase();
  const filtered = allOrders.filter(o =>
    String(o.id).includes(q) ||
    (o.items || '').toLowerCase().includes(q)
  );
  renderHistory(filtered);
}

// ── Filter by type ────────────────────────────
function filterType(val) {
  const filtered = val
    ? allOrders.filter(o => (o.payment_method || '').toLowerCase().includes(val.toLowerCase()))
    : allOrders;
  renderHistory(filtered);
}

// ── Receipt modal ─────────────────────────────
function openReceipt(order) {
  const pm       = order.payment_method || 'Dine In';
  const pmIcon   = pm.toLowerCase().includes('dine')     ? '🍽️'
                 : pm.toLowerCase().includes('take')     ? '🛍️'
                 : pm.toLowerCase().includes('delivery') ? '🚗' : '🍽️';

  const itemsArr = (order.items || '').split(', ').filter(Boolean);

  // Build item rows for receipt
  const itemRows = itemsArr.map(item => {
    // format: "Product Name x3"
    const match = item.match(/^(.+)\s+x(\d+)$/i);
    if (match) {
      const name = match[1].trim();
      const qty  = parseInt(match[2]);
      return { name, qty };
    }
    return { name: item, qty: 1 };
  });

  document.getElementById('r-order-num').textContent = '#' + String(order.id).padStart(4,'0');
  document.getElementById('r-date').textContent      = order.created_at;
  document.getElementById('r-type').textContent      = pmIcon + ' ' + pm;
  document.getElementById('r-status').textContent    = order.status || 'pending';
  document.getElementById('r-status').className      = 'r-status-badge ' +
    (order.status === 'complete' || order.status === 'completed' ? 'badge-complete' : 'badge-pending');

  // Items table
  document.getElementById('r-items').innerHTML = itemRows.map(i => `
    <tr>
      <td>🧋 ${i.name}</td>
      <td style="text-align:center">×${i.qty}</td>
      <td style="text-align:right">—</td>
    </tr>
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
window.openReceipt   = openReceipt;
window.closeReceipt  = closeReceipt;
window.printReceipt  = printReceipt;