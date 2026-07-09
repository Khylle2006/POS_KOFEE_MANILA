<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

header('Content-Type: application/json');

$pdo = get_db();

$stmt = $pdo->query("
    SELECT 
        p.id,
        p.name,
        p.price_small,
        p.price_large,
        p.stock,
        c.category_name
    FROM products p
    JOIN categories c ON CAST(c.id AS CHAR) = p.category_id
    WHERE p.stock > 0
    ORDER BY c.category_name, p.name
");

echo json_encode($stmt->fetchAll());