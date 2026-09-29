<?php
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();

echo "Starting payroll v2 database migration...\n";

// 1. Modify payroll_periods status to VARCHAR(30) to support pending_finance, partially_paid, etc.
$pdo->exec("ALTER TABLE `payroll_periods` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'draft'");
echo "payroll_periods.status converted to VARCHAR(30).\n";

// 2. Modify payslips payment_status to VARCHAR(30) to support individual transfer statuses
$pdo->exec("ALTER TABLE `payslips` MODIFY COLUMN `payment_status` VARCHAR(30) NOT NULL DEFAULT 'unpaid'");
echo "payslips.payment_status converted to VARCHAR(30).\n";

// 3. Add individual transfer tracking columns to payslips if not present
$cols = $pdo->query("SHOW COLUMNS FROM `payslips`")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('transfer_id', $cols, true)) {
    $pdo->exec("ALTER TABLE `payslips` ADD COLUMN `transfer_id` VARCHAR(100) NULL AFTER `payment_status`");
    echo "Added `transfer_id` column to `payslips`.\n";
}
if (!in_array('transfer_error', $cols, true)) {
    $pdo->exec("ALTER TABLE `payslips` ADD COLUMN `transfer_error` TEXT NULL AFTER `transfer_id`");
    echo "Added `transfer_error` column to `payslips`.\n";
}
if (!in_array('transfer_channel', $cols, true)) {
    $pdo->exec("ALTER TABLE `payslips` ADD COLUMN `transfer_channel` VARCHAR(50) NULL AFTER `transfer_error`");
    echo "Added `transfer_channel` column to `payslips`.\n";
}
if (!in_array('payout_account_info', $cols, true)) {
    $pdo->exec("ALTER TABLE `payslips` ADD COLUMN `payout_account_info` VARCHAR(150) NULL AFTER `transfer_channel`");
    echo "Added `payout_account_info` column to `payslips`.\n";
}
if (!in_array('released_by', $cols, true)) {
    $pdo->exec("ALTER TABLE `payslips` ADD COLUMN `released_by` INT(11) NULL AFTER `paid_at`");
    echo "Added `released_by` column to `payslips`.\n";
}

echo "Payroll v2 database migration completed successfully!\n";
