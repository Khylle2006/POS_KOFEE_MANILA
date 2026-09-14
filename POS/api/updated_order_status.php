<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/ingredient_deduction.php';
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
    $pdo->beginTransaction();
    $current = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
    $current->execute([':id' => $id]);
    $order = $current->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    if ($status === 'completed' && $order['status'] !== 'completed') {
        deduct_order_ingredients($pdo, $id, (int)$_SESSION['user_id']);
    }

    $stmt = $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);
    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('update_order_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}