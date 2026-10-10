<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecurityBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        define('APP_MIGRATING', true);
        require_once __DIR__ . '/../../includes/permissions.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    private function statement(): PDOStatement
    {
        return $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()
            ->onlyMethods(['execute', 'fetch', 'fetchColumn'])->getMock();
    }

    public function testExpiredAccountLockoutCannotBypassAnActiveIpLockout(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $account = $this->statement();
        $account->method('execute')->willReturn(true);
        $account->method('fetch')->willReturn(['failures' => 6, 'last_try' => date('Y-m-d H:i:s', time() - 601)]);
        $ip = $this->statement();
        $ip->method('execute')->willReturn(true);
        $ip->method('fetch')->willReturn(['failures' => 24, 'last_try' => date('Y-m-d H:i:s')]);
        $pdo->expects($this->exactly(2))->method('prepare')->willReturnOnConsecutiveCalls($account, $ip);
        $GLOBALS['migration_pdo'] = $pdo;
        $this->assertGreaterThanOrEqual(598, login_lockout_seconds('an-account'));
    }

    public function testLongerAccountLockoutIsPreservedWhenIpLockoutAlsoExists(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $account = $this->statement();
        $account->method('execute')->willReturn(true);
        $account->method('fetch')->willReturn(['failures' => 6, 'last_try' => date('Y-m-d H:i:s')]);
        $ip = $this->statement();
        $ip->method('execute')->willReturn(true);
        $ip->method('fetch')->willReturn(['failures' => 24, 'last_try' => date('Y-m-d H:i:s', time() - 120)]);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($account, $ip);
        $GLOBALS['migration_pdo'] = $pdo;
        $this->assertGreaterThanOrEqual(598, login_lockout_seconds('an-account'));
    }

    public function testAdministratorProtectionBindsBothRoleLookupsAndDeniesNonAdmins(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $stmt = $this->statement();
        $stmt->expects($this->once())->method('execute')->with([7, 7])->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($stmt);
        try {
            require_admin_account_management($pdo, 7, false);
            $this->fail('A secondary admin role must be protected.');
        } catch (SecurityFault $error) {
            $this->assertSame('PERMISSION_DENIED', $error->errorCode);
            $this->assertSame(403, $error->status);
        }
    }

    public function testNonAdminCanManageANonAdministrator(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $stmt = $this->statement();
        $stmt->expects($this->once())->method('execute')->with([7, 7])->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);
        require_admin_account_management($pdo, 7, false);
    }

    public function testAdminCanManageAdministratorAccounts(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $pdo->expects($this->never())->method('prepare');
        require_admin_account_management($pdo, 7, true);
    }

    public function testRoleAssignedAsASecondaryRoleCannotBeDeleted(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $role = $this->statement();
        $role->method('execute')->willReturn(true);
        $role->method('fetch')->willReturn(['is_system' => 0]);
        $assigned = $this->statement();
        $assigned->expects($this->once())->method('execute')->with([':primary_role' => 'finance', ':assigned_role' => 'finance'])->willReturn(true);
        $assigned->method('fetchColumn')->willReturn(1);
        $pdo->expects($this->exactly(2))->method('prepare')->willReturnOnConsecutiveCalls($role, $assigned);
        $GLOBALS['migration_pdo'] = $pdo;
        $this->assertFalse(delete_role('finance')['ok']);
    }

    public function testInactiveUserCannotConsumeAnApprovedLogin(): void
    {
        $_SESSION = ['pending_auth_token' => str_repeat('a', 64), 'pending_auth_user_id' => 7];
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()
            ->onlyMethods(['prepare', 'beginTransaction', 'rollBack'])->getMock();
        $stmt = $this->statement();
        $stmt->expects($this->once())->method('execute')->with([7])->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo->expects($this->once())->method('prepare')->with($this->stringContains("FROM users WHERE id = ? AND status = 'active' FOR UPDATE"))->willReturn($stmt);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->assertFalse(consume_login_approval($pdo, str_repeat('a', 64)));
    }
}
