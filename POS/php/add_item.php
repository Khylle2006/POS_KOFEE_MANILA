<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role('admin');

$pdo = get_db();

// ── POST: Add new item ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    header('Content-Type: application/json');

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
  <style>
    :root {
      --accent:     #c47d3e; --accent-lt:  #fdf3ea;
      --card-bg:    #ffffff; --border:     #ecddc8;
      --text-main:  #2c1a0e; --text-muted: #9a7e65;
      --bg:         #faf5ef; --cream:      #fdf6ec;
      --green:      #2e7d32; --green-lt:   #e8f5e9;
      --red:        #c62828; --red-lt:     #ffebee;
      --amber:      #e65100; --amber-lt:   #fff3e0;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    #page-addmenu { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
    .page-header  { padding: 22px 28px 0; flex-shrink: 0; }
    .page-header h1 { font-size: 22px; font-weight: 800; }
    .page-header p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
    .page-body { flex: 1; overflow: hidden; padding: 18px 28px 24px; display: flex; gap: 20px; }

    /* ── LEFT: Add form ── */
    .add-col { width: 340px; flex-shrink: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }

    .form-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 18px; padding: 24px;
      display: flex; flex-direction: column; gap: 14px;
    }
    .form-card h2 { font-size: 14px; font-weight: 700; }

    .field-group  { display: flex; flex-direction: column; gap: 5px; }
    .field-label  { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; }
    .req { color: var(--red); }

    .field-input, .field-select, .field-textarea {
      padding: 10px 13px;
      border: 1.5px solid var(--border); border-radius: 10px;
      font-family: 'Poppins', sans-serif; font-size: 13px;
      background: var(--cream); color: var(--text-main);
      outline: none; transition: border-color .15s; width: 100%;
    }
    .field-input:focus, .field-select:focus, .field-textarea:focus {
      border-color: var(--accent); background: #fff;
    }
    .field-textarea { resize: vertical; min-height: 70px; }

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .submit-btn {
      width: 100%; padding: 13px; background: var(--accent); color: #fff;
      border: none; border-radius: 13px;
      font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700;
      cursor: pointer; transition: background .15s, transform .12s;
    }
    .submit-btn:hover  { background: #7a4e2e; transform: translateY(-1px); }
    .submit-btn:active { transform: scale(.98); }

    .add-msg {
      display: none; padding: 10px 14px; border-radius: 10px;
      font-size: 12px; font-weight: 600;
    }
    .add-msg.success { background: var(--green-lt); color: var(--green); border: 1px solid #c8e6c9; display: block; }
    .add-msg.error   { background: var(--red-lt);   color: var(--red);   border: 1px solid #ffcdd2; display: block; }

    /* ── RIGHT: Product list ── */
    .menu-col { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }

    .menu-col-header {
      display: flex; align-items: center; gap: 10px; flex-shrink: 0;
    }
    .menu-col-header h2 { font-size: 15px; font-weight: 700; flex: 1; }
    .search-input {
      padding: 8px 14px 8px 32px;
      border: 1.5px solid var(--border); border-radius: 20px;
      font-family: 'Poppins', sans-serif; font-size: 12px;
      background: var(--card-bg); outline: none; width: 200px;
      transition: border-color .15s;
    }
    .search-input:focus { border-color: var(--accent); }
    .search-wrap { position: relative; }
    .search-wrap .si { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; }

    .cat-section { display: flex; flex-direction: column; gap: 8px; }
    .cat-label {
      font-size: 10px; font-weight: 800; text-transform: uppercase;
      letter-spacing: .08em; color: var(--text-muted);
      padding: 6px 0 2px; border-bottom: 1.5px solid var(--border);
    }

    /* product card */
    .product-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 14px 16px;
      display: flex; align-items: center; gap: 12px;
      transition: border-color .15s;
    }
    .product-card:hover { border-color: #d4b896; }
    .product-card.unavailable { opacity: .55; }

    .prod-icon {
      width: 44px; height: 44px; border-radius: 12px;
      background: var(--cream); border: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; flex-shrink: 0;
    }
    .prod-info { flex: 1; min-width: 0; }
    .prod-name { font-size: 13px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .prod-cat  { font-size: 10px; color: var(--text-muted); margin-top: 1px; }
    .prod-price{ font-size: 11px; color: var(--accent); font-weight: 700; margin-top: 3px; }

    .avail-toggle {
      display: flex; align-items: center; gap: 6px;
      font-size: 11px; font-weight: 700; cursor: pointer;
      padding: 5px 10px; border-radius: 20px; border: 1.5px solid;
      transition: all .15s; white-space: nowrap; flex-shrink: 0;
      background: none; font-family: 'Poppins', sans-serif;
    }
    .avail-toggle.on  { color: var(--green); border-color: #c8e6c9; background: var(--green-lt); }
    .avail-toggle.off { color: var(--red);   border-color: #ffcdd2; background: var(--red-lt); }
    .avail-toggle:hover { filter: brightness(.93); }

    .prod-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .btn-edit-prod {
      padding: 6px 12px; border-radius: 8px;
      border: 1.5px solid #bbdefb; background: #e3f2fd;
      color: #1565c0; font-family: 'Poppins', sans-serif;
      font-size: 11px; font-weight: 600; cursor: pointer; transition: all .14s;
    }
    .btn-edit-prod:hover { background: #1565c0; color: #fff; }
    .btn-del-prod {
      padding: 6px 10px; border-radius: 8px;
      border: 1.5px solid #ffcdd2; background: var(--red-lt);
      color: var(--red); font-family: 'Poppins', sans-serif;
      font-size: 11px; font-weight: 600; cursor: pointer; transition: all .14s;
    }
    .btn-del-prod:hover { background: var(--red); color: #fff; }

    .empty-list { text-align: center; padding: 40px; color: var(--text-muted); font-size: 13px; }

    /* ── Edit modal ── */
    .modal-overlay { display: none; position: fixed; top:0;left:0;width:100vw;height:100vh; background:rgba(44,26,14,.4); z-index:99999; align-items:center; justify-content:center; }
    .modal-overlay.open { display: flex; }
    .modal { background: var(--card-bg); border-radius: 20px; padding: 28px; width:100%; max-width:440px; box-shadow:0 12px 48px rgba(0,0,0,.18); animation: popIn .22s ease; max-height: 90vh; overflow-y: auto; }
    @keyframes popIn { from{opacity:0;transform:scale(.93)}to{opacity:1;transform:scale(1)} }
    .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
    .modal-header h3 { font-size:16px; font-weight:700; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:var(--text-muted); }
    .modal-actions { display:flex; gap:10px; margin-top:18px; }
    .btn-mcancel { flex:1; padding:11px; border:1.5px solid var(--border); border-radius:11px; background:none; font-family:'Poppins',sans-serif; font-size:13px; font-weight:600; cursor:pointer; color:var(--text-muted); }
    .btn-msave   { flex:2; padding:11px; background:var(--accent); color:#fff; border:none; border-radius:11px; font-family:'Poppins',sans-serif; font-size:13px; font-weight:700; cursor:pointer; }
    .btn-msave:hover { background:#7a4e2e; }

    /* delete confirm modal */
    .del-modal { max-width: 340px; text-align: center; }

    /* toast */
    .toast { position:fixed; bottom:24px; right:24px; z-index:99998; padding:13px 20px; border-radius:12px; font-size:13px; font-weight:600; box-shadow:0 4px 20px rgba(0,0,0,.14); transition:opacity .4s; }
    .toast-success { background:var(--green-lt); color:var(--green); border:1.5px solid #c8e6c9; }
    .toast-error   { background:var(--red-lt);   color:var(--red);   border:1.5px solid #ffcdd2; }
  </style>
</head>
<body>

<?php include('../includes/admin_sidebar.php'); ?>

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
                <button class="btn-del-prod"
                  onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">🗑️</button>
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
        msg.textContent = '✅ "' + res.name + '" added! Refresh to see it in the list.';
        msg.className   = 'add-msg success';
        // Reset form
        document.getElementById('add-category').value    = '';
        document.getElementById('add-name').value        = '';
        document.getElementById('add-desc').value        = '';
        document.getElementById('add-price-small').value = '';
        document.getElementById('add-price-large').value = '';
        showToast('✅ "' + res.name + '" added to menu!');
        // Reload page to show new product
        setTimeout(() => location.reload(), 1200);
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

// ── Toggle availability ───────────────────────
function toggleAvail(id, btn) {
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
      }
    });
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
  const fd = new FormData();
  fd.append('action',      'edit');
  fd.append('id',          document.getElementById('e-id').value);
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
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast('⚠️ ' + res.error, 'error');
      }
    });
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
    if (e.target === el) { closeEdit(); closeDelete(); }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeEdit(); closeDelete(); }
});

// Product count
document.addEventListener('DOMContentLoaded', () => {
  const count = document.querySelectorAll('.product-card').length;
  document.getElementById('product-count').textContent = '(' + count + ' items)';
});
</script>

</body>
</html>