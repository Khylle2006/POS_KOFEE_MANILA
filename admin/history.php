<?php
require_once '../includes/auth_check.php';
require_role('admin');
?>

<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/history.css">
</head>

<body>
    <?php include('../includes/admin_sidebar.php'); ?>

    <div id="page-history" class="page active">

        <div class="page-header">
            <div>
                <h1>Order History</h1>
                <p>All past transactions</p>
            </div>
        </div>

        <div class="page-body">

            <div class="filter-bar">
                <input class="filter-input" type="text"
                    placeholder="🔍 Search by order # or item…"
                    oninput="filterHistory(this.value)">

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

                <tbody id="history-tbody"></tbody>
            </table>

        </div>
    </div>

<script>
fetch('get_history.php')
.then(r => r.json())
.then(data => {

    let html = '';

    data.forEach(o => {
        html += `
        <tr>
            <td>#${o.id}</td>
            <td>${o.created_at}</td>
            <td>${o.items || '-'}</td>
            <td>${o.payment_method}</td>
            <td>₱${o.total_amount || '0.00'}</td>
            <td><button>View</button></td>
        </tr>`;
    });

    document.getElementById('history-tbody').innerHTML = html;
})
.catch(err => {
    console.error("History load error:", err);
});
</script>

</body>
</html>