<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'PAYMENT_MODE', 'APP_URL', 'PRIVATE_DATA_KEY', 'JOB_ENCRYPTION_KEY', 'TRUSTED_PROXIES'] as $key) putenv($key);
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testWebhookUsesRawBodyAndCorrectMode(): void
    {
        $body = '{"amount":100,"id":"event_1"}';
        $timestamp = time();
        $hmac = hash_hmac('sha256', $timestamp . '.' . $body, 'test-webhook-secret');
        verify_paymongo_signature($body, "t=$timestamp,te=$hmac,li=", 'test-webhook-secret', 'test', $timestamp);
        verify_paymongo_signature($body, "t=$timestamp,li=$hmac,te=", 'test-webhook-secret', 'live', $timestamp);
        $this->expectException(SecurityFault::class);
        verify_paymongo_signature($body . ' ', "t=$timestamp,te=$hmac", 'test-webhook-secret', 'test', $timestamp);
    }

    public function testStaleWebhookIsRejected(): void
    {
        $timestamp = time() - 301;
        $hmac = hash_hmac('sha256', $timestamp . '.{}', 'secret');
        $this->expectException(SecurityFault::class);
        verify_paymongo_signature('{}', "t=$timestamp,te=$hmac", 'secret', 'test');
    }

    public function testMissingWebhookSecretFailsClosed(): void
    {
        try { verify_paymongo_signature('{}', '', '', 'live'); $this->fail(); }
        catch (SecurityFault $error) { $this->assertSame(503, $error->status); }
    }

    public function testProductionRejectsSimulation(): void
    {
        putenv('APP_ENV=production'); putenv('PAYMENT_MODE=demo');
        $this->expectException(SecurityFault::class);
        require_demo_payment();
    }

    public function testCanonicalUrlIgnoresHostSpoofing(): void
    {
        putenv('APP_ENV=production'); putenv('APP_URL=https://example.test/POS');
        $_SERVER['HTTP_HOST'] = 'attacker.invalid';
        $this->assertSame('https://example.test/POS', app_url());
        putenv('APP_URL=http://example.test/POS');
        $this->expectException(SecurityFault::class); app_url();
    }

    public function testPasswordPolicyCountsCharactersAndBcryptBytes(): void
    {
        $this->assertNotNull(new_password_error('short'));
        $this->assertNull(new_password_error('a sufficiently long passphrase'));
        $this->assertNotNull(new_password_error(str_repeat('a', 73)));
        $this->assertNotNull(new_password_error(str_repeat('界', 25)));
        $this->assertNotNull(new_password_error("long enough password\0"));
    }

    public function testLoginPasswordsRejectBcryptTruncationAndNullBytes(): void
    {
        $password = str_repeat('a', 72);
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertTrue(verify_login_password($password, $hash));
        $this->assertFalse(verify_login_password($password . 'b', $hash));
        $this->assertFalse(verify_login_password($password . "\0", $hash));
        $this->assertFalse(verify_login_password('incorrect', $hash));
        $this->assertFalse(verify_login_password('a missing account password', null));
    }

    public function testForwardedHttpsRequiresAnExplicitlyTrustedProxy(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        putenv('TRUSTED_PROXIES=192.0.2.20');
        $this->assertFalse(app_https());
        putenv('TRUSTED_PROXIES=192.0.2.10');
        $this->assertTrue(app_https());
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [true], [1.5], ['1e2'], ['1.0'], ['-1'], [1000], [[]]];
    }

    #[DataProvider('invalidQuantities')]
    public function testMalformedQuantityIsRejected(mixed $quantity): void
    {
        $this->expectException(SecurityFault::class); positive_quantity($quantity);
    }

    public function testCentavoArithmeticAvoidsRoundingErrors(): void
    {
        $this->assertSame(30, money_centavos('0.10') + money_centavos('0.20'));
        $this->assertSame('123.45', money_decimal(12345));
        $this->expectException(SecurityFault::class); money_centavos('12.345');
    }

    public static function invalidPaths(): array
    {
        return [['uploads/resumes/../secret.pdf'], ['uploads/resumes/%2e%2e.pdf'], ['C:/private/doc.pdf'], ['uploads/resumes/a.php/x'], ['uploads/menu/a.png']];
    }

    #[DataProvider('invalidPaths')]
    public function testTraversalIsRejected(string $path): void
    {
        $this->expectException(SecurityFault::class); validate_private_logical_path($path);
    }

    public function testEncryptedJobsAndAccountsDoNotContainPlaintext(): void
    {
        $key = base64_encode(random_bytes(32)); putenv('PRIVATE_DATA_KEY=' . $key); putenv('JOB_ENCRYPTION_KEY=' . $key);
        $account = encrypt_private_value('123456789012');
        $this->assertStringNotContainsString('123456789012', $account);
        $this->assertSame('123456789012', decrypt_private_value($account));
        $job = encode_job_payload('email', ['html' => 'private tracking token', 'to' => 'private@example.test']);
        $this->assertStringNotContainsString('private', $job);
        $this->assertSame('private@example.test', decode_job_payload('email', $job)['to']);
        $this->expectException(SecurityFault::class); decrypt_private_value(base64_encode('123456789012'));
    }

    public function testApprovalIsBoundToPendingBrowser(): void
    {
        $_SESSION['pending_auth_token'] = str_repeat('a', 64);
        require_pending_approval(str_repeat('a', 64));
        $this->expectException(SecurityFault::class); require_pending_approval(str_repeat('b', 64));
    }

    public function testSensitiveActionsRequireRecentPassword(): void
    {
        $_SESSION['reauthenticated_at'] = time() - 301;
        $this->expectException(SecurityFault::class); require_recent_password();
    }

    public function testPayrollMutationsRejectGet(): void
    {
        $this->assertSame(['POST'], api_request_policy('payroll.php', 'approve')['methods']);
        $this->assertTrue(api_request_policy('payroll.php', 'paymongo_dispatch_payout')['sensitive']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->expectException(SecurityFault::class); require_method('POST');
    }
}
