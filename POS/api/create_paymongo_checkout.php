<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_service.php';
require_clocked_in_for_pos(true);
header('Content-Type: application/json');
$gateway = true;
if ($gateway && !paymongo_is_demo() && !paymongo_is_configured()) {
    throw new SecurityFault('PAYMENT_NOT_CONFIGURED', 'Gateway payments are unavailable.', 503);
}
echo json_encode(submit_order(get_db(), request_data(), (int)$_SESSION['user_id'], $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', $gateway), JSON_THROW_ON_ERROR);
