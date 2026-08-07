<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('dashboard.view');
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
  <title>Dashboard — Kofee Manila</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/home.css"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-home" class="page active">
  <div class="page-header">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($fname) ?> <?= icon('sun', 20) ?></h1>
      <p><?= date('l, F j, Y') ?> — Today's overview</p>
    </div>
    <div class="signed-in-pill">
      Signed in as <b>@<?= htmlspecialchars($user['username']) ?></b>
      <div class="signed-in-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
  </div>

  <div class="page-body">

    <!-- Stat cards -->
    <div class="home-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--accent-lt)"><?= icon('coin') ?></div>
        <div class="stat-label">Today's Sales</div>
        <div class="stat-value">₱<?= number_format($today_sales['total']) ?></div>
        <div class="stat-sub"><?= $today_sales['cnt'] ?> orders today</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--green-lt);color:var(--green)"><?= icon('order') ?></div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= $today_sales['cnt'] ?></div>
        <div class="stat-sub">Today</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue)"><?= icon('analytics') ?></div>
        <div class="stat-label">Avg Order</div>
        <div class="stat-value">₱<?= number_format($avg_order) ?></div>
        <div class="stat-sub">Per transaction</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;color:#ad1457"><?= icon('menu') ?></div>
        <div class="stat-label">Top Item</div>
        <div class="stat-value" style="font-size:16px">
          <?= $top_item ? htmlspecialchars($top_item['name']) : '—' ?>
        </div>
        <div class="stat-sub">
          <?= $top_item ? $top_item['qty'] . ' cups today' : 'No orders yet' ?>
        </div>
      </div>
    </div>

    <!-- Quick access -->
    <div>
      <div class="section-title">Quick Access</div>
      <div class="home-shortcuts">
        <a href="menu.php" class="shortcut-card">
          <div class="shortcut-icon"><?= icon('order') ?></div>
          <div><h3>New Order</h3><p>Start taking an order now</p></div>
        </a>
        <a href="history.php" class="shortcut-card">
          <div class="shortcut-icon"><?= icon('history') ?></div>
          <div><h3>Order History</h3><p>View all past transactions</p></div>
        </a>
        <a href="analytics.php" class="shortcut-card">
          <div class="shortcut-icon"><?= icon('analytics') ?></div>
          <div><h3>Analytics</h3><p>Sales performance overview</p></div>
        </a>
        <a href="manage_users.php" class="shortcut-card">
          <div class="shortcut-icon"><?= icon('employees') ?></div>
          <div><h3>Manage Staff</h3><p>Add and manage user accounts</p></div>
        </a>
      </div>
    </div>

    <!-- Recent orders -->
    <div class="recent-section">
      <div class="recent-header">
        <h2>Recent Orders</h2>
        <a href="history.php" style="font-size:12px;color:var(--caramel);font-weight:600;text-decoration:none">View all →</a>
      </div>
      <table class="recent-table">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Items</th>
            <th>Type</th>
            <th>Status</th>
            <th>Total</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
            <tr class="empty-row"><td colspan="6">🫙 No orders yet today</td></tr>
          <?php else: foreach ($recent as $o):
            $pm = strtolower($o['payment_method']);
            $pm_class = str_contains($pm,'dine') ? 'badge-dine' : (str_contains($pm,'take') ? 'badge-take' : 'badge-delivery');
            $st_class = $o['status'] === 'complete' ? 'badge-complete' : 'badge-pending';
          ?>
            <tr>
              <td style="font-weight:700">#<?= str_pad($o['id'],4,'0',STR_PAD_LEFT) ?></td>
              <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted)">
                <?= htmlspecialchars($o['items'] ?? '—') ?>
              </td>
              <td><span class="badge <?= $pm_class ?>"><?= htmlspecialchars($o['payment_method']) ?></span></td>
              <td><span class="badge <?= $st_class ?>"><?= ucfirst($o['status'] ?: 'pending') ?></span></td>
              <td style="font-weight:700">₱<?= number_format($o['total_amount']) ?></td>
              <td style="color:var(--text-muted)"><?= $o['created_at'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Role Access Overview — pulled live from Manage Permissions -->
    <div>
      <div class="section-title">Role Access Overview</div>
      <p style="font-size:12px;color:var(--text-muted);margin:-6px 0 12px">What each role can do — pulled from Manage Permissions</p>
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
              <?= $rk === 'admin' ? 'Always allowed' : $count . ' / ' . $total_perms ?>
            </span>
          </div>
          <div class="rao-list">
            <?php foreach ($all_permissions as $p):
              $on = $rk === 'admin' || in_array($p['perm_key'], $granted, true);
            ?>
              <div class="rao-item <?= $on ? '' : 'off' ?>">
                <span class="rao-dot"></span><?= htmlspecialchars($p['label']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

</body>
</html>
