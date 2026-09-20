<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = (int)($data['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    $pdo = get_db();
    paymongo_ensure_order_columns($pdo);

    // Cancel order only if it is still pending
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled', payment_status = 'failed'
        WHERE id = :id AND payment_status = 'pending' AND status = 'pending'
    ");
    $stmt->execute([':id' => $orderId]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('cancel_paymongo_order error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

