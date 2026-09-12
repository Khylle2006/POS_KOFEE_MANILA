<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');
include("../api/add_item.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Menu Manager — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <?php ?>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-menu-manager" class="page active">
  <div class="page-header">
    <div>
      <h1>Menu Manager</h1>
      <p>Edit and manage your drink menu</p>

    
      
    </div>
   <button class="btn-msave" onclick="openAdd()">
    ➕ Add Item
</button>
  </div>

  <div class="page-body">

    <div class="filter-bar" style="align-items:center">
      <input class="filter-input" type="text" id="search-products"
             placeholder="🔍 Search by item name…" oninput="applyFilters()"
             style="flex:1;min-width:220px"/>

      <select class="filter-select" id="filter-category" onchange="applyFilters()" style="width:auto">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="filter-select" id="filter-status" onchange="applyFilters()" style="width:auto">
        <option value="">All Statuses</option>
        <option value="available">Available</option>
        <option value="unavailable">Unavailable</option>
      </select>

      <span id="record-count" style="font-size:12px;color:var(--text-muted);white-space:nowrap;margin-left:auto"></span>
    </div>

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th>Price</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="menu-tbody">
          <?php if (empty($products)): ?>
          <tr class="empty-row"><td colspan="5">🫙 No menu items yet — add drinks from the Inventory module first.</td></tr>
          <?php else: ?>
            <?php
            $cat_icons = ['Ice Coffee'=>'🧊','Hot Coffee'=>'☕','Milk Tea'=>'🧋','Fruit Tea'=>'🍹'];
            foreach ($products as $p):
              $available = (int)$p['stock'] > 0;
              $cat_name  = $p['category_name'] ?? '—';
              $icon      = $cat_icons[$cat_name] ?? '🥤';
              $edit_data = htmlspecialchars(json_encode([
                'id'          => $p['id'],
                'name'        => $p['name'],
                'description' => $p['description'],
                'price_small' => $p['price_small'],
                'price_large' => $p['price_large'],
                'category_id' => $p['category_id'],
              ]), ENT_QUOTES);
            ?>
            <tr class="menu-row" id="prow-<?= $p['id'] ?>"
                data-cat="<?= (int)$p['category_id'] ?>"
                data-status="<?= $available ? 'available' : 'unavailable' ?>"
                data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>">
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:36px;height:36px;border-radius:10px;background:var(--accent-lt);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0"><?= $icon ?></div>
                  <div>
                    <div class="prod-name" style="font-weight:600;color:var(--espresso)"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if (!empty($p['description'])): ?>
                      <div style="font-size:11.5px;color:var(--text-muted)"><?= htmlspecialchars($p['description']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="muted-cell"><?= htmlspecialchars($cat_name) ?></td>
              <td class="prod-price">₱<?= number_format($p['price_small'], 2) ?> · ₱<?= number_format($p['price_large'], 2) ?></td>
              <td>
                <span class="status-badge <?= $available ? 'status-active' : 'status-blocked' ?>" id="status-<?= $p['id'] ?>">
                  <?= $available ? '✅ Available' : '❌ Unavailable' ?>
                </span>
              </td>
              <td>
                <div class="act-group">
                  <?php if (has_permission('menu.edit')): ?>
                  <button class="act-btn <?= $available ? 'act-hold' : 'act-activate' ?>"
                          id="toggle-<?= $p['id'] ?>"
                          data-state="<?= $available ? 'on' : 'off' ?>"
                          onclick="toggleAvail(<?= $p['id'] ?>, this)">
                    <?= $available ? 'Mark Unavailable' : 'Mark Available' ?>
                  </button>
                  <button class="act-btn" onclick='openEdit(<?= $edit_data ?>)'>✏️ Edit</button>
                  <button class="act-btn" onclick="openRecipe(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>)">🧪 Recipe</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- Edit modal -->
<div class="modal-overlay" id="edit-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>✏️ Edit Menu Item</h3>
      <button class="modal-close" onclick="closeEdit()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="e-id"/>
      <div class="field-group">
        <label class="field-label">Category</label>
        <select class="field-select" id="e-category">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Drink Name</label>
        <input class="field-input" type="text" id="e-name"/>
      </div>
      <div class="field-group">
        <label class="field-label">Description</label>
        <textarea class="field-textarea" id="e-desc"></textarea>
      </div>
      <div class="field-group">
        <label class="field-label">Product Image</label>
        <input class="field-input" type="file" id="e-image" accept="image/jpeg,image/png,image/webp"/>
        <small style="color:var(--text-muted)">Optional. JPG, PNG, or WebP up to 5 MB.</small>
      </div>
      <div class="field-row">
        <div class="field-group">
          <label class="field-label">Regular Price (₱)</label>
          <input class="field-input" type="number" id="e-price-small" step="0.01" min="0"/>
        </div>
        <div class="field-group">
          <label class="field-label">Up Size Price (₱)</label>
          <input class="field-input" type="number" id="e-price-large" step="0.01" min="0"/>
        </div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeEdit()">Cancel</button>
      <button class="btn-msave" onclick="saveEdit()">
    💾 Save Changes
</button>
    </div>
  </div>
</div>

<!-- add modal -->
<div class="modal-overlay" id="add-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3>➕ Add Menu Item</h3>
      <button class="modal-close" onclick="closeAdd()">✕</button>
    </div>
    <div class="modal-body">
      <div class="field-group">
        <label class="field-label">Category</label>
        <select class="field-select" id="add-category">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group">
        <label class="field-label">Drink Name</label>
        <input class="field-input" type="text" id="add-name"/>
      </div>
      <div class="field-group">
        <label class="field-label">Description</label>
        <textarea class="field-textarea" id="add-desc"></textarea>
      </div>
      <div class="field-group">
        <label class="field-label">Product Image</label>
        <input class="field-input" type="file" id="add-image" accept="image/jpeg,image/png,image/webp"/>
        <small style="color:var(--text-muted)">Optional. JPG, PNG, or WebP up to 5 MB.</small>
      </div>
      <div class="field-row">
        <div class="field-group">
          <label class="field-label">Regular Price (₱)</label>
          <input class="field-input" type="number" id="add-price-small" step="0.01" min="0"/>
        </div>
        <div class="field-group">
          <label class="field-label">Up Size Price (₱)</label>
          <input class="field-input" type="number" id="add-price-large" step="0.01" min="0"/>
        </div>
      </div>

      <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">
      <div style="font-size:13px;font-weight:700;color:var(--espresso);margin-bottom:6px">
        🧪 Recipe & Ingredients (Auto-deducted on order)
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
        Pick the ingredients consumed per cup for Regular and Up Size.
      </p>
      <div class="filter-bar" style="margin-bottom:10px">
        <button type="button" class="act-btn" id="add-recipe-tab-small" onclick="switchNewItemRecipeSize('small')">Regular</button>
        <button type="button" class="act-btn" id="add-recipe-tab-large" onclick="switchNewItemRecipeSize('large')">Up Size</button>
      </div>
      <div id="add-recipe-rows"></div>
      <button type="button" class="act-btn" style="margin-top:8px" onclick="addNewItemRecipeRow()">➕ Add Ingredient</button>
      <p id="add-recipe-empty-msg" style="font-size:12px;color:var(--text-muted);margin-top:8px">
        No ingredients added for this size yet.
      </p>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeAdd()">Cancel</button>
      <button class="btn-msave" onclick="addMenuItem()">💾 Save Item</button>
    </div>
  </div>
</div>

<!-- Add Item confirm modal -->
<div class="modal-overlay" id="add-confirm-modal">
  <div class="modal" style="max-width:380px;text-align:center">
    <div class="modal-body" style="text-align:center">
      <div style="font-size:44px;margin-bottom:12px">➕</div>
      <h3 style="font-size:17px;margin-bottom:8px">Add This Item?</h3>
      <div id="add-confirm-summary" style="font-size:13px;color:var(--text-muted);text-align:left;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:12px 0;margin-top:8px"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-mcancel" onclick="closeAddConfirm()">Cancel</button>
      <button type="button" class="btn-msave" id="add-confirm-btn" onclick="doAddMenuItem()">✅ Yes, Add Item</button>
    </div>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal" style="max-width:360px">
    <div class="modal-body" style="text-align:center">
      <div style="font-size:46px;margin-bottom:12px">🗑️</div>
      <h3 style="font-size:17px;margin-bottom:8px">Delete Item?</h3>
      <p id="del-msg" style="font-size:13px;color:var(--text-muted)"></p>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-msave" style="background:var(--red)" onclick="doDelete()">Yes, Delete</button>
    </div>
  </div>
</div>

<!-- Availability confirm modal -->
<div class="modal-overlay" id="avail-modal">
  <div class="modal" style="max-width:360px">
    <div class="modal-body" style="text-align:center">
      <div style="font-size:46px;margin-bottom:12px" id="avail-icon">❓</div>
      <h3 style="font-size:17px;margin-bottom:8px" id="avail-title">Change Availability?</h3>
      <p id="avail-msg" style="font-size:13px;color:var(--text-muted)"></p>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeAvail()">Cancel</button>
      <button class="btn-msave" id="avail-confirm-btn" onclick="doToggleAvail()">Yes, Confirm</button>
    </div>
  </div>
</div>

<!-- Recipe builder modal -->
<div class="modal-overlay" id="recipe-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3>🧪 Recipe — <span id="recipe-item-name"></span></h3>
      <button class="modal-close" onclick="closeRecipe()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:10px">
        Set which inventory ingredients this drink consumes, and how much per cup.
        Regular and Up Size can use different amounts.
      </p>
      <div class="filter-bar" style="margin-bottom:12px">
        <button type="button" class="act-btn" id="recipe-tab-small" onclick="switchRecipeSize('small')">Regular</button>
        <button type="button" class="act-btn" id="recipe-tab-large" onclick="switchRecipeSize('large')">Up Size</button>
      </div>
      <div id="recipe-rows"></div>
      <button type="button" class="act-btn" style="margin-top:8px" onclick="addRecipeRow()">➕ Add Ingredient</button>
      <p id="recipe-empty-msg" style="font-size:12.5px;color:var(--text-muted);margin-top:8px;display:none">
        No ingredients set for this size yet — this size won't deduct any stock at checkout.
      </p>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeRecipe()">Cancel</button>
      <button class="btn-msave" onclick="saveRecipe()">💾 Save Recipe</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast" style="display:none"></div>

<script>

let allIngredientsList = [];
async function ensureIngredientsLoaded() {
  if (allIngredientsList.length > 0) return allIngredientsList;
  try {
    const res = await fetch('../api/get_ingredients.php');
    allIngredientsList = await res.json();
    if (!recipeState.ingredientList || recipeState.ingredientList.length === 0) {
      recipeState.ingredientList = allIngredientsList;
    }
  } catch(e) {
    console.error('Failed to load ingredients:', e);
  }
  return allIngredientsList;
}

let newItemRecipe = {
  activeSize: 'small',
  small: [],
  large: []
};

async function openAdd() {
  document.getElementById('add-category').value    = '';
  document.getElementById('add-name').value        = '';
  document.getElementById('add-desc').value        = '';
  document.getElementById('add-price-small').value = '';
  document.getElementById('add-price-large').value = '';
  document.getElementById('add-image').value        = '';
  newItemRecipe = { activeSize: 'small', small: [], large: [] };
  await ensureIngredientsLoaded();
  renderNewItemRecipeRows();
  document.getElementById('add-modal').classList.add('open');
}
function closeAdd() { document.getElementById('add-modal').classList.remove('open'); }

function switchNewItemRecipeSize(size) {
  saveCurrentNewItemRows();
  newItemRecipe.activeSize = size;
  renderNewItemRecipeRows();
}

function saveCurrentNewItemRows() {
  const rows = Array.from(document.querySelectorAll('#add-recipe-rows .field-row'));
  const list = [];
  for (const row of rows) {
    const sel = row.querySelector('.recipe-ing-select');
    const inp = row.querySelector('.recipe-qty-input');
    if (sel && inp && sel.value && inp.value) {
      list.push({ ingredient_id: parseInt(sel.value, 10), qty_used: parseFloat(inp.value) });
    }
  }
  newItemRecipe[newItemRecipe.activeSize] = list;
}

function renderNewItemRecipeRows() {
  const tabSmall = document.getElementById('add-recipe-tab-small');
  const tabLarge = document.getElementById('add-recipe-tab-large');
  if (tabSmall) tabSmall.style.background = newItemRecipe.activeSize === 'small' ? 'var(--accent-lt)' : '';
  if (tabLarge) tabLarge.style.background = newItemRecipe.activeSize === 'large' ? 'var(--accent-lt)' : '';

  const rows = newItemRecipe[newItemRecipe.activeSize] || [];
  const container = document.getElementById('add-recipe-rows');
  if (!container) return;
  container.innerHTML = '';

  const emptyMsg = document.getElementById('add-recipe-empty-msg');
  if (rows.length === 0) {
    if (emptyMsg) emptyMsg.style.display = '';
  } else {
    if (emptyMsg) emptyMsg.style.display = 'none';
    rows.forEach((row, i) => {
      container.appendChild(buildNewItemRowEl(row.ingredient_id, row.qty_used));
    });
  }
}

function buildNewItemRowEl(selectedId, qty) {
  const wrap = document.createElement('div');
  wrap.className = 'field-row';
  wrap.style.alignItems = 'center';

  const options = allIngredientsList.map(ing =>
    `<option value="${ing.id}" ${ing.id == selectedId ? 'selected' : ''}>${escapeHtml(ing.name)} (${escapeHtml(ing.unit)})</option>`
  ).join('');

  wrap.innerHTML = `
    <div class="field-group" style="flex:2">
      <select class="field-select recipe-ing-select">
        <option value="">— choose ingredient —</option>
        ${options}
      </select>
    </div>
    <div class="field-group" style="flex:1">
      <input class="field-input recipe-qty-input" type="number" step="0.01" min="0.01" placeholder="Qty used" value="${qty ?? ''}"/>
    </div>
    <button type="button" class="act-btn act-hold" style="height:38px" onclick="this.closest('.field-row').remove(); toggleNewItemEmptyMsg();">🗑️</button>
  `;
  return wrap;
}

function addNewItemRecipeRow() {
  const emptyMsg = document.getElementById('add-recipe-empty-msg');
  if (emptyMsg) emptyMsg.style.display = 'none';
  const container = document.getElementById('add-recipe-rows');
  container.appendChild(buildNewItemRowEl('', ''));
}

function toggleNewItemEmptyMsg() {
  const container = document.getElementById('add-recipe-rows');
  const emptyMsg = document.getElementById('add-recipe-empty-msg');
  if (emptyMsg && container) emptyMsg.style.display = container.children.length === 0 ? '' : 'none';
}

// ── Add: preview step ──
function addMenuItem() {
  const cat = document.getElementById('add-category');
  const name = document.getElementById('add-name');
  const priceSmall = document.getElementById('add-price-small');
  const priceLarge = document.getElementById('add-price-large');

  let valid = true;
  let firstInvalid = null;

  if (!cat.value) {
    KofeeValidator.showError(cat, 'Please choose a category.');
    valid = false;
    if (!firstInvalid) firstInvalid = cat;
  } else {
    KofeeValidator.clearError(cat);
  }

  if (!name.value.trim()) {
    KofeeValidator.showError(name, 'Drink name is required.');
    valid = false;
    if (!firstInvalid) firstInvalid = name;
  } else if (name.value.trim().length < 2) {
    KofeeValidator.showError(name, 'Drink name must be at least 2 characters.');
    valid = false;
    if (!firstInvalid) firstInvalid = name;
  } else {
    KofeeValidator.clearError(name);
  }

  if (!priceSmall.value || parseFloat(priceSmall.value) <= 0) {
    KofeeValidator.showError(priceSmall, 'Regular price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceSmall;
  } else {
    KofeeValidator.clearError(priceSmall);
  }

  if (!priceLarge.value || parseFloat(priceLarge.value) <= 0) {
    KofeeValidator.showError(priceLarge, 'Up size price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceLarge;
  } else {
    KofeeValidator.clearError(priceLarge);
  }

  saveCurrentNewItemRows();

  const currentRows = Array.from(document.querySelectorAll('#add-recipe-rows .field-row'));
  for (const row of currentRows) {
    const sel = row.querySelector('.recipe-ing-select');
    const inp = row.querySelector('.recipe-qty-input');
    if (sel && sel.value && (!inp.value || parseFloat(inp.value) <= 0)) {
      KofeeValidator.showError(inp, 'Enter quantity greater than 0.');
      valid = false;
      if (!firstInvalid) firstInvalid = inp;
    }
  }

  if (!valid && firstInvalid) {
    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    firstInvalid.focus();
    return;
  }

  const catName   = cat.options[cat.selectedIndex]?.textContent || '—';
  const desc      = document.getElementById('add-desc').value.trim();
  const regCount  = newItemRecipe.small.length;
  const upCount   = newItemRecipe.large.length;

  document.getElementById('add-confirm-summary').innerHTML = `
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Name</span><strong>${escapeHtml(name.value.trim())}</strong></div>
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Category</span><strong>${escapeHtml(catName)}</strong></div>
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Regular Price</span><strong>₱${parseFloat(priceSmall.value).toFixed(2)}</strong></div>
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Up Size Price</span><strong>₱${parseFloat(priceLarge.value).toFixed(2)}</strong></div>
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Recipe (Regular)</span><strong>${regCount} ingredient${regCount === 1 ? '' : 's'}</strong></div>
    <div style="display:flex;justify-content:space-between;padding:3px 0"><span>Recipe (Up Size)</span><strong>${upCount} ingredient${upCount === 1 ? '' : 's'}</strong></div>
    ${desc ? `<div style="padding:6px 0 0;color:var(--text-muted);font-style:italic">"${escapeHtml(desc)}"</div>` : ''}
  `;

  document.getElementById('add-confirm-modal').classList.add('open');
}

function closeAddConfirm() {
  document.getElementById('add-confirm-modal').classList.remove('open');
}

// ── Add: actual commit ──
function doAddMenuItem() {
  saveCurrentNewItemRows();

  const fd = new FormData();
  fd.append('action',      'add');
  fd.append('category_id', document.getElementById('add-category').value);
  fd.append('name',        document.getElementById('add-name').value.trim());
  fd.append('description', document.getElementById('add-desc').value);
  fd.append('price_small', document.getElementById('add-price-small').value);
  fd.append('price_large', document.getElementById('add-price-large').value);
  fd.append('recipe',      JSON.stringify({
    small: newItemRecipe.small,
    large: newItemRecipe.large
  }));

  const addImage = document.getElementById('add-image').files[0];
  if (addImage) fd.append('image', addImage);

  const btn = document.getElementById('add-confirm-btn');
  KofeeValidator.setLoading(btn, 'Adding…');

  fetch("../api/add_item.php", { method: "POST", body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        showToast('✅ Item added with recipe!');
        closeAddConfirm();
        closeAdd();
        location.reload();
      } else {
        closeAddConfirm();
        KofeeValidator.resetLoading(btn, '✅ Yes, Add Item');
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => {
      closeAddConfirm();
      KofeeValidator.resetLoading(btn, '✅ Yes, Add Item');
      showToast('⚠️ Network error.', 'error');
    });
}

const SELF = window.location.pathname; // posts back to same file
const CAT_ICONS = { 'Ice Coffee':'🧊','Hot Coffee':'☕','Milk Tea':'🧋','Fruit Tea':'🍹' };

// ── Toast ─────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent  = msg;
  t.className    = 'toast toast-' + type + ' show';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Filters (search + category + status) ───────
function applyFilters() {
  const q      = document.getElementById('search-products').value.toLowerCase().trim();
  const cat    = document.getElementById('filter-category').value;
  const status = document.getElementById('filter-status').value;

  let visible = 0;
  document.querySelectorAll('.menu-row').forEach(row => {
    const matchesName   = !q || row.dataset.name.includes(q);
    const matchesCat    = !cat || row.dataset.cat === cat;
    const matchesStatus = !status || row.dataset.status === status;
    const show = matchesName && matchesCat && matchesStatus;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  const countEl = document.getElementById('record-count');
  if (countEl) countEl.textContent = visible + (visible === 1 ? ' item' : ' items');
}
document.addEventListener('DOMContentLoaded', applyFilters);

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// ── Toggle availability ────────────────────────
let pendingToggle = null; // { id, btn, makingAvailable }

function toggleAvail(id, btn) {
  const makingAvailable = btn.dataset.state === 'off'; // currently off -> about to turn on
  pendingToggle = { id, btn, makingAvailable };

  const row      = document.getElementById('prow-' + id);
  const itemName = row.querySelector('.prod-name').textContent;

  document.getElementById('avail-icon').textContent  = makingAvailable ? '✅' : '❌';
  document.getElementById('avail-title').textContent = makingAvailable ? 'Mark as Available?' : 'Mark as Unavailable?';
  document.getElementById('avail-msg').textContent = makingAvailable
    ? `"${itemName}" will be visible and orderable again.`
    : `"${itemName}" will be hidden from ordering until re-enabled.`;

  document.getElementById('avail-confirm-btn').style.background = makingAvailable ? '' : 'var(--red)';
  document.getElementById('avail-modal').classList.add('open');
}
function closeAvail() {
  document.getElementById('avail-modal').classList.remove('open');
  pendingToggle = null;
}
function doToggleAvail() {
  if (!pendingToggle) return;
  const { id, btn } = pendingToggle;

  const fd = new FormData();
  fd.append('action', 'toggle');
  fd.append('id', id);

      fetch("../api/add_item.php", {
        method: "POST",
        body: fd
    })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        const row    = document.getElementById('prow-' + id);
        const status = document.getElementById('status-' + id);
        if (res.available) {
          btn.textContent      = 'Mark Unavailable';
          btn.className        = 'act-btn act-hold';
          btn.dataset.state    = 'on';
          status.className     = 'status-badge status-active';
          status.textContent   = '✅ Available';
          row.dataset.status   = 'available';
          showToast('✅ Item set to Available');
        } else {
          btn.textContent      = 'Mark Available';
          btn.className        = 'act-btn act-activate';
          btn.dataset.state    = 'off';
          status.className     = 'status-badge status-blocked';
          status.textContent   = '❌ Unavailable';
          row.dataset.status   = 'unavailable';
          showToast('❌ Item set to Unavailable', 'error');
        }
        applyFilters();
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => showToast('⚠️ Network error.', 'error'))
    .finally(closeAvail);
}

// ── Edit modal ────────────────────────────────
function openEdit(p) {
  document.getElementById('e-id').value          = p.id;
  document.getElementById('e-category').value    = p.category_id;
  document.getElementById('e-name').value        = p.name;
  document.getElementById('e-desc').value        = p.description || '';
  document.getElementById('e-price-small').value = p.price_small;
  document.getElementById('e-price-large').value = p.price_large;
  document.getElementById('e-image').value        = '';
  document.getElementById('edit-modal').classList.add('open');
}
function closeEdit() { document.getElementById('edit-modal').classList.remove('open'); }

function saveEdit() {
  const cat = document.getElementById('e-category');
  const name = document.getElementById('e-name');
  const priceSmall = document.getElementById('e-price-small');
  const priceLarge = document.getElementById('e-price-large');

  let valid = true;
  let firstInvalid = null;

  if (!name.value.trim()) {
    KofeeValidator.showError(name, 'Drink name is required.');
    valid = false;
    if (!firstInvalid) firstInvalid = name;
  } else {
    KofeeValidator.clearError(name);
  }

  if (!priceSmall.value || parseFloat(priceSmall.value) <= 0) {
    KofeeValidator.showError(priceSmall, 'Regular price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceSmall;
  } else {
    KofeeValidator.clearError(priceSmall);
  }

  if (!priceLarge.value || parseFloat(priceLarge.value) <= 0) {
    KofeeValidator.showError(priceLarge, 'Up size price must be greater than 0.');
    valid = false;
    if (!firstInvalid) firstInvalid = priceLarge;
  } else {
    KofeeValidator.clearError(priceLarge);
  }

  if (!valid && firstInvalid) {
    firstInvalid.focus();
    return;
  }

  const id = document.getElementById('e-id').value;
  const btn = document.querySelector('#edit-modal .btn-msave');
  KofeeValidator.setLoading(btn, 'Saving…');

  const fd = new FormData();
  fd.append('action',      'edit');
  fd.append('id',          id);
  fd.append('category_id', cat.value);
  fd.append('name',        name.value.trim());
  fd.append('description', document.getElementById('e-desc').value);
  fd.append('price_small', priceSmall.value);
  fd.append('price_large', priceLarge.value);
  const editImage = document.getElementById('e-image').files[0];
  if (editImage) fd.append('image', editImage);

  fetch(SELF, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      KofeeValidator.resetLoading(btn, '💾 Save Changes');
      if (res.ok) {
        closeEdit();
        showToast('✅ Item updated!');
        updateRowInDOM(id, {
          name: name.value.trim(),
          description: document.getElementById('e-desc').value,
          price_small: priceSmall.value,
          price_large: priceLarge.value,
          category_id: cat.value,
        });
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => {
      KofeeValidator.resetLoading(btn, '💾 Save Changes');
      showToast('⚠️ Network error.', 'error');
    });
}

function updateRowInDOM(id, p) {
  const row = document.getElementById('prow-' + id);
  if (!row) return;
  const catSelect = document.getElementById('e-category');
  const catName   = catSelect.options[catSelect.selectedIndex].textContent;

  row.querySelector('.prod-name').textContent = p.name;
  row.querySelector('.prod-price').textContent =
    `₱${parseFloat(p.price_small).toFixed(2)} · ₱${parseFloat(p.price_large).toFixed(2)}`;
  row.querySelector('.muted-cell').textContent = catName;
  row.dataset.cat  = p.category_id;
  row.dataset.name = p.name.toLowerCase();

  // Keep the Edit button's stored data current for the next click
  const editBtn = row.querySelector('.act-group button:nth-child(2)');
  if (editBtn) editBtn.setAttribute('onclick', `openEdit(${JSON.stringify(p).replace(/"/g, '&quot;')})`);
}

// ── Delete item ──────────────────────────────
let pendingDelete = null;
function confirmDelete(id, name) {
  pendingDelete = id;
  document.getElementById('del-msg').textContent = `Are you sure you want to delete "${name}"?`;
  document.getElementById('delete-modal').classList.add('open');
}
function closeDelete() {
  pendingDelete = null;
  document.getElementById('delete-modal').classList.remove('open');
}
function doDelete() {
  if (!pendingDelete) return;
  const id = pendingDelete;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);

  fetch('../api/add_item.php', { method: 'POST', body: fd })
    .then(response => response.json())
    .then(result => {
      if (!result.ok) throw new Error(result.error || 'Unable to delete item.');
      closeDelete();
      document.getElementById('prow-' + id)?.remove();
      showToast('✅ Item deleted.');
      applyFilters();
    })
    .catch(error => showToast('⚠️ ' + error.message, 'error'));
}


// ── Close modals on backdrop / Escape ──────────
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) { closeAdd(); closeEdit(); closeDelete(); closeAvail(); closeAddConfirm(); closeRecipe(); }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeAdd(); closeEdit(); closeDelete(); closeAvail(); closeAddConfirm(); closeRecipe(); }
});

// ── Recipe builder ──────────────────────────────
let recipeState = {
  productId: null,
  ingredientList: [],      // [{id, name, unit, cat_name}]
  recipe: { small: [], large: [] },
  activeSize: 'small',
};

function openRecipe(productId, productName) {
  recipeState.productId = productId;
  document.getElementById('recipe-item-name').textContent = productName;
  document.getElementById('recipe-rows').innerHTML = '<p style="font-size:12.5px;color:var(--text-muted)">Loading…</p>';
  document.getElementById('recipe-modal').classList.add('open');

  fetch(`../api/recipe.php?product_id=${productId}`)
    .then(r => r.json())
    .then(res => {
      if (!res.ok) { showToast('⚠️ ' + res.error, 'error'); closeRecipe(); return; }
      recipeState.ingredientList = res.ingredients;
      recipeState.recipe = res.recipe;
      recipeState.activeSize = 'small';
      renderRecipeRows();
    })
    .catch(() => { showToast('⚠️ Network error.', 'error'); closeRecipe(); });
}

function closeRecipe() {
  document.getElementById('recipe-modal').classList.remove('open');
}

function switchRecipeSize(size) {
  recipeState.activeSize = size;
  renderRecipeRows();
}

function renderRecipeRows() {
  document.getElementById('recipe-tab-small').style.background = recipeState.activeSize === 'small' ? 'var(--accent-lt)' : '';
  document.getElementById('recipe-tab-large').style.background = recipeState.activeSize === 'large' ? 'var(--accent-lt)' : '';

  const rows = recipeState.recipe[recipeState.activeSize] || [];
  const container = document.getElementById('recipe-rows');
  container.innerHTML = '';

  if (rows.length === 0) {
    document.getElementById('recipe-empty-msg').style.display = '';
  } else {
    document.getElementById('recipe-empty-msg').style.display = 'none';
    rows.forEach((row, i) => container.appendChild(buildRecipeRowEl(row.ingredient_id, row.qty_used, i)));
  }
}

function buildRecipeRowEl(selectedId, qty, index) {
  const wrap = document.createElement('div');
  wrap.className = 'field-row';
  wrap.style.alignItems = 'center';
  wrap.dataset.rowIndex = index;

  const options = recipeState.ingredientList.map(ing =>
    `<option value="${ing.id}" ${ing.id == selectedId ? 'selected' : ''}>${escapeHtml(ing.name)} (${escapeHtml(ing.unit)})</option>`
  ).join('');

  wrap.innerHTML = `
    <div class="field-group" style="flex:2">
      <select class="field-select recipe-ing-select">
        <option value="">— choose ingredient —</option>
        ${options}
      </select>
    </div>
    <div class="field-group" style="flex:1">
      <input class="field-input recipe-qty-input" type="number" step="0.01" min="0.01" placeholder="Qty used" value="${qty ?? ''}"/>
    </div>
    <button type="button" class="act-btn act-hold" style="height:38px" onclick="this.closest('.field-row').remove(); toggleRecipeEmptyMsg();">🗑️</button>
  `;
  return wrap;
}

function addRecipeRow() {
  document.getElementById('recipe-empty-msg').style.display = 'none';
  const container = document.getElementById('recipe-rows');
  container.appendChild(buildRecipeRowEl('', '', container.children.length));
}

function toggleRecipeEmptyMsg() {
  const container = document.getElementById('recipe-rows');
  document.getElementById('recipe-empty-msg').style.display = container.children.length === 0 ? '' : 'none';
}

function saveRecipe() {
  const rows = Array.from(document.querySelectorAll('#recipe-rows .field-row'));
  const ingredients = [];

  for (const row of rows) {
    const ingredientId = row.querySelector('.recipe-ing-select').value;
    const qty = row.querySelector('.recipe-qty-input').value;
    if (!ingredientId || !qty) continue; // skip incomplete rows silently
    if (parseFloat(qty) <= 0) {
      showToast('⚠️ Quantities must be greater than 0.', 'error');
      return;
    }
    ingredients.push({ ingredient_id: parseInt(ingredientId, 10), qty_used: parseFloat(qty) });
  }

  const btn = document.querySelector('#recipe-modal .btn-msave');
  KofeeValidator.setLoading(btn, 'Saving…');

  fetch('../api/recipe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      product_id: recipeState.productId,
      size: recipeState.activeSize,
      ingredients,
    }),
  })
    .then(r => r.json())
    .then(res => {
      KofeeValidator.resetLoading(btn, '💾 Save Recipe');
      if (!res.ok) { showToast('⚠️ ' + res.error, 'error'); return; }
      recipeState.recipe[recipeState.activeSize] = ingredients.map(i => ({
        ingredient_id: i.ingredient_id,
        qty_used: i.qty_used,
        name: (recipeState.ingredientList.find(x => x.id == i.ingredient_id) || {}).name || '',
        unit: (recipeState.ingredientList.find(x => x.id == i.ingredient_id) || {}).unit || '',
      }));
      showToast(`✅ ${recipeState.activeSize === 'small' ? 'Regular' : 'Up Size'} recipe saved!`);
    })
    .catch(() => {
      KofeeValidator.resetLoading(btn, '💾 Save Recipe');
      showToast('⚠️ Network error.', 'error');
    });
}

</script>

<script src="../js/validator.js"></script>
</body>
</html>