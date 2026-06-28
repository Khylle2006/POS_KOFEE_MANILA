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
        // Redirect to appropriate dashboard based on current role
        $current_role = $_SESSION['role'] ?? 'staff';
        
        if (in_array($current_role, ['admin', 'manager'])) {
            header('Location: ../admin/dashboard.php');
        } else {
            header('Location: ../staff/menu.php');
        }
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
 * Get dashboard URL based on user role
 * 
 * @return string Dashboard URL
 */
function get_dashboard_url(): string {
    if (in_array($_SESSION['role'] ?? '', ['admin', 'manager'])) {
        return '../admin/dashboard.php';
    }
    return '../staff/home.php';
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