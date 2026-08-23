<?php 

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');


$pdo = get_db();
<<<<<<< HEAD
=======
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0");

function save_product_image(int $product_id, string $field, ?string $old_path = null): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return $old_path;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The image upload failed.');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) throw new RuntimeException('Image must be 5 MB or smaller.');

    $info = @getimagesize($_FILES[$field]['tmp_name']);
    $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($extensions[$info[2]])) throw new RuntimeException('Only JPG, PNG, and WebP images are allowed.');

    $relative = 'menu/' . $product_id . '.' . $extensions[$info[2]];
    $directory = __DIR__ . '/../assets/menu';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('The image folder could not be created.');
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $directory . '/' . basename($relative))) {
        throw new RuntimeException('The image could not be saved.');
    }

    if ($old_path && $old_path !== $relative) {
        $old_file = __DIR__ . '/../assets/' . ltrim($old_path, '/');
        if (is_file($old_file)) @unlink($old_file);
    }
    return $relative;
}
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1

// ── POST: Add new item ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    header('Content-Type: application/json');

    // Adding new items is Inventory's job now — the Menu module only edits/manages
    // existing drinks. Block it here too (not just in the UI) so the endpoint
    // can't be posted to directly.
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
<<<<<<< HEAD
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
=======
            $id = (int)$pdo->lastInsertId();
            $image_path = save_product_image($id, 'image');
            if ($image_path) $pdo->prepare('UPDATE products SET image_path=:image WHERE id=:id')->execute([':image'=>$image_path, ':id'=>$id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Writes/toggling need menu.edit; deletion needs the stronger menu.delete.
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

    // ── Edit ──────────────────────────────────
    if ($action === 'edit') {
        $id          = (int)($_POST['id']           ?? 0);
        $cat_id      = (int)($_POST['category_id']  ?? 0);
        $name        = trim($_POST['name']          ?? '');
        $desc        = trim($_POST['description']   ?? '');
        $price_small = (float)($_POST['price_small']?? 0);
        $price_large = (float)($_POST['price_large']?? 0);

        if (!$id || !$name) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Missing fields.']); exit; }

        try {
<<<<<<< HEAD
            $pdo->prepare(
                'UPDATE products SET name=:n, description=:d, price_small=:ps, price_large=:pl, category_id=:c WHERE id=:id'
            )->execute([':n'=>$name,':d'=>$desc,':ps'=>$price_small,':pl'=>$price_large,':c'=>(string)$cat_id,':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
=======
            $current = $pdo->prepare('SELECT image_path FROM products WHERE id=:id');
            $current->execute([':id'=>$id]);
            $old_path = $current->fetchColumn() ?: null;
            $image_path = save_product_image($id, 'image', $old_path);
            $pdo->prepare(
                'UPDATE products SET name=:n, description=:d, price_small=:ps, price_large=:pl, category_id=:c, image_path=:image WHERE id=:id'
            )->execute([':n'=>$name,':d'=>$desc,':ps'=>$price_small,':pl'=>$price_large,':c'=>(string)$cat_id,':image'=>$image_path,':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Toggle availability ───────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        // stock=1 means available, stock=0 means unavailable
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

    // ── Delete ────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
<<<<<<< HEAD
            $pdo->prepare('DELETE FROM products WHERE id=:id')->execute([':id'=>$id]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
=======
            $image = $pdo->prepare('SELECT image_path FROM products WHERE id=:id');
            $image->execute([':id'=>$id]);
            $image_path = $image->fetchColumn();
            $pdo->prepare('DELETE FROM products WHERE id=:id')->execute([':id'=>$id]);
            if ($image_path) {
                $image_file = __DIR__ . '/../assets/' . ltrim($image_path, '/');
                if (is_file($image_file)) @unlink($image_file);
            }
            echo json_encode(['ok'=>true, 'message'=>'Item deleted.']);
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1451) {
                $pdo->prepare('UPDATE products SET is_deleted=1, stock=0 WHERE id=:id')->execute([':id'=>$id]);
                echo json_encode(['ok'=>true, 'message'=>'Item archived because it is used in order history.']);
                exit;
            }
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
            http_response_code(500);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
}

// ── Load categories ───────────────────────────
$categories = $pdo->query('SELECT id, category_name FROM categories ORDER BY category_name')->fetchAll();

// ── Load all products ─────────────────────────
$products = $pdo->query("
    SELECT p.*, c.category_name
    FROM products p
    LEFT JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
    ORDER BY c.category_name, p.name
")->fetchAll();

// Group by category
$grouped = [];
foreach ($products as $p) {
    $grouped[$p['category_name']][] = $p;
}
?>