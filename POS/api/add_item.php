<?php 

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once '../includes/shift_guard.php';
require_once '../includes/notify.php';
require_login();
require_permission('menu.manage');


$pdo = get_db();

// Add products
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    header('Content-Type: application/json');

     if ($action === 'add') {
        if (!has_permission('menu.edit')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to add menu items.']);
            exit;
        }

        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']           ?? '');
        $desc        = trim($_POST['description']    ?? '');
        $price_small = (float)($_POST['price_small'] ?? 0);
        $price_large = (float)($_POST['price_large'] ?? 0);

        if (!$cat_id || !$name) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Category and name are required.']);
            exit;
        }

        try {
            $pdo->prepare("
                INSERT INTO products (category_id, name, description, price_small, price_large, stock)
                VALUES (:cat, :name, :desc, :ps, :pl, 1)
            ")->execute([
                ':cat'  => $cat_id,
                ':name' => $name,
                ':desc' => $desc,
                ':ps'   => $price_small,
                ':pl'   => $price_large,
            ]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Check permissions
    if (in_array($action, ['edit', 'toggle'], true) && !has_permission('menu.edit')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit menu items.']);
        exit;
    }
    if ($action === 'delete' && !has_permission('menu.delete')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to delete menu items.']);
        exit;
    }

    // Edit products
    if ($action === 'edit') {
        $id          = (int)($_POST['id']           ?? 0);
        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']          ?? '');
        $desc        = trim($_POST['description']   ?? '');
        $price_small = (float)($_POST['price_small']?? 0);
        $price_large = (float)($_POST['price_large']?? 0);

        if (!$id || !$name) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Missing fields.']); exit; }

        try {
            $pdo->prepare(
                'UPDATE products SET name=:n, description=:d, price_small=:ps, price_large=:pl, category_id=:c WHERE id=:id'
            )->execute([':n'=>$name,':d'=>$desc,':ps'=>$price_small,':pl'=>$price_large,':c'=>(string)$cat_id,':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // Toggle availability
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        // Stock controls availability
        try {
            $curr = $pdo->prepare('SELECT stock FROM products WHERE id=:id');
            $curr->execute([':id'=>$id]);
            $row = $curr->fetch();
            $new_stock = ($row['stock'] > 0) ? 0 : 1;
            $pdo->prepare('UPDATE products SET stock=:s WHERE id=:id')
                ->execute([':s'=>$new_stock,':id'=>$id]);
            echo json_encode(['ok'=>true,'available'=>$new_stock > 0]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // Delete products
    // ── Archive (soft delete). Hard deletion is no longer exposed. ──
    if ($action === 'archive' || $action === 'delete') {   // 'delete' kept as an alias
        if (!has_permission('menu.archive') && !has_permission('menu.delete')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to archive menu items.']);
            exit;
        }
        require_shift_for_api();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid product ID.']);
            exit;
        }

        try {
            $check = $pdo->prepare('SELECT id, name, is_deleted FROM products WHERE id = :id');
            $check->execute([':id' => $id]);
            $row = $check->fetch();

            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Menu item not found.']);
                exit;
            }
            if ((int)$row['is_deleted'] === 1) {
                echo json_encode(['ok' => true, 'message' => 'Item is already archived.']);
                exit;
            }

            $pdo->prepare(
                'UPDATE products
                    SET is_deleted = 1, stock = 0, archived_at = NOW(), archived_by = :by
                  WHERE id = :id'
            )->execute([':by' => (int)$_SESSION['user_id'], ':id' => $id]);

            notify_event(
                action_type: 'MENU_ITEM_ARCHIVED',
                perm_key:    'menu.manage',
                title:       '🗄 Menu item archived — ' . $row['name'],
                message:     'Removed from the active menu and the POS. It can be restored anytime.',
                target_url:  'add_item.php?view=archived',
                entity_type: 'product',
                entity_id:   $id
            );

            echo json_encode([
                'ok'       => true,
                'archived' => true,
                'message'  => '"' . $row['name'] . '" archived. It no longer appears in the POS.',
            ]);
        } catch (Throwable $e) {
            error_log('archive product failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not archive this item.']);
        }
        exit;
    }

    // Restore archived products
        // ── Restore from archive ──
    if ($action === 'restore') {
        if (!has_permission('menu.archive') && !has_permission('menu.edit')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to restore menu items.']);
            exit;
        }
        require_shift_for_api();

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare(
            'UPDATE products
                SET is_deleted = 0, stock = 1, archived_at = NULL, archived_by = NULL
              WHERE id = :id AND is_deleted = 1'
        );
        $stmt->execute([':id' => $id]);

        if (!$stmt->rowCount()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Archived product not found.']);
            exit;
        }

        $name = $pdo->prepare('SELECT name FROM products WHERE id = :id');
        $name->execute([':id' => $id]);

        notify_event(
            action_type: 'MENU_ITEM_RESTORED',
            perm_key:    'menu.manage',
            title:       '↩️ Menu item restored — ' . $name->fetchColumn(),
            message:     'Back on the active menu and available in the POS.',
            target_url:  'add_item.php',
            entity_type: 'product',
            entity_id:   $id
        );

        echo json_encode(['ok' => true, 'message' => 'Product restored to the active menu.']);
        exit;
    }
    // Permanently delete an archived product without order history
        // ── Permanent deletion is intentionally disabled. ──
    // The spec replaces hard deletion with archiving; keeping the rows
    // preserves referential integrity with order_items and sales history.
    if ($action === 'purge') {
        http_response_code(410);
        echo json_encode([
            'ok'    => false,
            'error' => 'Permanent deletion is disabled. Menu items are archived so order history stays intact.',
        ]);
        exit;
    }
}

// Load menu data
$categories = $pdo->query('SELECT id, category_name FROM categories ORDER BY category_name')->fetchAll();
$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
$archived_count = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE is_deleted = 1')->fetchColumn();

// ── Load all products ─────────────────────────
$products = $pdo->query("
    SELECT p.*, c.category_name
    FROM products p
    LEFT JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
    WHERE p.is_deleted = " . ($view === 'archived' ? '1' : '0') . "
    ORDER BY c.category_name, p.name
")->fetchAll();

// Group by category
$grouped = [];
foreach ($products as $p) {
    $grouped[$p['category_name']][] = $p;
}
?>