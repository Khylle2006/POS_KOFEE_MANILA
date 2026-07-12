<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

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

// ── Greeting ──────────────────────────────────
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fname = $user['firstname'] ?: $user['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/home.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:     #c47d3e;
      --accent-lt:  #fdf3ea;
      --card-bg:    #ffffff;
      --border:     #ecddc8;
      --text-main:  #2c1a0e;
      --text-muted: #9a7e65;
      --bg:         #faf5ef;
      --green:      #2e7d32; --green-lt: #e8f5e9;
      --red:        #c62828; --red-lt:   #ffebee;
      --blue:       #1565c0; --blue-lt:  #e3f2fd;
      --amber:      #e65100; --amber-lt: #fff3e0;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    #page-home { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
    .page-header {
      padding: 22px 28px 0; flex-shrink: 0;
      display: flex; align-items: center; justify-content: space-between;
    }
    .page-header h1 { font-size: 22px; font-weight: 800; }
    .page-header p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
    .page-body { flex: 1; overflow-y: auto; padding: 20px 28px 28px; display: flex; flex-direction: column; gap: 22px; }

    /* stat cards */
    .home-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
    .stat-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 16px; padding: 18px 20px;
      display: flex; flex-direction: column; gap: 6px;
    }
    .stat-icon { width: 42px; height: 42px; border-radius: 12px; font-size: 20px; display: flex; align-items: center; justify-content: center; margin-bottom: 2px; }
    .stat-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
    .stat-value { font-size: 26px; font-weight: 800; color: var(--text-main); line-height: 1; }
    .stat-sub   { font-size: 11px; color: var(--text-muted); }

    /* shortcuts */
    .section-title { font-size: 14px; font-weight: 700; margin-bottom: 10px; }
    .home-shortcuts { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
    .shortcut-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 14px; padding: 16px;
      display: flex; align-items: center; gap: 12px;
      cursor: pointer; text-decoration: none; color: var(--text-main);
      transition: border-color .15s, transform .12s;
    }
    .shortcut-card:hover { border-color: var(--accent); transform: translateY(-2px); }
    .shortcut-icon { width: 42px; height: 42px; border-radius: 12px; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .shortcut-card h3 { font-size: 13px; font-weight: 700; }
    .shortcut-card p  { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

    /* recent table */
    .recent-section { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 16px; overflow: hidden; }
    .recent-header { padding: 16px 20px; border-bottom: 1.5px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
    .recent-header h2 { font-size: 14px; font-weight: 700; }
    .recent-table { width: 100%; border-collapse: collapse; }
    .recent-table th { padding: 10px 16px; font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; text-align: left; background: #fdf6ec; border-bottom: 1.5px solid var(--border); }
    .recent-table td { padding: 12px 16px; font-size: 12px; border-bottom: 1px solid #f5ede0; vertical-align: middle; }
    .recent-table tr:last-child td { border-bottom: none; }
    .recent-table tr:hover td { background: #fffaf5; }

    .badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .badge-dine     { background: var(--blue-lt);  color: var(--blue); }
    .badge-take     { background: var(--green-lt); color: var(--green); }
    .badge-delivery { background: var(--amber-lt); color: var(--amber); }
    .badge-complete { background: var(--green-lt); color: var(--green); }
    .badge-pending  { background: var(--amber-lt); color: var(--amber); }

    .empty-row td { text-align: center; padding: 32px; color: var(--text-muted); font-size: 13px; }

    @media (max-width: 900px) {
      .home-grid       { grid-template-columns: repeat(2,1fr); }
      .home-shortcuts  { grid-template-columns: repeat(2,1fr); }
    }
  </style>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-home" class="page active">
  <div class="page-header">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($fname) ?>! ☀️</h1>
      <p><?= date('l, F j, Y') ?> — Today's overview</p>
    </div>
  </div>

  <div class="page-body">

    <!-- Stat cards -->
    <div class="home-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff3e0">💰</div>
        <div class="stat-label">Today's Sales</div>
        <div class="stat-value">₱<?= number_format($today_sales['total']) ?></div>
        <div class="stat-sub"><?= $today_sales['cnt'] ?> orders today</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9">📋</div>
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= $today_sales['cnt'] ?></div>
        <div class="stat-sub">Today</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd">🛒</div>
        <div class="stat-label">Avg Order</div>
        <div class="stat-value">₱<?= number_format($avg_order) ?></div>
        <div class="stat-sub">Per transaction</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec">⭐</div>
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
          <div class="shortcut-icon" style="background:#fff3e0">📋</div>
          <div><h3>New Order</h3><p>Start taking an order now</p></div>
        </a>
        <a href="history.php" class="shortcut-card">
          <div class="shortcut-icon" style="background:#e8f5e9">🕐</div>
          <div><h3>Order History</h3><p>View all past transactions</p></div>
        </a>
        <a href="analytics.php" class="shortcut-card">
          <div class="shortcut-icon" style="background:#e3f2fd">📊</div>
          <div><h3>Analytics</h3><p>Sales performance overview</p></div>
        </a>
        <a href="manage_users.php" class="shortcut-card">
          <div class="shortcut-icon" style="background:#fce4ec">👥</div>
          <div><h3>Manage Staff</h3><p>Add and manage user accounts</p></div>
        </a>
      </div>
    </div>

    <!-- Recent orders -->
    <div class="recent-section">
      <div class="recent-header">
        <h2>Recent Orders</h2>
        <a href="../php/history.php" style="font-size:12px;color:var(--accent);font-weight:600;text-decoration:none">View all →</a>
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

  </div>
</div>

</body>
</html>