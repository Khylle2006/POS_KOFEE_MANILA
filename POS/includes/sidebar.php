<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/procurement_helpers.php';
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
        'dashboard'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'rfq'         => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
            'truck'       => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
            'invoice'     => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
            'scale'       => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 7l-3 6a3 3 0 006 0z"/><path d="M19 7l-3 6a3 3 0 006 0z"/>',
            'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'star'        => '<path d="M12 2l3 6.5 7 .9-5 5 1.3 7-6.3-3.6L5.7 21.4 7 14.4l-5-5 7-.9z"/>',
            'portal'      => '<path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M10 21v-6h4v6"/>',
            'bell'        => '<path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        ];
        $body = $paths[$name] ?? $paths['home'];
        return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
    }
}

$user    = current_user();
$role    = $user['role']  ?? 'crew';   // primary role — used only for the display badge
$roles   = $user['roles'] ?? [$role];  // ALL roles this account holds — used for access checks
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
    'hr_employees' => (bool)array_intersect(['admin', 'hr'], $roles),
    'hr_attendance'=> (bool)array_intersect(['admin', 'hr'], $roles),
    'hr_leave'     => true,
    'hr_requests'  => true,
    'manage_permissions' => in_array('admin', $roles, true),
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
$notification_count = 0;
$notifications = [];
try {
    $notification_count = unread_notification_count((int)$user['id']);
    $notifications = recent_notifications((int)$user['id'], 12);
} catch (Throwable $e) {
    // Notifications are optional; never prevent the shared layout from rendering.
}

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
<!-- ── Kofee Manila Smooth Page Transition & Loader ── -->
<style>
  @view-transition {
    navigation: auto;
  }
  #kofee-loader {
    position: fixed;
    inset: 0;
    z-index: 9999999;
    background: radial-gradient(circle at center, #26160d 0%, #120905 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    opacity: 1;
    visibility: visible;
    transition: opacity 0.32s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.32s ease;
    pointer-events: all;
    user-select: none;
  }
  #kofee-loader.loader-hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
  }
  .kfs-loader-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
  }
  .kfs-cup-wrap {
    position: relative;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #d9a06b;
    animation: kfsGlow 2.4s ease-in-out infinite;
  }
  .kfs-cup-wrap svg {
    width: 46px;
    height: 46px;
  }
  .kfs-steam-lines {
    position: absolute;
    top: -6px;
    display: flex;
    gap: 5px;
  }
  .kfs-steam-line {
    width: 3px;
    height: 12px;
    border-radius: 2px;
    background: linear-gradient(to top, rgba(217, 160, 107, 0.85), transparent);
    animation: kfsSteam 1.6s ease-in-out infinite;
  }
  .kfs-steam-line:nth-child(2) {
    animation-delay: 0.35s;
    height: 15px;
  }
  .kfs-steam-line:nth-child(3) {
    animation-delay: 0.7s;
  }
  @keyframes kfsSteam {
    0% { transform: translateY(0) scaleX(1); opacity: 0; }
    35% { opacity: 0.85; }
    70% { transform: translateY(-8px) scaleX(1.4); opacity: 0.3; }
    100% { transform: translateY(-16px) scaleX(2); opacity: 0; }
  }
  @keyframes kfsGlow {
    0%, 100% { filter: drop-shadow(0 0 6px rgba(201, 123, 61, 0.25)); }
    50% { filter: drop-shadow(0 0 16px rgba(201, 123, 61, 0.65)); }
  }
  .kfs-loader-brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-align: center;
  }
  .kfs-loader-title {
    font-family: 'Playfair Display', 'Poppins', Georgia, serif;
    font-weight: 700;
    font-size: 19px;
    letter-spacing: 0.16em;
    color: #FBF3E9;
    text-transform: uppercase;
  }
  .kfs-loader-sub {
    font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.08em;
    color: #d9a06b;
  }
  .kfs-loader-bar {
    width: 140px;
    height: 3.5px;
    background: rgba(251, 243, 233, 0.12);
    border-radius: 999px;
    overflow: hidden;
    position: relative;
    margin-top: 4px;
  }
  .kfs-loader-bar-fill {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 40%;
    background: linear-gradient(90deg, transparent, #c47d3e, #f0c396, #c47d3e, transparent);
    border-radius: 999px;
    animation: kfsProgress 1.4s ease-in-out infinite;
  }
  @keyframes kfsProgress {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(260%); }
  }
