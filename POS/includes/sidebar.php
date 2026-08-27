<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';
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
    'hr_employees' => in_array($role, ['admin', 'hr'], true),
    'hr_attendance'=> in_array($role, ['admin', 'hr'], true),
    'hr_leave'     => true,
    'hr_requests'  => true,
    'manage_permissions' => ($role === 'admin'),
];

// ── Live badge counts (best-effort; never break the sidebar if a
//    table isn't set up yet in this install) ──────────────────────
$pending_count = 0;
$requests_count = 0;
$notification_count = 0;
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
    sync_notifications($pdo, $user);
    $notification_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL AND (user_id = :user_id OR role_key = :role_key)");
    $notification_stmt->execute([':user_id' => $user['id'], ':role_key' => $role]);
    $notification_count = (int)$notification_stmt->fetchColumn();
} catch (Throwable $e) {
    // DB not reachable — sidebar still renders, just without counts.
}

$initials = strtoupper(substr($user['firstname'] ?: $user['username'] ?: '?', 0, 1));

// ── Tailwind class helpers ────────────────────────────────────
// Colors reference your existing CSS custom properties (--espresso etc.)
// with a hex fallback, so this still matches your theme wherever those
// variables are already defined, and won't render invisible if they're not.
$C = [
    'espresso'      => 'var(--espresso,#2c1a0e)',
    'espresso-deep' => 'var(--espresso-deep,#1c1108)',
    'cream'         => 'var(--cream,#fbf3e9)',
    'caramel'       => 'var(--caramel,#c47d3e)',
    'caramel-light' => 'var(--caramel-light,#d9a06b)',
];

function navBtnClasses(bool $active): string {
    $base = 'group flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-[10px] '
          . 'text-[13px] font-medium transition-colors duration-150 relative';
    if ($active) {
        return $base . ' text-white font-semibold shadow-[0_6px_16px_-6px_rgba(201,123,61,0.65)]'
                      . ' bg-[linear-gradient(135deg,var(--caramel,#c47d3e)_0%,var(--espresso-deep,#1c1108)_100%)]';
    }
    return $base . ' text-[rgba(251,243,233,0.72)] hover:bg-[rgba(251,243,233,0.06)] hover:text-[var(--cream,#fbf3e9)]';
}

$groupLabel = 'text-[10px] font-bold tracking-[0.12em] uppercase text-[rgba(251,243,233,0.35)] px-3 pt-[14px] pb-[6px]';
?>
<script src="https://cdn.tailwindcss.com"></script>

