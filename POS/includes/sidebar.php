<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/procurement_helpers.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/login_approval_helpers.php';
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
            'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
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
    'dashboard'          => has_permission('dashboard.view'),
    'employee_dashboard' => has_permission('employee_dashboard.view'),
    'new_order'          => has_permission('orders.new'),
    'pending'            => has_permission('orders.pending'),
    'history'            => has_permission('orders.history'),
    'inventory'          => has_permission('inventory.view'),
    'menu_manager'       => has_permission('menu.manage'),
    'analytics'          => has_permission('analytics.view'),
    'users'              => has_permission('users.manage'),
    'hr_employees'       => has_permission('users.manage'),
    'recruitment'        => has_permission('recruitment.manage') || has_permission('users.manage'),
    'hr_attendance'      => has_permission('attendance.view'),
    'hr_leave'           => has_permission('leave.view'),
    
        'payroll'            => has_permission('payroll.view'),
    'payroll_own'        => has_permission('payroll.own'),
    'hr_requests'        => has_permission('requests.manage') || has_permission('leave.view') || in_array('admin', $roles, true),
    'manage_permissions' => has_permission('permissions.manage') || in_array('admin', $roles, true),

    // Procurement Module Access
    'procurement_view'         => has_permission('procurement.view'),
    'procurement_requisitions' => has_permission('procurement.requisitions') || has_permission('procurement.requisition.create'),
    'procurement_rfq'          => has_permission('procurement.rfq.manage') || has_permission('procurement.bidding.review'),
    'procurement_finance_review' => has_permission('procurement.finance.review') || has_permission('finance.view') || in_array('admin', $roles, true),
    'procurement_po'           => has_permission('procurement.po.manage'),
    'procurement_receiving'    => has_permission('procurement.receiving'),
    'procurement_invoices'     => has_permission('procurement.invoice.create'),
    'procurement_match'        => has_permission('procurement.invoice.match'),
    'procurement_payments'     => has_permission('procurement.payment.process'),
    'procurement_suppliers'    => has_permission('procurement.suppliers.manage'),
    'procurement_performance'  => has_permission('procurement.performance.rate'),
    'procurement_reports'      => has_permission('procurement.reports.view'),
    'supplier_portal'          => has_permission('procurement.supplier.portal') || in_array('procurement', $roles, true) || in_array('manager', $roles, true) || in_array('admin', $roles, true),
    'store_management'         => has_permission('store.view') || has_permission('store.manage') || in_array('admin', $roles, true) || in_array('manager', $roles, true),
    'login_approvals'          => has_permission('login_approval.manage'),
    'profile'                  => true,
];

