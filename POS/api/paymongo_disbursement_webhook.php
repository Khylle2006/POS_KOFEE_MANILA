<?php
// ==============================================================================
// API: api/paymongo_disbursement_webhook.php
// Webhook endpoint for receiving PayMongo disbursement status callbacks
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/paymongo_disbursement_helpers.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$payload = file_get_contents('php://input');
if (!$payload) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty request body.']);
    exit;
}

$sig_header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ($_SERVER['HTTP_PAYMONGO_SIGNATURE_HEADER'] ?? '');

$pdo = get_db();
$res = process_paymongo_disbursement_webhook($pdo, $payload, $sig_header);

if ($res['ok']) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $res]);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $res['error']]);
}
