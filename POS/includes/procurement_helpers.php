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

/** Human label for a PO/GRN/invoice/payment status enum. */
function status_badge(string $status): string {
    $map = [
        'pending'      => 'Pending',
        'approved'     => 'Approved',
        'rejected'     => 'Rejected',
        'sourcing'     => 'Sourcing',
        'awarded'      => 'Awarded',
        'closed'       => 'Closed',
        'draft'        => 'Draft',
        'sent'         => 'Sent',
        'acknowledged' => 'Acknowledged',
        'delivered'    => 'Delivered',
        'cancelled'    => 'Cancelled',
        'partial'      => 'Partial',
        'complete'     => 'Complete',
        'discrepancy'  => 'Discrepancy',
        'matched'      => 'Matched',
        'disputed'     => 'Disputed',
        'paid'         => 'Paid',
        'scheduled'    => 'Scheduled',
        'completed'    => 'Completed',
        'failed'       => 'Failed',
        'submitted'    => 'Submitted',
        'shortlisted'  => 'Shortlisted',
        'selected'     => 'Selected',
        'open'         => 'Open',
    ];
    return $map[$status] ?? ucfirst($status);
}

// ═══════════════════════════════════════════════
//  PROCUREMENT LETTERS & E-SIGNATURE WORKFLOW
// ═══════════════════════════════════════════════

/**
 * Ensures the procurement_letters table and requisition foreign reference exist.
 */
function ensure_procurement_letters_table(): void {
    static $ensured = false;
    if ($ensured) return;
    $pdo = get_db();
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS procurement_letters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                letter_ref VARCHAR(50) NOT NULL UNIQUE,
                requisition_id INT NOT NULL,
                supplier_id INT NOT NULL,
                approver_id INT NOT NULL,
                approver_name VARCHAR(150) NOT NULL,
                approver_title VARCHAR(100) NOT NULL,
                approver_signature LONGTEXT NOT NULL,
                subject VARCHAR(255) NOT NULL,
                delivery_terms VARCHAR(255) NULL,
                payment_terms VARCHAR(255) NULL,
                special_instructions TEXT NULL,
                status ENUM('sent', 'acknowledged') DEFAULT 'sent',
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                acknowledged_at DATETIME NULL,
                acknowledgement_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (requisition_id),
                INDEX (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $cols = $pdo->query("SHOW COLUMNS FROM purchase_requisitions LIKE 'supplier_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE purchase_requisitions ADD COLUMN supplier_id INT NULL AFTER reviewed_by");
        }
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensure_procurement_letters_table failed: ' . $e->getMessage());
    }
}

// Ensure schema is active on include
ensure_procurement_letters_table();

/**
 * Creates and dispatches a formal procurement letter directly to the awarded supplier.
 */
