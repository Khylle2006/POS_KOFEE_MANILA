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
            'dashboard'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'rfq'         => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
            'truck'       => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
            'invoice'     => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
            'scale'       => '<path d="M12 3v18"/><path d="M5 7h14"/><path d="M5 7l-3 6a3 3 0 006 0z"/><path d="M19 7l-3 6a3 3 0 006 0z"/>',
            'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'star'        => '<path d="M12 2l3 6.5 7 .9-5 5 1.3 7-6.3-3.6L5.7 21.4 7 14.4l-5-5 7-.9z"/>',
            'portal'      => '<path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M10 21v-6h4v6"/>',
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
    'employee_dashboard' => has_permission('employee_dashboard.view'),
    'new_order'    => has_permission('orders.new'),
    'pending'      => has_permission('orders.pending'),
    'history'      => has_permission('orders.history'),
    'analytics'    => has_permission('analytics.view'),
    'menu_manager' => has_permission('menu.manage'),
    'inventory'    => has_permission('inventory.view'),
    'users'        => has_permission('users.manage'),

    // ── Procurement module — gated on the specific permission each
    //    page's workflow actually needs, so the link only shows to
    //    roles who can do something once they get there. ──────────
    'procurement_home'    => has_permission('procurement.view'),
    'requisitions'         => has_permission('procurement.requisitions') || has_permission('procurement.requisition.create') || has_permission('procurement.requisition.review'),
    'rfq'                  => has_permission('procurement.rfq.manage') || has_permission('procurement.bidding.review') || has_permission('procurement.negotiation'),
    'purchase_orders'      => has_permission('procurement.po.manage') || has_permission('procurement.receiving') || has_permission('procurement.negotiation'),
    'goods_receipts'       => has_permission('procurement.receiving') || has_permission('procurement.grn.discrepancy.manage'),
    'invoices'             => has_permission('procurement.invoice.create') || has_permission('procurement.invoice.match'),
    'three_way_match'      => has_permission('procurement.invoice.match'),
    'payments'             => has_permission('procurement.payment.process'),
    'supplier_performance' => has_permission('procurement.performance.rate') || has_permission('procurement.close'),
    'suppliers'            => has_permission('procurement.suppliers.manage'),
    'procurement_reports'  => has_permission('procurement.reports.view'),
    'supplier_portal'      => has_permission('procurement.supplier.portal'),
    'hr_employees' => in_array($role, ['admin', 'hr'], true),
    'hr_attendance'=> in_array($role, ['admin', 'hr'], true),
    'hr_leave'     => in_array($role, ['admin', 'hr'], true),
    'manage_permissions' => in_array($role, ['admin', 'hr'], true),
];

