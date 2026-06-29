<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role('admin');

$pdo = get_db();

// ── Handle POST ────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    $cat_id = (int)($_POST['category_id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $price  = (int)($_POST['price'] ?? 0);
    $stock  = (int)($_POST['stock'] ?? 0);

    if (!$cat_id) {
        $error = 'Please select a category.';
    } elseif ($name === '') {
        $error = 'Please enter a drink name.';
    } elseif ($price <= 0) {
        $error = 'Please enter a valid price.';
    } elseif ($stock < 0) {
        $error = 'Stock cannot be negative.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO products (name, description, price, stock, category_id, created_at, updated_at)
                 VALUES (:name, :desc, :price, :stock, :cat, CURDATE(), CURDATE())'
            );
            $stmt->execute([
                ':name'  => $name,
                ':desc'  => $desc,
                ':price' => $price,
                ':stock' => $stock,
                ':cat'   => $cat_id,
            ]);

            // Return JSON for AJAX
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'name' => $name]);
            exit;

        } catch (PDOException $e) {
            error_log('Add item error: ' . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save item. Try again.']);
            exit;
        }
    }

    // Validation errors return JSON too
    if ($error) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}

// ── Load categories ────────────────────────────
$categories = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add Menu Item — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:       #c47d3e;
      --accent-light: #ecddc8;
      --cream:        #99582a;
      --card-bg:      #ffffff;
      --border:       #ecddc8;
      --text-main:    #000000;
      --text-muted:   #000000;
      --bg:           #faf5ef;
      --green:        #2e7d32;
      --green-lt:     #e8f5e9;
      --red:          #c62828;
      --red-lt:       #ffebee;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    /* ── page layout ── */
    #page-addmenu {
      padding: 32px 36px;
      max-width: 700px;
    }

    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 24px; font-weight: 800; color: var(--text-main); }
    .page-header p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

    /* ── form card ── */
    .form-card {
      background: var(--card-bg);
      border: 1.5px solid var(--border);
      border-radius: 16px;
      padding: 24px;
      margin-bottom: 16px;
    }
    .form-card h2 {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--text-muted);
      margin-bottom: 18px;
    }

    /* ── fields ── */
    .field-group { margin-bottom: 16px; }
    .field-group:last-child { margin-bottom: 0; }
    .field-label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 6px;
    }
    .field-label .req { color: var(--accent); margin-left: 2px; }

    .field-input,
    .field-select,
    .field-textarea {
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      font-family: 'Poppins', sans-serif;
      font-size: 13px;
      background: #fff;
      color: var(--text-main);
      outline: none;
      transition: border-color .15s, box-shadow .15s;
      appearance: none;
    }
    .field-input:focus,
    .field-select:focus,
    .field-textarea:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(196,125,62,.12);
    }
    .field-select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239a7e65' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      padding-right: 36px;
      cursor: pointer;
    }
    .field-textarea { resize: vertical; min-height: 80px; }

    /* price + stock row */
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    /* ── submit button ── */
    .submit-btn {
      width: 100%;
      padding: 14px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-family: 'Poppins', sans-serif;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s, transform .12s;
      margin-top: 4px;
    }
    .submit-btn:hover    { background: #7a4e2e; transform: translateY(-1px); }
    .submit-btn:active   { transform: scale(.98); }
    .submit-btn:disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; transform: none; }

    /* ── toast ── */
    .toast {
      position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
      background: var(--text-main); color: #fff;
      padding: 11px 22px; border-radius: 10px;
      font-size: 13px; font-weight: 600;
      box-shadow: 0 4px 20px rgba(0,0,0,.2);
      z-index: 9999; opacity: 0; pointer-events: none;
      transition: opacity .25s;
      white-space: nowrap;
    }
    .toast.show    { opacity: 1; }
    .toast.success { background: var(--green); }
    .toast.error   { background: var(--red);   }

    /* ── field error highlight ── */
    .field-input.invalid,
    .field-select.invalid,
    .field-textarea.invalid {
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(198,40,40,.10);
    }
  </style>
