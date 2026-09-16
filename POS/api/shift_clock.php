<?php
// ─────────────────────────────────────────────────────────────
//  api/shift_clock.php
//  Instant clock-in for the shift gate (no photo).
//  The photo-based flow in api/mark_attendance.php is unchanged
//  and remains the richer path from the employee dashboard.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_login();

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

if ($action !== 'clock_in') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
    exit;
}

try {
    $pdo = get_db();

    $emp = $pdo->prepare("SELECT id FROM employees WHERE user_id = :u AND status = 'active' LIMIT 1");
    $emp->execute([':u' => (int)$_SESSION['user_id']]);
    $employee_id = (int)$emp->fetchColumn();

    if (!$employee_id) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => "Your account isn't linked to an employee profile."]);
        exit;
    }

    $today = date('Y-m-d');

    $check = $pdo->prepare('SELECT time_in, time_out FROM attendance WHERE employee_id = :e AND attendance_date = :d');
    $check->execute([':e' => $employee_id, ':d' => $today]);
    $existing = $check->fetch();

    if ($existing && $existing['time_in'] && !$existing['time_out']) {
        echo json_encode(['ok' => true, 'already' => true, 'time' => date('g:i A', strtotime($existing['time_in']))]);
        exit;
    }
    if ($existing && $existing['time_in'] && $existing['time_out']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'You already completed a shift today. Contact HR to reopen it.']);
        exit;
    }

    // Grace window before a clock-in counts as late (minutes past 08:00).
    $late = (date('H:i') > '08:15') ? 'late' : 'present';

    $pdo->prepare(
        "INSERT INTO attendance (employee_id, attendance_date, time_in, status, notes)
         VALUES (:e, :d, CURTIME(), :s, 'Quick clock-in from shift gate')
         ON DUPLICATE KEY UPDATE time_in = CURTIME(), status = :s2"
    )->execute([':e' => $employee_id, ':d' => $today, ':s' => $late, ':s2' => $late]);

    echo json_encode(['ok' => true, 'time' => date('g:i A'), 'status' => $late]);
} catch (Throwable $e) {
    error_log('shift_clock error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not record your clock-in.']);
}