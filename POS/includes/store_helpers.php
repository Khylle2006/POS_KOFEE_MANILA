<?php
// ==============================================================================
// FILE: includes/store_helpers.php
// Store Hours, Operational Status, and Operations Activity Logging
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/db.php';

/**
 * Fetch all branches.
 */
function get_all_branches(PDO $pdo, bool $active_only = true): array {
    $sql = "SELECT * FROM `branches` " . ($active_only ? "WHERE `status` = 'active'" : "") . " ORDER BY `name` ASC";
    return $pdo->query($sql)->fetchAll();
}

/**
 * Fetch a single branch by ID.
 */
function get_branch_by_id(PDO $pdo, int $branch_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM `branches` WHERE `id` = :id");
    $stmt->execute([':id' => $branch_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get the weekly store hours for a branch (days 0-6).
 */
function get_branch_store_hours(PDO $pdo, int $branch_id): array {
    $stmt = $pdo->prepare("SELECT * FROM `store_hours` WHERE `branch_id` = :bid ORDER BY `day_of_week` ASC");
    $stmt->execute([':bid' => $branch_id]);
    $rows = $stmt->fetchAll();

    // Map indexed by day_of_week (0 to 6)
    $mapped = [];
    foreach ($rows as $r) {
        $mapped[(int)$r['day_of_week']] = $r;
    }

    // Ensure all 7 days exist in return array
    for ($d = 0; $d <= 6; $d++) {
        if (!isset($mapped[$d])) {
            $mapped[$d] = [
                'branch_id'    => $branch_id,
                'day_of_week'  => $d,
                'open_time'    => '07:00:00',
                'close_time'   => '22:00:00',
                'is_closed'    => 0,
                'is_overnight' => 0,
            ];
        }
    }
    ksort($mapped);
    return $mapped;
}

/**
 * Day name label helper.
 */
function get_day_name(int $day_of_week): string {
    $days = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];
    return $days[$day_of_week] ?? "Day $day_of_week";
}

/**
 * Determine real-time store operational status.
 * Returns: ['status' => 'open'|'closed'|'opening_soon'|'closing_soon', 'label' => '...', 'badge' => '...', 'message' => '...']
 */
function calculate_store_status(array $hours_map, ?DateTime $current_time = null): array {
    if (!$current_time) {
        $tz = new DateTimeZone('Asia/Manila');
        $current_time = new DateTime('now', $tz);
    }

    $today_dow = (int)$current_time->format('w'); // 0 (Sun) to 6 (Sat)
    $yesterday_dow = ($today_dow + 6) % 7;

    $now_ts = strtotime($current_time->format('H:i:s'));
    $today_schedule = $hours_map[$today_dow] ?? null;
    $yesterday_schedule = $hours_map[$yesterday_dow] ?? null;

    // 1. Check if yesterday was an overnight schedule extending into today's early morning
    if ($yesterday_schedule && empty($yesterday_schedule['is_closed']) && !empty($yesterday_schedule['is_overnight'])) {
        $yesterday_close_ts = strtotime($yesterday_schedule['close_time']);
        $yesterday_open_ts = strtotime($yesterday_schedule['open_time']);
        // If close_time < open_time (overnight), and now is between 00:00 and close_time
        if ($yesterday_close_ts < $yesterday_open_ts && $now_ts < $yesterday_close_ts) {
            $mins_to_close = round(($yesterday_close_ts - $now_ts) / 60);
            if ($mins_to_close <= 45) {
                return [
                    'status' => 'closing_soon',
                    'label'  => 'Closing Soon',
                    'badge'  => 'status-pending',
                    'message'=> "Overnight schedule closing in {$mins_to_close} minutes (at " . date('g:i A', $yesterday_close_ts) . ").",
                    'is_open'=> true,
                ];
            }
            return [
                'status' => 'open',
                'label'  => 'Open',
                'badge'  => 'status-approved',
                'message'=> "Open (Overnight shift from yesterday until " . date('g:i A', $yesterday_close_ts) . ").",
                'is_open'=> true,
            ];
        }
    }

    // 2. Check today's schedule
    if (!$today_schedule || !empty($today_schedule['is_closed'])) {
        // Closed today
        // Find next open day
        $next_open_msg = "Store is closed today.";
        for ($i = 1; $i <= 7; $i++) {
            $next_dow = ($today_dow + $i) % 7;
            if (!empty($hours_map[$next_dow]) && empty($hours_map[$next_dow]['is_closed'])) {
                $open_fmt = date('g:i A', strtotime($hours_map[$next_dow]['open_time']));
                $next_open_msg = "Closed today. Opens " . ($i === 1 ? "tomorrow" : get_day_name($next_dow)) . " at {$open_fmt}.";
                break;
            }
        }
        return [
            'status' => 'closed',
            'label'  => 'Closed',
            'badge'  => 'status-rejected',
            'message'=> $next_open_msg,
            'is_open'=> false,
        ];
    }

    $open_ts  = strtotime($today_schedule['open_time']);
    $close_ts = strtotime($today_schedule['close_time']);
    $is_overnight = !empty($today_schedule['is_overnight']);

    // If overnight, closing is next day
    if ($is_overnight && $close_ts < $open_ts) {
        // Open time until midnight
        if ($now_ts >= $open_ts) {
            return [
                'status' => 'open',
                'label'  => 'Open (Overnight)',
                'badge'  => 'status-approved',
                'message'=> "Open tonight until " . date('g:i A', $close_ts) . " tomorrow.",
                'is_open'=> true,
            ];
        }
        // Before opening today
        $mins_to_open = round(($open_ts - $now_ts) / 60);
        if ($mins_to_open <= 45 && $mins_to_open > 0) {
            return [
                'status' => 'opening_soon',
                'label'  => 'Opening Soon',
                'badge'  => 'status-pending',
                'message'=> "Opening soon in {$mins_to_open} minutes (at " . date('g:i A', $open_ts) . ").",
                'is_open'=> false,
            ];
        }
        return [
            'status' => 'closed',
            'label'  => 'Closed',
            'badge'  => 'status-rejected',
            'message'=> "Closed now. Opens tonight at " . date('g:i A', $open_ts) . ".",
            'is_open'=> false,
        ];
    }

    // Normal day schedule
    if ($now_ts >= $open_ts && $now_ts < $close_ts) {
        $mins_to_close = round(($close_ts - $now_ts) / 60);
        if ($mins_to_close <= 45) {
            return [
                'status' => 'closing_soon',
                'label'  => 'Closing Soon',
                'badge'  => 'status-pending',
                'message'=> "Closing soon in {$mins_to_close} minutes (at " . date('g:i A', $close_ts) . ").",
                'is_open'=> true,
            ];
        }
        return [
            'status' => 'open',
            'label'  => 'Open',
            'badge'  => 'status-approved',
            'message'=> "Open today until " . date('g:i A', $close_ts) . ".",
            'is_open'=> true,
        ];
    }

    if ($now_ts < $open_ts) {
        $mins_to_open = round(($open_ts - $now_ts) / 60);
        if ($mins_to_open <= 45) {
            return [
                'status' => 'opening_soon',
                'label'  => 'Opening Soon',
                'badge'  => 'status-pending',
                'message'=> "Opening soon in {$mins_to_open} minutes (at " . date('g:i A', $open_ts) . ").",
                'is_open'=> false,
            ];
        }
        return [
            'status' => 'closed',
            'label'  => 'Closed',
            'badge'  => 'status-rejected',
            'message'=> "Closed now. Opens today at " . date('g:i A', $open_ts) . ".",
            'is_open'=> false,
        ];
    }

    // After close_ts
    // Find next opening
    $next_open_msg = "Closed for the day.";
    for ($i = 1; $i <= 7; $i++) {
        $next_dow = ($today_dow + $i) % 7;
        if (!empty($hours_map[$next_dow]) && empty($hours_map[$next_dow]['is_closed'])) {
            $open_fmt = date('g:i A', strtotime($hours_map[$next_dow]['open_time']));
            $next_open_msg = "Closed for the day. Opens " . ($i === 1 ? "tomorrow" : get_day_name($next_dow)) . " at {$open_fmt}.";
            break;
        }
    }
    return [
        'status' => 'closed',
        'label'  => 'Closed',
        'badge'  => 'status-rejected',
        'message'=> $next_open_msg,
        'is_open'=> false,
    ];
}

/**
 * Update branch store hours with validation and audit logging.
 */
function update_branch_store_hours(PDO $pdo, int $branch_id, array $days_data, int $user_id, string $user_name = '', string $reason = ''): array {
    $branch = get_branch_by_id($pdo, $branch_id);
    if (!$branch) {
        return ['ok' => false, 'error' => 'Branch not found.'];
    }

    $existing = get_branch_store_hours($pdo, $branch_id);

    try {
        $pdo->beginTransaction();

        $upsert_stmt = $pdo->prepare("
            INSERT INTO `store_hours` 
                (`branch_id`, `day_of_week`, `open_time`, `close_time`, `is_closed`, `is_overnight`)
            VALUES 
                (:bid, :day, :open, :close, :closed, :overnight)
            ON DUPLICATE KEY UPDATE
                `open_time` = VALUES(`open_time`),
                `close_time` = VALUES(`close_time`),
                `is_closed` = VALUES(`is_closed`),
                `is_overnight` = VALUES(`is_overnight`)
        ");

        $log_stmt = $pdo->prepare("
            INSERT INTO `store_hour_logs`
                (`branch_id`, `day_of_week`, `changed_by`, 
                 `prior_open_time`, `prior_close_time`, `prior_is_closed`, `prior_is_overnight`,
                 `new_open_time`, `new_close_time`, `new_is_closed`, `new_is_overnight`, `reason`)
            VALUES
                (:bid, :day, :uid, :po, :pc, :pic, :pio, :no, :nc, :nic, :nio, :r)
        ");

        $changes_count = 0;

        for ($d = 0; $d <= 6; $d++) {
            if (!isset($days_data[$d])) continue;

            $d_data = $days_data[$d];
            $is_closed = !empty($d_data['is_closed']) ? 1 : 0;
            $is_overnight = !empty($d_data['is_overnight']) ? 1 : 0;
            $open_time = !empty($d_data['open_time']) ? trim($d_data['open_time']) : '07:00:00';
            $close_time = !empty($d_data['close_time']) ? trim($d_data['close_time']) : '22:00:00';

            // Ensure format HH:MM:SS
            if (strlen($open_time) === 5) $open_time .= ':00';
            if (strlen($close_time) === 5) $close_time .= ':00';

            // Validation: unless is_closed, closing time must be later than opening time,
            // EXCEPT when an approved overnight schedule is selected!
            if (!$is_closed) {
                $open_ts = strtotime($open_time);
                $close_ts = strtotime($close_time);

                if ($close_ts <= $open_ts && !$is_overnight) {
                    throw new Exception("For " . get_day_name($d) . ", closing time (" . date('g:i A', $close_ts) . ") must be later than opening time (" . date('g:i A', $open_ts) . ") unless an overnight schedule is selected.");
                }
            }

            $cur = $existing[$d] ?? null;
            $has_changed = (
                !$cur ||
                $cur['open_time'] !== $open_time ||
                $cur['close_time'] !== $close_time ||
                (int)$cur['is_closed'] !== $is_closed ||
                (int)$cur['is_overnight'] !== $is_overnight
            );

            if ($has_changed) {
                $upsert_stmt->execute([
                    ':bid'       => $branch_id,
                    ':day'       => $d,
                    ':open'      => $open_time,
                    ':close'     => $close_time,
                    ':closed'    => $is_closed,
                    ':overnight' => $is_overnight,
                ]);

                $log_stmt->execute([
                    ':bid' => $branch_id,
                    ':day' => $d,
                    ':uid' => $user_id,
                    ':po'  => $cur['open_time'] ?? null,
                    ':pc'  => $cur['close_time'] ?? null,
                    ':pic' => isset($cur['is_closed']) ? (int)$cur['is_closed'] : null,
                    ':pio' => isset($cur['is_overnight']) ? (int)$cur['is_overnight'] : null,
                    ':no'  => $open_time,
                    ':nc'  => $close_time,
                    ':nic' => $is_closed,
                    ':nio' => $is_overnight,
                    ':r'   => $reason ?: 'Routine schedule update',
                ]);

                $changes_count++;
            }
        }

        if ($changes_count > 0) {
            log_operations_activity(
                $pdo,
                module: 'store_hours',
                action: 'hours_updated',
                record_ref: $branch['code'],
                status: 'success',
                user_id: $user_id,
                user_name: $user_name,
                details: "Updated operating hours for {$branch['name']} ($changes_count day(s) adjusted). Reason: " . ($reason ?: 'Routine schedule update'),
                branch_id: $branch_id,
                branch_name: $branch['name']
            );
        }

        $pdo->commit();
        return ['ok' => true, 'changes_count' => $changes_count];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Fetch store hour logs for a branch or all branches.
 */
function get_store_hour_logs(PDO $pdo, ?int $branch_id = null, int $limit = 50): array {
    $where = $branch_id ? "WHERE shl.branch_id = :bid" : "";
    $sql = "
        SELECT shl.*, b.name AS branch_name, b.code AS branch_code, u.username, u.firstname, u.lastname
        FROM `store_hour_logs` shl
        JOIN `branches` b ON b.id = shl.branch_id
        LEFT JOIN `users` u ON u.id = shl.changed_by
        $where
        ORDER BY shl.created_at DESC
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($sql);
    if ($branch_id) {
        $stmt->bindValue(':bid', $branch_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Log an operational event to the centralized operations_activity table.
 */
function log_operations_activity(
    PDO $pdo,
    string $module,
    string $action,
    ?string $record_ref,
    string $status = 'info',
    ?int $user_id = null,
    ?string $user_name = null,
    ?string $details = null,
    ?int $branch_id = null,
    ?string $branch_name = null
): int {
    if ($user_id && !$user_name) {
        $u_stmt = $pdo->prepare("SELECT username, firstname, lastname FROM `users` WHERE `id` = :id");
        $u_stmt->execute([':id' => $user_id]);
        $u = $u_stmt->fetch();
        if ($u) {
            $user_name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: $u['username'];
        }
    }

    if ($branch_id && !$branch_name) {
        $b_stmt = $pdo->prepare("SELECT name FROM `branches` WHERE `id` = :id");
        $b_stmt->execute([':id' => $branch_id]);
        $branch_name = $b_stmt->fetchColumn() ?: null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO `operations_activity`
            (`branch_id`, `branch_name`, `module`, `action`, `record_ref`, `status`, `user_id`, `user_name`, `details`, `created_at`)
        VALUES
            (:bid, :bname, :mod, :act, :ref, :st, :uid, :uname, :det, NOW())
    ");
    $stmt->execute([
        ':bid'   => $branch_id,
        ':bname' => $branch_name,
        ':mod'   => $module,
        ':act'   => $action,
        ':ref'   => $record_ref,
        ':st'    => in_array($status, ['success','pending','warning','failed','info'], true) ? $status : 'info',
        ':uid'   => $user_id,
        ':uname' => $user_name,
        ':det'   => $details,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetch filtered operations activity list.
 */
function get_operations_activity(PDO $pdo, array $filters = [], int $limit = 100): array {
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['branch_id'])) {
        $where[] = "oa.branch_id = :bid";
        $params[':bid'] = (int)$filters['branch_id'];
    }
    if (!empty($filters['module']) && $filters['module'] !== 'all') {
        $where[] = "oa.module = :mod";
        $params[':mod'] = $filters['module'];
    }
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "oa.status = :st";
        $params[':st'] = $filters['status'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = "oa.user_id = :uid";
        $params[':uid'] = (int)$filters['user_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = "oa.created_at >= :dfrom";
        $params[':dfrom'] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = "oa.created_at <= :dto";
        $params[':dto'] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $where[] = "(oa.record_ref LIKE :srch OR oa.details LIKE :srch OR oa.action LIKE :srch)";
        $params[':srch'] = '%' . $filters['search'] . '%';
    }

    $where_sql = implode(' AND ', $where);
    $sql = "
        SELECT oa.*
        FROM `operations_activity` oa
        WHERE $where_sql
        ORDER BY oa.created_at DESC
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
