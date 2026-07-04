<?php
// dashboard.php
// FIXES:
// 1. "Add Staff" shortcut now links to manage-users page, not addmenu.
// 2. recent-tbody is now populated via fetch to get_analytics.php.
// 3. <script> moved out of .pages div, placed before </body>.
require_once '../includes/auth_check.php';
require_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <title>POS System — Dashboard</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/home.css">
</head>
<body>
    <?php include('../includes/admin_sidebar.php'); ?>

    <div class="pages">

        <!-- HOME PAGE -->
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
                        <div class="stat-label">Weekly Sales</div>
                        <div class="stat-value" id="dash-sales">—</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#e8f5e9">📋</div>
                        <div class="stat-label">Weekly Orders</div>
                        <div class="stat-value" id="dash-orders">—</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#e3f2fd">🥤</div>
                        <div class="stat-label">Cups Sold</div>
                        <div class="stat-value" id="dash-cups">—</div>
                        <div class="stat-sub">All time</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fce4ec">⭐</div>
                        <div class="stat-label">Best Category</div>
                        <div class="stat-value" id="dash-best-cat" style="font-size:16px">—</div>
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
                        <div class="shortcut-card" onclick="showPage('addmenu', document.querySelector('[onclick*=addmenu]'))">
                            <div class="shortcut-icon" style="background:#e3f2fd">➕</div>
                            <div>
                                <h3>Add Menu Item</h3>
                                <p>Add drinks to your menu</p>
                            </div>
                        </div>
                        <!-- FIX: was also calling showPage('addmenu'); now correctly goes to users page -->
                        <div class="shortcut-card" onclick="showPage('users', document.querySelector('[onclick*=users]'))">
                            <div class="shortcut-icon" style="background:#fce4ec">👥</div>
                            <div>
                                <h3>Manage Staff</h3>
                                <p>Add and manage user accounts</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FIX: Recent orders now actually loaded via fetch -->
                <div class="recent-section">
                    <h2>Recent Orders</h2>
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Items</th>
                                <th>Payment</th>
                                <th>Total</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody id="recent-tbody">
                            <tr><td colspan="5" style="text-align:center;color:#9a7e65;padding:20px">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

    </div><!-- /.pages -->

    <!-- FIX: script moved here, outside .pages div and before </body> -->
    <script>
    // Set today's date
    document.getElementById('home-date').textContent =
        new Date().toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

    // Load analytics data for stat cards + recent orders
    fetch('get_analytics.php')
        .then(r => r.json())
        .then(data => {
            document.getElementById('dash-sales').textContent    = '₱' + Number(data.weekly_sales).toFixed(2);
            document.getElementById('dash-orders').textContent   = data.weekly_orders;
            document.getElementById('dash-cups').textContent     = data.cups;
            document.getElementById('dash-best-cat').textContent = data.best_category;

            // Populate recent orders table
            const tbody = document.getElementById('recent-tbody');
            if (!data.recent_orders || data.recent_orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9a7e65;padding:20px">No orders yet.</td></tr>';
                return;
            }
            tbody.innerHTML = data.recent_orders.map(o => `
                <tr>
                    <td>#${o.id}</td>
                    <td>${o.items_count} item(s)</td>
                    <td>—</td>
                    <td>₱${parseFloat(o.total_amount).toFixed(2)}</td>
                    <td>${o.created_at}</td>
                </tr>
            `).join('');
        })
        .catch(err => {
            console.error('Dashboard load error:', err);
            document.getElementById('recent-tbody').innerHTML =
                '<tr><td colspan="5" style="text-align:center;color:#c62828;padding:20px">Failed to load data.</td></tr>';
        });

    function showPage(name, btn) {
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        const page = document.getElementById('page-' + name);
        if (page) page.classList.add('active');
        if (btn)  btn.classList.add('active');
        if (name === 'analytics') renderAnalytics();
        if (name === 'history')   loadHistory();
    }


    
    </script>
</body>
</html>