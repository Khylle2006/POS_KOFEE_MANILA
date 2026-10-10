<?php
declare(strict_types=1);
require_once __DIR__ . '/login_approval_helpers.php';

/** Continue only after every enabled sign-in factor has been verified.
 * @param array<string, mixed> $user @param array<string, mixed> $context
 */
function complete_password_login(PDO $pdo, array $user, array $context): never
{
    record_login_attempt((string)$user['username'], true);
    $stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role');
    $stmt->execute([(int)$user['id']]);
    $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [$user['role']];
    if (does_user_require_login_approval($pdo, $user) && !is_device_trusted($pdo, (int)$user['id'], '')) {
        $lat = is_numeric($context['latitude'] ?? null) ? (float)$context['latitude'] : null;
        $lon = is_numeric($context['longitude'] ?? null) ? (float)$context['longitude'] : null;
        $acc = is_numeric($context['accuracy'] ?? null) ? (float)$context['accuracy'] : null;
        $locationStatus = is_string($context['location_status'] ?? null) ? $context['location_status'] : 'unknown';
        $deviceInfo = is_string($context['device_info'] ?? null) ? $context['device_info'] : '';
        $result = create_login_authorization($pdo, $user, $lat, $lon, $acc, $locationStatus, $deviceInfo);
        $_SESSION['pending_auth_token'] = $result['auth_token'];
        $_SESSION['pending_auth_user_id'] = (int)$user['id'];
        header('Location: waiting_approval.php?token=' . urlencode($result['auth_token']));
        exit;
    }
    establish_user_session($pdo, $user);
    header('Location: index.php');
    exit;
}
