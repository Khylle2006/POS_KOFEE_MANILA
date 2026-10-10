<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/payment_events.php';
require_method('POST');
require_runtime_schema(get_db());
header('Content-Type: application/json');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) throw new SecurityFault('PAYLOAD_TOO_LARGE', 'Webhook too large.', 413);
echo json_encode(process_payment_webhook(get_db(), (string)file_get_contents('php://input'), $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '', true), JSON_THROW_ON_ERROR);
