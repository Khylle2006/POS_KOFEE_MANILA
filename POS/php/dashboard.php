<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_login();
require_permission('dashboard.view');
require_clocked_in_for_pos();
ob_start();

$pdo  = get_db();
$user = current_user();

// ── Today's stats ─────────────────────────────
$today_sales = $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
    FROM orders WHERE created_at = CURDATE()
")->fetch();

$avg_order = $today_sales['cnt'] > 0
    ? round($today_sales['total'] / $today_sales['cnt'], 2) : 0;

$top_item = $pdo->query("
    SELECT p.name, SUM(oi.quantity) AS qty
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.created_at = CURDATE()
    GROUP BY p.id, p.name
    ORDER BY qty DESC LIMIT 1
")->fetch();

// ── Recent orders ─────────────────────────────
$recent = $pdo->query("
    SELECT o.id, o.total_amount, o.payment_method, o.status, o.created_at,
           GROUP_CONCAT(CONCAT(p.name,' x',oi.quantity) SEPARATOR ', ') AS items,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    GROUP BY o.id
    ORDER BY o.id DESC LIMIT 8
")->fetchAll();

// ── Role Access Overview (real data from permissions/role_permissions) ──
$all_roles       = get_all_roles();           // role_key, label, is_system
$all_permissions = get_all_permissions();     // perm_key, label, category, description
$role_perm_map   = get_all_role_permissions(); // role_key => [perm_key, ...]
$total_perms     = count($all_permissions);

// ── Quick Access shortcuts — only show what this user can actually open ──
$shortcut_defs = [
    ['perm' => 'orders.new',     'href' => 'menu.php',          'icon' => 'order',     'title' => 'New Order',     'desc' => 'Start taking an order now'],
    ['perm' => 'orders.pending', 'href' => 'pending_orders.php','icon' => 'pending',   'title' => 'Pending Orders','desc' => 'Manage active order queue'],
    ['perm' => 'orders.history', 'href' => 'history.php',       'icon' => 'history',   'title' => 'Order History', 'desc' => 'View all past transactions'],
    ['perm' => 'inventory.view', 'href' => 'inventory.php',     'icon' => 'inventory', 'title' => 'Inventory',     'desc' => 'Manage ingredient stocks'],
    ['perm' => 'menu.manage',    'href' => 'add_item.php',      'icon' => 'menu',      'title' => 'Menu Manager',  'desc' => 'Edit drinks and prices'],
    ['perm' => 'analytics.view', 'href' => 'analytics.php',     'icon' => 'analytics', 'title' => 'Analytics',     'desc' => 'Sales performance overview'],
    ['perm' => 'users.manage',   'href' => 'manage_users.php',  'icon' => 'employees', 'title' => 'Manage Staff',  'desc' => 'Add and manage user accounts'],
    ['perm' => 'login_approval.manage', 'href' => 'login_approvals.php', 'icon' => 'shield-check', 'title' => 'Login Approvals', 'desc' => 'Review staff geolocation & logins'],
];
$shortcuts = array_values(array_filter($shortcut_defs, fn($s) => has_permission($s['perm'])));
$shortcuts = array_slice($shortcuts, 0, 8);

// ── Greeting ──────────────────────────────────
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fname = $user['firstname'] ?: $user['username'];
$initials = strtoupper(substr($fname, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — BrewVanti POS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css?v=<?= filemtime(__DIR__ . '/../css/style.css') ?>"/>
  <link rel="stylesheet" href="../css/sidebar.css?v=<?= filemtime(__DIR__ . '/../css/sidebar.css') ?>"/>
  <link rel="stylesheet" href="../css/home.css?v=<?= filemtime(__DIR__ . '/../css/home.css') ?>"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-home" class="page active">
  <div class="page-header">
    <div>
      <h1>
        <?= $greeting ?>, <?= htmlspecialchars($fname) ?>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
      </h1>
      <p><?= date('l, F j, Y') ?> &bull; Executive store operations &amp; live overview</p>
    </div>
    <div class="signed-in-pill">
      Signed in as <b>@<?= htmlspecialchars($user['username']) ?></b>
      <div class="signed-in-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
  </div>

  <div class="page-body">

    <!-- ── 2.5D KPI Metric Cards ── -->
    <div class="home-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(217,186,133,0.18); color:#D9BA85; border:1px solid rgba(217,186,133,0.38);">
          <?= icon('coin', 22) ?>
        </div>
        <div class="stat-label">Today's Sales</div>
        <div class="stat-value">₱<?= number_format($today_sales['total']) ?></div>
        <div class="stat-sub"><?= $today_sales['cnt'] ?> orders processed today</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(16,185,129,0.14); color:#10B981; border:1px solid rgba(16,185,129,0.32);">
          <?= icon('order', 22) ?>
        </div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= $today_sales['cnt'] ?></div>
        <div class="stat-sub">Live today's count</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(59,130,246,0.14); color:#3B82F6; border:1px solid rgba(59,130,246,0.32);">
          <?= icon('analytics', 22) ?>
        </div>
        <div class="stat-label">Avg Order Value</div>
        <div class="stat-value">₱<?= number_format($avg_order) ?></div>
        <div class="stat-sub">Per transaction average</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(236,72,153,0.14); color:#EC4899; border:1px solid rgba(236,72,153,0.32);">
          <?= icon('menu', 22) ?>
        </div>
        <div class="stat-label">Top Performing Item</div>
        <div class="stat-value" style="font-size:<?= $top_item ? '20px' : '26px' ?>; font-weight:700;">
          <?= $top_item ? htmlspecialchars($top_item['name']) : '—' ?>
        </div>
        <div class="stat-sub">
          <?= $top_item ? $top_item['qty'] . ' cups sold today' : 'No orders recorded yet' ?>
        </div>
      </div>
    </div>

    <!-- ── 2.5D Quick Access Shortcuts ── -->
    <?php if (!empty($shortcuts)): ?>
    <div>
      <div class="section-title">Quick Access Shortcuts</div>
      <div class="home-shortcuts">
        <?php foreach ($shortcuts as $s): ?>
        <a href="<?= htmlspecialchars($s['href']) ?>" class="shortcut-card">
          <div class="shortcut-icon"><?= icon($s['icon'], 20) ?></div>
          <div>
            <h3><?= htmlspecialchars($s['title']) ?></h3>
            <p><?= htmlspecialchars($s['desc']) ?></p>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Recent Orders Ledger ── -->
    <div class="recent-section">
      <div class="recent-header">
        <h2>
          <?= icon('order', 18) ?>
          Recent Orders
          <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; background:rgba(217,186,133,0.2); color:#8C662D; border:1px solid rgba(217,186,133,0.35); text-transform:uppercase; letter-spacing:0.8px; margin-left:6px;">
            Live Feed
          </span>
        </h2>
        <a href="history.php" style="font-size:12.5px; color:#D9BA85; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
          View all history &rarr;
        </a>
      </div>
      <div class="table-scroll-wrapper">
        <table class="recent-table">
          <thead>
            <tr>
              <th class="col-sticky">Order #</th>
              <th>Items</th>
              <th>Payment Type</th>
              <th>Status</th>
              <th>Total</th>
              <th>Date &amp; Time</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr class="empty-row">
                <td colspan="6" style="text-align:center; padding:28px 20px; color:#68584B;">
                  <?= icon('history', 18) ?> No orders recorded yet today.
                </td>
              </tr>
            <?php else: foreach ($recent as $o):
              $pm = strtolower($o['payment_method'] ?? '');
              $pm_class = str_contains($pm,'dine') ? 'badge-dine' : (str_contains($pm,'take') ? 'badge-take' : (str_contains($pm,'paymongo') ? 'badge-paymongo' : 'badge-delivery'));
              $st = strtolower($o['status'] ?? 'pending');
              $st_class = ($st === 'complete' || $st === 'completed') ? 'badge-complete' : (($st === 'cancelled' || $st === 'canceled') ? 'badge-cancelled' : 'badge-pending');
            ?>
              <tr>
                <td class="col-sticky" style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:800; color:#D9BA85;">
                  #<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?>
                </td>
                <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#68584B; font-weight:500;">
                  <?= htmlspecialchars($o['items'] ?? '—') ?>
                </td>
                <td><span class="badge <?= $pm_class ?>"><?= htmlspecialchars($o['payment_method'] ?: 'Cash') ?></span></td>
                <td><span class="badge <?= $st_class ?>"><?= ucfirst($o['status'] ?: 'Pending') ?></span></td>
                <td style="font-family:'Playfair Display',serif; font-weight:700; color:#1E1517; font-size:14.5px;">
                  ₱<?= number_format($o['total_amount']) ?>
                </td>
                <td style="color:#8C786B; font-size:12px; font-weight:500;">
                  <?= htmlspecialchars($o['created_at']) ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Role Access Overview — Live from Permissions ── -->
    <div>
      <div class="section-title">Role Access Overview</div>
      <p style="font-size:12.5px; color:#68584B; margin:-6px 0 14px; font-weight:500;">
        Live privileges assigned to each role — synchronized with system security rules.
      </p>
      <div class="rao-grid">
        <?php foreach ($all_roles as $r):
          $rk = $r['role_key'];
          $granted = $rk === 'admin' ? array_column($all_permissions, 'perm_key') : ($role_perm_map[$rk] ?? []);
          $count = count($granted);
          $count_class = $rk === 'admin' ? 'full' : ($count === 0 ? 'none' : '');
        ?>
        <div class="rao-card">
          <div class="rao-head">
            <h3><?= htmlspecialchars($r['label']) ?></h3>
            <span class="rao-count <?= $count_class ?>">
              <?= $rk === 'admin' ? 'Always Allowed' : $count . ' / ' . $total_perms ?>
            </span>
          </div>
          <div class="rao-list">
            <?php foreach ($all_permissions as $p):
              $on = $rk === 'admin' || in_array($p['perm_key'], $granted, true);
            ?>
              <div class="rao-item <?= $on ? '' : 'off' ?>">
                <span class="rao-dot"></span>
                <?= htmlspecialchars($p['label']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- ── Interactive 2.5D Hover Tilt on KPI Cards ── -->
<script>
  document.querySelectorAll('.stat-card, .shortcut-card').forEach(card => {
    card.addEventListener('mousemove', (e) => {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width - 0.5;
      const y = (e.clientY - rect.top) / rect.height - 0.5;
      card.style.transform = `perspective(600px) rotateX(${(-y * 6).toFixed(2)}deg) rotateY(${(x * 6).toFixed(2)}deg) translateY(-4px)`;
    });
    card.addEventListener('mouseleave', () => {
      card.style.transform = '';
    });
  });
</script>

</body>
</html>
