<?php
require_once '../includes/auth.php';
require_login();

header('Content-Type: application/json');

$pdo  = get_db();
$user = current_user();

$stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $user['id']]);
$employee = $stmt->fetch();

if (!$employee) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>"Your account isn't linked to an employee profile."]);
    exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$leave_types = ['Vacation','Sick','Emergency','Unpaid','Other'];

$type   = in_array($data['leave_type'] ?? '', $leave_types, true) ? $data['leave_type'] : 'Vacation';
$start  = $data['start_date'] ?? '';
$end    = $data['end_date']   ?? '';
$reason = trim($data['reason'] ?? '');

if (!$start || !$end) {
    echo json_encode(['ok'=>false, 'error'=>'Start date and end date are required.']);
    exit;
}
if (strtotime($end) < strtotime($start)) {
    echo json_encode(['ok'=>false, 'error'=>'End date cannot be before start date.']);
    exit;
}

$days = (strtotime($end) - strtotime($start)) / 86400 + 1;

try {
    $pdo->prepare(
        'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days_count, reason, status)
         VALUES (:e,:t,:s,:en,:d,:r,"pending")'
    )->execute([':e'=>$employee['id'], ':t'=>$type, ':s'=>$start, ':en'=>$end, ':d'=>$days, ':r'=>$reason]);

    echo json_encode(['ok'=>true]);
} catch (PDOException $e) {
    error_log('submit_leave error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Database error.']);
}