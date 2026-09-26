<?php
// ─────────────────────────────────────────────────────────────
//  includes/inventory_helpers.php
//  Batch/expiry tracking + automated reorder engine.
//
//  Include AFTER includes/db.php and includes/auth.php.
//  Depends on: includes/procurement_helpers.php (audit_log)
//              includes/notify.php              (notify_event)
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/procurement_helpers.php';
require_once __DIR__ . '/notify.php';

// Days before expiry that an item starts showing the amber badge.
if (!defined('EXPIRY_WARNING_DAYS')) define('EXPIRY_WARNING_DAYS', 7);
// Fallback shelf life when a category has none configured.
if (!defined('DEFAULT_SHELF_LIFE_DAYS')) define('DEFAULT_SHELF_LIFE_DAYS', 30);
// Department the auto-reorder requisitions are filed under.
if (!defined('AUTO_REORDER_DEPARTMENT')) define('AUTO_REORDER_DEPARTMENT', 'Inventory');

// ═══════════════════════════════════════════════
//  SHELF LIFE / EXPIRY MATH
// ═══════════════════════════════════════════════

/** Shelf life in days for an ingredient, via its category. */
function ingredient_shelf_life_days(int $ingredient_id): int {
    static $cache = [];
    if (isset($cache[$ingredient_id])) return $cache[$ingredient_id];

    try {
        $stmt = get_db()->prepare(
            'SELECT COALESCE(ic.shelf_life_days, :fallback)
               FROM ingredients i
               JOIN ingredient_categories ic ON ic.id = i.cat_id
              WHERE i.id = :id'
        );
        $stmt->execute([':fallback' => DEFAULT_SHELF_LIFE_DAYS, ':id' => $ingredient_id]);
        $days = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('ingredient_shelf_life_days failed: ' . $e->getMessage());
        $days = 0;
    }

    return $cache[$ingredient_id] = ($days > 0 ? $days : DEFAULT_SHELF_LIFE_DAYS);
}

/** delivery date + shelf life → Y-m-d expiry date. */
function compute_expiry_date(string $delivery_date, int $shelf_life_days): string {
    $ts = strtotime($delivery_date) ?: time();
    return date('Y-m-d', strtotime('+' . max(1, $shelf_life_days) . ' days', $ts));
}

/**
 * Classify an expiry date into a display state.
 *
 * @return array{key:string,label:string,days:?int,classes:string,dot:string}
 */
function batch_expiry_status(?string $expiry_date): array {
    if (!$expiry_date) {
        return [
            'key' => 'none', 'label' => 'No expiry', 'days' => null,
            'classes' => 'bg-stone-100 text-stone-500 border-stone-200',
            'dot'     => 'bg-stone-400',
        ];
    }

    $today = new DateTimeImmutable('today');
    $exp   = new DateTimeImmutable($expiry_date);
    $days  = (int)$today->diff($exp)->format('%r%a');

    if ($days < 0) {
        return [
            'key' => 'expired', 'label' => 'Expired ' . abs($days) . 'd ago', 'days' => $days,
            'classes' => 'bg-red-50 text-red-700 border-red-200',
            'dot'     => 'bg-red-500',
        ];
    }
    if ($days <= EXPIRY_WARNING_DAYS) {
        return [
            'key' => 'soon',
            'label' => $days === 0 ? 'Expires today' : 'Expires in ' . $days . 'd',
            'days' => $days,
            'classes' => 'bg-amber-50 text-amber-700 border-amber-200',
            'dot'     => 'bg-amber-500',
        ];
    }
    return [
        'key' => 'fresh', 'label' => 'Fresh · ' . $days . 'd left', 'days' => $days,
        'classes' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'dot'     => 'bg-emerald-500',
    ];
}

// ═══════════════════════════════════════════════
//  BATCH RECORDING
// ═══════════════════════════════════════════════

/**
 * Record a delivered batch for an ingredient.
 *
 * IMPORTANT: this does NOT touch ingredients.quantity. The caller
 * (goods_receipts.php, the restock form) already adds the stock —
 * doing it here too would double-count.
 *
 * @param array $opts grn_id, po_id, supplier_id, batch_ref, unit,
 *                    delivery_date, expiry_date, notes, recorded_by
 * @return int new batch id, or 0 on failure
 */
