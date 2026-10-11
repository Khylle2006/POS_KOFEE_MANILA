<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/jobs.php';
try {
    $pdo = get_db();
    require_runtime_schema($pdo);
    if (!(int)$pdo->query("SELECT GET_LOCK('kofee_background_worker', 0)")->fetchColumn()) exit(0);
    recover_payment_jobs($pdo);
    // A crashed email worker may safely retry. Transfers are never submitted by this worker.
    $pdo->exec("UPDATE background_jobs SET status = 'pending', locked_at = NULL WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $jobs = $pdo->query("SELECT * FROM background_jobs WHERE status = 'pending' AND available_at <= NOW() ORDER BY id LIMIT 25")->fetchAll();
    foreach ($jobs as $job) {
        $pdo->prepare("UPDATE background_jobs SET status = 'processing', attempts = attempts + 1, locked_at = NOW() WHERE id = ?")->execute([$job['id']]);
        try {
            perform_background_job($pdo, $job);
            $pdo->prepare("UPDATE background_jobs SET status = 'completed', locked_at = NULL, payload = '{}' WHERE id = ?")->execute([$job['id']]);
        } catch (Throwable $exception) {
            $attempt = (int)$job['attempts'] + 1;
            $code = $exception instanceof SecurityFault ? $exception->errorCode : 'JOB_FAILED';
            $status = $attempt >= 12 || $code === 'MANUAL_RECONCILIATION_REQUIRED' ? 'failed' : 'pending';
            $delay = min(3600, 60 * (2 ** min($attempt, 6)));
            $pdo->prepare('UPDATE background_jobs SET status = ?, locked_at = NULL, last_error_code = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?')
                ->execute([$status, $code, $delay, $job['id']]);
            error_log('job=' . $job['id'] . ' code=' . $code);
        }
    }
    $pdo->query("SELECT RELEASE_LOCK('kofee_background_worker')");
    echo count($jobs) . " jobs processed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Worker failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}
