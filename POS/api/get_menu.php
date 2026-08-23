<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('orders.new');

header('Content-Type: application/json');

$pdo = get_db();
<<<<<<< HEAD
=======
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
$pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0");
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1

$stmt = $pdo->query("
    SELECT 
        p.id,
        p.name,
        p.price_small,
        p.price_large,
        p.stock,
<<<<<<< HEAD
        c.category_name
    FROM products p
    JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
=======
        p.image_path,
        c.category_name
    FROM products p
    JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
    WHERE p.is_deleted = 0
>>>>>>> a4bf73b17bf67d5f6c4e3af0dddabeeb38e2c1b1
    ORDER BY c.category_name, p.name
");

echo json_encode($stmt->fetchAll());