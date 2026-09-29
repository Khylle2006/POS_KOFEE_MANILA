<?php
// ==============================================================================
// FILE: database/update_belonged_departments.php
// Update employee, requisition, and budget records to match their belonged department
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db();

echo "Starting database department reconciliation...\n";

// 1. Update Employees to their true belonged departments and normalized position titles
$emp_updates = [
    1  => ['dept' => 'cashier',     'pos' => 'Cashier',                       'name' => 'Khylle Roque'],
    2  => ['dept' => 'admin',       'pos' => 'Administrator',                 'name' => 'Admin User'],
    3  => ['dept' => 'hr',          'pos' => 'HR Officer',                    'name' => 'Hr Test'],
    4  => ['dept' => 'crew',        'pos' => 'Barista / Crew',                'name' => 'Crew Test'],
    5  => ['dept' => 'finance',     'pos' => 'Finance Officer',               'name' => 'finance testing'],
    6  => ['dept' => 'manager',     'pos' => 'Store Manager',                 'name' => 'manager test'],
    8  => ['dept' => 'ops',         'pos' => 'Operations Specialist',         'name' => 'ops test'],
    9  => ['dept' => 'procurement', 'pos' => 'Procurement Officer',           'name' => 'Procurment Testing'],
    10 => ['dept' => 'warehouse',   'pos' => 'Warehouse & Receiving Officer', 'name' => 'Receiving Testing'],
    11 => ['dept' => 'crew',        'pos' => 'Barista / Crew',                'name' => 'testing Test'],
    13 => ['dept' => 'supplier',    'pos' => 'Supplier Representative',       'name' => 'Supplier Testing'],
];

$stmt_emp = $pdo->prepare("UPDATE employees SET department = :dept, position = :pos WHERE id = :id");
foreach ($emp_updates as $id => $info) {
    $stmt_emp->execute([
        ':dept' => $info['dept'],
        ':pos'  => $info['pos'],
        ':id'   => $id
    ]);
    echo "Updated Emp ID $id ({$info['name']}) -> Dept: {$info['dept']}, Pos: {$info['pos']}\n";
}

// 2. Ensure User 19 (cashier) is linked to an employee record in 'cashier' department
$u19_exists = $pdo->query("SELECT id FROM employees WHERE user_id = 19")->fetchColumn();
if (!$u19_exists) {
    $pdo->prepare("
        INSERT INTO employees (user_id, employee_code, firstname, lastname, position, department, branch, email, hire_date, employment_type, base_salary, pay_type, pay_rate, payment_method, status)
        VALUES (19, 'EMP-0019', 'Cashier', 'Staff', 'Cashier', 'cashier', 'Main', 'cashier@gmail.com', '2026-09-26', 'Full-time', 18000.00, 'hourly', 86.54, 'cash', 'active')
    ")->execute();
    echo "Created employee record for User 19 (cashier) -> Dept: cashier\n";
} else {
    $pdo->prepare("UPDATE employees SET department = 'cashier', position = 'Cashier' WHERE user_id = 19")->execute();
    echo "Updated existing employee record for User 19 -> Dept: cashier\n";
}

// 3. Ensure procurement_budgets covers all operational departments
$budgets_to_seed = [
    ['dept' => 'procurement', 'period' => '2026-Q3', 'allocated' => 50000.00],
    ['dept' => 'warehouse',   'period' => '2026-Q3', 'allocated' => 50000.00],
    ['dept' => 'ops',         'period' => '2026-Q3', 'allocated' => 50000.00],
    ['dept' => 'cashier',     'period' => '2026-Q3', 'allocated' => 25000.00],
];

foreach ($budgets_to_seed as $b) {
    $b_exists = $pdo->prepare("SELECT id FROM procurement_budgets WHERE department = :d AND period_label = :p");
    $b_exists->execute([':d' => $b['dept'], ':p' => $b['period']]);
    if (!$b_exists->fetchColumn()) {
        $pdo->prepare("INSERT INTO procurement_budgets (department, period_label, allocated_amount, used_amount) VALUES (:d, :p, :a, 0.00)")
            ->execute([':d' => $b['dept'], ':p' => $b['period'], ':a' => $b['allocated']]);
        echo "Seeded procurement budget for department '{$b['dept']}' ({$b['period']})\n";
    }
}

// 4. Update Requisitions filed by procurement user to 'procurement' department
$req_updated = $pdo->exec("UPDATE purchase_requisitions SET department = 'procurement' WHERE requested_by = 11 AND department = 'finance'");
echo "Updated $req_updated purchase requisition(s) for procurement user.\n";

echo "Database department update complete!\n";
