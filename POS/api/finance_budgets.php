<?php
// ==============================================================================
// API: api/finance_budgets.php
// Finance Budget Allocation, Top-ups, Transfers & Auditing
// ==============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$user  = current_user();
$roles = $user['roles'] ?? (isset($user['role']) ? [$user['role']] : []);

// Access check: Admin, Finance role, or users with procurement.budget.manage or finance.view
$has_access = has_permission('procurement.budget.manage') 
    || has_permission('finance.view') 
    || in_array('admin', $roles, true) 
    || in_array('finance', $roles, true);

if (!$has_access) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Access to finance budget allocations is restricted.']);
    exit;
}

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $period = trim($_GET['period'] ?? procurement_current_period());

    if ($action === 'list') {
        // Fetch all budgets for the selected period
        $stmt = $pdo->prepare("
            SELECT b.*,
                   u.firstname AS allocator_fname, u.lastname AS allocator_lname
            FROM procurement_budgets b
            LEFT JOIN users u ON u.id = b.allocated_by
            WHERE b.period_label = :p
            ORDER BY b.allocated_amount DESC, b.department ASC
        ");
        $stmt->execute([':p' => $period]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute aggregate metrics
        $total_allocated = 0.0;
        $total_used      = 0.0;

        foreach ($budgets as &$b) {
            $alloc = (float)$b['allocated_amount'];
            $used  = (float)$b['used_amount'];
            $rem   = max(0.0, $alloc - $used);
            $pct   = $alloc > 0 ? round(($used / $alloc) * 100, 1) : ($used > 0 ? 100.0 : 0.0);

            $b['allocated_amount'] = $alloc;
            $b['used_amount']      = $used;
            $b['remaining_amount'] = $rem;
            $b['utilization_pct']  = $pct;
            $b['is_overbudget']    = $used > $alloc;

            $total_allocated += $alloc;
            $total_used      += $used;
        }
        unset($b);

        $total_remaining = max(0.0, $total_allocated - $total_used);
        $overall_utilization = $total_allocated > 0 ? round(($total_used / $total_allocated) * 100, 1) : 0.0;

        // Fetch distinct available periods for filter
        $periodStmt = $pdo->query("SELECT DISTINCT period_label FROM procurement_budgets ORDER BY period_label DESC");
        $periods = $periodStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($period, $periods, true)) {
            array_unshift($periods, $period);
        }

        echo json_encode([
            'success' => true,
            'period'  => $period,
            'summary' => [
                'total_allocated' => $total_allocated,
                'total_used'      => $total_used,
                'total_remaining' => $total_remaining,
                'utilization_pct' => $overall_utilization,
                'budget_count'    => count($budgets)
            ],
            'budgets' => $budgets,
            'available_periods' => $periods
        ]);
        exit;
    }

    if ($action === 'breakdown') {
        $budget_id = (int)($_GET['budget_id'] ?? 0);
        if (!$budget_id) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Missing budget ID.']);
            exit;
        }

        $bStmt = $pdo->prepare("SELECT * FROM procurement_budgets WHERE id = :id");
        $bStmt->execute([':id' => $budget_id]);
        $budget = $bStmt->fetch(PDO::FETCH_ASSOC);

        if (!$budget) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Budget allocation not found.']);
            exit;
        }

        // Fetch requisitions that charged this department
        $reqStmt = $pdo->prepare("
            SELECT pr.id, pr.title, pr.department, pr.estimated_total, pr.notes,
                   pr.status, pr.created_at,
                   u.firstname AS requester_fname, u.lastname AS requester_lname,
                   po.po_number, po.total_amount AS po_total
            FROM purchase_requisitions pr
            LEFT JOIN users u ON u.id = pr.requested_by
            LEFT JOIN purchase_orders po ON po.requisition_id = pr.id
            WHERE LOWER(pr.department) = LOWER(:dept)
            ORDER BY pr.id DESC
            LIMIT 50
        ");
        $reqStmt->execute([':dept' => $budget['department']]);
        $requisitions = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch line items for each requisition if any
        if (!empty($requisitions)) {
            $reqIds = array_column($requisitions, 'id');
            $inClause = implode(',', array_fill(0, count($reqIds), '?'));
            $itemStmt = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id IN ($inClause)");
            $itemStmt->execute($reqIds);
            $itemsByReq = [];
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $itemsByReq[$it['requisition_id']][] = $it;
            }
            foreach ($requisitions as &$r) {
                $r['pr_number'] = sprintf('PR-%04d', $r['id']);
                $r['actual_total'] = ($r['po_total'] !== null && $r['po_total'] !== '') ? (float)$r['po_total'] : (float)$r['estimated_total'];
                $r['items'] = $itemsByReq[$r['id']] ?? [];
            }
            unset($r);
        }

        echo json_encode([
            'success'      => true,
            'budget'       => $budget,
            'requisitions' => $requisitions
        ]);
        exit;
    }

    if ($action === 'logs') {
        $budget_id = (int)($_GET['budget_id'] ?? 0);
        $period    = trim($_GET['period'] ?? '');

        $sql = "
            SELECT l.*, u.firstname, u.lastname, u.username
            FROM budget_allocation_logs l
            LEFT JOIN users u ON u.id = l.allocated_by
            WHERE 1=1
        ";
        $params = [];

        if ($budget_id) {
            $sql .= " AND l.budget_id = :bid";
            $params[':bid'] = $budget_id;
        } elseif ($period) {
            $sql .= " AND l.period_label = :p";
            $params[':p'] = $period;
        }

        $sql .= " ORDER BY l.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'logs'    => $logs
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid GET action.']);
    exit;
}