function create_procurement_letter(
    int $requisition_id,
    int $supplier_id,
    int $approver_id,
    string $approver_name,
    string $approver_title,
    string $approver_signature,
    string $delivery_terms,
    string $payment_terms,
    ?string $special_instructions = null
): array {
    ensure_procurement_letters_table();
    $pdo = get_db();

    $req_stmt = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id = :id');
    $req_stmt->execute([':id' => $requisition_id]);
    $req = $req_stmt->fetch();
    if (!$req) {
        return ['ok' => false, 'error' => 'Requisition not found.'];
    }

    $sup_stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
    $sup_stmt->execute([':id' => $supplier_id]);
    $supplier = $sup_stmt->fetch();
    if (!$supplier) {
        return ['ok' => false, 'error' => 'Supplier not found.'];
    }

    // Generate clean reference: KM-LTR-YYYY-XXXX
    $year = date('Y');
    $base_ref = sprintf('KM-LTR-%s-%04d', $year, $requisition_id);
    $letter_ref = $base_ref;
    $suffix = 1;
    while (true) {
        $chk = $pdo->prepare('SELECT id FROM procurement_letters WHERE letter_ref = :ref');
        $chk->execute([':ref' => $letter_ref]);
        if (!$chk->fetch()) break;
        $suffix++;
        $letter_ref = $base_ref . '-' . $suffix;
    }

    $subject = sprintf('Procurement Award & Purchase Authorization — %s (#%04d)', $req['title'], $requisition_id);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO procurement_letters
                (letter_ref, requisition_id, supplier_id, approver_id, approver_name, approver_title,
                 approver_signature, subject, delivery_terms, payment_terms, special_instructions, status, sent_at)
            VALUES
                (:ref, :req_id, :sup_id, :app_id, :app_name, :app_title,
                 (:sig), :subj, :deliv, :pay, :inst, 'sent', NOW())
        ");
        $stmt->execute([
            ':ref'       => $letter_ref,
            ':req_id'    => $requisition_id,
            ':sup_id'    => $supplier_id,
            ':app_id'    => $approver_id,
            ':app_name'  => $approver_name,
            ':app_title' => $approver_title,
            ':sig'       => $approver_signature,
            ':subj'      => $subject,
            ':deliv'     => $delivery_terms,
            ':pay'       => $payment_terms,
            ':inst'      => $special_instructions,
        ]);
        $letter_id = (int)$pdo->lastInsertId();

        // Update purchase_requisitions supplier_id
        $pdo->prepare('UPDATE purchase_requisitions SET supplier_id = :sid WHERE id = :id')
            ->execute([':sid' => $supplier_id, ':id' => $requisition_id]);

        $pdo->commit();

        // In-system notification to the supplier's portal account (if linked)
        if (!empty($supplier['user_id'])) {
            notify_user(
                (int)$supplier['user_id'],
                'procurement_letter',
                'New Procurement Award Letter Issued',
                "Kofee Manila has issued official Procurement Letter {$letter_ref} for {$req['title']} (" . php_currency((float)$req['estimated_total']) . '). Please review and acknowledge.',
                'procurement_letter.php?id=' . $letter_id
            );
        }

        audit_log('requisition', $requisition_id, 'letter_issued', "Official procurement letter {$letter_ref} dispatched to supplier {$supplier['name']}");

        return ['ok' => true, 'letter_id' => $letter_id, 'letter_ref' => $letter_ref];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('create_procurement_letter error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Fetches full details of a procurement letter, including supplier, approver, and requisition line items.
 */
function get_procurement_letter(int $id, bool $by_requisition = false): ?array {
    ensure_procurement_letters_table();
    $pdo = get_db();

    $where = $by_requisition ? 'pl.requisition_id = :id' : 'pl.id = :id';
    $stmt = $pdo->prepare("
        SELECT pl.*,
               pr.title AS req_title, pr.department AS req_department, pr.estimated_total AS req_total,
               pr.notes AS req_notes, pr.created_at AS req_created_at, pr.reviewed_at AS req_reviewed_at,
               pr.requested_by,
               s.name AS supplier_name, s.contact_person AS supplier_contact,
               s.email AS supplier_email, s.phone AS supplier_phone, s.address AS supplier_address,
               s.user_id AS supplier_user_id,
               ru.firstname AS requester_fname, ru.lastname AS requester_lname, ru.username AS requester_uname
        FROM procurement_letters pl
        JOIN purchase_requisitions pr ON pr.id = pl.requisition_id
        JOIN suppliers s ON s.id = pl.supplier_id
        LEFT JOIN users ru ON ru.id = pr.requested_by
        WHERE $where
        ORDER BY pl.id DESC
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $letter = $stmt->fetch();
    if (!$letter) return null;

    // Fetch line items
    $it_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :rid ORDER BY id ASC');
    $it_stmt->execute([':rid' => $letter['requisition_id']]);
    $letter['items'] = $it_stmt->fetchAll();

    return $letter;
}

/**
 * Acknowledges receipt of a procurement letter by the supplier.
 */
function acknowledge_procurement_letter(int $letter_id, int $supplier_id, ?string $notes = null): array {
    ensure_procurement_letters_table();
    $pdo = get_db();

    $stmt = $pdo->prepare("
        SELECT pl.*, s.name AS supplier_name, pr.title AS req_title
        FROM procurement_letters pl
        JOIN suppliers s ON s.id = pl.supplier_id
        JOIN purchase_requisitions pr ON pr.id = pl.requisition_id
        WHERE pl.id = :id AND pl.supplier_id = :sid
    ");
    $stmt->execute([':id' => $letter_id, ':sid' => $supplier_id]);
    $letter = $stmt->fetch();
    if (!$letter) {
        return ['ok' => false, 'error' => 'Letter not found or not assigned to your supplier profile.'];
    }

    if ($letter['status'] === 'acknowledged') {
        return ['ok' => true, 'already' => true, 'message' => 'This letter has already been acknowledged.'];
    }

    try {
        $upd = $pdo->prepare("
            UPDATE procurement_letters
            SET status = 'acknowledged', acknowledged_at = NOW(), acknowledgement_notes = :notes
            WHERE id = :id
        ");
        $upd->execute([':notes' => $notes, ':id' => $letter_id]);

        // Notify procurement officers
        notify_role_by_permission(
            'procurement.requisition.review',
            'letter_acknowledged',
            'Procurement Letter Acknowledged',
            "{$letter['supplier_name']} has formally acknowledged receipt of {$letter['letter_ref']}" . ($notes ? ": \"{$notes}\"" : '.'),
            'procurement_letter.php?id=' . $letter_id
        );

        audit_log('procurement_letter', $letter_id, 'acknowledged', "Supplier {$letter['supplier_name']} acknowledged letter {$letter['letter_ref']}");

        return ['ok' => true, 'message' => 'Procurement letter successfully acknowledged!'];
    } catch (Throwable $e) {
        error_log('acknowledge_procurement_letter error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}