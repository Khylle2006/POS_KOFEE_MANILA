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

    // Writes/toggling need menu.edit; deletion needs the stronger menu.delete.
    if (in_array($action, ['add', 'edit', 'toggle'], true) && !has_permission('menu.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit menu items.']);
        exit;
    }
    if ($action === 'delete' && !has_permission('menu.delete')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to delete menu items.']);
        exit;
    }

    // ── Add ───────────────────────────────────
    if ($action === 'add') {
        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']           ?? '');
        $desc        = trim($_POST['description']    ?? '');
        $price_small = (float)($_POST['price_small'] ?? 0);
        $price_large = (float)($_POST['price_large'] ?? 0);

        if (!$cat_id)          { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Please select a category.']); exit; }
        if ($name === '')      { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Please enter a drink name.']); exit; }
        if ($price_small <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Enter a valid regular price.']); exit; }
        if ($price_large <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Enter a valid up-size price.']); exit; }

        try {
            $pdo->prepare(
                'INSERT INTO products (name, description, price_small, price_large, category_id, stock)
                 VALUES (:n, :d, :ps, :pl, :c, 1)'
            )->execute([':n'=>$name,':d'=>$desc,':ps'=>$price_small,':pl'=>$price_large,':c'=>(string)$cat_id]);
            echo json_encode(['ok'=>true,'name'=>$name,'id'=>$pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>'DB error: '.$e->getMessage()]);
        }
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
  <link rel="stylesheet" href="../css/add_items.css"/>

</head>
<body>

<div id="page-addmenu" class="page active">
  <div class="page-header">
    <div>
      <h1>Menu Manager</h1>
      <p>Add drinks and manage your menu</p>
    </div>
  </div>

  <div class="page-body">

    <!-- ── LEFT: Add form ── -->
    <div class="add-col">

      <div class="form-card">
        <h2>➕ Add New Item</h2>

        <div class="field-group">
          <label class="field-label">Category <span class="req">*</span></label>
          <select class="field-select" id="add-category">
            <option value="">Select a category…</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field-group">
          <label class="field-label">Drink Name <span class="req">*</span></label>
          <input class="field-input" type="text" id="add-name" placeholder="e.g. Taro Milk Tea"/>
        </div>

        <div class="field-group">
          <label class="field-label">Description <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
          <textarea class="field-textarea" id="add-desc" placeholder="Brief description…"></textarea>
        </div>
      </div>

      <div class="form-card">
        <h2>💰 Pricing</h2>
        <div class="two-col">
          <div class="field-group">
            <label class="field-label">Regular (₱) <span class="req">*</span></label>
            <input class="field-input" type="number" id="add-price-small" placeholder="0" min="1" step="0.01"/>
          </div>
          <div class="field-group">
            <label class="field-label">Up Size (₱) <span class="req">*</span></label>
            <input class="field-input" type="number" id="add-price-large" placeholder="0" min="1" step="0.01"/>
          </div>
        </div>

        <div class="add-msg" id="add-msg"></div>
        <button class="submit-btn" onclick="addMenuItem()">➕ Add to Menu</button>
      </div>

    </div>

    <!-- ── RIGHT: Product list ── -->
    <div class="menu-col">

      <div class="menu-col-header">
        <h2>All Menu Items <span id="product-count" style="font-size:13px;color:var(--text-muted);font-weight:500"></span></h2>
        <div class="search-wrap">
          <span class="si">🔍</span>
          <input class="search-input" type="text" id="search-products" placeholder="Search items…" oninput="filterProducts(this.value)"/>
        </div>
      </div>

      <div id="product-list">
        <?php if (empty($grouped)): ?>
          <div class="empty-list">🫙 No menu items yet — add your first drink!</div>
        <?php else: ?>
          <?php
          $cat_icons = ['Ice Coffee'=>'🧊','Hot Coffee'=>'☕','Milk Tea'=>'🧋','Fruit Tea'=>'🍹'];
          foreach ($grouped as $cat_name => $items):
            $icon = $cat_icons[$cat_name] ?? '🥤';
          ?>
          <div class="cat-section" data-cat="<?= htmlspecialchars($cat_name) ?>">
            <div class="cat-label"><?= $icon ?> <?= htmlspecialchars($cat_name) ?> (<?= count($items) ?>)</div>
            <?php foreach ($items as $p):
              $available = (int)$p['stock'] > 0;
            ?>
            <div class="product-card <?= $available ? '' : 'unavailable' ?>" id="pcard-<?= $p['id'] ?>">
              <div class="prod-icon"><?= $icon ?></div>
              <div class="prod-info">
                <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="prod-cat"><?= htmlspecialchars($cat_name) ?></div>
                <div class="prod-price">
                  Regular ₱<?= number_format($p['price_small'],2) ?> · Up Size ₱<?= number_format($p['price_large'],2) ?>
                </div>
              </div>
              <button class="avail-toggle <?= $available ? 'on' : 'off' ?>"
                      id="toggle-<?= $p['id'] ?>"
                      onclick="toggleAvail(<?= $p['id'] ?>, this)">
                <?= $available ? '✅ Available' : '❌ Unavailable' ?>
              </button>
              <div class="prod-actions">
                <button class="btn-edit-prod"
                  onclick='openEdit(<?= htmlspecialchars(json_encode([
                    "id"          => $p["id"],
                    "name"        => $p["name"],
                    "description" => $p["description"],
                    "price_small" => $p["price_small"],
                    "price_large" => $p["price_large"],
                    "category_id" => $p["category_id"],
                  ]), ENT_QUOTES) ?>)'>✏️ Edit</button>
              
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

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
    <input type="hidden" id="e-id"/>
    <div class="field-group" style="margin-bottom:12px">
      <label class="field-label">Category</label>
      <select class="field-select" id="e-category">
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field-group" style="margin-bottom:12px">
      <label class="field-label">Drink Name</label>
      <input class="field-input" type="text" id="e-name"/>
    </div>
    <div class="field-group" style="margin-bottom:12px">
      <label class="field-label">Description</label>
      <textarea class="field-textarea" id="e-desc"></textarea>
    </div>
    <div class="two-col" style="margin-bottom:4px">
      <div class="field-group">
        <label class="field-label">Regular Price (₱)</label>
        <input class="field-input" type="number" id="e-price-small" step="0.01" min="0"/>
      </div>
      <div class="field-group">
        <label class="field-label">Up Size Price (₱)</label>
        <input class="field-input" type="number" id="e-price-large" step="0.01" min="0"/>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeEdit()">Cancel</button>
      <button class="btn-msave"   onclick="saveEdit()">💾 Save Changes</button>
    </div>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal del-modal">
    <div style="font-size:46px;margin-bottom:12px">🗑️</div>
    <h3 style="font-size:17px;margin-bottom:8px">Delete Item?</h3>
    <p id="del-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:22px"></p>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-msave" style="background:var(--red)" onclick="doDelete()">Yes, Delete</button>
    </div>
  </div>
</div>

<!-- Availability confirm modal -->
<div class="modal-overlay" id="avail-modal">
  <div class="modal del-modal">
    <div style="font-size:46px;margin-bottom:12px" id="avail-icon">❓</div>
    <h3 style="font-size:17px;margin-bottom:8px" id="avail-title">Change Availability?</h3>
    <p id="avail-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:22px"></p>
    <div class="modal-actions">
      <button class="btn-mcancel" onclick="closeAvail()">Cancel</button>
      <button class="btn-msave" id="avail-confirm-btn" onclick="doToggleAvail()">Yes, Confirm</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast" style="display:none"></div>

<script>
const SELF = window.location.pathname; // posts back to same file

// ── Toast ─────────────────────────────────────
let toastTimer;
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent  = msg;
  t.className    = 'toast toast-' + type;
  t.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.style.display='none', 3000);
}

