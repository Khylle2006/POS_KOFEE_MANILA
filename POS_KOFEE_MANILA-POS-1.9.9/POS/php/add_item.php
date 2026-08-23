<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();
require_permission('menu.manage');


$pdo = get_db();

// ── POST: Add new item ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    header('Content-Type: application/json');

    // Adding new items is Inventory's job now — the Menu module only edits/manages
    // existing drinks. Block it here too (not just in the UI) so the endpoint
    // can't be posted to directly.
     if ($action === 'add') {
        if (!has_permission('menu.edit')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to add menu items.']);
            exit;
        }

        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']           ?? '');
        $desc        = trim($_POST['description']    ?? '');
        $price_small = (float)($_POST['price_small'] ?? 0);
        $price_large = (float)($_POST['price_large'] ?? 0);

        if (!$cat_id || !$name) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Category and name are required.']);
            exit;
        }

        try {
            $pdo->prepare("
                INSERT INTO products (category_id, name, description, price_small, price_large, stock)
                VALUES (:cat, :name, :desc, :ps, :pl, 1)
            ")->execute([
                ':cat'  => $cat_id,
                ':name' => $name,
                ':desc' => $desc,
                ':ps'   => $price_small,
                ':pl'   => $price_large,
            ]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Writes/toggling need menu.edit; deletion needs the stronger menu.delete.
    if (in_array($action, ['edit', 'toggle'], true) && !has_permission('menu.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit menu items.']);
        exit;
    }
    if ($action === 'delete' && !has_permission('menu.delete')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to delete menu items.']);
        exit;
    }

    // ── Edit ──────────────────────────────────
    if ($action === 'edit') {
        $id          = (int)($_POST['id']           ?? 0);
        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']          ?? '');
        $desc        = trim($_POST['description']   ?? '');
        $price_small = (float)($_POST['price_small']?? 0);
        $price_large = (float)($_POST['price_large']?? 0);

        if (!$id || !$name) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Missing fields.']); exit; }

        try {
            $pdo->prepare(
                'UPDATE products SET name=:n, description=:d, price_small=:ps, price_large=:pl, category_id=:c WHERE id=:id'
            )->execute([':n'=>$name,':d'=>$desc,':ps'=>$price_small,':pl'=>$price_large,':c'=>(string)$cat_id,':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Toggle availability ───────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        // stock=1 means available, stock=0 means unavailable
        try {
            $curr = $pdo->prepare('SELECT stock FROM products WHERE id=:id');
            $curr->execute([':id'=>$id]);
            $row = $curr->fetch();
            $new_stock = ($row['stock'] > 0) ? 0 : 1;
            $pdo->prepare('UPDATE products SET stock=:s WHERE id=:id')
                ->execute([':s'=>$new_stock,':id'=>$id]);
            echo json_encode(['ok'=>true,'available'=>$new_stock > 0]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Delete ────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM products WHERE id=:id')->execute([':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
}

// ── Load categories ───────────────────────────
$categories = $pdo->query('SELECT id, category_name FROM categories ORDER BY category_name')->fetchAll();

// ── Load all products ─────────────────────────
$products = $pdo->query("
    SELECT p.*, c.category_name
    FROM products p
    LEFT JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
    ORDER BY c.category_name, p.name
")->fetchAll();

// Group by category
$grouped = [];
foreach ($products as $p) {
    $grouped[$p['category_name']][] = $p;
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Menu Manager — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
</head>
<body>

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
                  <?php endif; ?>
                  <?php if (has_permission('menu.delete')): ?>
                  <button class="act-btn act-block" onclick="confirmDelete(<?= $p['id'] ?>, <?= json_encode($p['name']) ?>)">🗑️</button>
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
  <div class="modal">
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
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeAdd()">Cancel</button>
      <button class="btn-msave" onclick="addMenuItem()">💾 Save Item</button>
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

<!-- Toast -->
<div class="toast" id="toast" style="display:none"></div>

<script>

function openAdd() {
  document.getElementById('add-category').value    = '';
  document.getElementById('add-name').value        = '';
  document.getElementById('add-desc').value        = '';
  document.getElementById('add-price-small').value = '';
  document.getElementById('add-price-large').value = '';
  document.getElementById('add-modal').classList.add('open');
}
function closeAdd() { document.getElementById('add-modal').classList.remove('open'); }

function addMenuItem() {
  const name = document.getElementById('add-name').value.trim();
  if (!name) { showToast('⚠️ Name is required.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',      'add');
  fd.append('category_id', document.getElementById('add-category').value);
  fd.append('name',        name);
  fd.append('description', document.getElementById('add-desc').value);
  fd.append('price_small', document.getElementById('add-price-small').value);
  fd.append('price_large', document.getElementById('add-price-large').value);

  fetch(SELF, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        showToast('✅ Item added!');
        closeAdd();
        location.reload(); // simplest way to show the new row with correct id/data
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    })
    .catch(() => showToast('⚠️ Network error.', 'error'));
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

  fetch(SELF, { method: 'POST', body: fd })
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
  document.getElementById('edit-modal').classList.add('open');
}
function closeEdit() { document.getElementById('edit-modal').classList.remove('open'); }

function saveEdit() {
  const id = document.getElementById('e-id').value;
  const fd = new FormData();
  fd.append('action',      'edit');
  fd.append('id',          id);
  fd.append('category_id', document.getElementById('e-category').value);
  fd.append('name',        document.getElementById('e-name').value);
  fd.append('description', document.getElementById('e-desc').value);
  fd.append('price_small', document.getElementById('e-price-small').value);
  fd.append('price_large', document.getElementById('e-price-large').value);

  fetch(SELF, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        closeEdit();
        showToast('✅ Item updated!');
        updateRowInDOM(id, {
          name: document.getElementById('e-name').value,
          description: document.getElementById('e-desc').value,
          price_small: document.getElementById('e-price-small').value,
          price_large: document.getElementById('e-price-large').value,
          category_id: document.getElementById('e-category').value,
        });
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
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

// ── Delete ────────────────────────────────────
let deleteId = null;
function confirmDelete(id, name) {
  deleteId = id;
  document.getElementById('del-msg').textContent = 'This will permanently remove "' + name + '" from your menu.';
  document.getElementById('delete-modal').classList.add('open');
}
function closeDelete() { document.getElementById('delete-modal').classList.remove('open'); deleteId = null; }

function doDelete() {
  if (!deleteId) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', deleteId);
  fetch(SELF, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        closeDelete();
        document.getElementById('prow-' + deleteId)?.remove();
        if (!document.querySelector('.menu-row')) {
          document.getElementById('menu-tbody').innerHTML =
            '<tr class="empty-row"><td colspan="5">🫙 No menu items yet — add drinks from the Inventory module first.</td></tr>';
        }
        applyFilters();
        showToast('🗑️ Item deleted.');
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    });
}

// ── Close modals on backdrop / Escape ──────────
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) { closeAdd(); closeEdit(); closeDelete(); closeAvail(); }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeAdd(); closeEdit(); closeDelete(); closeAvail(); }
});


</script>

</body>
</html>