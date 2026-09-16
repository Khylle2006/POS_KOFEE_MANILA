<?php
// ─────────────────────────────────────────────────────────────
//  includes/notify.php
//  Role-targeted notification dispatcher with audit metadata.
//
//  Wraps (does not replace) the older notify_user() /
//  notify_role_by_permission() in procurement_helpers.php so the
//  existing procurement calls keep working untouched.
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/procurement_helpers.php';

/**
 * Every user who can see a given module, resolved through RBAC.
 * Admins are always included. Returns a list of user ids.
 */
function users_with_permission(string $perm_key): array {
    try {
        $stmt = get_db()->prepare(
            "SELECT DISTINCT u.id
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN role_permissions rp
                      ON rp.role = ur.role AND rp.perm_key = :p
              WHERE u.status = 'active'
                AND ( u.role = 'admin'
                   OR rp.perm_key IS NOT NULL
                   OR ( ur.user_id IS NULL
                        AND u.role IN (SELECT role FROM role_permissions WHERE perm_key = :p2) ) )"
        );
        $stmt->execute([':p' => $perm_key, ':p2' => $perm_key]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('users_with_permission failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Insert one notification row with full audit metadata.
 * `link_url` is the spec's target_url — reusing the existing column.
 */
function notify_user_event(
    int $recipient_id,
    string $action_type,
    string $title,
    ?string $message = null,
    ?string $target_url = null,
    ?string $entity_type = null,
    ?int $entity_id = null,
    ?int $actor_id = null
): void {
    try {
        get_db()->prepare(
            'INSERT INTO notifications
                (recipient_user_id, actor_id, type, action_type, entity_type, entity_id,
                 title, message, link_url)
             VALUES (:r, :a, :t, :at, :et, :ei, :ti, :m, :l)'
        )->execute([
            ':r'  => $recipient_id,
            ':a'  => $actor_id,
            ':t'  => strtolower($action_type),   // legacy `type` column
            ':at' => strtoupper($action_type),
            ':et' => $entity_type,
            ':ei' => $entity_id,
            ':ti' => $title,
            ':m'  => $message,
            ':l'  => $target_url,
        ]);
    } catch (Throwable $e) {
        error_log('notify_user_event failed: ' . $e->getMessage());
    }
}

/**
 * Fan an event out to everyone who holds `$perm_key`.
 * The actor is skipped — no one needs a ping about their own action.
 * Pass actor_id: null for system-generated events.
 */
function notify_event(
    string $action_type,
    string $perm_key,
    string $title,
    ?string $message = null,
    ?string $target_url = null,
    ?string $entity_type = null,
    ?int $entity_id = null,
    ?int $actor_id = 0          // 0 = "use the session user"
): int {
    if ($actor_id === 0) {
        $actor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    $sent = 0;
    foreach (users_with_permission($perm_key) as $uid) {
        if ($actor_id !== null && $uid === $actor_id) continue;
        notify_user_event($uid, $action_type, $title, $message, $target_url,
                          $entity_type, $entity_id, $actor_id);
        $sent++;
    }
    return $sent;
}

/** "5 minutes ago" style formatting for the bell popover. */
function relative_time(string $timestamp): string {
    $ts = strtotime($timestamp);
    if (!$ts) return '';

    $diff = time() - $ts;
    if ($diff < 0)    return 'just now';
    if ($diff < 60)   return 'just now';
    if ($diff < 3600) { $m = (int)floor($diff / 60);   return $m . ' minute'  . ($m > 1 ? 's' : '') . ' ago'; }
    if ($diff < 86400){ $h = (int)floor($diff / 3600); return $h . ' hour'    . ($h > 1 ? 's' : '') . ' ago'; }
    if ($diff < 604800){$d = (int)floor($diff / 86400);return $d . ' day'     . ($d > 1 ? 's' : '') . ' ago'; }
    if ($diff < 2592000){$w = (int)floor($diff/604800);return $w . ' week'    . ($w > 1 ? 's' : '') . ' ago'; }
    return date('M j, Y', $ts);
}

/** Display label for the actor of a notification — "System" when null. */
function notification_actor_label(?int $actor_id): string {
    if ($actor_id === null) return 'System';
    return user_display_name($actor_id);
}

/** One notification with its actor joined in, scoped to the recipient. */
function notification_detail(int $notification_id, int $recipient_id): ?array {
    $stmt = get_db()->prepare(
        'SELECT n.*, u.firstname, u.lastname, u.username, u.role AS actor_role
           FROM notifications n
           LEFT JOIN users u ON u.id = n.actor_id
          WHERE n.id = :i AND n.recipient_user_id = :r'
    );
    $stmt->execute([':i' => $notification_id, ':r' => $recipient_id]);
    return $stmt->fetch() ?: null;
}

/** Recent notifications with actor names, for the popover. */
function recent_notifications_detailed(int $user_id, int $limit = 15): array {
    $stmt = get_db()->prepare(
        'SELECT n.*, u.firstname, u.lastname, u.username
           FROM notifications n
           LEFT JOIN users u ON u.id = n.actor_id
          WHERE n.recipient_user_id = :u
          ORDER BY n.created_at DESC
          LIMIT ' . (int)$limit
    );
    $stmt->execute([':u' => $user_id]);
    return $stmt->fetchAll();
}