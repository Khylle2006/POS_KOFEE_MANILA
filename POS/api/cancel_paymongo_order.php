<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payment_events.php';
$id = positive_quantity(request_data()['order_id'] ?? null, 2147483647);
cancel_gateway_order(get_db(), $id, (int)$_SESSION['user_id']);
header('Content-Type: application/json');
echo json_encode(['success' => true]);
