<?php
// ─────────────────────────────────────────────
//  includes/db.php  —  Database connection
//
//  Credentials are read from the environment first so that
//  nothing sensitive is ever committed. For local XAMPP, copy
//  config.local.php.example to config.local.php (gitignored)
//  and set your values there.
// ─────────────────────────────────────────────

// Optional local override — keep this file out of version control.
$local_config = __DIR__ . '/config.local.php';
if (is_file($local_config)) {
    require_once $local_config;
}

if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'kofeedb');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'root');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Set APP_ENV=production on the live server. Controls whether
// raw error text is ever shown to a visitor.
if (!defined('APP_ENV')) define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Ensure system timezone is aligned with Kofee Manila (Asia/Manila, UTC+8)
if (date_default_timezone_get() !== 'Asia/Manila') {
    date_default_timezone_set('Asia/Manila');
}

function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);

            // Never leak the DSN, credentials or driver error to a visitor.
            die(APP_ENV === 'production'
                ? 'Service temporarily unavailable.'
                : 'Database connection failed. Check includes/config.local.php.');
        }
    }

    return $pdo;
}