<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/analytics_expenses.php';

final class AnalyticsExpenseTestConnection extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // Only schema discovery differs; the expense queries execute against real fixtures.
        if (str_contains($query, 'information_schema.TABLES')) {
            $query = "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
        } elseif (str_contains($query, 'information_schema.COLUMNS')) {
            $query = "SELECT COUNT(*) FROM pragma_table_info('expenses') WHERE name = 'status'";
        }
        return parent::prepare($query, $options);
    }
}

final class AnalyticsExpensesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new AnalyticsExpenseTestConnection('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount NUMERIC, status TEXT, completed_at TEXT, payment_date TEXT, scheduled_at TEXT)');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, total_amount NUMERIC, status TEXT, invoice_date TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE payslips (id INTEGER PRIMARY KEY, net_pay NUMERIC, payment_status TEXT, paid_at TEXT, created_at TEXT)');
    }

    private function totals(): array
    {
        return analytics_expense_breakdown($this->pdo, new DateTimeImmutable('2026-10-10', new DateTimeZone('Asia/Manila')));
    }

    public function testOnlyCompletedSupplierPaymentsAndReleasedPayrollAreCounted(): void
    {
        $this->pdo->exec("INSERT INTO payments VALUES (1, 1, 100.25, 'completed', '2026-10-09', '2026-10-09', '2026-10-08'), (2, 2, 50.50, 'paid', NULL, '2026-10-03', '2026-10-02'), (3, 3, 999, 'scheduled', NULL, '2026-10-09', '2026-10-09'), (4, 4, 999, 'failed', NULL, '2026-10-09', '2026-10-09')");
        $this->pdo->exec("INSERT INTO payslips VALUES (1, 200.10, 'paid', '2026-10-09', '2026-09-01'), (2, 999, 'approved', NULL, '2026-10-09'), (3, 999, 'processing', NULL, '2026-10-09'), (4, 999, 'failed', NULL, '2026-10-09')");
        $totals = $this->totals();
        $this->assertSame(150.75, $totals['all']['procurement']);
        $this->assertSame(150.75, $totals['weekly']['procurement']);
        $this->assertSame(200.10, $totals['weekly']['payroll']);
        $this->assertSame(350.85, round(array_sum($totals['all']), 2));
    }

    public function testPartialInvoiceCountsOnlyItsRecordedPayment(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES (1, 1000, 'partially_paid', '2026-10-09', '2026-10-09')");
        $this->pdo->exec("INSERT INTO payments VALUES (1, 1, 200, 'completed', '2026-10-09', '2026-10-09', '2026-10-09')");
        $this->assertSame(200.0, $this->totals()['all']['procurement']);
    }

    public function testLegacyPaidInvoicesAreIncludedIndividuallyWithoutDuplicatingPayments(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES (1, 1000, 'paid', '2026-10-09', '2026-10-09'), (2, 300, 'paid', '2026-10-09', '2026-10-09'), (3, 900, 'partially_paid', '2026-10-09', '2026-10-09'), (4, 800, 'approved', '2026-10-09', '2026-10-09')");
        $this->pdo->exec("INSERT INTO payments VALUES (1, 2, 300, 'completed', '2026-10-09', '2026-10-09', '2026-10-09')");
        $this->assertSame(1300.0, $this->totals()['all']['procurement']);
    }

    public function testInvoicesWithAnUnconfirmedPaymentCannotBecomeFallbackExpenses(): void
    {
        $this->pdo->exec("INSERT INTO invoices VALUES (1, 1000, 'paid', '2026-10-09', '2026-10-09')");
        $this->pdo->exec("INSERT INTO payments VALUES (1, 1, 1000, 'scheduled', NULL, '2026-10-09', '2026-10-09')");
        $this->assertSame(0.0, $this->totals()['all']['procurement']);
    }

    public function testWeeklyAndMonthlyUseThePaymentDateRatherThanCreationOrSchedulingDate(): void
    {
        $this->pdo->exec("INSERT INTO payments VALUES (1, 1, 100, 'completed', '2026-09-30', '2026-10-09', '2026-10-09'), (2, 2, 200, 'completed', '2026-10-02', '2026-10-09', '2026-10-09'), (3, 3, 300, 'completed', '2026-10-03', '2026-09-01', '2026-09-01')");
        $this->pdo->exec("INSERT INTO payslips VALUES (1, 50, 'paid', '2026-09-30', '2026-10-09'), (2, 60, 'paid', '2026-10-09', '2026-09-01')");
        $totals = $this->totals();
        $this->assertSame(600.0, $totals['all']['procurement']);
        $this->assertSame(500.0, $totals['monthly']['procurement']);
        $this->assertSame(300.0, $totals['weekly']['procurement']);
        $this->assertSame(110.0, $totals['all']['payroll']);
        $this->assertSame(60.0, $totals['weekly']['payroll']);
    }

    public function testRestockAndPayoutMirrorsAreNotAdditionalExpenses(): void
    {
        $this->pdo->exec("INSERT INTO payments VALUES (1, 1, 100, 'completed', '2026-10-09', '2026-10-09', '2026-10-09')");
        $this->pdo->exec("INSERT INTO payslips VALUES (1, 200, 'paid', '2026-10-09', '2026-10-09')");
        $this->pdo->exec('CREATE TABLE restock_log (added_qty NUMERIC, ingredient_id INTEGER)');
        $this->pdo->exec('INSERT INTO restock_log VALUES (50, 1)');
        $this->pdo->exec('CREATE TABLE ingredients (id INTEGER, unit_cost NUMERIC)');
        $this->pdo->exec('INSERT INTO ingredients VALUES (1, 500)');
        $this->pdo->exec('CREATE TABLE paymongo_payout_items (amount NUMERIC, payslip_id INTEGER)');
        $this->pdo->exec('INSERT INTO paymongo_payout_items VALUES (200, 1)');
        $totals = $this->totals();
        $this->assertSame(300.0, array_sum($totals['all']));
        $this->assertSame(0.0, $totals['all']['inventory']);
    }

    public function testChangesInPaymentLedgersAppearOnTheNextRead(): void
    {
        $this->pdo->exec("INSERT INTO payslips VALUES (1, 200, 'approved', NULL, '2026-10-09')");
        $this->assertSame(0.0, $this->totals()['all']['payroll']);
        $this->pdo->exec("UPDATE payslips SET payment_status = 'paid', paid_at = '2026-10-09' WHERE id = 1");
        $this->assertSame(200.0, $this->totals()['weekly']['payroll']);
    }

    public function testOptionalOperatingExpensesExcludeUnpaidRecords(): void
    {
        $this->pdo->exec('CREATE TABLE expenses (amount NUMERIC, status TEXT, created_at TEXT)');
        $this->pdo->exec("INSERT INTO expenses VALUES (25, 'paid', '2026-10-09'), (30, 'completed', '2026-10-09'), (999, 'pending', '2026-10-09')");
        $this->assertSame(55.0, $this->totals()['weekly']['other']);
    }

    public function testLegacyExpenseLedgerWithoutStatusesStillWorks(): void
    {
        $this->pdo->exec('CREATE TABLE expenses (amount NUMERIC, created_at TEXT)');
        $this->pdo->exec("INSERT INTO expenses VALUES (25, '2026-10-09')");
        $this->assertSame(25.0, $this->totals()['weekly']['other']);
    }

    public function testMissingOptionalTablesReturnZeroAndSchemaErrorsAreNotSilenced(): void
    {
        $empty = new AnalyticsExpenseTestConnection('sqlite::memory:');
        $this->assertSame(0.0, array_sum(analytics_expense_breakdown($empty)['all']));
        $empty->exec('CREATE TABLE payments (amount NUMERIC)');
        $this->expectException(PDOException::class);
        analytics_expense_breakdown($empty);
    }
}
