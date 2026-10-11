<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MySqlIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $name = getenv('TEST_DB_NAME') ?: '';
        if ($name === '') $this->markTestSkipped('Set TEST_DB_NAME ending in _test and isolated test credentials.');
        if (!preg_match('/^[A-Za-z0-9_]+_test$/D', $name)) throw new RuntimeException('Refusing an unsafe test database name.');
        if (!defined('APP_MIGRATING')) define('APP_MIGRATING', true);
        $this->pdo = new PDO('mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';dbname=' . $name . ';charset=utf8mb4', getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $GLOBALS['migration_pdo'] = $this->pdo;
        $this->pdo->exec("SET time_zone = '+08:00'");
        foreach (self::fixtures() as $sql) $this->pdo->exec($sql);
        migrate_security_v1($this->pdo);
        foreach (['order_items', 'orders', 'ingredient_usage_log', 'product_ingredients', 'products', 'ingredients', 'employees', 'users', 'auth_sessions', 'auth_devices', 'login_authorizations', 'password_resets', 'security_rate_limits', 'security_audit', 'request_idempotency', 'payment_attempts', 'payment_webhook_events', 'background_jobs'] as $table) $this->pdo->exec('TRUNCATE TABLE ' . $table);
        $this->pdo->exec("INSERT INTO users (id, status, password) VALUES (1, 'active', 'unused'), (2, 'inactive', 'unused')");
        $this->pdo->exec("INSERT INTO employees (id, user_id) VALUES (1, 1)");
        $this->pdo->exec("INSERT INTO products (id, name, price_small, price_large, price, is_deleted) VALUES (1, 'Coffee', 100.10, 120.20, 100.10, 0)");
        $this->pdo->exec("INSERT INTO ingredients (id, name, quantity) VALUES (1, 'Coffee beans', 10)");
        $this->pdo->exec("INSERT INTO product_ingredients (product_id, ingredient_id, qty_used, size) VALUES (1, 1, 2, 'small'), (1, 1, 3, 'large')");
        $_SESSION = ['user_id' => 1];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        putenv('PAYMENT_MODE'); putenv('PAYMONGO_WEBHOOK_SECRET');
        $_SESSION = [];
    }

    private static function fixtures(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, status VARCHAR(20), password VARCHAR(255), updated_at DATETIME NULL) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS employees (id INT PRIMARY KEY, user_id INT) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS products (id INT PRIMARY KEY, name VARCHAR(100), price_small DECIMAL(12,2), price_large DECIMAL(12,2), price DECIMAL(12,2), is_deleted TINYINT) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS ingredients (id INT PRIMARY KEY, name VARCHAR(100), quantity DECIMAL(12,3)) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS product_ingredients (product_id INT, ingredient_id INT, qty_used DECIMAL(12,3), size VARCHAR(10)) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS orders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, employee_id INT NULL, total_amount DECIMAL(12,2), payment_method VARCHAR(20), status VARCHAR(20), payment_status VARCHAR(20), amount_tendered DECIMAL(12,2) NULL, change_amount DECIMAL(12,2) NULL, payment_reference VARCHAR(100) NULL, stock_deducted TINYINT, ingredients_deducted_at DATETIME NULL, stock_restored_at DATETIME NULL, paymongo_session_id VARCHAR(100) NULL, paymongo_payment_id VARCHAR(100) NULL, created_at DATETIME, placed_at DATETIME) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS order_items (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, product_id INT, quantity INT, price DECIMAL(12,2), subtotal DECIMAL(12,2), size VARCHAR(10)) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS ingredient_usage_log (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, ingredient_id INT, used_qty DECIMAL(12,3), processed_by INT) ENGINE=InnoDB',
            'CREATE TABLE IF NOT EXISTS login_authorizations (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, status VARCHAR(20), session_created TINYINT DEFAULT 0) ENGINE=InnoDB',
        ];
    }

    private function order(int $quantity = 1): array
    {
        return ['items' => [['id' => 1, 'size' => 'small', 'qty' => $quantity, 'price' => 0.01]], 'payment_method' => 'cash', 'amount_tendered' => '1000.00'];
    }

    public function testCheckoutDeductsOnceAndCalculatesServerPrices(): void
    {
        $first = submit_order($this->pdo, $this->order(), 1, 'checkout-key-00000001');
        $again = submit_order($this->pdo, $this->order(), 1, 'checkout-key-00000001');
        $this->assertSame($first, $again);
        $this->assertSame('100.10', $this->pdo->query('SELECT total_amount FROM orders')->fetchColumn());
        $this->assertSame('8.000', $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM ingredient_usage_log')->fetchColumn());
    }

    public function testConflictingKeyDoesNotCreateAnotherOrder(): void
    {
        submit_order($this->pdo, $this->order(), 1, 'checkout-key-00000001');
        try { submit_order($this->pdo, $this->order(2), 1, 'checkout-key-00000001'); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame(409, $error->status); }
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testInsufficientStockRollsBackOrderItemsAndUsage(): void
    {
        try { submit_order($this->pdo, $this->order(6), 1, 'checkout-key-00000002'); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame('STOCK_INSUFFICIENT', $error->errorCode); }
        foreach (['orders', 'order_items', 'ingredient_usage_log', 'request_idempotency'] as $table) $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
        $this->assertSame('10.000', $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testCancellationRestoresRecordedStockOnce(): void
    {
        $order = submit_order($this->pdo, $this->order(), 1, 'checkout-key-00000003');
        $this->pdo->beginTransaction(); restore_order_stock($this->pdo, $order['order_id']); restore_order_stock($this->pdo, $order['order_id']); $this->pdo->commit();
        $this->assertSame('10.000', $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testWebhookDeduplicationAndLatePaymentException(): void
    {
        require_once __DIR__ . '/../../includes/payment_events.php';
        putenv('PAYMENT_MODE=test'); putenv('PAYMONGO_WEBHOOK_SECRET=test-signature-secret');
        $order = submit_order($this->pdo, $this->order(), 1, 'checkout-key-00000004');
        $this->pdo->prepare("UPDATE orders SET payment_method = 'paymongo', payment_status = 'pending', paymongo_session_id = 'cs_test_1' WHERE id = ?")->execute([$order['order_id']]);
        $event = ['data' => ['id' => 'evt_test_1', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => 'cs_test_1', 'attributes' => ['payments' => [['id' => 'pay_test_1', 'attributes' => ['status' => 'paid', 'currency' => 'PHP', 'amount' => 10010]]]]]]]];
        $body = json_encode($event, JSON_THROW_ON_ERROR); $timestamp = time(); $signature = 't=' . $timestamp . ',te=' . hash_hmac('sha256', $timestamp . '.' . $body, 'test-signature-secret');
        process_payment_webhook($this->pdo, $body, $signature);
        $this->assertTrue(process_payment_webhook($this->pdo, $body, $signature)['duplicate']);
        $row = $this->pdo->query('SELECT status, payment_status FROM orders')->fetch();
        $this->assertSame(['status' => 'pending', 'payment_status' => 'paid'], $row);
        $this->pdo->exec("UPDATE orders SET status = 'cancelled', payment_status = 'failed'");
        $this->pdo->beginTransaction(); finalize_order_payment($this->pdo, $order['order_id'], 'late_test'); $this->pdo->commit();
        $this->assertSame('cancelled', $this->pdo->query('SELECT status FROM orders')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM security_audit WHERE action = 'late_payment_requires_review'")->fetchColumn());
    }

    public function testRevokedAndInactiveSessionsAreDenied(): void
    {
        register_auth_session($this->pdo, 1); validate_auth_session($this->pdo); revoke_user_sessions($this->pdo, 1);
        try { validate_auth_session($this->pdo); $this->fail(); } catch (SecurityFault $error) { $this->assertSame(401, $error->status); }
        $_SESSION['user_id'] = 2; register_auth_session($this->pdo, 2);
        $this->expectException(SecurityFault::class); validate_auth_session($this->pdo);
    }

    public function testPasswordResetIsSingleUseAndRevokesSessions(): void
    {
        register_auth_session($this->pdo, 1); $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (1, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')->execute(['test@example.test', hash('sha256', $token)]);
        consume_password_reset($this->pdo, $token, 'a strong replacement password');
        $this->assertTrue(password_verify('a strong replacement password', $this->pdo->query('SELECT password FROM users WHERE id = 1')->fetchColumn()));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL')->fetchColumn());
        $this->expectException(SecurityFault::class); consume_password_reset($this->pdo, $token, 'a different strong password');
    }

    private function parallel(array $inputs, string $lockSql): array
    {
        $this->pdo->beginTransaction(); $this->pdo->query($lockSql);
        $children = [];
        foreach ($inputs as $input) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/concurrent_operation.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency test.');
            fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        $this->pdo->commit();
        $results = [];
        foreach ($children as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 15); stream_set_timeout($pipes[2], 15);
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $results[] = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        }
        return $results;
    }

    public function testConcurrentStockConsumersCannotOversell(): void
    {
        $this->pdo->exec('UPDATE ingredients SET quantity = 2');
        $results = $this->parallel([
            ['operation' => 'order', 'data' => $this->order(), 'key' => 'parallel-order-0000001'],
            ['operation' => 'order', 'data' => $this->order(), 'key' => 'parallel-order-0000002'],
        ], 'SELECT id FROM ingredients WHERE id = 1 FOR UPDATE');
        $this->assertCount(1, array_filter($results, static fn(array $r): bool => $r['ok']));
        $this->assertSame('0.000', $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function testConcurrentDuplicateCheckoutReturnsOneOrder(): void
    {
        $input = ['operation' => 'order', 'data' => $this->order(), 'key' => 'parallel-same-00000001'];
        $results = $this->parallel([$input, $input], 'SELECT id FROM products WHERE id = 1 FOR UPDATE');
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $this->assertSame('8.000', $this->pdo->query('SELECT quantity FROM ingredients')->fetchColumn());
    }

    public function testConcurrentResetConsumersPermitOnePasswordChange(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (1, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')->execute(['test@example.test', hash('sha256', $token)]);
        $input = ['operation' => 'reset', 'token' => $token, 'password' => 'a concurrent strong password'];
        $results = $this->parallel([$input, $input], 'SELECT id FROM users WHERE id = 1 FOR UPDATE');
        $this->assertCount(1, array_filter($results, static fn(array $r): bool => $r['ok']));
        $this->assertCount(1, array_filter($results, static fn(array $r): bool => ($r['code'] ?? '') === 'RESET_EXPIRED'));
    }

    public function testPayoutClaimsKeepUnknownOutcomesAndReplayConfirmedResults(): void
    {
        require_once __DIR__ . '/../../includes/payout_service.php';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS payslips (id INT PRIMARY KEY, payment_status VARCHAR(20)) ENGINE=InnoDB');
        $this->pdo->exec("REPLACE INTO payslips (id, payment_status) VALUES (1, 'pending'), (2, 'pending')");
        $payload = ['amount' => '100.10'];
        $this->assertNull(claim_transfer($this->pdo, 'payout-test-unknown', 'payslip', 1, $payload));
        record_transfer_result($this->pdo, 'payout-test-unknown', ['ok' => false, 'unknown' => true]);
        try { claim_transfer($this->pdo, 'payout-test-unknown', 'payslip', 1, $payload); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame('PAYMENT_OUTCOME_PENDING', $error->errorCode); }
        $this->assertNull(claim_transfer($this->pdo, 'payout-test-paid', 'payslip', 2, $payload));
        $result = ['ok' => true, 'status' => 'paid', 'transfer_id' => 'tr_test_paid'];
        record_transfer_result($this->pdo, 'payout-test-paid', $result);
        $this->pdo->exec("UPDATE payslips SET payment_status = 'paid' WHERE id = 2");
        $this->assertSame($result, claim_transfer($this->pdo, 'payout-test-paid', 'payslip', 2, $payload));
        try { claim_transfer($this->pdo, 'payout-test-paid', 'payslip', 2, ['amount' => '200.00']); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame('IDEMPOTENCY_CONFLICT', $error->errorCode); }
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM payment_attempts')->fetchColumn());
    }

    public function testDeviceTrustRequiresAnApprovedUnexpiredRandomToken(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'Approved browser';
        $_COOKIE['kofee_device'] = str_repeat('a', 64);
        $this->assertFalse(trusted_device($this->pdo, 1));
        $this->pdo->prepare('INSERT INTO auth_devices (user_id, token_hash, approved_by, expires_at) VALUES (1, ?, 1, DATE_ADD(NOW(), INTERVAL 30 DAY))')->execute([hash('sha256', $_COOKIE['kofee_device'])]);
        $this->assertTrue(trusted_device($this->pdo, 1));
        $this->assertFalse(trusted_device($this->pdo, 2));
        $this->pdo->exec('UPDATE auth_devices SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)');
        $this->assertFalse(trusted_device($this->pdo, 1));
        $_COOKIE = [];
    }

    public function testThrottleReturns429WithoutIncreasingCounterBeyondLimit(): void
    {
        rate_limit('test-throttle', 'test-identity', 2, 3600);
        rate_limit('test-throttle', 'test-identity', 2, 3600);
        try { rate_limit('test-throttle', 'test-identity', 2, 3600); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame(429, $error->status); }
        $this->assertSame(2, (int)$this->pdo->query('SELECT attempts FROM security_rate_limits')->fetchColumn());
    }
}
