<?php
// ==============================================================================
// Database Migration: Clean Permissions & Roles
// Consolidates roles, normalizes permission categories, and sets up
// clean, role-based access control grants.
// ==============================================================================

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();
echo "Starting Permissions & Roles Cleanup...\n\n";

$pdo->beginTransaction();

try {
    // ── 1. Reassign users with redundant roles ──
    echo "1. Reassigning users with redundant roles...\n";
    
    // Migrate 'suppliers' -> 'supplier'
    $pdo->exec("UPDATE users SET role = 'supplier' WHERE role = 'suppliers'");
    $pdo->exec("UPDATE IGNORE user_roles SET role = 'supplier' WHERE role = 'suppliers'");
    $pdo->exec("DELETE FROM user_roles WHERE role = 'suppliers'");
    
    // Migrate 'staff' -> 'crew'
    $pdo->exec("UPDATE users SET role = 'crew' WHERE role = 'staff'");
    $pdo->exec("UPDATE IGNORE user_roles SET role = 'crew' WHERE role = 'staff'");
    $pdo->exec("DELETE FROM user_roles WHERE role = 'staff'");

    // ── 2. Clean up roles table ──
    echo "2. Setting up clean 10 system & standard roles...\n";
    
    // Delete obsolete roles
    $pdo->exec("DELETE FROM role_permissions WHERE role IN ('suppliers', 'staff')");
    $pdo->exec("DELETE FROM roles WHERE role_key IN ('suppliers', 'staff')");

    $roles = [
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

    $role_stmt = $pdo->prepare("
        INSERT INTO roles (role_key, label, is_system)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), is_system = VALUES(is_system)
    ");
    foreach ($roles as $r) {
        $role_stmt->execute($r);
    }

    // ── 3. Clean up and normalize permissions table ──
    echo "3. Normalizing permission categories, labels, and descriptions...\n";

    $permissions_def = [
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
        ['attendance.view',                 'Attendance & Time-Clock',              'HR & Staff',          'Track employee clock-in/out records, calculate work hours, and approve shifts'],
        ['leave.view',                      'Leave & PTO Management',               'HR & Staff',          'Review, approve, or reject employee leave and paid time-off applications'],
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

    $perm_stmt = $pdo->prepare("
        INSERT INTO permissions (perm_key, label, category, description)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            label       = VALUES(label),
            category    = VALUES(category),
            description = VALUES(description)
    ");
    foreach ($permissions_def as $p) {
        $perm_stmt->execute($p);
    }

    // ── 4. Grant Clean Permissions to Each Role ──
    echo "4. Assigning calibrated permissions to each role...\n";

    // Clean existing role_permissions
    $pdo->exec("DELETE FROM role_permissions");

    $grant_stmt = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, perm_key) VALUES (?, ?)");

    // 1. Admin gets all permissions
    $all_keys = array_column($permissions_def, 0);
    foreach ($all_keys as $k) {
        $grant_stmt->execute(['admin', $k]);
    }

    // 2. Manager (Store / Branch Manager)
    $manager_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'orders.new', 'orders.pending', 'orders.history',
        'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
        'menu.manage', 'menu.edit', 'menu.delete',
        'attendance.view', 'leave.view', 'requests.manage',
        'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.loans', 'payroll.own', 'payroll.advance.request',
        'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review',
        'procurement.reports.view', 'procurement.attachments.manage', 'procurement.audit.view',
        'analytics.view', 'operations.activity.view',
        'store.view', 'store.manage', 'files.download'
    ];
    foreach ($manager_perms as $k) {
        $grant_stmt->execute(['manager', $k]);
    }

    // 3. Cashier (POS Cashier)
    $cashier_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'orders.new', 'orders.pending', 'orders.history',
        'menu.manage',
        'store.view',
        'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
    ];
    foreach ($cashier_perms as $k) {
        $grant_stmt->execute(['cashier', $k]);
    }

    // 4. Crew / Barista
    $crew_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'orders.new', 'orders.pending', 'orders.history',
        'inventory.view',
        'store.view',
        'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
    ];
    foreach ($crew_perms as $k) {
        $grant_stmt->execute(['crew', $k]);
    }

    // 5. Warehouse / Inventory Officer
    $warehouse_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
        'procurement.view', 'procurement.receiving', 'procurement.grn.discrepancy.manage',
        'procurement.requisition.create', 'procurement.attachments.manage',
        'store.view',
        'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
    ];
    foreach ($warehouse_perms as $k) {
        $grant_stmt->execute(['warehouse', $k]);
    }

    // 6. Procurement Officer
    $procurement_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'procurement.view', 'procurement.requisitions', 'procurement.requisition.create', 'procurement.requisition.review',
        'procurement.rfq.manage', 'procurement.bidding.review', 'procurement.negotiation',
        'procurement.po.manage', 'procurement.close',
        'procurement.performance.rate', 'procurement.suppliers.manage', 'procurement.reports.view',
        'procurement.budget.manage', 'procurement.attachments.manage', 'procurement.audit.view',
        'inventory.view', 'store.view',
        'attendance.view', 'leave.view', 'payroll.own', 'payroll.advance.request'
    ];
    foreach ($procurement_perms as $k) {
        $grant_stmt->execute(['procurement', $k]);
    }

    // 7. Finance Officer
    $finance_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'analytics.view', 'operations.activity.view',
        'payroll.view', 'payroll.approve', 'payroll.release', 'payroll.payout.approve', 'payroll.payout.paymongo', 'payroll.loans', 'payroll.own', 'payroll.advance.request',
        'procurement.view', 'procurement.finance.review', 'procurement.invoice.create', 'procurement.invoice.match', 'procurement.payment.process',
        'procurement.budget.manage', 'procurement.reports.view', 'procurement.audit.view', 'procurement.attachments.manage',
        'attendance.view', 'leave.view', 'files.download'
    ];
    foreach ($finance_perms as $k) {
        $grant_stmt->execute(['finance', $k]);
    }

    // 8. Human Resources Officer
    $hr_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'users.manage', 'recruitment.manage', 'attendance.view', 'leave.view', 'requests.manage', 'employee.payment.manage',
        'payroll.view', 'payroll.manage', 'payroll.loans', 'payroll.settings', 'payroll.own', 'payroll.advance.request',
        'analytics.view', 'files.download', 'store.view'
    ];
    foreach ($hr_perms as $k) {
        $grant_stmt->execute(['hr', $k]);
    }

    // 9. Operations Supervisor
    $ops_perms = [
        'dashboard.view', 'employee_dashboard.view', 'profile.view', 'profile.edit',
        'orders.new', 'orders.pending', 'orders.history',
        'inventory.view', 'inventory.manage', 'inventory.expiry.manage',
        'menu.manage', 'menu.edit',
        'operations.activity.view', 'analytics.view',
        'attendance.view', 'leave.view', 'requests.manage',
        'store.view', 'store.manage',
        'payroll.own', 'payroll.advance.request'
    ];
    foreach ($ops_perms as $k) {
        $grant_stmt->execute(['ops', $k]);
    }

    // 10. Supplier Partner
    $supplier_perms = [
        'procurement.supplier.portal',
        'profile.view', 'profile.edit'
    ];
    foreach ($supplier_perms as $k) {
        $grant_stmt->execute(['supplier', $k]);
    }

    $pdo->commit();
    echo "\nPermissions & Roles Cleanup completed successfully!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
