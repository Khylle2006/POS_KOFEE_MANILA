<?php 

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
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
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid product ID.']);
            exit;
      }

      try {
          $pdo->beginTransaction();

          $product = $pdo->prepare(
              'SELECT p.id, p.image_path,
                      EXISTS (SELECT 1 FROM order_items oi WHERE oi.product_id = p.id) AS has_orders
               FROM products p
               WHERE p.id = :id
               FOR UPDATE'
          );
          $product->execute([':id' => $id]);
          $row = $product->fetch();
          if (!$row) {
              throw new RuntimeException('Menu item not found.');
          }

          if ((int)$row['has_orders'] === 1) {
              $pdo->prepare('UPDATE products SET is_deleted = 1, stock = 0 WHERE id = :id')
                  ->execute([':id' => $id]);
              $pdo->commit();
              echo json_encode([
                  'ok' => true,
                  'archived' => true,
                  'message' => 'Item archived because it is used in order history.',
              ]);
              exit;
          }

          try {
              $pdo->prepare('DELETE FROM products WHERE id = :id')->execute([':id' => $id]);
              $pdo->commit();

              if (!empty($row['image_path'])) {
                  $image_file = __DIR__ . '/../assets/' . ltrim($row['image_path'], '/');
                  if (is_file($image_file)) @unlink($image_file);
              }
              echo json_encode(['ok' => true, 'archived' => false, 'message' => 'Item permanently deleted.']);
          } catch (PDOException $deleteError) {
                if (!$pdo->inTransaction()) throw $deleteError;

                // Archive used products
                if ((int)($deleteError->errorInfo[1] ?? 0) !== 1451) throw $deleteError;
                $pdo->prepare('UPDATE products SET is_deleted = 1, stock = 0 WHERE id = :id')
                    ->execute([':id' => $id]);
                $pdo->commit();
                echo json_encode(['ok' => true, 'message' => 'Item archived because it is used in order history.']);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // Restore archived products
    if ($action === 'restore') {
        if (!has_permission('menu.edit')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to restore menu items.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE products SET is_deleted = 0, stock = 1 WHERE id = :id AND is_deleted = 1');
        $stmt->execute([':id' => $id]);
        echo json_encode($stmt->rowCount() ? ['ok' => true, 'message' => 'Product restored.'] : ['ok' => false, 'error' => 'Archived product not found.']);
        exit;
    }

    // Permanently delete an archived product without order history
    if ($action === 'purge') {
        if (!has_permission('menu.delete')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You do not have permission to permanently delete menu items.']);
            exit;
        }
        $id = (int)($_POST['id'] ?? 0);
        $check = $pdo->prepare('SELECT image_path FROM products WHERE id = :id AND is_deleted = 1 AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.product_id = products.id)');
        $check->execute([':id' => $id]);
        $row = $check->fetch();
        if (!$row) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'This product is used in order history and cannot be permanently deleted.']);
            exit;
        }
        $pdo->prepare('DELETE FROM products WHERE id = :id AND is_deleted = 1')->execute([':id' => $id]);
        if (!empty($row['image_path'])) {
            $image_file = __DIR__ . '/../assets/' . ltrim($row['image_path'], '/');
            if (is_file($image_file)) @unlink($image_file);
        }
        echo json_encode(['ok' => true, 'message' => 'Archived product permanently deleted.']);
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