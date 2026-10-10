<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$name = getenv('TEST_DB_NAME') ?: '';
if (!preg_match('/^[A-Za-z0-9_]+_test$/D', $name)) exit(1);
define('APP_MIGRATING', true);
require_once __DIR__ . '/bootstrap.php';
$pdo = new PDO('mysql:host=' . getenv('TEST_DB_HOST') . ';dbname=' . $name . ';charset=utf8mb4', getenv('TEST_DB_USER'), getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET time_zone = '+08:00'");
$GLOBALS['migration_pdo'] = $pdo;
$_SESSION = ['user_id' => 1];
$input = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
try {
    if ($input['operation'] === 'order') $result = submit_order($pdo, $input['data'], 1, $input['key']);
    else $result = ['user_id' => consume_password_reset($pdo, $input['token'], $input['password'])];
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (SecurityFault $error) { echo json_encode(['ok' => false, 'code' => $error->errorCode], JSON_THROW_ON_ERROR); }