// ── POST Requests ─────────────────────────────
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        // Fallback to $_POST form data
        $data = $_POST;
    }

    $action = $data['action'] ?? '';
    $userId = (int)$user['id'];

    if ($action === 'allocate') {
        $department    = trim($data['department'] ?? '');
        $category_name = trim($data['category_name'] ?? '');
        $period_label  = trim($data['period_label'] ?? procurement_current_period());
        $amount        = (float)($data['allocated_amount'] ?? 0);
        $notes         = trim($data['notes'] ?? '');

        if (!$department) {
            echo json_encode(['success' => false, 'error' => 'Department or cost center is required.']);
            exit;
        }
        if ($amount < 0) {
            echo json_encode(['success' => false, 'error' => 'Budget allocation amount cannot be negative.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Check if record exists for this department and period
            $check = $pdo->prepare("SELECT * FROM procurement_budgets WHERE department = :d AND period_label = :p FOR UPDATE");
            $check->execute([':d' => $department, ':p' => $period_label]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $prevAmount = (float)$existing['allocated_amount'];
                $budgetId   = (int)$existing['id'];

                $update = $pdo->prepare("
                    UPDATE procurement_budgets
                    SET allocated_amount = :amt,
                        category_name    = :cat,
                        notes            = :n,
                        allocated_by     = :u
                    WHERE id = :id
                ");
                $update->execute([
                    ':amt' => $amount,
                    ':cat' => $category_name ?: $existing['category_name'],
                    ':n'   => $notes ?: $existing['notes'],
                    ':u'   => $userId,
                    ':id'  => $budgetId
                ]);

                // Audit log
                $log = $pdo->prepare("
                    INSERT INTO budget_allocation_logs
                    (budget_id, department, category_name, period_label, action_type, amount_change, previous_allocated, new_allocated, reason, allocated_by)
                    VALUES (:bid, :d, :c, :p, :act, :chg, :prev, :new, :r, :by)
                ");
                $log->execute([
                    ':bid'  => $budgetId,
                    ':d'    => $department,
                    ':c'    => $category_name ?: $existing['category_name'],
                    ':p'    => $period_label,
                    ':act'  => 'adjusted',
                    ':chg'  => $amount - $prevAmount,
                    ':prev' => $prevAmount,
                    ':new'  => $amount,
                    ':r'    => $notes ?: 'Budget allocation updated',
                    ':by'   => $userId
                ]);

            } else {
                $insert = $pdo->prepare("
                    INSERT INTO procurement_budgets
                    (department, category_name, period_label, allocated_amount, used_amount, notes, allocated_by)
                    VALUES (:d, :cat, :p, :amt, 0.00, :n, :u)
                ");
                $insert->execute([
                    ':d'   => $department,
                    ':cat' => $category_name ?: 'General Department Budget',
                    ':p'   => $period_label,
                    ':amt' => $amount,
                    ':n'   => $notes,
                    ':u'   => $userId
                ]);
                $budgetId = (int)$pdo->lastInsertId();

                // Audit log
                $log = $pdo->prepare("
                    INSERT INTO budget_allocation_logs
                    (budget_id, department, category_name, period_label, action_type, amount_change, previous_allocated, new_allocated, reason, allocated_by)
                    VALUES (:bid, :d, :c, :p, 'allocated', :chg, 0.00, :new, :r, :by)
                ");
                $log->execute([
                    ':bid'  => $budgetId,
                    ':d'    => $department,
                    ':c'    => $category_name ?: 'General Department Budget',
                    ':p'    => $period_label,
                    ':chg'  => $amount,
                    ':new'  => $amount,
                    ':r'    => $notes ?: 'Initial budget allocation',
                    ':by'   => $userId
                ]);
            }

            $pdo->commit();
            echo json_encode([
                'success'   => true,
                'message'   => "Successfully allocated ₱" . number_format($amount, 2) . " to {$department} for {$period_label}.",
                'budget_id' => $budgetId
            ]);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'adjust') {
        $budgetId     = (int)($data['budget_id'] ?? 0);
        $amountChange = (float)($data['amount_change'] ?? 0);
        $reason       = trim($data['reason'] ?? '');

        if (!$budgetId) {
            echo json_encode(['success' => false, 'error' => 'Invalid budget ID.']);
            exit;
        }
        if ($amountChange == 0) {
            echo json_encode(['success' => false, 'error' => 'Adjustment amount cannot be zero.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare("SELECT * FROM procurement_budgets WHERE id = :id FOR UPDATE");
            $check->execute([':id' => $budgetId]);
            $budget = $check->fetch(PDO::FETCH_ASSOC);

            if (!$budget) {
                throw new Exception("Budget allocation record not found.");
            }

            $prevAllocated = (float)$budget['allocated_amount'];
            $usedAmount    = (float)$budget['used_amount'];
            $newAllocated  = $prevAllocated + $amountChange;

            if ($newAllocated < 0) {
                throw new Exception("Allocation cannot be reduced below zero.");
            }
            if ($newAllocated < $usedAmount) {
                throw new Exception(sprintf(
                    "Cannot reduce budget to ₱%s because ₱%s has already been spent/committed in this period.",
                    number_format($newAllocated, 2),
                    number_format($usedAmount, 2)
                ));
            }

            $actionType = $amountChange > 0 ? 'topped_up' : 'adjusted';

            $update = $pdo->prepare("
                UPDATE procurement_budgets
                SET allocated_amount = :new,
                    allocated_by     = :u
                WHERE id = :id
            ");
            $update->execute([
                ':new' => $newAllocated,
                ':u'   => $userId,
                ':id'  => $budgetId
            ]);

            $log = $pdo->prepare("
                INSERT INTO budget_allocation_logs
                (budget_id, department, category_name, period_label, action_type, amount_change, previous_allocated, new_allocated, reason, allocated_by)
                VALUES (:bid, :d, :c, :p, :act, :chg, :prev, :new, :r, :by)
            ");
            $log->execute([
                ':bid'  => $budgetId,
                ':d'    => $budget['department'],
                ':c'    => $budget['category_name'],
                ':p'    => $budget['period_label'],
                ':act'  => $actionType,
                ':chg'  => $amountChange,
                ':prev' => $prevAllocated,
                ':new'  => $newAllocated,
                ':r'    => $reason ?: ($amountChange > 0 ? "Budget top-up of ₱" . number_format($amountChange, 2) : "Budget adjusted"),
                ':by'   => $userId
            ]);

            $pdo->commit();
            echo json_encode([
                'success'       => true,
                'message'       => "Budget updated. New allocation: ₱" . number_format($newAllocated, 2),
                'new_allocated' => $newAllocated
            ]);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'transfer') {
        $sourceId = (int)($data['source_budget_id'] ?? 0);
        $targetId = (int)($data['target_budget_id'] ?? 0);
        $amount   = (float)($data['transfer_amount'] ?? 0);
        $reason   = trim($data['reason'] ?? '');

        if (!$sourceId || !$targetId || $sourceId === $targetId) {
            echo json_encode(['success' => false, 'error' => 'Please select distinct source and target budgets.']);
            exit;
        }
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Transfer amount must be greater than zero.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $srcStmt = $pdo->prepare("SELECT * FROM procurement_budgets WHERE id = :id FOR UPDATE");
            $srcStmt->execute([':id' => $sourceId]);
            $source = $srcStmt->fetch(PDO::FETCH_ASSOC);

            $tgtStmt = $pdo->prepare("SELECT * FROM procurement_budgets WHERE id = :id FOR UPDATE");
            $tgtStmt->execute([':id' => $targetId]);
            $target = $tgtStmt->fetch(PDO::FETCH_ASSOC);

            if (!$source || !$target) {
                throw new Exception("One or both budget records were not found.");
            }

            $sourceAlloc = (float)$source['allocated_amount'];
            $sourceUsed  = (float)$source['used_amount'];
            $sourceAvail = $sourceAlloc - $sourceUsed;

            if ($amount > $sourceAvail) {
                throw new Exception(sprintf(
                    "Insufficient uncommitted funds in %s. Available balance is only ₱%s.",
                    $source['department'],
                    number_format($sourceAvail, 2)
                ));
            }

            $newSourceAlloc = $sourceAlloc - $amount;
            $newTargetAlloc = (float)$target['allocated_amount'] + $amount;

            // Apply update
            $pdo->prepare("UPDATE procurement_budgets SET allocated_amount = :amt, allocated_by = :u WHERE id = :id")
                ->execute([':amt' => $newSourceAlloc, ':u' => $userId, ':id' => $sourceId]);

            $pdo->prepare("UPDATE procurement_budgets SET allocated_amount = :amt, allocated_by = :u WHERE id = :id")
                ->execute([':amt' => $newTargetAlloc, ':u' => $userId, ':id' => $targetId]);

            // Log source deduction
            $pdo->prepare("
                INSERT INTO budget_allocation_logs
                (budget_id, department, category_name, period_label, action_type, amount_change, previous_allocated, new_allocated, reason, allocated_by)
                VALUES (:bid, :d, :c, :p, 'transferred', :chg, :prev, :new, :r, :by)
            ")->execute([
                ':bid'  => $sourceId,
                ':d'    => $source['department'],
                ':c'    => $source['category_name'],
                ':p'    => $source['period_label'],
                ':chg'  => -$amount,
                ':prev' => $sourceAlloc,
                ':new'  => $newSourceAlloc,
                ':r'    => "Transferred ₱" . number_format($amount, 2) . " to {$target['department']}. " . ($reason ? "Note: {$reason}" : ''),
                ':by'   => $userId
            ]);

            // Log target addition
            $pdo->prepare("
                INSERT INTO budget_allocation_logs
                (budget_id, department, category_name, period_label, action_type, amount_change, previous_allocated, new_allocated, reason, allocated_by)
                VALUES (:bid, :d, :c, :p, 'transferred', :chg, :prev, :new, :r, :by)
            ")->execute([
                ':bid'  => $targetId,
                ':d'    => $target['department'],
                ':c'    => $target['category_name'],
                ':p'    => $target['period_label'],
                ':chg'  => $amount,
                ':prev' => (float)$target['allocated_amount'],
                ':new'  => $newTargetAlloc,
                ':r'    => "Received ₱" . number_format($amount, 2) . " transferred from {$source['department']}. " . ($reason ? "Note: {$reason}" : ''),
                ':by'   => $userId
            ]);

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Successfully transferred ₱" . number_format($amount, 2) . " from {$source['department']} to {$target['department']}."
            ]);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}
