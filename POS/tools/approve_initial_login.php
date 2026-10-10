<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
$options = getopt('', ['request:', 'reviewer:']);
try {
    $pdo = get_db(); require_runtime_schema($pdo);
    $reviewer = (int)($options['reviewer'] ?? 0); $request = (int)($options['request'] ?? 0);
    $stmt = $pdo->prepare("SELECT u.password FROM users u WHERE u.id = ? AND u.status = 'active' AND (u.role = 'admin' OR EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role = ur.role WHERE ur.user_id = u.id AND rp.perm_key = 'login_approval.manage'))");
    $stmt->execute([$reviewer]); $hash = $stmt->fetchColumn();
    fwrite(STDOUT, "HR reviewer password (read from standard input): ");
    $password = rtrim((string)fgets(STDIN), "\r\n");
    if (!$hash || !password_verify($password, $hash)) throw new SecurityFault('APPROVAL_DENIED', 'HR approval denied.', 403);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE login_authorizations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending' AND expires_at > NOW() AND session_created = 0");
    $stmt->execute([$reviewer, $request]);
    if ($stmt->rowCount() !== 1) throw new SecurityFault('APPROVAL_EXPIRED', 'Request is expired or already reviewed.', 409);
    $_SESSION['user_id'] = $reviewer;
    security_audit($pdo, 'initial_login_approved', 'login_authorization', $request);
    $pdo->commit(); echo "Login request approved. Only its pending browser can consume it.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Approval failed: ' . get_class($exception) . PHP_EOL); exit(1);
}
