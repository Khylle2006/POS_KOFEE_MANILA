<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:     #c47d3e;
      --accent-lt:  #fdf3ea;
      --card-bg:    #ffffff;
      --border:     #ecddc8;
      --text-main:  #2c1a0e;
      --text-muted: #9a7e65;
      --bg:         #faf5ef;
      --green:      #2e7d32; --green-lt: #e8f5e9;
      --amber:      #e65100; --amber-lt: #fff3e0;
      --red:        #c62828; --red-lt:   #ffebee;
      --cream:      #fdf6ec;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    #page-inventory { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

    .page-header { padding: 22px 28px 0; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; }
    .page-header h1 { font-size: 22px; font-weight: 800; }
    .page-header p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

    .inv-body { flex: 1; overflow: hidden; display: flex; flex-direction: column; padding: 18px 28px 24px; gap: 16px; }

    /* stat pills */
    .stat-pills { display: flex; gap: 10px; flex-shrink: 0; }
    .stat-pill {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 16px; border-radius: 20px;
      font-size: 12px; font-weight: 700; border: 1.5px solid var(--border);
      background: var(--card-bg);
    }
    .stat-pill .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

    /* filter bar */
    .filter-bar {
      display: flex; align-items: center; gap: 8px;
      flex-shrink: 0; flex-wrap: wrap;
    }
    .filter-pill {
      padding: 7px 16px; border-radius: 20px;
      border: 1.5px solid var(--border);
      background: var(--card-bg);
      font-family: 'Poppins', sans-serif;
      font-size: 12px; font-weight: 600; color: var(--text-muted);
      cursor: pointer; text-decoration: none;
      transition: all .15s; white-space: nowrap;
    }
    .filter-pill:hover  { border-color: var(--accent); color: var(--accent); }
    .filter-pill.active { background: var(--accent); border-color: var(--accent); color: #fff; }
    .search-wrap { position: relative; margin-left: auto; }
    .search-wrap input {
      padding: 8px 14px 8px 34px;
      border: 1.5px solid var(--border); border-radius: 20px;
      font-family: 'Poppins', sans-serif; font-size: 12px;
      background: var(--card-bg); outline: none; width: 200px;
      transition: border-color .15s;
    }
    .search-wrap input:focus { border-color: var(--accent); }
    .search-wrap .si { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; }

    /* main split */
    .inv-split { display: grid; grid-template-columns: 380px 1fr; gap: 16px; flex: 1; overflow: hidden; }

    /* left list */
    .inv-list-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 18px; overflow: hidden;
      display: flex; flex-direction: column;
    }
    .inv-list-scroll { flex: 1; overflow-y: auto; }

    .cat-section { }
    .cat-label {
      padding: 10px 18px 6px;
      font-size: 10px; font-weight: 800;
      text-transform: uppercase; letter-spacing: .08em;
      color: var(--text-muted);
      background: var(--cream);
      border-bottom: 1px solid var(--border);
      position: sticky; top: 0; z-index: 1;
      display: flex; align-items: center; gap: 6px;
    }

    .ing-row {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 18px; cursor: pointer;
      border-bottom: 1px solid #f5ede0;
      transition: background .12s;
    }
    .ing-row:hover   { background: #fffaf5; }
    .ing-row.active  { background: var(--accent-lt); border-left: 3px solid var(--accent); }
    .ing-row:last-child { border-bottom: none; }

    .ing-icon-wrap {
      width: 38px; height: 38px; border-radius: 10px;
      background: var(--cream); border: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
    }
    .ing-info { flex: 1; min-width: 0; }
    .ing-name  { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ing-meta  { font-size: 11px; color: var(--text-muted); }
    .ing-dot   { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .dot-ok    { background: #4caf50; }
    .dot-low   { background: #ff9800; }
    .dot-out   { background: #f44336; }

    /* right detail */
    .inv-detail {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 18px; padding: 28px;
      display: flex; flex-direction: column; gap: 20px;
      overflow-y: auto;
    }
    .detail-empty {
      flex: 1; display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: var(--text-muted); gap: 10px; text-align: center;
    }
    .detail-empty .de-icon { font-size: 48px; opacity: .25; }
    .detail-empty p  { font-size: 14px; font-weight: 600; }
    .detail-empty small { font-size: 12px; }

    /* detail header */
    .detail-head { display: flex; align-items: flex-start; gap: 14px; }
    .detail-icon {
      width: 54px; height: 54px; border-radius: 14px;
      background: var(--cream); border: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 26px; flex-shrink: 0;
    }
    .detail-title { flex: 1; }
    .detail-title h2 { font-size: 20px; font-weight: 800; }
    .detail-title p  { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
    .status-badge {
      padding: 5px 12px; border-radius: 20px;
      font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .04em; white-space: nowrap; align-self: flex-start;
    }
    .badge-ok  { background: var(--green-lt); color: var(--green); }
    .badge-low { background: var(--amber-lt); color: var(--amber); }
    .badge-out { background: var(--red-lt);   color: var(--red); }

    /* stock display */
    .stock-display {
      text-align: center; padding: 20px;
      background: var(--cream); border-radius: 14px;
      border: 1.5px solid var(--border);
    }
    .stock-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); margin-bottom: 8px; }
    .stock-num   { font-size: 56px; font-weight: 800; color: var(--accent); line-height: 1; }
    .stock-unit  { font-size: 20px; font-weight: 600; color: var(--text-muted); margin-left: 4px; }
    .reorder-hint{ font-size: 12px; color: var(--text-muted); margin-top: 8px; }

    /* restock form */
    .restock-section h3 {
      font-size: 11px; font-weight: 800; text-transform: uppercase;
      letter-spacing: .08em; color: var(--text-muted); margin-bottom: 10px;
    }
    .restock-row { display: flex; gap: 10px; }
    .restock-row input {
      flex: 1; padding: 12px 14px;
      border: 1.5px solid var(--border); border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 14px;
      background: var(--cream); outline: none;
      transition: border-color .15s;
    }
    .restock-row input:focus { border-color: var(--accent); background: #fff; }
    .btn-confirm {
      padding: 12px 22px; background: var(--accent); color: #fff;
      border: none; border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: background .15s; white-space: nowrap;
    }
    .btn-confirm:hover { background: #7a4e2e; }

    /* action buttons */
    .detail-actions { display: flex; gap: 8px; margin-top: auto; padding-top: 8px; border-top: 1.5px solid var(--border); }
    .btn-edit {
      flex: 1; padding: 11px; border: 1.5px solid var(--border); border-radius: 12px;
      background: none; font-family: 'Poppins', sans-serif; font-size: 13px;
      font-weight: 600; color: var(--text-muted); cursor: pointer; transition: all .15s;
    }
    .btn-edit:hover { border-color: var(--accent); color: var(--accent); }
    .btn-del {
      padding: 11px 18px; border: 1.5px solid #ffcdd2; border-radius: 12px;
      background: var(--red-lt); font-family: 'Poppins', sans-serif; font-size: 13px;
      font-weight: 600; color: var(--red); cursor: pointer; transition: all .15s;
    }
    .btn-del:hover { background: var(--red); color: #fff; }

    /* add button */
    .btn-add {
      display: flex; align-items: center; gap: 6px;
      padding: 10px 18px; background: var(--accent); color: #fff;
      border: none; border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; transition: background .15s; text-decoration: none;
    }
    .btn-add:hover { background: #7a4e2e; }

    /* modal */
    .modal-overlay { display: none; position: fixed; top:0;left:0;width:100vw;height:100vh; background: rgba(44,26,14,.4); z-index: 99999; align-items: center; justify-content: center; }
    .modal-overlay.open { display: flex; }
    .modal { background: var(--card-bg); border-radius: 20px; padding: 28px; width: 100%; max-width: 440px; box-shadow: 0 12px 48px rgba(0,0,0,.18); animation: popIn .22s ease; }
    @keyframes popIn { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-header h3 { font-size: 16px; font-weight: 700; }
    .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
    .mfield { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
    .mfield label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
    .mfield input, .mfield select {
      padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 9px;
      font-family: 'Poppins', sans-serif; font-size: 13px; background: var(--cream);
      color: var(--text-main); outline: none; transition: border-color .15s;
    }
    .mfield input:focus, .mfield select:focus { border-color: var(--accent); background: #fff; }
    .mfield-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .modal-footer { display: flex; gap: 10px; margin-top: 16px; }
    .btn-mcancel { flex:1; padding:11px; border:1.5px solid var(--border); border-radius:11px; background:none; font-family:'Poppins',sans-serif; font-size:13px; font-weight:600; cursor:pointer; color:var(--text-muted); }
    .btn-msave   { flex:2; padding:11px; background:var(--accent); color:#fff; border:none; border-radius:11px; font-family:'Poppins',sans-serif; font-size:13px; font-weight:700; cursor:pointer; }
    .btn-msave:hover { background: #7a4e2e; }

    /* toast */
    .toast { position:fixed; bottom:24px; right:24px; z-index:99998; padding:13px 20px; border-radius:12px; font-size:13px; font-weight:600; box-shadow:0 4px 20px rgba(0,0,0,.14); transition:opacity .4s; max-width:320px; }
    .toast-success { background:var(--green-lt); color:var(--green); border:1.5px solid #c8e6c9; }
    .toast-error   { background:var(--red-lt);   color:var(--red);   border:1.5px solid #ffcdd2; }

    @media (max-width:900px) {
      .inv-split { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
 <?php include('../includes/admin_sidebar.php'); ?>
<!-- Include your sidebar -->
<div id="page-inventory" class="page active">

  <div class="page-header">
    <div>
      <h1>Inventory</h1>
      <p>Tap an item to view stock and restock</p>
    </div>
    <button class="btn-add" onclick="openAdd()">➕ Add Item</button>
  </div>

  <div class="inv-body">

    <!-- Stat pills -->
    <div class="stat-pills">
      <div class="stat-pill"><div class="dot" style="background:#4caf50"></div>5 In stock</div>
      <div class="stat-pill"><div class="dot" style="background:#ff9800"></div>2 Low stock</div>
      <div class="stat-pill"><div class="dot" style="background:#f44336"></div>1 Out of stock</div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <a href="#" class="filter-pill active">All</a>
      <a href="#" class="filter-pill">☕ Coffee</a>
      <a href="#" class="filter-pill">🥛 Milk</a>
      <a href="#" class="filter-pill">🧊 Syrups</a>
      <a href="#" class="filter-pill">🍵 Tea</a>
      <div class="search-wrap">
        <span class="si">🔍</span>
        <input type="text" placeholder="Search…"/>
      </div>
    </div>

    <!-- Main split -->
    <div class="inv-split">

      <!-- LEFT: ingredient list -->
      <div class="inv-list-card">
        <div class="inv-list-scroll">
          <div class="cat-section">
            <div class="cat-label">☕ COFFEE</div>
            
            <div class="ing-row active" id="row-1" onclick="selectItem(1)">
              <div class="ing-icon-wrap">☕</div>
              <div class="ing-info">
                <div class="ing-name">Espresso Beans</div>
                <div class="ing-meta">Lavazza · 2.5 kg</div>
              </div>
              <div class="ing-dot dot-ok"></div>
            </div>

            <div class="ing-row" id="row-2" onclick="selectItem(2)">
              <div class="ing-icon-wrap">☕</div>
              <div class="ing-info">
                <div class="ing-name">Dark Roast</div>
                <div class="ing-meta">Starbucks · 1.8 kg</div>
              </div>
              <div class="ing-dot dot-low"></div>
            </div>
          </div>

          <div class="cat-section">
            <div class="cat-label">🥛 MILK</div>
            
            <div class="ing-row" id="row-3" onclick="selectItem(3)">
              <div class="ing-icon-wrap">🥛</div>
              <div class="ing-info">
                <div class="ing-name">Fresh Milk</div>
                <div class="ing-meta">Nestle · 4.0 L</div>
              </div>
              <div class="ing-dot dot-ok"></div>
            </div>

            <div class="ing-row" id="row-4" onclick="selectItem(4)">
              <div class="ing-icon-wrap">🥛</div>
              <div class="ing-info">
                <div class="ing-name">Almond Milk</div>
                <div class="ing-meta">Alpro · 0.5 L</div>
              </div>
              <div class="ing-dot dot-out"></div>
            </div>

            <div class="ing-row" id="row-5" onclick="selectItem(5)">
              <div class="ing-icon-wrap">🥛</div>
              <div class="ing-info">
                <div class="ing-name">Oat Milk</div>
                <div class="ing-meta">Oatly · 2.0 L</div>
              </div>
              <div class="ing-dot dot-ok"></div>
            </div>
          </div>

          <div class="cat-section">
            <div class="cat-label">🧊 SYRUPS</div>
            
            <div class="ing-row" id="row-6" onclick="selectItem(6)">
              <div class="ing-icon-wrap">🧊</div>
              <div class="ing-info">
                <div class="ing-name">Vanilla Syrup</div>
                <div class="ing-meta">Monin · 0.8 L</div>
              </div>
              <div class="ing-dot dot-low"></div>
            </div>

            <div class="ing-row" id="row-7" onclick="selectItem(7)">
              <div class="ing-icon-wrap">🧊</div>
              <div class="ing-info">
                <div class="ing-name">Caramel Syrup</div>
                <div class="ing-meta">Torani · 1.2 L</div>
              </div>
              <div class="ing-dot dot-ok"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: detail panel -->
      <div class="inv-detail" id="detail-panel">
        <div class="detail-empty">
          <div class="de-icon">📦</div>
          <p>Select an ingredient</p>
          <small>Click any item on the left to view details and restock</small>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add / Edit modal -->
<div class="modal-overlay" id="item-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title">➕ Add Ingredient</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <form method="POST" id="item-form">
      <input type="hidden" name="action" id="f-action" value="add"/>
      <input type="hidden" name="ingredient_id" id="f-ing-id" value=""/>

      <div class="mfield">
        <label>Category</label>
        <select name="cat_id" id="f-cat" required>
          <option value="1">☕ Coffee</option>
          <option value="2">🥛 Milk</option>
          <option value="3">🧊 Syrups</option>
          <option value="4">🍵 Tea</option>
        </select>
      </div>
      <div class="mfield">
        <label>Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="name" id="f-name" placeholder="e.g. Fresh milk" required/>
      </div>
      <div class="mfield-row">
        <div class="mfield">
          <label>Brand</label>
          <input type="text" name="brand" id="f-brand" placeholder="e.g. Nestle"/>
        </div>
        <div class="mfield">
          <label>Unit</label>
          <input type="text" name="unit" id="f-unit" placeholder="L / kg / pcs / btl"/>
        </div>
      </div>
      <div class="mfield-row">
        <div class="mfield">
          <label>Current Qty</label>
          <input type="number" name="quantity" id="f-qty" placeholder="0" step="0.1" min="0"/>
        </div>
        <div class="mfield">
          <label>Reorder At</label>
          <input type="number" name="reorder_at" id="f-reorder" placeholder="5" step="0.1" min="0"/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-mcancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-msave" id="modal-save-btn">➕ Add Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal" style="max-width:340px;text-align:center">
    <div style="font-size:44px;margin-bottom:12px">🗑️</div>
    <h3 style="margin-bottom:8px">Delete Item?</h3>
    <p id="del-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:20px"></p>
    <form method="POST">
      <input type="hidden" name="action" value="delete"/>
      <input type="hidden" name="ingredient_id" id="del-id"/>
      <div class="modal-footer" style="justify-content:center">
        <button type="button" class="btn-mcancel" onclick="closeDelete()">Cancel</button>
        <button type="submit" class="btn-msave" style="background:var(--red)">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast notification -->
<div class="toast toast-success" id="toast-el" style="display:none;">Item added successfully!</div>

<script>
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
</script>

</body>
</html>