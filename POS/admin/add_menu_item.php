<?php
require_once '../includes/db.php';

$db = get_db();

$data = json_decode(file_get_contents("php://input"), true);

$stmt = $db->prepare("
INSERT INTO products (name, category_id, price_small, price_large, description)
VALUES (?, ?, ?, ?, ?)
");

$stmt->execute([
    $data['name'],
    $data['category'],
    $data['price_small'],
    $data['price_large'],
    $data['description']
]);

echo json_encode(["success" => true]);