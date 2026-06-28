<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role('admin');

$pdo = get_db();

// ── Load categories ───────────────────────────
$categories = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();

// ── Load products grouped by category ─────────
$products_raw = $pdo->query(
    'SELECT p.*, c.category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     ORDER BY c.category_name, p.name'
)->fetchAll();

// Group into JS-friendly structure
$menu_by_cat = [];
foreach ($categories as $cat) {
    $menu_by_cat[$cat['id']] = [
        'id'    => $cat['id'],
        'name'  => $cat['category_name'],
        'items' => [],
    ];
}
foreach ($products_raw as $p) {
    if (isset($menu_by_cat[$p['category_id']])) {
        $menu_by_cat[$p['category_id']]['items'][] = [
            'id'    => $p['id'],
            'name'  => $p['name'],
            'desc'  => $p['description'],
            'price' => (int)$p['price'],
            'stock' => (int)$p['stock'],
        ];
    }
}
$menu_json = json_encode(array_values($menu_by_cat));

// ── Category emoji map ─────────────────────────
function cat_emoji(string $name): string {
    $name = strtolower($name);
    if (str_contains($name, 'ice'))    return '🧊';
    if (str_contains($name, 'hot'))    return '☕';
    if (str_contains($name, 'milk'))   return '🧋';
    if (str_contains($name, 'fruit'))  return '🍹';
    if (str_contains($name, 'matcha')) return '🍵';
    if (str_contains($name, 'choco'))  return '🍫';
    if (str_contains($name, 'lemon'))  return '🍋';
    return '🥤';
}

