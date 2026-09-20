<?php
// ─────────────────────────────────────────────────────────────
//  api/shift_clock.php
//  Instant clock-in and clock-out for the shift gate or quick
//  fallback without photo.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_login();

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';

if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
    exit;
}

try {
    $pdo = get_db();

    $employee = get_or_create_user_employee($pdo, (int)$_SESSION['user_id']);
    $employee_id = (int)($employee['id'] ?? 0);

    if (!$employee_id) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => "Your account isn't linked to an employee profile."]);
        exit;
    }

    $today = date('Y-m-d');

    $check = $pdo->prepare('SELECT time_in, time_out FROM attendance WHERE employee_id = :e AND attendance_date = :d');
    $check->execute([':e' => $employee_id, ':d' => $today]);
    $existing = $check->fetch();

    if ($action === 'clock_in') {
        if ($existing && $existing['time_in'] && !$existing['time_out']) {
            echo json_encode(['ok' => true, 'already' => true, 'time' => date('g:i A', strtotime($existing['time_in']))]);
            exit;
        }
        if ($existing && $existing['time_in'] && $existing['time_out']) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'You already completed a shift today. Contact HR to reopen it.']);
            exit;
        }

        // Grace window before a clock-in counts as late (minutes past 08:15).
        $late = (date('H:i') > '08:15') ? 'late' : 'present';

        $pdo->prepare(
            "INSERT INTO attendance (employee_id, attendance_date, time_in, status, notes)
             VALUES (:e, :d, CURTIME(), :s, 'Quick clock-in')
             ON DUPLICATE KEY UPDATE time_in = CURTIME(), status = :s2"
        )->execute([':e' => $employee_id, ':d' => $today, ':s' => $late, ':s2' => $late]);

        echo json_encode(['ok' => true, 'time' => date('g:i A'), 'status' => $late]);
        exit;
    }

    if ($action === 'clock_out') {
        if (!$existing || !$existing['time_in']) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Clock in first before clocking out.']);
            exit;
        }
        if ($existing['time_out']) {
            echo json_encode(['ok' => true, 'already' => true, 'time' => date('g:i A', strtotime($existing['time_out']))]);
            exit;
        }

        $pdo->prepare('UPDATE attendance SET time_out = CURTIME() WHERE employee_id = :e AND attendance_date = :d')
            ->execute([':e' => $employee_id, ':d' => $today]);

        echo json_encode(['ok' => true, 'time' => date('g:i A')]);
        exit;
    }
} catch (Throwable $e) {
    error_log('shift_clock error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not record your attendance.']);
}