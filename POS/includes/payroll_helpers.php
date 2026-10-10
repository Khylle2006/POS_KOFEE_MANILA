<?php
// ─────────────────────────────────────────────────────────────
//  includes/payroll_helpers.php
//  Period maths, attendance aggregation, commission and tips,
//  statutory deductions, and payslip generation.
//
//  IMPORTANT: the contribution tables below are configuration,
//  not law. Verify the current SSS / PhilHealth / Pag-IBIG and
//  BIR schedules before the first live run, and update them
//  whenever the agencies publish new ones.
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

// ═══════════════════════════════════════════════
//  SETTINGS
// ═══════════════════════════════════════════════

function payroll_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    try {
        foreach (get_db()->query('SELECT setting_key, setting_value FROM payroll_settings')->fetchAll() as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('payroll_settings failed: ' . 'Service temporarily unavailable.');
    }
    return $cache;
}

function payroll_setting(string $key, $default = null) {
    return payroll_settings()[$key] ?? $default;
}

function payroll_setting_num(string $key, float $default = 0.0): float {
    return (float)payroll_setting($key, $default);
}

// ═══════════════════════════════════════════════
//  PERIOD HELPERS
// ═══════════════════════════════════════════════

/**
 * Suggest the next period boundaries for a frequency.
 * @return array{start:string,end:string,pay_date:string,label:string}
 */
function suggest_payroll_period(string $frequency, ?string $anchor = null): array {
    $today = new DateTimeImmutable($anchor ?: 'today');

    switch ($frequency) {
        case 'weekly':
            $start = $today->modify('monday this week');
            $end   = $start->modify('+6 days');
            break;

        case 'biweekly':
            $start = $today->modify('monday this week')->modify('-7 days');
            $end   = $start->modify('+13 days');
            break;

        case 'monthly':
            $start = $today->modify('first day of this month');
            $end   = $today->modify('last day of this month');
            break;

        case 'semimonthly':
        default:
            if ((int)$today->format('j') <= 15) {
                $start = $today->modify('first day of this month');
                $end   = $start->modify('+14 days');           // 1st - 15th
            } else {
                $start = $today->modify('first day of this month')->modify('+15 days');
                $end   = $today->modify('last day of this month'); // 16th - EOM
            }
            break;
    }

    // Pay date defaults to three days after the cut-off.
    $pay = $end->modify('+3 days');

    return [
        'start'    => $start->format('Y-m-d'),
        'end'      => $end->format('Y-m-d'),
        'pay_date' => $pay->format('Y-m-d'),
        'label'    => $start->format('M j') . ' - ' . $end->format('M j, Y'),
    ];
}

/** How many of the year's periods this frequency produces. */
function periods_per_year(string $frequency): int {
    return match ($frequency) {
        'weekly'      => 52,
        'biweekly'    => 26,
        'monthly'     => 12,
        default       => 24,   // semimonthly
    };
}

// ═══════════════════════════════════════════════
//  TIME AGGREGATION
// ═══════════════════════════════════════════════

/**
 * Roll up one employee's attendance across a date range.
 *
 * Night differential is approximated from the clock-in/out clock
 * times against the 22:00-06:00 window. Breaks are subtracted
 * when both break_start and break_end are recorded.
 *
 * @return array<string,float>
 */
