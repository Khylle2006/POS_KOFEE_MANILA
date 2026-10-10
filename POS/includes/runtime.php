<?php
declare(strict_types=1);

/** A safe boundary error; technical exception details never enter responses. */
final class SecurityFault extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

function app_setting(string $name, string $default = ''): string
{
    $environment = getenv($name);
    return $environment !== false ? $environment : (defined($name) ? (string)constant($name) : $default);
}

function app_production(): bool
{
    return app_setting('APP_ENV', 'development') === 'production';
}

function app_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proxies = array_filter(array_map('trim', explode(',', app_setting('TRUSTED_PROXIES'))));
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true)
        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function app_url(): string
{
    $url = rtrim(app_setting('APP_URL', app_production() ? '' : 'http://localhost/POS_KOFEE_MANILA/POS'), '/');
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || isset($parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
        || (app_production() && $parts['scheme'] !== 'https')) {
        throw new SecurityFault('CONFIGURATION_INVALID', 'The application URL is not configured securely.', 503);
    }
    return $url;
}

function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(12));
}

function request_is_json(): bool
{
    return str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''), '/api/')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function safe_exception(Throwable $exception): never
{
    $status = $exception instanceof SecurityFault ? $exception->status : 503;
    $code = $exception instanceof SecurityFault ? $exception->errorCode : 'SERVICE_UNAVAILABLE';
    $message = $exception instanceof SecurityFault ? $exception->getMessage() : 'Service temporarily unavailable.';
    error_log('request=' . request_id() . ' exception=' . get_class($exception) . ' code=' . $code);
    http_response_code($status);
    if (request_is_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $message, 'code' => $code, 'request_id' => request_id()]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Request failed</title><h1>Request failed</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>Reference: ' . request_id() . '</p>';
    }
    exit;
}

/** @return array<string, mixed> */
function request_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) {
            throw new SecurityFault('PAYLOAD_TOO_LARGE', 'Request is too large.', 413);
        }
        try {
            $raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
            if ($raw === false) throw new SecurityFault('REQUEST_UNAVAILABLE', 'Request could not be read.', 400);
            if (strlen($raw) > 8 * 1024 * 1024) throw new SecurityFault('PAYLOAD_TOO_LARGE', 'Request is too large.', 413);
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SecurityFault('JSON_INVALID', 'Invalid JSON request.', 400);
        }
        if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) {
            throw new SecurityFault('JSON_INVALID', 'A JSON object is required.', 400);
        }
        return $data = $value;
    }
    return $data = $_POST;
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        throw new SecurityFault('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
    }
}

function require_runtime_schema(PDO $pdo): void
{
    if (defined('APP_MIGRATING') && APP_MIGRATING && PHP_SAPI === 'cli') {
        return;
    }
    static $checked = false;
    if (!$checked) {
        try {
            $ready = $pdo->query("SELECT 1 FROM app_migrations WHERE version = '20261008_security_v1'")->fetchColumn();
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) !== '42S02') {
                throw $exception;
            }
            throw new SecurityFault('MIGRATION_REQUIRED', 'Database maintenance is required before sign-in can continue.', 503);
        }
        if (!$ready) {
            throw new SecurityFault('MIGRATION_REQUIRED', 'Database maintenance is required before sign-in can continue.', 503);
        }
        $checked = true;
    }
}

/** @param array<string, scalar|null> $details */
function security_audit(PDO $pdo, string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    // Keep an allowlist: caller-supplied secrets or personal fields cannot be persisted.
    $safe = array_intersect_key($details, array_flip(['status', 'previous_status', 'permission', 'decision', 'reason_code', 'count']));
    $pdo->prepare('INSERT INTO security_audit (actor_id, action, entity_type, entity_id, request_id, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$_SESSION['user_id'] ?? null, $action, $entity, $entityId, request_id(), json_encode($safe, JSON_THROW_ON_ERROR)]);
}

function payment_mode(): string
{
    $mode = app_setting('PAYMENT_MODE', 'disabled');
    if (!in_array($mode, ['disabled', 'demo', 'test', 'live'], true) || (app_production() && !in_array($mode, ['disabled', 'live'], true))) {
        throw new SecurityFault('PAYMENT_CONFIGURATION_INVALID', 'Payment mode is not configured securely.', 503);
    }
    return $mode;
}

function require_demo_payment(): void
{
    if (app_production() || payment_mode() !== 'demo') {
        throw new SecurityFault('NOT_FOUND', 'Not found.', 404);
    }
}

function verify_paymongo_signature(string $payload, string $header, string $secret, string $mode, ?int $now = null): void
{
    if ($secret === '' || str_contains(strtolower($secret), 'demo') || !in_array($mode, ['test', 'live'], true)) {
        throw new SecurityFault('WEBHOOK_NOT_CONFIGURED', 'Webhook not configured.', 503);
    }
    $parts = [];
    foreach (explode(',', $header) as $pair) {
        $entry = explode('=', trim($pair), 2);
        if (count($entry) !== 2 || isset($parts[$entry[0]])) {
            throw new SecurityFault('SIGNATURE_INVALID', 'Invalid webhook signature.', 401);
        }
        $parts[$entry[0]] = $entry[1];
    }
    $timestamp = $parts['t'] ?? '';
    $signature = $parts[$mode === 'live' ? 'li' : 'te'] ?? '';
    if (!ctype_digit($timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300
        || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), $signature)) {
        throw new SecurityFault('SIGNATURE_INVALID', 'Invalid webhook signature.', 401);
    }
}

function new_password_error(string $password): ?string
{
    $length = preg_match_all('/./us', $password);
    return $length === false || $length < 15 || strlen($password) > 72 || str_contains($password, "\0")
        ? 'Use at least 15 characters and no more than 72 UTF-8 bytes.' : null;
}

/** Match the work factor of newly issued credentials even for an unknown account. */
function verify_login_password(string $password, ?string $stored): bool
{
    $dummy = '$2y$12$GoxQkwtfTm/COCX9KitQee9WlPFnc7VqSn.EaI08i1GiudinXqnmK';
    if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
        return false;
    }
    $verified = password_verify($password, $stored ?? $dummy);
    return $stored !== null && $verified;
}

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    set_exception_handler('safe_exception');
    header('X-Request-ID: ' . request_id());
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=(self)');
    header_remove('X-Powered-By');
    if (app_production() || app_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if (app_production()) { app_url(); payment_mode(); }
}
