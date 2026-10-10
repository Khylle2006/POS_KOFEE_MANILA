<?php
// ==============================================================================
// FILE: includes/login_approval_helpers.php
// Login Geolocation & HR Authorization Management System
// Enforces restricted workplace-only logins with HR review and approval.
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_security.php';
require_once __DIR__ . '/security.php';
if (file_exists(__DIR__ . '/notify.php')) {
    require_once __DIR__ . '/notify.php';
}

/**
 * Ensure database tables for Login Geolocation & HR Authorizations exist.
 */
function ensure_login_approval_tables(PDO $pdo): void {
    require_runtime_schema(get_db());
}

/**
 * Retrieve configuration settings for Login Approvals & Geolocation.
 */
function get_login_approval_settings(PDO $pdo): array {
    ensure_login_approval_tables($pdo);
    $st = $pdo->query("SELECT setting_key, setting_value FROM login_approval_settings");
    $raw = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'enabled'              => ($raw['require_approval_enabled'] ?? '1') === '1',
        'exempt_roles'         => array_filter(array_map('trim', explode(',', strtolower($raw['exempt_roles'] ?? 'admin,hr')))),
        'store_name'           => $raw['store_name'] ?? 'Kofee Manila (Main Store)',
        'store_lat'            => isset($raw['store_latitude']) && is_numeric($raw['store_latitude']) ? (float)$raw['store_latitude'] : 14.3294,
        'store_lon'            => isset($raw['store_longitude']) && is_numeric($raw['store_longitude']) ? (float)$raw['store_longitude'] : 120.9367,
        'geofence_radius'      => !empty($raw['store_geofence_radius_meters']) ? (float)$raw['store_geofence_radius_meters'] : 200.0,
        'auto_approve_in_zone' => ($raw['auto_approve_within_geofence'] ?? '0') === '1',
    ];
}

/** Validate settings before any writes; role keys come from the current role catalog. */
function validate_login_approval_settings(array $input, array $availableRoles): array {
    $name = $input['store_name'] ?? '';
    if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 120) {
        throw new InvalidArgumentException('Enter a store name of up to 120 characters.');
    }
    $numbers = [];
    foreach (['store_latitude' => [-90, 90], 'store_longitude' => [-180, 180], 'store_geofence_radius_meters' => [20, 10000]] as $key => [$min, $max]) {
        $value = $input[$key] ?? '';
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max) {
            throw new InvalidArgumentException('Enter valid coordinates (latitude -90 to 90, longitude -180 to 180) and a radius of 20 to 10,000 meters.');
        }
        $numbers[$key] = (string)(float)$value;
    }
    $roles = $input['exempt_roles'] ?? [];
    $knownRoles = array_column($availableRoles, 'role_key');
    if (!is_array($roles)) {
        throw new InvalidArgumentException('Choose bypass roles from the existing role list.');
    }
    foreach ($roles as $role) {
        if (!is_string($role) || !in_array($role, $knownRoles, true)) {
            throw new InvalidArgumentException('Choose bypass roles from the existing role list.');
        }
    }
    return $numbers + [
        'store_name' => trim($name),
        'require_approval_enabled' => isset($input['require_approval_enabled']) ? '1' : '0',
        // Browser coordinates remain review context, never a device-trust credential.
        'auto_approve_within_geofence' => '0',
        'exempt_roles' => implode(',', array_unique($roles)),
    ];
}

/**
 * Update configuration settings.
 */
