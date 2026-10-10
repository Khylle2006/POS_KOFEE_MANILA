<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LoginApprovalSettingsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        define('APP_MIGRATING', true);
        $this->pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'prepare', 'beginTransaction', 'commit', 'rollBack', 'inTransaction'])->getMock();
        $GLOBALS['migration_pdo'] = $this->pdo;
        require_once __DIR__ . '/../../includes/login_approval_helpers.php';
    }

    private function input(): array
    {
        return ['store_name' => ' Main Store ', 'store_latitude' => '14.3294', 'store_longitude' => '120.9367',
            'store_geofence_radius_meters' => '200', 'require_approval_enabled' => '1', 'exempt_roles' => ['hr', 'finance', 'hr']];
    }

    private function roles(): array
    {
        return [['role_key' => 'hr'], ['role_key' => 'finance'], ['role_key' => 'crew']];
    }

    private function settings(array $settings): void
    {
        $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['fetchAll'])->getMock();
        $statement->method('fetchAll')->with(PDO::FETCH_KEY_PAIR)->willReturn($settings);
        $this->pdo->method('query')->willReturn($statement);
    }

    public function testMultipleExistingRolesAreSavedAndDeduplicated(): void
    {
        $result = validate_login_approval_settings($this->input(), $this->roles());
        $this->assertSame('hr,finance', $result['exempt_roles']);
        $this->assertSame('Main Store', $result['store_name']);
        $this->assertSame('0', $result['auto_approve_within_geofence']);
    }

    public function testUncheckedRolesMeansNoBypassAndZeroCoordinatesAreValid(): void
    {
        $input = $this->input();
        unset($input['exempt_roles']);
        $input['store_latitude'] = $input['store_longitude'] = '0';
        $validated = validate_login_approval_settings($input, $this->roles());
        $this->assertSame('', $validated['exempt_roles']);
        $this->settings($validated);
        $settings = get_login_approval_settings($this->pdo);
        $this->assertSame(0.0, $settings['store_lat']);
        $this->assertSame(0.0, $settings['store_lon']);
        $this->assertSame([], $settings['exempt_roles']);
    }

    public static function invalidSettings(): array
    {
        return [
            'latitude out of range' => ['store_latitude', '91'],
            'longitude out of range' => ['store_longitude', '-181'],
            'missing latitude' => ['store_latitude', ''],
            'non finite coordinate' => ['store_latitude', '1e999'],
            'malformed coordinate' => ['store_longitude', []],
            'radius too small' => ['store_geofence_radius_meters', '19'],
            'radius too large' => ['store_geofence_radius_meters', '10001'],
            'unknown role' => ['exempt_roles', ['admin']],
            'malformed roles' => ['exempt_roles', 'hr,finance'],
            'nested role' => ['exempt_roles', [['hr']]],
            'empty store name' => ['store_name', ' '],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsCannotReachDatabaseWrites(string $key, mixed $value): void
    {
        $input = $this->input();
        $input[$key] = $value;
        $this->pdo->expects($this->never())->method('prepare');
        $this->expectException(InvalidArgumentException::class);
        save_login_approval_settings($this->pdo, validate_login_approval_settings($input, $this->roles()));
    }

    public function testSelectedPrimaryAndSecondaryRolesBypassButOtherRolesRequireReview(): void
    {
        $this->settings(['require_approval_enabled' => '1', 'exempt_roles' => 'hr,finance']);
        $this->assertFalse(does_user_require_login_approval($this->pdo, ['role' => 'hr']));
        $this->assertFalse(does_user_require_login_approval($this->pdo, ['role' => 'crew', 'roles' => ['crew', 'finance']]));
        $this->assertTrue(does_user_require_login_approval($this->pdo, ['role' => 'admin', 'roles' => ['admin']]));
        $this->assertTrue(does_user_require_login_approval($this->pdo, ['role' => 'crew', 'roles' => ['crew']]));
    }

    public function testNoRoleBypassesWhenSelectionIsEmpty(): void
    {
        $this->settings(['require_approval_enabled' => '1', 'exempt_roles' => '']);
        $this->assertTrue(does_user_require_login_approval($this->pdo, ['role' => 'hr', 'roles' => ['admin', 'hr']]));
    }

    public function testDisablingReviewHonorsTheSetting(): void
    {
        $this->settings(['require_approval_enabled' => '0', 'exempt_roles' => '']);
        $this->assertFalse(does_user_require_login_approval($this->pdo, ['role' => 'crew']));
    }

    public function testSettingsAndAuditAreCommittedTogether(): void
    {
        $settings = validate_login_approval_settings($this->input(), $this->roles());
        $writes = [];
        $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['execute'])->getMock();
        $statement->expects($this->exactly(count($settings)))->method('execute')->willReturnCallback(function (array $params) use (&$writes): bool {
            $writes[$params[':k']] = $params[':v'];
            return true;
        });
        $audit = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['execute'])->getMock();
        $audit->expects($this->once())->method('execute')->willReturn(true);
        $this->pdo->method('prepare')->willReturnOnConsecutiveCalls($statement, $audit);
        $this->pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('commit')->willReturn(true);
        save_login_approval_settings($this->pdo, $settings);
        $this->assertSame($settings, $writes);
    }

    public function testFailedWriteRollsBackAllSettings(): void
    {
        $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['execute'])->getMock();
        $statement->method('execute')->willThrowException(new PDOException('Simulated write failure'));
        $this->pdo->method('prepare')->willReturn($statement);
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdo->expects($this->never())->method('commit');
        $this->expectException(PDOException::class);
        save_login_approval_settings($this->pdo, validate_login_approval_settings($this->input(), $this->roles()));
    }
}