function record_ingredient_batch(int $ingredient_id, float $qty, array $opts = []): int {
    if ($ingredient_id <= 0 || $qty <= 0) return 0;

    try {
        $pdo = get_db();

        $delivery = $opts['delivery_date'] ?? date('Y-m-d H:i:s');
        $expiry   = $opts['expiry_date']   ?? null;
        $source   = 'auto';

        if ($expiry) {
            $source = 'manual';
        } else {
            $expiry = compute_expiry_date($delivery, ingredient_shelf_life_days($ingredient_id));
        }

        if (empty($opts['unit'])) {
            $u = $pdo->prepare('SELECT unit FROM ingredients WHERE id = :id');
            $u->execute([':id' => $ingredient_id]);
            $opts['unit'] = $u->fetchColumn() ?: 'pcs';
        }

        $pdo->prepare(
            'INSERT INTO ingredient_batches
                (ingredient_id, grn_id, po_id, supplier_id, batch_ref, qty_received,
                 qty_remaining, unit, delivery_date, expiry_date, expiry_source, notes, recorded_by)
             VALUES (:ing, :grn, :po, :sup, :ref, :qr, :qr2, :unit, :del, :exp, :src, :notes, :by)'
        )->execute([
            ':ing'   => $ingredient_id,
            ':grn'   => $opts['grn_id']      ?? null,
            ':po'    => $opts['po_id']       ?? null,
            ':sup'   => $opts['supplier_id'] ?? null,
            ':ref'   => $opts['batch_ref']   ?? null,
            ':qr'    => $qty,
            ':qr2'   => $qty,
            ':unit'  => $opts['unit'],
            ':del'   => $delivery,
            ':exp'   => $expiry,
            ':src'   => $source,
            ':notes' => $opts['notes'] ?? null,
            ':by'    => $opts['recorded_by'] ?? ($_SESSION['user_id'] ?? null),
        ]);

        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('record_ingredient_batch failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Draw down batches FIFO (oldest expiry first) when stock is consumed.
 * Best-effort — never throws, so a sale is never blocked by batch math.
 */
function consume_ingredient_batches(int $ingredient_id, float $qty): void {
    if ($ingredient_id <= 0 || $qty <= 0) return;

    try {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT id, qty_remaining FROM ingredient_batches
              WHERE ingredient_id = :id AND status = 'active' AND qty_remaining > 0
              ORDER BY COALESCE(expiry_date, '9999-12-31') ASC, delivery_date ASC"
        );
        $stmt->execute([':id' => $ingredient_id]);

        $update = $pdo->prepare(
            "UPDATE ingredient_batches
                SET qty_remaining = :rem,
                    status = CASE WHEN :rem2 <= 0 THEN 'depleted' ELSE status END
              WHERE id = :id"
        );

        $left = $qty;
        foreach ($stmt->fetchAll() as $batch) {
            if ($left <= 0) break;
            $take = min($left, (float)$batch['qty_remaining']);
            $rem  = (float)$batch['qty_remaining'] - $take;
            $update->execute([':rem' => $rem, ':rem2' => $rem, ':id' => $batch['id']]);
            $left -= $take;
        }
    } catch (Throwable $e) {
        error_log('consume_ingredient_batches failed: ' . $e->getMessage());
    }
}

/** Flag past-date batches as expired. Cheap enough to call on page load. */
function sync_expired_batches(): void {
    try {
        get_db()->exec(
            "UPDATE ingredient_batches
                SET status = 'expired'
              WHERE status = 'active'
                AND expiry_date IS NOT NULL
                AND expiry_date < CURDATE()"
        );
    } catch (Throwable $e) {
        error_log('sync_expired_batches failed: ' . $e->getMessage());
    }
}

/**
 * Per-ingredient expiry roll-up for the inventory table:
 * soonest active expiry + counts. Returns [ingredient_id => row].
 */
function ingredient_expiry_map(): array {
    try {
        $rows = get_db()->query(
            "SELECT ingredient_id,
                    MIN(CASE WHEN status = 'active' THEN expiry_date END)               AS next_expiry,
                    SUM(status = 'active')                                              AS active_batches,
                    SUM(status = 'expired')                                             AS expired_batches,
                    SUM(CASE WHEN status = 'active' THEN qty_remaining ELSE 0 END)      AS qty_tracked
               FROM ingredient_batches
              GROUP BY ingredient_id"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('ingredient_expiry_map failed: ' . $e->getMessage());
        return [];
    }

    $map = [];
    foreach ($rows as $r) $map[(int)$r['ingredient_id']] = $r;
    return $map;
}

/** All batches for one ingredient, newest delivery first. */
function batches_for_ingredient(int $ingredient_id): array {
    $stmt = get_db()->prepare(
        'SELECT b.*, s.name AS supplier_name
           FROM ingredient_batches b
           LEFT JOIN suppliers s ON s.id = b.supplier_id
          WHERE b.ingredient_id = :id
          ORDER BY b.delivery_date DESC, b.id DESC'
    );
    $stmt->execute([':id' => $ingredient_id]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════
//  AUTOMATED REORDER ENGINE
// ═══════════════════════════════════════════════

/**
 * Is there already an in-flight procurement request for this item?
 * "In flight" = an auto requisition that hasn't been rejected/closed,
 * or any requisition line for this ingredient still moving through
 * the pipeline.
 */
function has_active_reorder(int $ingredient_id): bool {
    try {
        $stmt = get_db()->prepare(
            "SELECT 1
               FROM purchase_requisitions pr
               LEFT JOIN requisition_items ri ON ri.requisition_id = pr.id
              WHERE (pr.source_ingredient_id = :id1 OR ri.ingredient_id = :id2)
                AND pr.status NOT IN ('rejected','closed')
              LIMIT 1"
        );
        $stmt->execute([':id1' => $ingredient_id, ':id2' => $ingredient_id]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('has_active_reorder failed: ' . $e->getMessage());
        return true; // fail closed — better to skip than spam duplicates
    }
}

/** Last known unit price for an ingredient, prioritizing configured unit_cost, then past requisition lines. */
function last_known_unit_price(int $ingredient_id, string $item_name = ''): float {
    try {
        // Priority 1: Check unit_cost configured on the ingredient itself
        $stmt_cost = get_db()->prepare('SELECT unit_cost FROM ingredients WHERE id = :id');
        $stmt_cost->execute([':id' => $ingredient_id]);
        $cost = $stmt_cost->fetchColumn();
        if ($cost !== false && $cost !== null && (float)$cost > 0) {
            return (float)$cost;
        }

        // Priority 2: Fall back to past requisition lines
        $stmt = get_db()->prepare(
            'SELECT est_unit_price
               FROM requisition_items
              WHERE (ingredient_id = :id OR LOWER(TRIM(item_name)) = LOWER(TRIM(:n)))
                AND est_unit_price > 0
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $ingredient_id, ':n' => $item_name]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * The threshold rule:
 *   IF current_stock <= reorder_at
 *   AND NOT EXISTS (active pending order for this item)
 *   THEN file an auto requisition for reorder_quantity.
 *
 * @return int requisition id created, or 0 if nothing was needed
 */
function check_and_trigger_reorder(int $ingredient_id, ?int $actor_id = null): int {
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare(
            'SELECT i.*, ic.name AS cat_name
               FROM ingredients i
               JOIN ingredient_categories ic ON ic.id = i.cat_id
              WHERE i.id = :id AND i.archived_at IS NULL'
        );
        $stmt->execute([':id' => $ingredient_id]);
        $item = $stmt->fetch();

        if (!$item)                              return 0;
        if ((int)$item['auto_reorder'] !== 1)    return 0;
        if ((float)$item['reorder_at'] <= 0)     return 0;
        if ((float)$item['quantity'] > (float)$item['reorder_at']) return 0;
        if (has_active_reorder($ingredient_id))  return 0;

        $qty        = (float)$item['reorder_quantity'];
        if ($qty <= 0) $qty = max((float)$item['reorder_at'] * 2, 10);
        $unit_price = (float)($item['unit_cost'] ?? 0) > 0 ? (float)$item['unit_cost'] : last_known_unit_price($ingredient_id, $item['name']);
        $total      = $qty * $unit_price;

        $cost_note = $unit_price > 0
            ? sprintf('Estimated auto-reorder total: ₱%s (₱%s / %s).', number_format($total, 2), number_format($unit_price, 2), $item['cost_unit'] ?: $item['unit'])
            : 'Estimate unavailable (cost not set).';

        $pdo->prepare(
            "INSERT INTO purchase_requisitions
                (requested_by, department, source, source_ingredient_id, title, notes,
                 estimated_total, status)
             VALUES (:by, :dept, 'auto_reorder', :ing, :title, :notes, :total, 'pending')"
        )->execute([
            ':by'    => $actor_id ?? ($_SESSION['user_id'] ?? 1),
            ':dept'  => AUTO_REORDER_DEPARTMENT,
            ':ing'   => $ingredient_id,
            ':title' => 'Auto-reorder — ' . $item['name'],
            ':notes' => sprintf(
                'Generated automatically. Stock fell to %s %s (threshold %s %s). %s',
                rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.'), $item['unit'],
                rtrim(rtrim(number_format((float)$item['reorder_at'], 2), '0'), '.'), $item['unit'],
                $cost_note
            ),
            ':total' => $total,
        ]);
        $req_id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO requisition_items
                (requisition_id, ingredient_id, item_name, quantity, unit, est_unit_price)
             VALUES (:r, :ing, :n, :q, :u, :p)'
        )->execute([
            ':r' => $req_id, ':ing' => $ingredient_id, ':n' => $item['name'],
            ':q' => $qty, ':u' => $item['unit'], ':p' => $unit_price,
        ]);

        $pdo->prepare('UPDATE ingredients SET last_auto_reorder_at = NOW() WHERE id = :id')
            ->execute([':id' => $ingredient_id]);

        audit_log('requisition', $req_id, 'auto_created',
            'Auto-reorder triggered for ' . $item['name'], $actor_id);

        notify_event(
            action_type: 'INVENTORY_AUTO_REORDER',
            perm_key:    'procurement.requisitions',
            title:       'Auto-reorder filed — ' . $item['name'],
            message:     sprintf('Stock hit %s %s. Requested %s %s from the default supplier.',
                            rtrim(rtrim(number_format((float)$item['quantity'], 2), '0'), '.'), $item['unit'],
                            rtrim(rtrim(number_format($qty, 2), '0'), '.'), $item['unit']),
            target_url:  'requisitions.php?id=' . $req_id,
            entity_type: 'requisition',
            entity_id:   $req_id,
            actor_id:    null   // system-generated
        );

        return $req_id;
    } catch (Throwable $e) {
        error_log('check_and_trigger_reorder failed: ' . $e->getMessage());
        return 0;
    }
}

/** Run the threshold check across every active ingredient. */
function run_reorder_sweep(?int $actor_id = null): array {
    $created = [];
    try {
        $ids = get_db()->query(
            'SELECT id FROM ingredients
              WHERE archived_at IS NULL AND auto_reorder = 1
                AND reorder_at > 0 AND quantity <= reorder_at'
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $req = check_and_trigger_reorder((int)$id, $actor_id);
            if ($req) $created[] = $req;
        }
    } catch (Throwable $e) {
        error_log('run_reorder_sweep failed: ' . $e->getMessage());
    }
    return $created;
}

/** Count of auto-reorders still awaiting action — for the metric card. */
function pending_reorder_count(): int {
    try {
        return (int)get_db()->query(
            "SELECT COUNT(*) FROM purchase_requisitions
              WHERE source = 'auto_reorder' AND status NOT IN ('rejected','closed')"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ═══════════════════════════════════════════════
//  EXPIRY WARNINGS
// ═══════════════════════════════════════════════

/**
 * Notify inventory staff about batches crossing into the amber window.
 * Deduplicated per batch per day via the notifications table itself.
 */
function dispatch_expiry_warnings(): int {
    $sent = 0;
    try {
        $rows = get_db()->prepare(
            "SELECT b.id, b.expiry_date, b.qty_remaining, b.unit, i.name
               FROM ingredient_batches b
               JOIN ingredients i ON i.id = b.ingredient_id
              WHERE b.status = 'active'
                AND b.qty_remaining > 0
                AND b.expiry_date IS NOT NULL
                AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :d DAY)
                AND NOT EXISTS (
                      SELECT 1 FROM notifications n
                       WHERE n.action_type = 'INVENTORY_EXPIRY_WARNING'
                         AND n.entity_type = 'batch'
                         AND n.entity_id   = b.id
                         AND DATE(n.created_at) = CURDATE()
                )"
        );
        $rows->execute([':d' => EXPIRY_WARNING_DAYS]);

        foreach ($rows->fetchAll() as $b) {
            notify_event(
                action_type: 'INVENTORY_EXPIRY_WARNING',
                perm_key:    'inventory.view',
                title:       'Expiring soon — ' . $b['name'],
                message:     sprintf('%s %s expires on %s.',
                                rtrim(rtrim(number_format((float)$b['qty_remaining'], 2), '0'), '.'),
                                $b['unit'], date('M d, Y', strtotime($b['expiry_date']))),
                target_url:  'inventory.php?status=expiring',
                entity_type: 'batch',
                entity_id:   (int)$b['id'],
                actor_id:    null
            );
            $sent++;
        }
    } catch (Throwable $e) {
        error_log('dispatch_expiry_warnings failed: ' . $e->getMessage());
    }
    return $sent;
}