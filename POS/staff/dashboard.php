<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/home.css">
</head>
<body>
    <?php include('../includes/staff_sidebar.php'); ?>

      <div id="page-home" class="page active">
    <div class="page-header">
      <div>
        <h1>Good morning! ☀️</h1>
        <p id="home-date">Today's overview</p>
      </div>
    </div>
    <div class="page-body">
      <div class="home-grid">
        <div class="stat-card">
          <div class="stat-icon" style="background:#fff3e0">💰</div>
          <div class="stat-label">Today's Sales</div>
          <div class="stat-value">₱0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e8f5e9">📋</div>
          <div class="stat-label">Total Orders</div>
          <div class="stat-value">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e3f2fd">🛒</div>
          <div class="stat-label">Avg Order</div>
          <div class="stat-value">0</div>
          <div class="stat-sub">Per transaction</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fce4ec">🍵</div>
          <div class="stat-label">Top Item</div>
          <div class="stat-value" style="font-size:16px">Taro Milk Tea</div>
          <div class="stat-sub">0 cups today</div>
        </div>
      </div>

      <div>
        <div style="font-size:15px;font-weight:700;margin-bottom:12px">Quick Access</div>
        <div class="home-shortcuts">
          <div class="shortcut-card" onclick="showPage('menu', document.querySelector('[onclick*=menu]'))">
            <div class="shortcut-icon" style="background:#fff3e0">📋</div>
            <div>
              <h3>New Order</h3>
              <p>Start taking an order now</p>
            </div>
          </div>
          <div class="shortcut-card" onclick="showPage('history', document.querySelector('[onclick*=history]'))">
            <div class="shortcut-icon" style="background:#e8f5e9">🕐</div>
            <div>
              <h3>Order History</h3>
              <p>View all past transactions</p>
            </div>
          </div>
        </div>
      </div>

      <div class="recent-section">
        <h2>Recent Orders</h2>
        <table class="recent-table">
          <thead>
            <tr>
              <th>Order #</th>
              <th>Items</th>
              <th>Type</th>
              <th>Total</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody id="recent-tbody">
            // Recent orders will be dynamically populated here
          </tbody>
        </table>
      </div>
    </div>
  </div>

</body>
</html>