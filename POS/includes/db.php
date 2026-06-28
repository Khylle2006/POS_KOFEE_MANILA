<?php
// ─────────────────────────────────────────────
//  includes/db.php  —  Database connection
//  Edit the four constants to match your setup.
// ─────────────────────────────────────────────

define('DB_HOST',    'localhost');
define('DB_NAME',    'kofeedb');
define('DB_USER',    'root');    // your MySQL username
define('DB_PASS',    '');        // your MySQL password
define('DB_CHARSET', 'utf8mb4');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection failed. Check your configuration.');
        }
    }
    return $pdo;
}