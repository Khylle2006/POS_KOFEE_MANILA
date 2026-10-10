<?php
declare(strict_types=1);
require_once __DIR__ . '/account_security.php';

/** @return array{methods:list<string>, permissions:list<string>, sensitive:bool} */
function api_request_policy(string $route, string $action): array
{
    $read = ['get_menu.php' => ['orders.new'], 'get_orders.php' => ['orders.pending'],
        'get_history.php' => ['orders.history'], 'get_analytics.php' => ['analytics.view'],
        'get_ingredients.php' => ['inventory.view', 'menu.manage'], 'check_paymongo_status.php' => ['orders.new', 'orders.history']];
    $write = ['checkout.php' => ['orders.new'], 'save_order.php' => ['orders.new'], 'place_order.php' => ['orders.new'],
        'create_paymongo_checkout.php' => ['orders.new'], 'cancel_paymongo_order.php' => ['orders.new'],
        'cart_stock.php' => ['orders.new'], 'updated_order_status.php' => ['orders.pending'],
        'save_item.php' => ['menu.manage'],
        'manage_roles.php' => ['permissions.manage'], 'save_permissions.php' => ['permissions.manage'],
        'update_permissions.php' => ['permissions.manage'], 'mark_attendance.php' => ['employee_dashboard.view'],
        'shift_clock.php' => ['employee_dashboard.view'], 'submit_leave.php' => ['employee_dashboard.view'],
        'reauthenticate.php' => [], 'revoke_sessions.php' => []];
    if (isset($read[$route])) {
        return ['methods' => ['GET'], 'permissions' => $read[$route], 'sensitive' => false];
    }
    if (isset($write[$route])) {
        return ['methods' => ['POST'], 'permissions' => $write[$route],
            'sensitive' => in_array($route, ['manage_roles.php', 'save_permissions.php', 'update_permissions.php', 'revoke_sessions.php'], true)];
    }
    if ($route === 'add_item.php') return ['methods' => ['GET', 'POST'], 'permissions' => ['menu.manage'], 'sensitive' => false];
    if ($route === 'recipe.php') {
        return ['methods' => ['GET', 'POST'], 'permissions' => ['menu.manage'], 'sensitive' => false];
    }
    if ($route === 'clear_cache.php') {
        return ['methods' => $action === 'stats' || $action === '' ? ['GET'] : ['POST'], 'permissions' => ['store.manage'], 'sensitive' => false];
    }
    if ($route === 'inventory_batches.php') {
        return ['methods' => in_array($action, ['list', 'expiring', 'summary'], true) ? ['GET', 'POST'] : ['POST'],
            'permissions' => ['inventory.view'], 'sensitive' => false];
    }
    if ($route === 'notifications.php') {
        return ['methods' => ['POST'], 'permissions' => [], 'sensitive' => false];
    }
    if ($route === 'payroll.php') {
        $reads = ['export_csv', 'get_employee_roster_preview', 'list_adjustments', 'paymongo_validate_payout'];
        $sensitive = ['approve', 'release', 'release_individual', 'batch_release_selected', 'finance_review',
            'paymongo_dispatch_payout', 'paymongo_retry_item', 'paymongo_test_connection', 'update_settings', 'update_payslip_payment_method'];
        $permissions = ['create_period' => 'payroll.manage', 'create_step_payroll_run' => 'payroll.manage',
            'calculate' => 'payroll.manage', 'delete_period' => 'payroll.manage', 'update_payslip_payment_method' => 'payroll.manage',
            'approve' => 'payroll.approve', 'finance_review' => 'payroll.approve', 'release' => 'payroll.release',
            'release_individual' => 'payroll.release', 'batch_release_selected' => 'payroll.release',
            'paymongo_dispatch_payout' => 'payroll.release', 'paymongo_retry_item' => 'payroll.release',
            'paymongo_test_connection' => 'payroll.settings', 'update_settings' => 'payroll.settings',
            'add_loan' => 'payroll.loans', 'issue_loan' => 'payroll.loans', 'update_loan_status' => 'payroll.loans',
            'request_advance' => 'payroll.advance.request', 'add_adjustment' => 'payroll.manage', 'remove_adjustment' => 'payroll.manage'];
        return ['methods' => in_array($action, $reads, true) ? ['GET', 'POST'] : ['POST'],
            'permissions' => [$permissions[$action] ?? 'payroll.view'],
            'sensitive' => in_array($action, $sensitive, true)];
    }
    throw new SecurityFault('ROUTE_POLICY_MISSING', 'Endpoint is unavailable.', 503);
}

