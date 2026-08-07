let selectedId = null;

// Sample data for items
const items = {
  1: { id: 1, name: 'Espresso Beans', brand: 'Lavazza', unit: 'kg', quantity: 2.5, reorder_at: 1.0, cat_icon: '☕', cat_name: 'Coffee' },
  2: { id: 2, name: 'Dark Roast', brand: 'Starbucks', unit: 'kg', quantity: 1.8, reorder_at: 2.0, cat_icon: '☕', cat_name: 'Coffee' },
  3: { id: 3, name: 'Fresh Milk', brand: 'Nestle', unit: 'L', quantity: 4.0, reorder_at: 2.0, cat_icon: '🥛', cat_name: 'Milk' },
  4: { id: 4, name: 'Almond Milk', brand: 'Alpro', unit: 'L', quantity: 0.5, reorder_at: 1.0, cat_icon: '🥛', cat_name: 'Milk' },
  5: { id: 5, name: 'Oat Milk', brand: 'Oatly', unit: 'L', quantity: 2.0, reorder_at: 1.0, cat_icon: '🥛', cat_name: 'Milk' },
  6: { id: 6, name: 'Vanilla Syrup', brand: 'Monin', unit: 'L', quantity: 0.8, reorder_at: 1.0, cat_icon: '🧊', cat_name: 'Syrups' },
  7: { id: 7, name: 'Caramel Syrup', brand: 'Torani', unit: 'L', quantity: 1.2, reorder_at: 0.5, cat_icon: '🧊', cat_name: 'Syrups' }
};

// ── Select item → fill right panel ───────────
function selectItem(id) {
  selectedId = id;
  const ing = items[id];
  if (!ing) return;

  // Highlight row
  document.querySelectorAll('.ing-row').forEach(r => r.classList.remove('active'));
  const row = document.getElementById('row-' + id);
  if (row) row.classList.add('active');

  const qty = parseFloat(ing.quantity);
  const reorder = parseFloat(ing.reorder_at);
  const isOut = qty <= 0;
  const isLow = !isOut && qty <= reorder;
  const statusCls = isOut ? 'badge-out' : isLow ? 'badge-low' : 'badge-ok';
  const statusTxt = isOut ? '🔴 Out of stock' : isLow ? '⚠️ Low stock' : '✅ In stock';
  const color = isOut ? 'var(--red)' : isLow ? 'var(--amber)' : 'var(--accent)';

  document.getElementById('detail-panel').innerHTML = `
    <div class="detail-head">
      <div class="detail-icon">${ing.cat_icon}</div>
      <div class="detail-title">
        <h2>${escHtml(ing.name)}</h2>
        <p>${escHtml(ing.brand || '—')} · ${escHtml(ing.cat_name)} · ${escHtml(ing.unit)}</p>
      </div>
      <span class="status-badge ${statusCls}">${statusTxt}</span>
    </div>

    <div class="stock-display">
      <div class="stock-label">Current Stock</div>
      <div>
        <span class="stock-num" style="color:${color}">${parseFloat(qty).toFixed(1)}</span>
        <span class="stock-unit">${escHtml(ing.unit)}</span>
      </div>
      <div class="reorder-hint">Reorder at ${parseFloat(reorder).toFixed(1)} ${escHtml(ing.unit)}</div>
    </div>

    <div class="restock-section">
      <h3>Restock This Item</h3>
      <form method="POST" onsubmit="restockItem(event, ${ing.id})">
        <input type="hidden" name="action" value="restock"/>
        <input type="hidden" name="ingredient_id" value="${ing.id}"/>
        <div class="restock-row">
          <input type="number" name="qty" placeholder="Enter quantity (${escHtml(ing.unit)})"
                 step="0.1" min="0.1" required/>
          <button type="submit" class="btn-confirm">✔ Confirm</button>
        </div>
      </form>
    </div>

    <div class="detail-actions">
      <button class="btn-edit" onclick='openEdit(${JSON.stringify(ing)})'>✏️ Edit Details</button>
      <button class="btn-del" onclick="openDelete(${ing.id}, '${escHtml(ing.name)}')">🗑️ Delete</button>
    </div>
  `;
}

function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Restock ──────────────────────────────────
function restockItem(e, id) {
  e.preventDefault();
  const form = e.target;
  const qty = form.querySelector('input[name="qty"]').value;
  if (!qty || parseFloat(qty) <= 0) {
    showToast('Please enter a valid quantity', 'error');
    return;
  }
  
  const ing = items[id];
  ing.quantity = parseFloat(ing.quantity) + parseFloat(qty);
  
  showToast(`✅ Restocked ${qty} ${ing.unit} of ${ing.name}`, 'success');
  selectItem(id); // Refresh detail panel
  updateRowStatus(id);
}

// ── Update row status ──────────────────────
function updateRowStatus(id) {
  const ing = items[id];
  const row = document.getElementById('row-' + id);
  if (!row) return;
  
  const dot = row.querySelector('.ing-dot');
  const qty = parseFloat(ing.quantity);
  const reorder = parseFloat(ing.reorder_at);
  
  dot.className = 'ing-dot';
  if (qty <= 0) dot.classList.add('dot-out');
  else if (qty <= reorder) dot.classList.add('dot-low');
  else dot.classList.add('dot-ok');
  
  // Update meta text
  const meta = row.querySelector('.ing-meta');
  meta.textContent = `${ing.brand || '—'} · ${ing.quantity} ${ing.unit}`;
}

