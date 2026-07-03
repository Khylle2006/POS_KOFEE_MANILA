<?php
require_once '../includes/db.php';

$pdo = get_db();

$stmt = $pdo->query("
    SELECT 
        p.id,
        p.name,
        p.price_small,
        p.price_large,
        c.category_name
    FROM products p
    JOIN categories c ON c.id = p.category_id
");

echo json_encode($stmt->fetchAll());