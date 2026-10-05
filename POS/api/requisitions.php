<?php
// ============================================================
// API: api/requisitions.php
// Dedicated JSON endpoint for purchase requisitions & offline synchronization
// ============================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';
require_once __DIR__ . '/../includes/notify.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required. Please log in.']);
    exit;
}

$user = current_user();
$pdo  = get_db();
$period_label = procurement_current_period();

$departments = [
    'manager' => 'Operations',
    'crew'    => 'Crew',
    'finance' => 'Finance',
    'hr'      => 'HR',
    'admin'   => 'Admin'
];

$can_review = has_permission('procurement.requisition.review');
$can_create = has_permission('procurement.requisitions') || has_permission('procurement.requisition.create');

// ── GET: Return list of requisitions, department budgets, and user session info ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Fetch budgets
        if ($can_review) {
            $bStmt = $pdo->prepare('SELECT * FROM procurement_budgets WHERE period_label=:p ORDER BY department');
            $bStmt->execute([':p' => $period_label]);
        } else {
            $bStmt = $pdo->prepare('SELECT * FROM procurement_budgets WHERE period_label=:p AND department=:d');
            $bStmt->execute([':p' => $period_label, ':d' => $user['role'] ?? 'crew']);
        }
        $budgets = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch requisitions
        $where  = '1=1';
        $params = [];
        if (!$can_review) {
            $where .= ' AND pr.requested_by = :uid';
            $params[':uid'] = $user['id'];
        }

        $rStmt = $pdo->prepare("
            SELECT pr.*, u.firstname, u.lastname
            FROM purchase_requisitions pr
            JOIN users u ON u.id = pr.requested_by
            WHERE $where
            ORDER BY FIELD(pr.status,'pending','approved','sourcing','awarded','rejected','closed'), pr.created_at DESC
        ");
        $rStmt->execute($params);
        $requisitions = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Pre-load items
        if (!empty($requisitions)) {
            $ids = array_column($requisitions, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $itq = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id IN ($in)");
            $itq->execute($ids);
            $itemsGrouped = [];
            foreach ($itq->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $itemsGrouped[$it['requisition_id']][] = $it;
            }
            foreach ($requisitions as &$r) {
                $r['items'] = $itemsGrouped[$r['id']] ?? [];
            }
            unset($r);
        }

        echo json_encode([
            'ok'           => true,
            'period_label' => $period_label,
            'user'         => [
                'id'         => (int)$user['id'],
                'firstname'  => $user['firstname'] ?? '',
                'lastname'   => $user['lastname'] ?? '',
                'role'       => $user['role'] ?? 'crew',
            ],
            'permissions'  => [
                'can_create' => $can_create,
                'can_review' => $can_review,
            ],
            'departments'  => $departments,
            'budgets'      => $budgets,
            'requisitions' => $requisitions,
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── POST: Create, Batch-Sync, or Review ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);
    $data = is_array($jsonData) ? $jsonData : $_POST;

    $action = $data['action'] ?? 'create';

    // 1. SINGLE CREATE
    if ($action === 'create') {
        if (!$can_create) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to file requisitions.']);
            exit;
        }

        $title = trim($data['title'] ?? '');
        $dept  = $data['department'] ?? ($user['role'] ?? 'crew');
        $notes = trim($data['notes'] ?? '');
        $items = $data['items'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        if (empty($title)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Title is required.']);
            exit;
        }
        if (empty($items) || !is_array($items)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'At least one item is required.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $total = 0;
            foreach ($items as $it) {
                $total += (float)($it['qty'] ?? 1) * (float)($it['price'] ?? 0);
            }

            $pdo->prepare('
                INSERT INTO purchase_requisitions (requested_by, department, title, notes, estimated_total, status)
                VALUES (:u, :d, :t, :n, :et, "pending")
            ')->execute([
                ':u'  => $user['id'],
                ':d'  => $dept,
                ':t'  => $title,
                ':n'  => $notes,
                ':et' => $total
            ]);
            $req_id = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('
                INSERT INTO requisition_items (requisition_id, item_name, quantity, unit, est_unit_price)
                VALUES (:r, :n, :q, :u, :p)
            ');
            foreach ($items as $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') continue;
                $ins->execute([
                    ':r' => $req_id,
                    ':n' => $name,
                    ':q' => max(0.01, (float)($it['qty'] ?? 1)),
                    ':u' => trim($it['unit'] ?? 'pcs') ?: 'pcs',
                    ':p' => max(0, (float)($it['price'] ?? 0)),
                ]);
            }
            $pdo->commit();

            audit_log('requisition', $req_id, 'created', 'Filed with ' . count($items) . ' item(s)');

            notify_role_by_permission(
                'procurement.requisition.review', 'requisition_filed',
                'New requisition awaiting review',
                htmlspecialchars($title) . ' — ' . php_currency($total),
                'requisitions.php', $user['id']
            );

            echo json_encode([
                'ok'              => true,
                'id'              => $req_id,
                'title'           => $title,
                'estimated_total' => $total,
                'message'         => 'Requisition submitted for review successfully!'
            ]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // 2. BATCH SYNC OFFLINE QUEUE
    if ($action === 'batch_sync') {
        if (!$can_create) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to sync requisitions.']);
            exit;
        }

        $batch = $data['requisitions'] ?? [];
        if (is_string($batch)) {
            $batch = json_decode($batch, true) ?: [];
        }

        if (empty($batch) || !is_array($batch)) {
            echo json_encode(['ok' => true, 'synced' => [], 'failed' => [], 'message' => 'No requisitions to sync.']);
            exit;
        }

        $synced = [];
        $failed = [];

        foreach ($batch as $entry) {
            $tempId = $entry['temp_id'] ?? ('offline_' . bin2hex(random_bytes(4)));
            $title  = trim($entry['title'] ?? '');
            $dept   = $entry['department'] ?? ($user['role'] ?? 'crew');
            $notes  = trim($entry['notes'] ?? '');
            $items  = $entry['items'] ?? [];

            if (empty($title) || empty($items)) {
                $failed[] = ['temp_id' => $tempId, 'error' => 'Missing title or items'];
                continue;
            }

            try {
                $pdo->beginTransaction();
                $total = 0;
                foreach ($items as $it) {
                    $total += (float)($it['qty'] ?? 1) * (float)($it['price'] ?? 0);
                }

                $pdo->prepare('
                    INSERT INTO purchase_requisitions (requested_by, department, title, notes, estimated_total, status)
                    VALUES (:u, :d, :t, :n, :et, "pending")
                ')->execute([
                    ':u'  => $user['id'],
                    ':d'  => $dept,
                    ':t'  => $title,
                    ':n'  => $notes . (empty($notes) ? '' : "\n") . '[Synced from offline storage]',
                    ':et' => $total
                ]);
                $req_id = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare('
                    INSERT INTO requisition_items (requisition_id, item_name, quantity, unit, est_unit_price)
                    VALUES (:r, :n, :q, :u, :p)
                ');
                foreach ($items as $it) {
                    $name = trim($it['name'] ?? '');
                    if ($name === '') continue;
                    $ins->execute([
                        ':r' => $req_id,
                        ':n' => $name,
                        ':q' => max(0.01, (float)($it['qty'] ?? 1)),
                        ':u' => trim($it['unit'] ?? 'pcs') ?: 'pcs',
                        ':p' => max(0, (float)($it['price'] ?? 0)),
                    ]);
                }
                $pdo->commit();

                audit_log('requisition', $req_id, 'created_offline_sync', 'Synced from offline device');

                notify_role_by_permission(
                    'procurement.requisition.review', 'requisition_filed',
                    'New requisition awaiting review (Synced)',
                    htmlspecialchars($title) . ' — ' . php_currency($total),
                    'requisitions.php', $user['id']
                );

                $synced[] = [
                    'temp_id'   => $tempId,
                    'server_id' => $req_id,
                    'title'     => $title
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $failed[] = ['temp_id' => $tempId, 'error' => $e->getMessage()];
            }
        }

        echo json_encode([
            'ok'      => true,
            'synced'  => $synced,
            'failed'  => $failed,
            'message' => count($synced) . ' offline requisition(s) synchronized successfully.'
        ]);
        exit;
    }

    // 3. REVIEW (Approve / Reject)
    if ($action === 'review') {
        if (!$can_review) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to review requisitions.']);
            exit;
        }

        $id     = (int)($data['id'] ?? 0);
        $status = $data['status'] ?? '';
        $notes  = trim($data['review_notes'] ?? '');

        if (!$id || !in_array($status, ['approved', 'rejected'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid requisition ID or review status.']);
            exit;
        }

        try {
            $req = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id=:id');
            $req->execute([':id' => $id]);
            $req = $req->fetch(PDO::FETCH_ASSOC);

            if (!$req || $req['status'] !== 'pending') {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Requisition not found or is no longer pending.']);
                exit;
            }

            $override_note = trim($data['budget_override_note'] ?? '');

            if ($status === 'approved') {
                $budget_check = check_budget_availability($req['department'], (float)$req['estimated_total'], $period_label);

                if (!$budget_check['ok'] && !$override_note) {
                    http_response_code(422);
                    echo json_encode([
                        'ok'          => false,
                        'over_budget' => true,
                        'remaining'   => $budget_check['remaining'],
                        'error'       => 'Exceeds department budget. An override note is required to approve.'
                    ]);
                    exit;
                }

                $pdo->prepare('
                    UPDATE purchase_requisitions
                    SET status=:s, reviewed_by=:u, reviewed_at=NOW(), review_notes=:n
                    WHERE id=:id
                ')->execute([
                    ':s' => 'approved', ':u' => $user['id'], ':n' => $notes, ':id' => $id,
                ]);

                reserve_budget($req['department'], (float)$req['estimated_total'], $period_label);
                if (!$budget_check['ok'] && $override_note) {
                    audit_log('requisition', $id, 'approved_over_budget', $override_note);
                } else {
                    audit_log('requisition', $id, 'approved', $notes ?: 'Approved without conditions');
                }

                notify_role_by_permission(
                    'procurement.rfq.manage', 'requisition_approved',
                    'Requisition approved — ready for RFQ',
                    htmlspecialchars($req['title']) . ' can now go out for supplier quotes.',
                    'rfq.php?requisition_id=' . $id, $user['id']
                );

                echo json_encode(['ok' => true, 'message' => 'Requisition approved. Ready for RFQ.']);
                exit;
            } else {
                $pdo->prepare('
                    UPDATE purchase_requisitions
                    SET status="rejected", reviewed_by=:u, reviewed_at=NOW(), review_notes=:n
                    WHERE id=:id
                ')->execute([':u' => $user['id'], ':n' => $notes, ':id' => $id]);

                audit_log('requisition', $id, 'rejected', $notes ?: 'Rejected by reviewer');

                echo json_encode(['ok' => true, 'message' => 'Requisition rejected.']);
                exit;
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}