// ── Add item ──────────────────────────────────
function addMenuItem() {
  const cat   = document.getElementById('add-category').value;
  const name  = document.getElementById('add-name').value.trim();
  const desc  = document.getElementById('add-desc').value.trim();
  const ps    = document.getElementById('add-price-small').value;
  const pl    = document.getElementById('add-price-large').value;
  const msg   = document.getElementById('add-msg');

  msg.className = 'add-msg';

  const fd = new FormData();
  fd.append('action',      'add');
  fd.append('category_id', cat);
  fd.append('name',        name);
  fd.append('description', desc);
  fd.append('price_small', ps);
  fd.append('price_large', pl);

  fetch(SELF, { method:'POST', body:fd })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      msg.textContent = '✅ "' + res.name + '" added!';
      msg.className   = 'add-msg success';

      addProductCardToDOM({
        id: res.id,
        name: res.name,
        description: desc,
        price_small: ps,
        price_large: pl,
        category_id: cat,
      });

      // Reset form
      document.getElementById('add-category').value    = '';
      document.getElementById('add-name').value        = '';
      document.getElementById('add-desc').value        = '';
      document.getElementById('add-price-small').value = '';
      document.getElementById('add-price-large').value = '';
      showToast('✅ "' + res.name + '" added to menu!');
    } else {
      msg.textContent = '⚠️ ' + res.error;
      msg.className   = 'add-msg error';
    }
  })
  .catch(() => {
    msg.textContent = '⚠️ Network error. Try again.';
    msg.className   = 'add-msg error';
  });
}

