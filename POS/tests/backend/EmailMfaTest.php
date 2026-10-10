<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/email_mfa.php';

use PHPUnit\Framework\TestCase;

final class EmailMfaConnection extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE enabled = 1, verified_email = VALUES(verified_email)',
            'ON CONFLICT(user_id) DO UPDATE SET enabled = 1, verified_email = excluded.verified_email', $query);
        return parent::prepare($query, $options);
    }
}

final class EmailMfaTest extends TestCase
{
    private PDO $pdo;
    private array $user;
    private string $code;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = new EmailMfaConnection('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE app_migrations (version TEXT PRIMARY KEY)');
        $this->pdo->exec("INSERT INTO app_migrations VALUES ('20261010_email_mfa_v1')");
        $this->pdo->exec('CREATE TABLE user_email_mfa (user_id INTEGER PRIMARY KEY, enabled INTEGER DEFAULT 0, verified_email TEXT)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, firstname TEXT, lastname TEXT, email TEXT, password TEXT, role TEXT, status TEXT, avatar_path TEXT)');
        $this->pdo->prepare("INSERT INTO users VALUES (1, 'staff', 'Test', 'User', 'staff@example.test', ?, 'crew', 'active', NULL)")
            ->execute([password_hash('test password', PASSWORD_DEFAULT)]);
        $this->pdo->exec('CREATE TABLE security_audit (actor_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER, request_id TEXT, details TEXT)');
        $this->user = email_mfa_user($this->pdo, 1);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function challenge(string $purpose = 'login', ?int $now = null): array
    {
        return new_email_mfa_challenge($this->user, $purpose, ['latitude' => '14.3'], function (string $email, string $code): void {
            $this->assertSame('staff@example.test', $email);
            $this->code = $code;
        }, $now);
    }

    public function testMfaIsOffForEachUserByDefaultAndBeforeInstallation(): void
    {
        $this->assertFalse(email_mfa_enabled($this->pdo, 1));
        $this->assertFalse(email_mfa_enabled($this->pdo, 2));
        $this->pdo->exec('DELETE FROM app_migrations');
        $this->assertFalse(email_mfa_available($this->pdo));
        $this->assertFalse(email_mfa_enabled($this->pdo, 1));
    }

    public function testEnrollmentEnablesOnlyTheVerifiedUserAndAuditContainsNoCode(): void
    {
        $challenge = $this->challenge('enroll');
        $this->assertFalse(email_mfa_enabled($this->pdo, 1));
        enable_email_mfa($this->pdo, 1, $challenge, $this->code);
        $this->assertTrue(email_mfa_enabled($this->pdo, 1));
        $this->assertFalse(email_mfa_enabled($this->pdo, 2));
        $this->assertSame([], $challenge);
        $this->assertStringNotContainsString($this->code, json_encode($this->pdo->query('SELECT * FROM security_audit')->fetchAll()));
    }

    public function testWrongEnrollmentCodeKeepsMfaOff(): void
    {
        $challenge = $this->challenge('enroll');
        try {
            enable_email_mfa($this->pdo, 1, $challenge, '000000');
            $this->fail('Unverified enrollment must not enable MFA.');
        } catch (SecurityFault $error) {
            $this->assertSame('MFA_CODE_INVALID', $error->errorCode);
        }
        $this->assertFalse(email_mfa_enabled($this->pdo, 1));
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testCorrectLoginCodeIsSingleUseAndDoesNotCreateASessionByItself(): void
    {
        $challenge = $this->challenge();
        $this->assertNotSame($this->code, $challenge['code_hash']);
        $this->assertSame('14.3', $challenge['context']['latitude']);
        verify_email_mfa_challenge($challenge, $this->user, 'login', $this->code);
        $this->assertSame([], $challenge);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->expectException(SecurityFault::class);
        verify_email_mfa_challenge($challenge, $this->user, 'login', $this->code);
    }

    public function testExpiredCodesAreRejected(): void
    {
        $challenge = $this->challenge(now: 1000);
        $this->expectException(SecurityFault::class);
        verify_email_mfa_challenge($challenge, $this->user, 'login', $this->code, 1600);
    }

    public function testFiveWrongCodesInvalidateTheChallenge(): void
    {
        $challenge = $this->challenge();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try { verify_email_mfa_challenge($challenge, $this->user, 'login', '000000'); }
            catch (SecurityFault $error) { $this->assertSame('MFA_CODE_INVALID', $error->errorCode); }
        }
        $this->assertSame([], $challenge);
        $this->expectException(SecurityFault::class);
        verify_email_mfa_challenge($challenge, $this->user, 'login', $this->code);
    }

    public function testCodeCannotCrossUsersPurposesOrChangedAccountDetails(): void
    {
        foreach (['id', 'email', 'password', 'status', 'purpose'] as $field) {
            $challenge = $this->challenge();
            $user = $this->user;
            $purpose = 'login';
            if ($field === 'purpose') $purpose = 'enroll';
            else $user[$field] = $field === 'id' ? 2 : 'changed';
            try {
                verify_email_mfa_challenge($challenge, $user, $purpose, $this->code);
                $this->fail('Changed identity or purpose must invalidate the challenge.');
            } catch (SecurityFault $error) {
                $this->assertSame('MFA_CODE_EXPIRED', $error->errorCode);
                $this->assertSame([], $challenge);
            }
        }
    }

    public function testResendingReplacesTheOldCode(): void
    {
        $old = $this->challenge();
        $challenge = $this->challenge();
        $this->assertNotSame($old['code_hash'], $challenge['code_hash']);
        verify_email_mfa_challenge($challenge, $this->user, 'login', $this->code);
        $this->assertSame([], $challenge);
    }

    public function testEmailDeliveryFailureDoesNotProduceAChallenge(): void
    {
        $this->expectException(SecurityFault::class);
        new_email_mfa_challenge($this->user, 'enroll', [], function (): void {
            throw new SecurityFault('MFA_EMAIL_UNAVAILABLE', 'Email delivery failed.', 503);
        });
    }

    public function testInvalidEmailCannotStartEnrollment(): void
    {
        $this->user['email'] = '';
        $this->expectException(SecurityFault::class);
        new_email_mfa_challenge($this->user, 'enroll', [], function (): void {
            $this->fail('Invalid email must never reach the sender.');
        });
    }

    public function testDisablingRequiresTheCurrentPassword(): void
    {
        $challenge = $this->challenge('enroll');
        enable_email_mfa($this->pdo, 1, $challenge, $this->code);
        try {
            disable_email_mfa($this->pdo, 1, 'wrong password');
            $this->fail('Disabling MFA needs the current password.');
        } catch (SecurityFault $error) {
            $this->assertSame('PASSWORD_INVALID', $error->errorCode);
        }
        $this->assertTrue(email_mfa_enabled($this->pdo, 1));
        disable_email_mfa($this->pdo, 1, 'test password');
        $this->assertFalse(email_mfa_enabled($this->pdo, 1));
    }
}
