<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/permissions.php';

$user    = current_user();
$role    = $user['role'] ?? 'crew';
$current = basename($_SERVER['PHP_SELF']);

$access = [
    'dashboard'    => has_permission('dashboard.view'),
    'new_order'    => has_permission('orders.new'),
    'pending'      => has_permission('orders.pending'),
    'history'      => has_permission('orders.history'),
    'analytics'    => has_permission('analytics.view'),
    'menu_manager' => has_permission('menu.manage'),
    'inventory'    => has_permission('inventory.view'),
    'users'        => has_permission('users.manage'),

    // No page-view perm_key exists for these — they're not in the
    // delegable matrix, so keep them hardcoded to admin/hr like before.
    'hr_employees' => in_array($role, ['admin', 'hr'], true),
    'hr_attendance'=> in_array($role, ['admin', 'hr'], true),

    // Open to everyone by original design; no clean perm_key maps to
    // either page individually, so not gating these via has_permission().
    'hr_leave'     => true,
    'hr_requests'  => true,

    // Admin-only, hardcoded — never delegate this via the grants table
    // (a role that could toggle its own manage_permissions would be a
    // privilege escalation path).
    'manage_permissions' => ($role === 'admin'),
];
?>

<!-- Mobile menu toggle (hidden on desktop) -->
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<nav class="sidebar" id="main-sidebar">
    <div class="sidebar-logo">☕</div>

    <?php if ($access['dashboard']): ?>
    <button class="nav-btn <?= $current === 'dashboard.php' ? 'active' : '' ?>"
        onclick="window.location.href='dashboard.php'">
        <span class="nav-label">Home</span>
    </button>
    <?php endif; ?>

    <?php if ($access['new_order']): ?>
    <button class="nav-btn <?= $current === 'menu.php' ? 'active' : '' ?>"
        onclick="window.location.href='menu.php'">
        <span class="nav-label">Order</span>
    </button>
    <?php endif; ?>

    <?php if ($access['pending']): ?>
    <button class="nav-btn <?= $current === 'pending_orders.php' ? 'active' : '' ?>"
        onclick="window.location.href='pending_orders.php'">
        <span class="nav-label">Pending</span>
    </button>
    <?php endif; ?>

    <?php if ($access['inventory']): ?>
    <button class="nav-btn <?= $current === 'inventory.php' ? 'active' : '' ?>"
        onclick="window.location.href='inventory.php'">
        <span class="nav-label">Inventory</span>
    </button>
    <?php endif; ?>

    <?php if ($access['history']): ?>
    <button class="nav-btn <?= $current === 'history.php' ? 'active' : '' ?>"
        onclick="window.location.href='history.php'">
        <span class="nav-label">History</span>
    </button>
    <?php endif; ?>

    <?php if ($access['menu_manager']): ?>
    <button class="nav-btn <?= $current === 'add_item.php' ? 'active' : '' ?>"
        onclick="window.location.href='add_item.php'">
        <span class="nav-label">Menu</span>
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <button class="nav-btn <?= $current === 'analytics.php' ? 'active' : '' ?>"
        onclick="window.location.href='analytics.php'">
        <span class="nav-label">Analytics</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_requests'] || $access['hr_leave'] || $access['users'] || $access['hr_employees'] || $access['hr_attendance']): ?>
    <div class="sidebar-divider" title="Human Resources"></div>
    <?php endif; ?>

    <?php if ($access['hr_requests']): ?>
    <button class="nav-btn <?= $current === 'hr_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='hr_requests.php'">
        <span class="nav-label">Requests</span>
    </button>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="nav-btn <?= $current === 'manage_users.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_users.php'">
        <span class="nav-label">Accounts</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_employees']): ?>
    <button class="nav-btn <?= $current === 'employees.php' ? 'active' : '' ?>"
        onclick="window.location.href='employees.php'">
        <span class="nav-label">Employees</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_attendance']): ?>
    <button class="nav-btn <?= $current === 'attendance.php' ? 'active' : '' ?>"
        onclick="window.location.href='attendance.php'">
        <span class="nav-label">Attendance</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave']): ?>
    <button class="nav-btn <?= $current === 'leave_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='leave_requests.php'">
        <span class="nav-label">Leave</span>
    </button>
    <?php endif; ?>

    <?php if ($access['manage_permissions']): ?>
    <button class="nav-btn <?= $current === 'manage_permissions.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_permissions.php'">
        <span class="nav-label">Manage Permission</span>
    </button>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>

    <button class="logout-btn" onclick="window.location.href='../auth/logout.php'">
        <span class="nav-icon">🚪</span>
        <span class="nav-label">Logout</span>
    </button>
</nav>

<script>
function toggleSidebar() {
    document.getElementById('main-sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('open');
}
window.addEventListener('resize', () => {
    if (window.innerWidth > 900) {
        document.getElementById('main-sidebar')?.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('open');
    }
});
</script>