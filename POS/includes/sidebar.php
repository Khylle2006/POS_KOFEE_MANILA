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
    // ── HR module ──
    'hr_employees' => in_array($role, ['admin', 'hr']),
    'hr_attendance'=> in_array($role, ['admin', 'hr']),
    'hr_leave'     => in_array($role, ['admin', 'hr']),
    'hr_requests'  => in_array($role, ['admin', 'hr']),
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
        Manage Items
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <button class="nav-btn <?= $current === 'analytics.php' ? 'active' : '' ?>"
        onclick="window.location.href='analytics.php'">
        Analytics
    </button>
    <?php endif; ?>

    <?php if ($access['hr_employees'] || $access['hr_attendance'] || $access['hr_leave'] || $access['hr_requests'] || $access['users']): ?>
    <div class="sidebar-divider" title="Human Resources"></div>
    <?php endif; ?>

    <?php if ($access['hr_requests']): ?>
    <button class="nav-btn <?= $current === 'hr_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='hr_requests.php'">
        Requests
    </button>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="nav-btn <?= $current === 'manage_users.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_users.php'">
        Accounts
    </button>
    <?php endif; ?>

    <?php if ($access['hr_employees']): ?>
    <button class="nav-btn <?= $current === 'employees.php' ? 'active' : '' ?>"
        onclick="window.location.href='employees.php'">
        Employees
    </button>
    <?php endif; ?>

    <?php if ($access['hr_attendance']): ?>
    <button class="nav-btn <?= $current === 'attendance.php' ? 'active' : '' ?>"
        onclick="window.location.href='attendance.php'">
        Attendance
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave']): ?>
    <button class="nav-btn <?= $current === 'leave_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='leave_requests.php'">
        Leave
    </button>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>

    <button class="logout-btn" onclick="window.location.href='../auth/logout.php'">
        Logout
    </button>
</nav>