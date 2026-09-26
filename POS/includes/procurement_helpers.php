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
        error_log('get_procurement_setting error: ' . $e->getMessage());
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
        error_log('set_procurement_setting error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ensures all procurement tables, indexes, and columns exist across the database.
 */
function ensure_procurement_tables(?PDO $pdo = null): void {
    static $done = false;
    if ($done) return;
    $pdo = $pdo ?? get_db();

    try {
        // 1. procurement_settings table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS procurement_settings (
                setting_key VARCHAR(60) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                description VARCHAR(255) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed default settings if not exist
        $defaults = [
            ['finance_approval_threshold', '10000.00', 'Financial threshold above which management selections require Finance review.'],
            ['three_way_match_price_tolerance_pct', '3.0', 'Percentage price variance tolerance allowed in 3-way matching.'],
            ['three_way_match_qty_tolerance_units', '0.0', 'Quantity tolerance units allowed for goods receipt vs invoice.'],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO procurement_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        foreach ($defaults as $d) {
            $ins->execute($d);
        }

        // Ensure procurement.finance.review permission and role grants exist
        try {
            $pdo->prepare("
                INSERT INTO permissions (perm_key, label, category, description) 
                VALUES ('procurement.finance.review', 'Finance Review of Quotes', 'Procurement', 'Review and authorize or reject high-value purchase quotations exceeding the finance threshold')
                ON DUPLICATE KEY UPDATE label=VALUES(label), category=VALUES(category), description=VALUES(description)
            ")->execute();
            $grant_stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (?, 'procurement.finance.review')");
            foreach (['admin', 'finance', 'manager'] as $r) {
                $grant_stmt->execute([$r]);
            }
        } catch (Throwable $pe) {
            // Non-fatal if permissions table is not active yet
        }

        // 2. Add columns if missing on existing tables
        $rfq_cols = $pdo->query("SHOW COLUMNS FROM rfqs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('rfq_ref', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN rfq_ref VARCHAR(60) NULL AFTER id");
        }
        if (!in_array('invitation_letter', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN invitation_letter TEXT NULL AFTER title");
        }
        if (!in_array('buyer_signature', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN buyer_signature LONGTEXT NULL AFTER invitation_letter");
        }
        if (!in_array('buyer_signed_by', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN buyer_signed_by INT NULL AFTER buyer_signature");
        }
        if (!in_array('buyer_signed_at', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN buyer_signed_at DATETIME NULL AFTER buyer_signed_by");
        }
        if (!in_array('terms_and_conditions', $rfq_cols)) {
            $pdo->exec("ALTER TABLE rfqs ADD COLUMN terms_and_conditions TEXT NULL AFTER buyer_signed_at");
        }

        $po_cols = $pdo->query("SHOW COLUMNS FROM purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('paid_at', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN paid_at DATETIME NULL AFTER delivered_at");
        }
        if (!in_array('contract_id', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN contract_id INT NULL AFTER requisition_id");
        }
        try {
            $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN rfq_id INT NULL");
        } catch (Throwable $e) {
            // Ignore if already nullable or constrained
        }
        if (!in_array('issue_status', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_status ENUM('none','open','resolved') DEFAULT 'none' AFTER contract_id");
        }
        if (!in_array('issue_notes', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_notes TEXT NULL AFTER issue_status");
        }
        if (!in_array('issue_raised_at', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_raised_at DATETIME NULL AFTER issue_notes");
        }
        if (!in_array('issue_resolved_at', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_resolved_at DATETIME NULL AFTER issue_raised_at");
        }
        if (!in_array('issue_resolution_notes', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_resolution_notes TEXT NULL AFTER issue_resolved_at");
        }
        if (!in_array('issue_resolved_by', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN issue_resolved_by INT NULL AFTER issue_resolution_notes");
        }
        if (!in_array('fulfillment_status', $po_cols)) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN fulfillment_status ENUM('preparing','partially_shipped','shipped','delivered') DEFAULT NULL AFTER issue_resolved_by");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS delivery_notices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notice_ref VARCHAR(50) NOT NULL UNIQUE,
                po_id INT NOT NULL,
                supplier_id INT NOT NULL,
                carrier_name VARCHAR(100) NULL,
                tracking_number VARCHAR(100) NULL,
                shipped_date DATE NOT NULL,
                expected_arrival_date DATE NULL,
                fulfillment_status ENUM('preparing','partially_shipped','shipped','delivered') DEFAULT 'shipped',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY (po_id),
                KEY (supplier_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS delivery_notice_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                delivery_notice_id INT NOT NULL,
                requisition_item_id INT NULL,
                item_name VARCHAR(150) NOT NULL,
                shipped_qty DECIMAL(10,2) NOT NULL,
                unit VARCHAR(20) DEFAULT 'pcs',
                KEY (delivery_notice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $bid_cols = $pdo->query("SHOW COLUMNS FROM bids")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('finance_status', $bid_cols)) {
            $pdo->exec("ALTER TABLE bids ADD COLUMN finance_status ENUM('pending', 'approved', 'rejected', 'not_required') DEFAULT 'pending' AFTER status");
        }
        if (!in_array('finance_reviewed_by', $bid_cols)) {
            $pdo->exec("ALTER TABLE bids ADD COLUMN finance_reviewed_by INT NULL AFTER finance_status");
        }
        if (!in_array('finance_reviewed_at', $bid_cols)) {
            $pdo->exec("ALTER TABLE bids ADD COLUMN finance_reviewed_at DATETIME NULL AFTER finance_reviewed_by");
        }
        if (!in_array('finance_review_notes', $bid_cols)) {
            $pdo->exec("ALTER TABLE bids ADD COLUMN finance_review_notes TEXT NULL AFTER finance_reviewed_at");
        }

        $gr_cols = $pdo->query("SHOW COLUMNS FROM goods_receipts")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('delivery_notice_id', $gr_cols)) {
            $pdo->exec("ALTER TABLE goods_receipts ADD COLUMN delivery_notice_id INT NULL AFTER po_id");
        }

        $inv_cols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('correction_notes', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN correction_notes TEXT NULL AFTER match_notes");
        }
        if (!in_array('contract_id', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN contract_id INT NULL AFTER po_id");
        }
        if (!in_array('attachment_path', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN attachment_path VARCHAR(255) NULL AFTER match_notes");
        }
        if (!in_array('version', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN version INT DEFAULT 1 AFTER attachment_path");
        }
        if (!in_array('parent_invoice_id', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN parent_invoice_id INT NULL AFTER version");
        }

        $done = true;
    } catch (Throwable $e) {
        error_log('ensure_procurement_tables error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════
//  RFQ INVITATION LETTER & BIDDING HELPERS
// ═══════════════════════════════════════════════

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
        error_log('create_rfq_invitation error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
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

    if (!$is_open) {
        $reason = "This RFQ is {$rfq['status']} and is no longer accepting quotations.";
        return ['can_bid' => false, 'is_expired' => false, 'is_open' => false, 'reason' => $reason];
    }

    if (!empty($rfq['due_date'])) {
        $today = date('Y-m-d');
        if (strtotime($today) > strtotime($rfq['due_date'])) {
            $is_expired = true;
            $formatted_due = date('M d, Y', strtotime($rfq['due_date']));
            $reason = "Quotation submission deadline has passed (expired on {$formatted_due}). Submissions are closed.";
            return ['can_bid' => false, 'is_expired' => true, 'is_open' => true, 'reason' => $reason];
        }
    }

    return ['can_bid' => true, 'is_expired' => false, 'is_open' => true, 'reason' => 'RFQ is open for quotations.'];
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
        error_log('withdraw_supplier_bid error: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
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

