<?php
declare(strict_types=1);
require_once __DIR__ . '/account_security.php';
require_once __DIR__ . '/jobs.php';

function issue_application_tracking(PDO $pdo, string $type, int $id, string $email): void
{
    if (!in_array($type, ['job', 'supplier'], true)) throw new LogicException('Invalid application type.');
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE application_tracking_tokens SET revoked_at = NOW() WHERE application_type = ? AND application_id = ? AND revoked_at IS NULL')->execute([$type, $id]);
    $pdo->prepare('INSERT INTO application_tracking_tokens (application_type, application_id, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([$type, $id, hash('sha256', $token)]);
    $page = $type === 'job' ? 'careers.php' : 'supplier_partnership.php';
    // A URL fragment keeps the token out of access logs and referrers on page navigation.
    $link = htmlspecialchars(app_url() . '/' . $page . '#track=' . $token, ENT_QUOTES, 'UTF-8');
    enqueue_job($pdo, 'tracking-email-' . hash('sha256', $token), 'email', ['to' => $email,
        'subject' => 'Your Kofee Manila application status',
        'html' => '<p>View your application status using this private link. It expires in 30 days.</p><p><a href="' . $link . '">View application status</a></p>']);
}

function public_submission_guard(string $scope): void
{
    secure_session_start();
    require_method('POST');
    require_csrf_json();
    require_runtime_schema(get_db());
    rate_limit($scope, client_ip(), 10, 3600);
}

/** @return array<string, mixed> */
function application_tracking_request(PDO $pdo, string $type): array
{
    require_method('POST');
    secure_session_start();
    require_csrf_json();
    require_runtime_schema($pdo);
    $data = request_data();
    $table = $type === 'job' ? 'job_applications' : 'supplier_applications';
    if (($data['action'] ?? '') === 'recover') {
        encode_job_payload('email', []);
        app_url();
        $email = $data['email'] ?? '';
        rate_limit('tracking-recovery-ip', client_ip(), 20, 3600);
        rate_limit('tracking-recovery-address', is_string($email) ? strtolower(trim($email)) : '', 5, 3600);
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id, email FROM $table WHERE email = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([trim($email)]);
            $application = $stmt->fetch();
            if ($application) {
                $pdo->beginTransaction();
                try {
                    $lock = $pdo->prepare("SELECT id FROM $table WHERE id = ? FOR UPDATE");
                    $lock->execute([$application['id']]);
                    issue_application_tracking($pdo, $type, (int)$application['id'], $application['email']);
                    $pdo->commit();
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $exception;
                }
            }
        }
        return ['success' => true, 'recovery' => true, 'message' => 'If an application matches, a private status link will be emailed.'];
    }
    rate_limit('tracking-status-ip', client_ip(), 60, 3600);
    $token = $data['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new SecurityFault('TRACKING_INVALID', 'Use the private status link sent to your email.', 404);
    }
    $stmt = $pdo->prepare("SELECT a.status, a.created_at FROM application_tracking_tokens t JOIN $table a ON a.id = t.application_id
        WHERE t.application_type = ? AND t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()");
    $stmt->execute([$type, hash('sha256', $token)]);
    $application = $stmt->fetch();
    if (!$application) throw new SecurityFault('TRACKING_INVALID', 'This link is invalid or expired. Request a new link.', 404);
    return ['success' => true, 'status' => $application['status'], 'submitted_at' => date('F j, Y', strtotime($application['created_at']))];
}
