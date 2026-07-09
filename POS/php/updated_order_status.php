<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();

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

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('update_order_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}