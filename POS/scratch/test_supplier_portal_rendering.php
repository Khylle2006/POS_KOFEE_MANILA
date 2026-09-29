<?php
/**
 * Test Supplier Portal Rendering across different roles and tabs.
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db();

function test_render_portal($userId, $supplierId = null, $tab = 'rfqs') {
    global $pdo;
    $_SESSION = [
        'user_id' => $userId,
        'username' => 'testuser_' . $userId,
        'role' => 'supplier'
    ];

    // Fetch user details to set session properly
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $_SESSION['username'] = $u['username'];
        $_SESSION['role'] = $u['role'] ?? 'supplier';
    }

    // Load roles
    $rstmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = ?");
    $rstmt->execute([$userId]);
    $_SESSION['roles'] = $rstmt->fetchAll(PDO::FETCH_COLUMN) ?: [$_SESSION['role']];

    $_GET = ['tab' => $tab];
    if ($supplierId !== null) {
        $_GET['supplier_id'] = $supplierId;
    }
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/POS/php/supplier_portal.php';

    // Start output buffering and error capturing
    ob_start();
    $errs = [];
    set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errs) {
        // Ignore CLI-only session header warnings
        if (strpos($errstr, 'headers have already been sent') !== false) {
            return;
        }
        $errs[] = "[$errno] $errstr in $errfile:$errline";
    });

    // Set working directory to php folder so relative includes (../includes/...) resolve correctly
    $origCwd = getcwd();
    chdir(__DIR__ . '/../php');

    try {
        include __DIR__ . '/../php/supplier_portal.php';
    } catch (\Throwable $t) {
        $errs[] = "FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine();
    }
    chdir($origCwd);

    restore_error_handler();
    $output = ob_get_clean();

    return [
        'output_len' => strlen($output),
        'errors' => $errs,
        'has_switcher' => strpos($output, 'Switch Supplier:') !== false,
        'has_tabs' => strpos($output, 'tab-btn') !== false,
        'has_empty_notice' => strpos($output, "Your account isn't linked to a supplier profile yet") !== false,
        'title_found' => strpos($output, 'Supplier Portal') !== false,
        'html_sample' => substr(strip_tags($output), 0, 150)
    ];
}

echo "========================================================\n";
echo "TESTING SUPPLIER PORTAL RENDERING & ACCESS SCENARIOS\n";
echo "========================================================\n\n";

$tests = [
    // [desc, userId, supplierId, tab]
    ["Supplier user (ID 12) - Default View", 12, null, 'rfqs'],
    ["Admin user (ID 1) - Default (Auto-Resolved Supplier)", 1, null, 'rfqs'],
    ["Admin user (ID 1) - Explicit Selecta (ID 1)", 1, 1, 'orders'],
    ["Admin user (ID 1) - Tab 6 Scorecard (6 dimensions)", 1, 1, 'scorecard'],
    ["Admin user (ID 1) - Tab 5 Invoices", 1, 1, 'invoices'],
    ["Admin user (ID 1) - Tab 2 Contracts", 1, 1, 'contracts'],
    ["Admin user (ID 1) - Tab 4 Letters", 1, 1, 'letters'],
    ["Khylle user (ID 2) - Scorecard with Switcher", 2, 1, 'scorecard'],
    ["Procurement user (ID 11) - Orders Tab", 11, 1, 'orders'],
];

$allPassed = true;
foreach ($tests as $t) {
    list($desc, $uid, $sid, $tab) = $t;
    echo "TEST: $desc ... ";
    $res = test_render_portal($uid, $sid, $tab);

    $failReasons = [];
    if (!empty($res['errors'])) {
        $failReasons[] = "PHP Notices/Errors: " . implode('; ', $res['errors']);
    }
    if ($res['output_len'] < 500) {
        $failReasons[] = "Output too short (" . $res['output_len'] . " bytes)";
    }
    if (!$res['title_found']) {
        $failReasons[] = "Page title not found in output";
    }
    if ($res['has_empty_notice']) {
        $failReasons[] = "Unexpected unlinked blocking notice displayed";
    }

    if (empty($failReasons)) {
        echo "PASSED (len: " . $res['output_len'] . ", switcher: " . ($res['has_switcher'] ? 'YES' : 'NO') . ")\n";
    } else {
        echo "FAILED!\n";
        foreach ($failReasons as $fr) {
            echo "   -> $fr\n";
        }
        $allPassed = false;
    }
}

echo "\n========================================================\n";
echo "OVERALL: " . ($allPassed ? "ALL SCENARIOS PASSED" : "SOME SCENARIOS FAILED") . "\n";
echo "========================================================\n";
