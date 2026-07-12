<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$user    = current_user();
$role    = $user['role'] ?? 'crew';
$current = basename($_SERVER['PHP_SELF']);

$access = [
    'dashboard'    => in_array($role, ['admin', 'manager', 'finance']),
    'new_order'    => in_array($role, ['admin', 'manager', 'staff', 'crew']),
    'pending'      => in_array($role, ['admin', 'manager', 'staff', 'crew']),
    'history'      => in_array($role, ['admin', 'manager', 'finance', 'staff']),
    'analytics'    => in_array($role, ['admin', 'manager', 'finance']),
    'menu_manager' => in_array($role, ['admin', 'manager']),
    'inventory'    => in_array($role, ['admin', 'manager', 'crew']),
    'users'        => in_array($role, ['admin', 'hr']),
];
?>

<nav class="sidebar">
    <div class="sidebar-logo">☕</div>

    <?php if ($access['dashboard']): ?>
    <button class="nav-btn <?= $current === 'dashboard.php' ? 'active' : '' ?>"
        onclick="window.location.href='dashboard.php'">
        Home
    </button>
    <?php endif; ?>

    <?php if ($access['new_order']): ?>
    <button class="nav-btn <?= $current === 'menu.php' ? 'active' : '' ?>"
        onclick="window.location.href='menu.php'">
        Order
    </button>
    <?php endif; ?>

    <?php if ($access['pending']): ?>
    <button class="nav-btn <?= $current === 'pending_orders.php' ? 'active' : '' ?>"
        onclick="window.location.href='pending_orders.php'">
        Pending Orders
    </button>
    <?php endif; ?>

    <?php if ($access['inventory']): ?>
    <button class="nav-btn <?= $current === 'inventory.php' ? 'active' : '' ?>"
        onclick="window.location.href='inventory.php'">
        Inventory
    </button>
    <?php endif; ?>

    <?php if ($access['history']): ?>
    <button class="nav-btn <?= $current === 'history.php' ? 'active' : '' ?>"
        onclick="window.location.href='history.php'">
        History
    </button>
    <?php endif; ?>

    <?php if ($access['menu_manager']): ?>
    <button class="nav-btn <?= $current === 'add_item.php' ? 'active' : '' ?>"
        onclick="window.location.href='add_item.php'">
        Add Item
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <button class="nav-btn <?= $current === 'analytics.php' ? 'active' : '' ?>"
        onclick="window.location.href='analytics.php'">
        Analytics
    </button>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="nav-btn <?= $current === 'manage_users.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_users.php'">
        Manage Users
    </button>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>

    <button class="logout-btn" onclick="window.location.href='../auth/logout.php'">
        Logout
    </button>
</nav>