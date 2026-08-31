<?php

require_once '../includes/auth.php';
require_login();

$landing_pages = [
    'dashboard.view'  => '../php/dashboard.php',
    'orders.new'      => '../php/menu.php',
    'orders.pending'  => '../php/pending_orders.php',
    'orders.history'  => '../php/history.php',
    'analytics.view'  => '../php/analytics.php',
    'menu.manage'     => '../php/add_item.php',
    'inventory.view'  => '../php/inventory.php',
    'users.manage'    => '../php/manage_users.php',
    'procurement.view'             => '../php/procurement_dashboard.php',
    'procurement.supplier.portal'  => '../php/supplier_portal.php',
];

$user_id = (int)($_SESSION['user_id'] ?? 0);
$pdo     = get_db();

// Pull roles fresh from the DB rather than trusting the session —
// avoids any case where the session was populated before a role
// change, or wasn't populated correctly at login.
$roles = [];
try {
    $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id');
    $stmt->execute([':id' => $user_id]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('auth/index.php: user_roles lookup failed — ' . $e->getMessage());
}

if (empty($roles)) {
    // No rows in user_roles yet — fall back to the legacy single role.
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute([':id' => $user_id]);
    $legacy = $stmt->fetchColumn();
    if ($legacy) $roles = [$legacy];
}

// Normalize: trim whitespace, drop empties, lowercase for safety.
$roles = array_values(array_filter(array_map(fn($r) => strtolower(trim($r)), $roles)));

error_log("auth/index.php: user_id={$user_id} roles=[" . implode(',', $roles) . "]");

// Admin in ANY held role -> dashboard, always.
if (in_array('admin', $roles, true)) {
    header('Location: ../php/dashboard.php');
    exit;
}

// Union of permissions across every role held.
$user_perms = [];
foreach ($roles as $r) {
    try {
        $stmt = $pdo->prepare('SELECT perm_key FROM role_permissions WHERE role = :r');
        $stmt->execute([':r' => $r]);
        $perms_for_role = $stmt->fetchAll(PDO::FETCH_COLUMN);
        error_log("auth/index.php: role='{$r}' perms=[" . implode(',', $perms_for_role) . "]");
        $user_perms = array_merge($user_perms, $perms_for_role);
    } catch (Exception $e) {
        error_log("auth/index.php: permission lookup failed for role '{$r}' — " . $e->getMessage());
    }
}
$user_perms = array_values(array_unique($user_perms));
$perm_count = count($user_perms);

error_log("auth/index.php: final perm_count={$perm_count} perms=[" . implode(',', $user_perms) . "]");

if ($perm_count === 0) {
    header('Location: ../php/no_access.php');
    exit;
}

if ($perm_count === 1) {
    $single_perm = $user_perms[0];
    $destination = $landing_pages[$single_perm] ?? '../php/no_access.php';
    header('Location: ' . $destination);
    exit;
}

foreach ($landing_pages as $perm => $url) {
    if (in_array($perm, $user_perms, true)) {
        header('Location: ' . $url);
        exit;
    }
}

header('Location: ../php/no_access.php');
exit;