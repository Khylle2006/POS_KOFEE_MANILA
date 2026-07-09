
<?php
$user = current_user();
$is_admin = $user['role'] === 'admin';
?>

<nav class="sidebar">
    <div class="sidebar-logo">☕</div>

    <button class="nav-btn"
        onclick="window.location.href='dashboard.php'">
        Home
    </button>

    <button class="nav-btn"
            onclick="window.location.href='menu.php'">
        Order
    </button>
    
    <button class="nav-btn"
            onclick="window.location.href='pending_orders.php'">
        Pending Orders
    </button>

    <button class="nav-btn"
            onclick="window.location.href='history.php'">
        history
    </button>

    <button class="nav-btn"
            onclick="window.location.href='analytics.php'">
        Analytics
    </button>

    <button class="nav-btn"
            onclick="window.location.href='add_item.php'">
        Add Item
    </button>

    <button class="nav-btn"
            onclick="window.location.href='manage_users.php'">
        Manage Users
    </button>

    <div class="sidebar-spacer"></div>

    <button class="logout-btn" onclick="window.location.href='../auth/logout.php'">
        Logout
    </button>
</nav>