<?php
// ─────────────────────────────────────────────────────────────
//  includes/procurement_helpers.php
//  Shared plumbing for the Procurement Module: audit logging,
//  notifications, budget check/reserve, and small formatting
//  helpers used across requisitions → RFQ → PO → GRN → invoice →
//  payment → closure.
//
//  Include this AFTER includes/db.php and includes/auth.php,
//  same as includes/permissions.php.
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';

// ═══════════════════════════════════════════════
//  PERIOD LABEL
// ═══════════════════════════════════════════════

/**
 * Current quarter label, e.g. "2026-Q3". Matches the format already
 * used in php/requisitions.php and the seeded procurement_budgets rows.
 */
function procurement_current_period(): string {
    return date('Y') . '-Q' . (int)ceil((int)date('n') / 3);
}

// ═══════════════════════════════════════════════
//  AUDIT LOG
// ═══════════════════════════════════════════════

/**
 * Record an audit trail entry. Never throws — a logging failure should
 * never block the actual business action that triggered it.
 *
 * @param string   $entity_type  e.g. 'requisition','rfq','bid','po','grn','invoice','payment','supplier'
 * @param int      $entity_id
 * @param string   $action       short verb phrase, e.g. 'created','approved','rejected','received','matched'
 * @param string|null $details   free-text context (kept short)
 * @param int|null $performed_by defaults to the current session user
 */
