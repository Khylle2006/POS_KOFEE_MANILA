<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo   = get_db();
$toast = '';
$toast_type = 'success';

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add ingredient
    if ($action === 'add') {
        $cat_id   = (int)($_POST['cat_id']     ?? 0);
        $name     = trim($_POST['name']         ?? '');
        $brand    = trim($_POST['brand']        ?? '');
        $unit     = trim($_POST['unit']         ?? 'pcs');
        $quantity = (float)($_POST['quantity']  ?? 0);
        $reorder  = (float)($_POST['reorder_at']?? 5);

        if (!$cat_id || !$name) {
            $toast = '⚠️ Name and category are required.';
            $toast_type = 'error';
        } else {
            $pdo->prepare(
                'INSERT INTO ingredients (cat_id, name, brand, unit, quantity, reorder_at)
                 VALUES (:c,:n,:b,:u,:q,:r)'
            )->execute([':c'=>$cat_id,':n'=>$name,':b'=>$brand,':u'=>$unit,':q'=>$quantity,':r'=>$reorder]);
            $toast = '✅ "' . htmlspecialchars($name) . '" added!';
        }
    }

    // Restock (add to existing)
    if ($action === 'restock') {
        $id  = (int)($_POST['ingredient_id'] ?? 0);
        $qty = (float)($_POST['qty']         ?? 0);
        if ($id && $qty > 0) {
            $pdo->prepare('UPDATE ingredients SET quantity = quantity + :q WHERE id = :id')
                ->execute([':q'=>$qty,':id'=>$id]);
            $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
                ->execute([':i'=>$id,':q'=>$qty,':u'=>$_SESSION['user_id']]);
            $toast = '✅ Restocked successfully!';
        } else {
            $toast = '⚠️ Enter a valid quantity.';
            $toast_type = 'error';
        }
    }

    // Set stock (set exact value)
    if ($action === 'set_stock') {
        $id  = (int)($_POST['ingredient_id'] ?? 0);
        $qty = (float)($_POST['qty']         ?? -1);
        if ($id && $qty >= 0) {
            $pdo->prepare('UPDATE ingredients SET quantity = :q WHERE id = :id')
                ->execute([':q'=>$qty,':id'=>$id]);
            $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
                ->execute([':i'=>$id,':q'=>$qty,':u'=>$_SESSION['user_id']]);
            $toast = '✅ Stock set to ' . $qty . '!';
        } else {
            $toast = '⚠️ Enter a valid quantity (0 or more).';
            $toast_type = 'error';
        }
    }

    // Edit
    if ($action === 'edit') {
        $id      = (int)($_POST['ingredient_id'] ?? 0);
        $cat_id  = (int)($_POST['cat_id']        ?? 0);
        $name    = trim($_POST['name']            ?? '');
        $brand   = trim($_POST['brand']           ?? '');
        $unit    = trim($_POST['unit']            ?? 'pcs');
        $reorder = (float)($_POST['reorder_at']   ?? 5);
        if ($id && $name) {
            $pdo->prepare(
                'UPDATE ingredients SET name=:n, brand=:b, unit=:u, reorder_at=:r, cat_id=:c WHERE id=:id'
            )->execute([':n'=>$name,':b'=>$brand,':u'=>$unit,':r'=>$reorder,':c'=>$cat_id,':id'=>$id]);
            $toast = '✅ Item updated!';
        }
    }

    // Delete
    if ($action === 'delete') {
        $id = (int)($_POST['ingredient_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM ingredients WHERE id = :id')->execute([':id'=>$id]);
            $toast = '🗑️ Item deleted.';
        }
    }

    $self = dirname($_SERVER['PHP_SELF']) . '/inventory.php';
    $q    = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: ' . $self . $q);
    exit;
}

// Flash from redirect
if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Load categories ───────────────────────────
$cats = $pdo->query('SELECT * FROM ingredient_categories ORDER BY name')->fetchAll();

// ── Load ingredients ──────────────────────────
$filter_cat = (int)($_GET['cat'] ?? 0);
$search     = trim($_GET['search'] ?? '');

