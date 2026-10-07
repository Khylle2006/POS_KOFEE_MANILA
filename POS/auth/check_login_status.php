<?php
// ==============================================================================
// FILE: auth/check_login_status.php
// Real-time polling endpoint for employees waiting for HR login approval.
// When HR approves the request, establishes the authenticated session.
// ==============================================================================

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/login_approval_helpers.php';

header('Content-Type: application/json; charset=utf-8');

secure_session_start();
send_security_headers();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    echo json_encode([
        'ok'     => false,
        'status' => 'error',
        'error'  => 'Invalid authorization token.'
    ]);
    exit;
}

try {
    $pdo = get_db();
    $auth = get_login_authorization_by_token($pdo, $token);

    if (!$auth) {
        echo json_encode([
            'ok'     => false,
            'status' => 'not_found',
            'error'  => 'Authorization request not found or has expired.'
        ]);
        exit;
    }

    // Check expiration
    if (strtotime($auth['expires_at']) <= time() && $auth['status'] === 'pending') {
        $pdo->prepare("UPDATE login_authorizations SET status = 'expired' WHERE id = :id")->execute([':id' => $auth['id']]);
        $auth['status'] = 'expired';
    }

    if ($auth['status'] === 'approved') {
        // If session not yet created on this browser, establish it now
        if ((int)$auth['session_created'] === 0) {
            $userStmt = $pdo->prepare('SELECT id, username, firstname, lastname, email, role, status, avatar_path FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute([':id' => $auth['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['status'] === 'active') {
                establish_user_session($pdo, $user);
                $pdo->prepare("UPDATE login_authorizations SET session_created = 1 WHERE id = :id")->execute([':id' => $auth['id']]);
            } else {
                echo json_encode([
                    'ok'     => false,
                    'status' => 'rejected',
                    'error'  => 'Account is no longer active.'
                ]);
                exit;
            }
        }

        echo json_encode([
            'ok'          => true,
            'status'      => 'approved',
            'message'     => 'Login approved! Redirecting to workspace…',
            'redirect'    => 'index.php',
            'approved_at' => $auth['approved_at'],
        ]);
        exit;
    }

    if ($auth['status'] === 'rejected') {
        echo json_encode([
            'ok'      => true,
            'status'  => 'rejected',
            'reason'  => $auth['rejection_reason'] ?: 'Login attempt denied by HR.',
            'message' => 'Your login request was rejected by HR.'
        ]);
        exit;
    }

    if ($auth['status'] === 'expired') {
        echo json_encode([
            'ok'      => true,
            'status'  => 'expired',
            'message' => 'Login request timed out without HR response. Please sign in again.'
        ]);
        exit;
    }

    if ($auth['status'] === 'cancelled') {
        echo json_encode([
            'ok'      => true,
            'status'  => 'cancelled',
            'message' => 'Login request was cancelled.'
        ]);
        exit;
    }

    // Status is 'pending'
    $elapsedSec = max(0, time() - strtotime($auth['created_at']));
    $remainingSec = max(0, strtotime($auth['expires_at']) - time());

    echo json_encode([
        'ok'            => true,
        'status'        => 'pending',
        'elapsed_sec'   => $elapsedSec,
        'remaining_sec' => $remainingSec,
        'location_name' => $auth['location_name'],
        'distance'      => $auth['distance_meters'],
    ]);
} catch (Throwable $e) {
    error_log('check_login_status error: ' . $e->getMessage());
    echo json_encode([
        'ok'     => false,
        'status' => 'error',
        'error'  => 'Server error checking authorization status.'
    ]);
}
