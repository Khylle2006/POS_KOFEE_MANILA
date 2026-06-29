<?php
require_once '../includes/db.php';

$data = json_decode(file_get_contents("php://input"), true);

$items = $data['items'];
$total = $data['total'];
$type = $data['type'];

$sql = "INSERT INTO orders (items, total_amount, order_type, created_at)
        VALUES (?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sds", $items, $total, $type);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false]);
}
?>