</style>

<div id="kofee-loader" aria-live="polite" role="status" aria-label="Loading page">
  <div class="kfs-loader-card">
    <div class="kfs-cup-wrap">
      <div class="kfs-steam-lines">
        <div class="kfs-steam-line"></div>
        <div class="kfs-steam-line"></div>
        <div class="kfs-steam-line"></div>
      </div>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
        <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
        <line x1="6" y1="1" x2="6" y2="4"/>
        <line x1="10" y1="1" x2="10" y2="4"/>
        <line x1="14" y1="1" x2="14" y2="4"/>
      </svg>
    </div>
    <div class="kfs-loader-brand">
      <span class="kfs-loader-title">Kofee Manila</span>
      <span class="kfs-loader-sub">Brewing workspace…</span>
    </div>
    <div class="kfs-loader-bar">
      <div class="kfs-loader-bar-fill"></div>
    </div>
  </div>
</div>

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

    <div class="relative" id="notification-wrap">
        <button id="notification-btn" type="button" onclick="toggleNotifications()"
            class="relative w-9 h-9 flex items-center justify-center rounded-lg
                   text-[rgba(251,243,233,0.78)] hover:bg-[rgba(251,243,233,0.12)]"
            aria-label="Notifications" aria-expanded="false" aria-controls="notification-panel">
            <?= icon('bell', 18) ?>
            <?php if ($notification_count > 0): ?>
            <span id="notification-count" class="absolute -top-1 -right-1 min-w-[17px] h-[17px] px-1 rounded-full
                         bg-[var(--caramel-light,#d9a06b)] text-[var(--espresso-deep,#1c1108)] text-[10px] font-extrabold flex items-center justify-center">
                <?= $notification_count > 99 ? '99+' : $notification_count ?>
            </span>
            <?php endif; ?>
        </button>
        <div id="notification-panel" class="hidden absolute right-0 top-11 w-[340px] max-w-[calc(100vw-24px)]
                    rounded-xl bg-white text-[var(--text-main,#2b2130)] shadow-2xl border border-[var(--latte,#efe0cc)] overflow-hidden z-[260]">
            <div class="flex items-center justify-between px-4 py-3 border-b border-[var(--latte,#efe0cc)]">
                <strong class="text-[13px]">Notifications</strong>
                <button type="button" onclick="markAllNotificationsRead()" class="text-[11px] font-semibold text-[var(--caramel,#c97b3d)]">Mark all read</button>
            </div>
            <div id="notification-list" class="max-h-[360px] overflow-y-auto">
                <?php if (!$notifications): ?>
                <div class="px-4 py-8 text-center text-[12px] text-[var(--text-muted,#8b7c88)]">No notifications</div>
                <?php else: foreach ($notifications as $notification): ?>
                <a href="<?= htmlspecialchars($notification['link_url'] ?: '#') ?>"
                   class="notification-item block px-4 py-3 border-b border-[var(--latte,#efe0cc)] hover:bg-[var(--accent-lt,#fcefe1)] <?= !$notification['is_read'] ? 'bg-[var(--accent-lt,#fcefe1)]' : '' ?>"
                   data-notification-id="<?= (int)$notification['id'] ?>">
                    <div class="flex items-start gap-2">
                        <span class="notification-dot mt-1.5 w-2 h-2 rounded-full flex-shrink-0 <?= $notification['is_read'] ? 'opacity-0' : 'bg-[var(--caramel,#c97b3d)]' ?>"></span>
                        <span class="min-w-0">
                            <strong class="block text-[12px] leading-4"><?= htmlspecialchars($notification['title']) ?></strong>
                            <?php if ($notification['message']): ?><span class="block mt-1 text-[11px] leading-4 text-[var(--text-muted,#8b7c88)]"><?= htmlspecialchars($notification['message']) ?></span><?php endif; ?>
                            <time class="block mt-1 text-[10px] text-[var(--text-muted,#8b7c88)]"><?= htmlspecialchars($notification['created_at']) ?></time>
                        </span>
                    </div>
                </a>
                <?php endforeach; endif; ?>
            </div>
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
            <div class="text-[10.5px] text-[var(--caramel-light,#d9a06b)] capitalize truncate" title="<?= htmlspecialchars(implode(', ', $roles)) ?>">
                <?= htmlspecialchars(implode(' + ', $roles)) ?>
            </div>
        </div>
    </div>

    <button class="flex items-center gap-3 w-full px-3 py-2.5 rounded-[10px] text-[13px] font-semibold
                   bg-[rgba(198,40,40,0.14)] text-[#f2a9a9] hover:bg-[rgba(198,40,40,0.24)] transition-colors duration-150"
        onclick="window.location.href='../auth/logout.php'">
        <?= icon('logout') ?><span>Logout</span>
    </button>
</nav>

<script>
// Refresh read-only monitoring screens without interrupting forms or POS carts.
(function setupAutoRefresh() {
    const livePages = new Set([
        'dashboard.php',
        'analytics.php',
        'history.php',
        'inventory.php',
        'pending_orders.php',
        'procurement_dashboard.php',
        'procurement_reports.php',
        'supplier_performace.php'
    ]);
    const page = window.location.pathname.split('/').pop();
    if (!livePages.has(page)) return;

    const refreshAfterMs = 30000;
    window.setInterval(() => {
        if (document.hidden) return;
        if (document.querySelector('.modal-overlay.open, .modal-bg.open')) return;
        if (document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName)) return;
        window.location.reload();
    }, refreshAfterMs);
})();

function toggleNotifications() {
    const panel = document.getElementById('notification-panel');
    const button = document.getElementById('notification-btn');
    const opening = panel.classList.contains('hidden');
    panel.classList.toggle('hidden', !opening);
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) {
        document.querySelectorAll('[data-notification-id]').forEach(item => {
            item.addEventListener('click', () => markNotificationRead(item.dataset.notificationId), { once: true });
        });
    }
}

function markNotificationRead(id) {
    fetch('../api/notifications.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'read', id})
    });
}

function markAllNotificationsRead() {
    fetch('../api/notifications.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'all_read'})
    }).then(() => {
        document.querySelectorAll('.notification-dot').forEach(dot => { dot.className = 'notification-dot mt-1.5 w-2 h-2 rounded-full flex-shrink-0 opacity-0'; });
        document.getElementById('notification-count')?.remove();
    });
}

document.addEventListener('click', event => {
    const wrap = document.getElementById('notification-wrap');
    if (wrap && !wrap.contains(event.target)) document.getElementById('notification-panel')?.classList.add('hidden');
});

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

// ── Kofee Manila Loading Screen Controller ──
(function() {
    function showKofeeLoader(text) {
        const loader = document.getElementById('kofee-loader');
        if (!loader) return;
        if (text) {
            const sub = loader.querySelector('.kfs-loader-sub');
            if (sub) sub.textContent = text;
        }
        loader.classList.remove('loader-hidden');
    }

    function hideKofeeLoader() {
        const loader = document.getElementById('kofee-loader');
        if (loader) {
            loader.classList.add('loader-hidden');
        }
    }

    window.showKofeeLoader = showKofeeLoader;
    window.hideKofeeLoader = hideKofeeLoader;

    // Smoothly fade out the loader once the current page content is ready
    if (document.readyState === 'complete') {
        setTimeout(hideKofeeLoader, 100);
    } else {
        window.addEventListener('load', () => {
            setTimeout(hideKofeeLoader, 100);
        });
        // Failsafe: hide after 1.5s max in case an external font/asset stalls
        setTimeout(hideKofeeLoader, 1500);
    }

    // Handle bfcache (browser back/forward button restores)
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            hideKofeeLoader();
        }
    });

    // Intercept internal link navigation
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a');
        if (a && a.href) {
            if (
                a.target === '_blank' ||
                a.hasAttribute('download') ||
                a.getAttribute('href').startsWith('#') ||
                a.getAttribute('href').startsWith('javascript:') ||
                e.ctrlKey || e.metaKey || e.shiftKey
            ) {
                return;
            }
            try {
                const targetUrl = new URL(a.href, window.location.href);
                if (targetUrl.origin === window.location.origin) {
                    showKofeeLoader();
                }
            } catch (_) {}
        }
    }, true);

    // Intercept sidebar navigation buttons and logout
    document.querySelectorAll('.kfs-nav-btn, .kfs-logout-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            showKofeeLoader();
        });
    });

    // Intercept page unloads (form posts that redirect or reload)
    window.addEventListener('beforeunload', () => {
        showKofeeLoader();
    });
})();
</script>