function addProductCardToDOM(p) {
  const catSelect = document.getElementById('add-category');
  const catName   = catSelect.options[catSelect.selectedIndex].textContent;
  const icons     = { 'Ice Coffee':'🧊','Hot Coffee':'☕','Milk Tea':'🧋','Fruit Tea':'🍹' };
  const icon      = icons[catName] || '🥤';

  let section = document.querySelector(`.cat-section[data-cat="${CSS.escape(catName)}"]`);

  if (!section) {
    section = document.createElement('div');
    section.className = 'cat-section';
    section.dataset.cat = catName;
    section.innerHTML = `<div class="cat-label">${icon} ${catName} (0)</div>`;
    document.getElementById('product-list').appendChild(section);
    document.querySelector('.empty-list')?.remove();
  }

  const card = document.createElement('div');
  card.className = 'product-card';
  card.id = 'pcard-' + p.id;
  card.innerHTML = `
    <div class="prod-icon">${icon}</div>
    <div class="prod-info">
      <div class="prod-name">${escapeHtml(p.name)}</div>
      <div class="prod-cat">${escapeHtml(catName)}</div>
      <div class="prod-price">Regular ₱${parseFloat(p.price_small).toFixed(2)} · Up Size ₱${parseFloat(p.price_large).toFixed(2)}</div>
    </div>
    <button class="avail-toggle on" id="toggle-${p.id}" onclick="toggleAvail(${p.id}, this)">✅ Available</button>
    <div class="prod-actions">
      <button class="btn-edit-prod" onclick='openEdit(${JSON.stringify(p)})'>✏️ Edit</button>
      <button class="btn-del-prod" onclick="confirmDelete(${p.id}, ${JSON.stringify(p.name)})">🗑️</button>
    </div>
  `;
  section.appendChild(card);

  // Update count badge
  const count = section.querySelectorAll('.product-card').length;
  section.querySelector('.cat-label').textContent = `${icon} ${catName} (${count})`;

  const totalCount = document.querySelectorAll('.product-card').length;
  document.getElementById('product-count').textContent = `(${totalCount} items)`;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

// ── Toggle availability ───────────────────────
// ── Toggle availability ───────────────────────
let pendingToggle = null; // { id, btn, makingAvailable }

function toggleAvail(id, btn) {
  const makingAvailable = btn.classList.contains('off'); // currently off -> about to turn on

  pendingToggle = { id, btn, makingAvailable };

  const card     = document.getElementById('pcard-' + id);
  const itemName = card.querySelector('.prod-name').textContent;

  document.getElementById('avail-icon').textContent  = makingAvailable ? '✅' : '❌';
  document.getElementById('avail-title').textContent = makingAvailable
    ? 'Mark as Available?'
    : 'Mark as Unavailable?';
  document.getElementById('avail-msg').textContent = makingAvailable
    ? `"${itemName}" will be visible and orderable again.`
    : `"${itemName}" will be hidden from ordering until re-enabled.`;

  const confirmBtn = document.getElementById('avail-confirm-btn');
  confirmBtn.style.background = makingAvailable ? '' : 'var(--red)';

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

  fetch(SELF, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        const card = document.getElementById('pcard-' + id);
        if (res.available) {
          btn.textContent = '✅ Available';
          btn.className   = 'avail-toggle on';
          card.classList.remove('unavailable');
          showToast('✅ Item set to Available');
        } else {
          btn.textContent = '❌ Unavailable';
          btn.className   = 'avail-toggle off';
          card.classList.add('unavailable');
          showToast('❌ Item set to Unavailable', 'error');
        }
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

  fetch(SELF, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        closeEdit();
        showToast('✅ Item updated!');
        updateProductCardInDOM(id, {
          name: document.getElementById('e-name').value,
          price_small: document.getElementById('e-price-small').value,
          price_large: document.getElementById('e-price-large').value,
        });
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    });
}

function updateProductCardInDOM(id, p) {
  const card = document.getElementById('pcard-' + id);
  if (!card) return;
  card.querySelector('.prod-name').textContent = p.name;
  card.querySelector('.prod-price').textContent =
    `Regular ₱${parseFloat(p.price_small).toFixed(2)} · Up Size ₱${parseFloat(p.price_large).toFixed(2)}`;
  // Update the edit button's stored data too
  const editBtn = card.querySelector('.btn-edit-prod');
  const currentData = JSON.parse(editBtn.getAttribute('onclick').match(/openEdit\((.*)\)/)[1]);
  Object.assign(currentData, p);
  editBtn.setAttribute('onclick', `openEdit(${JSON.stringify(currentData).replace(/"/g, '&quot;')})`);
}
// ── Delete ────────────────────────────────────
let deleteId = null;
function confirmDelete(id, name) {
  deleteId = id;
  document.getElementById('del-msg').textContent =
    'This will permanently remove "' + name + '" from your menu.';
  document.getElementById('delete-modal').classList.add('open');
}
function closeDelete() { document.getElementById('delete-modal').classList.remove('open'); deleteId=null; }

function doDelete() {
  if (!deleteId) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', deleteId);
  fetch(SELF, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        closeDelete();
        const card = document.getElementById('pcard-' + deleteId);
        if (card) card.remove();
        showToast('🗑️ Item deleted.');
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    });
}

// ── Search ────────────────────────────────────
function filterProducts(val) {
  const q = val.toLowerCase();
  document.querySelectorAll('.product-card').forEach(card => {
    const name = card.querySelector('.prod-name').textContent.toLowerCase();
    card.style.display = name.includes(q) ? '' : 'none';
  });
  // Hide empty category sections
  document.querySelectorAll('.cat-section').forEach(sec => {
    const visible = [...sec.querySelectorAll('.product-card')]
      .some(c => c.style.display !== 'none');
    sec.style.display = visible ? '' : 'none';
  });
}

// Close modals on backdrop / Escape

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) { closeEdit(); closeDelete(); closeAvail(); }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeEdit(); closeDelete(); closeAvail(); }
});

// Product count
document.addEventListener('DOMContentLoaded', () => {
  const count = document.querySelectorAll('.product-card').length;
  document.getElementById('product-count').textContent = '(' + count + ' items)';
});
</script>

</body>
</html>