// ── Live badge counts (best-effort; never break the sidebar if a
//    table isn't set up yet in this install) ──────────────────────
$pending_count = 0;
$requests_count = 0;
$pending_logins_count = 0;
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
    $pending_loans_count = 0;
    if ($access['payroll']) {
        try { $pending_loans_count = (int)$pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status = 'pending_approval'")->fetchColumn(); } catch (Throwable $e) {}
    }
    $pending_finance_approvals_count = 0;
    if ($access['procurement_finance_review']) {
        try { $pending_finance_approvals_count = (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'selected' AND finance_status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
    }
    if ($access['login_approvals']) {
        try { $pending_logins_count = count_pending_login_authorizations($pdo); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    // DB not reachable — sidebar still renders, just without counts.
}

$initials = strtoupper(substr($user['firstname'] ?: $user['username'] ?: '?', 0, 1));

// Synchronize avatar_path with users table so navbar and sidebar always display the latest photo
if (!empty($user['id']) && empty($user['avatar_path'])) {
    try {
        if (!isset($pdo)) $pdo = get_db();
        $avStmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = :uid LIMIT 1");
        $avStmt->execute([':uid' => (int)$user['id']]);
        $dbAv = $avStmt->fetchColumn();
        if (!empty($dbAv)) {
            $user['avatar_path'] = $dbAv;
            $_SESSION['avatar_path'] = $dbAv;
        }
    } catch (Throwable $e) {}
}
$notification_count = 0;
$notifications = [];
try {
    $notification_count = unread_notification_count((int)$user['id']);
    $notifications = recent_notifications_detailed((int)$user['id'], 15);
} catch (Throwable $e) {
    error_log('sidebar notifications failed: ' . $e->getMessage());
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

if (!function_exists('navBtnClasses')) {
    function navBtnClasses(bool $active): string {
        $base = 'kfs-nav-btn group flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-[10px] '
              . 'text-[13px] font-medium transition-colors duration-150 relative';
        if ($active) {
            return $base . ' active text-white font-semibold shadow-[0_6px_16px_-6px_rgba(201,123,61,0.65)]'
                          . ' bg-[linear-gradient(135deg,var(--caramel,#c47d3e)_0%,var(--espresso-deep,#1c1108)_100%)]';
        }
        return $base . ' text-[rgba(251,243,233,0.72)] hover:bg-[rgba(251,243,233,0.06)] hover:text-[var(--cream,#fbf3e9)]';
    }
}

$groupLabel = 'kfs-group-label text-[10px] font-bold tracking-[0.12em] uppercase text-[rgba(251,243,233,0.35)] px-3 pt-[14px] pb-[6px]';
?>
<script>
  try {
    if (localStorage.getItem('kfs_sidebar_minimized') === 'true' && window.innerWidth >= 1024) {
      document.documentElement.classList.add('sidebar-minimized');
    }
  } catch(e) {}
</script>
<link rel="stylesheet" href="../css/index.css?v=<?= filemtime(__DIR__ . '/../css/index.css') ?>">
<link rel="stylesheet" href="../css/sidebar.css?v=<?= filemtime(__DIR__ . '/../css/sidebar.css') ?>">
<link rel="stylesheet" href="../css/notifications.css?v=<?= file_exists(__DIR__ . '/../css/notifications.css') ? filemtime(__DIR__ . '/../css/notifications.css') : time() ?>">

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
  @keyframes notifWiggle {
    0%, 100% { transform: rotate(0deg); }
    20%, 60% { transform: rotate(12deg) scale(1.15); }
    40%, 80% { transform: rotate(-12deg) scale(1.15); }
  }
  .notif-wiggle {
    animation: notifWiggle 0.65s cubic-bezier(0.36, 0.07, 0.19, 0.97);
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
      <span class="kfs-loader-title">BrewVanti</span>
      <span class="kfs-loader-sub">Artisan Coffee Workspace…</span>
    </div>
    <div class="kfs-loader-bar">
      <div class="kfs-loader-bar-fill"></div>
    </div>
  </div>
</div>

<!-- ── Top bar: always visible, holds the Menu toggle ── -->
<header id="kofee-topbar" class="kfs-topbar fixed top-0 inset-x-0 h-14 z-[200] flex items-center gap-3 px-4
               bg-[linear-gradient(90deg,#181013_0%,#120A0D_50%,#0E0709_100%)]
               border-b border-[rgba(217,186,133,0.22)]
               text-[#FAF7F2] shadow-md">

    <button id="sidebar-menu-btn" onclick="toggleSidebarOrMinimize()"
        class="flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] font-semibold
               bg-[rgba(217,186,133,0.08)] hover:bg-[rgba(217,186,133,0.18)] border border-[rgba(217,186,133,0.22)] hover:border-[rgba(217,186,133,0.5)] transition-colors duration-150"
        aria-expanded="false" aria-controls="main-sidebar">
        <svg id="menu-icon-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        <span id="menu-btn-label">Expand</span>
    </button>

    <div class="flex items-center gap-2.5 min-w-0">
        <img src="../assets/brewvanti_logo.png" alt="BrewVanti Logo" class="kfs-topbar-logo h-8 w-auto object-contain filter drop-shadow-[0_2px_8px_rgba(0,0,0,0.5)] flex-shrink-0" style="height: 30px; max-height: 30px; width: auto; object-fit: contain;" onerror="this.src='../assets/brewvanti_pos_logo.svg'">
        <span class="font-['Playfair_Display',serif] font-bold text-[16px] tracking-wide truncate text-[#FAF7F2]">BrewVanti</span>
    </div>

    <div class="flex-1"></div>

    <!-- Mobile Backdrop Overlay -->
    <div id="notification-backdrop" class="kfs-notif-backdrop hidden" onclick="toggleNotifications(false)"></div>

    <div class="relative" id="notification-wrap">
        <button id="notification-btn" type="button" onclick="toggleNotifications()"
            aria-label="Notifications" aria-expanded="false" aria-controls="notification-panel">
            <?= icon('bell', 19) ?>
            <span id="notification-count" class="<?= $notification_count > 0 ? '' : 'hidden' ?>">
                <?= $notification_count > 99 ? '99+' : $notification_count ?>
            </span>
        </button>

        <!-- Dropdown / Mobile Sheet Notification Panel -->
        <div id="notification-panel" class="kfs-notif-panel hidden" role="region" aria-label="Notifications Panel">
            <!-- Mobile Pull / Drag Handle -->
            <div class="kfs-notif-handle-bar" onclick="toggleNotifications(false)">
                <span class="kfs-notif-handle"></span>
            </div>

            <!-- Header Section -->
            <div class="kfs-notif-header">
                <div class="kfs-notif-header-top">
                    <div class="kfs-notif-title-group">
                        <h3 class="kfs-notif-heading">Notifications</h3>
                        <span id="notif-header-badge" class="kfs-notif-badge <?= $notification_count > 0 ? 'has-unread' : '' ?>">
                            <?= $notification_count ?> unread
                        </span>
                    </div>
                    <button type="button" class="kfs-notif-close-btn" onclick="toggleNotifications(false)" aria-label="Close notifications">
                        <?= icon('x', 16) ?>
                    </button>
                </div>

                <!-- Sub-bar: Filter Tabs & Bulk Actions -->
                <div class="kfs-notif-subbar">
                    <!-- Segmented Tabs -->
                    <div class="kfs-notif-tabs">
                        <button type="button" id="notif-tab-all" class="kfs-tab-btn active" onclick="filterNotifications('all')">
                            All <span id="notif-count-all" class="kfs-badge-count"><?= count($notifications) ?></span>
                        </button>
                        <button type="button" id="notif-tab-unread" class="kfs-tab-btn" onclick="filterNotifications('unread')">
                            Unread <span id="notif-count-unread" class="kfs-badge-count"><?= $notification_count ?></span>
                        </button>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="kfs-notif-bulk-actions">
                        <button type="button" class="kfs-bulk-btn" onclick="markAllNotificationsRead()" title="Mark all notifications as read">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Read all</span>
                        </button>
                        <button type="button" class="kfs-bulk-btn kfs-bulk-btn-muted" onclick="clearReadNotifications()" title="Delete read notifications">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            <span>Clear</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- List of Notification Items -->
            <div id="notification-list" class="kfs-notif-list">
                <?php if (!$notifications): ?>
                <div id="notif-empty-state" class="kfs-notif-empty">
                    <div class="kfs-notif-empty-icon-box">
                        <?= icon('bell', 24) ?>
                    </div>
                    <h4 class="kfs-notif-empty-title">You're all caught up!</h4>
                    <p class="kfs-notif-empty-sub">No notifications right now.</p>
                </div>
                <?php else: foreach ($notifications as $n):
                    $actor = $n['actor_id']
                        ? trim(($n['firstname'] ?? '') . ' ' . ($n['lastname'] ?? '')) ?: ($n['username'] ?? 'Unknown')
                        : 'System';

                    $n_type = strtolower(($n['action_type'] ?? '') . ' ' . ($n['type'] ?? ''));
                    $n_icon = match (true) {
                        str_contains($n_type, 'ship') || str_contains($n_type, 'truck') || str_contains($n_type, 'receive') => 'truck',
                        str_contains($n_type, 'rfq') || str_contains($n_type, 'bid') || str_contains($n_type, 'quote')     => 'rfq',
                        str_contains($n_type, 'req')                                                                        => 'requests',
                        str_contains($n_type, 'pay') || str_contains($n_type, 'invoice') || str_contains($n_type, 'coin')  => 'coin',
                        str_contains($n_type, 'batch') || str_contains($n_type, 'stock') || str_contains($n_type, 'item')   => 'package',
                        str_contains($n_type, 'user') || str_contains($n_type, 'employee') || str_contains($n_type, 'login') => 'users',
                        default => 'bell',
                    };
                    $clean_title = trim(preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\s]+/u', '', $n['title']));
                ?>
                <div class="kfs-notif-item <?= !$n['is_read'] ? 'is-unread' : 'is-read' ?>"
                     data-notification-id="<?= (int)$n['id'] ?>"
                     data-is-read="<?= $n['is_read'] ? '1' : '0' ?>"
                     onclick="handleNotificationClick(<?= (int)$n['id'] ?>, '<?= addslashes($n['link_url'] ?? '') ?>')">
                    
                    <div class="kfs-notif-icon-badge kfs-badge-<?= htmlspecialchars($n_icon) ?>">
                        <?= icon($n_icon, 16) ?>
                    </div>

                    <div class="kfs-notif-content">
                        <div class="kfs-notif-item-header">
                            <h4 class="kfs-notif-item-title"><?= htmlspecialchars($clean_title) ?></h4>
                            <span class="kfs-notif-time"><?= htmlspecialchars(relative_time($n['created_at'])) ?></span>
                        </div>
                        <?php if ($n['message']): ?>
                        <p class="kfs-notif-item-msg">
                            <?= htmlspecialchars($n['message']) ?>
                        </p>
                        <?php endif; ?>
                        <div class="kfs-notif-item-footer">
                            <span class="kfs-notif-actor-pill">
                                <span><?= htmlspecialchars($actor) ?></span>
                            </span>
                            <?php if (!empty($n['link_url'])): ?>
                            <span class="kfs-notif-link-hint">Open &rarr;</span>
                            <?php endif; ?>
                            <!-- Quick action buttons -->
                            <div class="kfs-notif-actions" onclick="event.stopPropagation()">
                                <button type="button" onclick="openNotificationDetail(<?= (int)$n['id'] ?>, event)"
                                        title="View activity details"
                                        class="kfs-card-action-btn">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                </button>
                                <button type="button" onclick="toggleReadState(<?= (int)$n['id'] ?>, <?= $n['is_read'] ? '0' : '1' ?>, event)"
                                        title="<?= $n['is_read'] ? 'Mark as unread' : 'Mark as read' ?>"
                                        class="kfs-card-action-btn">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>
                                </button>
                                <button type="button" onclick="deleteNotification(<?= (int)$n['id'] ?>, event)"
                                        title="Delete notification"
                                        class="kfs-card-action-btn btn-delete">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (!$n['is_read']): ?>
                    <span class="kfs-unread-indicator"></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Footer Bar -->
            <div class="kfs-notif-footer">
                <span><?= count($notifications) ?> update<?= count($notifications) === 1 ? '' : 's' ?></span>
                <span class="kfs-notif-footer-tip">Click any item to view or open</span>
            </div>
        </div>
    </div>

    <?php
    $topbar_avatar_path = !empty($user['avatar_path']) ? trim($user['avatar_path']) : '';
    $topbar_avatar_url = $topbar_avatar_path ? ('../' . ltrim($topbar_avatar_path, '/')) : '';
    ?>
    <div class="kfs-topbar-profile-btn relative w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-[12px] font-extrabold cursor-pointer transition-all duration-150 hover:scale-105 hover:ring-2 hover:ring-[var(--caramel,#c47d3e)] shadow-xs overflow-hidden
                bg-[linear-gradient(150deg,var(--caramel-light,#d9a06b),var(--caramel,#c47d3e))]
                text-[var(--espresso-deep,#1c1108)]"
         title="My Profile &amp; Account Details"
         onclick="window.location.href='profile.php'">
        <?php if ($topbar_avatar_url): ?>
            <img src="<?= htmlspecialchars($topbar_avatar_url) ?>"
                 alt="<?= htmlspecialchars($user['name'] ?: $user['username']) ?>"
                 class="w-full h-full object-cover rounded-full"
                 style="width:32px; height:32px; object-fit:cover; border-radius:50%;"
                 onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';" />
            <span style="display:none;" class="w-full h-full items-center justify-center"><?= htmlspecialchars($initials) ?></span>
        <?php else: ?>
            <span><?= htmlspecialchars($initials) ?></span>
        <?php endif; ?>
    </div>
</header>

<!-- ── Notification Detail Modal (Outside header to avoid overflow/display issues) ── -->
<div id="notif-detail-modal"
     class="modal-overlay hidden fixed inset-0 z-[99999] bg-black/60 backdrop-blur-xs items-center justify-center p-4">
  <div class="w-full max-w-[440px] rounded-2xl bg-white shadow-2xl overflow-hidden animate-fade-in border border-[var(--latte,#efe0cc)]">
    <div class="px-5 py-4 border-b border-[var(--latte,#efe0cc)] bg-[#fcf9f5] flex items-center justify-between">
      <div class="flex items-center gap-2.5">
        <span class="w-7 h-7 rounded-lg bg-[var(--accent-lt,#fcefe1)] text-[var(--caramel,#c47d3e)] flex items-center justify-center">
          <?= icon('bell', 15) ?>
        </span>
        <strong class="text-[14px] font-bold text-[var(--espresso,#2c1a0e)]">Notification Details</strong>
      </div>
      <button type="button" onclick="closeNotificationDetail()" class="text-[var(--text-muted,#8b7c88)] hover:text-[var(--espresso,#2c1a0e)] p-1 rounded-lg flex items-center justify-center transition-colors cursor-pointer">
        <?= icon('x', 16) ?>
      </button>
    </div>
    <div id="notif-detail-body" class="px-5 py-5 text-[13px] text-[var(--text-main,#2b2130)] max-h-[75vh] overflow-y-auto">
      <p class="text-center py-6 text-[var(--text-muted,#8b7c88)]">Loading…</p>
    </div>
  </div>
</div>

<!-- Spacer so page content (rendered after this include) isn't hidden under the fixed top bar -->
<div class="kfs-topbar-spacer topbar-spacer h-14"></div>

<!-- ── Backdrop ── -->
<div id="sidebar-backdrop" onclick="toggleSidebar(false)"
     class="fixed inset-0 bg-black/50 z-[220] opacity-0 pointer-events-none transition-opacity duration-200"></div>

<!-- ── Sidebar popover panel ── -->
<nav id="main-sidebar"
     class="fixed top-0 left-0 h-full w-[280px] z-[230] flex flex-col
            px-4 pt-4 pb-4 overflow-y-auto
            bg-[linear-gradient(165deg,#1C1215_0%,#0E0709_115%)]
            border-r border-[rgba(217,186,133,0.18)]
            text-[#FAF7F2]
            transition-transform duration-300 ease-out">

    <div class="kfs-sidebar-header flex items-center justify-between pb-3.5 mb-2.5 border-b border-[rgba(217,186,133,0.15)]">
        <div class="flex items-center kfs-brand-block cursor-pointer"
             onclick="if (window.innerWidth >= 1024 && document.documentElement.classList.contains('sidebar-minimized')) toggleSidebarMinimize();"
             title="BrewVanti">
            <img src="../assets/brewvanti_logo.png" alt="BrewVanti Logo"
                 class="kfs-sidebar-logo"
                 style="height: 38px; max-height: 38px; width: auto; object-fit: contain;"
                 onerror="this.src='../assets/brewvanti_pos_logo.svg'">
        </div>

        <!-- Inner Sidebar Collapse Button (Visible when expanded, Gone when collapsed) -->
        <button type="button" id="sidebar-collapse-btn" onclick="toggleSidebarOrMinimize()"
            aria-label="Collapse sidebar" title="Collapse sidebar"
            class="kfs-inner-collapse-btn flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold
                   bg-[rgba(217,186,133,0.08)] hover:bg-[rgba(217,186,133,0.18)] border border-[rgba(217,186,133,0.22)]
                   hover:border-[rgba(217,186,133,0.5)] text-[#FAF7F2] transition-all flex-shrink-0 cursor-pointer">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M3 6h18M3 12h18M3 18h18"/>
            </svg>
            <span>Collapse</span>
        </button>
    </div>

    <?php
    $sidebar_nav_categories = [
        'main' => [
            'title' => 'Main',
            'icon'  => 'dashboard',
            'badge' => 0,
            'items' => [
                [
                    'label'  => 'Dashboard',
                    'url'    => 'dashboard.php',
                    'icon'   => 'dashboard',
                    'access' => $access['dashboard'],
                    'active' => ($current === 'dashboard.php'),
                ],
                [
                    'label'  => 'Employee Dashboard',
                    'url'    => 'employee_dashboard.php',
                    'icon'   => 'home',
                    'access' => $access['employee_dashboard'],
                    'active' => ($current === 'employee_dashboard.php'),
                ],
            ],
        ],
        'operations' => [
            'title' => 'Operations',
            'icon'  => 'order',
            'badge' => $pending_count,
            'items' => [
                [
                    'label'  => 'POS',
                    'url'    => 'menu.php',
                    'icon'   => 'order',
                    'access' => $access['new_order'],
                    'active' => ($current === 'menu.php'),
                ],
                [
                    'label'  => 'Pending Orders',
                    'url'    => 'pending_orders.php',
                    'icon'   => 'pending',
                    'badge'  => $pending_count,
                    'access' => $access['pending'],
                    'active' => ($current === 'pending_orders.php'),
                ],
                [
                    'label'  => 'Inventory',
                    'url'    => 'inventory.php',
                    'icon'   => 'inventory',
                    'access' => $access['inventory'],
                    'active' => ($current === 'inventory.php'),
                ],
                [
                    'label'  => 'Menu',
                    'url'    => 'add_item.php',
                    'icon'   => 'menu',
                    'access' => $access['menu_manager'],
                    'active' => ($current === 'add_item.php'),
                ],
                [
                    'label'  => 'Order History',
                    'url'    => 'history.php',
                    'icon'   => 'history',
                    'access' => $access['history'],
                    'active' => ($current === 'history.php'),
                ],
            ],
        ],
        'finance' => [
            'title' => 'Finance',
            'icon'  => 'coin',
            'badge' => $pending_loans_count + $pending_finance_approvals_count,
            'items' => [
                [
                    'label'  => 'Payroll Management',
                    'url'    => 'payroll.php',
                    'icon'   => 'coin',
                    'badge'  => $pending_loans_count,
                    'access' => $access['payroll'],
                    'active' => in_array($current, ['payroll.php', 'payroll_run.php', 'payroll_reports.php', 'payroll_settings.php'], true) || ($current === 'payslip.php' && $access['payroll']),
                ],
                [
                    'label'  => 'My Payslips',
                    'url'    => 'my_payslips.php',
                    'icon'   => 'file-text',
                    'access' => $access['payroll_own'],
                    'active' => ($current === 'my_payslips.php') || ($current === 'payslip.php' && !$access['payroll']),
                ],
                [
                    'label'  => 'Purchase Approvals',
                    'url'    => 'finance_purchase_approvals.php',
                    'icon'   => 'shield-check',
                    'badge'  => $pending_finance_approvals_count,
                    'access' => $access['procurement_finance_review'],
                    'active' => ($current === 'finance_purchase_approvals.php'),
                ],
                [
                    'label'  => 'Invoices',
                    'url'    => 'invoices.php',
                    'icon'   => 'invoice',
                    'access' => $access['procurement_invoices'],
                    'active' => ($current === 'invoices.php'),
                ],
                [
                    'label'  => 'Supplier Payments',
                    'url'    => 'payments.php',
                    'icon'   => 'card',
                    'access' => $access['procurement_payments'],
                    'active' => ($current === 'payments.php'),
                ],
            ],
        ],
        'procurement' => [
            'title' => (in_array('supplier', $roles, true) && count($roles) === 1) ? 'Supplier Portal' : 'Procurement',
            'icon'  => (in_array('supplier', $roles, true) && count($roles) === 1) ? 'briefcase' : 'truck',
            'badge' => 0,
            'items' => [
                [
                    'label'  => 'Overview',
                    'url'    => 'procurement_dashboard.php',
                    'icon'   => 'portal',
                    'access' => $access['procurement_view'],
                    'active' => ($current === 'procurement_dashboard.php'),
                ],
                [
                    'label'  => 'Requisitions',
                    'url'    => 'requisitions.php',
                    'icon'   => 'requests',
                    'access' => $access['procurement_requisitions'],
                    'active' => ($current === 'requisitions.php'),
                ],
                [
                    'label'  => 'RFQs & Bids',
                    'url'    => 'rfq.php',
                    'icon'   => 'rfq',
                    'access' => $access['procurement_rfq'],
                    'active' => ($current === 'rfq.php'),
                ],
                [
                    'label'  => 'Contracts',
                    'url'    => 'purchase_contracts.php',
                    'icon'   => 'file-text',
                    'access' => $access['procurement_po'],
                    'active' => ($current === 'purchase_contracts.php'),
                ],
                [
                    'label'  => 'Purchase Orders',
                    'url'    => 'purchase_orders.php',
                    'icon'   => 'truck',
                    'access' => $access['procurement_po'],
                    'active' => ($current === 'purchase_orders.php'),
                ],
                [
                    'label'  => 'Goods Receipts',
                    'url'    => 'goods_receipts.php',
                    'icon'   => 'truck',
                    'access' => $access['procurement_receiving'],
                    'active' => ($current === 'goods_receipts.php'),
                ],
                [
                    'label'  => '3-Way Match',
                    'url'    => 'three_way_match.php',
                    'icon'   => 'scale',
                    'access' => $access['procurement_match'],
                    'active' => ($current === 'three_way_match.php'),
                ],
                [
                    'label'  => 'Suppliers',
                    'url'    => 'suppliers.php',
                    'icon'   => 'employees',
                    'access' => $access['procurement_suppliers'],
                    'active' => ($current === 'suppliers.php'),
                ],
                [
                    'label'  => 'Supplier Ratings',
                    'url'    => 'supplier_performace.php',
                    'icon'   => 'star',
                    'access' => $access['procurement_performance'],
                    'active' => ($current === 'supplier_performace.php'),
                ],
                [
                    'label'  => 'Procurement Reports',
                    'url'    => 'procurement_reports.php',
                    'icon'   => 'analytics',
                    'access' => $access['procurement_reports'],
                    'active' => ($current === 'procurement_reports.php'),
                ],
                [
                    'label'  => 'Supplier Portal',
                    'url'    => 'supplier_portal.php',
                    'icon'   => 'briefcase',
                    'access' => $access['supplier_portal'],
                    'active' => ($current === 'supplier_portal.php'),
                ],
            ],
        ],
        'hr' => [
            'title' => 'HR',
            'icon'  => 'users',
            'badge' => $requests_count + $pending_logins_count,
            'items' => [
                [
                    'label'  => 'Login Approvals',
                    'url'    => 'login_approvals.php',
                    'icon'   => 'shield-check',
                    'badge'  => $pending_logins_count,
                    'access' => $access['login_approvals'],
                    'active' => ($current === 'login_approvals.php'),
                ],
                [
                    'label'  => 'Staff Requests',
                    'url'    => 'hr_requests.php',
                    'icon'   => 'requests',
                    'badge'  => $requests_count,
                    'access' => $access['hr_requests'],
                    'active' => ($current === 'hr_requests.php'),
                ],
                [
                    'label'  => 'Manage Employees',
                    'url'    => 'manage_users.php',
                    'icon'   => 'employees',
                    'access' => $access['users'],
                    'active' => ($current === 'manage_users.php'),
                ],
                [
                    'label'  => 'Recruitment',
                    'url'    => 'recruitment.php',
                    'icon'   => 'briefcase',
                    'access' => $access['recruitment'],
                    'active' => ($current === 'recruitment.php'),
                ],
                [
                    'label'  => 'Attendance',
                    'url'    => 'attendance.php',
                    'icon'   => 'attendance',
                    'access' => $access['hr_attendance'],
                    'active' => ($current === 'attendance.php'),
                ],
                [
                    'label'  => 'Leave',
                    'url'    => 'leave_requests.php',
                    'icon'   => 'leave',
                    'access' => $access['hr_leave'],
                    'active' => ($current === 'leave_requests.php'),
                ],
            ],
        ],
        'admin' => [
            'title' => 'Admin',
            'icon'  => 'permissions',
            'badge' => 0,
            'items' => [
                [
                    'label'  => 'Permissions',
                    'url'    => 'manage_permissions.php',
                    'icon'   => 'permissions',
                    'access' => $access['manage_permissions'],
                    'active' => ($current === 'manage_permissions.php'),
                ],
                [
                    'label'  => 'Business Analytics',
                    'url'    => 'analytics.php',
                    'icon'   => 'analytics',
                    'access' => $access['analytics'],
                    'active' => ($current === 'analytics.php'),
                ],
                [
                    'label'  => 'Store & Branch Hours',
                    'url'    => 'store_management.php',
                    'icon'   => 'home',
                    'access' => $access['store_management'],
                    'active' => ($current === 'store_management.php'),
                ],
            ],
        ],
    ];

    $anyCategoryActive = false;
    foreach ($sidebar_nav_categories as $cat) {
        foreach ($cat['items'] as $item) {
            if (!empty($item['access']) && !empty($item['active'])) {
                $anyCategoryActive = true;
                break 2;
            }
        }
    }
    ?>

    <div class="kfs-sidebar-nav flex-1 py-1 flex flex-col gap-1">
    <?php foreach ($sidebar_nav_categories as $catKey => $cat): ?>
        <?php
        $visibleItems = array_values(array_filter($cat['items'], fn($it) => !empty($it['access'])));
        if (empty($visibleItems)) continue;

        $hasActiveChild = false;
        foreach ($visibleItems as $it) {
            if (!empty($it['active'])) {
                $hasActiveChild = true;
                break;
            }
        }

        // Keep open if this category contains the active page, or if no active page anywhere default main
        $isOpen = $hasActiveChild || (!$anyCategoryActive && $catKey === 'main');
        $groupClass = 'kfs-cat-group' . ($isOpen ? ' is-open' : '') . ($hasActiveChild ? ' has-active-child' : '');
        ?>
        <div class="<?= $groupClass ?>" id="cat-group-<?= $catKey ?>">
            <button type="button" class="kfs-cat-header <?= $hasActiveChild ? 'is-active-cat' : '' ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                    aria-controls="cat-body-<?= $catKey ?>"
                    title="<?= htmlspecialchars($cat['title']) ?>"
                    onclick="toggleSidebarCategory('<?= $catKey ?>')">
                <span class="kfs-cat-icon"><?= icon($cat['icon'], 18) ?></span>
                <span class="kfs-cat-title"><?= htmlspecialchars($cat['title']) ?></span>
                <?php if (!empty($cat['badge']) && (int)$cat['badge'] > 0): ?>
                <span class="kfs-cat-badge"><?= (int)$cat['badge'] ?></span>
                <?php endif; ?>
                <span class="kfs-cat-chevron">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9l6 6 6-6"/>
                    </svg>
                </span>
            </button>

            <div class="kfs-cat-body" id="cat-body-<?= $catKey ?>" role="region" aria-label="<?= htmlspecialchars($cat['title']) ?> Submenu">
                <div class="kfs-cat-content">
                    <?php foreach ($visibleItems as $item): ?>
                    <button type="button"
                            class="kfs-cat-item <?= !empty($item['active']) ? 'active' : '' ?>"
                            title="<?= htmlspecialchars($item['label']) ?>"
                            onclick="window.location.href='<?= htmlspecialchars($item['url']) ?>'">
                        <?= icon($item['icon'], 16) ?>
                        <span class="flex-1 truncate"><?= htmlspecialchars($item['label']) ?></span>
                        <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                        <span class="kfs-nav-badge"><?= (int)$item['badge'] ?></span>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="flex-1"></div>

    <div class="kfs-user-card flex items-center gap-2.5 p-2.5 rounded-xl bg-[rgba(251,243,233,0.06)] mb-2 cursor-pointer hover:bg-[rgba(251,243,233,0.12)] transition-colors"
         title="View My Profile & Salary Payment Details"
         onclick="window.location.href='profile.php'">
        <div class="kfs-user-avatar w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-[12px] font-extrabold
                    bg-[linear-gradient(150deg,var(--caramel-light,#d9a06b),var(--caramel,#c47d3e))]
                    text-[var(--espresso-deep,#1c1108)] overflow-hidden">
            <?php if (!empty($topbar_avatar_url)): ?>
                <img src="<?= htmlspecialchars($topbar_avatar_url) ?>"
                     alt="<?= htmlspecialchars($user['name'] ?: $user['username']) ?>"
                     class="w-full h-full object-cover rounded-full"
                     style="width:32px; height:32px; object-fit:cover; border-radius:50%;"
                     onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';" />
                <span style="display:none;" class="w-full h-full items-center justify-center"><?= htmlspecialchars($initials) ?></span>
            <?php else: ?>
                <?= htmlspecialchars($initials) ?>
            <?php endif; ?>
        </div>
        <div class="leading-tight overflow-hidden kfs-user-info">
            <div class="text-[12.5px] font-semibold text-[var(--cream,#fbf3e9)] truncate">
                <?= htmlspecialchars($user['firstname'] ?: $user['username']) ?>
            </div>
            <div class="text-[10.5px] text-[var(--caramel-light,#d9a06b)] capitalize truncate" title="<?= htmlspecialchars(implode(', ', $roles)) ?>">
                <?= htmlspecialchars(implode(' + ', $roles)) ?>
            </div>
        </div>
    </div>

    <button class="kfs-logout-btn flex items-center gap-3 w-full px-3 py-2.5 rounded-[10px] text-[13px] font-semibold
                   bg-[rgba(198,40,40,0.14)] text-[#f2a9a9] hover:bg-[rgba(198,40,40,0.24)] transition-colors duration-150"
        title="Logout"
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

let currentNotifFilter = 'all';
let lastKnownNotifCount = <?= (int)$notification_count ?>;

function toggleNotifications(forceState) {
    const panel    = document.getElementById('notification-panel');
    const button   = document.getElementById('notification-btn');
    const backdrop = document.getElementById('notification-backdrop');
    if (!panel) return;

    const opening = typeof forceState === 'boolean' ? forceState : panel.classList.contains('hidden');

    panel.classList.toggle('hidden', !opening);
    if (backdrop) {
        backdrop.classList.toggle('hidden', !opening);
    }
    if (button) button.setAttribute('aria-expanded', opening ? 'true' : 'false');

    // On mobile, lock background scrolling while panel is open
    if (window.innerWidth < 640) {
        document.body.style.overflow = opening ? 'hidden' : '';
    }

    if (opening) {
        updateListCounts();
    }
}

function filterNotifications(mode) {
    currentNotifFilter = mode;
    const tabAll = document.getElementById('notif-tab-all');
    const tabUnread = document.getElementById('notif-tab-unread');

    if (mode === 'unread') {
        tabUnread?.classList.add('active');
        tabAll?.classList.remove('active');

        document.querySelectorAll('.kfs-notif-item').forEach(el => {
            const isRead = el.getAttribute('data-is-read') === '1';
            el.classList.toggle('hidden', isRead);
        });
    } else {
        tabAll?.classList.add('active');
        tabUnread?.classList.remove('active');

        document.querySelectorAll('.kfs-notif-item').forEach(el => {
            el.classList.remove('hidden');
        });
    }
    updateListCounts();
}

function updateListCounts() {
    const allItems = document.querySelectorAll('.kfs-notif-item');
    const unreadItems = document.querySelectorAll('.kfs-notif-item.is-unread, .kfs-notif-item[data-is-read="0"]');
    const countAllEl = document.getElementById('notif-count-all');
    const countUnreadEl = document.getElementById('notif-count-unread');
    if (countAllEl) countAllEl.textContent = allItems.length;
    if (countUnreadEl) countUnreadEl.textContent = unreadItems.length;

    const list = document.getElementById('notification-list');
    let emptyEl = document.getElementById('notif-empty-state');
    const visibleCount = currentNotifFilter === 'unread' ? unreadItems.length : allItems.length;

    if (visibleCount === 0) {
        if (!emptyEl && list) {
            emptyEl = document.createElement('div');
            emptyEl.id = 'notif-empty-state';
            emptyEl.className = 'kfs-notif-empty';
            emptyEl.innerHTML = `
                <div class="kfs-notif-empty-icon-box">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                </div>
                <h4 class="kfs-notif-empty-title">All caught up!</h4>
                <p class="kfs-notif-empty-sub">${currentNotifFilter === 'unread' ? 'No unread notifications right now.' : 'No notifications right now.'}</p>
            `;
            list.appendChild(emptyEl);
        }
        if (emptyEl) emptyEl.classList.remove('hidden');
    } else {
        if (emptyEl) emptyEl.classList.add('hidden');
    }
}

function resolveNotificationUrl(url) {
    if (!url) return '';
    url = url.trim();
    if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/')) {
        return url;
    }
    const path = window.location.pathname;
    if (!path.includes('/php/') && !url.startsWith('php/')) {
        return 'php/' + url;
    }
    return url;
}

async function handleNotificationClick(id, targetUrl) {
    // 1. Visually mark as read immediately
    const item = document.querySelector(`.kfs-notif-item[data-notification-id="${id}"]`);
    if (item) {
        item.setAttribute('data-is-read', '1');
        item.classList.remove('is-unread');
        item.classList.add('is-read');
        item.querySelector('.kfs-unread-indicator')?.remove();
    }

    // 2. Mark as read on server with keepalive
    try {
        fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'read', id }),
            keepalive: true
        }).then(r => r.json()).then(data => {
            if (data && data.success) applyUnreadCount(data.unread);
        }).catch(() => {});
    } catch (_) {}

    // 3. Direct navigation if link exists, otherwise open modal
    const resolved = resolveNotificationUrl(targetUrl);
    if (resolved) {
        toggleNotifications(false);
        if (typeof showKofeeLoader === 'function') {
            showKofeeLoader('Navigating…');
        }
        window.location.href = resolved;
    } else {
        openNotificationDetail(id);
    }
}

async function openNotificationDetail(id, event) {
    if (event) event.stopPropagation();
    const modal = document.getElementById('notif-detail-modal');
    const body  = document.getElementById('notif-detail-body');
    if (!modal || !body) return;

    body.innerHTML = `
      <div class="py-8 text-center text-[var(--text-muted,#8b7c88)] flex flex-col items-center gap-2">
        <svg class="animate-spin text-[var(--caramel,#c47d3e)]" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10"/></svg>
        <p class="text-[12px]">Loading notification details…</p>
      </div>`;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // Close panel so modal is focused cleanly
    toggleNotifications(false);

    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'detail', id })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Could not load this notification.');

        const n = data.notification;
        body.innerHTML = `
          <div class="mb-3">
            <span class="inline-block px-2.5 py-0.5 rounded-full text-[10.5px] font-bold tracking-wide uppercase bg-[var(--accent-lt,#fcefe1)] text-[var(--caramel,#c47d3e)] mb-1.5">
              ${esc(n.action_type || 'ACTIVITY')}
            </span>
            <strong class="block text-[15px] font-bold text-[var(--espresso,#2c1a0e)] leading-snug">${esc(n.title)}</strong>
          </div>
          ${n.message ? `<div class="p-3 rounded-xl bg-[#faf5ee] border border-[var(--latte,#efe0cc)] text-[12.5px] leading-relaxed text-stone-700 mb-4">${esc(n.message)}</div>` : ''}
          <dl class="space-y-2.5 text-[12.5px] border-t border-[var(--latte,#efe0cc)] pt-3.5">
            <div class="flex justify-between gap-3">
              <dt class="text-[var(--text-muted,#8b7c88)]">Performed by</dt>
              <dd class="font-semibold text-right text-[var(--espresso,#2c1a0e)]">${esc(n.actor_label)}</dd>
            </div>
            ${n.entity_type ? `
            <div class="flex justify-between gap-3">
              <dt class="text-[var(--text-muted,#8b7c88)]">Reference Record</dt>
              <dd class="font-mono text-right text-stone-700">${esc(n.entity_type)} #${esc(n.entity_id)}</dd>
            </div>` : ''}
            <div class="flex justify-between gap-3">
              <dt class="text-[var(--text-muted,#8b7c88)]">Timestamp</dt>
              <dd class="text-right text-stone-700 font-medium">${esc(n.timestamp_full)}</dd>
            </div>
            <div class="flex justify-between gap-3">
              <dt class="text-[var(--text-muted,#8b7c88)]">Received</dt>
              <dd class="text-right text-stone-500">${esc(n.relative)}</dd>
            </div>
          </dl>
          <div class="mt-5 flex items-center gap-2 pt-2 border-t border-[var(--latte,#efe0cc)]">
            <button type="button" onclick="closeNotificationDetail()"
                    class="flex-1 py-2 rounded-xl text-[12.5px] font-semibold border border-[var(--latte,#efe0cc)] hover:bg-stone-50 transition-colors cursor-pointer">
              Close
            </button>
            ${n.target_url ? `
            <a href="${esc(resolveNotificationUrl(n.target_url))}"
               class="flex-1 text-center py-2 rounded-xl text-[12.5px] font-bold text-white bg-[var(--caramel,#c47d3e)] hover:bg-[#b56e34] shadow-xs transition-colors flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Go to Page</span>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>` : ''}
          </div>`;

        // Reading it marks it read — update UI immediately
        applyUnreadCount(data.unread);
        const item = document.querySelector(`.kfs-notif-item[data-notification-id="${id}"]`);
        if (item) {
            item.setAttribute('data-is-read', '1');
            item.classList.remove('is-unread');
            item.classList.add('is-read');
            item.querySelector('.kfs-unread-indicator')?.remove();
        }
        updateListCounts();
    } catch (e) {
        body.innerHTML = `
          <div class="py-6 text-center">
            <p class="text-[13px] text-red-600 font-medium mb-3">${esc(e.message)}</p>
            <button type="button" onclick="closeNotificationDetail()" class="px-4 py-1.5 rounded-lg text-[12px] font-semibold border border-stone-200 cursor-pointer">Close</button>
          </div>`;
    }
}

function closeNotificationDetail() {
    const m = document.getElementById('notif-detail-modal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

async function markAllNotificationsRead() {
    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'all_read' })
        });
        const data = await res.json();
        if (data.success) {
            document.querySelectorAll('.kfs-unread-indicator').forEach(dot => dot.remove());
            document.querySelectorAll('.kfs-notif-item').forEach(item => {
                item.setAttribute('data-is-read', '1');
                item.classList.remove('is-unread');
                item.classList.add('is-read');
            });
            applyUnreadCount(0);
            updateListCounts();
            if (currentNotifFilter === 'unread') {
                filterNotifications('unread');
            }
        }
    } catch (e) {
        console.error('Failed to mark all notifications read:', e);
    }
}

async function clearReadNotifications() {
    const readItems = document.querySelectorAll('.kfs-notif-item[data-is-read="1"]');
    if (!readItems.length) return;

    readItems.forEach(item => {
        item.style.transition = 'all 0.2s ease';
        item.style.opacity = '0';
        item.style.transform = 'scale(0.95)';
        setTimeout(() => item.remove(), 200);
    });
    setTimeout(updateListCounts, 250);

    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clear_read' })
        });
        const data = await res.json();
        if (data.success) {
            applyUnreadCount(data.unread);
        }
    } catch (e) {
        console.error('Failed to clear read notifications:', e);
    }
}

async function toggleReadState(id, newState, event) {
    if (event) event.stopPropagation();
    const item = document.querySelector(`.kfs-notif-item[data-notification-id="${id}"]`);
    if (!item) return;

    // Optimistic UI
    item.setAttribute('data-is-read', newState ? '1' : '0');
    if (newState === 1) {
        item.classList.remove('is-unread');
        item.classList.add('is-read');
        item.querySelector('.kfs-unread-indicator')?.remove();
    } else {
        item.classList.add('is-unread');
        item.classList.remove('is-read');
        if (!item.querySelector('.kfs-unread-indicator')) {
            const dot = document.createElement('span');
            dot.className = 'kfs-unread-indicator';
            item.appendChild(dot);
        }
    }

    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: newState ? 'read' : 'unread', id })
        });
        const data = await res.json();
        if (data.success) {
            applyUnreadCount(data.unread);
            filterNotifications(currentNotifFilter);
        }
    } catch (e) {
        console.error('Failed to toggle notification state:', e);
    }
}

async function deleteNotification(id, event) {
    if (event) event.stopPropagation();
    const item = document.querySelector(`.kfs-notif-item[data-notification-id="${id}"]`);
    if (item) {
        item.style.transition = 'all 0.25s ease';
        item.style.opacity = '0';
        item.style.transform = 'translateX(24px)';
        setTimeout(() => {
            item.remove();
            updateListCounts();
        }, 250);
    }

    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id })
        });
        const data = await res.json();
        if (data.success) {
            applyUnreadCount(data.unread);
        }
    } catch (e) {
        console.error('Failed to delete notification:', e);
    }
}

function applyUnreadCount(count) {
    const num = Math.max(0, parseInt(count || 0, 10));
    const badge = document.getElementById('notification-count');
    const headerBadge = document.getElementById('notif-header-badge');
    const unreadTabCount = document.getElementById('notif-count-unread');

    if (headerBadge) {
        headerBadge.textContent = num + ' unread';
        headerBadge.classList.toggle('has-unread', num > 0);
    }
    if (unreadTabCount) unreadTabCount.textContent = num;

    if (badge) {
        if (num < 1) {
            badge.classList.add('hidden');
            badge.textContent = '0';
        } else {
            badge.classList.remove('hidden');
            badge.textContent = num > 99 ? '99+' : num;
        }
    }
}

function renderNotificationItems(items) {
    const list = document.getElementById('notification-list');
    if (!list) return;

    if (!items || !items.length) {
        updateListCounts();
        return;
    }

    const html = items.map(n => {
        const isRead = n.is_read ? 1 : 0;
        const iconSvg = getIconSvg(n.icon);
        const iconClass = 'kfs-badge-' + (n.icon || 'bell');
        const safeUrl = (n.link_url || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        return `
            <div class="kfs-notif-item ${!isRead ? 'is-unread' : 'is-read'} ${currentNotifFilter === 'unread' && isRead ? 'hidden' : ''}"
                 data-notification-id="${n.id}"
                 data-is-read="${isRead}"
                 onclick="handleNotificationClick(${n.id}, '${safeUrl}')">
                
                <div class="kfs-notif-icon-badge ${iconClass}">
                    ${iconSvg}
                </div>

                <div class="kfs-notif-content">
                    <div class="kfs-notif-item-header">
                        <h4 class="kfs-notif-item-title">${esc(n.title)}</h4>
                        <span class="kfs-notif-time">${esc(n.relative)}</span>
                    </div>

                    ${n.message ? `<p class="kfs-notif-item-msg">${esc(n.message)}</p>` : ''}

                    <div class="kfs-notif-item-footer">
                        <span class="kfs-notif-actor-pill">${esc(n.actor)}</span>
                        ${n.link_url ? '<span class="kfs-notif-link-hint">Open &rarr;</span>' : ''}
                        
                        <div class="kfs-notif-actions" onclick="event.stopPropagation()">
                            <button type="button" onclick="openNotificationDetail(${n.id}, event)"
                                    title="View activity details"
                                    class="kfs-card-action-btn">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                            </button>
                            <button type="button" onclick="toggleReadState(${n.id}, ${isRead ? 0 : 1}, event)"
                                    title="${isRead ? 'Mark as unread' : 'Mark as read'}"
                                    class="kfs-card-action-btn">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>
                            </button>
                            <button type="button" onclick="deleteNotification(${n.id}, event)"
                                    title="Delete notification"
                                    class="kfs-card-action-btn btn-delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                ${!isRead ? '<span class="kfs-unread-indicator"></span>' : ''}
            </div>`;
    }).join('');

    list.innerHTML = html;
    updateListCounts();
}

function getIconSvg(name) {
    const icons = {
        'truck': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/></svg>',
        'rfq': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>',
        'requests': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>',
        'coin': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 6.4v1.2M12 16.4v1.2M9.2 15.4c.5.9 1.5 1.5 2.8 1.5 1.8 0 3-1 3-2.3 0-3.2-5.6-1.7-5.6-4.9 0-1.3 1.2-2.3 3-2.3 1.2 0 2.2.5 2.7 1.4"/></svg>',
        'package': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
        'users': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.4 6.5-6.4s6.5 2.8 6.5 6.4"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.7 13.6c2.6.4 4.6 2.6 4.8 5.4"/></svg>',
        'bell': '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 00-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>'
    };
    return icons[name] || icons['bell'];
}

async function pollNotifications() {
    try {
        const res = await fetch('../api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'poll' })
        });
        const data = await res.json();
        if (!data.success) return;

        const newCount = parseInt(data.unread || 0, 10);
        if (newCount > lastKnownNotifCount) {
            const bell = document.getElementById('notification-btn');
            if (bell) {
                bell.classList.add('notif-wiggle');
                setTimeout(() => bell.classList.remove('notif-wiggle'), 700);
            }
        }
        lastKnownNotifCount = newCount;
        applyUnreadCount(newCount);

        const panel = document.getElementById('notification-panel');
        if (panel && !panel.classList.contains('hidden') && Array.isArray(data.items)) {
            renderNotificationItems(data.items);
        }
    } catch (_) {}
}

setInterval(pollNotifications, 20000);

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

document.addEventListener('click', event => {
    const wrap = document.getElementById('notification-wrap');
    const panel = document.getElementById('notification-panel');
    const backdrop = document.getElementById('notification-backdrop');
    if (panel && !panel.classList.contains('hidden')) {
        if (wrap && !wrap.contains(event.target) && (!backdrop || event.target === backdrop)) {
            toggleNotifications(false);
        }
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeNotificationDetail();
        toggleNotifications(false);
    }
});

document.getElementById('notif-detail-modal')?.addEventListener('click', e => {
    if (e.target.id === 'notif-detail-modal') closeNotificationDetail();
});

function toggleSidebarOrMinimize() {
    if (window.innerWidth >= 1024) {
        toggleSidebarMinimize();
    } else {
        toggleSidebar();
    }
}

function toggleSidebar(force) {
    if (window.innerWidth >= 1024) {
        toggleSidebarMinimize();
        return;
    }

    const panel    = document.getElementById('main-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const btn      = document.getElementById('sidebar-menu-btn');

    const willOpen = typeof force === 'boolean' ? force : !panel.classList.contains('open');

    panel.classList.toggle('open', willOpen);
    panel.classList.toggle('translate-x-0', willOpen);
    panel.classList.toggle('-translate-x-full', !willOpen);

    backdrop.classList.toggle('opacity-0', !willOpen);
    backdrop.classList.toggle('pointer-events-none', !willOpen);
    backdrop.classList.toggle('opacity-100', willOpen);
    backdrop.classList.toggle('pointer-events-auto', willOpen);

    if (btn) {
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    document.body.classList.toggle('overflow-hidden', willOpen);
}

function toggleSidebarMinimize() {
    const docEl = document.documentElement;
    const isMin = docEl.classList.toggle('sidebar-minimized');
    document.body.classList.toggle('sidebar-minimized', isMin);

    try {
        localStorage.setItem('kfs_sidebar_minimized', isMin ? 'true' : 'false');
    } catch(e) {}

    updateCollapseButtonState(isMin);
}

function updateCollapseButtonState(isMin) {
    const label = document.getElementById('menu-btn-label');
    if (label) {
        if (window.innerWidth >= 1024) {
            label.textContent = 'Expand';
        } else {
            label.textContent = 'Menu';
        }
    }
}

// Initial sync on script load & resize
(function initSidebarMinimizeState() {
    const isMin = document.documentElement.classList.contains('sidebar-minimized');
    if (isMin && document.body) {
        document.body.classList.add('sidebar-minimized');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            const min = document.documentElement.classList.contains('sidebar-minimized');
            if (min) document.body.classList.add('sidebar-minimized');
            updateCollapseButtonState(min);
        });
    } else {
        updateCollapseButtonState(isMin);
    }

    window.addEventListener('resize', () => {
        const min = document.documentElement.classList.contains('sidebar-minimized');
        updateCollapseButtonState(min);
    });
})();

function toggleSidebarCategory(catId) {
    const group = document.getElementById('cat-group-' + catId);
    if (!group) return;
    const btn = group.querySelector('.kfs-cat-header');
    const willOpen = !group.classList.contains('is-open');

    group.classList.toggle('is-open', willOpen);
    if (btn) btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

    try {
        const saved = JSON.parse(localStorage.getItem('kfs_open_cats') || '{}');
        saved[catId] = willOpen;
        localStorage.setItem('kfs_open_cats', JSON.stringify(saved));
    } catch(e) {}
}

(function restoreCategoryState() {
    try {
        const saved = JSON.parse(localStorage.getItem('kfs_open_cats') || '{}');
        Object.keys(saved).forEach(catId => {
            const group = document.getElementById('cat-group-' + catId);
            if (!group) return;
            // Retain active category open state if it has the current page
            if (group.classList.contains('has-active-child')) return;

            const btn = group.querySelector('.kfs-cat-header');
            if (saved[catId] === true) {
                group.classList.add('is-open');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            } else if (saved[catId] === false) {
                group.classList.remove('is-open');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        });
    } catch(e) {}
})();

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

    // Intercept sidebar navigation buttons, category items, and logout
    document.querySelectorAll('.kfs-nav-btn, .kfs-cat-item, .kfs-logout-btn').forEach(btn => {
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