function guard_authenticated_request(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    send_security_headers();
    require_login();
    // Fresh roles and permissions once per request; never preserve a stale admin bypass.
    sync_user_session_permissions();
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $route = basename($script);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $data = $method === 'GET' ? $_GET : request_data();
    if ($method !== 'GET') require_csrf_json();
    $action = is_string($data['action'] ?? '') ? ($data['action'] ?? '') : '';
    if ((str_contains($script, '/api/') || in_array($route, ['update_permissions.php', 'save_item.php'], true))) {
        $policy = api_request_policy($route, $action);
        require_method(...$policy['methods']);
        if ($policy['permissions'] !== [] && !array_filter($policy['permissions'], 'has_permission')) {
            throw new SecurityFault('PERMISSION_DENIED', 'You do not have permission to do that.', 403);
        }
        if ($policy['sensitive']) {
            require_recent_password();
        }
    } else {
        require_method('GET', 'POST');
        if ($method === 'POST' && $route === 'finance_purchase_approvals.php' && $action === 'save_settings') require_permission('procurement.finance.review');
        if ($method === 'POST' && $route === 'leave_requests.php' && $action === 'review') require_permission('leave.manage');
        if ($method === 'POST' && $route === 'attendance.php') require_permission('attendance.manage');
        if ($method === 'POST' && $route === 'profile.php' && $action === 'hr_review_request') require_permission('employee.payment.manage');
        if ($route === 'manage_users.php' || $route === 'manage_permissions.php'
            || ($route === 'profile.php' && in_array($action, ['request_payment_change', 'hr_review_request'], true))
            || ($route === 'payments.php' && $method === 'POST')
            || ($route === 'suppliers.php' && $method === 'POST')) {
            if ($method === 'POST') {
                require_recent_password();
            }
        }
    }
    if ($method !== 'GET') {
        require_csrf_json();
        if ($route !== 'reauthenticate.php') {
            security_audit(get_db(), 'mutation_requested', 'route', null);
        }
    }
}

/** Claim before financial work: a crash requires review rather than blind resubmission. */
function claim_financial_request(PDO $pdo, string $scope, array $data): void
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $data['idempotency_key'] ?? '';
    if (!is_string($key)) throw new SecurityFault('IDEMPOTENCY_KEY_REQUIRED', 'A request key is required.', 400);
    $actor = (int)$_SESSION['user_id'];
    unset($data['idempotency_key']);
    $pdo->beginTransaction();
    try {
        $previous = begin_idempotency($pdo, $scope, $actor, $key, $data);
        if ($previous !== null) {
            $pdo->commit();
            if (!empty($previous['processing'])) throw new SecurityFault('FINANCIAL_REQUEST_PENDING', 'This request requires reconciliation before another submission.', 409);
            http_response_code($previous['status']);
            if (isset($previous['redirect'])) header('Location: ' . $previous['redirect']);
            else { header('Content-Type: application/json'); echo json_encode($previous['body'], JSON_THROW_ON_ERROR); }
            exit;
        }
        finish_idempotency($pdo, $scope, $actor, $key, ['processing' => true]);
        $pdo->commit();
        $GLOBALS['financial_request'] = [$scope, $actor, $key];
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function complete_financial_request(PDO $pdo, array $payload): void
{
    if (isset($GLOBALS['financial_request'])) {
        [$scope, $actor, $key] = $GLOBALS['financial_request'];
        finish_idempotency($pdo, $scope, $actor, $key, $payload);
    }
}

function form_attribute(string $markup, string $name): ?string
{
    if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $markup, $match, PREG_UNMATCHED_AS_NULL)) {
        return null;
    }
    return html_entity_decode($match[1] ?? $match[2] ?? $match[3] ?? '', ENT_QUOTES, 'UTF-8');
}

