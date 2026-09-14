<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');

header('Content-Type: application/json');

$pdo = get_db();

// ── GET: fetch a product's recipe (both sizes) + the ingredient picklist ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing product_id.']);
        exit;
    }

    $ingredients = $pdo->query("
        SELECT i.id, i.name, i.unit, i.quantity, c.name AS cat_name
        FROM ingredients i
        LEFT JOIN ingredient_categories c ON c.id = i.cat_id
        WHERE i.archived_at IS NULL
        ORDER BY c.name, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT pi.size, pi.ingredient_id, pi.qty_used, i.name, i.unit
        FROM product_ingredients pi
        JOIN ingredients i ON i.id = pi.ingredient_id
        WHERE pi.product_id = :pid
        ORDER BY pi.size, i.name
    ");
    $stmt->execute([':pid' => $product_id]);

    $recipe = ['small' => [], 'large' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipe[$row['size']][] = [
            'ingredient_id' => (int)$row['ingredient_id'],
            'name'          => $row['name'],
            'unit'          => $row['unit'],
            'qty_used'      => (float)$row['qty_used'],
        ];
    }

    echo json_encode(['ok' => true, 'ingredients' => $ingredients, 'recipe' => $recipe]);
    exit;
}

// ── POST: replace a product's recipe for one size ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON input.']);
        exit;
    }

    $product_id = (int)($data['product_id'] ?? 0);
    $size       = ($data['size'] ?? '') === 'large' ? 'large' : 'small';
    $lines      = $data['ingredients'] ?? [];

    if (!$product_id) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing product_id.']);
        exit;
    }
    if (!is_array($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid ingredients list.']);
        exit;
    }

    // De-dupe + validate lines before touching the database.
    $clean = [];
    foreach ($lines as $line) {
        $ingredient_id = (int)($line['ingredient_id'] ?? 0);
        $qty_used      = (float)($line['qty_used'] ?? 0);
        if ($ingredient_id <= 0 || $qty_used <= 0) {
            continue; // skip blank/zero rows the UI may send
        }
        $clean[$ingredient_id] = $qty_used; // last one wins if duplicated
    }

    try {
        $pdo->beginTransaction();

        $product_check = $pdo->prepare('SELECT id FROM products WHERE id = :id');
        $product_check->execute([':id' => $product_id]);
        if (!$product_check->fetchColumn()) {
            throw new Exception('Product not found.');
        }

        $pdo->prepare('DELETE FROM product_ingredients WHERE product_id = :pid AND size = :size')
            ->execute([':pid' => $product_id, ':size' => $size]);

        if (!empty($clean)) {
            $insert = $pdo->prepare("
                INSERT INTO product_ingredients (product_id, size, ingredient_id, qty_used)
                VALUES (:pid, :size, :ingredient_id, :qty_used)
            ");
            foreach ($clean as $ingredient_id => $qty_used) {
                $insert->execute([
                    ':pid'           => $product_id,
                    ':size'          => $size,
                    ':ingredient_id' => $ingredient_id,
                    ':qty_used'      => $qty_used,
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'saved' => count($clean)]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);