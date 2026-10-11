<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
putenv('APP_ENV=development');
putenv('TRUSTED_PROXIES=');
require_once __DIR__ . '/../../includes/request_security.php';
if (($_GET['action'] ?? '') === 'logout') {
    require __DIR__ . '/../../auth/logout.php';
    exit;
}
secure_session_start();
if (($_GET['action'] ?? '') === 'logout_seed') $_SESSION['logout_probe'] = 'retained';
if (($_GET['action'] ?? '') === 'logout_state') {
    header('Content-Type: application/json');
    echo json_encode(['probe' => $_SESSION['logout_probe'] ?? null], JSON_THROW_ON_ERROR);
    exit;
}
$_SESSION['_csrf_token'] = 'http-bound-token';
header('Content-Type: application/json');
if (($_GET['action'] ?? '') === 'exception') throw new RuntimeException('Sensitive database credentials and internal path');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_data();
    require_csrf_json();
    echo json_encode(['ok' => true, 'value' => $data['value'] ?? null], JSON_THROW_ON_ERROR);
} else {
    echo json_encode(['request_id' => request_id()], JSON_THROW_ON_ERROR);
}
