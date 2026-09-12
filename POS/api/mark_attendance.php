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

$data   = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$photo  = $data['photo']  ?? '';

if (!in_array($action, ['clock_in','clock_out'], true) || !$photo) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>'Missing action or photo.']);
    exit;
}

// ── Decode base64 image ────────────────────────
if (!preg_match('/^data:image\/(jpeg|png);base64,/', $photo, $m)) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>'Invalid image data.']);
    exit;
}
$ext      = $m[1] === 'png' ? 'png' : 'jpg';
$raw      = base64_decode(substr($photo, strpos($photo, ',') + 1));
if ($raw === false || strlen($raw) < 100) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>'Could not decode image.']);
    exit;
}

$upload_dir = __DIR__ . '/../uploads/attendance/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$today    = date('Y-m-d');
$filename = 'emp' . $employee['id'] . '_' . $today . '_' . ($action === 'clock_in' ? 'in' : 'out') . '_' . time() . '.' . $ext;
$filepath = $upload_dir . $filename;
$rel_path = 'uploads/attendance/' . $filename; // stored in DB, resolved as ../<rel_path> from php/

if (file_put_contents($filepath, $raw) === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Failed to save photo.']);
    exit;
}

try {
    $s = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=:e AND attendance_date=:d');
    $s->execute([':e'=>$employee['id'], ':d'=>$today]);
    $existing = $s->fetch();

    if ($action === 'clock_in') {
        if ($existing && $existing['time_in']) {
            @unlink($filepath);
            echo json_encode(['ok'=>false, 'error'=>'You already clocked in today.']);
            exit;
        }
        $pdo->prepare("
            INSERT INTO attendance (employee_id, attendance_date, time_in, time_in_photo, status)
            VALUES (:e, :d, CURTIME(), :p, 'present')
            ON DUPLICATE KEY UPDATE time_in = CURTIME(), time_in_photo = :p2, status = 'present'
        ")->execute([':e'=>$employee['id'], ':d'=>$today, ':p'=>$rel_path, ':p2'=>$rel_path]);
    } else {
        if (!$existing || !$existing['time_in']) {
            @unlink($filepath);
            echo json_encode(['ok'=>false, 'error'=>'Clock in first before clocking out.']);
            exit;
        }
        if ($existing['time_out']) {
            @unlink($filepath);
            echo json_encode(['ok'=>false, 'error'=>'You already clocked out today.']);
            exit;
        }
        $pdo->prepare('UPDATE attendance SET time_out = CURTIME(), time_out_photo = :p WHERE employee_id=:e AND attendance_date=:d')
            ->execute([':p'=>$rel_path, ':e'=>$employee['id'], ':d'=>$today]);
    }

    echo json_encode(['ok'=>true, 'photo'=>$rel_path, 'time'=>date('g:i A')]);
} catch (PDOException $e) {
    @unlink($filepath);
    error_log('mark_attendance error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Database error.']);
}