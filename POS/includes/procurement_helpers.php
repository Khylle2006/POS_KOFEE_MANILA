<?php
require_once __DIR__ . '/private_storage.php';
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
        error_log('audit_log failed: ' . 'Service temporarily unavailable.');
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
        error_log('notify_user failed: ' . 'Service temporarily unavailable.');
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
        error_log('notify_role_by_permission failed: ' . 'Service temporarily unavailable.');
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
        'needs_correction' => 'Needs Correction',
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
    require_runtime_schema(get_db());
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
        error_log('create_procurement_letter error: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
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
        error_log('acknowledge_procurement_letter error: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

// ═══════════════════════════════════════════════
//  PROCUREMENT SETTINGS & WORKFLOW SCHEMA
// ═══════════════════════════════════════════════

/**
 * Fetch a configurable procurement setting from procurement_settings table.
 */
function get_procurement_setting(string $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT setting_value FROM procurement_settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $cache[$key] = $val;
            return $val;
        }
    } catch (Throwable $e) {
        error_log('get_procurement_setting error: ' . 'Service temporarily unavailable.');
    }
    return $default;
}

/**
 * Save or update a configurable procurement setting.
 */
function set_procurement_setting(string $key, $value, ?string $description = null): bool {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare('
            INSERT INTO procurement_settings (setting_key, setting_value, description)
            VALUES (:k, :v, :d)
            ON DUPLICATE KEY UPDATE setting_value = :v2, description = COALESCE(:d2, description), updated_at = NOW()
        ');
        return $stmt->execute([
            ':k' => $key,
            ':v' => (string)$value,
            ':d' => $description,
            ':v2' => (string)$value,
            ':d2' => $description
        ]);
    } catch (Throwable $e) {
        error_log('set_procurement_setting error: ' . 'Service temporarily unavailable.');
        return false;
    }
}

/**
 * Ensures all procurement tables, indexes, and columns exist across the database.
 */
