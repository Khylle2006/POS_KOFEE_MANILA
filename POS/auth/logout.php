<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/account_security.php';
secure_session_start();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Log out — Kofee Manila</title>
        <style>
            body { margin: 0; padding: 24px; min-height: 100vh; box-sizing: border-box; display: grid; place-items: center; font: 16px/1.5 system-ui, sans-serif; background: #faf5ef; color: #2c1a0e; }
            main { width: 100%; max-width: 420px; }
            h1 { font-size: 24px; }
            button { padding: 12px 24px; border: 0; border-radius: 8px; background: #7a4e2e; color: #fff; font: inherit; cursor: pointer; }
        </style>
    </head>
    <body>
        <main>
            <h1>Log out of Kofee Manila?</h1>
            <p>Confirm to end your current session.</p>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit">Log out</button>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit;
}
require_method('POST');
require_csrf_json();
if (!empty($_SESSION['auth_handle'])) get_db()->prepare('UPDATE auth_sessions SET revoked_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', (string)$_SESSION['auth_handle'])]);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'], 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict']);
}
session_destroy();
header('Location: ../index.html?reason=logout', true, 303);
exit;
