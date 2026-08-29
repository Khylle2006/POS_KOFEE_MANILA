<?php
require_once __DIR__ . '/db.php';

function create_notification(PDO $pdo, string $eventKey, string $type, string $title, string $message, ?string $link = null, ?int $userId = null, ?string $roleKey = null): void {
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO notifications (user_id, role_key, type, title, message, link, event_key)
         VALUES (:user_id, :role_key, :type, :title, :message, :link, :event_key)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':role_key' => $roleKey,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':link' => $link,
        ':event_key' => $eventKey,
    ]);
}

function notify_roles(PDO $pdo, string $eventKey, string $type, string $title, string $message, ?string $link, array $roles): void {
    foreach ($roles as $role) {
        create_notification($pdo, $eventKey . ':role:' . $role, $type, $title, $message, $link, null, $role);
    }
}

function notify_user(PDO $pdo, string $eventKey, string $type, string $title, string $message, ?string $link, int $userId): void {
    create_notification($pdo, $eventKey . ':user:' . $userId, $type, $title, $message, $link, $userId);
}

function sync_notifications(PDO $pdo, array $user): void {
    try {
        $roles = ['admin', 'manager', 'staff', 'crew'];
        $pending = $pdo->query("SELECT id, total_amount FROM orders WHERE status = 'pending'")->fetchAll();
        foreach ($pending as $order) {
            notify_roles($pdo, 'order:new:' . $order['id'], 'order', 'New order received', 'Order #' . str_pad($order['id'], 4, '0', STR_PAD_LEFT) . ' is waiting.', 'pending_orders.php', $roles);
        }

        $requests = $pdo->query("SELECT id, request_type FROM hr_requests WHERE status = 'pending'")->fetchAll();
        foreach ($requests as $request) {
            notify_roles($pdo, 'request:new:' . $request['id'], 'request', 'New HR request', $request['request_type'] . ' needs review.', 'hr_requests.php', ['admin', 'hr']);
        }

        $leave = $pdo->query("SELECT id, leave_type FROM leave_requests WHERE status = 'pending'")->fetchAll();
        foreach ($leave as $request) {
            notify_roles($pdo, 'leave:new:' . $request['id'], 'leave', 'New leave request', $request['leave_type'] . ' leave needs review.', 'leave_requests.php', ['admin', 'hr']);
        }

        $low = $pdo->query("SELECT id, name, quantity FROM ingredients WHERE archived_at IS NULL AND quantity <= reorder_at")->fetchAll();
        foreach ($low as $ingredient) {
            $level = (float)$ingredient['quantity'] <= 0 ? 'out of stock' : 'running low';
            notify_roles($pdo, 'inventory:low:' . $ingredient['id'], 'inventory', 'Inventory alert', $ingredient['name'] . ' is ' . $level . '.', 'inventory.php', ['admin', 'manager']);
        }
    } catch (Throwable $e) {
        error_log('notification sync error: ' . $e->getMessage());
    }
}