$where  = '1=1';
$params = [];
if ($filter_cat) {
    $where .= ' AND i.cat_id = :c';
    $params[':c'] = $filter_cat;
}
if ($search) {
    $where .= ' AND (i.name LIKE :s OR i.brand LIKE :s2)';
    $params[':s']  = "%$search%";
    $params[':s2'] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT i.*, ic.name AS cat_name, ic.icon AS cat_icon
    FROM ingredients i
    JOIN ingredient_categories ic ON ic.id = i.cat_id
    WHERE $where
    ORDER BY ic.name, i.name
");
$stmt->execute($params);
$ingredients = $stmt->fetchAll();

// Group by category
$grouped = [];
foreach ($ingredients as $ing) {
    $grouped[$ing['cat_name']][] = $ing;
}

// Stats
$total   = count($ingredients);
$low     = count(array_filter($ingredients, fn($i) => $i['quantity'] > 0 && $i['quantity'] <= $i['reorder_at']));
$out     = count(array_filter($ingredients, fn($i) => $i['quantity'] <= 0));
$ok      = $total - $low - $out;

// Last update
$last_update = $pdo->query("SELECT MAX(updated_at) FROM ingredients")->fetchColumn();
?>
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

<?php include('../includes/sidebar.php'); ?>

<div id="page-inventory" class="page active">
  <div class="page-header">
    <div>
      <h1>Inventory</h1>
      <p>Manage your ingredient stocks</p>
    </div>
    <button class="btn-add" onclick="openAdd()">➕ Add Item</button>
  </div>

  <div class="inv-body">

    <!-- Top row: stats + last update -->
    <div class="inv-top">
      <div class="stat-pills">
        <div class="stat-pill"><div class="dot" style="background:#4caf50"></div><?= $ok ?> In stock</div>
        <div class="stat-pill"><div class="dot" style="background:#ff9800"></div><?= $low ?> Low stock</div>
        <div class="stat-pill"><div class="dot" style="background:#f44336"></div><?= $out ?> Out of stock</div>
      </div>
      <div class="last-update">
        🕐 Last updated:
        <strong><?= $last_update ? date('M d, Y g:i A', strtotime($last_update)) : 'Never' ?></strong>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <a href="inventory.php<?= $search ? '?search='.urlencode($search) : '' ?>"
         class="filter-pill <?= !$filter_cat ? 'active' : '' ?>">All</a>
      <?php foreach ($cats as $cat): ?>
        <a href="inventory.php?cat=<?= $cat['id'] ?><?= $search ? '&search='.urlencode($search) : '' ?>"
           class="filter-pill <?= $filter_cat === (int)$cat['id'] ? 'active' : '' ?>">
          <?= $cat['icon'] ?> <?= htmlspecialchars($cat['name']) ?>
        </a>
      <?php endforeach; ?>
      <div class="search-wrap">
        <span class="si">🔍</span>
        <form method="GET" style="display:contents">
          <?php if ($filter_cat): ?><input type="hidden" name="cat" value="<?= $filter_cat ?>"><?php endif; ?>
          <input type="text" name="search" placeholder="Search…"
                 value="<?= htmlspecialchars($search) ?>"
                 onchange="this.form.submit()"/>
        </form>
      </div>
    </div>

    <!-- Main split -->
    <div class="inv-split">

      <!-- LEFT: list -->
      <div class="inv-list-card">
        <div class="inv-list-scroll">
          <?php if (empty($grouped)): ?>
            <div class="empty-list">🫙 No ingredients yet.<br>Click ➕ Add Item to get started.</div>
          <?php else: ?>
            <?php foreach ($grouped as $cat_name => $items): ?>
              <div>
                <div class="cat-label">
                  <?= htmlspecialchars($items[0]['cat_icon']) ?>
                  <?= htmlspecialchars(strtoupper($cat_name)) ?>
                </div>
                <?php foreach ($items as $ing):
                  $status = $ing['quantity'] <= 0 ? 'out'
                          : ($ing['quantity'] <= $ing['reorder_at'] ? 'low' : 'ok');
                  $dot    = ['ok'=>'dot-ok','low'=>'dot-low','out'=>'dot-out'][$status];
                ?>
                <div class="ing-row" id="row-<?= $ing['id'] ?>"
                     onclick="selectItem(<?= htmlspecialchars(json_encode($ing), ENT_QUOTES) ?>)">
                  <div class="ing-icon-wrap"><?= $ing['cat_icon'] ?></div>
                  <div class="ing-info">
                    <div class="ing-name"><?= htmlspecialchars($ing['name']) ?></div>
                    <div class="ing-meta">
                      <?= htmlspecialchars($ing['brand'] ?? '—') ?> ·
                      <?= number_format($ing['quantity'],1) ?> <?= htmlspecialchars($ing['unit']) ?>
                    </div>
                  </div>
                  <div class="ing-dot <?= $dot ?>"></div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: detail -->
      <div class="inv-detail" id="detail-panel">
        <div class="detail-empty">
          <div class="de-icon">📦</div>
          <p>Select an ingredient</p>
          <small>Click any item on the left to view stock and restock</small>
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
      <input type="hidden" name="action"        id="f-action" value="add"/>
      <input type="hidden" name="ingredient_id" id="f-ing-id" value=""/>

      <div class="mfield">
        <label>Category <span style="color:#c62828">*</span></label>
        <select name="cat_id" id="f-cat" required>
          <option value="">Select…</option>
          <?php foreach ($cats as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= $cat['icon'] ?> <?= htmlspecialchars($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mfield">
        <label>Name <span style="color:#c62828">*</span></label>
        <input type="text" name="name" id="f-name" placeholder="e.g. Fresh milk" required/>
      </div>

      <div class="mfield-row">
        <div class="mfield">
          <label>Brand</label>
          <input type="text" name="brand" id="f-brand" placeholder="e.g. Nestle"/>
        </div>
        <div class="mfield">
          <label>Unit</label>
          <input type="text" name="unit" id="f-unit" placeholder="L / kg / pcs"/>
        </div>
      </div>

      <div class="mfield-row" id="qty-row">
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
        <button type="submit" class="btn-msave"   id="modal-save-btn">➕ Add Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal" style="max-width:340px;text-align:center">
    <div style="font-size:44px;margin-bottom:12px">🗑️</div>
    <h3 style="margin-bottom:8px">Delete Item?</h3>
    <p id="del-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:20px"></p>
    <form method="POST">
      <input type="hidden" name="action"        value="delete"/>
      <input type="hidden" name="ingredient_id" id="del-id"/>
      <div class="modal-footer">
        <button type="button" class="btn-mcancel" onclick="closeDelete()">Cancel</button>
        <button type="submit" class="btn-msave"   style="background:#c62828">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-el"><?= $toast ?></div>
<script>setTimeout(()=>{ const t=document.getElementById('toast-el'); if(t) t.style.opacity='0'; },3000);</script>
<?php endif; ?>

<script>
// ── Select item ───────────────────────────────
function selectItem(ing) {
  document.querySelectorAll('.ing-row').forEach(r => r.classList.remove('active'));
  const row = document.getElementById('row-' + ing.id);
  if (row) row.classList.add('active');

  const qty     = parseFloat(ing.quantity);
  const reorder = parseFloat(ing.reorder_at);
  const isOut   = qty <= 0;
  const isLow   = !isOut && qty <= reorder;
  const scls    = isOut ? 'badge-out' : isLow ? 'badge-low' : 'badge-ok';
  const stxt    = isOut ? '🔴 Out of stock' : isLow ? '⚠️ Low stock' : '✅ In stock';
  const scolor  = isOut ? 'var(--red)' : isLow ? 'var(--amber)' : 'var(--accent)';

  document.getElementById('detail-panel').innerHTML = `
    <div class="detail-head">
      <div class="detail-icon">${esc(ing.cat_icon)}</div>
      <div class="detail-title">
        <h2>${esc(ing.name)}</h2>
        <p>${esc(ing.brand||'—')} · ${esc(ing.cat_name)} · ${esc(ing.unit)}</p>
      </div>
      <span class="status-badge ${scls}">${stxt}</span>
    </div>

    <div class="stock-display">
      <div class="stock-label">Current Stock</div>
      <div>
        <span class="stock-num" style="color:${scolor}">${qty.toFixed(1)}</span>
        <span class="stock-unit">${esc(ing.unit)}</span>
      </div>
      <div class="reorder-hint">Reorder point: ${reorder.toFixed(1)} ${esc(ing.unit)}</div>
    </div>

    <div class="restock-section">
      <h3>➕ Add to Stock</h3>
      <form method="POST">
        <input type="hidden" name="action"        value="restock"/>
        <input type="hidden" name="ingredient_id" value="${ing.id}"/>
        <div class="restock-row">
          <input type="number" name="qty"
                 placeholder="Add quantity (${esc(ing.unit)})"
                 step="0.1" min="0.1" required/>
          <button type="submit" class="btn-confirm">➕ Add</button>
        </div>
      </form>
    </div>

    <div class="restock-section">
      <h3>✏️ Set Exact Stock</h3>
      <form method="POST">
        <input type="hidden" name="action"        value="set_stock"/>
        <input type="hidden" name="ingredient_id" value="${ing.id}"/>
        <div class="restock-row">
          <input type="number" name="qty"
                 placeholder="Set stock to… (${esc(ing.unit)})"
                 value="${qty.toFixed(1)}"
                 step="0.1" min="0" required/>
          <button type="submit" class="btn-confirm" style="background:#1565c0">✔ Set</button>
        </div>
      </form>
    </div>

    <div class="detail-actions">
      <button class="btn-edit-ing" onclick='openEdit(${JSON.stringify(ing)})'>✏️ Edit Details</button>
      <button class="btn-del-ing"  onclick="openDelete(${ing.id},'${esc(ing.name)}')">🗑️ Delete</button>
    </div>
  `;
}

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Add modal ─────────────────────────────────
function openAdd() {
  document.getElementById('modal-title').textContent    = '➕ Add Ingredient';
  document.getElementById('modal-save-btn').textContent = '➕ Add Item';
  document.getElementById('f-action').value  = 'add';
  document.getElementById('f-ing-id').value  = '';
  document.getElementById('f-cat').value     = '';
  document.getElementById('f-name').value    = '';
  document.getElementById('f-brand').value   = '';
  document.getElementById('f-unit').value    = '';
  document.getElementById('f-qty').value     = '';
  document.getElementById('f-reorder').value = '';
  document.getElementById('qty-row').style.display = '';
  document.getElementById('item-modal').classList.add('open');
}

// ── Edit modal ────────────────────────────────
function openEdit(ing) {
  document.getElementById('modal-title').textContent    = '✏️ Edit Ingredient';
  document.getElementById('modal-save-btn').textContent = '💾 Save Changes';
  document.getElementById('f-action').value  = 'edit';
  document.getElementById('f-ing-id').value  = ing.id;
  document.getElementById('f-cat').value     = ing.cat_id;
  document.getElementById('f-name').value    = ing.name;
  document.getElementById('f-brand').value   = ing.brand || '';
  document.getElementById('f-unit').value    = ing.unit;
  document.getElementById('f-reorder').value = ing.reorder_at;
  document.getElementById('qty-row').style.display = 'none'; // use restock for qty
  document.getElementById('item-modal').classList.add('open');
}

function closeModal() {
  document.getElementById('item-modal').classList.remove('open');
  document.getElementById('qty-row').style.display = '';
}

// ── Delete modal ──────────────────────────────
function openDelete(id, name) {
  document.getElementById('del-id').value  = id;
  document.getElementById('del-msg').textContent = 'This will permanently remove "' + name + '".';
  document.getElementById('delete-modal').classList.add('open');
}
function closeDelete() { document.getElementById('delete-modal').classList.remove('open'); }

// Backdrop / Escape
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target===el){ closeModal(); closeDelete(); } });
});
document.addEventListener('keydown', e => {
  if (e.key==='Escape'){ closeModal(); closeDelete(); }
});
</script>

</body>
</html>