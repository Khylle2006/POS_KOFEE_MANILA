<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/history.css">
</head>
<body>

    <?php include('../includes/staff_sidebar.php'); ?>

    <div id="page-history" class="page active">
    <div class="page-header">
      <div>
        <h1>Order History</h1>
        <p>All past transactions</p>
      </div>
    </div>
    <div class="page-body">
      <div class="filter-bar">
        <input class="filter-input" type="text" placeholder="🔍  Search by order # or item…" oninput="filterHistory(this.value)">
        <select class="filter-select" onchange="filterType(this.value)">
          <option value="">All Types</option>
          <option value="Dine In">Dine In</option>
          <option value="Take Out">Take Out</option>
          <option value="Delivery">Delivery</option>
        </select>
        <select class="filter-select">
          <option>Today</option>
          <option>This Week</option>
          <option>This Month</option>
        </select>
      </div>

      <table class="history-table">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Date & Time</th>
            <th>Items</th>
            <th>Type</th>
            <th>Total</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="history-tbody">
          <!-- Populated by JS -->
        </tbody>
      </table>
    </div>
  </div>

</body>
</html>