function ensure_procurement_tables(?PDO $pdo = null): void {
    $db = $pdo ?? get_db();
    require_runtime_schema($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `supplier_application_attachments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `application_id` INT NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(255) NOT NULL,
            `file_size` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_sup_att_app` (`application_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}

// ═══════════════════════════════════════════════
//  RFQ INVITATION LETTER & BIDDING HELPERS
// ═══════════════════════════════════════════════

/** @param list<array{original_name:string,stored_path:string,file_size:int}> $files */
function save_supplier_application_attachments(PDO $pdo, int $applicationId, array $files): void {
    if (!$pdo->inTransaction()) throw new LogicException('Supplier attachments require an application transaction.');
    if (!$files) return;
    // Schema creation would implicitly commit the application before its documents are saved.
    $stmt = $pdo->prepare('INSERT INTO supplier_application_attachments (application_id, filename, file_path, file_size, created_at) VALUES (?, ?, ?, ?, NOW())');
    foreach ($files as $file) {
        $stmt->execute([$applicationId, $file['original_name'], $file['stored_path'], $file['file_size']]);
    }
}

/**
 * Creates and dispatches an official RFQ with Buyer e-signature and formal invitation letter.
 */
function create_rfq_invitation(
    int $requisition_id,
    int $buyer_id,
    string $title,
    ?string $due_date,
    array $supplier_ids,
    string $terms = '',
    string $invitation_letter = '',
    ?string $buyer_signature = null
): array {
    ensure_procurement_tables();
    $pdo = get_db();

    if ($requisition_id <= 0 || empty($supplier_ids)) {
        return ['ok' => false, 'error' => 'Requisition ID and at least one supplier are required.'];
    }

    $req_stmt = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id = :id');
    $req_stmt->execute([':id' => $requisition_id]);
    $req = $req_stmt->fetch();
    if (!$req) {
        return ['ok' => false, 'error' => 'Requisition not found.'];
    }
    if ($req['status'] !== 'approved') {
        return ['ok' => false, 'error' => 'Only approved requisitions can proceed to RFQ and bidding.'];
    }

    // Generate formal RFQ Reference: KM-RFQ-YYYY-XXXX
    $year = date('Y');
    $base_ref = sprintf('KM-RFQ-%s-%04d', $year, $requisition_id);
    $rfq_ref = $base_ref;
    $suffix = 1;
    while (true) {
        $chk = $pdo->prepare('SELECT id FROM rfqs WHERE rfq_ref = :ref');
        $chk->execute([':ref' => $rfq_ref]);
        if (!$chk->fetch()) break;
        $suffix++;
        $rfq_ref = $base_ref . '-' . $suffix;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO rfqs 
                (rfq_ref, requisition_id, created_by, title, status, due_date,
                 invitation_letter, buyer_signature, buyer_signed_by, buyer_signed_at, terms_and_conditions)
            VALUES 
                (:ref, :req_id, :uid, :title, 'open', :due,
                 :letter, :sig, :sig_uid, NOW(), :terms)
        ");
        $stmt->execute([
            ':ref'     => $rfq_ref,
            ':req_id'  => $requisition_id,
            ':uid'     => $buyer_id,
            ':title'   => $title ?: ('RFQ for ' . $req['title']),
            ':due'     => $due_date ?: null,
            ':letter'  => $invitation_letter,
            ':sig'     => $buyer_signature,
            ':sig_uid' => $buyer_signature ? $buyer_id : null,
            ':terms'   => $terms,
        ]);
        $rfq_id = (int)$pdo->lastInsertId();

        // Invite suppliers
        $ins = $pdo->prepare('INSERT IGNORE INTO rfq_invites (rfq_id, supplier_id) VALUES (:r, :s)');
        foreach ($supplier_ids as $sid) {
            $ins->execute([':r' => $rfq_id, ':s' => (int)$sid]);
        }

        // Update requisition status to 'sourcing'
        $pdo->prepare("UPDATE purchase_requisitions SET status = 'sourcing' WHERE id = :id")
            ->execute([':id' => $requisition_id]);

        $pdo->commit();

        // Notify invited suppliers
        $sup_user_stmt = $pdo->prepare('SELECT user_id, name FROM suppliers WHERE id = :id');
        foreach ($supplier_ids as $sid) {
            $sup_user_stmt->execute([':id' => (int)$sid]);
            $sup = $sup_user_stmt->fetch();
            if ($sup && !empty($sup['user_id'])) {
                notify_user(
                    (int)$sup['user_id'],
                    'rfq_invite',
                    'New RFQ Invitation Letter — ' . $rfq_ref,
                    "Kofee Manila invites {$sup['name']} to submit a quotation for \"{$req['title']}\"" . ($due_date ? " due by {$due_date}." : '.'),
                    'supplier_portal.php?tab=rfqs'
                );
            }
        }

        audit_log('rfq', $rfq_id, 'rfq_created', "RFQ invitation letter {$rfq_ref} created with buyer e-signature and dispatched to " . count($supplier_ids) . " supplier(s)");

        return ['ok' => true, 'rfq_id' => $rfq_id, 'rfq_ref' => $rfq_ref];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('create_rfq_invitation error: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Checks whether an RFQ is currently open and valid for supplier quoting.
 * Enforces status == 'open' and due_date not passed.
 *
 * @param array $rfq
 * @return array{can_bid: bool, is_expired: bool, is_open: bool, reason: string}
 */
function can_supplier_bid(array $rfq): array {
    $is_open = ($rfq['status'] === 'open');
    $is_expired = false;
    $reason = '';
    $days_left = 0;

    if (!$is_open) {
        $reason = "This RFQ is {$rfq['status']} and is no longer accepting quotations.";
        return ['can_bid' => false, 'is_expired' => false, 'is_open' => false, 'reason' => $reason, 'days_left' => 0];
    }

    if (!empty($rfq['due_date'])) {
        $today = date('Y-m-d');
        if (strtotime($today) > strtotime($rfq['due_date'])) {
            $is_expired = true;
            $formatted_due = date('M d, Y', strtotime($rfq['due_date']));
            $reason = "Quotation submission deadline has passed (expired on {$formatted_due}). Submissions are closed.";
            return ['can_bid' => false, 'is_expired' => true, 'is_open' => true, 'reason' => $reason, 'days_left' => 0];
        }
        $days_left = max(0, (int)ceil((strtotime($rfq['due_date']) - strtotime($today)) / 86400));
    }

    return ['can_bid' => true, 'is_expired' => false, 'is_open' => true, 'reason' => 'RFQ is open for quotations.', 'days_left' => $days_left];
}

/**
 * Withdraws a supplier's quote before deadline.
 */
function withdraw_supplier_bid(int $bid_id, ?int $supplier_id = null): array {
    $pdo = get_db();

    $stmt = $pdo->prepare('
        SELECT b.*, rfq.status AS rfq_status, rfq.due_date, rfq.rfq_ref, s.name AS supplier_name
        FROM bids b
        JOIN rfqs rfq ON rfq.id = b.rfq_id
        JOIN suppliers s ON s.id = b.supplier_id
        WHERE b.id = :id
    ');
    $stmt->execute([':id' => $bid_id]);
    $bid = $stmt->fetch();

    if (!$bid) {
        return ['ok' => false, 'error' => 'Quotation not found.'];
    }

    // Verify ownership if called by supplier
    if ($supplier_id !== null && (int)$bid['supplier_id'] !== $supplier_id) {
        return ['ok' => false, 'error' => 'You are not authorized to withdraw this quotation.'];
    }

    if ($bid['status'] === 'withdrawn') {
        return ['ok' => true, 'message' => 'Quotation is already withdrawn.'];
    }

    if ($bid['status'] === 'selected') {
        return ['ok' => false, 'error' => 'Selected quotes cannot be withdrawn without procurement authorization.'];
    }

    // Due-date check: cannot withdraw once RFQ is closed or deadline expired
    $check = can_supplier_bid($bid);
    if ($check['is_expired']) {
        return ['ok' => false, 'error' => 'Cannot withdraw quote: The submission deadline has already passed.'];
    }

    try {
        $pdo->prepare("UPDATE bids SET status = 'withdrawn' WHERE id = :id")->execute([':id' => $bid_id]);

        audit_log('bid', $bid_id, 'withdrawn', "Quotation withdrawn by {$bid['supplier_name']} for RFQ {$bid['rfq_ref']}");

        return ['ok' => true, 'message' => 'Quotation has been successfully withdrawn.'];
    } catch (Throwable $e) {
        error_log('withdraw_supplier_bid error: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Fetches calculated performance scorecard metrics for a supplier.
 */
function get_supplier_scorecard_summary(int $supplier_id): array {
    $pdo = get_db();

    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS total_reviews,
            AVG(quality_score) AS avg_quality,
            AVG(timeliness_score) AS avg_timeliness,
            AVG(price_score) AS avg_price,
            AVG(communication_score) AS avg_communication
        FROM supplier_performance_ratings
        WHERE supplier_id = :sid
    ');
    $stmt->execute([':sid' => $supplier_id]);
    $r = $stmt->fetch();

    $total = (int)($r['total_reviews'] ?? 0);
    if ($total === 0) {
        return [
            'has_history'       => false,
            'total_reviews'     => 0,
            'overall_score'     => null,
            'otif_pct'          => null,
            'quality_pct'       => null,
            'responsiveness_pct'=> null,
            'price_score'       => null,
            'star_rating'       => 'No ratings yet',
        ];
    }

    $q = (float)$r['avg_quality'];
    $t = (float)$r['avg_timeliness'];
    $p = (float)$r['avg_price'];
    $c = (float)$r['avg_communication'];

    $overall = round(($q + $t + $p + $c) / 4, 1);
    $otif_pct = round(($t / 5) * 100, 1);
    $quality_pct = round(($q / 5) * 100, 1);
    $responsiveness_pct = round(($c / 5) * 100, 1);

    return [
        'has_history'       => true,
        'total_reviews'     => $total,
        'overall_score'     => $overall,
        'otif_pct'          => $otif_pct,
        'quality_pct'       => $quality_pct,
        'responsiveness_pct'=> $responsiveness_pct,
        'price_score'       => round($p, 1),
        'star_rating'       => sprintf('%.1f / 5.0 (%d order%s)', $overall, $total, $total > 1 ? 's' : ''),
    ];
}

/**
 * Run the 3-way match for one invoice against PO and completed GRNs.
 * Pure computation — does not write to the DB.
 */
if (!function_exists('run_three_way_match')) {
    function run_three_way_match(PDO $pdo, array $invoice, array $po, float $price_tol = 3.0, float $qty_tol = 0.0): array {
        $exceptions = [];

        // ── Line-level: invoiced qty vs received (good-condition) qty ──
        $li_stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
        $li_stmt->execute([':id' => $invoice['id']]);
        $lines = $li_stmt->fetchAll();

        $recv_stmt = $pdo->prepare(
            "SELECT gri.requisition_item_id, SUM(gri.received_qty) AS qty
             FROM goods_receipt_items gri JOIN goods_receipts g ON g.id = gri.grn_id
             WHERE g.po_id = :po AND gri.item_condition = 'good'
             GROUP BY gri.requisition_item_id"
        );
        $recv_stmt->execute([':po' => $po['id']]);
        $received_map = $recv_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $has_any_grn = $pdo->prepare("SELECT COUNT(*) FROM goods_receipts WHERE po_id = :po AND status != 'discrepancy'");
        $has_any_grn->execute([':po' => $po['id']]);
        $grn_exists = (int)$has_any_grn->fetchColumn() > 0;

        $line_results = [];
        foreach ($lines as $l) {
            $received = (float)($received_map[$l['requisition_item_id']] ?? 0);
            $over     = ($l['qty'] - $received) > $qty_tol;
            $line_results[] = [
                'item_name'   => $l['item_name'],
                'invoiced_qty'=> (float)$l['qty'],
                'received_qty'=> $received,
                'over_billed' => $over,
            ];
            if ($over) {
                $exceptions[] = "\"{$l['item_name']}\" invoiced for {$l['qty']} but only {$received} received (tolerance is {$qty_tol} units).";
            }
        }

        if (!$grn_exists) {
            $exceptions[] = 'No completed Goods Receipt found for this Purchase Order.';
        }

        // ── Header-level: invoice total vs PO (awarded) total ──
        $po_total  = (float)$po['total_amount'];
        $inv_total = (float)$invoice['total_amount'];
        $variance  = $po_total > 0 ? abs($inv_total - $po_total) / $po_total * 100 : ($inv_total > 0 ? 100 : 0);
        $price_ok  = $variance <= $price_tol;

        if (!$price_ok) {
            $exceptions[] = sprintf(
                'Invoice total %s differs from PO total %s by %.1f%% (tolerance is %.1f%%).',
                php_currency($inv_total), php_currency($po_total), $variance, $price_tol
            );
        }

        return [
            'passed'       => empty($exceptions),
            'exceptions'   => $exceptions,
            'line_results' => $line_results,
            'po_total'     => $po_total,
            'inv_total'    => $inv_total,
            'variance_pct' => round($variance, 2),
            'price_tol'    => $price_tol,
            'qty_tol'      => $qty_tol,
        ];
    }
}

// ═══════════════════════════════════════════════
//  PAYMONGO PROCUREMENT PAYMENTS & DISBURSEMENTS
// ═══════════════════════════════════════════════

/**
 * Retrieve PayMongo API credentials and configuration for Procurement.
 */
function get_procurement_paymongo_config(PDO $pdo): array {
    return ['mode' => payment_mode() === 'demo' ? 'sandbox' : payment_mode(),
        'secret_key' => app_setting('PAYMONGO_DISBURSEMENT_SECRET_KEY', app_setting('PAYMONGO_SECRET_KEY')),
        'public_key' => app_setting('PAYMONGO_PUBLIC_KEY'), 'webhook_secret' => app_setting('PAYMONGO_WEBHOOK_SECRET'),
        'enabled' => payment_mode() !== 'disabled'];
}

/**
 * Generate official electronic PayMongo Proof of Payment voucher.
 */
function generate_paymongo_procurement_voucher(
    int $invoice_id,
    string $inv_number,
    string $po_number,
    string $supplier_name,
    float $amount,
    string $channel_label,
    string $recipient_account_info,
    string $reference_no,
    string $authorized_by_name,
    ?string $timestamp = null
): array {
    $time_str = $timestamp ?: date('Y-m-d H:i:s');
    $display_date = date('F d, Y \a\t g:i A', strtotime($time_str));
    $amount_fmt = '₱' . number_format($amount, 2);

    $upload_dir = private_upload_directory('receipts');
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $safe_inv = preg_replace('/[^a-zA-Z0-9_-]/', '_', $inv_number);
    $rand_token = bin2hex(random_bytes(4));
    $filename = "paymongo_voucher_{$invoice_id}_{$safe_inv}_{$rand_token}.html";
    $filepath = $upload_dir . '/' . $filename;

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>PayMongo Electronic Payment Voucher — ' . htmlspecialchars($inv_number) . '</title>
  <style>
    :root {
      --espresso: #2B1810;
      --caramel: #8B4513;
      --cream: #FAF7F2;
      --green: #166534;
      --green-bg: #f0fdf4;
      --green-border: #bbf7d0;
      --border: #e8ded2;
      --text-main: #1f2937;
      --text-muted: #6b7280;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: #f4efe8; color: var(--text-main); padding: 40px 15px; }
    .voucher-card {
      max-width: 680px;
      margin: 0 auto;
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(43, 24, 16, 0.08);
      border: 1px solid var(--border);
      overflow: hidden;
    }
    .header-bar {
      background: linear-gradient(135deg, #2B1810 0%, #4a2818 100%);
      color: #ffffff;
      padding: 24px 30px;
      position: relative;
    }
    .brand-title { font-size: 20px; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; }
    .brand-sub { font-size: 12px; color: #e5d5c5; margin-top: 3px; }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #22c55e;
      color: #ffffff;
      font-size: 11.5px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 6px 14px;
      border-radius: 999px;
      margin-top: 14px;
    }
    .body-content { padding: 30px; }
    .amount-box {
      background: var(--cream);
      border: 1.5px dashed var(--caramel);
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      margin-bottom: 25px;
    }
    .amount-label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
    .amount-val { font-size: 32px; font-weight: 900; color: var(--caramel); margin-top: 4px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; font-size: 13px; }
    .info-item { background: #fafafa; border: 1px solid #f0f0f0; border-radius: 8px; padding: 12px 14px; }
    .info-item .lbl { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; }
    .info-item .val { font-size: 13.5px; font-weight: 600; color: var(--text-main); word-break: break-all; }
    .beneficiary-box {
      background: #fdfdfd;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 16px;
      margin-bottom: 24px;
    }
    .sec-title { font-size: 12px; font-weight: 800; color: var(--caramel); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
    .footer-bar {
      border-top: 1px solid #f0f0f0;
      padding: 20px 30px;
      background: #fafafa;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 11.5px;
      color: var(--text-muted);
    }
    .btn-print {
      background: var(--caramel);
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
    }
    @media print {
      body { background: #fff; padding: 0; }
      .voucher-card { box-shadow: none; border: none; max-width: 100%; }
      .btn-print { display: none; }
    }
  </style>
</head>
<body>
  <div class="voucher-card">
    <div class="header-bar">
      <div class="brand-title">Kofee Manila</div>
      <div class="brand-sub">Enterprise Procurement &amp; Supplier Disbursements</div>
      <div class="status-badge">&#10003; Verified Electronic Payment Voucher</div>
    </div>
    <div class="body-content">
      <div class="amount-box">
        <div class="amount-label">Total Amount Disbursed</div>
        <div class="amount-val">' . htmlspecialchars($amount_fmt) . '</div>
        <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">Payment Settled in Philippine Peso (PHP)</div>
      </div>

      <div class="info-grid">
        <div class="info-item">
          <div class="lbl">Invoice Reference</div>
          <div class="val">' . htmlspecialchars($inv_number) . '</div>
        </div>
        <div class="info-item">
          <div class="lbl">Purchase Order</div>
          <div class="val">' . htmlspecialchars($po_number) . '</div>
        </div>
        <div class="info-item">
          <div class="lbl">PayMongo Reference / Transfer ID</div>
          <div class="val" style="color:var(--caramel);font-family:monospace;font-size:13px">' . htmlspecialchars($reference_no) . '</div>
        </div>
        <div class="info-item">
          <div class="lbl">Payment Channel</div>
          <div class="val">' . htmlspecialchars($channel_label) . '</div>
        </div>
        <div class="info-item">
          <div class="lbl">Disbursement Timestamp</div>
          <div class="val">' . htmlspecialchars($display_date) . '</div>
        </div>
        <div class="info-item">
          <div class="lbl">Authorized By</div>
          <div class="val">' . htmlspecialchars($authorized_by_name) . '</div>
        </div>
      </div>

      <div class="beneficiary-box">
        <div class="sec-title">Supplier / Beneficiary Details</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
          <div>
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700">Supplier Name</div>
            <div style="font-weight:700;color:var(--text-main);margin-top:2px">' . htmlspecialchars($supplier_name) . '</div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:700">Receiving Account</div>
            <div style="font-weight:700;color:var(--caramel);margin-top:2px">' . htmlspecialchars($recipient_account_info) . '</div>
          </div>
        </div>
      </div>

      <div style="background:var(--green-bg);border:1px solid var(--green-border);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--green);display:flex;align-items:center;gap:10px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span>This electronic voucher serves as official system-generated proof of payment through PayMongo. Beneficiary account credited successfully.</span>
      </div>
    </div>
    <div class="footer-bar">
      <div>Kofee Manila POS &bull; PayMongo Automated Disbursement Engine</div>
      <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
    </div>
  </div>
</body>
</html>';

    private_write_file($filepath, $html, true);

    return [
        'ok'   => true,
        'path' => 'uploads/receipts/' . $filename,
        'name' => 'PayMongo_Voucher_' . $safe_inv . '.html',
        'size' => filesize($filepath),
        'type' => 'text/html',
    ];
}

/**
 * Execute automated PayMongo disbursement to supplier for an approved invoice.
 */
function process_supplier_paymongo_disbursement(PDO $pdo, int $invoice_id, float $amount, array $payout_params, int $user_id, string $notes = ''): array {
    require_once __DIR__ . '/paymongo_disbursement_helpers.php';
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? '';
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) throw new SecurityFault('IDEMPOTENCY_KEY_REQUIRED', 'A payout operation key is required.', 400);
    $key = 'invoice-transfer-' . hash('sha256', $user_id . ':' . $key);
    $type = $payout_params['payout_type'] ?? '';
    if (!in_array($type, ['bank', 'ewallet'], true)) throw new SecurityFault('PAYOUT_DESTINATION_INVALID', 'Select a valid payout destination.');
    $account = (string)($payout_params['account_number'] ?? '');
    $mobile = (string)($payout_params['ewallet_mobile_number'] ?? '');
    $name = trim((string)($payout_params[$type === 'bank' ? 'account_name' : 'ewallet_account_name'] ?? ''));
    if ($name === '' || ($type === 'bank' && !preg_match('/^[0-9]{8,20}$/D', $account)) || ($type === 'ewallet' && !preg_match('/^09[0-9]{9}$/D', $mobile))) throw new SecurityFault('PAYOUT_DESTINATION_INVALID', 'Complete valid account details.');
    $cents = money_centavos(number_format($amount, 2, '.', ''));
    if ($cents <= 0) throw new SecurityFault('PAYOUT_AMOUNT_INVALID', 'Payment must be positive.');
    $payload = ['amount_centavos' => $cents, 'recipient_name' => $name, 'type' => $type, 'account' => $account, 'mobile' => $mobile, 'bank_code' => $payout_params['bank_code'] ?? ''];
    $previous = claim_transfer($pdo, $key, 'invoice', $invoice_id, $payload);
    if ($previous !== null) return $previous;
    $result = perform_paymongo_transfer_call(get_procurement_paymongo_config($pdo), ['amount' => money_decimal($cents), 'recipient_name' => $name, 'payout_destination' => $type, 'bank_code' => $payload['bank_code'], 'account_number' => $account, 'ewallet_mobile_number' => $mobile, 'idempotency_key' => $key]);
    if (empty($result['ok'])) { record_transfer_result($pdo, $key, $result); return $result; }
    $paid = in_array($result['status'] ?? '', ['paid', 'succeeded'], true);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT po_id, total_amount FROM invoices WHERE id = ? FOR UPDATE'); $stmt->execute([$invoice_id]); $invoice = $stmt->fetch();
        $pdo->prepare('INSERT INTO payments (invoice_id, po_id, amount, payment_date, payment_method, paying_account, reference_no, paymongo_payout_id, paymongo_channel, notes, paid_by, status, supplier_confirmation_status, scheduled_at, completed_at) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')->execute([$invoice_id, $invoice['po_id'], money_decimal($cents), $type === 'bank' ? 'bank_transfer' : 'ewallet', 'PayMongo', $result['transfer_id'], $result['transfer_id'], 'disbursement_' . $type, $notes, $user_id, $paid ? 'completed' : 'scheduled', 'awaiting_confirmation', $paid ? date('Y-m-d H:i:s') : null]);
        $payment_id = (int)$pdo->lastInsertId();
        if ($paid) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'completed'"); $stmt->execute([$invoice_id]);
            $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([money_centavos($stmt->fetchColumn()) >= money_centavos($invoice['total_amount']) ? 'paid' : 'partially_paid', $invoice_id]);
        }
        $response = ['ok' => true, 'payment_id' => $payment_id, 'transfer_id' => $result['transfer_id'], 'amount' => $amount, 'status' => $paid ? 'paid' : 'processing', 'message' => $paid ? 'Payment confirmed.' : 'Transfer submitted; payment confirmation is pending.'];
        record_transfer_result($pdo, $key, $response);
        $pdo->commit();
        return $response;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

/**
 * Create a PayMongo Online Checkout Session for a supplier invoice.
 */
function create_supplier_invoice_paymongo_checkout(PDO $pdo, int $invoice_id, float $amount, int $user_id, string $success_url, string $cancel_url): array {
    require_once __DIR__ . '/invoice_payment_service.php';
    return submit_invoice_checkout($pdo, $invoice_id, $amount, $user_id);
}

/**
 * Verify and complete a PayMongo Checkout Session for procurement.
 */
function verify_and_complete_paymongo_procurement_checkout(PDO $pdo, string $session_id, ?int $user_id = null): array {
    require_once __DIR__ . '/invoice_payment_service.php';
    if (str_starts_with($session_id, 'cs_demo_')) {
        require_demo_payment();
        return ['ok' => false, 'error' => 'Demo invoice payments require explicit test reconciliation.'];
    }
    $result = paymongo_request('checkout_sessions/' . rawurlencode($session_id));
    if (empty($result['success'])) throw new SecurityFault('PROVIDER_UNAVAILABLE', 'Provider verification is unavailable.', 503);
    return reconcile_invoice_checkout($pdo, $result['data']);
}
