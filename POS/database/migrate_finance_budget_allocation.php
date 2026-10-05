<?php
// ==============================================================================
// Migration: Finance Budget Allocation & Audit Trail
// ==============================================================================

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Starting migration for Finance Budget Allocation...\n";

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col
    ");
    $stmt->execute([':tbl' => $table, ':col' => $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

// 1. Extend procurement_budgets table
if (!column_exists($pdo, 'procurement_budgets', 'category_name')) {
    $pdo->exec("ALTER TABLE `procurement_budgets` ADD COLUMN `category_name` VARCHAR(100) NULL AFTER `department`");
    echo "Added column `category_name` to `procurement_budgets`.\n";
}

if (!column_exists($pdo, 'procurement_budgets', 'notes')) {
    $pdo->exec("ALTER TABLE `procurement_budgets` ADD COLUMN `notes` TEXT NULL AFTER `used_amount`");
    echo "Added column `notes` to `procurement_budgets`.\n";
}

if (!column_exists($pdo, 'procurement_budgets', 'allocated_by')) {
    $pdo->exec("ALTER TABLE `procurement_budgets` ADD COLUMN `allocated_by` INT(11) NULL AFTER `notes`");
    echo "Added column `allocated_by` to `procurement_budgets`.\n";
}

if (!column_exists($pdo, 'procurement_budgets', 'created_at')) {
    $pdo->exec("ALTER TABLE `procurement_budgets` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `allocated_by`");
    echo "Added column `created_at` to `procurement_budgets`.\n";
}

if (!column_exists($pdo, 'procurement_budgets', 'updated_at')) {
    $pdo->exec("ALTER TABLE `procurement_budgets` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    echo "Added column `updated_at` to `procurement_budgets`.\n";
}

// 2. Create budget_allocation_logs table
$pdo->exec("
CREATE TABLE IF NOT EXISTS `budget_allocation_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `budget_id` INT(11) NOT NULL,
    `department` VARCHAR(60) NOT NULL,
    `category_name` VARCHAR(100) NULL,
    `period_label` VARCHAR(30) NOT NULL,
    `action_type` ENUM('allocated', 'adjusted', 'topped_up', 'transferred', 'released') NOT NULL DEFAULT 'allocated',
    `amount_change` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `previous_allocated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `new_allocated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT NULL,
    `allocated_by` INT(11) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bal_budget` (`budget_id`),
    KEY `idx_bal_dept_period` (`department`, `period_label`),
    KEY `idx_bal_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");
echo "Table `budget_allocation_logs` checked/created.\n";

// 3. Ensure permissions
$checkPerm = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE perm_key = 'procurement.budget.manage'");
$checkPerm->execute();
if ((int)$checkPerm->fetchColumn() === 0) {
    $pdo->exec("
        INSERT INTO permissions (perm_key, label, category, description)
        VALUES ('procurement.budget.manage', 'Manage Budget Allocations', 'Finance', 'Allocate, top-up, adjust, and monitor department and procurement budgets')
    ");
    echo "Permission `procurement.budget.manage` registered.\n";
}

// Ensure admin and finance have the permission
$pdo->exec("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES ('admin', 'procurement.budget.manage')");
$pdo->exec("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES ('finance', 'procurement.budget.manage')");
$pdo->exec("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES ('manager', 'procurement.budget.manage')");

// Populate default category_name for seeded rows if null
$pdo->exec("
    UPDATE `procurement_budgets` 
    SET `category_name` = CASE 
        WHEN LOWER(department) IN ('inventory', 'warehouse') THEN 'Raw Ingredients & Inventory'
        WHEN LOWER(department) IN ('crew', 'cashier') THEN 'Counter Supplies & Packaging'
        WHEN LOWER(department) IN ('manager', 'ops') THEN 'Store Operations & Maintenance'
        WHEN LOWER(department) IN ('procurement') THEN 'Direct Sourcing & Equipment'
        WHEN LOWER(department) IN ('hr') THEN 'Training & Uniforms'
        WHEN LOWER(department) IN ('admin') THEN 'General Administration & Utilities'
        WHEN LOWER(department) IN ('finance') THEN 'Financial & Accounting Operations'
        ELSE 'General Department Budget'
    END
    WHERE `category_name` IS NULL OR `category_name` = ''
");

echo "Finance budget migration completed successfully.\n";
