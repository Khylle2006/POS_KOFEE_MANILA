<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !defined('APP_MIGRATING') || !APP_MIGRATING) { http_response_code(404); exit; }

function migration_ensure_login_approval_tables(PDO $pdo): void {
    // 1. Authorizations Queue Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_authorizations` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `username` varchar(60) NOT NULL,
            `auth_token` varchar(64) NOT NULL,
            `status` enum('pending','approved','rejected','expired','cancelled') NOT NULL DEFAULT 'pending',
            `ip_address` varchar(45) NOT NULL,
            `user_agent` text DEFAULT NULL,
            `device_info` varchar(150) DEFAULT NULL,
            `device_hash` varchar(64) DEFAULT NULL,
            `latitude` decimal(10,7) DEFAULT NULL,
            `longitude` decimal(10,7) DEFAULT NULL,
            `accuracy_meters` decimal(8,2) DEFAULT NULL,
            `location_status` varchar(50) DEFAULT 'success',
            `location_name` varchar(255) DEFAULT NULL,
            `distance_meters` decimal(10,2) DEFAULT NULL,
            `rejection_reason` varchar(255) DEFAULT NULL,
            `approved_by` int(11) DEFAULT NULL,
            `approved_at` datetime DEFAULT NULL,
            `rejected_by` int(11) DEFAULT NULL,
            `rejected_at` datetime DEFAULT NULL,
            `session_created` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `expires_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_auth_token` (`auth_token`),
            KEY `idx_user_status` (`user_id`, `status`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Settings Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_approval_settings` (
            `setting_key` varchar(60) NOT NULL,
            `setting_value` text DEFAULT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Trusted Devices Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `trusted_login_devices` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `device_hash` varchar(64) NOT NULL,
            `device_name` varchar(150) NOT NULL,
            `ip_address` varchar(45) NOT NULL,
            `trusted_by` int(11) NOT NULL,
            `expires_at` datetime NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_dev` (`user_id`, `device_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Seed default settings if empty
    $seedDefaults = [
        'require_approval_enabled'     => '1',
        'exempt_roles'                 => 'admin,hr',
        'store_name'                   => 'Kofee Manila (Main Store)',
        'store_latitude'               => '14.3294000',
        'store_longitude'              => '120.9367000',
        'store_geofence_radius_meters' => '200',
        'auto_approve_within_geofence' => '0',
    ];

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM login_approval_settings WHERE setting_key = :k");
    $insertStmt = $pdo->prepare("INSERT INTO login_approval_settings (setting_key, setting_value) VALUES (:k, :v)");

    foreach ($seedDefaults as $k => $v) {
        $checkStmt->execute([':k' => $k]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            $insertStmt->execute([':k' => $k, ':v' => $v]);
        }
    }

    // 4. Ensure login_approval.manage permission exists in RBAC tables
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `permissions` (
                `perm_key` varchar(64) NOT NULL,
                `label` varchar(100) NOT NULL,
                `category` varchar(50) NOT NULL,
                `description` text DEFAULT NULL,
                PRIMARY KEY (`perm_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `role_permissions` (
                `role` varchar(30) NOT NULL,
                `perm_key` varchar(64) NOT NULL,
                PRIMARY KEY (`role`, `perm_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $permCheck = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE perm_key = 'login_approval.manage'");
        $permCheck->execute();
        if ((int)$permCheck->fetchColumn() === 0) {
            $pdo->prepare("
                INSERT INTO permissions (perm_key, label, category, description)
                VALUES ('login_approval.manage', 'Login Approvals & Geolocation', 'HR & Staff', 'Review, approve, or reject staff login authorization requests and configure store geofence settings')
            ")->execute();
        }

        // Grant to Admin role by default if not yet granted
        $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES ('admin', 'login_approval.manage')")->execute();
    } catch (Throwable $e) { throw $e; }
}

function migration_ensure_procurement_letters_table(): void {
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
    } catch (Throwable $e) { throw $e; }
}

function migration_ensure_procurement_tables(?PDO $pdo = null): void {
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
            foreach (['admin'] as $r) {
                $grant_stmt->execute([$r]);
            }
        } catch (Throwable $pe) { throw $pe; }

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
        } catch (Throwable $e) { throw $e; }
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
        if (!in_array('paymongo_session_id', $inv_cols)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN paymongo_session_id VARCHAR(100) NULL AFTER parent_invoice_id");
        }

        // Supplier payout receiving account columns
        $sup_cols = $pdo->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payout_type', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN payout_type ENUM('bank','ewallet') DEFAULT 'bank' AFTER address");
        }
        if (!in_array('bank_name', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN bank_name VARCHAR(100) NULL AFTER payout_type");
        }
        if (!in_array('bank_code', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN bank_code VARCHAR(50) NULL AFTER bank_name");
        }
        if (!in_array('account_name', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN account_name VARCHAR(150) NULL AFTER bank_code");
        }
        if (!in_array('account_number', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN account_number VARCHAR(50) NULL AFTER account_name");
        }
        if (!in_array('ewallet_provider', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN ewallet_provider VARCHAR(50) NULL AFTER account_number");
        }
        if (!in_array('ewallet_account_name', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN ewallet_account_name VARCHAR(150) NULL AFTER ewallet_provider");
        }
        if (!in_array('ewallet_mobile_number', $sup_cols)) {
            $pdo->exec("ALTER TABLE suppliers ADD COLUMN ewallet_mobile_number VARCHAR(20) NULL AFTER ewallet_account_name");
        }

        // Payments PayMongo transaction columns
        $pay_cols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('paymongo_payout_id', $pay_cols)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN paymongo_payout_id VARCHAR(100) NULL AFTER reference_no");
        }
        if (!in_array('paymongo_checkout_id', $pay_cols)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN paymongo_checkout_id VARCHAR(100) NULL AFTER paymongo_payout_id");
        }
        if (!in_array('paymongo_payment_id', $pay_cols)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN paymongo_payment_id VARCHAR(100) NULL AFTER paymongo_checkout_id");
        }
        // Supplier applications table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS supplier_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_code VARCHAR(50) NOT NULL UNIQUE,
                company_name VARCHAR(150) NOT NULL,
                contact_person VARCHAR(120) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(50) NOT NULL,
                address TEXT NOT NULL,
                tax_id VARCHAR(50) NULL,
                product_type ENUM('existing_ingredient', 'custom_product') NOT NULL DEFAULT 'existing_ingredient',
                ingredient_id INT NULL,
                product_name VARCHAR(150) NOT NULL,
                product_category VARCHAR(100) NULL,
                product_description TEXT NULL,
                proposed_price DECIMAL(10,2) NULL,
                price_unit VARCHAR(30) NULL DEFAULT 'per kg',
                supply_capacity VARCHAR(100) NULL,
                business_permit_filename VARCHAR(255) NOT NULL,
                business_permit_path VARCHAR(255) NOT NULL,
                authenticity_cert_filename VARCHAR(255) NOT NULL,
                authenticity_cert_path VARCHAR(255) NOT NULL,
                additional_documents_filename VARCHAR(255) NULL,
                additional_documents_path VARCHAR(255) NULL,
                company_profile_notes TEXT NULL,
                status ENUM('review', 'under_review', 'approved', 'rejected') NOT NULL DEFAULT 'review',
                reviewer_notes TEXT NULL,
                rejection_reason TEXT NULL,
                reviewed_by INT NULL,
                reviewed_at DATETIME NULL,
                supplier_id INT NULL,
                created_user_id INT NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_supapp_code (application_code),
                INDEX idx_supapp_email (email),
                INDEX idx_supapp_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $done = true;
    } catch (Throwable $e) { throw $e; }
}

function migration_ensure_notifications_table(?PDO $pdo = null): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db = $pdo ?? get_db();
        $cols = $db->query("SHOW COLUMNS FROM notifications LIKE 'read_at'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read");
        }
    } catch (Throwable $e) { throw $e; }
    $ensured = true;
}

function migration_paymongo_ensure_order_columns(PDO $pdo): void
{
    $columns = [
        'paymongo_session_id' => 'VARCHAR(100) NULL',
        'paymongo_payment_id' => 'VARCHAR(100) NULL',
        'payment_status'      => "ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending'",
        'amount_tendered'     => 'DECIMAL(12,2) NULL',
        'change_amount'       => 'DECIMAL(12,2) NULL',
        'payment_reference'   => 'VARCHAR(100) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!paymongo_order_has_column($pdo, $column)) {
            try {
                $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $column . ' ' . $definition);
            } catch (Throwable $e) { throw $e; }
        }
    }
}

function migration_install_default_permissions(): void {
    try {
        $pdo = get_db();
        
        $default_permissions = [
            // General / Account
            ['dashboard.view',                  'View Dashboard Overview',               'General',             'Access system summary metrics, daily charts, and quick actions'],
            ['employee_dashboard.view',         'View Employee Dashboard',              'General',             'Personal staff portal to clock in/out, view schedule, and request leave'],
            ['profile.view',                    'View Own Profile',                     'General',             'View personal profile, assigned roles, and store credentials'],
            ['profile.edit',                    'Update Personal Profile',              'General',             'Update personal contact information, avatar, and security password'],

            // POS & Orders
            ['orders.new',                      'Create POS Orders',                    'POS & Orders',        'Ring up walk-in and takeout customer orders and process checkout payments'],
            ['orders.pending',                  'Kitchen & Pending Orders',             'POS & Orders',        'View, manage, and fulfill active drink orders in the barista queue'],
            ['orders.history',                  'Order Receipts & History',             'POS & Orders',        'View completed transaction history, sales receipts, and reprint orders'],

            // Inventory
            ['inventory.view',                  'View Inventory & BOM',                 'Inventory',           'View current stock levels, unit costs, low-stock reorder alerts, and recipe bill of materials'],
            ['inventory.manage',                'Manage & Restock Inventory',           'Inventory',           'Add ingredients, adjust stock counts, record wastage, and process store restocks'],
            ['inventory.expiry.manage',         'Manage Batch Expiry Dates',            'Inventory',           'Track perishable ingredient batches, shelf-life dates, and handle expired items'],

            // Menu
            ['menu.manage',                     'Menu Management & Add Items',          'Menu',                'Access menu catalog and create new drinks, pastries, and items'],
            ['menu.edit',                       'Edit Items & Pricing',                 'Menu',                'Modify item prices, descriptions, recipe configurations, and categories'],
            ['menu.delete',                     'Delete & Archive Items',               'Menu',                'Archive or permanently remove products from the active POS menu'],

            // HR & Staff
            ['users.manage',                    'Staff & User Management',              'HR & Staff',          'Create, edit, and deactivate employee login accounts and roles'],
            ['login_approval.manage',           'Login Approvals & Geolocation',        'HR & Staff',          'Review, approve, or reject staff login authorization requests and configure store geofence settings'],
            ['attendance.view',                 'Attendance & Time-Clock',              'HR & Staff',          'Track employee clock-in/out records, calculate work hours, and approve shifts'],
            ['attendance.manage',               'Manage Attendance Records',            'HR & Staff',          'Mark, correct, clock out, and delete attendance records'],
            ['leave.view',                      'Leave & PTO Management',               'HR & Staff',          'Review, approve, or reject employee leave and paid time-off applications'],
            ['leave.manage',                    'Review Leave Requests',                 'HR & Staff',          'Approve, reject, and manage employee leave requests'],
            ['recruitment.manage',              'Recruitment & Job Vacancies',          'HR & Staff',          'Manage career job postings, applicant tracking, and interview stages'],
            ['requests.manage',                 'Manage HR Requests',                   'HR & Staff',          'Process general employee inquiries, certificates, and HR change requests'],
            ['employee.payment.manage',         'Manage Employee Payment Details',      'HR & Staff',          'Review and approve employee bank account and e-wallet payout destinations'],

            // Payroll
            ['payroll.view',                    'View Payroll Register & Dashboard',    'Payroll',             'View payroll dashboard summary, period registers, and employee payslips'],
            ['payroll.manage',                  'Manage Payroll Periods & Calculate',   'Payroll',             'Create multi-step pay periods, edit employee amounts, and calculate deductions'],
            ['payroll.approve',                 'Approve Calculated Payroll Runs',      'Payroll',             'Review and sign-off on payroll figures prior to payout release'],
            ['payroll.release',                 'Disburse & Release Payroll',           'Payroll',             'Execute automated bank/e-wallet transfers or manual cash payouts to employees'],
            ['payroll.loans',                   'Manage & Approve Loans / Advances',    'Payroll',             'Issue staff cash advances, set installment amortizations, and manage balances'],
            ['payroll.settings',                'Configure Payroll Standards',          'Payroll',             'Configure working hours, overtime/holiday multipliers, and tip pooling rules'],
            ['payroll.payout.approve',          'Approve Payout Batches',               'Payroll',             'Authorize PayMongo automated disbursement batches for transmission'],
            ['payroll.payout.paymongo',         'PayMongo Disbursements',               'Payroll',             'Configure and connect PayMongo API disbursement credentials'],
            ['payroll.own',                     'My Compensation & Payslips',           'Payroll',             'Staff self-service: view own salary slips, breakdown, and loan ledgers'],
            ['payroll.advance.request',         'Request Cash Advance',                 'Payroll',             'Staff self-service: submit salary cash advance requests for management review'],

            // Procurement
            ['procurement.view',                'View Procurement Dashboard',           'Procurement',         'Access procurement analytics, order pipelines, and requisition registers'],
            ['procurement.requisitions',        'Create / Edit Requisitions',           'Procurement',         'View, draft, and modify departmental purchase requisitions'],
            ['procurement.requisition.create',  'File Purchase Requisitions',           'Procurement',         'Submit purchase requests for coffee beans, dairy, packaging, and supplies'],
            ['procurement.requisition.review',  'Review & Approve Requisitions',        'Procurement',         'Authorize or reject purchase requests and verify departmental budget allocation'],
            ['procurement.rfq.manage',          'Manage RFQs',                          'Procurement',         'Create Requests for Quotation and invite verified suppliers to bid'],
            ['procurement.bidding.review',      'Review Supplier Bids',                 'Procurement',         'Evaluate competing supplier bids on price, quality, warranty, and lead time'],
            ['procurement.negotiation',         'Negotiate Supplier Terms',             'Procurement',         'Conduct commercial negotiations and finalize unit pricing with suppliers'],
            ['procurement.po.manage',           'Manage Purchase Orders',               'Procurement',         'Generate, issue, and track Purchase Orders sent to vendor partners'],
            ['procurement.receiving',           'Record Goods Receipt (GRN)',           'Procurement',         'Log delivered physical shipments, inspect quality, and record received items'],
            ['procurement.grn.discrepancy.manage','Resolve Delivery Discrepancies',      'Procurement',         'Handle damaged shipments, missing items, and vendor return authorizations'],
            ['procurement.invoice.create',      'Log Supplier Invoices',                'Procurement',         'Enter incoming supplier billing statements and tax invoices against Purchase Orders'],
            ['procurement.invoice.match',       'Match Invoices (3-Way Match)',         'Procurement',         'Perform 3-way reconciliation among Purchase Order, Goods Receipt, and Invoice'],
            ['procurement.payment.process',     'Process Supplier Payments',            'Procurement',         'Schedule and record disbursements for approved supplier invoices'],
            ['procurement.finance.review',      'Finance Review of Quotes',             'Procurement',         'Review and approve high-value quotations exceeding the manager threshold'],
            ['procurement.suppliers.manage',    'Manage Supplier Directory',            'Procurement',         'Add, verify, and maintain vendor contact details and payment terms'],
            ['procurement.performance.rate',    'Rate Supplier Performance',            'Procurement',         'Score vendor reliability, fulfillment speed, product quality, and compliance'],
            ['procurement.close',               'Close & Archive Orders',               'Procurement',         'Officially close completed purchase orders upon fulfillment and rating'],
            ['procurement.reports.view',        'View Procurement Reports',             'Procurement',         'Generate vendor spend reports, lead time analytics, and purchase statistics'],
            ['procurement.budget.manage',       'Manage Procurement Budgets',           'Procurement',         'Allocate and adjust departmental monthly purchasing budgets'],
            ['procurement.attachments.manage',  'Manage Procurement Attachments',       'Procurement',         'Upload and inspect contractual agreements, delivery receipts, and spec sheets'],
            ['procurement.audit.view',          'View Procurement Audit Log',           'Procurement',         'Inspect chronological audit trails of all purchasing events and approvals'],
            ['procurement.supplier.portal',     'Supplier Portal Access',               'Procurement',         'External vendor access to view RFQ invites, submit quotes, and track orders'],

            // Reports & Analytics
            ['analytics.view',                  'Financial Analytics & Reports',        'Reports & Analytics', 'Inspect store revenue, profit & loss, product sales, and cashier metrics'],
            ['operations.activity.view',        'View Operations Activity Log',         'Reports & Analytics', 'Review comprehensive system-wide activity, audit events, and user actions'],

            // Settings & Store
            ['store.view',                      'View Store Hours & Schedule',          'Settings & Store',    'Check branch operational schedule, opening hours, and active notices'],
            ['store.manage',                    'Manage Store Hours & Schedules',       'Settings & Store',    'Update store operating hours, holiday closures, and branch parameters'],
            ['permissions.manage',              'Manage Role Permissions (RBAC)',       'Settings & Store',    'Configure system roles, access levels, and assign permissions'],
            ['files.download',                  'Download Secured Files',               'Settings & Store',    'Download exported reports, invoices, backups, and secure attachments'],
        ];
        
        $pStmt = $pdo->prepare("INSERT INTO permissions (perm_key, label, category, description) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), description = VALUES(description)");
        foreach ($default_permissions as $perm) {
            $pStmt->execute($perm);
        }
        
        // Clean roles to standard 10
        $roles_to_ensure = [
            ['admin',       'System Administrator',           1],
            ['manager',     'Branch / Store Manager',         1],
            ['cashier',     'POS Cashier',                    1],
            ['crew',        'Crew / Barista',                 1],
            ['warehouse',   'Warehouse & Inventory Officer',  1],
            ['procurement', 'Procurement Officer',            1],
            ['finance',     'Finance Officer',                1],
            ['hr',          'Human Resources Officer',        1],
            ['ops',         'Operations Supervisor',          1],
            ['supplier',    'Supplier Partner',               1],
        ];
        
        $rStmt = $pdo->prepare("INSERT INTO roles (role_key, label, is_system) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), is_system = VALUES(is_system)");
        foreach ($roles_to_ensure as $r) {
            $rStmt->execute($r);
        }
        
        // Ensure Admin has all permissions
        $allPerms = $pdo->query("SELECT perm_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        $rpStmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (?, ?)");
        foreach ($allPerms as $pk) {
            $rpStmt->execute(['admin', $pk]);
        }
        // Existing view grants do not authorize personnel mutations.
        foreach (['hr', 'manager'] as $reviewerRole) {
            foreach (['attendance.manage', 'leave.manage'] as $permission) $rpStmt->execute([$reviewerRole, $permission]);
        }
        
    } catch (Exception $e) { throw $e; }
}
