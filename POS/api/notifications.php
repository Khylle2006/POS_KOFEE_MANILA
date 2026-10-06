<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';
require_once __DIR__ . '/../includes/notify.php';
require_login();

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$userId = (int)$_SESSION['user_id'];

try {
    $pdo = get_db();
    ensure_notifications_table($pdo);

    // ── Mark single notification as read ──
    if ($action === 'read') {
        $id = (int)($data['id'] ?? 0);
        $pdo->prepare(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE id = :i AND recipient_user_id = :u'
        )->execute([':i' => $id, ':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
        exit;
    }

    // ── Mark single notification as unread ──
    if ($action === 'unread') {
        $id = (int)($data['id'] ?? 0);
        $pdo->prepare(
            'UPDATE notifications SET is_read = 0, read_at = NULL
              WHERE id = :i AND recipient_user_id = :u'
        )->execute([':i' => $id, ':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
        exit;
    }

    // ── Mark all notifications as read ──
    if ($action === 'all_read') {
        $pdo->prepare(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE recipient_user_id = :u AND is_read = 0'
        )->execute([':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => 0]);
        exit;
    }

    // ── Delete single notification ──
    if ($action === 'delete') {
        $id = (int)($data['id'] ?? 0);
        $pdo->prepare(
            'DELETE FROM notifications WHERE id = :i AND recipient_user_id = :u'
        )->execute([':i' => $id, ':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
        exit;
    }

    // ── Clear all read notifications ──
    if ($action === 'clear_read') {
        $pdo->prepare(
            'DELETE FROM notifications WHERE recipient_user_id = :u AND is_read = 1'
        )->execute([':u' => $userId]);

        echo json_encode(['success' => true, 'unread' => unread_notification_count($userId)]);
        exit;
    }

    // ── Fetch / Poll notifications list ──
    if ($action === 'list' || $action === 'poll') {
        $limit = max(5, min(50, (int)($data['limit'] ?? 20)));
        $rows = recent_notifications_detailed($userId, $limit);
        $items = [];

        foreach ($rows as $n) {
            $actor = 'System';
            if (!empty($n['actor_id'])) {
                $actor = trim(($n['firstname'] ?? '') . ' ' . ($n['lastname'] ?? '')) ?: ($n['username'] ?? 'User');
            }

            $n_type = strtolower(($n['action_type'] ?? '') . ' ' . ($n['type'] ?? ''));
            $n_icon = match (true) {
                str_contains($n_type, 'ship') || str_contains($n_type, 'truck') || str_contains($n_type, 'receive') => 'truck',
                str_contains($n_type, 'rfq') || str_contains($n_type, 'bid') || str_contains($n_type, 'quote')     => 'rfq',
                str_contains($n_type, 'req')                                                                        => 'requests',
                str_contains($n_type, 'pay') || str_contains($n_type, 'invoice') || str_contains($n_type, 'coin')  => 'coin',
                str_contains($n_type, 'batch') || str_contains($n_type, 'stock') || str_contains($n_type, 'item')   => 'package',
                str_contains($n_type, 'user') || str_contains($n_type, 'employee')                                  => 'users',
                default => 'bell',
            };

            $clean_title = trim(preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\s]+/u', '', (string)$n['title']));

            $items[] = [
                'id'         => (int)$n['id'],
                'title'      => $clean_title,
                'message'    => (string)($n['message'] ?? ''),
                'is_read'    => (bool)$n['is_read'],
                'actor'      => $actor,
                'icon'       => $n_icon,
                'relative'   => relative_time($n['created_at']),
                'link_url'   => $n['link_url'] ? (string)$n['link_url'] : null,
                'created_at' => $n['created_at'],
            ];
        }

        echo json_encode([
            'success' => true,
            'unread'  => unread_notification_count($userId),
            'items'   => $items
        ]);
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
            $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :i')
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
                'title'          => trim(preg_replace('/^[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}\s]+/u', '', (string)$n['title'])),
                'message'        => (string)($n['message'] ?? ''),
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
    echo json_encode(['success' => false, 'error' => 'Unable to update notification: ' . $e->getMessage()]);
}