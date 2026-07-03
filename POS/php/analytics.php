<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/analytics.css">
</head>


<body>
    <?php include('../includes/admin_sidebar.php'); ?>

      <div id="page-analytics" class="page active">
    <div class="page-header">
      <div>
        <h1>Analytics</h1>
        <p>Sales performance overview</p>
      </div>
    </div>
    <div class="page-body">
      <div class="analytics-top">
        <div class="stat-card">
          <div class="stat-icon" style="background:#fff3e0">💰</div>
          <div class="stat-label">Weekly Sales</div>
          <div class="stat-value">₱0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e8f5e9">📦</div>
          <div class="stat-label">Weekly Orders</div>
          <div class="stat-value">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e3f2fd">🥤</div>
          <div class="stat-label">Cups Sold</div>
          <div class="stat-value">0</div>
          <div class="stat-sub">Across all categories</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fce4ec">⭐</div>
          <div class="stat-label">Best Category</div>
          <div class="stat-value" style="font-size:16px">Milk Tea</div>
        </div>
      </div>

      <div class="chart-section">
        <div class="chart-card">
          <h3>Daily Sales This Week (₱)</h3>
          <div class="bar-chart" id="bar-chart"></div>
        </div>
        <div class="chart-card">
          <h3>Sales by Category</h3>
          <div class="donut-wrap">
            <svg class="donut-svg" viewBox="0 0 120 120" id="donut-svg"></svg>
            <div class="legend" id="donut-legend"></div>
          </div>
        </div>
      </div>

      <div class="top-items-card">
        <h3>Top Selling Items</h3>
        <div id="top-items-list"></div>
      </div>
    </div>
  </div>

  <script src="../js/analytics.js"></script>

</body>
</html>