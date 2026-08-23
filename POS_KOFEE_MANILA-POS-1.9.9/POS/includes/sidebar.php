<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/permissions.php';
ob_start();
require_once __DIR__ . '/icons.php';
ob_end_clean(); // discard any stray/leaked text icons.php might accidentally output

// ── Safety net: if icons.php somehow failed to define icon() (bad file
//    encoding, stale/blank copy on disk, etc.) define it right here so
//    the sidebar/dashboard never fatal-error over a missing icon. ──────
if (!function_exists('icon')) {
    function icon(string $name, int $size = 18): string {
        $paths = [
            'home'        => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
            'order'       => '<path d="M3 2h18M3 6h18M21 12H3M3 16h10"/><circle cx="17" cy="18" r="3"/>',
            'pending'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
            'inventory'   => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
            'history'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/><path d="M3 3v6h6"/>',
            'menu'        => '<path d="M4 19V5a2 2 0 012-2h4a2 2 0 012 2v14"/><path d="M14 19V9a2 2 0 012-2h2a2 2 0 012 2v10"/><path d="M2 19h20"/>',
            'analytics'   => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
            'requests'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/>',
            'employees'   => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.4 6.5-6.4s6.5 2.8 6.5 6.4"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.7 13.6c2.6.4 4.6 2.6 4.8 5.4"/>',
            'attendance'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M9 15l2 2 4-4"/>',
            'leave'       => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 15h3M8 18h6"/>',
            'permissions' => '<path d="M12 2l7 3.5v5.4c0 4.7-3 8.9-7 10.1-4-1.2-7-5.4-7-10.1V5.5L12 2z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'sun'         => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.4M12 19.6V22M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2 12h2.4M19.6 12H22M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"/>',
            'coin'        => '<circle cx="12" cy="12" r="9"/><path d="M9.2 15.4c.5.9 1.5 1.5 2.8 1.5 1.8 0 3-1 3-2.3 0-3.2-5.6-1.7-5.6-4.9 0-1.3 1.2-2.3 3-2.3 1.2 0 2.2.5 2.7 1.4M12 6.4v1.2M12 16.4v1.2"/>',
            'chevron'     => '<path d="M9 6l6 6-6 6"/>',
        ];
        $body = $paths[$name] ?? $paths['home'];
        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
    }
}

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

