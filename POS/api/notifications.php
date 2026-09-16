<?php
require_once '../includes/auth.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/notify.php';
require_login();

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

try {
    if ($action === 'read') {
        $id = (int)($data['id'] ?? 0);
        get_db()->prepare(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE id = :i AND recipient_user_id = :u AND is_read = 0'
        )->execute([':i' => $id, ':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
        exit;
    }

    if ($action === 'all_read') {
        get_db()->prepare(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE recipient_user_id = :u AND is_read = 0'
        )->execute([':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => 0]);
        exit;
    }

    // ── Audit detail: who did what, and exactly when ──
    if ($action === 'detail') {
        $id = (int)($data['id'] ?? 0);
        $n  = notification_detail($id, $userId);

        if (!$n) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Notification not found.']);
            exit;
        }

        // Opening the detail marks it read.
        if (!$n['is_read']) {
            get_db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :i')
                    ->execute([':i' => $id]);
        }

        $actor_label = 'System';
        if ($n['actor_id']) {
            $actor_label = trim(($n['firstname'] ?? '') . ' ' . ($n['lastname'] ?? ''))
                        ?: ($n['username'] ?? 'Unknown user');
            if (!empty($n['actor_role'])) {
                $actor_label .= ' (' . $n['actor_role'] . ')';
            }
        }

        echo json_encode([
            'success' => true,
            'unread'  => unread_notification_count($userId),
            'notification' => [
                'id'             => (int)$n['id'],
                'title'          => $n['title'],
                'message'        => $n['message'],
                'action_type'    => $n['action_type'] ?: strtoupper((string)$n['type']),
                'entity_type'    => $n['entity_type'],
                'entity_id'      => $n['entity_id'],
                'actor_label'    => $actor_label,
                'target_url'     => $n['link_url'],
                'timestamp_full' => date('M d, Y · g:i:s A', strtotime($n['created_at'])),
                'relative'       => relative_time($n['created_at']),
            ],
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid notification action.']);
} catch (Throwable $e) {
    error_log('notifications api error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to update notification.']);
}