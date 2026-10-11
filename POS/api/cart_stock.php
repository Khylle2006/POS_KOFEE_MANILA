<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_clocked_in_for_pos(true);
header('Content-Type: application/json');
$data = request_data();
$action = $data['action'] ?? '';
// Compatibility responses are intentionally read-only: a cart is not a stock ledger.
if (in_array($action, ['refund', 'refund_batch'], true)) {
    echo json_encode(['success' => true, 'refunded' => false, 'restored_ingredients' => 0]);
    exit;
}
if ($action !== 'deduct') {
    throw new SecurityFault('ACTION_INVALID', 'Unknown cart action.');
}
if (!array_key_exists('items', $data)) {
    $data['items'] = [['id' => $data['product_id'] ?? null, 'qty' => $data['qty'] ?? null, 'size' => $data['size'] ?? null]];
}
validate_cart_stock(get_db(), $data);
echo json_encode(['success' => true, 'deducted' => false]);
