<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/notifications.php';
require_login();
require_permission('orders.pending');

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$id     = (int)($data['order_id'] ?? 0);
$status = $data['status'] ?? '';

$allowed = ['pending', 'completed', 'cancelled'];

if (!$id || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    $pdo  = get_db();
    $stmt = $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);

    $owner = $pdo->prepare('SELECT user_id FROM orders WHERE id = :id');
    $owner->execute([':id' => $id]);
    $ownerId = (int)$owner->fetchColumn();
    if ($ownerId) {
        notify_user($pdo, 'order:status:' . $id . ':' . $status, 'order', 'Order status updated', 'Order #' . str_pad($id, 4, '0', STR_PAD_LEFT) . ' is now ' . $status . '.', 'history.php', $ownerId);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('update_order_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}