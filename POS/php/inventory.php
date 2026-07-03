<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/inventory.css"/>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
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

<script src="../js/inventory.js"></script>

</body>
</html>