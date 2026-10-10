<?php
declare(strict_types=1);

function analytics_expense_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/** @return array{all:float,weekly:float,monthly:float} */
function analytics_expense_sum(PDO $pdo, string $sql, array $dates): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($dates);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Expense totals could not be read.');
    }
    return ['all' => (float)$row['all_total'], 'weekly' => (float)$row['weekly_total'], 'monthly' => (float)$row['monthly_total']];
}

/** @return array<string,array<string,float>> */
function analytics_expense_breakdown(PDO $pdo, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $dates = [$today->modify('-7 days')->format('Y-m-d'), $today->format('Y-m-01')];
    $breakdown = array_fill_keys(['all', 'weekly', 'monthly'], ['procurement' => 0.0, 'payroll' => 0.0, 'inventory' => 0.0, 'other' => 0.0]);
    $hasPayments = analytics_expense_table_exists($pdo, 'payments');
    if ($hasPayments) {
        $payments = analytics_expense_sum($pdo, <<<'SQL'
            SELECT COALESCE(SUM(amount), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(completed_at, payment_date, scheduled_at)) >= ? THEN amount ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(completed_at, payment_date, scheduled_at)) >= ? THEN amount ELSE 0 END), 0) AS monthly_total
            FROM payments WHERE status IN ('completed', 'paid')
            SQL, $dates);
        foreach ($payments as $period => $amount) {
            $breakdown[$period]['procurement'] += $amount;
        }
    }
    if (analytics_expense_table_exists($pdo, 'invoices')) {
        // Legacy fully paid invoices may have no payment ledger entry. Partial invoices
        // contribute only their recorded payments, never their entire invoice balance.
        $invoiceSql = $hasPayments ? <<<'SQL'
            SELECT COALESCE(SUM(i.total_amount), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(i.invoice_date, i.created_at)) >= ? THEN i.total_amount ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(i.invoice_date, i.created_at)) >= ? THEN i.total_amount ELSE 0 END), 0) AS monthly_total
            FROM invoices i WHERE i.status = 'paid'
                AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = i.id)
            SQL : <<<'SQL'
            SELECT COALESCE(SUM(total_amount), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(invoice_date, created_at)) >= ? THEN total_amount ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(invoice_date, created_at)) >= ? THEN total_amount ELSE 0 END), 0) AS monthly_total
            FROM invoices WHERE status = 'paid'
            SQL;
        foreach (analytics_expense_sum($pdo, $invoiceSql, $dates) as $period => $amount) {
            $breakdown[$period]['procurement'] += $amount;
        }
    }
    if (analytics_expense_table_exists($pdo, 'payslips')) {
        $payroll = analytics_expense_sum($pdo, <<<'SQL'
            SELECT COALESCE(SUM(net_pay), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(paid_at, created_at)) >= ? THEN net_pay ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(COALESCE(paid_at, created_at)) >= ? THEN net_pay ELSE 0 END), 0) AS monthly_total
            FROM payslips WHERE payment_status = 'paid'
            SQL, $dates);
        foreach ($payroll as $period => $amount) {
            $breakdown[$period]['payroll'] = $amount;
        }
    }
    if (analytics_expense_table_exists($pdo, 'expenses')) {
        $statusColumn = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'status'");
        $statusColumn->execute();
        $expenseSql = (int)$statusColumn->fetchColumn() > 0 ? <<<'SQL'
            SELECT COALESCE(SUM(amount), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) AS monthly_total
            FROM expenses WHERE status IN ('paid', 'completed')
            SQL : <<<'SQL'
            SELECT COALESCE(SUM(amount), 0) AS all_total,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) AS weekly_total,
                COALESCE(SUM(CASE WHEN DATE(created_at) >= ? THEN amount ELSE 0 END), 0) AS monthly_total
            FROM expenses
            SQL;
        foreach (analytics_expense_sum($pdo, $expenseSql, $dates) as $period => $amount) {
            $breakdown[$period]['other'] = $amount;
        }
    }
    // Restock logs and payout batches mirror purchases/payslips. They are not
    // additional cash outflows, and current ingredient prices are only estimates.
    foreach ($breakdown as &$period) {
        foreach ($period as &$amount) {
            $amount = round($amount, 2);
        }
        unset($amount);
    }
    unset($period);
    return $breakdown;
}
