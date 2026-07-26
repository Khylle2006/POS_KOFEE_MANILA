<?php
// includes/auth.php - Authentication Functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require a specific role or redirect
 * 
 * @param string|null $role Required role (admin, manager, staff)
 * @param string|null $redirect_to Optional custom redirect URL
 */
function require_role(?string $role = null, ?string $redirect_to = null): void {
    // Check if user is logged in
    if (empty($_SESSION['user_id'])) {
        if ($redirect_to) {
            header('Location: ' . $redirect_to);
        } else {
            header('Location: ../login.php?reason=unauthenticated');
        }
        exit;
    }
    
    // Check if user has the required role
    if ($role !== null && ($_SESSION['role'] ?? '') !== $role) {
        // Redirect to wherever this user's actual permissions land them
        header('Location: ' . get_dashboard_url());
        exit;
    }
}

/**
 * Get current user data
 * 
 * @return array User data
 */
function current_user(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'firstname' => $_SESSION['firstname'] ?? '',
        'lastname'  => $_SESSION['lastname']  ?? '',
        'email'     => $_SESSION['email']     ?? '',
        'role'      => $_SESSION['role']      ?? 'staff',
        'status'    => $_SESSION['status']    ?? 'active',
        // Full name convenience
        'name'      => trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')),
        'full_name' => trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')),
    ];
}

/**
 * Check if current user is admin or manager
 * 
 * @return bool True if admin or manager
 */
function is_admin(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'manager']);
}

/**
 * Check if current user is staff (not admin)
 * 
 * @return bool True if staff
 */
function is_staff(): bool {
    return ($_SESSION['role'] ?? '') === 'staff';
}

/**
 * Check if current user is logged in
 * 
 * @return bool True if logged in
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Get the best landing page for the CURRENT logged-in user, based on
 * their actual permissions — not a hardcoded role name. This means a
 * newly created role (or a role whose permissions you just edited in
 * Manage Permissions) always lands somewhere it can actually see,
 * instead of falling through to menu.php and getting bounced.
 *
 * Order below is a priority list of "most useful first" — reorder it
 * if you want a different default landing page.
 *
 * @return string Dashboard URL
 */
function get_dashboard_url(): string {
    require_once __DIR__ . '/permissions.php';

    $candidates = [
        'dashboard.view' => '../php/dashboard.php',
        'orders.new'     => '../php/menu.php',
        'orders.pending' => '../php/pending_orders.php',
        'inventory.view' => '../php/inventory.php',
        'orders.history' => '../php/history.php',
        'analytics.view' => '../php/analytics.php',
        'users.manage'   => '../php/manage_users.php',
        'menu.manage'    => '../php/add_item.php',
    ];

    foreach ($candidates as $perm => $url) {
        if (has_permission($perm)) return $url;
    }

    // No permissions granted at all — send to a page that explains that,
    // instead of menu.php, which they'd otherwise be able to fully use unchecked.
    return '../php/no_access.php';
}

/**
 * Redirect to login page
 */
function redirect_to_login(string $reason = ''): void {
    $url = '../login.php';
    if (!empty($reason)) {
        $url .= '?reason=' . urlencode($reason);
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect to appropriate dashboard
 */
function redirect_to_dashboard(): void {
    header('Location: ' . get_dashboard_url());
    exit;
}
?>