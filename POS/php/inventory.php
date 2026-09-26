<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/inventory_helpers.php';
require_once '../includes/shift_guard.php';
require_login();
require_permission('inventory.view');

$pdo        = get_db();
$user       = current_user();
$can_manage = has_permission('inventory.manage');
$can_expiry = has_permission('inventory.expiry.manage');
$toast      = '';
$toast_type = 'success';

// Keep batch statuses honest before anything renders.
sync_expired_batches();

// ── POST actions ──────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!has_active_shift()) {
        header('Location: inventory.php?toast=' . urlencode('Clock in before making inventory changes.') . '&type=error');
        exit;
    }
    require_permission('inventory.manage');

    $action   = $_POST['action'] ?? '';
    $r_view   = $_POST['return_view']   ?? 'active';
    $r_cat    = $_POST['return_cat']    ?? '';
    $r_search = $_POST['return_search'] ?? '';

    // ── Add ingredient ──
    if ($action === 'add') {
        $cat_id   = (int)($_POST['cat_id'] ?? 0);
        $name     = trim($_POST['name']    ?? '');
        $brand    = trim($_POST['brand']   ?? '');
        $unit     = trim($_POST['unit']    ?? 'pcs');
        $quantity = (float)($_POST['quantity']         ?? 0);
        $reorder  = (float)($_POST['reorder_at']       ?? 5);
        $reorder_qty = (float)($_POST['reorder_quantity'] ?? 0);
        $supplier = (int)($_POST['default_supplier_id'] ?? 0) ?: null;
        $auto     = isset($_POST['auto_reorder']) ? 1 : 0;
        $unit_cost = isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
        $cost_unit = trim($_POST['cost_unit'] ?? '') ?: null;

        if (!$cat_id || !$name) {
            $toast = 'Name and category are required.'; $toast_type = 'error';
        } else {
            $pdo->prepare(
                'INSERT INTO ingredients
                    (cat_id, name, brand, unit, quantity, reorder_at,
                     reorder_quantity, default_supplier_id, auto_reorder, unit_cost, cost_unit)
                 VALUES (:c,:n,:b,:u,:q,:r,:rq,:s,:a,:uc,:cu)'
            )->execute([
                ':c'=>$cat_id, ':n'=>$name, ':b'=>$brand, ':u'=>$unit, ':q'=>$quantity,
                ':r'=>$reorder, ':rq'=>$reorder_qty ?: max($reorder * 2, 10),
                ':s'=>$supplier, ':a'=>$auto, ':uc'=>$unit_cost, ':cu'=>$cost_unit,
            ]);
            $new_id = (int)$pdo->lastInsertId();

            if ($quantity > 0) {
                record_ingredient_batch($new_id, $quantity, [
                    'batch_ref'   => 'INIT-' . $new_id,
                    'unit'        => $unit,
                    'supplier_id' => $supplier,
                    'notes'       => 'Opening stock entered on item creation',
                    'recorded_by' => (int)$user['id'],
                ]);
            }
            $toast = '"' . htmlspecialchars($name) . '" added!';
        }
    }

    // ── Restock (adds a new batch) ──
    if ($action === 'restock') {
        $id       = (int)($_POST['ingredient_id'] ?? 0);
        $qty      = (float)($_POST['qty'] ?? 0);
        $expiry   = trim($_POST['expiry_date'] ?? '');
        $supplier = (int)($_POST['supplier_id'] ?? 0) ?: null;

        if ($id && $qty > 0) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE ingredients SET quantity = quantity + :q WHERE id = :id')
                ->execute([':q'=>$qty, ':id'=>$id]);
            $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
                ->execute([':i'=>$id, ':q'=>$qty, ':u'=>$user['id']]);
            $pdo->commit();

            record_ingredient_batch($id, $qty, [
                'expiry_date' => $expiry ?: null,
                'supplier_id' => $supplier,
                'notes'       => 'Manual restock',
                'recorded_by' => (int)$user['id'],
            ]);
            $toast = 'Restocked — batch recorded.';
        } else {
            $toast = 'Enter a valid quantity.'; $toast_type = 'error';
        }
    }

    // ── Set exact stock ──
    if ($action === 'set_stock') {
        $id  = (int)($_POST['ingredient_id'] ?? 0);
        $qty = (float)($_POST['qty'] ?? -1);
        if ($id && $qty >= 0) {
            $before = $pdo->prepare('SELECT quantity FROM ingredients WHERE id = :id');
            $before->execute([':id'=>$id]);
            $old = (float)$before->fetchColumn();

            $pdo->prepare('UPDATE ingredients SET quantity = :q WHERE id = :id')->execute([':q'=>$qty, ':id'=>$id]);
            $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
                ->execute([':i'=>$id, ':q'=>$qty - $old, ':u'=>$user['id']]);

            if ($qty > $old) {
                record_ingredient_batch($id, $qty - $old, [
                    'notes' => 'Stock count adjustment', 'recorded_by' => (int)$user['id'],
                ]);
            } elseif ($qty < $old) {
                consume_ingredient_batches($id, $old - $qty);
            }
            check_and_trigger_reorder($id, (int)$user['id']);
            $toast = 'Stock set to ' . $qty . '.';
        } else {
            $toast = 'Enter a valid quantity (0 or more).'; $toast_type = 'error';
        }
    }

    // ── Edit item ──
    if ($action === 'edit') {
        $id          = (int)($_POST['ingredient_id'] ?? 0);
        $cat_id      = (int)($_POST['cat_id'] ?? 0);
        $name        = trim($_POST['name']  ?? '');
        $brand       = trim($_POST['brand'] ?? '');
        $unit        = trim($_POST['unit']  ?? 'pcs');
        $reorder     = (float)($_POST['reorder_at'] ?? 5);
        $reorder_qty = (float)($_POST['reorder_quantity'] ?? 0);
        $supplier    = (int)($_POST['default_supplier_id'] ?? 0) ?: null;
        $auto        = isset($_POST['auto_reorder']) ? 1 : 0;
        $unit_cost   = isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
        $cost_unit   = trim($_POST['cost_unit'] ?? '') ?: null;

        if ($id && $name) {
            $pdo->prepare(
                'UPDATE ingredients
                    SET name=:n, brand=:b, unit=:u, cat_id=:c, reorder_at=:r,
                        reorder_quantity=:rq, default_supplier_id=:s, auto_reorder=:a,
                        unit_cost=:uc, cost_unit=:cu
                  WHERE id=:id'
            )->execute([
                ':n'=>$name, ':b'=>$brand, ':u'=>$unit, ':c'=>$cat_id, ':r'=>$reorder,
                ':rq'=>$reorder_qty, ':s'=>$supplier, ':a'=>$auto,
                ':uc'=>$unit_cost, ':cu'=>$cost_unit, ':id'=>$id,
            ]);
            check_and_trigger_reorder($id, (int)$user['id']);
            $toast = 'Item updated!';
        }
    }

    if ($action === 'archive') {
        $id = (int)($_POST['ingredient_id'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE ingredients SET archived_at = NOW() WHERE id = :id')->execute([':id'=>$id]);
            $toast = 'Item archived.';
        }
    }

    if ($action === 'restore') {
        $id = (int)($_POST['ingredient_id'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE ingredients SET archived_at = NULL WHERE id = :id')->execute([':id'=>$id]);
            $toast = 'Item restored.';
        }
    }

    if ($action === 'purge') {
        $id = (int)($_POST['ingredient_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM ingredients WHERE id = :id AND archived_at IS NOT NULL')->execute([':id'=>$id]);
            $toast = 'Item permanently deleted.';
        }
    }

    // ── Manual reorder sweep ──
    if ($action === 'run_sweep') {
        $created = run_reorder_sweep((int)$user['id']);
        $toast = $created
            ? count($created) . ' auto-reorder request(s) filed.'
            : 'Nothing below threshold — no reorders needed.';
    }

    $qs = [];
    if ($toast)               { $qs['toast'] = $toast; $qs['type'] = $toast_type; }
    if ($r_view !== 'active') { $qs['view']  = $r_view; }
    if ($r_cat)               { $qs['cat']    = $r_cat; }
    if ($r_search)            { $qs['search'] = $r_search; }

    header('Location: inventory.php' . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success';
}

// Fire expiry warnings at most once per item per day.
dispatch_expiry_warnings();

// ── Data ───────────────────────────────────────
$cats      = $pdo->query('SELECT * FROM ingredient_categories ORDER BY name')->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();

$filter_cat    = (int)($_GET['cat'] ?? 0);
$search        = trim($_GET['search'] ?? '');
$view          = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
$filter_status = $_GET['status'] ?? 'all';

$where  = $view === 'archived' ? 'i.archived_at IS NOT NULL' : 'i.archived_at IS NULL';
$params = [];
if ($filter_cat) { $where .= ' AND i.cat_id = :c'; $params[':c'] = $filter_cat; }
if ($search) {
    $where .= ' AND (i.name LIKE :s OR i.brand LIKE :s2)';
    $params[':s'] = "%$search%"; $params[':s2'] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT i.*, ic.name AS cat_name, ic.icon AS cat_icon, ic.shelf_life_days,
           s.name AS supplier_name
      FROM ingredients i
      JOIN ingredient_categories ic ON ic.id = i.cat_id
      LEFT JOIN suppliers s ON s.id = i.default_supplier_id
     WHERE $where
     ORDER BY ic.name, i.name
");
$stmt->execute($params);
$ingredients = $stmt->fetchAll();

$expiry_map = ingredient_expiry_map();

// Decorate each row with its stock + expiry state.
foreach ($ingredients as &$ing) {
    $qty   = (float)$ing['quantity'];
    $thr   = (float)$ing['reorder_at'];
    $ing['stock_state'] = $qty <= 0 ? 'out' : ($thr > 0 && $qty <= $thr ? 'low' : 'ok');

    $meta = $expiry_map[(int)$ing['id']] ?? null;
    $ing['next_expiry']     = $meta['next_expiry'] ?? null;
    $ing['expired_batches'] = (int)($meta['expired_batches'] ?? 0);
    $ing['active_batches']  = (int)($meta['active_batches'] ?? 0);
    $ing['expiry_badge']    = batch_expiry_status($ing['next_expiry']);
    if ($ing['expired_batches'] > 0 && $ing['expiry_badge']['key'] === 'none') {
        $ing['expiry_badge'] = batch_expiry_status(date('Y-m-d', strtotime('-1 day')));
    }
}
unset($ing);

// Apply the status filter in PHP (it spans stock + expiry state).
if ($filter_status !== 'all') {
    $ingredients = array_values(array_filter($ingredients, function ($i) use ($filter_status) {
        return match ($filter_status) {
            'low'       => $i['stock_state'] === 'low',
            'out'       => $i['stock_state'] === 'out',
            'expiring'  => $i['expiry_badge']['key'] === 'soon',
            'expired'   => $i['expiry_badge']['key'] === 'expired' || $i['expired_batches'] > 0,
            default     => true,
        };
    }));
}

// ── Metric cards (always whole-inventory, ignoring filters) ──
$all_active = $pdo->query('SELECT id, quantity, reorder_at FROM ingredients WHERE archived_at IS NULL')->fetchAll();
$m_total = count($all_active);
$m_low   = count(array_filter($all_active, fn($i) => $i['quantity'] > 0 && $i['reorder_at'] > 0 && $i['quantity'] <= $i['reorder_at']));
$m_out   = count(array_filter($all_active, fn($i) => $i['quantity'] <= 0));

$m_expiring = (int)$pdo->query(
    "SELECT COUNT(DISTINCT ingredient_id) FROM ingredient_batches
      WHERE status='active' AND qty_remaining > 0 AND expiry_date IS NOT NULL
        AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . EXPIRY_WARNING_DAYS . " DAY)"
)->fetchColumn();
$m_expired = (int)$pdo->query(
    "SELECT COUNT(DISTINCT ingredient_id) FROM ingredient_batches
      WHERE status='expired' AND qty_remaining > 0"
)->fetchColumn();
$m_reorders = pending_reorder_count();

$archived_count = (int)$pdo->query('SELECT COUNT(*) FROM ingredients WHERE archived_at IS NOT NULL')->fetchColumn();
$last_update    = $pdo->query('SELECT MAX(updated_at) FROM ingredients WHERE archived_at IS NULL')->fetchColumn();

function inv_url(array $overrides = []): string {
    $p = array_merge([
        'view'   => $_GET['view']   ?? null,
        'cat'    => $_GET['cat']    ?? null,
        'search' => $_GET['search'] ?? null,
        'status' => $_GET['status'] ?? null,
    ], $overrides);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '' && $v !== 'all');
    return 'inventory.php' . ($p ? '?' . http_build_query($p) : '');
}

$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory — Kofee POS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-[var(--cream,#fdf3ea)] font-['Inter',sans-serif] text-[var(--text-main,#2b2130)]">

<?php include '../includes/sidebar.php'; ?>
<?php render_shift_gate(); ?>

<main class="md:ml-[var(--sidebar-w,248px)] px-4 md:px-7 py-6 max-w-[1500px]">

  <!-- Header -->
  <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
    <div>
      <h1 class="font-['Playfair_Display',serif] text-[26px] font-bold leading-tight">Inventory</h1>
      <p class="text-[12.5px] text-[var(--text-muted,#8b7c88)] mt-1">
        <?= $view === 'archived' ? 'Archived ingredients' : 'Stock levels, batches, and expiry tracking' ?>
        <?php if ($last_update): ?>
          · updated <?= htmlspecialchars(date('M d, Y g:i A', strtotime($last_update))) ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($can_manage): ?>
    <div class="flex gap-2">
      <form method="POST" onsubmit="return confirm('Run the reorder check across all items now?')">
        <input type="hidden" name="action" value="run_sweep">
        <button class="px-4 py-2.5 rounded-lg text-[13px] font-semibold border border-[var(--latte,#efe0cc)]
                       bg-white hover:bg-[var(--accent-lt,#fcefe1)] flex items-center gap-1.5"><?= icon('bot', 14) ?> Run reorder check</button>
      </form>
      <button onclick="openModal('modal-add')"
              class="px-4 py-2.5 rounded-lg text-[13px] font-bold text-white
                     bg-[var(--caramel,#c47d3e)] hover:opacity-90 shadow-sm flex items-center gap-1.5"><?= icon('plus', 14) ?> Add Item</button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Metric cards -->
  <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
    <?php
    $cards = [
      ['Total Items',     $m_total,    'all',      'text-[var(--text-main,#2b2130)]', 'bg-stone-100'],
      ['Low Stock',       $m_low,      'low',      'text-amber-700',                  'bg-amber-100'],
      ['Out of Stock',    $m_out,      'out',      'text-red-700',                    'bg-red-100'],
      ['Expiring Soon',   $m_expiring, 'expiring', 'text-orange-700',                 'bg-orange-100'],
      ['Pending Reorders',$m_reorders, null,       'text-emerald-700',                'bg-emerald-100'],
    ];
    foreach ($cards as [$label, $value, $status, $text, $chip]):
      $href = $status !== null ? inv_url(['status' => $status]) : 'requisitions.php';
    ?>
    <a href="<?= htmlspecialchars($href) ?>"
       class="group rounded-xl bg-white border border-[var(--latte,#efe0cc)] p-4
              hover:border-[var(--caramel,#c47d3e)] hover:shadow-md transition">
      <span class="block text-[11px] font-semibold uppercase tracking-wide text-[var(--text-muted,#8b7c88)]"><?= $label ?></span>
      <span class="mt-2 inline-flex items-center gap-2">
        <span class="text-[26px] font-extrabold leading-none <?= $text ?>"><?= (int)$value ?></span>
        <?php if ($value > 0 && $status !== 'all'): ?>
          <span class="w-2 h-2 rounded-full <?= $chip ?>"></span>
        <?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Controls -->
  <div class="rounded-xl bg-white border border-[var(--latte,#efe0cc)] p-3 mb-4 flex flex-wrap items-center gap-2">
    <div class="flex gap-1.5 mr-1">
      <a href="<?= htmlspecialchars(inv_url(['view'=>null])) ?>"
         class="px-3 py-2 rounded-lg text-[12.5px] font-semibold <?= $view==='active'
            ? 'bg-[var(--espresso,#3a2417)] text-white'
            : 'border border-[var(--latte,#efe0cc)] hover:bg-[var(--accent-lt,#fcefe1)]' ?>">Active</a>
      <a href="<?= htmlspecialchars(inv_url(['view'=>'archived'])) ?>"
         class="px-3 py-2 rounded-lg text-[12.5px] font-semibold <?= $view==='archived'
            ? 'bg-[var(--espresso,#3a2417)] text-white'
            : 'border border-[var(--latte,#efe0cc)] hover:bg-[var(--accent-lt,#fcefe1)]' ?>">
        Archived<?= $archived_count ? ' (' . $archived_count . ')' : '' ?></a>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2 flex-1 min-w-[260px]">
      <?php if ($view === 'archived'): ?><input type="hidden" name="view" value="archived"><?php endif; ?>

      <div class="relative flex-1 min-w-[180px]">
        <input type="search" name="search" id="inv-search" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search name or brand…" autocomplete="off"
               class="w-full pl-9 pr-3 py-2 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]
                      focus:outline-none focus:border-[var(--caramel,#c47d3e)]">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[13px] text-[var(--text-muted,#8b7c88)] flex items-center"><?= icon('search', 14) ?></span>
      </div>

      <select name="cat" onchange="this.form.submit()"
              class="py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
        <option value="">All categories</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filter_cat === (int)$c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>

      <select name="status" onchange="this.form.submit()"
              class="py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
        <?php foreach ([
            'all'=>'All statuses','low'=>'Low stock','out'=>'Out of stock',
            'expiring'=>'Expiring soon','expired'=>'Expired',
        ] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter_status===$k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>

      <button class="px-3 py-2 rounded-lg text-[12.5px] font-semibold border border-[var(--latte,#efe0cc)]
                     hover:bg-[var(--accent-lt,#fcefe1)]">Apply</button>
      <?php if ($search || $filter_cat || $filter_status !== 'all'): ?>
      <a href="<?= htmlspecialchars(inv_url(['search'=>null,'cat'=>null,'status'=>null])) ?>"
         class="px-3 py-2 rounded-lg text-[12.5px] font-semibold text-[var(--text-muted,#8b7c88)]
                hover:text-red-600">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <div class="rounded-xl bg-white border border-[var(--latte,#efe0cc)] overflow-hidden">
    <div class="max-h-[62vh] overflow-auto">
      <table class="w-full border-collapse text-[13px]">
        <thead class="sticky top-0 z-10 bg-[var(--accent-lt,#fcefe1)]">
          <tr class="text-left text-[11px] uppercase tracking-wide text-[var(--text-muted,#8b7c88)]">
            <th class="px-4 py-3 font-bold">Item</th>
            <th class="px-4 py-3 font-bold">Category</th>
            <th class="px-4 py-3 font-bold text-right">Stock</th>
            <th class="px-4 py-3 font-bold">Stock status</th>
            <th class="px-4 py-3 font-bold">Expiry</th>
            <th class="px-4 py-3 font-bold">Supplier</th>
            <th class="px-4 py-3 font-bold text-right">Actions</th>
          </tr>
        </thead>
        <tbody id="inv-tbody">
        <?php if (!$ingredients): ?>
          <tr><td colspan="7" class="px-4 py-14 text-center text-[13px] text-[var(--text-muted,#8b7c88)]">
            No items match these filters.
          </td></tr>
        <?php else: foreach ($ingredients as $i):
            $badge = $i['expiry_badge'];
            $stock_map = [
              'ok'  => ['Healthy',      'bg-emerald-50 text-emerald-700 border-emerald-200'],
              'low' => ['Low stock',    'bg-amber-50 text-amber-700 border-amber-200'],
              'out' => ['Out of stock', 'bg-red-50 text-red-700 border-red-200'],
            ];
            [$stock_label, $stock_cls] = $stock_map[$i['stock_state']];
            $payload = htmlspecialchars(json_encode([
                'id' => (int)$i['id'], 'name' => $i['name'], 'brand' => $i['brand'],
                'unit' => $i['unit'], 'cat_id' => (int)$i['cat_id'],
                'reorder_at' => (float)$i['reorder_at'],
                'reorder_quantity' => (float)$i['reorder_quantity'],
                'default_supplier_id' => (int)$i['default_supplier_id'],
                'auto_reorder' => (int)$i['auto_reorder'],
                'quantity' => (float)$i['quantity'],
                'unit_cost' => $i['unit_cost'] !== null ? (float)$i['unit_cost'] : null,
                'cost_unit' => $i['cost_unit'] ?? null,
            ]), ENT_QUOTES, 'UTF-8');
        ?>
          <tr class="border-t border-[var(--latte,#efe0cc)] hover:bg-[var(--accent-lt,#fcefe1)] transition
                     <?= $view === 'archived' ? 'opacity-70' : '' ?>"
              data-name="<?= htmlspecialchars(strtolower($i['name'] . ' ' . $i['brand'])) ?>">

            <td class="px-4 py-3">
              <div class="font-bold"><?= htmlspecialchars($i['name']) ?></div>
              <div class="text-[11px] text-[var(--text-muted,#8b7c88)]">
                <?= $i['brand'] ? htmlspecialchars($i['brand']) . ' · ' : '' ?>
                threshold <?= $fmt($i['reorder_at']) ?> <?= htmlspecialchars($i['unit']) ?>
                <?php if ((int)$i['auto_reorder'] === 1): ?>
                  <span class="ml-1 text-emerald-600 font-semibold">· auto</span>
                  <?php
                    $reorder_target_qty = (float)$i['reorder_quantity'] ?: max((float)$i['reorder_at'] * 2, 10);
                    if ($i['unit_cost'] !== null && (float)$i['unit_cost'] > 0):
                      $est_total = $reorder_target_qty * (float)$i['unit_cost'];
                  ?>
                    <span class="text-stone-500 font-normal">· est. ₱<?= number_format($est_total, 2) ?></span>
                  <?php else: ?>
                    <span class="text-stone-400 font-normal">· (cost unset)</span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($i['unit_cost'] !== null && (float)$i['unit_cost'] > 0): ?>
                  <span class="text-stone-400">· ₱<?= number_format((float)$i['unit_cost'], 2) ?>/<?= htmlspecialchars($i['cost_unit'] ?: $i['unit']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <td class="px-4 py-3 text-[12px] text-[var(--text-muted,#8b7c88)]">
              <span class="inline-flex items-center gap-1.5">
                <?= icon($i['cat_icon'] ?: 'package', 14) ?>
                <span><?= htmlspecialchars($i['cat_name']) ?></span>
              </span>
            </td>

            <td class="px-4 py-3 text-right font-bold tabular-nums">
              <?= $fmt($i['quantity']) ?> <span class="text-[11px] font-medium text-[var(--text-muted,#8b7c88)]"><?= htmlspecialchars($i['unit']) ?></span>
            </td>

            <td class="px-4 py-3">
              <span class="inline-flex items-center px-2 py-1 rounded-md border text-[11px] font-semibold <?= $stock_cls ?>">
                <?= $stock_label ?>
              </span>
            </td>

            <td class="px-4 py-3">
              <button type="button" onclick="openBatches(<?= (int)$i['id'] ?>)"
                      class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border text-[11px]
                             font-semibold <?= $badge['classes'] ?> hover:brightness-95"
                      title="View and edit batches">
                <span class="w-1.5 h-1.5 rounded-full <?= $badge['dot'] ?>"></span>
                <?= htmlspecialchars($badge['label']) ?>
              </button>
              <?php if ($i['active_batches'] > 1): ?>
                <span class="ml-1 text-[10px] text-[var(--text-muted,#8b7c88)]"><?= $i['active_batches'] ?> batches</span>
              <?php endif; ?>
            </td>

            <td class="px-4 py-3 text-[12px] text-[var(--text-muted,#8b7c88)]">
              <?= $i['supplier_name'] ? htmlspecialchars($i['supplier_name']) : '—' ?>
            </td>

            <td class="px-4 py-3">
              <div class="flex justify-end gap-1.5">
                <?php if ($can_manage && $view === 'active'): ?>
                  <button onclick='openRestock(<?= $payload ?>)'
                          class="px-2.5 py-1.5 rounded-md text-[11.5px] font-semibold
                                 border border-[var(--latte,#efe0cc)] hover:bg-white">Restock</button>
                  <button onclick='openEdit(<?= $payload ?>)'
                          class="px-2.5 py-1.5 rounded-md text-[11.5px] font-semibold
                                 border border-[var(--latte,#efe0cc)] hover:bg-white">Edit</button>
                  <button onclick='confirmAction("archive", <?= (int)$i['id'] ?>, <?= htmlspecialchars(json_encode($i['name']), ENT_QUOTES, 'UTF-8') ?>)'
                          class="px-2.5 py-1.5 rounded-md text-[11.5px] font-semibold
                                 border border-[var(--latte,#efe0cc)] text-[var(--text-muted,#8b7c88)]
                                 hover:text-amber-700 hover:border-amber-300">Archive</button>
                <?php elseif ($can_manage && $view === 'archived'): ?>
                  <button onclick='confirmAction("restore", <?= (int)$i['id'] ?>, <?= htmlspecialchars(json_encode($i['name']), ENT_QUOTES, 'UTF-8') ?>)'
                          class="px-2.5 py-1.5 rounded-md text-[11.5px] font-semibold
                                 border border-emerald-200 text-emerald-700 hover:bg-emerald-50">Restore</button>
                  <button onclick='confirmAction("purge", <?= (int)$i['id'] ?>, <?= htmlspecialchars(json_encode($i['name']), ENT_QUOTES, 'UTF-8') ?>)'
                          class="px-2.5 py-1.5 rounded-md text-[11.5px] font-semibold
                                 border border-red-200 text-red-700 hover:bg-red-50">Delete</button>
                <?php else: ?>
                  <span class="text-[11px] text-[var(--text-muted,#8b7c88)]">View only</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- ══════════ MODALS ══════════ -->

<?php if ($can_manage): ?>
<!-- Add -->
<div id="modal-add" class="modal-overlay hidden fixed inset-0 z-[500] bg-black/50 items-center justify-center p-4">
  <div class="w-full max-w-[440px] rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--latte,#efe0cc)] flex items-center justify-between">
      <strong class="text-[15px]">Add Ingredient</strong>
      <button type="button" onclick="closeModal('modal-add')" class="text-[var(--text-muted,#8b7c88)] hover:text-[var(--espresso,#3a2417)] transition-colors p-1" aria-label="Close"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" class="px-5 py-4 space-y-3">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="return_view"   value="<?= htmlspecialchars($view) ?>">
      <input type="hidden" name="return_cat"    value="<?= (int)$filter_cat ?: '' ?>">
      <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">

      <div class="grid grid-cols-2 gap-3">
        <label class="col-span-2 block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Name *</span>
          <input name="name" required class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Brand</span>
          <input name="brand" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Unit</span>
          <input name="unit" value="pcs" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="col-span-2 block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Category *</span>
          <select name="cat_id" required class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= (int)$c['shelf_life_days'] ?>d shelf life</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Opening qty</span>
          <input name="quantity" type="number" step="0.01" min="0" value="0"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Reorder threshold</span>
          <input name="reorder_at" type="number" step="0.01" min="0" value="5"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Reorder quantity</span>
          <input name="reorder_quantity" type="number" step="0.01" min="0" value="0" placeholder="auto"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Default supplier</span>
          <select name="default_supplier_id" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
            <option value="">— none —</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Est. Unit Cost (₱)</span>
          <input name="unit_cost" type="number" step="0.01" min="0" placeholder="0.00"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Cost Unit (optional)</span>
          <input name="cost_unit" placeholder="e.g. kg, pack, pcs"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="col-span-2 flex items-center gap-2 text-[12.5px]">
          <input type="checkbox" name="auto_reorder" checked class="w-4 h-4 accent-[var(--caramel,#c47d3e)]">
          Automatically file a purchase requisition when stock hits the threshold
        </label>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closeModal('modal-add')"
                class="flex-1 py-2.5 rounded-lg text-[13px] font-semibold border border-[var(--latte,#efe0cc)]">Cancel</button>
        <button class="flex-1 py-2.5 rounded-lg text-[13px] font-bold text-white bg-[var(--caramel,#c47d3e)]">Add Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit -->
<div id="modal-edit" class="modal-overlay hidden fixed inset-0 z-[500] bg-black/50 items-center justify-center p-4">
  <div class="w-full max-w-[440px] rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--latte,#efe0cc)] flex items-center justify-between">
      <strong class="text-[15px]">Edit Item</strong>
      <button type="button" onclick="closeModal('modal-edit')" class="text-[var(--text-muted,#8b7c88)] hover:text-[var(--espresso,#3a2417)] transition-colors p-1" aria-label="Close"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" class="px-5 py-4 space-y-3">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ingredient_id" id="e-id">
      <input type="hidden" name="return_view"   value="<?= htmlspecialchars($view) ?>">
      <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">

      <div class="grid grid-cols-2 gap-3">
        <label class="col-span-2 block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Name *</span>
          <input name="name" id="e-name" required class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Brand</span>
          <input name="brand" id="e-brand" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Unit</span>
          <input name="unit" id="e-unit" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="col-span-2 block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Category</span>
          <select name="cat_id" id="e-cat" class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Reorder threshold</span>
          <input name="reorder_at" id="e-reorder" type="number" step="0.01" min="0"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Reorder quantity</span>
          <input name="reorder_quantity" id="e-reorder-qty" type="number" step="0.01" min="0"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="col-span-2 block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Default supplier</span>
          <select name="default_supplier_id" id="e-supplier"
                  class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
            <option value="">— none —</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Est. Unit Cost (₱)</span>
          <input name="unit_cost" id="e-unit-cost" type="number" step="0.01" min="0" placeholder="0.00"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="block">
          <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Cost Unit (optional)</span>
          <input name="cost_unit" id="e-cost-unit" placeholder="e.g. kg, pack, pcs"
                 class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        </label>
        <label class="col-span-2 flex items-center gap-2 text-[12.5px]">
          <input type="checkbox" name="auto_reorder" id="e-auto" class="w-4 h-4 accent-[var(--caramel,#c47d3e)]">
          Auto-reorder enabled
        </label>
      </div>

      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closeModal('modal-edit')"
                class="flex-1 py-2.5 rounded-lg text-[13px] font-semibold border border-[var(--latte,#efe0cc)]">Cancel</button>
        <button class="flex-1 py-2.5 rounded-lg text-[13px] font-bold text-white bg-[var(--caramel,#c47d3e)]">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Restock -->
<div id="modal-restock" class="modal-overlay hidden fixed inset-0 z-[500] bg-black/50 items-center justify-center p-4">
  <div class="w-full max-w-[380px] rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--latte,#efe0cc)] flex items-center justify-between">
      <strong class="text-[15px]">Restock <span id="r-title" class="font-normal"></span></strong>
      <button type="button" onclick="closeModal('modal-restock')" class="text-[var(--text-muted,#8b7c88)] hover:text-[var(--espresso,#3a2417)] transition-colors p-1" aria-label="Close"><?= icon('x', 18) ?></button>
    </div>
    <form method="POST" class="px-5 py-4 space-y-3">
      <input type="hidden" name="action" value="restock">
      <input type="hidden" name="ingredient_id" id="r-id">
      <input type="hidden" name="return_view" value="<?= htmlspecialchars($view) ?>">

      <label class="block">
        <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Quantity received *</span>
        <input name="qty" id="r-qty" type="number" step="0.01" min="0.01" required
               class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
      </label>
      <label class="block">
        <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Expiry date</span>
        <input name="expiry_date" type="date"
               class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)]">
        <span class="block mt-1 text-[10.5px] text-[var(--text-muted,#8b7c88)]">
          Leave blank to compute it from the category shelf life.
        </span>
      </label>
      <label class="block">
        <span class="text-[11.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Supplier</span>
        <select name="supplier_id" id="r-supplier"
                class="mt-1 w-full py-2 px-3 rounded-lg text-[13px] border border-[var(--latte,#efe0cc)] bg-white">
          <option value="">— none —</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <div class="flex gap-2 pt-2">
        <button type="button" onclick="closeModal('modal-restock')"
                class="flex-1 py-2.5 rounded-lg text-[13px] font-semibold border border-[var(--latte,#efe0cc)]">Cancel</button>
        <button class="flex-1 py-2.5 rounded-lg text-[13px] font-bold text-white bg-[var(--caramel,#c47d3e)]">Record</button>
      </div>
    </form>
  </div>
</div>

<!-- Generic confirm -->
<div id="modal-confirm" class="modal-overlay hidden fixed inset-0 z-[600] bg-black/50 items-center justify-center p-4">
  <div class="w-full max-w-[360px] rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="px-5 py-5">
      <strong id="c-title" class="block text-[15px] mb-2"></strong>
      <p id="c-text" class="text-[13px] leading-5 text-[var(--text-muted,#8b7c88)]"></p>
    </div>
    <form method="POST" class="px-5 pb-5 flex gap-2">
      <input type="hidden" name="action" id="c-action">
      <input type="hidden" name="ingredient_id" id="c-id">
      <input type="hidden" name="return_view" value="<?= htmlspecialchars($view) ?>">
      <button type="button" onclick="closeModal('modal-confirm')"
              class="flex-1 py-2.5 rounded-lg text-[13px] font-semibold border border-[var(--latte,#efe0cc)]">Cancel</button>
      <button id="c-submit" class="flex-1 py-2.5 rounded-lg text-[13px] font-bold text-white bg-[var(--caramel,#c47d3e)]">Confirm</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Batches / expiry -->
<div id="modal-batches" class="modal-overlay hidden fixed inset-0 z-[550] bg-black/50 items-center justify-center p-4">
  <div class="w-full max-w-[640px] rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-[var(--latte,#efe0cc)] flex items-center justify-between">
      <strong class="text-[15px]">Batches — <span id="b-title" class="font-normal"></span></strong>
      <button type="button" onclick="closeModal('modal-batches')" class="text-[var(--text-muted,#8b7c88)] hover:text-[var(--espresso,#3a2417)] transition-colors p-1" aria-label="Close"><?= icon('x', 18) ?></button>
    </div>
    <div id="b-body" class="px-5 py-4 max-h-[60vh] overflow-y-auto text-[13px]">
      <p class="text-center py-8 text-[var(--text-muted,#8b7c88)]">Loading…</p>
    </div>
  </div>
</div>

<!-- Toast -->
<?php if ($toast): ?>
<div id="toast" class="fixed bottom-6 right-6 z-[700] px-4 py-3 rounded-xl shadow-2xl text-[13px] font-semibold
     <?= $toast_type === 'error' ? 'bg-red-600 text-white' : 'bg-[var(--espresso,#3a2417)] text-white' ?>">
  <?= $toast ?>
</div>
<script>setTimeout(() => document.getElementById('toast')?.remove(), 4500);</script>
<?php endif; ?>

<script>
const CAN_EDIT_EXPIRY = <?= $can_expiry ? 'true' : 'false' ?>;
const CAN_MANAGE      = <?= $can_manage ? 'true' : 'false' ?>;

function openModal(id)  { const m = document.getElementById(id); m.classList.remove('hidden'); m.classList.add('flex'); }
function closeModal(id) { const m = document.getElementById(id); m.classList.add('hidden');    m.classList.remove('flex'); }

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (document.getElementById('shift-gate')) return;   // gate is non-dismissible
  document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(m => closeModal(m.id));
});

// ── Live client-side search (on top of the server filter) ──
document.getElementById('inv-search')?.addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#inv-tbody tr[data-name]').forEach(row => {
    row.style.display = !q || row.dataset.name.includes(q) ? '' : 'none';
  });
});

// ── Row action modals ──
function openEdit(p) {
  document.getElementById('e-id').value          = p.id;
  document.getElementById('e-name').value        = p.name  || '';
  document.getElementById('e-brand').value       = p.brand || '';
  document.getElementById('e-unit').value        = p.unit  || '';
  document.getElementById('e-cat').value         = p.cat_id;
  document.getElementById('e-reorder').value     = p.reorder_at;
  document.getElementById('e-reorder-qty').value = p.reorder_quantity;
  document.getElementById('e-supplier').value    = p.default_supplier_id || '';
  document.getElementById('e-unit-cost').value   = (p.unit_cost !== null && p.unit_cost !== undefined) ? p.unit_cost : '';
  document.getElementById('e-cost-unit').value   = p.cost_unit || '';
  document.getElementById('e-auto').checked      = p.auto_reorder === 1;
  openModal('modal-edit');
}

function openRestock(p) {
  document.getElementById('r-id').value       = p.id;
  document.getElementById('r-title').textContent = '· ' + p.name;
  document.getElementById('r-qty').value      = '';
  document.getElementById('r-supplier').value = p.default_supplier_id || '';
  openModal('modal-restock');
}

function confirmAction(action, id, name) {
  const copy = {
    archive: ['Archive item?', `"${name}" will be hidden from the active list. Stock history is kept.`, 'Archive'],
    restore: ['Restore item?', `"${name}" will return to the active inventory list.`, 'Restore'],
    purge:   ['Delete permanently?', `"${name}" and all its batches will be erased. This cannot be undone.`, 'Delete'],
  }[action];

  document.getElementById('c-title').textContent  = copy[0];
  document.getElementById('c-text').textContent   = copy[1];
  document.getElementById('c-action').value       = action;
  document.getElementById('c-id').value           = id;

  const btn = document.getElementById('c-submit');
  btn.textContent = copy[2];
  btn.className = 'flex-1 py-2.5 rounded-lg text-[13px] font-bold text-white ' +
    (action === 'purge' ? 'bg-red-600' : 'bg-[var(--caramel,#c47d3e)]');

  openModal('modal-confirm');
}

// ── Batch / expiry management ──
async function openBatches(ingredientId) {
  const body = document.getElementById('b-body');
  body.innerHTML = '<p class="text-center py-8 text-[var(--text-muted,#8b7c88)]">Loading…</p>';
  openModal('modal-batches');

  try {
    const res  = await fetch('../api/inventory_batches.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'list', ingredient_id: ingredientId })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Could not load batches.');

    document.getElementById('b-title').textContent = data.item ? data.item.name : '';

    if (!data.batches.length) {
      body.innerHTML = '<p class="text-center py-10 text-[var(--text-muted,#8b7c88)]">' +
                       'No batches recorded yet. They are created when stock is received.</p>';
      return;
    }

    body.innerHTML = data.batches.map(b => `
      <div class="border border-[var(--latte,#efe0cc)] rounded-xl p-3 mb-2.5" data-batch="${b.id}">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-bold text-[13px]">
              ${b.batch_ref ? escapeHtml(b.batch_ref) : 'Batch #' + b.id}
              <span class="ml-1 font-normal text-[11px] text-[var(--text-muted,#8b7c88)]">
                ${b.qty_remaining} / ${b.qty_received} ${escapeHtml(b.unit)} left
              </span>
            </div>
            <div class="text-[11px] text-[var(--text-muted,#8b7c88)] mt-0.5">
              Delivered ${escapeHtml(b.delivery_human)}
              ${b.supplier_name ? ' · ' + escapeHtml(b.supplier_name) : ''}
              ${b.expiry_source === 'manual' ? ' · expiry set manually' : ''}
            </div>
          </div>
          <span class="shrink-0 inline-flex items-center gap-1.5 px-2 py-1 rounded-md border
                       text-[11px] font-semibold ${b.badge.classes}" data-badge>
            <span class="w-1.5 h-1.5 rounded-full ${b.badge.dot}"></span>${escapeHtml(b.badge.label)}
          </span>
        </div>

        ${data.can_edit ? `
        <div class="mt-3 flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="text-[10.5px] font-semibold text-[var(--text-muted,#8b7c88)]">Expiry date</span>
            <input type="date" value="${b.expiry_date || ''}" data-expiry-input
                   class="mt-1 py-1.5 px-2.5 rounded-lg text-[12.5px] border border-[var(--latte,#efe0cc)]">
          </label>
          <button type="button" onclick="saveExpiry(${b.id}, this)"
                  class="py-1.5 px-3 rounded-lg text-[12px] font-bold text-white bg-[var(--caramel,#c47d3e)]">Save</button>
          ${CAN_MANAGE && b.status !== 'discarded' && b.qty_remaining > 0 ? `
          <button type="button" onclick="discardBatch(${b.id}, this)"
                  class="py-1.5 px-3 rounded-lg text-[12px] font-semibold border border-red-200 text-red-700
                         hover:bg-red-50 ml-auto">Write off</button>` : ''}
          <span data-msg class="w-full text-[11px]"></span>
        </div>` : ''}
      </div>
    `).join('');
  } catch (e) {
    body.innerHTML = `<p class="text-center py-8 text-red-600">${escapeHtml(e.message)}</p>`;
  }
}

async function saveExpiry(batchId, btn) {
  const card  = btn.closest('[data-batch]');
  const input = card.querySelector('[data-expiry-input]');
  const msg   = card.querySelector('[data-msg]');
  btn.disabled = true;
  msg.textContent = '';

  try {
    const res  = await fetch('../api/inventory_batches.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_expiry', batch_id: batchId, expiry_date: input.value })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Update failed.');

    const badge = card.querySelector('[data-badge]');
    badge.className = 'shrink-0 inline-flex items-center gap-1.5 px-2 py-1 rounded-md border text-[11px] font-semibold ' + data.badge.classes;
    badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${data.badge.dot}"></span>${escapeHtml(data.badge.label)}`;

    msg.className = 'w-full text-[11px] text-emerald-600';
    msg.textContent = 'Saved.';
  } catch (e) {
    msg.className = 'w-full text-[11px] text-red-600';
    msg.textContent = e.message;
  } finally {
    btn.disabled = false;
  }
}

async function discardBatch(batchId, btn) {
  if (!confirm('Write off the remaining quantity in this batch? Stock will be deducted.')) return;
  btn.disabled = true;
  try {
    const res  = await fetch('../api/inventory_batches.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'discard', batch_id: batchId, reason: 'Written off from Inventory' })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Could not write off batch.');
    location.reload();
  } catch (e) {
    alert(e.message);
    btn.disabled = false;
  }
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
</script>
</body>
</html>