function audit_log(string $entity_type, int $entity_id, string $action, ?string $details = null, ?int $performed_by = null): void {
    try {
        $pdo = get_db();
        $uid = $performed_by ?? ($_SESSION['user_id'] ?? null);
        $pdo->prepare(
            'INSERT INTO procurement_audit_log (entity_type, entity_id, action, performed_by, details)
             VALUES (:t, :i, :a, :u, :d)'
        )->execute([
            ':t' => $entity_type,
            ':i' => $entity_id,
            ':a' => $action,
            ':u' => $uid,
            ':d' => $details,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

/** Fetch the audit trail for one entity, newest first. */
function audit_log_for(string $entity_type, int $entity_id): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        "SELECT l.*, u.firstname, u.lastname, u.username
         FROM procurement_audit_log l
         LEFT JOIN users u ON u.id = l.performed_by
         WHERE l.entity_type = :t AND l.entity_id = :i
         ORDER BY l.created_at DESC"
    );
    $stmt->execute([':t' => $entity_type, ':i' => $entity_id]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════
//  NOTIFICATIONS
// ═══════════════════════════════════════════════

/** Notify a single user. Never throws. */
function notify_user(int $user_id, string $type, string $title, ?string $message = null, ?string $link_url = null): void {
    try {
        $pdo = get_db();
        $pdo->prepare(
            'INSERT INTO notifications (recipient_user_id, type, title, message, link_url)
             VALUES (:u, :t, :ti, :m, :l)'
        )->execute([
            ':u' => $user_id, ':t' => $type, ':ti' => $title, ':m' => $message, ':l' => $link_url,
        ]);
    } catch (Throwable $e) {
        error_log('notify_user failed: ' . $e->getMessage());
    }
}

/**
 * Notify every user who holds a given permission (e.g. tell everyone
 * who can review requisitions that a new one just landed). Skips the
 * actor themself so people don't get notified about their own action.
 */
function notify_role_by_permission(string $perm_key, string $type, string $title, ?string $message = null, ?string $link_url = null, ?int $exclude_user_id = null): void {
    try {
        $pdo = get_db();

        // Admins implicitly have every permission — include them directly.
        $rows = $pdo->prepare(
            "SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN role_permissions rp ON rp.role = ur.role AND rp.perm_key = :p
             WHERE u.role = 'admin'
                OR rp.perm_key IS NOT NULL
                OR (ur.user_id IS NULL AND u.role IN (SELECT role FROM role_permissions WHERE perm_key = :p2))"
        );
        $rows->execute([':p' => $perm_key, ':p2' => $perm_key]);
        $user_ids = $rows->fetchAll(PDO::FETCH_COLUMN);

        foreach ($user_ids as $uid) {
            if ($exclude_user_id !== null && (int)$uid === (int)$exclude_user_id) continue;
            notify_user((int)$uid, $type, $title, $message, $link_url);
        }
    } catch (Throwable $e) {
        error_log('notify_role_by_permission failed: ' . $e->getMessage());
    }
}

/** Unread notification count for the navbar bell. */
function unread_notification_count(int $user_id): int {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_user_id = :u AND is_read = 0');
    $stmt->execute([':u' => $user_id]);
    return (int)$stmt->fetchColumn();
}

/** Recent notifications for a user (read + unread), newest first. */
function recent_notifications(int $user_id, int $limit = 20): array {
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE recipient_user_id = :u ORDER BY created_at DESC LIMIT ' . (int)$limit);
    $stmt->execute([':u' => $user_id]);
    return $stmt->fetchAll();
}

function mark_notification_read(int $notification_id, int $user_id): void {
    $pdo = get_db();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :i AND recipient_user_id = :u')
        ->execute([':i' => $notification_id, ':u' => $user_id]);
}

function mark_all_notifications_read(int $user_id): void {
    $pdo = get_db();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE recipient_user_id = :u AND is_read = 0')
        ->execute([':u' => $user_id]);
}

// ═══════════════════════════════════════════════
//  BUDGET CHECK / RESERVE
// ═══════════════════════════════════════════════

/** Get (or virtually default) the budget row for a department + period. */
function get_department_budget(string $department, ?string $period_label = null): ?array {
    $period_label = $period_label ?? procurement_current_period();
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT * FROM procurement_budgets WHERE department = :d AND period_label = :p');
    $stmt->execute([':d' => $department, ':p' => $period_label]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Check whether `$amount` fits within the department's remaining budget
 * for the current period. Departments with no budget row are treated
 * as having zero allocation (fails the check) rather than unlimited —
 * this matches the "budget validation" requirement of the lifecycle.
 *
 * @return array{ok: bool, remaining: float, allocated: float, used: float, period: string}
 */
function check_budget_availability(string $department, float $amount, ?string $period_label = null): array {
    $period_label = $period_label ?? procurement_current_period();
    $budget = get_department_budget($department, $period_label);

    $allocated = $budget ? (float)$budget['allocated_amount'] : 0.0;
    $used      = $budget ? (float)$budget['used_amount'] : 0.0;
    $remaining = $allocated - $used;

    return [
        'ok'        => $amount <= $remaining,
        'remaining' => $remaining,
        'allocated' => $allocated,
        'used'      => $used,
        'period'    => $period_label,
    ];
}

/**
 * Reserve (consume) budget for a department. Creates the period row if
 * missing (allocated_amount = 0, so it will correctly show as
 * over-budget until Finance allocates it — visible, not hidden).
 */
function reserve_budget(string $department, float $amount, ?string $period_label = null): void {
    $period_label = $period_label ?? procurement_current_period();
    $pdo = get_db();
    $pdo->prepare(
        'INSERT INTO procurement_budgets (department, period_label, allocated_amount, used_amount)
         VALUES (:d, :p, 0, :amt)
         ON DUPLICATE KEY UPDATE used_amount = used_amount + :amt2'
    )->execute([':d' => $department, ':p' => $period_label, ':amt' => $amount, ':amt2' => $amount]);
}

/** Release previously reserved budget (e.g. a PO gets cancelled). Floors at 0. */
function release_budget(string $department, float $amount, ?string $period_label = null): void {
    $period_label = $period_label ?? procurement_current_period();
    $pdo = get_db();
    $pdo->prepare(
        'UPDATE procurement_budgets
         SET used_amount = GREATEST(0, used_amount - :amt)
         WHERE department = :d AND period_label = :p'
    )->execute([':amt' => $amount, ':d' => $department, ':p' => $period_label]);
}

// ═══════════════════════════════════════════════
//  SMALL FORMATTING / LOOKUP HELPERS
// ═══════════════════════════════════════════════

/** ₱ formatted currency string. */
function php_currency(float $amount): string {
    return '₱' . number_format($amount, 2);
}

/** Display name for a user id, falling back gracefully. */
function user_display_name(?int $user_id): string {
    if (!$user_id) return '—';
    static $cache = [];
    if (isset($cache[$user_id])) return $cache[$user_id];

    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT firstname, lastname, username FROM users WHERE id = :i');
    $stmt->execute([':i' => $user_id]);
    $u = $stmt->fetch();
    if (!$u) return $cache[$user_id] = 'Unknown';

    $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
    return $cache[$user_id] = ($name !== '' ? $name : $u['username']);
}

/** Human label for a PO/GRN/invoice/payment status enum, with an emoji badge. */
function status_badge(string $status): string {
    $map = [
        'pending'      => '⏳ Pending',
        'approved'     => '✅ Approved',
        'rejected'     => '❌ Rejected',
        'sourcing'     => '🔍 Sourcing',
        'awarded'      => '🏆 Awarded',
        'closed'       => '🔒 Closed',
        'draft'        => '📝 Draft',
        'sent'         => '📨 Sent',
        'acknowledged' => '👍 Acknowledged',
        'delivered'    => '📦 Delivered',
        'cancelled'    => '🚫 Cancelled',
        'partial'      => '⚠️ Partial',
        'complete'     => '✅ Complete',
        'discrepancy'  => '⚠️ Discrepancy',
        'matched'      => '✅ Matched',
        'disputed'     => '⚠️ Disputed',
        'paid'         => '💸 Paid',
        'scheduled'    => '🗓️ Scheduled',
        'completed'    => '✅ Completed',
        'failed'       => '❌ Failed',
        'submitted'    => '📤 Submitted',
        'shortlisted'  => '⭐ Shortlisted',
        'selected'     => '🏆 Selected',
        'open'         => '🔓 Open',
    ];
    return $map[$status] ?? ucfirst($status);
}