<?php
require_once '../includes/auth.php';
require_login();
require_once '../includes/procurement_helpers.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

try {
    if ($action === 'read') {
        mark_notification_read((int)($data['id'] ?? 0), $userId);
    } elseif ($action === 'all_read') {
        mark_all_notifications_read($userId);
    } else {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid notification action']);
        exit;
    }
    echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update notification']);
}