function save_login_approval_settings(PDO $pdo, array $settings): void {
    ensure_login_approval_tables($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO login_approval_settings (setting_key, setting_value)
        VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $pdo->beginTransaction();
    try {
        foreach ($settings as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => (string)$v]);
        }
        security_audit($pdo, 'login_approval_settings_changed', 'login_approval_settings', null, [
            'decision' => ($settings['require_approval_enabled'] ?? '1') === '1' ? 'enabled' : 'disabled',
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Calculate Great-Circle distance in meters using Haversine formula.
 */
function calculate_geodistance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // in meters
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDelta / 2) * sin($lonDelta / 2);

    $c = 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    return round($earthRadius * $c, 1);
}

/**
 * Check if user role requires HR authorization before logging in.
 */
function does_user_require_login_approval(PDO $pdo, array $user): bool {
    $settings = get_login_approval_settings($pdo);
    if (!$settings['enabled']) {
        return false;
    }
    $roles = $user['roles'] ?? [];
    $roles[] = $user['role'] ?? '';
    return array_intersect($roles, $settings['exempt_roles']) === [];
}

/**
 * Check if this device fingerprint was previously trusted and not yet expired.
 */
function is_device_trusted(PDO $pdo, int $userId, string $deviceHash): bool {
    return trusted_device($pdo, $userId);
}

/**
 * Generate human-readable device name from User Agent.
 */
function parse_device_info(string $userAgent): string {
    $os = 'Unknown OS';
    if (stripos($userAgent, 'Windows NT 10') !== false) $os = 'Windows 10/11';
    elseif (stripos($userAgent, 'Windows NT 6.3') !== false) $os = 'Windows 8.1';
    elseif (stripos($userAgent, 'Windows NT 6.1') !== false) $os = 'Windows 7';
    elseif (stripos($userAgent, 'Android') !== false) $os = 'Android Device';
    elseif (stripos($userAgent, 'iPhone') !== false) $os = 'Apple iPhone';
    elseif (stripos($userAgent, 'iPad') !== false) $os = 'Apple iPad';
    elseif (stripos($userAgent, 'Macintosh') !== false) $os = 'macOS';
    elseif (stripos($userAgent, 'Linux') !== false) $os = 'Linux';

    $browser = 'Browser';
    if (stripos($userAgent, 'Edg/') !== false) $browser = 'Edge';
    elseif (stripos($userAgent, 'Chrome') !== false) $browser = 'Chrome';
    elseif (stripos($userAgent, 'Safari') !== false) $browser = 'Safari';
    elseif (stripos($userAgent, 'Firefox') !== false) $browser = 'Firefox';

    return "{$os} · {$browser}";
}

/**
 * Register a new login authorization attempt with captured geolocation.
 */
function create_login_authorization(
    PDO $pdo,
    array $user,
    ?float $latitude,
    ?float $longitude,
    ?float $accuracy,
    string $locationStatus,
    string $deviceInfo = ''
): array {
    ensure_login_approval_tables($pdo);

    $cfg = get_login_approval_settings($pdo);
    $authToken = bin2hex(random_bytes(32));
    $ip = client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User-Agent';

    if ($deviceInfo === '') {
        $deviceInfo = parse_device_info($userAgent);
    }
    $deviceHash = hash('sha256', $user['id'] . '|' . $userAgent . '|' . substr($ip, 0, strrpos($ip, '.')));

    // Calculate distance from store branch if coordinates are present
    $distanceMeters = null;
    $locationName = 'Pending Geolocation';

    if ($latitude !== null && $longitude !== null) {
        $distanceMeters = calculate_geodistance_meters($latitude, $longitude, $cfg['store_lat'], $cfg['store_lon']);

        if ($distanceMeters <= $cfg['geofence_radius']) {
            $locationName = "Inside {$cfg['store_name']} Premises (" . round($distanceMeters) . "m)";
        } else {
            $km = round($distanceMeters / 1000, 2);
            $locationName = "Outside Workplace (" . ($km >= 1 ? "{$km} km away" : round($distanceMeters) . "m away") . ")";
        }
    } elseif ($locationStatus === 'denied' || $locationStatus === 'permission_denied') {
        $locationName = "⚠️ Browser Location Permission Denied (IP: {$ip})";
    } else {
        $locationName = "Location Unavailable (IP: {$ip})";
    }

    // Browser coordinates provide context for HR, never an authentication factor.
    $initialStatus = 'pending';

    $expiresAt = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes validity

    $ins = $pdo->prepare("
        INSERT INTO login_authorizations (
            user_id, username, auth_token, status, ip_address, user_agent,
            device_info, device_hash, latitude, longitude, accuracy_meters,
            location_status, location_name, distance_meters, expires_at
        ) VALUES (
            :uid, :uname, :token, :st, :ip, :ua,
            :dev, :hash, :lat, :lon, :acc,
            :loc_st, :loc_name, :dist, :exp
        )
    ");
    $ins->execute([
        ':uid'      => $user['id'],
        ':uname'    => $user['username'],
        ':token'    => $authToken,
        ':st'       => $initialStatus,
        ':ip'       => $ip,
        ':ua'       => $userAgent,
        ':dev'      => $deviceInfo,
        ':hash'     => $deviceHash,
        ':lat'      => $latitude,
        ':lon'      => $longitude,
        ':acc'      => $accuracy,
        ':loc_st'   => $locationStatus,
        ':loc_name' => $locationName,
        ':dist'     => $distanceMeters,
        ':exp'      => $expiresAt,
    ]);
    $authId = (int)$pdo->lastInsertId();

    // Send high-priority notification to HR & users holding login approval permission
    if ($initialStatus === 'pending') {
        notify_role_by_permission(
            'login_approval.manage',
            'login_approval',
            'Staff Login Request Awaiting Review',
            "{$user['username']} ({$user['role']}) is attempting to log in from {$locationName}. Click to review & approve.",
            'login_approvals.php?highlight=' . $authId
        );
    }

    return [
        'auth_id'         => $authId,
        'auth_token'      => $authToken,
        'status'          => $initialStatus,
        'distance_meters' => $distanceMeters,
        'location_name'   => $locationName,
        'expires_at'      => $expiresAt,
    ];
}

/**
 * Retrieve login authorization details by auth token.
 */
function get_login_authorization_by_token(PDO $pdo, string $token): ?array {
    ensure_login_approval_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT la.*, u.firstname, u.lastname, u.email, u.role, u.avatar_path
        FROM login_authorizations la
        JOIN users u ON u.id = la.user_id
        WHERE la.auth_token = :t
        LIMIT 1
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Approve a login authorization attempt (invoked by HR).
 */
function approve_login_authorization(PDO $pdo, int $authId, int $reviewerId, int $trustDays = 0): array {
    ensure_login_approval_tables($pdo);
    $pdo->beginTransaction();
    try {
    $stmt = $pdo->prepare("SELECT * FROM login_authorizations WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $authId]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Login authorization record not found.'];
    }
    if ($auth['status'] !== 'pending') {
        $pdo->rollBack();
        return ['ok' => false, 'error' => "This login request is already {$auth['status']}."];
    }

    $update = $pdo->prepare("
        UPDATE login_authorizations
        SET status = 'approved',
            approved_by = :uid,
            approved_at = NOW()
        WHERE id = :id AND status = 'pending' AND expires_at > NOW()
    ");
    $update->execute([':uid' => $reviewerId, ':id' => $authId]);
    if ($update->rowCount() !== 1) throw new SecurityFault('LOGIN_APPROVAL_EXPIRED', 'Login request has expired.', 409);
    security_audit($pdo, 'device_login_approved', 'login_authorization', $authId, ['decision' => 'approved']);
    $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    // Trust is issued only to the browser that consumes this approved request.

    // Send confirmation notification to the employee
    notify_user(
        (int)$auth['user_id'],
        'login_approved',
        'Login Request Approved by HR',
        'Your login attempt from ' . ($auth['location_name'] ?: 'your current location') . ' has been approved. You may proceed.',
        'index.php'
    );

    return ['ok' => true, 'auth_id' => $authId, 'message' => 'Login request successfully approved!'];
}

/**
 * Reject a login authorization attempt (invoked by HR).
 */
function reject_login_authorization(PDO $pdo, int $authId, int $reviewerId, string $reason): array {
    ensure_login_approval_tables($pdo);
    $pdo->beginTransaction();
    try {
    $stmt = $pdo->prepare("SELECT * FROM login_authorizations WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $authId]);
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Login authorization record not found.'];
    }
    if ($auth['status'] !== 'pending') {
        $pdo->rollBack();
        return ['ok' => false, 'error' => "This login request is already {$auth['status']}."];
    }

    $reason = trim($reason) ?: 'Location or device not authorized by HR.';

    $update = $pdo->prepare("
        UPDATE login_authorizations
        SET status = 'rejected',
            rejection_reason = :reason,
            rejected_by = :uid,
            rejected_at = NOW()
        WHERE id = :id AND status = 'pending' AND expires_at > NOW()
    ");
    $update->execute([':reason' => $reason, ':uid' => $reviewerId, ':id' => $authId]);
    if ($update->rowCount() !== 1) throw new SecurityFault('LOGIN_APPROVAL_EXPIRED', 'Login request has expired.', 409);
    security_audit($pdo, 'device_login_rejected', 'login_authorization', $authId, ['decision' => 'rejected']);
    $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    notify_user(
        (int)$auth['user_id'],
        'login_rejected',
        'Login Attempt Denied by HR',
        "Your login attempt was rejected by HR: {$reason}",
        'auth/login.php'
    );

    return ['ok' => true, 'auth_id' => $authId, 'message' => 'Login request rejected.'];
}

/**
 * Retrieve count of pending login authorizations awaiting HR action.
 */
function count_pending_login_authorizations(PDO $pdo): int {
    try {
        ensure_login_approval_tables($pdo);
        return (int)$pdo->query("SELECT COUNT(*) FROM login_authorizations WHERE status = 'pending' AND expires_at > NOW()")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * List pending login requests for HR review queue.
 */
function get_pending_login_authorizations(PDO $pdo): array {
    ensure_login_approval_tables($pdo);
    $stmt = $pdo->query("
        SELECT la.*, u.firstname, u.lastname, u.email, u.role, u.avatar_path,
               e.id AS emp_id, e.employee_code, e.position, e.department, e.branch,
               (SELECT att.time_in_photo 
                  FROM attendance att 
                 WHERE att.employee_id = e.id AND att.time_in_photo IS NOT NULL AND att.time_in_photo != ''
                 ORDER BY att.id DESC LIMIT 1) AS selfie_photo
        FROM login_authorizations la
        JOIN users u ON u.id = la.user_id
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE la.status = 'pending' AND la.expires_at > NOW()
        ORDER BY la.id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * List recent login authorization history.
 */
function get_login_authorizations_history(PDO $pdo, int $limit = 60): array {
    ensure_login_approval_tables($pdo);
    $stmt = $pdo->prepare("
        SELECT la.*, u.firstname, u.lastname, u.role, u.avatar_path,
               e.id AS emp_id, e.employee_code, e.position, e.department, e.branch,
               app_u.firstname AS approved_by_name,
               rej_u.firstname AS rejected_by_name
        FROM login_authorizations la
        JOIN users u ON u.id = la.user_id
        LEFT JOIN employees e ON e.user_id = u.id
        LEFT JOIN users app_u ON app_u.id = la.approved_by
        LEFT JOIN users rej_u ON rej_u.id = la.rejected_by
        ORDER BY la.id DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Cleanly establishes an authenticated PHP session for a validated user.
 */
function establish_user_session(PDO $pdo, array $user): void {
    if (session_status() === PHP_SESSION_NONE) {
        secure_session_start();
    }
    session_regenerate_id(true);       // defeats session fixation
    $_SESSION = [];                    // start from a clean slate
    $_SESSION['_created'] = time();

    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['firstname'] = $user['firstname'] ?? '';
    $_SESSION['lastname']  = $user['lastname'] ?? '';
    $_SESSION['email']       = $user['email'] ?? '';
    $_SESSION['role']        = $user['role'] ?? 'crew';
    $_SESSION['avatar_path'] = $user['avatar_path'] ?? '';
    $_SESSION['logged_in']   = true;

    // Multi-role support; fall back to the legacy single role.
    try {
        $role_stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id ORDER BY role');
        $role_stmt->execute([':id' => $user['id']]);
        $all_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
        $_SESSION['roles'] = $all_roles ?: [$user['role']];
    } catch (PDOException $e) {
        $_SESSION = [];
        throw new SecurityFault('AUTHORIZATION_UNAVAILABLE', 'Sign-in is unavailable.', 503);
    }

    register_auth_session($pdo, (int)$user['id']);

    $_SESSION['permissions']        = null;
    $_SESSION['permissions_loaded'] = 0;

    try {
        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);
    } catch (PDOException $e) {
        error_log('last_login update failed: ' . 'Service temporarily unavailable.');
    }
}
