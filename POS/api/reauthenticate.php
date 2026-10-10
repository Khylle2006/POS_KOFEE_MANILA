<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

rate_limit('reauth-user', (string)$_SESSION['user_id'], 5, 900);
rate_limit('reauth-ip', client_ip(), 20, 900);
$data = request_data();
$password = $data['password'] ?? null;
$stmt = get_db()->prepare("SELECT password FROM users WHERE id = ? AND status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$hash = $stmt->fetchColumn();
if (!is_string($password) || !verify_login_password($password, is_string($hash) ? $hash : null)) {
    throw new SecurityFault('PASSWORD_INVALID', 'Password could not be verified.', 403);
}
$_SESSION['reauthenticated_at'] = time();
security_audit(get_db(), 'reauthenticated', 'user', (int)$_SESSION['user_id']);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'success' => true]);