function form_posts_locally(string $markup): bool
{
    if (strtolower(form_attribute($markup, 'method') ?? '') !== 'post') return false;
    $action = str_replace('\\', '/', trim(form_attribute($markup, 'action') ?? ''));
    $parts = parse_url($action);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return false;
    $base = parse_url(app_url());
    $scheme = $parts['scheme'] ?? $base['scheme'];
    if (!in_array($scheme, ['http', 'https'], true)) return false;
    if (!isset($parts['host'])) return !isset($parts['scheme']);
    return strtolower($parts['host']) === strtolower($base['host']) && $scheme === $base['scheme']
        && ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) === ($base['port'] ?? ($base['scheme'] === 'https' ? 443 : 80));
}

/** Apply browser token plumbing to existing server-rendered forms without duplicating their markup. */
function browser_security_output(string $output): string
{
    if (preg_match('/<head(?:\s[^>]*)?>/i', $output)) {
        $secJsPath = __DIR__ . '/../js/security.js';
        $secJsVer = file_exists($secJsPath) ? filemtime($secJsPath) : time();
        $bootstrap = csrf_meta() . '<script src="' . htmlspecialchars(app_url() . '/js/security.js?v=' . $secJsVer, ENT_QUOTES, 'UTF-8') . '"></script>';
        $output = preg_replace('/(<head(?:\s[^>]*)?>)/i', '$1' . $bootstrap, $output, 1) ?? $output;
        $output = preg_replace_callback('/<link\b([^>]*\bhref=["\'])([^"\'>]+\.css)(["\'][^>]*)>/i', static function (array $m): string {
            $href = $m[2];
            if (strpos($href, '?') !== false) return $m[0];
            $cleanHref = preg_replace('/^\.\.\//', '', $href);
            $cleanHref = ltrim($cleanHref, '/');
            $localPath = __DIR__ . '/../' . $cleanHref;
            if (file_exists($localPath)) {
                $ver = filemtime($localPath);
                return '<link' . $m[1] . $m[2] . '?v=' . $ver . $m[3] . '>';
            }
            return $m[0];
        }, $output) ?? $output;
        $output = preg_replace_callback('/(<form\b[^>]*>)([\s\S]*?)(<\/form\s*>)/i', static function (array $match): string {
            if (!form_posts_locally($match[1])) return $match[0];
            // A submit button can send the entire form to another origin.
            if (preg_match('/\sformaction\s*=/i', $match[2])) {
                preg_match_all('/<(?:button|input)\b[^>]*>/i', $match[2], $buttons);
                foreach ($buttons[0] as $button) {
                    $action = form_attribute($button, 'formaction');
                    if ($action !== null && !form_posts_locally('<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">')) return $match[0];
                }
            }
            $fields = '';
            if (!preg_match('/\sname\s*=\s*(?:["\x27](?:_csrf|csrf_token)["\x27]|(?:_csrf|csrf_token)(?=\s|>))/i', $match[2])) $fields .= csrf_field();
            if (!preg_match('/\sname\s*=\s*(?:["\x27]idempotency_key["\x27]|idempotency_key(?=\s|>))/i', $match[2])) $fields .= '<input type="hidden" name="idempotency_key" value="' . bin2hex(random_bytes(24)) . '">';
            return $match[1] . $fields . $match[2] . $match[3];
        }, $output) ?? $output;
        // Private uploaded image URLs must use the same authorized reader as documents.
        $output = preg_replace_callback('/((?:src|href)=["\x27])(?:\.\.\/)?(uploads\/(?:avatars|attendance|invoices|receipts|supplier_permits|resumes)\/[^"\x27<>]+)(["\x27])/i',
            static fn(array $m): string => $m[1] . htmlspecialchars(app_url() . '/php/download_file.php?f=' . rawurlencode(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') . $m[3], $output) ?? $output;
    } else {
        $value = json_decode($output, true);
        if (is_array($value) && (($value['ok'] ?? null) === false || ($value['success'] ?? null) === false)) {
            $value['ok'] = false;
            $value['success'] = false;
            $value['code'] ??= 'REQUEST_FAILED';
            $value['request_id'] = request_id();
            if (http_response_code() < 400) {
                http_response_code(422);
            }
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
    }
    return $output;
}

function start_browser_security_output(): void
{
    static $started = false;
    if (!$started && PHP_SAPI !== 'cli') {
        $started = true;
        ob_start('browser_security_output');
    }
}