// ── Handle checkout POST ───────────────────────
$checkout_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    $items       = json_decode($_POST['items'] ?? '[]', true);
    $order_type  = (int)($_POST['payment_method'] ?? 1); // 1=dine,2=take,3=delivery
    $user_id     = $_SESSION['user_id'];

    if (empty($items)) {
        $checkout_error = 'No items in order.';
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate total
            $total = 0;
            foreach ($items as $item) {
                $total += (int)$item['price'] * (int)$item['qty'];
            }

            // Insert order
            $pdo->prepare(
                'INSERT INTO orders (user_id, total_amount, payment_method, status, created_at)
                 VALUES (:uid, :total, :pm, "pending", CURDATE())'
            )->execute([':uid' => $user_id, ':total' => $total, ':pm' => $order_type]);

            $order_id = $pdo->lastInsertId();

            if (!$order_id) {
                throw new PDOException('lastInsertId() returned 0 — run fix_kofeedb.sql to add AUTO_INCREMENT to all tables.');
            }

            // Insert order items
            $item_stmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, quantity, price, subtotal)
                 VALUES (:oid, :pid, :qty, :price, :sub)'
            );
            foreach ($items as $item) {
                $sub = (int)$item['price'] * (int)$item['qty'];
                $item_stmt->execute([
                    ':oid'   => $order_id,
                    ':pid'   => $item['id'],
                    ':qty'   => $item['qty'],
                    ':price' => $item['price'],
                    ':sub'   => $sub,
                ]);
                // Decrement stock
                $pdo->prepare('UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :qty')
                    ->execute([':qty' => $item['qty'], ':id' => $item['id']]);
            }

            // Insert sales history
            $pdo->prepare(
                'INSERT INTO sales_history (order_id, processed_id, date)
                 VALUES (:oid, :pid, CURDATE())'
            )->execute([':oid' => $order_id, ':pid' => $user_id]);

            $pdo->commit();

            // Return JSON for AJAX
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'order_id' => $order_id]);
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Checkout error: ' . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            // Show real error during development — remove $e->getMessage() in production
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Menu — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:       #c47d3e;
      --accent-light: #ecddc8;
      --cream:        #faf5ef;
      --card-bg:      #ffffff;
      --border:       #ecddc8;
      --text-main:    #2c1a0e;
      --text-muted:   #9a7e65;
      --bg:           #faf5ef;
      --green:        #2e7d32;
      --green-lt:     #e8f5e9;
      --red:          #c62828;
      --red-lt:       #ffebee;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    /* ── page layout ── */
    #page-menu {
      display: flex;
      flex-direction: row !important;
      height: 100vh;
      overflow: hidden;
    }

    /* ── left: menu ── */
    .menu-left {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    /* category tabs */
    .category-tabs {
      display: flex;
      gap: 0;
      padding: 0 20px;
      background: var(--cream);
      border-bottom: 2px solid var(--border);
      flex-shrink: 0;
      overflow-x: auto;
    }
    .cat-tab {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 14px 22px 10px;
      cursor: pointer;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      transition: border-color .18s, color .18s;
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .05em;
      gap: 6px;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .cat-tab .cat-icon { font-size: 24px; }
    .cat-tab:hover { color: var(--accent); }
    .cat-tab.active { border-bottom-color: var(--accent); color: var(--accent); }

    /* size bar */
    .size-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 20px;
      background: var(--cream);
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .size-bar-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-right: 4px; }
    .search-input {
      margin-left: auto;
      padding: 7px 14px;
      border: 1.5px solid var(--border);
      border-radius: 20px;
      font-family: 'Poppins', sans-serif;
      font-size: 12px;
      background: #fff;
      outline: none;
      width: 200px;
      transition: border-color .15s;
    }
    .search-input:focus { border-color: var(--accent); }

    /* menu grid */
    .menu-grid-wrap {
      flex: 1;
      overflow-y: auto;
      padding: 16px 20px;
    }
    .menu-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 12px;
    }
    .menu-card {
      background: var(--card-bg);
      border: 1.5px solid var(--border);
      border-radius: 14px;
      padding: 14px 10px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      transition: transform .14s, box-shadow .14s, border-color .14s;
      position: relative;
      user-select: none;
    }
    .menu-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(139,94,60,.14); border-color: var(--accent-light); }
    .menu-card:active { transform: scale(.97); }
    .menu-card.out-of-stock { opacity: .45; pointer-events: none; }
    .menu-card .item-img { width: 64px; height: 64px; background: var(--cream); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 32px; }
    .menu-card .item-name { font-size: 12px; font-weight: 700; text-align: center; color: var(--text-main); line-height: 1.3; }
    .menu-card .item-price { font-size: 13px; font-weight: 800; color: var(--accent); }
    .menu-card .stock-badge { position: absolute; top: 8px; right: 8px; font-size: 9px; font-weight: 700; background: var(--red-lt); color: var(--red); border-radius: 6px; padding: 2px 5px; }
    .menu-card .stock-ok { background: var(--green-lt); color: var(--green); }

    .empty-cat { grid-column: 1/-1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: var(--text-muted); gap: 10px; }
    .empty-cat .empty-icon { font-size: 48px; opacity: .4; }
    .empty-cat p { font-size: 14px; font-weight: 600; }
    .empty-cat small { font-size: 12px; }

    /* ── right: order panel ── */
    .order-panel {
      width: 320px;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      background: var(--card-bg);
      border-left: 2px solid var(--border);
      height: 100vh;
    }

    .order-type-bar {
      display: flex;
      gap: 0;
      border-bottom: 2px solid var(--border);
      flex-shrink: 0;
    }
    .order-type-btn {
      flex: 1;
      padding: 12px 6px;
      border: none;
      background: none;
      font-family: 'Poppins', sans-serif;
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      cursor: pointer;
      color: var(--text-muted);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      border-bottom: 3px solid transparent;
      transition: all .15s;
    }
    .order-type-btn svg { width: 18px; height: 18px; }
    .order-type-btn:hover { color: var(--accent); }
    .order-type-btn.active { color: var(--accent); border-bottom-color: var(--accent); }

    .order-items {
      flex: 1;
      overflow-y: auto;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .order-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); gap: 8px; }
    .oe-icon { font-size: 44px; opacity: .3; }
    .order-empty p { font-size: 13px; font-weight: 600; }
    .order-empty small { font-size: 11px; }

    .order-item-row {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--cream);
      border-radius: 10px;
      padding: 10px;
      border: 1px solid var(--border);
    }
    .oi-icon { font-size: 22px; width: 32px; text-align: center; flex-shrink: 0; }
    .oi-info { flex: 1; min-width: 0; }
    .oi-name { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .oi-unit { font-size: 10px; color: var(--text-muted); }
    .oi-controls { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .qty-btn { width: 24px; height: 24px; border-radius: 6px; border: 1.5px solid var(--border); background: #fff; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-main); transition: all .12s; }
    .qty-btn:hover { border-color: var(--accent); color: var(--accent); }
    .qty-num { font-size: 13px; font-weight: 700; width: 20px; text-align: center; }
    .oi-price { font-size: 12px; font-weight: 800; color: var(--accent); flex-shrink: 0; min-width: 44px; text-align: right; }

    /* order footer */
    .order-footer {
      padding: 14px 16px;
      border-top: 2px solid var(--border);
      display: flex;
      flex-direction: column;
      gap: 6px;
      flex-shrink: 0;
    }
    .order-row { display: flex; justify-content: space-between; font-size: 13px; }
    .order-row.total { font-size: 16px; font-weight: 800; margin: 4px 0; }
    .checkout-btn {
      width: 100%; padding: 13px; background: var(--accent); color: #fff; border: none;
      border-radius: 12px; font-family: 'Poppins', sans-serif; font-size: 14px;
      font-weight: 700; cursor: pointer; margin-top: 6px;
      transition: background .15s, transform .12s;
    }
    .checkout-btn:hover { background: #7a4e2e; transform: translateY(-1px); }
    .checkout-btn:active { transform: scale(.98); }
    .checkout-btn:disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; transform: none; }
    .clear-btn {
      width: 100%; padding: 9px; background: none; color: var(--text-muted);
      border: 1.5px solid var(--border); border-radius: 12px;
      font-family: 'Poppins', sans-serif; font-size: 12px; font-weight: 600;
      cursor: pointer; transition: all .15s;
    }
    .clear-btn:hover { border-color: var(--red); color: var(--red); }

    /* ── toast ── */
    .toast {
      position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
      background: var(--text-main); color: #fff;
      padding: 11px 20px; border-radius: 10px;
      font-size: 13px; font-weight: 600;
      box-shadow: 0 4px 20px rgba(0,0,0,.2);
      z-index: 9999; opacity: 0; pointer-events: none;
      transition: opacity .25s;
      white-space: nowrap;
    }
    .toast.show { opacity: 1; }
    .toast.success { background: var(--green); }
    .toast.error   { background: var(--red); }

    /* ── receipt modal ── */
    .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1000; align-items: center; justify-content: center; }
    .modal-bg.open { display: flex; }
    .modal { background: #fff; border-radius: 20px; padding: 32px; width: 100%; max-width: 360px; box-shadow: 0 12px 48px rgba(0,0,0,.2); animation: popIn .22s ease; text-align: center; }
    @keyframes popIn { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
    .receipt-icon { font-size: 52px; margin-bottom: 12px; }
    .receipt-num  { font-size: 22px; font-weight: 800; color: var(--accent); margin-bottom: 4px; }
    .receipt-sub  { font-size: 13px; color: var(--text-muted); margin-bottom: 20px; }
    .receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; text-align: left; }
    .receipt-table td { padding: 5px 0; font-size: 13px; border-bottom: 1px solid #f0e8de; }
    .receipt-table td:last-child { text-align: right; font-weight: 700; }
    .receipt-total { display: flex; justify-content: space-between; font-size: 16px; font-weight: 800; margin: 12px 0 20px; color: var(--accent); }
    .btn-done { width: 100%; padding: 13px; background: var(--accent); color: #fff; border: none; border-radius: 12px; font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-done:hover { background: #7a4e2e; }

    @media (max-width: 900px) {
      .menu-grid { grid-template-columns: repeat(3,1fr); }
      .order-panel { width: 280px; }
    }
  </style>
</head>
<body>

<?php include('../includes/admin_sidebar.php'); ?>

<div id="page-menu" class="page active">

  <!-- ── LEFT: Menu ── -->
  <div class="menu-left">

    <!-- Category tabs (from DB) -->
    <div class="category-tabs" id="cat-tabs">
      <?php $tab_i = 0; foreach ($menu_by_cat as $cat): ?>
        <div class="cat-tab <?= $tab_i === 0 ? 'active' : '' ?>"
             data-cat="<?= $cat['id'] ?>"
             onclick="switchCat(this, <?= $cat['id'] ?>)">
          <span class="cat-icon"><?= cat_emoji($cat['name']) ?></span>
          <?= htmlspecialchars(strtoupper($cat['name'])) ?>
        </div>
      <?php $tab_i++; endforeach; ?>
    </div>

    <!-- Search bar -->
    <div class="size-bar">
      <span class="size-bar-label">Menu</span>
      <input class="search-input" type="text" id="search-input"
             placeholder="🔍 Search drinks…" oninput="renderGrid()"/>
    </div>

    <!-- Menu grid -->
    <div class="menu-grid-wrap">
      <div class="menu-grid" id="menu-grid"></div>
    </div>
  </div>

  <!-- ── RIGHT: Order Panel ── -->
  <div class="order-panel">
    <div class="order-type-bar">
      <button class="order-type-btn active" onclick="switchOrderType(this,1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2h18M3 6h18M21 12H3M3 16h10"/><circle cx="17" cy="18" r="3"/></svg>
        Dine In
      </button>
      <button class="order-type-btn" onclick="switchOrderType(this,2)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        Take Out
      </button>
      <button class="order-type-btn" onclick="switchOrderType(this,3)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        Delivery
      </button>
    </div>

    <div class="order-items" id="order-items">
      <div class="order-empty">
        <div class="oe-icon">🧋</div>
        <p>No items yet</p>
        <small>Tap a drink to add it</small>
      </div>
    </div>

    <div class="order-footer">
      <div class="order-row"><span>Subtotal</span><span id="subtotal">₱0</span></div>
      <div class="order-row"><span>Tax (12%)</span><span id="tax">₱0.00</span></div>
      <div class="order-row total"><span>Total</span><span id="total">₱0.00</span></div>
      <button class="checkout-btn" id="checkout-btn" onclick="checkout()" disabled>Place Order</button>
      <button class="clear-btn" onclick="clearOrder()">🗑️ Clear Order</button>
    </div>
  </div>

</div>

<!-- Receipt modal -->
<div class="modal-bg" id="receipt-modal">
  <div class="modal">
    <div class="receipt-icon">✅</div>
    <div class="receipt-num" id="r-num">#0001</div>
    <div class="receipt-sub" id="r-sub">Order placed successfully!</div>
    <table class="receipt-table" id="r-table"></table>
    <div class="receipt-total">
      <span>Total Paid</span>
      <span id="r-total">₱0.00</span>
    </div>
    <button class="btn-done" onclick="closeReceipt()">✅ New Order</button>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ── Data from PHP ─────────────────────────────
const menuData = <?= $menu_json ?>;

// ── State ─────────────────────────────────────
let currentCatId = menuData.length ? menuData[0].id : null; // set from first real cat id
let orderItems   = [];
let orderType    = 1; // 1=dine, 2=take, 3=delivery
let orderCount   = 0;

// ── Category ──────────────────────────────────
function switchCat(el, catId) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  currentCatId = catId;
  document.getElementById('search-input').value = '';
  renderGrid();
}

function switchOrderType(el, type) {
  document.querySelectorAll('.order-type-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  orderType = type;
}

// ── Menu grid ─────────────────────────────────
function renderGrid() {
  const grid    = document.getElementById('menu-grid');
  const search  = document.getElementById('search-input').value.trim().toLowerCase();
  const catData = menuData.find(c => c.id == currentCatId);
  let items     = catData ? catData.items : [];

  if (search) {
    items = items.filter(i => i.name.toLowerCase().includes(search));
  }

  if (!items.length) {
    grid.innerHTML = `<div class="empty-cat">
      <div class="empty-icon">🫙</div>
      <p>${search ? 'No results for "' + search + '"' : 'No items in this category'}</p>
      <small>${search ? 'Try a different search' : 'Add products in the menu manager'}</small>
    </div>`;
    return;
  }

  grid.innerHTML = items.map(item => {
    const oos = item.stock <= 0;
    const stockBadge = oos
      ? `<span class="stock-badge">Out of stock</span>`
      : item.stock <= 5
        ? `<span class="stock-badge stock-ok">Only ${item.stock} left</span>`
        : '';
    return `<div class="menu-card ${oos ? 'out-of-stock' : ''}"
                 onclick="addToOrder(${item.id})">
      ${stockBadge}
      <div class="item-img">🧋</div>
      <div class="item-name">${item.name}</div>
      <div class="item-price">₱${item.price}</div>
    </div>`;
  }).join('');
}

// ── Order ─────────────────────────────────────
function addToOrder(itemId) {
  itemId = Number(itemId); // normalize to number always
  // Find item across all categories
  let item = null;
  for (const cat of menuData) {
    item = cat.items.find(i => Number(i.id) === itemId);
    if (item) break;
  }
  if (!item) return;

  const existing = orderItems.find(o => Number(o.id) === itemId);
  if (existing) {
    if (existing.qty >= item.stock) {
      showToast('⚠️ Not enough stock', 'error'); return;
    }
    existing.qty++;
  } else {
    orderItems.push({ id: itemId, name: item.name, price: item.price, qty: 1, stock: item.stock });
  }
  renderOrder();
  showToast('🧋 ' + item.name + ' added!', 'success');
}

function renderOrder() {
  const container = document.getElementById('order-items');

  if (!orderItems.length) {
    container.innerHTML = `<div class="order-empty">
      <div class="oe-icon">🧋</div>
      <p>No items yet</p>
      <small>Tap a drink to add it</small>
    </div>`;
    updateTotals();
    document.getElementById('checkout-btn').disabled = true;
    return;
  }

  container.innerHTML = orderItems.map(o => `
    <div class="order-item-row">
      <div class="oi-icon">🧋</div>
      <div class="oi-info">
        <div class="oi-name">${o.name}</div>
        <div class="oi-unit">₱${o.price} each</div>
      </div>
      <div class="oi-controls">
        <button class="qty-btn" onclick="changeQty(${o.id},-1)">−</button>
        <span class="qty-num">${o.qty}</span>
        <button class="qty-btn" onclick="changeQty(${o.id},1)">+</button>
      </div>
      <div class="oi-price">₱${o.price * o.qty}</div>
    </div>
  `).join('');

  updateTotals();
  document.getElementById('checkout-btn').disabled = false;
}

function changeQty(id, delta) {
  id = Number(id);
  const idx = orderItems.findIndex(o => Number(o.id) === id);
  if (idx === -1) return;
  orderItems[idx].qty += delta;
  if (orderItems[idx].qty <= 0) orderItems.splice(idx, 1);
  renderOrder();
}

function updateTotals() {
  const sub = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
  const tax = sub * 0.12;
  document.getElementById('subtotal').textContent = '₱' + sub;
  document.getElementById('tax').textContent      = '₱' + tax.toFixed(2);
  document.getElementById('total').textContent    = '₱' + (sub + tax).toFixed(2);
}

function clearOrder() {
  orderItems = [];
  renderOrder();
}

// ── Checkout ──────────────────────────────────
async function checkout() {
  if (!orderItems.length) return;

  const btn = document.getElementById('checkout-btn');
  btn.disabled    = true;
  btn.textContent = 'Placing order…';

  const fd = new FormData();
  fd.append('action',         'checkout');
  fd.append('payment_method', orderType);
  fd.append('items',          JSON.stringify(orderItems));

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      orderCount++;
      const num   = '#' + String(data.order_id).padStart(4, '0');
      const sub   = orderItems.reduce((s, o) => s + o.price * o.qty, 0);
      const total = (sub * 1.12).toFixed(2);

      // Build receipt
      document.getElementById('r-num').textContent = num;
      document.getElementById('r-sub').textContent = 'Order placed successfully!';
      document.getElementById('r-total').textContent = '₱' + total;
      document.getElementById('r-table').innerHTML = orderItems.map(o =>
        `<tr><td>${o.name} ×${o.qty}</td><td>₱${o.price * o.qty}</td></tr>`
      ).join('');

      document.getElementById('receipt-modal').classList.add('open');

      // Update local stock counts so UI reflects change immediately
      orderItems.forEach(o => {
        for (const cat of menuData) {
          const item = cat.items.find(i => Number(i.id) === Number(o.id));
          if (item) item.stock -= o.qty;
        }
      });

      clearOrder();
      renderGrid();

    } else {
      showToast('❌ ' + (data.error ?? 'Order failed'), 'error');
      btn.disabled    = false;
      btn.textContent = 'Place Order';
    }
  } catch (e) {
    showToast('❌ Network error. Try again.', 'error');
    btn.disabled    = false;
    btn.textContent = 'Place Order';
  }
}

function closeReceipt() {
  document.getElementById('receipt-modal').classList.remove('open');
}

// ── Toast ─────────────────────────────────────
let toastTimer;
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'toast show ' + type;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
}

// ── Init ──────────────────────────────────────
renderGrid();
</script>

</body>
</html>