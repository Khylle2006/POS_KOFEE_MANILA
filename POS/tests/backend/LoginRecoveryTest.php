<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [
            'user_id' => 1,
            'auth_handle' => str_repeat('a', 64),
            'role' => 'admin',
            'authenticated_at' => time(),
            'last_activity' => time(),
            '_csrf_token' => 'old-session-token',
        ];
    }

    private function database(mixed $schema = 1, mixed $status = 'active'): PDO
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['query', 'prepare'])->getMock();
        $schemaStatement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['fetchColumn'])->getMock();
        $schemaStatement->method('fetchColumn')->willReturn($schema);
        if ($schema instanceof Throwable) {
            $pdo->method('query')->willThrowException($schema);
        } else {
            $pdo->method('query')->willReturn($schemaStatement);
        }
        $sessionStatement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['execute', 'fetchColumn'])->getMock();
        $sessionStatement->method('execute')->willReturn(true);
        $sessionStatement->method('fetchColumn')->willReturn($status);
        if ($status instanceof Throwable) {
            $pdo->method('prepare')->willThrowException($status);
        } else {
            $pdo->method('prepare')->willReturn($sessionStatement);
        }
        return $pdo;
    }

    public function testLegacySessionReturnsToSignInAndClearsPrivilegedState(): void
    {
        unset($_SESSION['auth_handle']);
        $this->assertFalse(resume_auth_session($this->database()));
        $this->assertSame([], $_SESSION);
    }

    public function testIdleSessionReturnsToSignIn(): void
    {
        $_SESSION['last_activity'] = time() - 1801;
        $this->assertFalse(resume_auth_session($this->database()));
        $this->assertSame([], $_SESSION);
    }

    public function testAbsoluteSessionExpiryReturnsToSignIn(): void
    {
        $_SESSION['authenticated_at'] = time() - 43201;
        $this->assertFalse(resume_auth_session($this->database()));
        $this->assertSame([], $_SESSION);
    }

    public function testRevokedSessionReturnsToSignIn(): void
    {
        $this->assertFalse(resume_auth_session($this->database(1, false)));
        $this->assertSame([], $_SESSION);
    }

    public function testInactiveAccountReturnsToSignIn(): void
    {
        $this->assertFalse(resume_auth_session($this->database(1, 'inactive')));
        $this->assertSame([], $_SESSION);
    }

    public function testValidSessionRemainsAuthenticated(): void
    {
        $this->assertTrue(resume_auth_session($this->database()));
        $this->assertSame(1, $_SESSION['user_id']);
        $this->assertSame(str_repeat('a', 64), $_SESSION['auth_handle']);
    }

    public function testMissingVersionFailsClosedWithoutClearingSession(): void
    {
        $original = $_SESSION;
        try {
            resume_auth_session($this->database(false));
            $this->fail('An unmigrated database must deny sign-in.');
        } catch (SecurityFault $exception) {
            $this->assertSame('MIGRATION_REQUIRED', $exception->errorCode);
            $this->assertSame(503, $exception->status);
            $this->assertSame($original, $_SESSION);
        }
    }

    public function testMissingMigrationTableHasSafeActionableError(): void
    {
        $databaseError = new PDOException('Sensitive SQL and database configuration');
        $databaseError->errorInfo = ['42S02', 1146, 'Sensitive table details'];
        try {
            resume_auth_session($this->database($databaseError));
            $this->fail('A missing migration table must deny sign-in.');
        } catch (SecurityFault $exception) {
            $this->assertSame('MIGRATION_REQUIRED', $exception->errorCode);
            $this->assertSame(503, $exception->status);
            $this->assertStringNotContainsString('Sensitive', $exception->getMessage());
        }
    }

    public function testSchemaConnectionFailureIsNotTreatedAsSignedOut(): void
    {
        $databaseError = new PDOException('Connection unavailable');
        $databaseError->errorInfo = ['HY000', 2006, 'Connection unavailable'];
        $original = $_SESSION;
        try {
            resume_auth_session($this->database($databaseError));
            $this->fail('Connection failures must fail closed.');
        } catch (PDOException $exception) {
            $this->assertSame($databaseError, $exception);
            $this->assertSame($original, $_SESSION);
        }
    }

    public function testSessionStoreFailureIsNotTreatedAsSignedOut(): void
    {
        $databaseError = new PDOException('Session store unavailable');
        $original = $_SESSION;
        try {
            resume_auth_session($this->database(1, $databaseError));
            $this->fail('Session lookup failures must fail closed.');
        } catch (PDOException $exception) {
            $this->assertSame($databaseError, $exception);
            $this->assertSame($original, $_SESSION);
        }
    }
}