function aggregate_attendance(int $employee_id, string $start, string $end): array {
    $std   = payroll_setting_num('standard_hours_per_day', 8);
    $grace = (int)payroll_setting_num('grace_period_minutes', 15);
    $include_unapproved = payroll_setting('auto_approve_attendance', '1') !== '0';

    $totals = [
        'days_worked' => 0.0, 'regular_hours' => 0.0, 'overtime_hours' => 0.0,
        'night_diff_hours' => 0.0, 'holiday_hours' => 0.0, 'rest_day_hours' => 0.0,
        'late_minutes' => 0.0, 'undertime_minutes' => 0.0,
        'absent_days' => 0.0, 'paid_leave_days' => 0.0,
        'unapproved_days' => 0.0, 'open_shifts' => 0.0,
    ];

    try {
        $sql = 'SELECT * FROM attendance
                 WHERE employee_id = :e
                   AND attendance_date BETWEEN :s AND :en';
        if (!$include_unapproved) {
            $sql .= ' AND is_approved = 1';
        }

        $stmt = get_db()->prepare($sql);
        $stmt->execute([':e' => $employee_id, ':s' => $start, ':en' => $end]);
        $rows = $stmt->fetchAll();

        // Separately count records that were skipped, so the run can
        // flag an exception instead of quietly underpaying someone.
        if (!$include_unapproved) {
            $skip = get_db()->prepare(
                'SELECT COUNT(*) FROM attendance
                  WHERE employee_id = :e AND attendance_date BETWEEN :s AND :en
                    AND is_approved = 0 AND time_in IS NOT NULL'
            );
            $skip->execute([':e' => $employee_id, ':s' => $start, ':en' => $end]);
            $totals['unapproved_days'] = (float)$skip->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('aggregate_attendance failed: ' . 'Service temporarily unavailable.');
        return $totals;
    }

    foreach ($rows as $r) {
        if ($r['status'] === 'absent') { $totals['absent_days'] += 1; continue; }
        if ($r['status'] === 'on_leave') { $totals['paid_leave_days'] += 1; continue; }
        if (!$r['time_in']) continue;

        // A shift with no clock-out cannot be paid without guessing.
        if (!$r['time_out']) { $totals['open_shifts'] += 1; continue; }

        $in  = strtotime($r['attendance_date'] . ' ' . $r['time_in']);
        $out = strtotime($r['attendance_date'] . ' ' . $r['time_out']);
        if ($out <= $in) $out += 86400;   // shift crossed midnight

        $worked_secs = $out - $in;

        // Subtract an unpaid break when it was actually recorded.
        if ($r['break_start'] && $r['break_end']) {
            $bs = strtotime($r['attendance_date'] . ' ' . $r['break_start']);
            $be = strtotime($r['attendance_date'] . ' ' . $r['break_end']);
            if ($be > $bs) $worked_secs -= ($be - $bs);
        }

        $hours = max(0, $worked_secs / 3600);
        $totals['days_worked'] += 1;

        // Lateness against an 08:00 start, past the grace period.
        $expected_in = strtotime($r['attendance_date'] . ' 08:00:00');
        $late_min    = (int)max(0, ($in - $expected_in) / 60);
        if ($late_min > $grace) $totals['late_minutes'] += $late_min;

        $regular = min($hours, $std);
        $ot      = max(0, $hours - $std);

        if ((int)$r['is_holiday'] === 1) {
            $totals['holiday_hours']  += $regular;
        } elseif ((int)$r['is_rest_day'] === 1) {
            $totals['rest_day_hours'] += $regular;
        } else {
            $totals['regular_hours']  += $regular;
            if ($regular < $std) {
                $totals['undertime_minutes'] += ($std - $regular) * 60;
            }
        }

        $totals['overtime_hours']   += $ot;
        $totals['night_diff_hours'] += night_hours_between($in, $out);
    }

    return $totals;
}

/** Hours of a shift falling inside the 22:00-06:00 night window. */
function night_hours_between(int $in_ts, int $out_ts): float {
    $night = 0.0;
    $cursor = $in_ts;

    while ($cursor < $out_ts) {
        $hour = (int)date('G', $cursor);
        $next = min($out_ts, strtotime('+1 hour', strtotime(date('Y-m-d H:00:00', $cursor))));
        if ($hour >= 22 || $hour < 6) {
            $night += ($next - $cursor) / 3600;
        }
        $cursor = $next;
    }

    return round($night, 2);
}

// ═══════════════════════════════════════════════
//  POS SALES LINK
// ═══════════════════════════════════════════════

/**
 * Completed sales rung up by an employee inside the period.
 * @return array{sales:float, orders:int, tips:float}
 */
function employee_sales_in_period(int $employee_id, string $start, string $end): array {
    try {
        $stmt = get_db()->prepare(
            "SELECT COALESCE(SUM(o.total_amount),0) AS sales,
                    COUNT(*)                        AS orders,
                    COALESCE(SUM(o.tip_amount),0)   AS tips
               FROM orders o
              WHERE o.employee_id = :e
                AND o.status = 'completed'
                AND DATE(o.placed_at) BETWEEN :s AND :en"
        );
        $stmt->execute([':e' => $employee_id, ':s' => $start, ':en' => $end]);
        $row = $stmt->fetch() ?: [];

        return [
            'sales'  => (float)($row['sales']  ?? 0),
            'orders' => (int)  ($row['orders'] ?? 0),
            'tips'   => (float)($row['tips']   ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('employee_sales_in_period failed: ' . 'Service temporarily unavailable.');
        return ['sales' => 0.0, 'orders' => 0, 'tips' => 0.0];
    }
}

/** Service charge collected across the whole period, for pooling. */
function pooled_tips_in_period(string $start, string $end): float {
    try {
        $stmt = get_db()->prepare(
            "SELECT COALESCE(SUM(service_charge_amount),0)
               FROM orders
              WHERE status = 'completed'
                AND DATE(placed_at) BETWEEN :s AND :en"
        );
        $stmt->execute([':s' => $start, ':en' => $end]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

// ═══════════════════════════════════════════════
//  RATES
// ═══════════════════════════════════════════════

/**
 * Derive daily and hourly rates from an employee's pay config.
 * @return array{daily:float, hourly:float}
 */
function derive_rates(array $employee): array {
    $std_hours = payroll_setting_num('standard_hours_per_day', 8);
    $work_days = payroll_setting_num('working_days_per_month', 26);
    $rate      = (float)$employee['pay_rate'];

    switch ($employee['pay_type']) {
        case 'hourly':
            return ['daily' => $rate * $std_hours, 'hourly' => $rate];

        case 'daily':
            return ['daily' => $rate, 'hourly' => $std_hours > 0 ? $rate / $std_hours : 0];

        case 'commission':
            return ['daily' => 0.0, 'hourly' => 0.0];

        case 'monthly':
        default:
            $daily = $work_days > 0 ? $rate / $work_days : 0;
            return ['daily' => $daily, 'hourly' => $std_hours > 0 ? $daily / $std_hours : 0];
    }
}

// ═══════════════════════════════════════════════
//  STATUTORY DEDUCTIONS  (verify before live use)
// ═══════════════════════════════════════════════

/**
 * SSS employee share. Bracket table — replace with the current
 * SSS contribution schedule and keep the ranges contiguous.
 */
function compute_sss(float $monthly_basic): float {
    if ($monthly_basic <= 0) return 0.0;
    $rate = 0.05;            // employee share of the total contribution
    $msc  = max(5000, min(35000, round($monthly_basic / 500) * 500));
    return round($msc * $rate, 2);
}

/** PhilHealth employee share — half of the premium, floored and capped. */
function compute_philhealth(float $monthly_basic): float {
    if ($monthly_basic <= 0) return 0.0;
    $premium_rate = 0.05;
    $base = max(10000, min(100000, $monthly_basic));
    return round(($base * $premium_rate) / 2, 2);
}

/** Pag-IBIG employee share, capped at the statutory maximum. */
function compute_pagibig(float $monthly_basic): float {
    if ($monthly_basic <= 0) return 0.0;
    $rate = $monthly_basic <= 1500 ? 0.01 : 0.02;
    return round(min($monthly_basic * $rate, 200), 2);
}

/**
 * Withholding tax on the period's taxable income.
 * Annualised brackets, divided down to the period.
 */
function compute_withholding_tax(float $taxable_period_income, string $frequency): float {
    $per_year   = periods_per_year($frequency);
    $annualised = $taxable_period_income * $per_year;

    // Annual brackets: [ceiling, base tax, rate on the excess over floor]
    $brackets = [
        [250000,    0.00,      0.00, 0],
        [400000,    0.00,      0.15, 250000],
        [800000,    22500.00,  0.20, 400000],
        [2000000,   102500.00, 0.25, 800000],
        [8000000,   402500.00, 0.30, 2000000],
        [PHP_FLOAT_MAX, 2202500.00, 0.35, 8000000],
    ];

    $annual_tax = 0.0;
    foreach ($brackets as [$ceiling, $base, $rate, $floor]) {
        if ($annualised <= $ceiling) {
            $annual_tax = $base + (($annualised - $floor) * $rate);
            break;
        }
    }

    return round(max(0, $annual_tax) / $per_year, 2);
}

/**
 * Compute standard Philippine statutory deductions for a payroll period.
 * Automatically computes SSS, PhilHealth, Pag-IBIG, and BIR TRAIN withholding tax,
 * plus any active employee loan repayments.
 *
 * @param float  $basic_pay Period basic pay (attendance-based)
 * @param float  $gross_pay Period gross pay
 * @param array  $employee  Employee record
 * @param string $frequency 'semimonthly', 'monthly', 'weekly', etc.
 * @return array Itemized deductions, statutory total, and combined total
 */
function compute_ph_statutory_deductions(float $basic_pay, float $gross_pay, array $employee, string $frequency = 'semimonthly'): array {
    $per_year = periods_per_year($frequency);
    $split    = max(1.0, (float)$per_year / 12.0);

    // Monthly basic salary equivalent for bracket schedules
    $monthly_basic = $basic_pay * $split;

    $is_tax_exempt = (isset($employee['tax_exempt']) && (int)$employee['tax_exempt'] === 1);

    if ($monthly_basic <= 0) {
        $sss        = 0.0;
        $philhealth = 0.0;
        $pagibig    = 0.0;
        $tax        = 0.0;
    } else {
        $sss        = $is_tax_exempt ? 0.0 : round(compute_sss($monthly_basic) / $split, 2);
        $philhealth = $is_tax_exempt ? 0.0 : round(compute_philhealth($monthly_basic) / $split, 2);
        $pagibig    = $is_tax_exempt ? 0.0 : round(compute_pagibig($monthly_basic) / $split, 2);

        // Taxable income under BIR TRAIN Law (Gross minus mandatory employee contributions)
        $taxable = max(0.0, $gross_pay - $sss - $philhealth - $pagibig);
        $tax     = $is_tax_exempt ? 0.0 : compute_withholding_tax($taxable, $frequency);
    }

    $eid = (int)($employee['id'] ?? 0);
    $loan_ded = $eid > 0 ? active_loan_deduction($eid) : 0.0;

    $statutory_total  = round($sss + $philhealth + $pagibig + $tax, 2);
    $total_deductions = round($statutory_total + $loan_ded, 2);

    return [
        'sss'              => $sss,
        'philhealth'       => $philhealth,
        'pagibig'          => $pagibig,
        'withholding_tax'  => $tax,
        'loan_deduction'   => $loan_ded,
        'statutory_total'  => $statutory_total,
        'total_deductions' => $total_deductions,
    ];
}

// ═══════════════════════════════════════════════
//  PAYSLIP CALCULATION
// ═══════════════════════════════════════════════

/**
 * Compute one employee's payslip figures for a period.
 * Pure function — returns an array, writes nothing.
 */
function calculate_payslip(array $employee, array $period, float $tip_pool_share = 0.0): array {
    $start = $period['period_start'];
    $end   = $period['period_end'];
    $freq  = $period['frequency'];

    $time  = aggregate_attendance((int)$employee['id'], $start, $end);
    $sales = employee_sales_in_period((int)$employee['id'], $start, $end);
    $rates = derive_rates($employee);

    $std_hours = payroll_setting_num('standard_hours_per_day', 8);
    $ot_mult   = payroll_setting_num('overtime_multiplier', 1.25);
    $rd_mult   = payroll_setting_num('rest_day_multiplier', 1.30);
    $hol_mult  = payroll_setting_num('holiday_multiplier', 2.00);
    $nd_mult   = payroll_setting_num('night_diff_multiplier', 1.10);

    // ── Earnings ──
    $paid_hours = $time['regular_hours'] + ($time['paid_leave_days'] * $std_hours);
    $basic      = round($paid_hours * $rates['hourly'], 2);

    $overtime   = round($time['overtime_hours']   * $rates['hourly'] * $ot_mult,  2);
    $rest_day   = round($time['rest_day_hours']   * $rates['hourly'] * $rd_mult,  2);
    $holiday    = round($time['holiday_hours']    * $rates['hourly'] * $hol_mult, 2);
    $night_diff = round($time['night_diff_hours'] * $rates['hourly'] * ($nd_mult - 1), 2);

    $commission = round($sales['sales'] * ((float)$employee['commission_rate'] / 100), 2);
    $tips       = round($sales['tips'] + ((int)$employee['tip_share'] === 1 ? $tip_pool_share : 0), 2);

    // ── Attendance deductions ──
    $late_ded   = round(($time['late_minutes'] / 60) * $rates['hourly'], 2);
    $absent_ded = round($time['absent_days'] * $rates['daily'], 2);

    $gross = $basic + $overtime + $rest_day + $holiday + $night_diff + $commission + $tips;

    // ── Statutory deductions ──
    // Contributions are computed on the monthly equivalent of basic
    // pay, then split across the periods in that month.
    $per_year      = periods_per_year($freq);
    $monthly_basic = $basic * ($per_year / 12);
    $split         = $per_year / 12;

    $sss        = (int)$employee['tax_exempt'] === 1 ? 0.0 : round(compute_sss($monthly_basic) / $split, 2);
    $philhealth = (int)$employee['tax_exempt'] === 1 ? 0.0 : round(compute_philhealth($monthly_basic) / $split, 2);
    $pagibig    = (int)$employee['tax_exempt'] === 1 ? 0.0 : round(compute_pagibig($monthly_basic) / $split, 2);

    $taxable = max(0, $gross - $late_ded - $absent_ded - $sss - $philhealth - $pagibig);
    $tax     = (int)$employee['tax_exempt'] === 1 ? 0.0 : compute_withholding_tax($taxable, $freq);

    // ── Loans ──
    $loan_ded = active_loan_deduction((int)$employee['id']);

    $total_ded = round($late_ded + $absent_ded + $sss + $philhealth + $pagibig + $tax + $loan_ded, 2);
    $net       = round($gross - $total_ded, 2);

    // ── Exceptions the reviewer must see ──
    $exceptions = [];
    if ($time['open_shifts'] > 0) {
        $exceptions[] = (int)$time['open_shifts'] . ' shift(s) never clocked out - hours not counted';
    }
    if ($time['unapproved_days'] > 0) {
        $exceptions[] = (int)$time['unapproved_days'] . ' unapproved attendance day(s) excluded';
    }
    if ($net < 0) {
        $exceptions[] = 'Net pay is negative - deductions exceed gross';
    }
    if ($rates['hourly'] <= 0 && $employee['pay_type'] !== 'commission') {
        $exceptions[] = 'No pay rate configured for this employee';
    }
    if ($time['days_worked'] == 0 && $time['paid_leave_days'] == 0) {
        $exceptions[] = 'No attendance recorded in this period';
    }

    return [
        'employee_id'       => (int)$employee['id'],
        'days_worked'       => round($time['days_worked'], 2),
        'regular_hours'     => round($time['regular_hours'], 2),
        'overtime_hours'    => round($time['overtime_hours'], 2),
        'night_diff_hours'  => round($time['night_diff_hours'], 2),
        'holiday_hours'     => round($time['holiday_hours'], 2),
        'rest_day_hours'    => round($time['rest_day_hours'], 2),
        'late_minutes'      => (int)$time['late_minutes'],
        'undertime_minutes' => (int)$time['undertime_minutes'],
        'absent_days'       => round($time['absent_days'], 2),
        'paid_leave_days'   => round($time['paid_leave_days'], 2),

        'basic_pay'         => $basic,
        'overtime_pay'      => $overtime,
        'holiday_pay'       => $holiday,
        'rest_day_pay'      => $rest_day,
        'night_diff_pay'    => $night_diff,
        'commission'        => $commission,
        'tips'              => $tips,
        'allowances'        => 0.00,
        'bonus'             => 0.00,
        'gross_pay'         => round($gross, 2),

        'late_deduction'    => $late_ded,
        'absence_deduction' => $absent_ded,
        'sss'               => $sss,
        'philhealth'        => $philhealth,
        'pagibig'           => $pagibig,
        'withholding_tax'   => $tax,
        'loan_deduction'    => $loan_ded,
        'other_deduction'   => 0.00,
        'total_deductions'  => $total_ded,
        'net_pay'           => $net,

        'snapshot_pay_type' => $employee['pay_type'],
        'snapshot_pay_rate' => (float)$employee['pay_rate'],
        'payment_method'    => $employee['payment_method'],

        'sales_total'       => $sales['sales'],
        'order_count'       => $sales['orders'],

        'has_exception'     => $exceptions ? 1 : 0,
        'exception_note'    => $exceptions ? implode('; ', $exceptions) : null,
    ];
}

/** This period's scheduled repayment across an employee's active loans. */
function active_loan_deduction(int $employee_id): float {
    try {
        $stmt = get_db()->prepare(
            "SELECT COALESCE(SUM(LEAST(per_period_amount, balance)),0)
               FROM employee_loans
              WHERE employee_id = :e AND status = 'active' AND balance > 0"
        );
        $stmt->execute([':e' => $employee_id]);
        return round((float)$stmt->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

// ═══════════════════════════════════════════════
//  RUNNING A PERIOD
// ═══════════════════════════════════════════════

/**
 * Calculate (or recalculate) every payslip in a period.
 * Refuses to touch a period that is approved, paid or locked.
 *
 * @return array{ok:bool, error?:string, count?:int, exceptions?:int}
 */
function run_payroll_calculation(int $period_id, ?int $actor_id = null): array {
    $pdo = get_db();

    try {
        $stmt = $pdo->prepare('SELECT * FROM payroll_periods WHERE id = :id');
        $stmt->execute([':id' => $period_id]);
        $period = $stmt->fetch();

        if (!$period) {
            return ['ok' => false, 'error' => 'Payroll period not found.'];
        }
        if (in_array($period['status'], ['approved', 'paid', 'locked'], true)) {
            return ['ok' => false, 'error' => 'This period is ' . $period['status'] . ' and can no longer be recalculated.'];
        }

        // Active employees, optionally scoped to one branch.
        $sql = "SELECT * FROM employees WHERE status = 'active'";
        $params = [];
        if (!empty($period['branch'])) {
            $sql .= ' AND branch = :b';
            $params[':b'] = $period['branch'];
        }
        $emp_stmt = $pdo->prepare($sql . ' ORDER BY lastname, firstname');
        $emp_stmt->execute($params);
        $employees = $emp_stmt->fetchAll();

        if (!$employees) {
            return ['ok' => false, 'error' => 'No active employees found for this period.'];
        }

        // ── Tip pool ──
        // Pooled service charge is split by hours worked (or equally),
        // but only among staff flagged as tip-sharing.
        $pool = pooled_tips_in_period($period['period_start'], $period['period_end']);
        $mode = payroll_setting('tip_pool_mode', 'hours');

        $shares = [];
        if ($pool > 0) {
            $weights = [];
            foreach ($employees as $emp) {
                if ((int)$emp['tip_share'] !== 1) continue;
                $t = aggregate_attendance((int)$emp['id'], $period['period_start'], $period['period_end']);
                $weights[(int)$emp['id']] = $mode === 'equal' ? 1.0 : max(0.0, $t['regular_hours']);
            }
            $total_weight = array_sum($weights);
            foreach ($weights as $eid => $w) {
                $shares[$eid] = $total_weight > 0 ? round($pool * ($w / $total_weight), 2) : 0.0;
            }
        }

        // ── Write ──
        $pdo->beginTransaction();

        // Manual adjustments already entered are preserved and
        // re-applied after the recalculation.
        $adj_stmt = $pdo->prepare(
            'SELECT a.payslip_id, p.employee_id, a.kind, a.amount
               FROM payslip_adjustments a
               JOIN payslips p ON p.id = a.payslip_id
              WHERE p.period_id = :pid'
        );
        $adj_stmt->execute([':pid' => $period_id]);

        $adjustments = [];
        foreach ($adj_stmt->fetchAll() as $a) {
            $eid = (int)$a['employee_id'];
            $adjustments[$eid][$a['kind']] = ($adjustments[$eid][$a['kind']] ?? 0) + (float)$a['amount'];
        }

        $upsert = $pdo->prepare(
            'INSERT INTO payslips (
                period_id, employee_id, days_worked, regular_hours, overtime_hours,
                night_diff_hours, holiday_hours, rest_day_hours, late_minutes,
                undertime_minutes, absent_days, paid_leave_days,
                basic_pay, overtime_pay, holiday_pay, rest_day_pay, night_diff_pay,
                commission, tips, allowances, bonus, gross_pay,
                late_deduction, absence_deduction, sss, philhealth, pagibig,
                withholding_tax, loan_deduction, other_deduction, total_deductions, net_pay,
                snapshot_pay_type, snapshot_pay_rate, payment_method,
                has_exception, exception_note
             ) VALUES (
                :period_id, :employee_id, :days_worked, :regular_hours, :overtime_hours,
                :night_diff_hours, :holiday_hours, :rest_day_hours, :late_minutes,
                :undertime_minutes, :absent_days, :paid_leave_days,
                :basic_pay, :overtime_pay, :holiday_pay, :rest_day_pay, :night_diff_pay,
                :commission, :tips, :allowances, :bonus, :gross_pay,
                :late_deduction, :absence_deduction, :sss, :philhealth, :pagibig,
                :withholding_tax, :loan_deduction, :other_deduction, :total_deductions, :net_pay,
                :snapshot_pay_type, :snapshot_pay_rate, :payment_method,
                :has_exception, :exception_note
             )
             ON DUPLICATE KEY UPDATE
                days_worked = VALUES(days_worked), regular_hours = VALUES(regular_hours),
                overtime_hours = VALUES(overtime_hours), night_diff_hours = VALUES(night_diff_hours),
                holiday_hours = VALUES(holiday_hours), rest_day_hours = VALUES(rest_day_hours),
                late_minutes = VALUES(late_minutes), undertime_minutes = VALUES(undertime_minutes),
                absent_days = VALUES(absent_days), paid_leave_days = VALUES(paid_leave_days),
                basic_pay = VALUES(basic_pay), overtime_pay = VALUES(overtime_pay),
                holiday_pay = VALUES(holiday_pay), rest_day_pay = VALUES(rest_day_pay),
                night_diff_pay = VALUES(night_diff_pay), commission = VALUES(commission),
                tips = VALUES(tips), allowances = VALUES(allowances), bonus = VALUES(bonus),
                gross_pay = VALUES(gross_pay), late_deduction = VALUES(late_deduction),
                absence_deduction = VALUES(absence_deduction), sss = VALUES(sss),
                philhealth = VALUES(philhealth), pagibig = VALUES(pagibig),
                withholding_tax = VALUES(withholding_tax), loan_deduction = VALUES(loan_deduction),
                other_deduction = VALUES(other_deduction), total_deductions = VALUES(total_deductions),
                net_pay = VALUES(net_pay), snapshot_pay_type = VALUES(snapshot_pay_type),
                snapshot_pay_rate = VALUES(snapshot_pay_rate), payment_method = VALUES(payment_method),
                has_exception = VALUES(has_exception), exception_note = VALUES(exception_note)'
        );

        $gross_total = 0.0; $ded_total = 0.0; $net_total = 0.0; $exception_count = 0;

        foreach ($employees as $emp) {
            $slip = calculate_payslip($emp, $period, $shares[(int)$emp['id']] ?? 0.0);

            // Re-apply saved manual adjustments.
            $extra = $adjustments[(int)$emp['id']] ?? [];
            $slip['allowances']      = round(($extra['allowance'] ?? 0) + ($extra['reimbursement'] ?? 0), 2);
            $slip['bonus']           = round($extra['bonus'] ?? 0, 2);
            $slip['other_deduction'] = round($extra['deduction'] ?? 0, 2);

            $slip['gross_pay']        = round($slip['gross_pay'] + $slip['allowances'] + $slip['bonus'], 2);
            $slip['total_deductions'] = round($slip['total_deductions'] + $slip['other_deduction'], 2);
            $slip['net_pay']          = round($slip['gross_pay'] - $slip['total_deductions'], 2);

            $params = $slip;
            unset($params['sales_total'], $params['order_count']);
            $params['period_id'] = $period_id;

            $bind = [];
            foreach ($params as $k => $v) $bind[':' . $k] = $v;
            $upsert->execute($bind);

            $gross_total += $slip['gross_pay'];
            $ded_total   += $slip['total_deductions'];
            $net_total   += $slip['net_pay'];
            if ($slip['has_exception']) $exception_count++;
        }

        $pdo->prepare(
            "UPDATE payroll_periods
                SET status = 'calculated', gross_total = :g, deduction_total = :d,
                    net_total = :n, headcount = :h, calculated_at = NOW()
              WHERE id = :id"
        )->execute([
            ':g' => round($gross_total, 2), ':d' => round($ded_total, 2),
            ':n' => round($net_total, 2),   ':h' => count($employees), ':id' => $period_id,
        ]);

        $pdo->commit();

        payroll_audit($period_id, null, 'calculated',
            count($employees) . ' payslip(s), ' . $exception_count . ' exception(s)', $actor_id);

        return ['ok' => true, 'count' => count($employees), 'exceptions' => $exception_count];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('run_payroll_calculation failed: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Calculation failed. Check the error log.'];
    }
}

/**
 * Release payment for an approved period: mark slips paid, post
 * loan repayments, and lock the period against further edits.
 */
function release_payroll(int $period_id, ?int $actor_id = null): array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id FROM payslips WHERE period_id = ? ORDER BY id');
    $stmt->execute([$period_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) release_single_employee_payslip($pdo, (int)$id, $actor_id ?? (int)$_SESSION['user_id']);
    return ['ok' => true, 'message' => 'Payroll release processed.'];
}

// ═══════════════════════════════════════════════
//  AUDIT & DISPLAY HELPERS
// ═══════════════════════════════════════════════

function payroll_audit(?int $period_id, ?int $payslip_id, string $action,
                       ?string $detail = null, ?int $actor_id = null): void {
    try {
        get_db()->prepare(
            'INSERT INTO payroll_audit (period_id, payslip_id, actor_id, action, detail)
             VALUES (:pe, :pa, :ac, :an, :d)'
        )->execute([
            ':pe' => $period_id, ':pa' => $payslip_id,
            ':ac' => $actor_id ?? ($_SESSION['user_id'] ?? null),
            ':an' => $action, ':d' => $detail,
        ]);
    } catch (Throwable $e) {
        error_log('payroll_audit failed: ' . 'Service temporarily unavailable.');
    }
}

/** Peso formatting used across every payroll view. */
function peso(float $amount): string {
    return '&#8369;' . number_format($amount, 2);
}

/** Badge class for a period status, using the shared status tokens. */
function period_status_class(string $status): string {
    return match ($status) {
        'draft'           => 'pr-badge pr-badge-gray',
        'calculated'      => 'pr-badge pr-badge-blue',
        'pending_finance' => 'pr-badge pr-badge-amber',
        'approved'        => 'pr-badge pr-badge-blue',
        'partially_paid'  => 'pr-badge pr-badge-amber',
        'paid'            => 'pr-badge pr-badge-green',
        'locked'          => 'pr-badge pr-badge-purple',
        default           => 'pr-badge pr-badge-gray',
    };
}

/** Render a rich period status badge with an SVG icon. */
function period_status_badge(string $status, int $exceptions = 0): string {
    $class = period_status_class($status);

    $label = match ($status) {
        'draft'           => 'Draft',
        'calculated'      => 'Calculated',
        'pending_finance' => 'Under Finance Review',
        'approved'        => 'Finance Approved',
        'partially_paid'  => 'Partially Released',
        'paid'            => 'Released',
        'locked'          => 'Locked',
        default           => ucfirst(htmlspecialchars($status)),
    };

    $icon_svg = match ($status) {
        'draft'           => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>',
        'calculated'      => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'pending_finance' => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'approved'        => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
        'partially_paid'  => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'paid'            => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'locked'          => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        default           => '',
    };

    $html = '<span class="' . $class . '">' . $icon_svg . ' ' . $label . '</span>';
    if ($exceptions > 0 && !in_array($status, ['paid', 'partially_paid'], true)) {
        $html .= ' <span class="pr-badge pr-badge-amber" style="margin-left:4px"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> ' . $exceptions . ' to review</span>';
    }
    return $html;
}

/**
 * Synchronize period status based on all payslip payment statuses in that period.
 */
function sync_period_payment_status(PDO $pdo, int $period_id): void {
    $counts = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed_count
        FROM payslips
        WHERE period_id = :pid
    ");
    $counts->execute([':pid' => $period_id]);
    $st = $counts->fetch();
    
    $total  = (int)($st['total'] ?? 0);
    $paid   = (int)($st['paid_count'] ?? 0);
    $failed = (int)($st['failed_count'] ?? 0);

    if ($total > 0 && $paid === $total) {
        $pdo->prepare("UPDATE payroll_periods SET status = 'paid', paid_at = COALESCE(paid_at, NOW()) WHERE id = :id")
            ->execute([':id' => $period_id]);
    } elseif ($paid > 0 || $failed > 0) {
        $pdo->prepare("UPDATE payroll_periods SET status = 'partially_paid' WHERE id = :id AND status != 'paid'")
            ->execute([':id' => $period_id]);
    }
}

/**
 * Release an individual employee's payslip.
 * Tracks transfer individually, supporting PayMongo bank/ewallet or manual cash.
 */
function release_single_employee_payslip(PDO $pdo, int $payslip_id, int $actor_id, ?string $method = null, array $details = []): array {
    require_once __DIR__ . '/paymongo_disbursement_helpers.php';
    $stmt = $pdo->prepare('SELECT s.*, p.status AS period_status, e.firstname, e.lastname, e.user_id, d.bank_code, d.bank_name, d.account_number_last4, d.ewallet_provider, d.ewallet_mobile_number FROM payslips s JOIN payroll_periods p ON p.id = s.period_id JOIN employees e ON e.id = s.employee_id LEFT JOIN employee_payment_details d ON d.employee_id = e.id AND d.is_active = 1 WHERE s.id = ?');
    $stmt->execute([$payslip_id]);
    $slip = $stmt->fetch();
    if (!$slip) throw new SecurityFault('PAYSLIP_NOT_FOUND', 'Payslip not found.', 404);
    if ($slip['payment_status'] === 'paid') return ['ok' => true, 'status' => 'paid', 'transfer_id' => $slip['transfer_id'], 'message' => 'Payslip was already released.'];
    if ($slip['period_status'] !== 'approved') throw new SecurityFault('PAYROLL_NOT_APPROVED', 'Payroll must be approved before release.', 409);
    $method = $method ?: ($slip['payment_method'] ?: 'cash');
    if (!in_array($method, ['cash', 'cheque', 'bank_transfer', 'ewallet', 'paymongo'], true)) throw new SecurityFault('PAYMENT_METHOD_INVALID', 'Invalid payout method.');
    if (money_centavos($slip['net_pay']) <= 0) throw new SecurityFault('PAYOUT_AMOUNT_INVALID', 'Payout must be positive.');
    $name = trim($slip['firstname'] . ' ' . $slip['lastname']);
    $online = in_array($method, ['bank_transfer', 'ewallet', 'paymongo'], true);
    $result = ['ok' => true, 'status' => 'paid', 'transfer_id' => 'CSH-' . $payslip_id];
    if ($online) {
        $result = execute_paymongo_transfer_call(get_paymongo_disbursement_config($pdo), [
            'payslip_id' => $payslip_id, 'employee_id' => $slip['employee_id'], 'amount' => $slip['net_pay'],
            'recipient_name' => $name, 'payout_destination' => $method === 'ewallet' ? 'ewallet' : 'bank',
            'bank_code' => $slip['bank_code'], 'bank_name' => $slip['bank_name'], 'account_number_last4' => $slip['account_number_last4'],
            'ewallet_provider' => $slip['ewallet_provider'], 'ewallet_mobile_number' => $slip['ewallet_mobile_number'],
        ]);
    }
    $paid = !empty($result['ok']) && in_array($result['status'] ?? '', ['paid', 'succeeded'], true);
    $status = $paid ? 'paid' : (!empty($result['unknown']) || !empty($result['ok']) ? 'pending' : 'failed');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT payment_status FROM payslips WHERE id = ? FOR UPDATE'); $lock->execute([$payslip_id]);
        if ($lock->fetchColumn() === 'paid') { $pdo->commit(); return ['ok' => true, 'status' => 'paid', 'message' => 'Payslip was already released.']; }
        // A manual release cannot override an unresolved gateway transfer.
        if (!$online) {
            $attempt = $pdo->prepare('SELECT status FROM payment_attempts WHERE operation_key = ? FOR UPDATE');
            $attempt->execute(['payslip-transfer-' . $payslip_id]);
            if ($attempt->fetchColumn()) throw new SecurityFault('PAYOUT_RECONCILIATION_REQUIRED', 'Review the existing transfer before manual release.', 409);
        }
        $pdo->prepare('UPDATE payslips SET payment_status = ?, transfer_id = ?, transfer_channel = ?, released_by = ?, paid_at = ?, transfer_error = ? WHERE id = ?')->execute([$status, $result['transfer_id'] ?? null, $method, $actor_id, $paid ? date('Y-m-d H:i:s') : null, $result['error'] ?? null, $payslip_id]);
        if ($paid) apply_payslip_loan_repayments($pdo, ['payslip_id' => $payslip_id, 'employee_id' => $slip['employee_id']]);
        sync_period_payment_status($pdo, (int)$slip['period_id']);
        security_audit($pdo, 'payslip_release', 'payslip', $payslip_id, ['status' => $status]);
        $pdo->commit();
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
    return ['ok' => $status !== 'failed', 'status' => $status, 'transfer_id' => $result['transfer_id'] ?? null, 'employee_name' => $name, 'error' => $result['error'] ?? null, 'message' => $paid ? 'Payout confirmed.' : 'Payout submitted; confirmation is pending.'];
}

/**
 * Batch release multiple employees with isolated transfer execution per employee.
 */
function batch_release_employees(PDO $pdo, int $period_id, array $payslip_ids, int $actor_id): array {
    if (empty($payslip_ids)) {
        return ['ok' => false, 'error' => 'No employees selected for payout release.'];
    }

    $results = [];
    $success_count = 0;
    $failed_count  = 0;

    foreach ($payslip_ids as $id) {
        $id = (int)$id;
        $res = release_single_employee_payslip($pdo, $id, $actor_id);
        if ($res['ok']) {
            $success_count++;
            $results[] = [
                'payslip_id'    => $id,
                'status'        => $res['status'] ?? 'pending',
                'employee_name' => $res['employee_name'] ?? "Payslip #$id",
                'transfer_id'   => $res['transfer_id'] ?? '',
                'message'       => $res['message'] ?? 'Paid'
            ];
        } else {
            $failed_count++;
            $results[] = [
                'payslip_id'    => $id,
                'status'        => 'failed',
                'employee_name' => $res['employee_name'] ?? "Payslip #$id",
                'error'         => $res['error'] ?? 'Transfer failed'
            ];
        }
    }

    $msg = "Batch release processed: {$success_count} succeeded" . ($failed_count > 0 ? ", {$failed_count} failed." : " successfully.");

    return [
        'ok'            => true,
        'success_count' => $success_count,
        'failed_count'  => $failed_count,
        'results'       => $results,
        'message'       => $msg,
    ];
}

/**
 * Finance review and approval action for a payroll period.
 */
function finance_review_payroll(PDO $pdo, int $period_id, string $decision, int $actor_id, string $note = ''): array {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = :id");
    $stmt->execute([':id' => $period_id]);
    $period = $stmt->fetch();

    if (!$period) {
        return ['ok' => false, 'error' => 'Payroll period not found.'];
    }

    if ($decision === 'approve') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE payroll_periods
                SET status = 'approved', approved_by = :u, approved_at = NOW(),
                    notes = COALESCE(NULLIF(:n, ''), notes)
                WHERE id = :id
            ")->execute([':u' => $actor_id, ':n' => $note, ':id' => $period_id]);

            $pdo->prepare("
                UPDATE payslips
                SET payment_status = 'approved'
                WHERE period_id = :pid AND payment_status IN ('unpaid', 'pending_finance')
            ")->execute([':pid' => $period_id]);

            payroll_audit($period_id, null, 'finance_approved', "Finance approved payroll period for payout release. Note: " . ($note ?: 'None'), $actor_id);

            $pdo->commit();
            return ['ok' => true, 'message' => 'Payroll period approved by Finance and ready for payout release.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Error approving payroll: ' . 'Service temporarily unavailable.'];
        }
    } else {
        // Request correction / Reject
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE payroll_periods
                SET status = 'draft',
                    notes = CONCAT('Finance rejected/requested correction: ', :n)
                WHERE id = :id
            ")->execute([':n' => $note ?: 'Corrections needed', ':id' => $period_id]);

            $pdo->prepare("
                UPDATE payslips
                SET payment_status = 'unpaid'
                WHERE period_id = :pid
            ")->execute([':pid' => $period_id]);

            payroll_audit($period_id, null, 'finance_rejected', "Finance returned payroll for revision: " . ($note ?: 'Corrections needed'), $actor_id);

            $pdo->commit();
            return ['ok' => true, 'message' => 'Payroll returned to draft for corrections.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'error' => 'Error returning payroll: ' . 'Service temporarily unavailable.'];
        }
    }
}

/**
 * Progressive Step Payroll Run Creation.
 * Creates the run with individually selected employees, customized amounts, payment methods,
 * and sets status to 'pending_finance' for Finance review.
 */
function create_step_payroll_run(PDO $pdo, array $period_meta, array $employees_data, int $actor_id): array {
    $start     = trim($period_meta['period_start'] ?? '');
    $end       = trim($period_meta['period_end'] ?? '');
    $pay_date  = trim($period_meta['pay_date'] ?? '');
    $frequency = trim($period_meta['frequency'] ?? 'semimonthly');
    $label     = trim($period_meta['label'] ?? '');
    $notes     = trim($period_meta['notes'] ?? '');

    if (!$start || !$end || !$pay_date) {
        return ['ok' => false, 'error' => 'Period start date, end date, and pay date are required.'];
    }

    if (!$label) {
        $label = date('M j', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));
    }

    if (empty($employees_data)) {
        return ['ok' => false, 'error' => 'Please select at least one employee for this payroll run.'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payroll_periods
                (label, frequency, period_start, period_end, pay_date, branch, status, headcount, notes, created_by, created_at)
            VALUES
                (:l, :f, :s, :e, :p, NULL, 'pending_finance', :h, :n, :u, NOW())
        ");
        $stmt->execute([
            ':l' => $label,
            ':f' => $frequency,
            ':s' => $start,
            ':e' => $end,
            ':p' => $pay_date,
            ':h' => count($employees_data),
            ':n' => $notes ?: 'Custom employee-selected payroll run submitted for Finance review',
            ':u' => $actor_id,
        ]);
        $period_id = (int)$pdo->lastInsertId();

        $gross_total = 0.0;
        $deduction_total = 0.0;
        $net_total = 0.0;

        $slip_stmt = $pdo->prepare("
            INSERT INTO payslips (
                period_id, employee_id, days_worked, regular_hours, overtime_hours,
                basic_pay, overtime_pay, holiday_pay, rest_day_pay, night_diff_pay,
                commission, tips, allowances, bonus, gross_pay,
                late_deduction, absence_deduction, sss, philhealth, pagibig,
                withholding_tax, loan_deduction, other_deduction, total_deductions, net_pay,
                snapshot_pay_type, snapshot_pay_rate, payment_method, payout_account_info,
                payment_status, has_exception, exception_note, notes, created_at
            ) VALUES (
                :period_id, :employee_id, :days_worked, :regular_hours, :overtime_hours,
                :basic_pay, :overtime_pay, 0.00, 0.00, 0.00,
                :commission, :tips, :allowances, :bonus, :gross_pay,
                0.00, 0.00, :sss, :philhealth, :pagibig,
                :withholding_tax, :loan_deduction, :other_deduction, :total_deductions, :net_pay,
                :snapshot_pay_type, :snapshot_pay_rate, :payment_method, :payout_account_info,
                'pending_finance', :has_exception, :exception_note, :notes, NOW()
            )
        ");

        $emp_stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");

        foreach ($employees_data as $ed) {
            $eid = (int)$ed['employee_id'];
            $emp_stmt->execute([':id' => $eid]);
            $emp = $emp_stmt->fetch();
            if (!$emp) continue;

            $time  = aggregate_attendance($eid, $start, $end);
            $sales = employee_sales_in_period($eid, $start, $end);
            $rates = derive_rates($emp);

            // Basic pay is strictly synced from clock in / clock out attendance (cannot be overridden):
            $std_hours   = payroll_setting_num('standard_hours_per_day', 8);
            $paid_hours  = (float)($time['regular_hours'] ?? 0) + ((float)($time['paid_leave_days'] ?? 0) * (float)$std_hours);
            $basic       = round($paid_hours * (float)$rates['hourly'], 2);
            $allowances  = round((float)($ed['allowances'] ?? 0), 2);
            $bonus       = round((float)($ed['bonus'] ?? 0), 2);
            $overtime    = round((float)($time['overtime_hours'] * $rates['hourly'] * 1.25), 2);
            $commission  = round((float)($ed['commission'] ?? ($sales['sales'] * ((float)$emp['commission_rate'] / 100))), 2);
            $tips        = round((float)($ed['tips'] ?? $sales['tips']), 2);

            $gross       = isset($ed['gross_pay']) && (float)$ed['gross_pay'] > 0
                         ? round((float)$ed['gross_pay'], 2)
                         : round($basic + $overtime + $commission + $tips + $allowances + $bonus, 2);

            // Philippine statutory deductions schedule and loan allocation:
            $freq = $period_meta['frequency'] ?? 'semimonthly';
            $ph   = compute_ph_statutory_deductions($basic, $gross, $emp, $freq);

            $custom_ded = isset($ed['deductions']) ? round((float)$ed['deductions'], 2) : (isset($ed['total_deductions']) ? round((float)$ed['total_deductions'], 2) : null);

            if ($custom_ded !== null) {
                $total_ded = $custom_ded;
                if (abs($custom_ded - $ph['total_deductions']) < 0.01) {
                    // Default Philippine statutory deductions + loan
                    $sss        = $ph['sss'];
                    $philhealth = $ph['philhealth'];
                    $pagibig    = $ph['pagibig'];
                    $tax        = $ph['withholding_tax'];
                    $loan       = $ph['loan_deduction'];
                    $other_ded  = 0.0;
                } elseif ($custom_ded > ($ph['statutory_total'] + $ph['loan_deduction'])) {
                    // Manager added extra custom deductions (uniform, cash advance, damage, etc.)
                    $sss        = $ph['sss'];
                    $philhealth = $ph['philhealth'];
                    $pagibig    = $ph['pagibig'];
                    $tax        = $ph['withholding_tax'];
                    $loan       = $ph['loan_deduction'];
                    $other_ded  = round($custom_ded - ($ph['statutory_total'] + $loan), 2);
                } elseif ($custom_ded <= 0) {
                    // Waived deductions
                    $sss        = 0.0;
                    $philhealth = 0.0;
                    $pagibig    = 0.0;
                    $tax        = 0.0;
                    $loan       = 0.0;
                    $other_ded  = 0.0;
                } else {
                    // Apportion custom deduction across statutory items
                    $rem        = $custom_ded;
                    $loan       = min($rem, $ph['loan_deduction']); $rem -= $loan;
                    $sss        = min($rem, $ph['sss']); $rem -= $sss;
                    $philhealth = min($rem, $ph['philhealth']); $rem -= $philhealth;
                    $pagibig    = min($rem, $ph['pagibig']); $rem -= $pagibig;
                    $tax        = min($rem, $ph['withholding_tax']); $rem -= $tax;
                    $other_ded  = max(0.0, round($rem, 2));
                }
            } else {
                $sss        = isset($ed['sss']) ? round((float)$ed['sss'], 2) : $ph['sss'];
                $philhealth = isset($ed['philhealth']) ? round((float)$ed['philhealth'], 2) : $ph['philhealth'];
                $pagibig    = isset($ed['pagibig']) ? round((float)$ed['pagibig'], 2) : $ph['pagibig'];
                $tax        = isset($ed['withholding_tax']) ? round((float)$ed['withholding_tax'], 2) : $ph['withholding_tax'];
                $loan       = isset($ed['loan_deduction']) ? round((float)$ed['loan_deduction'], 2) : $ph['loan_deduction'];
                $other_ded  = round((float)($ed['other_deduction'] ?? 0), 2);
                $total_ded  = round($sss + $philhealth + $pagibig + $tax + $loan + $other_ded, 2);
            }

            // Gross and Net Pay strictly computed with locked basic pay:
            $gross = round($basic + $overtime + $commission + $tips + $allowances + $bonus, 2);
            $net   = max(0, round($gross - $total_ded, 2));

            // Payment method & destination info from Step 3:
            $pay_method  = $ed['payment_method'] ?? ($emp['payment_method'] ?: 'cash');
            $payout_info = trim((string)($ed['payout_account_info'] ?? ''));

            $slip_stmt->execute([
                ':period_id'          => $period_id,
                ':employee_id'        => $eid,
                ':days_worked'        => $time['days_worked'],
                ':regular_hours'      => $time['regular_hours'],
                ':overtime_hours'     => $time['overtime_hours'],
                ':basic_pay'          => $basic,
                ':overtime_pay'       => $overtime,
                ':commission'         => $commission,
                ':tips'               => $tips,
                ':allowances'         => $allowances,
                ':bonus'              => $bonus,
                ':gross_pay'          => $gross,
                ':sss'                => $sss,
                ':philhealth'         => $philhealth,
                ':pagibig'            => $pagibig,
                ':withholding_tax'    => $tax,
                ':loan_deduction'     => $loan,
                ':other_deduction'    => $other_ded,
                ':total_deductions'   => $total_ded,
                ':net_pay'            => $net,
                ':snapshot_pay_type'  => $emp['pay_type'],
                ':snapshot_pay_rate'  => (float)$emp['pay_rate'],
                ':payment_method'     => $pay_method,
                ':payout_account_info'=> $payout_info,
                ':has_exception'      => $net < 0 ? 1 : 0,
                ':exception_note'     => $net < 0 ? 'Net pay is negative' : null,
                ':notes'              => $ed['notes'] ?? null,
            ]);

            $gross_total     += $gross;
            $deduction_total += $total_ded;
            $net_total       += $net;
        }

        // Update period totals
        $pdo->prepare("
            UPDATE payroll_periods
            SET gross_total = :g, deduction_total = :d, net_total = :n
            WHERE id = :id
        ")->execute([
            ':g' => round($gross_total, 2),
            ':d' => round($deduction_total, 2),
            ':n' => round($net_total, 2),
            ':id'=> $period_id,
        ]);

        payroll_audit($period_id, null, 'created_step_run', "Created payroll run for " . count($employees_data) . " employee(s) and submitted to Finance for review.", $actor_id);

        $pdo->commit();

        return [
            'ok'        => true,
            'period_id' => $period_id,
            'message'   => "Payroll run created with " . count($employees_data) . " employee(s) and submitted for Finance review."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('create_step_payroll_run failed: ' . 'Service temporarily unavailable.');
        return ['ok' => false, 'error' => 'Failed to create payroll run: ' . 'Service temporarily unavailable.'];
    }
}

/**
 * Fetch employee roster with calculated payroll estimates and payment details on file.
 */
function get_employee_roster_for_payroll(PDO $pdo, string $start = '', string $end = '', string $frequency = 'semimonthly'): array {
    $stmt = $pdo->query("
        SELECT e.id, e.employee_code, e.firstname, e.lastname, e.position, e.department,
               e.branch, e.pay_type, e.pay_rate, e.commission_rate, e.payment_method, e.tax_exempt,
               epd.payout_type, epd.bank_code, epd.bank_name, epd.account_name, epd.account_number_last4,
               epd.ewallet_provider, epd.ewallet_account_name, epd.ewallet_mobile_number
        FROM employees e
        LEFT JOIN employee_payment_details epd ON epd.employee_id = e.id AND epd.is_active = 1
        WHERE e.status = 'active'
        ORDER BY e.lastname, e.firstname
    ");
    $employees = $stmt->fetchAll();

    $roster = [];
    foreach ($employees as $emp) {
        $eid = (int)$emp['id'];
        $time = ($start && $end) ? aggregate_attendance($eid, $start, $end) : ['regular_hours' => 0, 'days_worked' => 0, 'overtime_hours' => 0];
        $sales = ($start && $end) ? employee_sales_in_period($eid, $start, $end) : ['sales' => 0, 'tips' => 0];
        $rates = derive_rates($emp);

        // Basic calculation baseline strictly synced to clock in / clock out attendance
        $std_hours  = payroll_setting_num('standard_hours_per_day', 8);
        $paid_hours = (float)($time['regular_hours'] ?? 0) + ((float)($time['paid_leave_days'] ?? 0) * (float)$std_hours);
        $basic      = round($paid_hours * (float)$rates['hourly'], 2);
        $ot_pay     = round($time['overtime_hours'] * $rates['hourly'] * 1.25, 2);
        $comm       = round($sales['sales'] * ((float)$emp['commission_rate'] / 100), 2);
        $tips       = round($sales['tips'], 2);
        $gross      = round($basic + $ot_pay + $comm + $tips, 2);

        // Standard Philippine Statutory Deductions (SSS, PhilHealth, Pag-IBIG, BIR TRAIN Tax + Loans)
        $ph_stat    = compute_ph_statutory_deductions($basic, $gross, $emp, $frequency);
        $sss        = $ph_stat['sss'];
        $philhealth = $ph_stat['philhealth'];
        $pagibig    = $ph_stat['pagibig'];
        $tax        = $ph_stat['withholding_tax'];
        $active_loan= $ph_stat['loan_deduction'];
        $deductions = $ph_stat['total_deductions'];
        $net        = max(0, round($gross - $deductions, 2));

        // Format payment destination display
        $dest_display = 'Not configured (Cash)';
        $p_type = $emp['payout_type'] ?: ($emp['payment_method'] === 'bank_transfer' ? 'bank' : ($emp['payment_method'] === 'ewallet' ? 'ewallet' : 'cash'));
        if ($p_type === 'bank' && !empty($emp['bank_name'])) {
            $dest_display = $emp['bank_name'] . ' •••• ' . ($emp['account_number_last4'] ?: '0000');
        } elseif ($p_type === 'ewallet' && !empty($emp['ewallet_provider'])) {
            $dest_display = strtoupper($emp['ewallet_provider']) . ' ' . ($emp['ewallet_mobile_number'] ?: '09XXXXXXXXX');
        } elseif ($emp['payment_method'] === 'cash') {
            $dest_display = 'Cash / Over-The-Counter';
        }

        $roster[] = [
            'id'                     => $eid,
            'employee_code'          => $emp['employee_code'],
            'name'                   => trim($emp['firstname'] . ' ' . $emp['lastname']),
            'firstname'              => $emp['firstname'],
            'lastname'               => $emp['lastname'],
            'position'               => $emp['position'],
            'department'             => $emp['department'] ?: 'Store Operations',
            'branch'                 => $emp['branch'] ?: 'Main',
            'pay_type'               => $emp['pay_type'],
            'pay_rate'               => (float)$emp['pay_rate'],
            'regular_hours'          => $time['regular_hours'],
            'days_worked'            => $time['days_worked'],
            'overtime_hours'         => $time['overtime_hours'],
            'basic_pay'              => $basic,
            'overtime_pay'           => $ot_pay,
            'commission'             => $comm,
            'tips'                   => $tips,
            'allowances'             => 0.00,
            'bonus'                  => 0.00,
            'gross_pay'              => $gross,
            'sss'                    => $sss,
            'philhealth'             => $philhealth,
            'pagibig'                => $pagibig,
            'withholding_tax'        => $tax,
            'loan_deduction'         => $active_loan,
            'statutory_total'        => $ph_stat['statutory_total'],
            'other_deduction'        => 0.00,
            'total_deductions'       => $deductions,
            'net_pay'                => $net,
            'payment_method'         => $emp['payment_method'] ?: 'cash',
            'payout_type'            => $p_type,
            'bank_name'              => $emp['bank_name'],
            'bank_code'              => $emp['bank_code'],
            'account_number_last4'   => $emp['account_number_last4'],
            'ewallet_provider'       => $emp['ewallet_provider'],
            'ewallet_mobile_number'  => $emp['ewallet_mobile_number'],
            'destination_display'    => $dest_display,
        ];
    }

    return $roster;
}


/** The employee record tied to a login account, if any. */
function employee_for_user(int $user_id): ?array {
    try {
        $stmt = get_db()->prepare('SELECT * FROM employees WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => $user_id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Render a unified, enterprise sub-navigation bar across the Payroll suite
 * (Runs, Reports & Analytics, Settings & Loans, My Payslips).
 */
function render_payroll_subnav(string $active_tab = 'runs', ?string $trail_label = null): string {
    require_once __DIR__ . '/permissions.php';
    require_once __DIR__ . '/icons.php';

    $can_view_all = has_permission('payroll.view');
    $can_settings = has_permission('payroll.settings') || has_permission('payroll.loans') || has_permission('payroll.manage');
    $can_own      = has_permission('payroll.own') || !empty($_SESSION['user_id']);

    $items = [];
    if ($can_view_all) {
        $items[] = [
            'key'   => 'runs',
            'label' => 'Payroll Runs',
            'url'   => 'payroll.php',
            'icon'  => 'coin'
        ];
        $items[] = [
            'key'   => 'reports',
            'label' => 'Reports & Analytics',
            'url'   => 'payroll_reports.php',
            'icon'  => 'bar-chart'
        ];
    }
    if ($can_settings) {
        $items[] = [
            'key'   => 'settings',
            'label' => 'Settings & Loans',
            'url'   => 'payroll_settings.php',
            'icon'  => 'permissions'
        ];
    }
    if ($can_own) {
        $items[] = [
            'key'   => 'my_payslips',
            'label' => 'My Compensation',
            'url'   => 'my_payslips.php',
            'icon'  => 'file-text'
        ];
    }

    $out = '<nav class="pr-subnav-bar no-print" aria-label="Payroll Navigation">';
    $out .= '<div class="pr-subnav-tabs">';
    foreach ($items as $it) {
        $isActive = ($active_tab === $it['key']) ? ' is-active' : '';
        $out .= '<a href="' . htmlspecialchars($it['url']) . '" class="pr-subnav-tab' . $isActive . '">';
        $out .= icon($it['icon'], 15);
        $out .= '<span>' . htmlspecialchars($it['label']) . '</span>';
        $out .= '</a>';
    }
    $out .= '</div>';

    if ($trail_label) {
        $out .= '<div class="pr-subnav-trail">';
        $out .= icon('chevron', 13);
        $out .= '<span>' . htmlspecialchars($trail_label) . '</span>';
        $out .= '</div>';
    }

    $out .= '</nav>';
    return $out;
}