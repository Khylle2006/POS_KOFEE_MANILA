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
    $current = $pdo->prepare('SELECT status, ingredients_deducted_at FROM orders WHERE id = :id FOR UPDATE');
    $current->execute([':id' => $id]);
    $order = $current->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    if ($status === 'completed' && $order['status'] !== 'completed') {
        deduct_order_ingredients($pdo, $id, (int)$_SESSION['user_id']);
    }

    if ($status === 'cancelled' && $order['ingredients_deducted_at'] !== null) {
        $reqStmt = $pdo->prepare(<<<'SQL'
            SELECT pi.ingredient_id, SUM(pi.qty_used * oi.quantity) AS refund_qty
            FROM order_items oi
            JOIN product_ingredients pi
              ON pi.product_id = oi.product_id
             AND pi.size = oi.size
            WHERE oi.order_id = :order_id
            GROUP BY pi.ingredient_id
        SQL);
        $reqStmt->execute([':order_id' => $id]);
        $refunds = $reqStmt->fetchAll();

        $restoreStmt = $pdo->prepare('UPDATE ingredients SET quantity = quantity + :qty WHERE id = :id');
        foreach ($refunds as $ref) {
            $restoreStmt->execute([
                ':qty' => (float)$ref['refund_qty'],
                ':id'  => (int)$ref['ingredient_id']
            ]);
        }

        $pdo->prepare('UPDATE orders SET ingredients_deducted_at = NULL, stock_deducted = 0 WHERE id = :id')
            ->execute([':id' => $id]);
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