<?php
// ─────────────────────────────────────────────────────────────
//  api/inventory_batches.php
//  Batch listing + inline expiry/delivery date editing.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/inventory_helpers.php';
require_once '../includes/shift_guard.php';
require_login();

header('Content-Type: application/json');

$pdo    = get_db();
$user   = current_user();
$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? ($_GET['action'] ?? '');

try {
    // ── List batches for one ingredient ───────────
    if ($action === 'list') {
        if (!has_permission('inventory.view')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'No permission to view inventory.']);
            exit;
        }

        $ingredient_id = (int)($data['ingredient_id'] ?? $_GET['ingredient_id'] ?? 0);
        if (!$ingredient_id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Missing ingredient id.']);
            exit;
        }

        $info = $pdo->prepare('SELECT name, unit FROM ingredients WHERE id = :id');
        $info->execute([':id' => $ingredient_id]);
        $ing = $info->fetch();

        $batches = [];
        foreach (batches_for_ingredient($ingredient_id) as $b) {
            $status = batch_expiry_status($b['expiry_date']);
            $batches[] = [
                'id'            => (int)$b['id'],
                'batch_ref'     => $b['batch_ref'],
                'qty_received'  => (float)$b['qty_received'],
                'qty_remaining' => (float)$b['qty_remaining'],
                'unit'          => $b['unit'],
                'delivery_date' => $b['delivery_date'],
                'delivery_human'=> date('M d, Y g:i A', strtotime($b['delivery_date'])),
                'expiry_date'   => $b['expiry_date'],
                'expiry_source' => $b['expiry_source'],
                'supplier_name' => $b['supplier_name'],
                'status'        => $b['status'],
                'badge'         => $status,
            ];
        }

        echo json_encode([
            'ok'        => true,
            'item'      => $ing ? ['name' => $ing['name'], 'unit' => $ing['unit']] : null,
            'batches'   => $batches,
            'can_edit'  => has_permission('inventory.expiry.manage'),
        ]);
        exit;
    }

    // ── Update a batch expiry (and optionally delivery date) ──
    if ($action === 'update_expiry') {
        if (!has_permission('inventory.expiry.manage')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit expiry dates.']);
            exit;
        }
        require_shift_for_api();

        $batch_id = (int)($data['batch_id'] ?? 0);
        $expiry   = trim((string)($data['expiry_date'] ?? ''));
        $delivery = trim((string)($data['delivery_date'] ?? ''));
        $notes    = trim((string)($data['notes'] ?? ''));

        if (!$batch_id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Missing batch id.']);
            exit;
        }
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Expiry must be in YYYY-MM-DD format.']);
            exit;
        }

        $cur = $pdo->prepare(
            'SELECT b.*, i.name AS item_name FROM ingredient_batches b
               JOIN ingredients i ON i.id = b.ingredient_id
              WHERE b.id = :id'
        );
        $cur->execute([':id' => $batch_id]);
        $batch = $cur->fetch();

        if (!$batch) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Batch not found.']);
            exit;
        }

        $new_delivery = $delivery !== '' ? $delivery : $batch['delivery_date'];
        if ($expiry !== '' && strtotime($expiry) < strtotime(date('Y-m-d', strtotime($new_delivery)))) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Expiry date cannot be before the delivery date.']);
            exit;
        }

        $new_status = $batch['status'];
        if (in_array($batch['status'], ['active', 'expired'], true)) {
            $new_status = ($expiry !== '' && strtotime($expiry) < strtotime('today')) ? 'expired' : 'active';
        }

        $pdo->prepare(
            "UPDATE ingredient_batches
                SET expiry_date   = :exp,
                    expiry_source = 'manual',
                    delivery_date = :del,
                    status        = :st,
                    notes         = COALESCE(NULLIF(:notes,''), notes)
              WHERE id = :id"
        )->execute([
            ':exp'   => $expiry !== '' ? $expiry : null,
            ':del'   => $new_delivery,
            ':st'    => $new_status,
            ':notes' => $notes,
            ':id'    => $batch_id,
        ]);

        audit_log('batch', $batch_id, 'expiry_adjusted',
            sprintf('%s: %s → %s', $batch['item_name'],
                    $batch['expiry_date'] ?: 'none', $expiry ?: 'none'));

        notify_event(
            action_type: 'INVENTORY_EXPIRY_ADJUSTED',
            perm_key:    'inventory.manage',
            title:       'Expiry date adjusted — ' . $batch['item_name'],
            message:     sprintf('Batch #%d expiry changed from %s to %s.',
                            $batch_id, $batch['expiry_date'] ?: '—', $expiry ?: '—'),
            target_url:  'inventory.php',
            entity_type: 'batch',
            entity_id:   $batch_id
        );

        echo json_encode([
            'ok'     => true,
            'expiry' => $expiry ?: null,
            'badge'  => batch_expiry_status($expiry ?: null),
            'status' => $new_status,
        ]);
        exit;
    }

    // ── Discard a batch (spoiled / written off) ───
    if ($action === 'discard') {
        if (!has_permission('inventory.manage')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to discard stock.']);
            exit;
        }
        require_shift_for_api();

        $batch_id = (int)($data['batch_id'] ?? 0);
        $reason   = trim((string)($data['reason'] ?? 'Discarded'));

        $cur = $pdo->prepare(
            'SELECT b.*, i.name AS item_name FROM ingredient_batches b
               JOIN ingredients i ON i.id = b.ingredient_id WHERE b.id = :id'
        );
        $cur->execute([':id' => $batch_id]);
        $batch = $cur->fetch();

        if (!$batch || $batch['status'] === 'discarded') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Batch not found or already discarded.']);
            exit;
        }

        $qty = (float)$batch['qty_remaining'];

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE ingredient_batches SET status='discarded', qty_remaining=0, notes=:n WHERE id=:id")
            ->execute([':n' => $reason, ':id' => $batch_id]);
        $pdo->prepare('UPDATE ingredients SET quantity = GREATEST(0, quantity - :q) WHERE id = :id')
            ->execute([':q' => $qty, ':id' => (int)$batch['ingredient_id']]);
        $pdo->commit();

        audit_log('batch', $batch_id, 'discarded',
            sprintf('%s — %s %s written off. %s', $batch['item_name'], $qty, $batch['unit'], $reason));

        // Writing stock off can push the item under its threshold.
        check_and_trigger_reorder((int)$batch['ingredient_id'], (int)$user['id']);

        echo json_encode(['ok' => true, 'removed' => $qty]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('inventory_batches error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error while updating batch.']);
}