</head>
<body>

<?php include('../includes/admin_sidebar.php'); ?>

<div id="page-addmenu" class="page active">
  <div class="page-header">
    <h1>Add Menu Item</h1>
    <p>Add a new drink to your menu</p>
  </div>

  <div class="form-card">
    <h2>Item Details</h2>

    <div class="field-group">
      <label class="field-label" for="add-category">Category <span class="req">*</span></label>
      <select class="field-select" id="add-category">
        <option value="">Select a category…</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>">
            <?= htmlspecialchars($cat['category_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field-group">
      <label class="field-label" for="add-name">Drink Name <span class="req">*</span></label>
      <input class="field-input" type="text" id="add-name" placeholder="e.g. Taro Milk Tea"/>
    </div>

    <div class="field-group">
      <label class="field-label" for="add-desc">Description <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
      <textarea class="field-textarea" id="add-desc" placeholder="Brief description of the drink…"></textarea>
    </div>
  </div>

  <div class="form-card">
    <h2>Pricing &amp; Stock</h2>
    <div class="two-col">
      <div class="field-group">
        <label class="field-label" for="add-price">Price (₱) <span class="req">*</span></label>
        <input class="field-input" type="number" id="add-price" placeholder="0" min="1"/>
      </div>
      <div class="field-group">
        <label class="field-label" for="add-stock">Stock <span class="req">*</span></label>
        <input class="field-input" type="number" id="add-stock" placeholder="0" min="0"/>
      </div>
    </div>
  </div>

  <button class="submit-btn" id="submit-btn" onclick="addMenuItem()">➕ Add to Menu</button>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
async function addMenuItem() {
  // Clear previous highlights
  document.querySelectorAll('.invalid').forEach(el => el.classList.remove('invalid'));

  const catEl    = document.getElementById('add-category');
  const nameEl   = document.getElementById('add-name');
  const descEl   = document.getElementById('add-desc');
  const priceEl  = document.getElementById('add-price');
  const stockEl  = document.getElementById('add-stock');
  const btn      = document.getElementById('submit-btn');

  const catId = catEl.value;
  const name  = nameEl.value.trim();
  const price = parseInt(priceEl.value, 10);
  const stock = parseInt(stockEl.value, 10);

  // Client-side validation
  let valid = true;
  if (!catId)             { catEl.classList.add('invalid');   valid = false; }
  if (!name)              { nameEl.classList.add('invalid');  valid = false; }
  if (!price || price < 1){ priceEl.classList.add('invalid'); valid = false; }
  if (isNaN(stock) || stock < 0) { stockEl.classList.add('invalid'); valid = false; }

  if (!valid) {
    showToast('⚠️ Please fill in all required fields.', 'error');
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Saving…';

  const fd = new FormData();
  fd.append('action',      'add_item');
  fd.append('category_id', catId);
  fd.append('name',        name);
  fd.append('description', descEl.value.trim());
  fd.append('price',       price);
  fd.append('stock',       stock);

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      // Reset form
      catEl.value   = '';
      nameEl.value  = '';
      descEl.value  = '';
      priceEl.value = '';
      stockEl.value = '';

      showToast('✅ "' + data.name + '" added to menu!', 'success');
    } else {
      showToast('❌ ' + (data.error ?? 'Something went wrong.'), 'error');
    }
  } catch (e) {
    showToast('❌ Network error. Please try again.', 'error');
  }

  btn.disabled    = false;
  btn.textContent = '➕ Add to Menu';
}

// Remove invalid highlight on input
document.querySelectorAll('.field-input, .field-select, .field-textarea').forEach(el => {
  el.addEventListener('input', () => el.classList.remove('invalid'));
  el.addEventListener('change', () => el.classList.remove('invalid'));
});

let toastTimer;
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast show ' + type;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2800);
}
</script>

</body>
</html>