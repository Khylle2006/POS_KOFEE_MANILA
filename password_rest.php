<?php
require_once 'POS/includes/db.php';

$username    = 'admin'; // change this
$new_password = 'admin123';  // change this

$hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo  = get_db();
$stmt = $pdo->prepare('UPDATE users SET password = :h WHERE username = :u');
$stmt->execute([':h' => $hash, ':u' => $username]);

echo 'Done! Rows updated: ' . $stmt->rowCount();