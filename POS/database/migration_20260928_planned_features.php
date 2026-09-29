<?php
// ==============================================================================
// Migration: Planned Features & Workflow Updates (2026-09-28)
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Starting migration for Planned Features...\n";

// Helper to check if column exists
function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
    ");
    $stmt->execute([':tbl' => $table, ':col' => $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

// ------------------------------------------------------------------------------
// 1. Branches & Store Hours Tables
// ------------------------------------------------------------------------------
$pdo->exec("
CREATE TABLE IF NOT EXISTS `branches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `address` VARCHAR(255) NULL,
    `phone` VARCHAR(30) NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_branch_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `branches` checked/created.\n";

// Ensure default branch exists
$pdo->exec("
INSERT IGNORE INTO `branches` (`id`, `code`, `name`, `address`, `phone`, `status`)
VALUES 
    (1, 'MAIN', 'Main Branch - Manila', '123 Espanya Blvd, Sampaloc, Manila', '+63 917 123 4567', 'active'),
    (2, 'BGC', 'BGC High Street Branch', 'Bonifacio Global City, Taguig', '+63 917 987 6543', 'active'),
    (3, 'QC', 'Quezon City Hub', 'Tomas Morato Ave, Quezon City', '+63 917 555 1212', 'active');
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS `store_hours` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `branch_id` INT(11) NOT NULL,
    `day_of_week` TINYINT(1) NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
    `open_time` TIME NOT NULL DEFAULT '07:00:00',
    `close_time` TIME NOT NULL DEFAULT '22:00:00',
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_overnight` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_branch_day` (`branch_id`, `day_of_week`),
    CONSTRAINT `fk_store_hours_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `store_hours` checked/created.\n";

// Seed default 7 days for existing branches if empty
$branches = $pdo->query("SELECT id FROM `branches`")->fetchAll(PDO::FETCH_COLUMN);
$seed_hours = $pdo->prepare("
    INSERT IGNORE INTO `store_hours` (`branch_id`, `day_of_week`, `open_time`, `close_time`, `is_closed`, `is_overnight`)
    VALUES (:bid, :day, :open, :close, :closed, :overnight)
");
foreach ($branches as $bid) {
    for ($d = 0; $d <= 6; $d++) {
        // Weekdays: 07:00 to 22:00; Weekends: 08:00 to 23:00
        $is_weekend = ($d === 0 || $d === 6);
        $seed_hours->execute([
            ':bid' => $bid,
            ':day' => $d,
            ':open' => $is_weekend ? '08:00:00' : '07:00:00',
            ':close' => $is_weekend ? '23:00:00' : '22:00:00',
            ':closed' => 0,
            ':overnight' => 0,
        ]);
    }
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS `store_hour_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `branch_id` INT(11) NOT NULL,
    `day_of_week` TINYINT(1) NOT NULL,
    `changed_by` INT(11) NULL,
    `prior_open_time` TIME NULL,
    `prior_close_time` TIME NULL,
    `prior_is_closed` TINYINT(1) NULL,
    `prior_is_overnight` TINYINT(1) NULL,
    `new_open_time` TIME NULL,
    `new_close_time` TIME NULL,
    `new_is_closed` TINYINT(1) NULL,
    `new_is_overnight` TINYINT(1) NULL,
    `reason` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_shl_branch` (`branch_id`),
    KEY `idx_shl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `store_hour_logs` checked/created.\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS `operations_activity` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `branch_id` INT(11) NULL,
    `branch_name` VARCHAR(100) NULL,
    `module` VARCHAR(40) NOT NULL COMMENT 'store_hours,pos_shifts,sales,inventory,procurement,payroll,attendance',
    `action` VARCHAR(60) NOT NULL,
    `record_ref` VARCHAR(80) NULL,
    `status` ENUM('success','pending','warning','failed','info') NOT NULL DEFAULT 'info',
    `user_id` INT(11) NULL,
    `user_name` VARCHAR(100) NULL,
    `details` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_op_module` (`module`),
    KEY `idx_op_action` (`action`),
    KEY `idx_op_created` (`created_at`),
    KEY `idx_op_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `operations_activity` checked/created.\n";

// ------------------------------------------------------------------------------
// 2. PayMongo Batch Payout & Disbursements Tables
// ------------------------------------------------------------------------------
$pdo->exec("
CREATE TABLE IF NOT EXISTS `paymongo_payout_batches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `period_id` INT(11) NOT NULL,
    `batch_reference` VARCHAR(80) NOT NULL,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `total_amount` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    `recipient_count` INT(11) NOT NULL DEFAULT 0,
    `status` ENUM('draft','pending_approval','processing','paid','failed','cancelled','reversed') NOT NULL DEFAULT 'draft',
    `disbursement_method` VARCHAR(40) NOT NULL DEFAULT 'mixed',
    `created_by` INT(11) NULL,
    `approved_by` INT(11) NULL,
    `approved_at` DATETIME NULL,
    `submitted_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ppb_ref` (`batch_reference`),
    UNIQUE KEY `uk_ppb_idempotency` (`idempotency_key`),
    KEY `idx_ppb_period` (`period_id`),
    KEY `idx_ppb_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `paymongo_payout_batches` checked/created.\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS `paymongo_payout_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `batch_id` INT(11) NOT NULL,
    `payslip_id` INT(11) NOT NULL,
    `employee_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    `payout_destination` ENUM('bank','ewallet') NOT NULL DEFAULT 'bank',
    `recipient_name` VARCHAR(150) NOT NULL,
    `bank_code` VARCHAR(50) NULL,
    `bank_name` VARCHAR(100) NULL,
    `account_number_last4` VARCHAR(4) NULL,
    `ewallet_provider` VARCHAR(50) NULL,
    `ewallet_account_name` VARCHAR(150) NULL,
    `ewallet_mobile_number` VARCHAR(30) NULL,
    `paymongo_transfer_id` VARCHAR(100) NULL,
    `idempotency_key` VARCHAR(100) NOT NULL,
    `status` ENUM('pending_approval','processing','paid','failed','cancelled','reversed') NOT NULL DEFAULT 'pending_approval',
    `error_message` TEXT NULL,
    `attempt_count` INT(11) NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME NULL,
    `paid_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ppi_idempotency` (`idempotency_key`),
    KEY `idx_ppi_batch` (`batch_id`),
    KEY `idx_ppi_payslip` (`payslip_id`),
    KEY `idx_ppi_employee` (`employee_id`),
    KEY `idx_ppi_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `paymongo_payout_items` checked/created.\n";

// Add PayMongo disbursement settings if missing
$pm_settings = [
    ['paymongo_mode', 'sandbox', 'PayMongo operating mode: sandbox or live'],
    ['paymongo_secret_key', 'sk_test_kofee_sandbox_demo12345', 'PayMongo secret API key (starts with sk_test_ or sk_live_)'],
    ['paymongo_public_key', 'pk_test_kofee_sandbox_demo12345', 'PayMongo publishable API key'],
    ['paymongo_webhook_secret', 'whsec_demo_payroll_disbursement_kofee', 'PayMongo webhook signing secret'],
    ['paymongo_disbursement_enabled', '1', 'Enable PayMongo automated batch disbursement for payroll'],
];
$ins_setting = $pdo->prepare("
    INSERT IGNORE INTO `payroll_settings` (`setting_key`, `setting_value`, `label`, `updated_at`)
    VALUES (:k, :v, :l, NOW())
");
foreach ($pm_settings as [$k, $v, $l]) {
    $ins_setting->execute([':k' => $k, ':v' => $v, ':l' => $l]);
}
echo "PayMongo settings added/verified in `payroll_settings`.\n";

// ------------------------------------------------------------------------------
// 3. User Profile & Salary Payment Details Tables
// ------------------------------------------------------------------------------
if (!column_exists($pdo, 'users', 'avatar_path')) {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar_path` VARCHAR(255) NULL AFTER `role`");
    echo "Added `avatar_path` to `users`.\n";
}
if (!column_exists($pdo, 'users', 'phone')) {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) NULL AFTER `email`");
    echo "Added `phone` to `users`.\n";
}
if (!column_exists($pdo, 'employees', 'address')) {
    $pdo->exec("ALTER TABLE `employees` ADD COLUMN `address` TEXT NULL AFTER `contact_number`");
    echo "Added `address` to `employees`.\n";
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS `employee_payment_details` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `employee_id` INT(11) NOT NULL,
    `payout_type` ENUM('bank','ewallet') NOT NULL DEFAULT 'bank',
    `bank_code` VARCHAR(50) NULL,
    `bank_name` VARCHAR(100) NULL,
    `account_name` VARCHAR(150) NULL,
    `account_number_encrypted` TEXT NULL,
    `account_number_last4` VARCHAR(4) NULL,
    `ewallet_provider` VARCHAR(50) NULL,
    `ewallet_account_name` VARCHAR(150) NULL,
    `ewallet_mobile_number` VARCHAR(30) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `verified_by_employee` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_epd_employee` (`employee_id`),
    KEY `idx_epd_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `employee_payment_details` checked/created.\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS `employee_payment_change_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `employee_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `payout_type` ENUM('bank','ewallet') NOT NULL,
    `bank_code` VARCHAR(50) NULL,
    `bank_name` VARCHAR(100) NULL,
    `account_name` VARCHAR(150) NULL,
    `account_number_encrypted` TEXT NULL,
    `account_number_last4` VARCHAR(4) NULL,
    `ewallet_provider` VARCHAR(50) NULL,
    `ewallet_account_name` VARCHAR(150) NULL,
    `ewallet_mobile_number` VARCHAR(30) NULL,
    `employee_confirmed` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
    `reviewed_by` INT(11) NULL,
    `reviewed_at` DATETIME NULL,
    `review_notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_epcr_employee` (`employee_id`),
    KEY `idx_epcr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `employee_payment_change_requests` checked/created.\n";

// Populate active payment details for existing employees if none exist
$pdo->exec("
INSERT IGNORE INTO `employee_payment_details` 
    (`employee_id`, `payout_type`, `bank_name`, `account_name`, `account_number_last4`, `is_active`)
SELECT 
    e.id, 
    IF(e.payment_method = 'ewallet', 'ewallet', 'bank'),
    COALESCE(e.bank_name, 'BDO Unibank'),
    CONCAT(e.firstname, ' ', e.lastname),
    COALESCE(e.bank_account_last4, '1234'),
    1
FROM `employees` e
WHERE e.id NOT IN (SELECT employee_id FROM `employee_payment_details` WHERE is_active = 1);
");

// ------------------------------------------------------------------------------
// 4. Supplier Payment Rework & Attachment
// ------------------------------------------------------------------------------
$payment_cols = [
    'payment_date'                 => "DATE NULL AFTER `amount`",
    'paying_account'               => "VARCHAR(100) NULL AFTER `payment_method`",
    'receipt_attachment_path'      => "VARCHAR(255) NULL AFTER `reference_no`",
    'receipt_file_name'            => "VARCHAR(255) NULL AFTER `receipt_attachment_path`",
    'receipt_file_size'            => "INT(11) NULL AFTER `receipt_file_name`",
    'receipt_file_type'            => "VARCHAR(50) NULL AFTER `receipt_file_size`",
    'supplier_confirmation_status' => "ENUM('pending','confirmed','disputed') NOT NULL DEFAULT 'pending' AFTER `status`",
    'supplier_confirmed_by'        => "INT(11) NULL AFTER `supplier_confirmation_status`",
    'supplier_confirmed_name'      => "VARCHAR(100) NULL AFTER `supplier_confirmed_by`",
    'supplier_confirmed_at'        => "DATETIME NULL AFTER `supplier_confirmed_name`",
    'supplier_confirmation_notes'  => "TEXT NULL AFTER `supplier_confirmed_at`",
    'supplier_dispute_reason'      => "TEXT NULL AFTER `supplier_confirmation_notes`",
    'supplier_dispute_attachment'  => "VARCHAR(255) NULL AFTER `supplier_dispute_reason`",
    'supplier_disputed_at'          => "DATETIME NULL AFTER `supplier_dispute_attachment`",
];

foreach ($payment_cols as $col => $definition) {
    if (!column_exists($pdo, 'payments', $col)) {
        $pdo->exec("ALTER TABLE `payments` ADD COLUMN `$col` $definition");
        echo "Added `$col` to `payments`.\n";
    }
}

// Modify status enum in payments to support full requested lifecycle
$pdo->exec("
ALTER TABLE `payments` 
MODIFY COLUMN `status` ENUM('draft','pending_approval','scheduled','completed','partial','failed','void','refunded','cancelled') NOT NULL DEFAULT 'scheduled';
");
echo "Updated `payments`.`status` enum definition.\n";

// Ensure upload directory exists
$receipt_dir = __DIR__ . '/../uploads/receipts';
if (!is_dir($receipt_dir)) {
    @mkdir($receipt_dir, 0755, true);
}

// ------------------------------------------------------------------------------
// 5. Purchase Order Completion & Supplier Rating Workflow
// ------------------------------------------------------------------------------
$po_cols = [
    'rating_status'                   => "ENUM('not_required','pending_rating','rated','skipped') NOT NULL DEFAULT 'not_required' AFTER `status`",
    'supplier_payment_confirmed_at'   => "DATETIME NULL AFTER `paid_at`",
    'supplier_payment_confirmed_by'   => "INT(11) NULL AFTER `supplier_payment_confirmed_at`",
    'closed_notes'                    => "TEXT NULL AFTER `closed_at`",
    'is_locked'                       => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `closed_notes`",
    'reopened_at'                     => "DATETIME NULL AFTER `is_locked`",
    'reopened_by'                     => "INT(11) NULL AFTER `reopened_at`",
    'reopen_reason'                   => "TEXT NULL AFTER `reopened_by`",
];

foreach ($po_cols as $col => $definition) {
    if (!column_exists($pdo, 'purchase_orders', $col)) {
        $pdo->exec("ALTER TABLE `purchase_orders` ADD COLUMN `$col` $definition");
        echo "Added `$col` to `purchase_orders`.\n";
    }
}

// Update purchase_orders status enum to support pending_rating
$pdo->exec("
ALTER TABLE `purchase_orders`
MODIFY COLUMN `status` ENUM('draft','sent','acknowledged','delivered','pending_rating','closed','cancelled') NOT NULL DEFAULT 'draft';
");
echo "Updated `purchase_orders`.`status` enum definition.\n";

// Update supplier_performance_ratings table
$rating_cols = [
    'quantity_accuracy_score' => "TINYINT(1) NOT NULL DEFAULT 5 AFTER `timeliness_score`",
    'compliance_score'        => "TINYINT(1) NOT NULL DEFAULT 5 AFTER `communication_score`",
    'overall_score'           => "DECIMAL(3,2) NOT NULL DEFAULT '5.00' AFTER `compliance_score`",
    'is_skipped'              => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `comments`",
    'skip_reason'             => "VARCHAR(255) NULL AFTER `is_skipped`",
];

foreach ($rating_cols as $col => $definition) {
    if (!column_exists($pdo, 'supplier_performance_ratings', $col)) {
        $pdo->exec("ALTER TABLE `supplier_performance_ratings` ADD COLUMN `$col` $definition");
        echo "Added `$col` to `supplier_performance_ratings`.\n";
    }
}

// ------------------------------------------------------------------------------
// 6. Permissions & RBAC
// ------------------------------------------------------------------------------
$new_perms = [
    ['store.view',               'View Store Hours',             'Store',       'View store hours and operational status'],
    ['store.manage',             'Manage Store Hours',           'Store',       'Manage store hours and branch schedules'],
    ['operations.activity.view', 'View Operations Activity',     'Operations',  'View unified operations activity logs'],
    ['payroll.payout.paymongo',  'PayMongo Disbursements',       'Payroll',     'Initiate PayMongo batch disbursements'],
    ['payroll.payout.approve',   'Approve Payout Batches',       'Payroll',     'Approve PayMongo payout batches'],
    ['profile.view',             'View Own Profile',             'Account',     'View own user profile'],
    ['profile.edit',             'Update Personal Profile',      'Account',     'Update personal information and avatar'],
    ['employee.payment.manage',  'Manage Employee Payment Dtls', 'HR',          'Review and approve employee payment destination changes'],
    ['procurement.po.close',     'Rate Supplier & Close PO',     'Procurement', 'Rate supplier and close purchase orders'],
    ['procurement.po.reopen',    'Reopen Closed PO',             'Procurement', 'Authorize reopening of closed purchase orders'],
];

$ins_perm = $pdo->prepare("
    INSERT IGNORE INTO `permissions` (`perm_key`, `label`, `category`, `description`) 
    VALUES (:key, :lbl, :cat, :desc)
");
foreach ($new_perms as [$k, $l, $c, $d]) {
    $ins_perm->execute([':key' => $k, ':lbl' => $l, ':cat' => $c, ':desc' => $d]);
}

$grant_perm = $pdo->prepare("
    INSERT IGNORE INTO `role_permissions` (`role`, `perm_key`)
    VALUES (:role, :key)
");

// Admin gets all
foreach ($new_perms as [$k]) {
    $grant_perm->execute([':role' => 'admin', ':key' => $k]);
}

// Manager
foreach (['store.view','store.manage','operations.activity.view','profile.view','profile.edit','employee.payment.manage','procurement.po.close'] as $k) {
    $grant_perm->execute([':role' => 'manager', ':key' => $k]);
}

// Finance
foreach (['operations.activity.view','payroll.payout.paymongo','payroll.payout.approve','profile.view','profile.edit','employee.payment.manage'] as $k) {
    $grant_perm->execute([':role' => 'finance', ':key' => $k]);
}

// Procurement
foreach (['procurement.po.close','procurement.po.reopen','profile.view','profile.edit','operations.activity.view'] as $k) {
    $grant_perm->execute([':role' => 'procurement', ':key' => $k]);
}

// General users (cashier, crew, etc.)
foreach (['cashier', 'crew', 'warehouse'] as $r) {
    $grant_perm->execute([':role' => $r, ':key' => 'profile.view']);
    $grant_perm->execute([':role' => $r, ':key' => 'profile.edit']);
}

echo "Permissions seeded and assigned.\n";

echo "Migration completed successfully!\n";
