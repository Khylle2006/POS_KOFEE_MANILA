<?php
// ==============================================================================
// FILE: api/clear_cache.php
// Admin Cache Management & Maintenance API
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is authenticated
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

// Only users with administrative or store management permissions can manage cache
$roles = $_SESSION['roles'] ?? (isset($_SESSION['role']) ? [$_SESSION['role']] : []);
$isAdmin = in_array('admin', $roles, true) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$canManage = $isAdmin || has_permission('store.manage') || has_permission('menu.manage');

if (!$canManage) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied. Only managers or administrators can manage system cache.']);
    exit;
}

$data = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? $_GET : request_data();
$action = trim($data['action'] ?? 'stats');

switch ($action) {
    case 'stats':
        echo json_encode([
            'ok'    => true,
            'stats' => cache_stats(),
        ]);
        break;

    case 'flush':
        cache_flush();
        echo json_encode([
            'ok'      => true,
            'message' => 'All application cache has been flushed successfully.',
            'stats'   => cache_stats(),
        ]);
        break;

    case 'clear_menu':
        $cleared = cache_delete_pattern('pos_menu');
        echo json_encode([
            'ok'      => true,
            'message' => "Cleared {$cleared} menu cache entries.",
            'stats'   => cache_stats(),
        ]);
        break;

    case 'clear_analytics':
        $cleared = cache_delete_pattern('analytics_');
        echo json_encode([
            'ok'      => true,
            'message' => "Cleared {$cleared} analytics cache entries.",
            'stats'   => cache_stats(),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown cache action. Valid actions: stats, flush, clear_menu, clear_analytics.']);
        break;
}