<!-- ── Top bar: always visible, holds the Menu toggle ── -->
<header class="fixed top-0 inset-x-0 h-14 z-[200] flex items-center gap-3 px-4
               bg-[var(--espresso,#2c1a0e)] text-[var(--cream,#fbf3e9)] shadow-md">

    <button id="sidebar-menu-btn" onclick="toggleSidebar()"
        class="flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] font-semibold
               bg-[rgba(251,243,233,0.08)] hover:bg-[rgba(251,243,233,0.16)] transition-colors duration-150"
        aria-expanded="false" aria-controls="main-sidebar">
        <svg id="menu-icon-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        <svg id="menu-icon-close" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" class="hidden"><path d="M6 6l12 12M18 6L6 18"/></svg>
        <span>Menu</span>
    </button>

    <div class="flex items-center gap-2.5 min-w-0">
        <div class="w-8 h-8 rounded-[9px] flex items-center justify-center flex-shrink-0
                    bg-[linear-gradient(150deg,var(--caramel,#c47d3e)_0%,var(--espresso-deep,#1c1108)_140%)]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cream,#fbf3e9)"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5V9z"/>
                <path d="M17 10.5c2.5 0 2.5 4 0 4"/>
                <path d="M7 3.5c-.6.8-.6 1.4 0 2.2M11 3.5c-.6.8-.6 1.4 0 2.2"/>
            </svg>
        </div>
        <span class="font-['Playfair_Display',serif] font-bold text-[15px] truncate">Kofee Manila</span>
    </div>

    <div class="flex-1"></div>

    <div class="relative">
        <button id="notification-btn" type="button" onclick="toggleNotifications()"
            class="relative w-9 h-9 flex items-center justify-center rounded-lg text-[rgba(251,243,233,0.78)] hover:bg-[rgba(251,243,233,0.10)] hover:text-[var(--cream,#fbf3e9)] transition-colors duration-150"
            aria-label="Notifications" aria-expanded="false" aria-controls="notification-panel">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            <span id="notification-badge" class="<?= $notification_count ? '' : 'hidden' ?> absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-[var(--caramel-light,#d9a06b)] text-[var(--espresso-deep,#1c1108)] text-[9px] font-extrabold leading-4"><?= $notification_count > 99 ? '99+' : $notification_count ?></span>
        </button>
        <div id="notification-panel" class="hidden absolute right-0 top-11 w-[min(360px,calc(100vw-24px))] rounded-xl bg-white text-[var(--text-main,#2b2130)] shadow-2xl border border-[var(--latte,#efe0cc)] overflow-hidden z-[250]">
            <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--latte,#efe0cc)]"><strong class="text-[13px]">Notifications</strong><button type="button" onclick="markNotificationsRead()" class="text-[11px] font-semibold text-[var(--caramel,#c47d3e)] hover:underline">Mark all read</button></div>
            <div id="notification-list" class="max-h-[360px] overflow-y-auto"><div class="px-4 py-8 text-center text-[12px] text-[var(--text-muted,#8b7c88)]">Loading notifications...</div></div>
        </div>
    </div>

    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-[12px] font-extrabold
                bg-[linear-gradient(150deg,var(--caramel-light,#d9a06b),var(--caramel,#c47d3e))]
                text-[var(--espresso-deep,#1c1108)]">
        <?= htmlspecialchars($initials) ?>
    </div>
</header>

<!-- Spacer so page content (rendered after this include) isn't hidden under the fixed top bar -->
<div class="h-14"></div>

<!-- ── Backdrop ── -->
<div id="sidebar-backdrop" onclick="toggleSidebar(false)"
     class="fixed inset-0 bg-black/50 z-[220] opacity-0 pointer-events-none transition-opacity duration-200"></div>

<!-- ── Sidebar popover panel ── -->
<nav id="main-sidebar"
     class="fixed top-0 left-0 h-full w-[248px] z-[230] flex flex-col
            px-3.5 pt-4 pb-4 overflow-y-auto
            bg-[linear-gradient(165deg,var(--espresso,#2c1a0e)_0%,var(--espresso-deep,#1c1108)_115%)]
            text-[var(--cream,#fbf3e9)]
            -translate-x-full transition-transform duration-300 ease-out">

    <div class="flex items-center justify-between pb-4 mb-2 border-b border-[rgba(251,243,233,0.10)]">
        <div class="flex items-center gap-2.5 min-w-0">
            <div class="w-9 h-9 rounded-[11px] flex items-center justify-center flex-shrink-0
                        bg-[linear-gradient(150deg,var(--caramel,#c47d3e)_0%,var(--espresso-deep,#1c1108)_140%)]
                        shadow-[0_6px_14px_-4px_rgba(201,123,61,0.6)]">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cream,#fbf3e9)"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5V9z"/>
                    <path d="M17 10.5c2.5 0 2.5 4 0 4"/>
                    <path d="M7 3.5c-.6.8-.6 1.4 0 2.2M11 3.5c-.6.8-.6 1.4 0 2.2"/>
                </svg>
            </div>
            <div class="leading-tight overflow-hidden">
                <div class="font-['Playfair_Display',serif] font-bold text-[15.5px] truncate">Kofee Manila</div>
                <div class="text-[10.5px] text-[var(--caramel-light,#d9a06b)] tracking-wide truncate">Coffee &amp; Bites</div>
            </div>
        </div>
        <button onclick="toggleSidebar(false)" aria-label="Close menu"
            class="w-8 h-8 flex items-center justify-center rounded-lg flex-shrink-0
                   text-[rgba(251,243,233,0.6)] hover:bg-[rgba(251,243,233,0.08)] hover:text-[var(--cream,#fbf3e9)]">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <?php if ($access['dashboard']): ?>
    <div class="<?= $groupLabel ?> pt-1.5">Main</div>
    <button class="<?= navBtnClasses($current === 'dashboard.php') ?>" onclick="window.location.href='dashboard.php'">
        <?= icon('home') ?><span class="flex-1 truncate">Dashboard</span>
    </button>
    <?php endif; ?>

    <?php if ($access['new_order'] || $access['pending'] || $access['inventory'] || $access['menu_manager'] || $access['history']): ?>
    <div class="<?= $groupLabel ?>">Operations</div>
    <?php endif; ?>

    <?php if ($access['new_order']): ?>
    <button class="<?= navBtnClasses($current === 'menu.php') ?>" onclick="window.location.href='menu.php'">
        <?= icon('order') ?><span class="flex-1 truncate">POS</span>
    </button>
    <?php endif; ?>

    <?php if ($access['pending']): ?>
    <button class="<?= navBtnClasses($current === 'pending_orders.php') ?>" onclick="window.location.href='pending_orders.php'">
        <?= icon('pending') ?><span class="flex-1 truncate">Pending</span>
        <?php if ($pending_count > 0): ?>
        <span class="flex-shrink-0 text-[10px] font-extrabold px-[7px] py-[1px] rounded-full
                     bg-[var(--caramel-light,#d9a06b)] text-[var(--espresso-deep,#1c1108)]"><?= $pending_count ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['inventory']): ?>
    <button class="<?= navBtnClasses($current === 'inventory.php') ?>" onclick="window.location.href='inventory.php'">
        <?= icon('inventory') ?><span class="flex-1 truncate">Inventory</span>
    </button>
    <?php endif; ?>

    <?php if ($access['menu_manager']): ?>
    <button class="<?= navBtnClasses($current === 'add_item.php') ?>" onclick="window.location.href='add_item.php'">
        <?= icon('menu') ?><span class="flex-1 truncate">Menu</span>
    </button>
    <?php endif; ?>

    <?php if ($access['history']): ?>
    <button class="<?= navBtnClasses($current === 'history.php') ?>" onclick="window.location.href='history.php'">
        <?= icon('history') ?><span class="flex-1 truncate">History</span>
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <div class="<?= $groupLabel ?>">Reports</div>
    <button class="<?= navBtnClasses($current === 'analytics.php') ?>" onclick="window.location.href='analytics.php'">
        <?= icon('analytics') ?><span class="flex-1 truncate">Analytics</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_requests'] || $access['hr_leave'] || $access['users'] || $access['hr_employees'] || $access['hr_attendance'] || $access['manage_permissions']): ?>
    <div class="<?= $groupLabel ?>">Admin</div>
    <?php endif; ?>

    <?php if ($access['hr_requests']): ?>
    <button class="<?= navBtnClasses($current === 'hr_requests.php') ?>" onclick="window.location.href='hr_requests.php'">
        <?= icon('requests') ?><span class="flex-1 truncate">Requests</span>
        <?php if ($requests_count > 0): ?>
        <span class="flex-shrink-0 text-[10px] font-extrabold px-[7px] py-[1px] rounded-full
                     bg-[var(--caramel-light,#d9a06b)] text-[var(--espresso-deep,#1c1108)]"><?= $requests_count ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="<?= navBtnClasses($current === 'manage_users.php') ?>" onclick="window.location.href='manage_users.php'">
        <?= icon('employees') ?><span class="flex-1 truncate">Manage Employees</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_attendance']): ?>
    <button class="<?= navBtnClasses($current === 'attendance.php') ?>" onclick="window.location.href='attendance.php'">
        <?= icon('attendance') ?><span class="flex-1 truncate">Attendance</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave']): ?>
    <button class="<?= navBtnClasses($current === 'leave_requests.php') ?>" onclick="window.location.href='leave_requests.php'">
        <?= icon('leave') ?><span class="flex-1 truncate">Leave</span>
    </button>
    <?php endif; ?>

    <?php if ($access['manage_permissions']): ?>
    <button class="<?= navBtnClasses($current === 'manage_permissions.php') ?>" onclick="window.location.href='manage_permissions.php'">
        <?= icon('permissions') ?><span class="flex-1 truncate">Manage Permission</span>
    </button>
    <?php endif; ?>

    <div class="flex-1"></div>

    <div class="flex items-center gap-2.5 p-2.5 rounded-xl bg-[rgba(251,243,233,0.06)] mb-2">
        <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-[12px] font-extrabold
                    bg-[linear-gradient(150deg,var(--caramel-light,#d9a06b),var(--caramel,#c47d3e))]
                    text-[var(--espresso-deep,#1c1108)]">
            <?= htmlspecialchars($initials) ?>
        </div>
        <div class="leading-tight overflow-hidden">
            <div class="text-[12.5px] font-semibold text-[var(--cream,#fbf3e9)] truncate">
                <?= htmlspecialchars($user['firstname'] ?: $user['username']) ?>
            </div>
            <div class="text-[10.5px] text-[var(--caramel-light,#d9a06b)] capitalize"><?= htmlspecialchars($role) ?></div>
        </div>
    </div>

    <button class="flex items-center gap-3 w-full px-3 py-2.5 rounded-[10px] text-[13px] font-semibold
                   bg-[rgba(198,40,40,0.14)] text-[#f2a9a9] hover:bg-[rgba(198,40,40,0.24)] transition-colors duration-150"
        onclick="window.location.href='../auth/logout.php'">
        <?= icon('logout') ?><span>Logout</span>
    </button>
</nav>

<script>
function toggleSidebar(force) {
    const panel    = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const btn      = document.getElementById('sidebar-menu-btn');
    const iconOpen  = document.getElementById('menu-icon-open');
    const iconClose = document.getElementById('menu-icon-close');

    const willOpen = typeof force === 'boolean' ? force : panel.classList.contains('-translate-x-full');

    panel.classList.toggle('-translate-x-full', !willOpen);
    panel.classList.toggle('translate-x-0', willOpen);

    backdrop.classList.toggle('opacity-0', !willOpen);
    backdrop.classList.toggle('pointer-events-none', !willOpen);
    backdrop.classList.toggle('opacity-100', willOpen);
    backdrop.classList.toggle('pointer-events-auto', willOpen);

    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    iconOpen.classList.toggle('hidden', willOpen);
    iconClose.classList.toggle('hidden', !willOpen);

    document.body.classList.toggle('overflow-hidden', willOpen);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') toggleSidebar(false);
});

const notificationPanel = document.getElementById('notification-panel');
const notificationBadge = document.getElementById('notification-badge');
const notificationList = document.getElementById('notification-list');

function toggleNotifications() {
    const open = notificationPanel.classList.toggle('hidden');
    document.getElementById('notification-btn').setAttribute('aria-expanded', open ? 'false' : 'true');
    if (!open) loadNotifications();
}

function loadNotifications() {
    fetch('../api/get_notifications.php').then(response => response.json()).then(data => {
        if (!data.success) throw new Error(data.error || 'Unable to load notifications');
        notificationBadge.textContent = data.unread > 99 ? '99+' : data.unread;
        notificationBadge.classList.toggle('hidden', data.unread === 0);
        notificationList.innerHTML = data.items.length ? data.items.map(item => `<a href="${escapeNotification(item.link || '#')}" class="block px-4 py-3 border-b border-[var(--latte,#efe0cc)] hover:bg-[var(--cream,#fbf3e9)] ${item.read_at ? '' : 'bg-[var(--accent-lt,#fcefe1)]'}"><div class="text-[12px] font-bold">${escapeNotification(item.title)}</div><div class="mt-1 text-[11px] text-[var(--text-muted,#8b7c88)]">${escapeNotification(item.message)}</div><div class="mt-1 text-[10px] text-[var(--text-muted,#8b7c88)]">${escapeNotification(item.created_at)}</div></a>`).join('') : '<div class="px-4 py-8 text-center text-[12px] text-[var(--text-muted,#8b7c88)]">You are all caught up.</div>';
    }).catch(() => { notificationList.innerHTML = '<div class="px-4 py-8 text-center text-[12px] text-[var(--text-muted,#8b7c88)]">Notifications unavailable.</div>'; });
}

function markNotificationsRead() {
    fetch('../api/mark_notifications_read.php', { method: 'POST' }).then(response => response.json()).then(data => { if (data.success) loadNotifications(); });
}

function escapeNotification(value) {
    return String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
}
</script>