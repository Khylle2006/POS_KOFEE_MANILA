<?php

require_once '../includes/auth.php';
require_login();

// Define landing pages
$landing_pages = [
    'view_dashboard'     => '../php/dashboard.php',
    'orders.new'         => '../php/menu.php',
    'view_sales_history' => '../php/history.php',
    'view_reports'       => '../php/reports.php',
    'manage_products'    => '../php/products.php',
    'manage_users'       => '../php/users.php',
    'manage_roles'       => '../php/roles.php',
    'analytics.view'     => '../php/analytics.php',
];

$user_perms = get_role_permissions($_SESSION['role']);
$perm_count = count($user_perms);

// Admin -> dashboard
if ($_SESSION['role'] === 'admin') {
    header('Location: ../php/dashboard.php');
    exit;
}

// No permissions
if ($perm_count === 0) {
    header('Location: ../php/no_access.php');
    exit;
}

// Single permission -> go straight there
if ($perm_count === 1) {
    $single_perm = $user_perms[0];
    $destination = $landing_pages[$single_perm] ?? 'no_access.php';
    header('Location: ' . $destination);
    exit;
}

// Multiple permissions -> first match in priority order
foreach ($landing_pages as $perm => $url) {
    if (in_array($perm, $user_perms)) {
        header('Location: ' . $url);
        exit;
    }
}

// Fallback
header('Location: no_access.php');
exit;
?>