// ── Toast ────────────────────────────────────
function showToast(message, type = 'success') {
  const toast = document.getElementById('toast-el');
  toast.textContent = message;
  toast.className = `toast toast-${type}`;
  toast.style.display = 'block';
  toast.style.opacity = '1';
  
  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => {
      toast.style.display = 'none';
    }, 400);
  }, 3200);
}

// ── Add modal ─────────────────────────────────
function openAdd() {
  document.getElementById('modal-title').textContent = '➕ Add Ingredient';
  document.getElementById('modal-save-btn').textContent = '➕ Add Item';
  document.getElementById('f-action').value = 'add';
  document.getElementById('f-ing-id').value = '';
  document.getElementById('f-name').value = '';
  document.getElementById('f-brand').value = '';
  document.getElementById('f-unit').value = '';
  document.getElementById('f-qty').value = '';
  document.getElementById('f-reorder').value = '';
  document.getElementById('f-qty').closest('.mfield').style.display = '';
  document.getElementById('item-modal').classList.add('open');
}

// ── Edit modal ────────────────────────────────
function openEdit(ing) {
  document.getElementById('modal-title').textContent = '✏️ Edit Ingredient';
  document.getElementById('modal-save-btn').textContent = '💾 Save Changes';
  document.getElementById('f-action').value = 'edit';
  document.getElementById('f-ing-id').value = ing.id;
  document.getElementById('f-cat').value = ing.cat_id || 1;
  document.getElementById('f-name').value = ing.name;
  document.getElementById('f-brand').value = ing.brand || '';
  document.getElementById('f-unit').value = ing.unit;
  document.getElementById('f-qty').value = ing.quantity;
  document.getElementById('f-reorder').value = ing.reorder_at;
  document.getElementById('f-qty').closest('.mfield').style.display = 'none';
  document.getElementById('item-modal').classList.add('open');
}

// ── Form submit handler ──────────────────────
document.getElementById('item-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const action = document.getElementById('f-action').value;
  const name = document.getElementById('f-name').value;
  const brand = document.getElementById('f-brand').value;
  const unit = document.getElementById('f-unit').value;
  const qty = parseFloat(document.getElementById('f-qty').value) || 0;
  const reorder = parseFloat(document.getElementById('f-reorder').value) || 1;
  
  if (!name || !unit) {
    showToast('⚠️ Name and unit are required', 'error');
    return;
  }
  
  if (action === 'add') {
    const newId = Math.max(...Object.keys(items).map(Number)) + 1;
    const cat_id = parseInt(document.getElementById('f-cat').value);
    const cat_map = {1: 'Coffee', 2: 'Milk', 3: 'Syrups', 4: 'Tea'};
    const icon_map = {1: '☕', 2: '🥛', 3: '🧊', 4: '🍵'};
    
    items[newId] = {
      id: newId,
      name: name,
      brand: brand || '—',
      unit: unit,
      quantity: qty,
      reorder_at: reorder,
      cat_icon: icon_map[cat_id] || '📦',
      cat_name: cat_map[cat_id] || 'Other'
    };
    
    showToast(`✅ "${name}" added successfully!`, 'success');
    closeModal();
    location.reload(); // Reload to show new item
  } else {
    const id = parseInt(document.getElementById('f-ing-id').value);
    if (items[id]) {
      items[id].name = name;
      items[id].brand = brand || '—';
      items[id].unit = unit;
      items[id].reorder_at = reorder;
      showToast(`✅ "${name}" updated successfully!`, 'success');
      closeModal();
      selectItem(id);
    }
  }
});

function closeModal() {
  document.getElementById('item-modal').classList.remove('open');
  document.getElementById('f-qty').closest('.mfield').style.display = '';
}

// ── Delete modal ──────────────────────────────
function openDelete(id, name) {
  document.getElementById('del-id').value = id;
  document.getElementById('del-msg').textContent = 'This will permanently remove "' + name + '".';
  document.getElementById('delete-modal').classList.add('open');
}

// Delete handler
document.querySelector('#delete-modal form').addEventListener('submit', function(e) {
  e.preventDefault();
  const id = parseInt(document.getElementById('del-id').value);
  const name = items[id]?.name || 'Item';
  delete items[id];
  showToast(`🗑️ "${name}" deleted successfully!`, 'success');
  closeDelete();
  location.reload();
});

function closeDelete() { 
  document.getElementById('delete-modal').classList.remove('open'); 
}

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) { closeModal(); closeDelete(); }
  });
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeDelete(); }
});

// Auto-select first item on load
document.addEventListener('DOMContentLoaded', function() {
  const firstRow = document.querySelector('.ing-row');
  if (firstRow) {
    const id = parseInt(firstRow.id.replace('row-', ''));
    selectItem(id);
  }
});