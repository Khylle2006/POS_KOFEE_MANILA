<?php
// ─────────────────────────────────────────────────────────────
//  includes/security.php
//  Session hardening, CSRF tokens, security headers,
//  and a persistent login throttle.
//
//  MUST be required before session_start() runs, so load it
//  first inside includes/auth.php.
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';

// ═══════════════════════════════════════════════
//  SESSION HARDENING
// ═══════════════════════════════════════════════

/**
 * Configure cookie flags, then start the session.
 * Idempotent — safe to call from anywhere.
 */
function secure_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,     // only sent over TLS in production
        'httponly' => true,       // JavaScript cannot read the session id
        'samesite' => 'Lax',      // blocks cross-site POST with cookies
    ]);

    ini_set('session.use_strict_mode', '1');   // reject attacker-supplied ids
    ini_set('session.use_only_cookies', '1');

    session_start();

    // Rotate the id periodically so a leaked one has a short life.
    if (empty($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}

/** Baseline response headers. Call once per HTML page render. */
function send_security_headers(): void {
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    header_remove('X-Powered-By');
}

// ═══════════════════════════════════════════════
//  CSRF
// ═══════════════════════════════════════════════

if (!defined('CSRF_FIELD')) define('CSRF_FIELD', '_csrf');

/** The current session's CSRF token, minted on first use. */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) secure_session_start();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/** Hidden input for any HTML form that POSTs. */
function csrf_field(): string {
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Meta tag so fetch() calls can read the token. Put it in <head>. */
function csrf_meta(): string {
    return '<meta name="csrf-token" content="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Is the incoming request's token valid?
 * Accepts the token from a form field, a JSON body, or the
 * X-CSRF-Token header (used by fetch()).
 */
function csrf_verify(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') return true;
    if (session_status() === PHP_SESSION_NONE) secure_session_start();

    $expected = $_SESSION['_csrf_token'] ?? '';
    if ($expected === '') return false;

    $supplied = $_POST[CSRF_FIELD]
             ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    // JSON bodies: peek without consuming the stream for the caller.
    if ($supplied === '') {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $json = json_decode($raw, true);
            $supplied = is_array($json) ? ($json[CSRF_FIELD] ?? '') : '';
        }
    }

    return is_string($supplied) && $supplied !== '' && hash_equals($expected, $supplied);
}

/** Hard stop for HTML pages when the token is missing or wrong. */
function require_csrf(): void {
    if (csrf_verify()) return;

    http_response_code(419);
    die('<!DOCTYPE html><meta charset="utf-8"><title>Session expired</title>'
      . '<div style="font:15px/1.6 system-ui;max-width:420px;margin:15vh auto;text-align:center">'
      . '<h1 style="font-size:19px">Session expired</h1>'
      . '<p>Your form token is no longer valid. Reload the page and try again.</p>'
      . '<p><a href="javascript:history.back()">Go back</a></p></div>');
}

/** Hard stop for JSON endpoints. */
function require_csrf_json(): void {
    if (csrf_verify()) return;

    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false, 'success' => false,
        'error' => 'Security token expired. Reload the page and try again.',
        'code'  => 'CSRF_INVALID',
    ]);
    exit;
}

// ═══════════════════════════════════════════════
//  LOGIN THROTTLE (persistent)
// ═══════════════════════════════════════════════

if (!defined('THROTTLE_MAX_ATTEMPTS')) define('THROTTLE_MAX_ATTEMPTS', 6);
if (!defined('THROTTLE_WINDOW_MIN'))   define('THROTTLE_WINDOW_MIN', 15);
if (!defined('THROTTLE_LOCKOUT_MIN'))  define('THROTTLE_LOCKOUT_MIN', 10);

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function record_login_attempt(string $identifier, bool $succeeded): void {
    try {
        get_db()->prepare(
            'INSERT INTO auth_throttle (identifier, ip_address, succeeded)
             VALUES (:i, :ip, :s)'
        )->execute([
            ':i'  => strtolower(substr(trim($identifier), 0, 190)),
            ':ip' => client_ip(),
            ':s'  => $succeeded ? 1 : 0,
        ]);

        // Clear the counter for this identifier on success.
        if ($succeeded) {
            get_db()->prepare(
                'DELETE FROM auth_throttle
                  WHERE identifier = :i AND succeeded = 0'
            )->execute([':i' => strtolower(trim($identifier))]);
        }

        // Opportunistic cleanup, roughly 1 request in 50.
        if (random_int(1, 50) === 1) {
            get_db()->exec(
                'DELETE FROM auth_throttle
                  WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
        }
    } catch (Throwable $e) {
        error_log('record_login_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Seconds the caller must wait, or 0 when they may try now.
 * Counts failures by username AND by IP, so neither a cookie
 * reset nor username rotation gets around it.
 */
function login_lockout_seconds(string $identifier): int {
    try {
        $stmt = get_db()->prepare(
            'SELECT MAX(attempted_at) AS last_try, COUNT(*) AS failures
               FROM auth_throttle
              WHERE succeeded = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL :win MINUTE)
                AND (identifier = :i OR ip_address = :ip)'
        );
        $stmt->execute([
            ':win' => THROTTLE_WINDOW_MIN,
            ':i'   => strtolower(trim($identifier)),
            ':ip'  => client_ip(),
        ]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['failures'] < THROTTLE_MAX_ATTEMPTS) return 0;

        $unlock = strtotime($row['last_try']) + (THROTTLE_LOCKOUT_MIN * 60);
        return max(0, $unlock - time());
    } catch (Throwable $e) {
        error_log('login_lockout_seconds failed: ' . $e->getMessage());
        return 0;   // never lock everyone out because of a DB hiccup
    }
}

// ═══════════════════════════════════════════════
//  MISC HELPERS
// ═══════════════════════════════════════════════

/** Escape for HTML output. Short name because it is used constantly. */
function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Only allow redirects to paths inside this app. */
function safe_redirect(string $path): never {
    if (preg_match('#^(https?:)?//#i', $path)) $path = 'dashboard.php';
    header('Location: ' . $path);
    exit;
}

/**
 * Real MIME type of an uploaded file, via magic bytes rather
 * than the client-supplied type or the filename extension.
 */
function detect_upload_mime(string $tmp_path): string {
    if (!is_file($tmp_path)) return '';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) return '';
    $mime = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);
    return $mime ?: '';
}