// ── Live badge counts (best-effort; never break the sidebar if a
//    table isn't set up yet in this install) ──────────────────────
$pending_count = 0;
$requests_count = 0;
$procurement_badge = 0;
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
    // Procurement "needs your attention" badge — only tallies the queues
    // this particular user can actually act on, so it never nags a
    // requester about bids they have no permission to evaluate.
    if ($access['procurement_home']) {
        $pc = 0;
        if (has_permission('procurement.requisition.review')) {
            try { $pc += (int)$pdo->query("SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
        }
        if (has_permission('procurement.bidding.review')) {
            try { $pc += (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'submitted'")->fetchColumn(); } catch (Throwable $e) {}
        }
        if (has_permission('procurement.grn.discrepancy.manage')) {
            try { $pc += (int)$pdo->query("SELECT COUNT(*) FROM goods_receipts WHERE status = 'discrepancy'")->fetchColumn(); } catch (Throwable $e) {}
        }
        if (has_permission('procurement.invoice.match')) {
            try { $pc += (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'disputed'")->fetchColumn(); } catch (Throwable $e) {}
        }
        $procurement_badge = $pc;
    }
} catch (Throwable $e) {
    // DB not reachable — sidebar still renders, just without counts.
}

$initials = strtoupper(substr($user['firstname'] ?: $user['username'] ?: '?', 0, 1));

function navBtnClasses(bool $active): string {
    return 'kfs-nav-btn' . ($active ? ' active' : '');
}

$groupLabel = 'kfs-group-label';
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
<header class="kfs-topbar">

    <button id="sidebar-menu-btn" onclick="toggleSidebar()"
        class="kfs-menu-btn"
        aria-expanded="false" aria-controls="main-sidebar">
        <svg id="menu-icon-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        <svg id="menu-icon-close" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" class="kfs-hidden"><path d="M6 6l12 12M18 6L6 18"/></svg>
        <span>Menu</span>
    </button>

    <div class="kfs-topbar-brand">
        <div class="kfs-topbar-mark">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--cream,#fbf3e9)"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5V9z"/>
                <path d="M17 10.5c2.5 0 2.5 4 0 4"/>
                <path d="M7 3.5c-.6.8-.6 1.4 0 2.2M11 3.5c-.6.8-.6 1.4 0 2.2"/>
            </svg>
        </div>
        <span class="kfs-topbar-name">Kofee Manila</span>
    </div>

    <div class="kfs-flex1"></div>

    <div class="kfs-topbar-avatar">
        <?= htmlspecialchars($initials) ?>
    </div>
</header>

<!-- Spacer so page content (rendered after this include) isn't hidden under the fixed top bar -->
<div class="kfs-topbar-spacer"></div>

<!-- ── Backdrop ── -->
<div id="sidebar-backdrop" onclick="toggleSidebar(false)" class="kfs-backdrop"></div>

<!-- ── Sidebar popover panel ── -->
<nav id="main-sidebar" class="kfs-sidebar">

    <div class="kfs-sidebar-head">
        <div class="kfs-sidebar-brand">
            <div class="kfs-sidebar-mark">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--cream,#fbf3e9)"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 9h13v5a5 5 0 01-5 5H9a5 5 0 01-5-5V9z"/>
                    <path d="M17 10.5c2.5 0 2.5 4 0 4"/>
                    <path d="M7 3.5c-.6.8-.6 1.4 0 2.2M11 3.5c-.6.8-.6 1.4 0 2.2"/>
                </svg>
            </div>
            <div class="kfs-sidebar-brand-text">
                <div class="name">Kofee Manila</div>
                <div class="tag">Coffee &amp; Bites</div>
            </div>
        </div>
        <button onclick="toggleSidebar(false)" aria-label="Close menu" class="kfs-sidebar-close">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <?php if ($access['dashboard']): ?>
    <div class="<?= $groupLabel ?>">Main</div>
    <button class="<?= navBtnClasses($current === 'dashboard.php') ?>" onclick="window.location.href='dashboard.php'">
        <?= icon('home') ?><span class="kfs-nav-label">Admin    Dashboard</span>
    </button>
    <?php endif; ?>

    <?php if ($access['employee_dashboard']): ?>
    <button class="<?= navBtnClasses($current === 'employee_dashboard.php') ?>" onclick="window.location.href='employee_dashboard.php'">
        <?= icon('attendance') ?><span class="kfs-nav-label">Employee Dashboard</span>
    </button>
    <?php endif; ?>

    <?php if ($access['new_order'] || $access['pending'] || $access['inventory'] || $access['menu_manager'] || $access['history']): ?>
    <div class="<?= $groupLabel ?>">Operations</div>
    <?php endif; ?>

    <?php if ($access['new_order']): ?>
    <button class="<?= navBtnClasses($current === 'menu.php') ?>" onclick="window.location.href='menu.php'">
        <?= icon('order') ?><span class="kfs-nav-label">POS</span>
    </button>
    <?php endif; ?>

    <?php if ($access['pending']): ?>
    <button class="<?= navBtnClasses($current === 'pending_orders.php') ?>" onclick="window.location.href='pending_orders.php'">
        <?= icon('pending') ?><span class="kfs-nav-label">Pending</span>
        <?php if ($pending_count > 0): ?>
        <span class="kfs-badge"><?= $pending_count ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['menu_manager']): ?>
    <button class="<?= navBtnClasses($current === 'add_item.php') ?>" onclick="window.location.href='add_item.php'">
        <?= icon('menu') ?><span class="kfs-nav-label">Manage Menu</span>
    </button>
    <?php endif; ?>

    <?php if ($access['history']): ?>
    <button class="<?= navBtnClasses($current === 'history.php') ?>" onclick="window.location.href='history.php'">
        <?= icon('history') ?><span class="kfs-nav-label">Order History</span>
    </button>
    <?php endif; ?>

    <?php if ($access['inventory']): ?>
    <button class="<?= navBtnClasses($current === 'inventory.php') ?>" onclick="window.location.href='inventory.php'">
        <?= icon('inventory') ?><span class="kfs-nav-label">Inventory</span>
    </button>
    <?php endif; ?>

    <?php if ($access['analytics']): ?>
    <div class="<?= $groupLabel ?>">Finance</div>
    <button class="<?= navBtnClasses($current === 'analytics.php') ?>" onclick="window.location.href='analytics.php'">
        <?= icon('analytics') ?><span class="kfs-nav-label">Analytics</span>
    </button>
    <?php endif; ?>

    <?php if ($access['procurement_home'] || $access['requisitions'] || $access['rfq'] || $access['purchase_orders'] || $access['goods_receipts'] || $access['invoices'] || $access['three_way_match'] || $access['payments'] || $access['supplier_performance'] || $access['suppliers'] || $access['procurement_reports'] || $access['supplier_portal']): ?>
    <div class="<?= $groupLabel ?>">Procurement</div>
    <?php endif; ?>

    <?php if ($access['procurement_home']): ?>
    <button class="<?= navBtnClasses($current === 'procurement_dashboard.php') ?>" onclick="window.location.href='procurement_dashboard.php'">
        <?= icon('dashboard') ?><span class="kfs-nav-label">Procurement Home</span>
        <?php if ($procurement_badge > 0): ?>
        <span class="kfs-badge"><?= $procurement_badge ?></span>
        <?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($access['requisitions']): ?>
    <button class="<?= navBtnClasses($current === 'requisitions.php') ?>" onclick="window.location.href='requisitions.php'">
        <?= icon('requests') ?><span class="kfs-nav-label">Requisitions</span>
    </button>
    <?php endif; ?>

    <?php if ($access['rfq']): ?>
    <button class="<?= navBtnClasses($current === 'rfq.php') ?>" onclick="window.location.href='rfq.php'">
        <?= icon('rfq') ?><span class="kfs-nav-label">RFQ &amp; Bidding</span>
    </button>
    <?php endif; ?>

    <?php if ($access['purchase_orders']): ?>
    <button class="<?= navBtnClasses($current === 'purchase_orders.php') ?>" onclick="window.location.href='purchase_orders.php'">
        <?= icon('inventory') ?><span class="kfs-nav-label">Purchase Orders</span>
    </button>
    <?php endif; ?>

    <?php if ($access['goods_receipts']): ?>
    <button class="<?= navBtnClasses($current === 'goods_receipts.php') ?>" onclick="window.location.href='goods_receipts.php'">
        <?= icon('truck') ?><span class="kfs-nav-label">Goods Receiving</span>
    </button>
    <?php endif; ?>

    <?php if ($access['invoices']): ?>
    <button class="<?= navBtnClasses($current === 'invoices.php') ?>" onclick="window.location.href='invoices.php'">
        <?= icon('invoice') ?><span class="kfs-nav-label">Invoices</span>
    </button>
    <?php endif; ?>

    <?php if ($access['payments']): ?>
    <button class="<?= navBtnClasses($current === 'payments.php') ?>" onclick="window.location.href='payments.php'">
        <?= icon('card') ?><span class="kfs-nav-label">Payments</span>
    </button>
    <?php endif; ?>

    <?php if ($access['supplier_performance']): ?>
    <button class="<?= navBtnClasses($current === 'supplier_performace.php') ?>" onclick="window.location.href='supplier_performace.php'">
        <?= icon('star') ?><span class="kfs-nav-label">Supplier Performance</span>
    </button>
    <?php endif; ?>

    <?php if ($access['suppliers']): ?>
    <button class="<?= navBtnClasses($current === 'suppliers.php') ?>" onclick="window.location.href='suppliers.php'">
        <?= icon('employees') ?><span class="kfs-nav-label">Suppliers</span>
    </button>
    <?php endif; ?>

    <?php if ($access['procurement_reports']): ?>
    <button class="<?= navBtnClasses($current === 'procurement_reports.php') ?>" onclick="window.location.href='procurement_reports.php'">
        <?= icon('analytics') ?><span class="kfs-nav-label">Procurement Reports</span>
    </button>
    <?php endif; ?>

    <?php if ($access['supplier_portal']): ?>
    <button class="<?= navBtnClasses($current === 'supplier_portal.php') ?>" onclick="window.location.href='supplier_portal.php'">
        <?= icon('portal') ?><span class="kfs-nav-label">Supplier Portal</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave'] || $access['users'] || $access['hr_employees'] || $access['hr_attendance'] || $access['manage_permissions']): ?>
    <div class="<?= $groupLabel ?>">Human Resources</div>
    <?php endif; ?>

    <?php if ($access['users']): ?>
    <button class="<?= navBtnClasses($current === 'manage_users.php') ?>" onclick="window.location.href='manage_users.php'">
        <?= icon('employees') ?><span class="kfs-nav-label">Manage Employees</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_attendance']): ?>
    <button class="<?= navBtnClasses($current === 'attendance.php') ?>" onclick="window.location.href='attendance.php'">
        <?= icon('attendance') ?><span class="kfs-nav-label">Attendance</span>
    </button>
    <?php endif; ?>

    <?php if ($access['manage_permissions']): ?>
    <button class="<?= navBtnClasses($current === 'manage_permissions.php') ?>" onclick="window.location.href='manage_permissions.php'">
        <?= icon('permissions') ?><span class="kfs-nav-label">Manage Permission</span>
    </button>
    <?php endif; ?>

    <?php if ($access['hr_leave']): ?>
    <button class="<?= navBtnClasses($current === 'leave_requests.php') ?>" onclick="window.location.href='leave_requests.php'">
        <?= icon('leave') ?><span class="kfs-nav-label">Leave</span>
    </button>
    <?php endif; ?>

    <div class="kfs-flexgrow"></div>

    <div class="kfs-user-card">
        <div class="kfs-user-avatar">
            <?= htmlspecialchars($initials) ?>
        </div>
        <div class="kfs-user-info">
            <div class="kfs-user-name">
                <?= htmlspecialchars($user['firstname'] ?: $user['username']) ?>
            </div>
            <div class="kfs-user-role"><?= htmlspecialchars($role) ?></div>
        </div>
    </div>

    <button class="kfs-logout-btn" onclick="window.location.href='../auth/logout.php'">
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

    const willOpen = typeof force === 'boolean' ? force : !panel.classList.contains('open');

    panel.classList.toggle('open', willOpen);
    backdrop.classList.toggle('open', willOpen);

    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    iconOpen.classList.toggle('kfs-hidden', willOpen);
    iconClose.classList.toggle('kfs-hidden', !willOpen);

    document.body.classList.toggle('kfs-noscroll', willOpen);
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