// ── Live badge counts (best-effort; never break the sidebar if a
//    table isn't set up yet in this install) ──────────────────────
$pending_count = 0;
$requests_count = 0;
try {
    $pdo = get_db();
    if ($access['pending']) {
        $pending_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    }
    if ($access['hr_requests'] || $access['hr_leave']) {
        $c = 0;
        try { $c += (int)$pdo->query("SELECT COUNT(*) FROM hr_requests WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
        try { $c += (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
        $requests_count = $c;
    }
} catch (Throwable $e) {
    // DB not reachable — sidebar still renders, just without counts.
}

$initials = strtoupper(substr($user['firstname'] ?: $user['username'] ?: '?', 0, 1));
?>

<!-- Mobile menu toggle (hidden on desktop) -->
<button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<nav class="sidebar" id="main-sidebar">

    <div class="sidebar-logo">
        <div class="sidebar-logo-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--cream)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5V9z"/>
                <path d="M17 10.5c2.5 0 2.5 4 0 4"/>
                <path d="M7 3.5c-.6.8-.6 1.4 0 2.2M11 3.5c-.6.8-.6 1.4 0 2.2"/>
            </svg>
        </div>
        <div class="sidebar-logo-text">
            <div class="name">Kofee Manila</div>
            <div class="tag">Coffee &amp; Bites</div>
        </div>
    </div>

    <?php if ($access['dashboard']): ?>
    <div class="nav-group-label">Main</div>
    <button class="nav-btn <?= $current === 'dashboard.php' ? 'active' : '' ?>"
        onclick="window.location.href='dashboard.php'">
        <?= icon('home') ?><span class="nav-label">Dashboard</span>
    </button>
    <?php endif; ?>

    <?php if ($access['new_order'] || $access['pending'] || $access['inventory'] || $access['menu_manager'] || $access['history']): ?>
    <div class="nav-group-label">Operations</div>
    <?php endif; ?>

    <?php if ($access['new_order']): ?>
    <button class="nav-btn <?= $current === 'menu.php' ? 'active' : '' ?>"
        onclick="window.location.href='menu.php'">
        <?= icon('order') ?><span class="nav-label">Order</span>
    </button>
    <?php endif; ?>

    <?php if ($access['pending']): ?>
    <button class="nav-btn <?= $current === 'pending_orders.php' ? 'active' : '' ?>"
        onclick="window.location.href='pending_orders.php'">
        <?= icon('pending') ?><span class="nav-label">Pending</span>
        <?php if ($pending_count > 0): ?><span class="nav-badge"><?= $pending_count ?></span><?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['inventory']): ?>
    <button class="nav-btn <?= $current === 'inventory.php' ? 'active' : '' ?>"
        onclick="window.location.href='inventory.php'">
        <?= icon('inventory') ?><span class="nav-label">Inventory</span>
    </button>
    <?php endif; ?>

    <?php if ($access['menu_manager']): ?>
    <button class="nav-btn <?= $current === 'add_item.php' ? 'active' : '' ?>"
        onclick="window.location.href='add_item.php'">
        <?= icon('menu') ?><span class="nav-label">Menu</span>
    </button>
    <?php endif; ?>

    <?php if ($access['history']): ?>
    <button class="nav-btn <?= $current === 'history.php' ? 'active' : '' ?>"
        onclick="window.location.href='history.php'">
        <?= icon('history') ?><span class="nav-label">History</span>
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <div class="nav-group-label">Reports</div>
    <button class="nav-btn <?= $current === 'analytics.php' ? 'active' : '' ?>"
        onclick="window.location.href='analytics.php'">
        <?= icon('analytics') ?><span class="nav-label">Analytics</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_requests'] || $access['hr_leave'] || $access['users'] || $access['hr_employees'] || $access['hr_attendance'] || $access['manage_permissions']): ?>
    <div class="nav-group-label">Admin</div>
    <?php endif; ?>

    <?php if ($access['hr_requests']): ?>
    <button class="nav-btn <?= $current === 'hr_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='hr_requests.php'">
        <?= icon('requests') ?><span class="nav-label">Requests</span>
        <?php if ($requests_count > 0): ?><span class="nav-badge"><?= $requests_count ?></span><?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="nav-btn <?= $current === 'manage_users.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_users.php'">
        <?= icon('employees') ?><span class="nav-label">Manage Employees</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_employees']): ?>
    <button class="nav-btn <?= $current === 'employees.php' ? 'active' : '' ?>"
        onclick="window.location.href='employees.php'">
        <?= icon('employees') ?><span class="nav-label">Employee Records</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_attendance']): ?>
    <button class="nav-btn <?= $current === 'attendance.php' ? 'active' : '' ?>"
        onclick="window.location.href='attendance.php'">
        <?= icon('attendance') ?><span class="nav-label">Attendance</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave']): ?>
    <button class="nav-btn <?= $current === 'leave_requests.php' ? 'active' : '' ?>"
        onclick="window.location.href='leave_requests.php'">
        <?= icon('leave') ?><span class="nav-label">Leave</span>
    </button>
    <?php endif; ?>

    <?php if ($access['manage_permissions']): ?>
    <button class="nav-btn <?= $current === 'manage_permissions.php' ? 'active' : '' ?>"
        onclick="window.location.href='manage_permissions.php'">
        <?= icon('permissions') ?><span class="nav-label">Manage Permission</span>
    </button>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>

    <div class="sidebar-user">
        <div class="sidebar-user-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($user['firstname'] ?: $user['username']) ?></div>
            <div class="sidebar-user-role"><?= htmlspecialchars($role) ?></div>
        </div>
    </div>

    <button class="logout-btn" onclick="window.location.href='../auth/logout.php'">
        <?= icon('logout') ?><span class="nav-label">Logout</span>
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
