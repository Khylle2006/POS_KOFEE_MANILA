<?php
// ==============================================================================
// FILE: php/login_approvals.php
// HR Staff Login Approvals - Current Status Dashboard
// Live GPS Radar Map with Store Geofence & Employee Verification
// ==============================================================================

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_once '../includes/login_approval_helpers.php';

require_login();

$user = current_user();
$roles = $user['roles'] ?? [$user['role'] ?? 'crew'];
$canManage = has_permission('users.manage') || in_array('hr', $roles, true) || in_array('admin', $roles, true);

if (!$canManage) {
    header('Location: no_access.php');
    exit;
}

$pdo = get_db();
ensure_login_approval_tables($pdo);

$toast = '';
$toast_type = 'success';

// ── AJAX Endpoint: Live Pending Fetch ──
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_json') {
    header('Content-Type: application/json; charset=utf-8');
    $pending = get_pending_login_authorizations($pdo);
    echo json_encode([
        'ok'      => true,
        'count'   => count($pending),
        'records' => $pending
    ]);
    exit;
}

// ── AJAX Endpoint: Check if there's a new login attempt (Silent detector) ──
if (isset($_GET['action']) && $_GET['action'] === 'check_new') {
    header('Content-Type: application/json; charset=utf-8');
    $clientLastId = (int)($_GET['last_id'] ?? 0);
    $clientCount  = (int)($_GET['count'] ?? 0);

    $st = $pdo->query("SELECT COALESCE(MAX(id), 0) AS max_id, COUNT(*) AS pending_count FROM login_authorizations WHERE status = 'pending' AND expires_at > NOW()");
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $currentMaxId = (int)($row['max_id'] ?? 0);
    $currentCount = (int)($row['pending_count'] ?? 0);

    // Only triggers if a new ID arrived or count increased
    $hasNew = ($currentMaxId > $clientLastId) || ($currentCount > $clientCount);

    echo json_encode([
        'ok'      => true,
        'has_new' => $hasNew,
        'max_id'  => $currentMaxId,
        'count'   => $currentCount,
    ]);
    exit;
}

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['is_ajax']);

    if ($action === 'approve') {
        $authId = (int)($_POST['auth_id'] ?? 0);
        $trustDays = max(0, (int)($_POST['trust_days'] ?? 0));
        $res = approve_login_authorization($pdo, $authId, (int)$user['id'], $trustDays);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res);
            exit;
        }

        $toast = $res['ok'] ? 'Login authorization approved successfully.' : ($res['error'] ?? 'Approval failed.');
        $toast_type = $res['ok'] ? 'success' : 'error';
    }

    if ($action === 'reject') {
        $authId = (int)($_POST['auth_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Location or device not authorized by HR.');
        $res = reject_login_authorization($pdo, $authId, (int)$user['id'], $reason);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res);
            exit;
        }

        $toast = $res['ok'] ? 'Login attempt rejected.' : ($res['error'] ?? 'Rejection failed.');
        $toast_type = $res['ok'] ? 'success' : 'error';
    }

    if ($action === 'save_settings') {
        $enabled = isset($_POST['require_approval_enabled']) ? '1' : '0';
        $autoApprove = isset($_POST['auto_approve_within_geofence']) ? '1' : '0';
        $storeName = trim($_POST['store_name'] ?? 'Kofee Manila (Main Store)');
        $storeLat = trim($_POST['store_latitude'] ?? '14.3294');
        $storeLon = trim($_POST['store_longitude'] ?? '120.9367');
        $radius = trim($_POST['store_geofence_radius_meters'] ?? '200');
        $exemptRoles = trim($_POST['exempt_roles'] ?? 'admin,hr');

        save_login_approval_settings($pdo, [
            'require_approval_enabled'     => $enabled,
            'auto_approve_within_geofence' => $autoApprove,
            'store_name'                   => $storeName,
            'store_latitude'               => $storeLat,
            'store_longitude'              => $storeLon,
            'store_geofence_radius_meters' => $radius,
            'exempt_roles'                 => $exemptRoles,
        ]);

        $toast = 'Geofence & security settings saved successfully!';
        $toast_type = 'success';
    }

    if ($action === 'revoke_device') {
        $devId = (int)($_POST['device_id'] ?? 0);
        if ($devId > 0) {
            $pdo->prepare("DELETE FROM trusted_login_devices WHERE id = :id")->execute([':id' => $devId]);
            $toast = 'Trusted device revoked.';
            $toast_type = 'success';
        }
    }

    if (!$isAjax) {
        $tab = htmlspecialchars($_POST['current_tab'] ?? 'dashboard');
        header('Location: login_approvals.php?tab=' . urlencode($tab) . '&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }
}

// Flash Toast Messages
if (!$toast && isset($_GET['toast'])) {
    $toast = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Query Current Data ──
$settings = get_login_approval_settings($pdo);
$pendingRequests = get_pending_login_authorizations($pdo);
$historyRecords = get_login_authorizations_history($pdo, 80);

// Trusted devices list
$trustedDevices = $pdo->query("
    SELECT td.*, u.username, u.firstname, u.lastname, u.role,
           hr.firstname AS trusted_by_name
    FROM trusted_login_devices td
    JOIN users u ON u.id = td.user_id
    LEFT JOIN users hr ON hr.id = td.trusted_by
    WHERE td.expires_at > NOW()
    ORDER BY td.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$totalPending = count($pendingRequests);
$initialMaxPendingId = 0;
foreach ($pendingRequests as $pr) {
    if ((int)$pr['id'] > $initialMaxPendingId) {
        $initialMaxPendingId = (int)$pr['id'];
    }
}
$todayApproved = (int)$pdo->query("SELECT COUNT(*) FROM login_authorizations WHERE status = 'approved' AND DATE(approved_at) = CURDATE()")->fetchColumn();
$todayRejected = (int)$pdo->query("SELECT COUNT(*) FROM login_authorizations WHERE status = 'rejected' AND DATE(rejected_at) = CURDATE()")->fetchColumn();
$totalTrusted = count($trustedDevices);

// Helper for parsing device telemetry
function parse_telemetry_breakdown(?string $deviceInfo, string $userAgent): array {
    $device = 'Desktop Workstation';
    $os = 'Windows 11';

    if ($deviceInfo) {
        if (stripos($deviceInfo, 'iPhone') !== false) {
            $device = 'iPhone 13 Pro';
            $os = 'iOS 16.5';
        } elseif (stripos($deviceInfo, 'iPad') !== false) {
            $device = 'Apple iPad';
            $os = 'iPadOS 16.5';
        } elseif (stripos($deviceInfo, 'Android') !== false) {
            $device = 'Android Device';
            $os = 'Android 14';
        } elseif (stripos($deviceInfo, 'Windows') !== false) {
            $device = 'Windows Workstation';
            $os = 'Windows 11';
        } elseif (stripos($deviceInfo, 'Mac') !== false) {
            $device = 'MacBook Pro';
            $os = 'macOS Ventura';
        }
    }

    if (preg_match('/iPhone|Android|iPad|Windows|Macintosh/i', $userAgent, $m)) {
        if (stripos($userAgent, 'iPhone') !== false) $device = 'iPhone 13 Pro';
    }

    return [$device, $os];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Login Approvals — Kofee Café</title>
<?= csrf_meta() ?>
<link rel="stylesheet" href="../css/sidebar.css">
<link rel="stylesheet" href="../css/login_approvals.css?v=<?= time() ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,500&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Leaflet OpenStreetMap CDN -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-login-approvals" class="kfs-approvals-wrap">
  <div class="kfs-approvals-container">

    <!-- Toast Notification -->
    <?php if ($toast): ?>
    <div style="background:#ECFDF5; border:1px solid #10B981; color:#065F46; padding:12px 18px; border-radius:12px; margin-bottom:20px; font-size:13px; font-weight:500; display:flex; align-items:center; justify-content:space-between;">
      <span><?= $toast_type === 'success' ? '✓' : '⚠️' ?> <?= htmlspecialchars($toast) ?></span>
      <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:bold; color:#065F46;">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Top Header: Title & Real-Time Clock Bar -->
    <div class="kfs-header-row">
      <div>
        <h1 class="kfs-page-title">Staff Login Approvals - Current Status</h1>
      </div>
      <div class="kfs-time-badge">
        <span id="current_date_display"><?= date('M d, Y') ?></span>
        <span style="color:#D1D5DB;">|</span>
        <span id="current_time_display" style="font-family:monospace; font-size:13.5px; color:#1F1626; font-weight:600;"><?= date('h:i A') ?></span>
        <button type="button" onclick="refreshQueue()" class="kfs-refresh-btn" title="Refresh Live Queue">
          <svg id="refresh_icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- 4 Top Status Metric Cards (Matching Mockup) -->
    <div class="kfs-metrics-grid">
      
      <!-- Card 1: Pending Requests -->
      <div class="kfs-metric-card">
        <div class="kfs-metric-icon orange">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
            <path d="M9 14h6M9 18h4"/>
          </svg>
          <span id="stat_badge_pill" class="kfs-icon-pill">
            <?= $totalPending ?>
          </span>
        </div>
        <div class="kfs-metric-info">
          <div class="kfs-metric-label">Pending Requests</div>
          <div id="stat_pending_val" class="kfs-metric-val"><?= $totalPending ?></div>
        </div>
      </div>

      <!-- Card 2: Approved Today -->
      <div class="kfs-metric-card">
        <div class="kfs-metric-icon green">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
        <div class="kfs-metric-info">
          <div class="kfs-metric-label">Approved Today</div>
          <div class="kfs-metric-val"><?= $todayApproved ?></div>
        </div>
      </div>

      <!-- Card 3: Rejected Today -->
      <div class="kfs-metric-card">
        <div class="kfs-metric-icon red">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </div>
        <div class="kfs-metric-info">
          <div class="kfs-metric-label">Rejected Today</div>
          <div class="kfs-metric-val"><?= $todayRejected ?></div>
        </div>
      </div>

      <!-- Card 4: Store Geofence -->
      <div class="kfs-metric-card">
        <div class="kfs-metric-icon stone">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </svg>
        </div>
        <div class="kfs-metric-info">
          <div class="kfs-metric-label">Store Geofence</div>
          <div class="kfs-metric-val"><?= (int)$settings['geofence_radius'] ?>m Radius</div>
        </div>
      </div>

    </div>

    <!-- MAIN TWO-COLUMN HERO GRID (Mockup Layout) -->
    <div class="kfs-hero-grid">
      
      <!-- LEFT COLUMN: LIVE GPS RADAR MAP (~62% width) -->
      <div class="kfs-radar-card">
        <div class="kfs-card-head">
          <h2 class="kfs-card-head-title">LIVE GPS RADAR MAP</h2>
          <span class="kfs-status-pill"><?= (int)$settings['geofence_radius'] ?>m Radius Status</span>
        </div>

        <div class="kfs-map-container">
          <div id="radarMap"></div>

          <!-- Bottom floating legend pill -->
          <div class="kfs-map-legend-pill">
            <span class="dot"></span>
            <span>Live GPS Radar Map | <strong><?= (int)$settings['geofence_radius'] ?>m Geofence</strong></span>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: PENDING LOGIN REQUEST CARD (~38% width) -->
      <div class="kfs-request-card" id="employee_card_container">
        
        <?php if (empty($pendingRequests)): ?>
        <!-- EMPTY STATE (All Caught Up) -->
        <div style="text-align:center; padding: 40px 10px; margin:auto;">
          <div style="width:64px; height:64px; border-radius:50%; background:#E8F8F0; color:#10B981; margin:0 auto 16px auto; display:flex; align-items:center; justify-content:center; font-size:28px;">
            ✓
          </div>
          <h3 style="font-size:19px; font-weight:700; color:#1F1626; margin:0 0 6px 0;">No Pending Requests</h3>
          <p style="font-size:12px; color:#6B7280; line-height:1.5; max-width:280px; margin:0 auto 20px auto;">
            All staff logins have been verified. When an employee signs in, their GPS proximity and selfie proof will appear here.
          </p>
          <button type="button" onclick="refreshQueue()" style="padding:9px 18px; border-radius:10px; background:#F3EFEA; border:1px solid #E2D4C3; font-size:12px; font-weight:600; cursor:pointer;">
            Check for Requests
          </button>
        </div>

        <?php else:
          $activeReq = $pendingRequests[0];
          $empName = trim(($activeReq['firstname'] ?? '') . ' ' . ($activeReq['lastname'] ?? '')) ?: $activeReq['username'];
          $empInitials = strtoupper(substr($activeReq['firstname'] ?: $activeReq['username'], 0, 1) . substr($activeReq['lastname'] ?? '', 0, 1));
          if (!$empInitials) $empInitials = strtoupper(substr($activeReq['username'], 0, 2));

          $position = $activeReq['position'] ?: ucfirst($activeReq['role'] ?? 'Cashier');
          $branch = $activeReq['branch'] ?: $settings['store_name'];
          $empCode = $activeReq['employee_code'] ?: ('KM' . (1000 + (int)$activeReq['user_id']));
          $dept = $activeReq['department'] ?: 'Operations';

          list($deviceModel, $deviceOS) = parse_telemetry_breakdown($activeReq['device_info'], $activeReq['user_agent'] ?? '');

          $distMeters = $activeReq['distance_meters'] !== null ? (float)$activeReq['distance_meters'] : null;
          $isInsideGeofence = ($distMeters !== null && $distMeters <= (float)$settings['geofence_radius']);
          $proximityText = $distMeters !== null ? (round($distMeters) . 'm Distance') : 'GPS Signal Weak / Unavailable';

          $photoUrl = null;
          if (!empty($activeReq['selfie_photo']) && file_exists(__DIR__ . '/../' . ltrim($activeReq['selfie_photo'], '/'))) {
              $photoUrl = '../' . ltrim($activeReq['selfie_photo'], '/');
          } elseif (!empty($activeReq['avatar_path']) && file_exists(__DIR__ . '/../' . ltrim($activeReq['avatar_path'], '/'))) {
              $photoUrl = '../' . ltrim($activeReq['avatar_path'], '/');
          }
        ?>

        <!-- Top Content Section -->
        <div>
          <div class="kfs-card-head" style="margin-bottom:12px; padding-bottom:10px;">
            <h2 class="kfs-req-title">Pending Login Request</h2>
            <?php if (count($pendingRequests) > 1): ?>
            <div style="font-size:12px; color:#6B7280; display:flex; align-items:center; gap:6px;">
              <span id="queue_counter_label">1 of <?= count($pendingRequests) ?></span>
              <button type="button" onclick="navigateQueue(-1)" style="width:22px; height:22px; border-radius:6px; background:#F3EFEA; border:1px solid #E2D4C3; cursor:pointer;">&larr;</button>
              <button type="button" onclick="navigateQueue(1)" style="width:22px; height:22px; border-radius:6px; background:#F3EFEA; border:1px solid #E2D4C3; cursor:pointer;">&rarr;</button>
            </div>
            <?php endif; ?>
          </div>

          <!-- Multiple Queue Thumbnails if > 1 -->
          <?php if (count($pendingRequests) > 1): ?>
          <div style="display:flex; gap:8px; margin-bottom:14px; overflow-x:auto; padding-bottom:4px;">
            <?php foreach ($pendingRequests as $idx => $pr):
              $pInside = $pr['distance_meters'] !== null && (float)$pr['distance_meters'] <= (float)$settings['geofence_radius'];
            ?>
            <button type="button" onclick="selectQueueIndex(<?= $idx ?>)" id="queue_thumb_<?= $idx ?>"
                    style="padding:5px 12px; border-radius:10px; font-size:11.5px; cursor:pointer; display:flex; align-items:center; gap:6px; border:1px solid <?= $idx === 0 ? '#C97B3D' : '#E5E7EB' ?>; background:<?= $idx === 0 ? '#FFF6EB' : '#ffffff' ?>; color:<?= $idx === 0 ? '#C97B3D' : '#374151' ?>; font-weight:<?= $idx === 0 ? '700' : '500' ?>;">
              <span style="width:7px; height:7px; border-radius:50%; background:<?= $pInside ? '#10B981' : '#EF4444' ?>;"></span>
              <span><?= htmlspecialchars($pr['firstname'] ?: $pr['username']) ?></span>
            </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Employee Name & Position -->
          <div>
            <h3 id="emp_name_display" class="kfs-emp-name"><?= htmlspecialchars($empName) ?></h3>
            <p id="emp_role_branch" class="kfs-emp-subtitle"><?= htmlspecialchars($position) ?> &ndash; <?= htmlspecialchars($branch) ?></p>
          </div>

          <!-- Profile Photo & Key Info Side-by-Side -->
          <div class="kfs-profile-section">
            
            <!-- Selfie Photo / Avatar -->
            <div class="kfs-photo-wrap">
              <div class="kfs-photo-ring">
                <div class="kfs-photo-inner">
                  <?php if ($photoUrl): ?>
                  <img id="emp_photo_img" src="<?= htmlspecialchars($photoUrl) ?>" alt="Employee Photo" class="kfs-photo-img">
                  <div id="emp_avatar_fallback" class="kfs-photo-avatar" style="display:none;"><?= htmlspecialchars($empInitials) ?></div>
                  <?php else: ?>
                  <img id="emp_photo_img" src="" alt="Employee Photo" class="kfs-photo-img" style="display:none;">
                  <div id="emp_avatar_fallback" class="kfs-photo-avatar"><?= htmlspecialchars($empInitials) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <span class="kfs-photo-caption">Employee Selfie Proof</span>
            </div>

            <!-- Details List -->
            <div class="kfs-details-list">
              <div class="kfs-detail-row">
                <span class="kfs-detail-lbl">ID:</span>
                <span id="detail_emp_code" class="kfs-detail-val"><?= htmlspecialchars($empCode) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-lbl">Dept:</span>
                <span id="detail_dept" class="kfs-detail-val"><?= htmlspecialchars($dept) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-lbl">Device:</span>
                <span id="detail_device" class="kfs-detail-val"><?= htmlspecialchars($deviceModel) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-lbl">OS:</span>
                <span id="detail_os" class="kfs-detail-val"><?= htmlspecialchars($deviceOS) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-lbl">IP:</span>
                <span id="detail_ip" class="kfs-detail-val mono"><?= htmlspecialchars($activeReq['ip_address']) ?></span>
              </div>
              <div class="kfs-detail-row" style="margin-top:2px;">
                <span class="kfs-detail-lbl">Proximity:</span>
                <span id="detail_proximity" class="kfs-detail-val" style="color:#1F1626;"><?= htmlspecialchars($proximityText) ?></span>
              </div>
            </div>

          </div>

          <!-- Geofence Status Pill -->
          <div>
            <?php if ($isInsideGeofence): ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill inside">
              INSIDE STORE GEOFENCE
            </div>
            <?php elseif ($distMeters !== null): ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill outside">
              OUTSIDE STORE GEOFENCE (<?= round($distMeters / 1000, 2) ?> km)
            </div>
            <?php else: ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill unknown">
              GPS SIGNAL UNAVAILABLE (IP ONLY)
            </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- Prominent Action Buttons -->
        <div class="kfs-actions-wrap">
          <div class="kfs-trust-toggle">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
              <input type="checkbox" id="trust_device_checkbox" style="width:14px; height:14px; accent-color:#16A34A; cursor:pointer;">
              <span>Trust this device for 7 days (Bypass daily check)</span>
            </label>
            <span style="font-size:10px; color:#9CA3AF;">ID #<?= $activeReq['id'] ?></span>
          </div>

          <!-- Big Vibrant Green Button -->
          <button type="button" id="btn_approve_main" onclick="handleMainApprove()" class="kfs-btn-approve">
            APPROVE LOGIN
          </button>

          <!-- Big Vibrant Red Button -->
          <button type="button" id="btn_reject_main" onclick="handleMainReject()" class="kfs-btn-reject">
            REJECT
          </button>
        </div>

        <?php endif; ?>
      </div>

    </div>

    <!-- Bottom Section: Tabs for Audit History, Geofence Settings & Trusted Devices -->
    <div class="kfs-bottom-card">
      <div class="kfs-tab-bar">
        <div class="kfs-tab-pills">
          <button type="button" onclick="switchSubTab('history')" id="subtab_btn_history" class="kfs-tab-pill active">
            📋 Audit History (<?= count($historyRecords) ?>)
          </button>
          <button type="button" onclick="switchSubTab('settings')" id="subtab_btn_settings" class="kfs-tab-pill">
            ⚙️ Geofence & Store Pin Settings
          </button>
          <button type="button" onclick="switchSubTab('trusted')" id="subtab_btn_trusted" class="kfs-tab-pill">
            💻 Trusted Devices (<?= $totalTrusted ?>)
          </button>
        </div>

        <span style="font-size:12px; color:#78716C;">
          Store Pin: <strong style="color:#1F1626; font-family:monospace;"><?= number_format($settings['store_lat'], 5) ?>, <?= number_format($settings['store_lon'], 5) ?></strong>
        </span>
      </div>

      <!-- SUBTAB 1: AUDIT HISTORY -->
      <div id="subtab_content_history">
        <div style="overflow-x:auto;">
          <table class="kfs-table">
            <thead>
              <tr>
                <th>Staff Member</th>
                <th>Status</th>
                <th>Location & Proximity</th>
                <th>Device / OS / IP</th>
                <th>Requested At</th>
                <th>HR Reviewer</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($historyRecords)): ?>
              <tr>
                <td colspan="6" style="text-align:center; padding:32px; color:#9CA3AF;">No authorization events logged yet.</td>
              </tr>
              <?php else: foreach ($historyRecords as $h):
                $hName = trim(($h['firstname'] ?? '') . ' ' . ($h['lastname'] ?? '')) ?: $h['username'];
                $st = $h['status'];
              ?>
              <tr>
                <td>
                  <strong style="color:#1F1626;"><?= htmlspecialchars($hName) ?></strong>
                  <div style="font-size:10.5px; color:#78716C;">@<?= htmlspecialchars($h['username']) ?> &bull; <?= htmlspecialchars($h['role']) ?></div>
                </td>
                <td>
                  <span class="badge-app <?= $st ?>"><?= htmlspecialchars($st) ?></span>
                </td>
                <td>
                  <div style="font-weight:500; color:#1F1626;"><?= htmlspecialchars($h['location_name'] ?: 'No GPS') ?></div>
                  <?php if ($h['distance_meters'] !== null): ?>
                  <div style="font-size:10.5px; color:#78716C;">Distance: <?= round((float)$h['distance_meters']) ?>m</div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= htmlspecialchars($h['device_info'] ?: 'Browser') ?></div>
                  <div style="font-family:monospace; font-size:10.5px; color:#78716C;"><?= htmlspecialchars($h['ip_address']) ?></div>
                </td>
                <td style="color:#4B5563; white-space:nowrap;">
                  <?= date('M d, Y h:i A', strtotime($h['created_at'])) ?>
                </td>
                <td>
                  <?php if ($st === 'approved'): ?>
                  <span style="color:#059669; font-weight:600;">Approved by <?= htmlspecialchars($h['approved_by_name'] ?: 'HR') ?></span>
                  <?php elseif ($st === 'rejected'): ?>
                  <span style="color:#DC2626; font-weight:500;" title="<?= htmlspecialchars($h['rejection_reason'] ?? '') ?>">
                    <?= htmlspecialchars($h['rejection_reason'] ?: 'Denied') ?>
                  </span>
                  <?php else: ?>
                  <span style="color:#9CA3AF;">&mdash;</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- SUBTAB 2: GEOFENCE SETTINGS -->
      <div id="subtab_content_settings" style="display:none;">
        <form method="POST" action="login_approvals.php" style="max-width:760px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_settings">

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px;">
            <div style="background:#FAF7F2; border:1px solid #EFE6DC; border-radius:14px; padding:16px;">
              <label for="require_approval_enabled" style="font-size:12.5px; font-weight:700; color:#1F1626; display:block; cursor:pointer;">
                Require HR Login Authorization
              </label>
              <p style="font-size:11.5px; color:#6B7280; margin:4px 0 10px 0;">Staff logins are paused until reviewed by HR.</p>
              <input type="checkbox" id="require_approval_enabled" name="require_approval_enabled" value="1"
                     <?= $settings['enabled'] ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:#C97B3D; cursor:pointer;">
            </div>

            <div style="background:#FAF7F2; border:1px solid #EFE6DC; border-radius:14px; padding:16px;">
              <label for="auto_approve_within_geofence" style="font-size:12.5px; font-weight:700; color:#1F1626; display:block; cursor:pointer;">
                Auto-Approve Inside Store Geofence
              </label>
              <p style="font-size:11.5px; color:#6B7280; margin:4px 0 10px 0;">Auto-approves staff if within store radius.</p>
              <input type="checkbox" id="auto_approve_within_geofence" name="auto_approve_within_geofence" value="1"
                     <?= $settings['auto_approve_in_zone'] ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:#C97B3D; cursor:pointer;">
            </div>
          </div>

          <div style="margin-bottom:14px;">
            <label style="display:block; font-size:11.5px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">Store / Branch Name</label>
            <input type="text" name="store_name" value="<?= htmlspecialchars($settings['store_name']) ?>" required
                   style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #D1D5DB; font-size:12.5px; box-sizing:border-box;">
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
            <div>
              <label style="display:block; font-size:11.5px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">Store Latitude</label>
              <input type="text" id="setting_lat" name="store_latitude" value="<?= htmlspecialchars((string)$settings['store_lat']) ?>" required
                     style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #D1D5DB; font-size:12.5px; font-family:monospace; box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block; font-size:11.5px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">Store Longitude</label>
              <input type="text" id="setting_lon" name="store_longitude" value="<?= htmlspecialchars((string)$settings['store_lon']) ?>" required
                     style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #D1D5DB; font-size:12.5px; font-family:monospace; box-sizing:border-box;">
            </div>
          </div>

          <div style="margin-bottom:14px;">
            <button type="button" onclick="detectStorePin()" style="padding:8px 14px; border-radius:8px; background:#F3EFEA; border:1px solid #E2D4C3; font-size:11.5px; font-weight:600; cursor:pointer;">
              📍 Use My Current Location as Store Pin
            </button>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
            <div>
              <label style="display:block; font-size:11.5px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">Geofence Radius (Meters)</label>
              <input type="number" name="store_geofence_radius_meters" value="<?= htmlspecialchars((string)$settings['geofence_radius']) ?>" min="20" max="10000" required
                     style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #D1D5DB; font-size:12.5px; box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block; font-size:11.5px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">Exempt Roles (Bypass)</label>
              <input type="text" name="exempt_roles" value="<?= htmlspecialchars(implode(', ', $settings['exempt_roles'])) ?>"
                     style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid #D1D5DB; font-size:12.5px; box-sizing:border-box;">
            </div>
          </div>

          <button type="submit" style="padding:11px 22px; border-radius:10px; background:#1F1626; color:#ffffff; font-size:12.5px; font-weight:700; border:none; cursor:pointer;">
            Save Geofence Settings
          </button>
        </form>
      </div>

      <!-- SUBTAB 3: TRUSTED DEVICES -->
      <div id="subtab_content_trusted" style="display:none;">
        <div style="max-width:640px; display:flex; flex-direction:column; gap:10px;">
          <?php if (empty($trustedDevices)): ?>
          <p style="font-size:12.5px; color:#9CA3AF; padding:16px 0;">No active trusted devices recorded.</p>
          <?php else: foreach ($trustedDevices as $td): ?>
          <div style="background:#FAF7F2; border:1px solid #EFE6DC; border-radius:12px; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; font-size:12px;">
            <div>
              <strong style="color:#1F1626;"><?= htmlspecialchars($td['firstname'] . ' ' . $td['lastname']) ?></strong>
              <div style="color:#6B7280; font-size:11px;"><?= htmlspecialchars($td['device_name']) ?> &bull; IP: <?= htmlspecialchars($td['ip_address']) ?></div>
              <div style="color:#059669; font-size:10px; margin-top:2px;">Expires: <?= date('M d, Y', strtotime($td['expires_at'])) ?></div>
            </div>
            <form method="POST" action="login_approvals.php" onsubmit="return confirm('Revoke trust for this device?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke_device">
              <input type="hidden" name="device_id" value="<?= $td['id'] ?>">
              <button type="submit" style="color:#DC2626; background:none; border:none; font-weight:600; cursor:pointer; font-size:11.5px;">Revoke</button>
            </form>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>

  </div>
</div>

<!-- ============================================================================== -->
<!-- REJECT MODAL WITH PRESETS                                                      -->
<!-- ============================================================================== -->
<div id="reject_modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:16px;">
  <div style="background:#ffffff; border-radius:22px; width:100%; max-width:440px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.25);">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #F3EDE6; padding-bottom:12px; margin-bottom:16px;">
      <h3 style="font-size:17px; font-weight:700; color:#DC2626; margin:0;">Reject Login Attempt</h3>
      <button onclick="closeRejectModal()" style="width:28px; height:28px; border-radius:50%; background:#F3EFEA; border:none; font-weight:bold; cursor:pointer;">&times;</button>
    </div>

    <form method="POST" action="login_approvals.php" style="display:flex; flex-direction:column; gap:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" id="reject_auth_id" name="auth_id" value="">

      <p style="font-size:12px; color:#4B5563; margin:0;">
        Rejecting login for <strong id="reject_emp_name_modal" style="color:#1F1626;">Staff</strong>. Select or write reason:
      </p>

      <div style="display:flex; flex-direction:column; gap:8px; font-size:12px;">
        <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; border:1px solid #E5E7EB; cursor:pointer;">
          <input type="radio" name="reason_preset" value="Outside workplace branch geofence radius." checked onchange="document.getElementById('custom_reason').value = this.value">
          <span>Outside workplace branch geofence radius</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; border:1px solid #E5E7EB; cursor:pointer;">
          <input type="radio" name="reason_preset" value="Staff is not scheduled for a shift today." onchange="document.getElementById('custom_reason').value = this.value">
          <span>Not scheduled for work shift today</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; border:1px solid #E5E7EB; cursor:pointer;">
          <input type="radio" name="reason_preset" value="Unrecognized personal device or suspicious IP." onchange="document.getElementById('custom_reason').value = this.value">
          <span>Unrecognized personal device or suspicious IP</span>
        </label>
      </div>

      <div>
        <label style="display:block; font-size:11px; font-weight:600; text-transform:uppercase; color:#6B7280; margin-bottom:4px;">
          Message to Employee:
        </label>
        <textarea id="custom_reason" name="reason" rows="2" required
                  style="width:100%; padding:8px 12px; border-radius:10px; border:1px solid #D1D5DB; font-size:12px; box-sizing:border-box;">Outside workplace branch geofence radius.</textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
        <button type="button" onclick="closeRejectModal()" style="padding:9px 16px; border-radius:10px; background:#F3EFEA; border:none; font-size:12px; font-weight:600; cursor:pointer;">Cancel</button>
        <button type="submit" style="padding:9px 18px; border-radius:10px; background:#DC2626; color:#ffffff; font-size:12px; font-weight:700; border:none; cursor:pointer;">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<script>
const STORE_LAT = <?= json_encode((float)$settings['store_lat']) ?>;
const STORE_LON = <?= json_encode((float)$settings['store_lon']) ?>;
const STORE_NAME = <?= json_encode($settings['store_name']) ?>;
const GEOFENCE_RADIUS = <?= json_encode((float)$settings['geofence_radius']) ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

let pendingList = <?= json_encode($pendingRequests) ?>;
let activeIndex = 0;
let radarMapInstance = null;
let employeeMarkers = [];
let connectionLine = null;
let geofenceCircle = null;

let lastSeenPendingId = <?= (int)$initialMaxPendingId ?>;
let lastSeenCount = <?= (int)$totalPending ?>;

// Silent background detector: auto-reloads ONLY when a NEW login attempt arrives
async function checkNewLoginAttempts() {
  try {
    const res = await fetch(`login_approvals.php?action=check_new&last_id=${lastSeenPendingId}&count=${lastSeenCount}`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (data.ok && data.has_new) {
      console.log('New staff login attempt detected! Auto-reloading page...');
      window.location.reload();
    }
  } catch (err) {
    // Silent fail without interrupting user
  }
}

// Check every 3.5 seconds completely silently in background
setInterval(checkNewLoginAttempts, 3500);

// Clock Updater
function updateClock() {
  const now = new Date();
  const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
  const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
  const dateEl = document.getElementById('current_date_display');
  const timeEl = document.getElementById('current_time_display');
  if (dateEl) dateEl.textContent = dateStr;
  if (timeEl) timeEl.textContent = timeStr;
}
setInterval(updateClock, 1000);

// Initialize Radar Map with Leaflet
function initRadarMap() {
  const mapEl = document.getElementById('radarMap');
  if (!mapEl) return;

  if (!radarMapInstance) {
    radarMapInstance = L.map('radarMap', { zoomControl: true }).setView([STORE_LAT, STORE_LON], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(radarMapInstance);
  }

  // Clear previous layers
  if (geofenceCircle) radarMapInstance.removeLayer(geofenceCircle);
  if (connectionLine) radarMapInstance.removeLayer(connectionLine);
  employeeMarkers.forEach(m => radarMapInstance.removeLayer(m));
  employeeMarkers = [];

  // Add Store Pin (Orange circular coffee bean pin matching mockup)
  const storeIcon = L.divIcon({
    className: 'custom-store-pin',
    html: `
      <div style="position:relative; width:44px; height:44px; display:flex; align-items:center; justify-content:center;">
        <div style="position:absolute; inset:0; background:radial-gradient(circle, rgba(230,138,54,0.4) 0%, transparent 70%); border-radius:50%;"></div>
        <div style="width:36px; height:36px; border-radius:50%; background:#E68A36; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:18px; border:2.5px solid #ffffff; box-shadow:0 6px 14px rgba(0,0,0,0.28);">
          ☕
        </div>
      </div>
    `,
    iconSize: [44, 44],
    iconAnchor: [22, 22]
  });

  const storeMarker = L.marker([STORE_LAT, STORE_LON], { icon: storeIcon }).addTo(radarMapInstance)
    .bindPopup(`<b style="font-size:13px; color:#241A2E;">${STORE_NAME}</b><br><span style="font-size:11px; color:#6B7280;">Workplace Store Center</span>`);

  // Add Geofence Green Circle (matching mockup's clean perimeter ring)
  geofenceCircle = L.circle([STORE_LAT, STORE_LON], {
    color: '#10B981',
    weight: 2.5,
    fillColor: '#10B981',
    fillOpacity: 0.16,
    radius: GEOFENCE_RADIUS
  }).addTo(radarMapInstance);

  // If there's an active selected employee with valid coordinates
  if (pendingList && pendingList.length > 0) {
    const cur = pendingList[activeIndex];
    if (cur && cur.latitude !== null && cur.longitude !== null) {
      const lat = parseFloat(cur.latitude);
      const lon = parseFloat(cur.longitude);
      const dist = cur.distance_meters !== null ? parseFloat(cur.distance_meters) : 0;
      const isInside = dist <= GEOFENCE_RADIUS;

      const initials = (cur.firstname ? cur.firstname.charAt(0) : (cur.username ? cur.username.charAt(0) : 'E')).toUpperCase() +
                       (cur.lastname ? cur.lastname.charAt(0) : '').toUpperCase();

      // Employee Marker (Green round badge with initials "JM" as in mockup)
      const empIcon = L.divIcon({
        className: 'custom-emp-pin',
        html: `
          <div style="width:34px; height:34px; border-radius:50%; background:${isInside ? '#10B981' : '#EF4444'}; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; border:2.5px solid #ffffff; box-shadow:0 6px 14px rgba(0,0,0,0.3);">
            ${initials}
          </div>
        `,
        iconSize: [34, 34],
        iconAnchor: [17, 17]
      });

      const empMarker = L.marker([lat, lon], { icon: empIcon }).addTo(radarMapInstance)
        .bindPopup(`<b>${cur.firstname || cur.username}</b><br>${isInside ? '🟢 Inside Geofence (' + Math.round(dist) + 'm)' : '🔴 Outside Store (' + (dist/1000).toFixed(2) + ' km)'}`)
        .openPopup();

      employeeMarkers.push(empMarker);

      // Connecting dotted line from store to employee
      connectionLine = L.polyline([[STORE_LAT, STORE_LON], [lat, lon]], {
        color: isInside ? '#10B981' : '#EF4444',
        weight: 2.5,
        dashArray: '5, 8'
      }).addTo(radarMapInstance);

      // Fit bounds nicely so both pins are visible
      const bounds = L.latLngBounds([[STORE_LAT, STORE_LON], [lat, lon]]);
      radarMapInstance.fitBounds(bounds.pad(0.35));
      return;
    }
  }

  // Default view centered on Store
  radarMapInstance.setView([STORE_LAT, STORE_LON], 16);
  radarMapInstance.invalidateSize();
}

// Select active employee in the queue
function selectQueueIndex(idx) {
  if (idx < 0 || idx >= pendingList.length) return;
  activeIndex = idx;
  updateEmployeeCardUI();
  initRadarMap();
}

function navigateQueue(step) {
  let next = activeIndex + step;
  if (next < 0) next = pendingList.length - 1;
  if (next >= pendingList.length) next = 0;
  selectQueueIndex(next);
}

// Update the Employee Details Panel UI to reflect the selected request
function updateEmployeeCardUI() {
  const cardContainer = document.getElementById('employee_card_container');
  if (!pendingList || pendingList.length === 0) {
    if (cardContainer) {
      cardContainer.innerHTML = `
        <div style="text-align:center; padding: 40px 10px; margin:auto;">
          <div style="width:64px; height:64px; border-radius:50%; background:#E8F8F0; color:#10B981; margin:0 auto 16px auto; display:flex; align-items:center; justify-content:center; font-size:28px;">
            ✓
          </div>
          <h3 style="font-size:19px; font-weight:700; color:#1F1626; margin:0 0 6px 0;">No Pending Requests</h3>
          <p style="font-size:12px; color:#6B7280; line-height:1.5; max-width:280px; margin:0 auto 20px auto;">
            All staff logins have been verified. When an employee signs in, their GPS proximity and selfie proof will appear here.
          </p>
          <button type="button" onclick="refreshQueue()" style="padding:9px 18px; border-radius:10px; background:#F3EFEA; border:1px solid #E2D4C3; font-size:12px; font-weight:600; cursor:pointer;">
            Check for Requests
          </button>
        </div>
      `;
    }
    return;
  }

  const cur = pendingList[activeIndex];
  const empName = `${cur.firstname || ''} ${cur.lastname || ''}`.trim() || cur.username;
  const position = cur.position || (cur.role ? cur.role.charAt(0).toUpperCase() + cur.role.slice(1) : 'Staff');
  const branch = cur.branch || STORE_NAME;
  const empCode = cur.employee_code || ('KM' + (1000 + parseInt(cur.user_id)));
  const dept = cur.department || 'Operations';
  const dist = cur.distance_meters !== null ? parseFloat(cur.distance_meters) : null;
  const isInside = dist !== null && dist <= GEOFENCE_RADIUS;

  // Header & Counter
  const countLabel = document.getElementById('queue_counter_label');
  if (countLabel) countLabel.textContent = `${activeIndex + 1} of ${pendingList.length}`;

  // Name & Role
  document.getElementById('emp_name_display').textContent = empName;
  document.getElementById('emp_role_branch').textContent = `${position} – ${branch}`;

  // Data fields
  document.getElementById('detail_emp_code').textContent = empCode;
  document.getElementById('detail_dept').textContent = dept;
  document.getElementById('detail_ip').textContent = cur.ip_address;
  document.getElementById('detail_proximity').textContent = dist !== null ? `${Math.round(dist)}m Distance` : 'GPS Signal Weak / Unavailable';

  // Device & OS breakdown
  let devModel = 'Desktop Workstation';
  let devOS = 'Windows 11';
  if (cur.device_info) {
    if (cur.device_info.includes('iPhone')) { devModel = 'iPhone 13 Pro'; devOS = 'iOS 16.5'; }
    else if (cur.device_info.includes('Android')) { devModel = 'Android Device'; devOS = 'Android 14'; }
    else if (cur.device_info.includes('Windows')) { devModel = 'Windows PC'; devOS = 'Windows 11'; }
    else if (cur.device_info.includes('Mac')) { devModel = 'MacBook Pro'; devOS = 'macOS Ventura'; }
  }
  document.getElementById('detail_device').textContent = devModel;
  document.getElementById('detail_os').textContent = devOS;

  // Status Pill
  const pill = document.getElementById('geofence_status_pill');
  if (pill) {
    if (isInside) {
      pill.className = 'kfs-geofence-pill inside';
      pill.textContent = 'INSIDE STORE GEOFENCE';
    } else if (dist !== null) {
      pill.className = 'kfs-geofence-pill outside';
      pill.textContent = `OUTSIDE STORE GEOFENCE (${(dist / 1000).toFixed(2)} km)`;
    } else {
      pill.className = 'kfs-geofence-pill unknown';
      pill.textContent = 'GPS SIGNAL UNAVAILABLE (IP ONLY)';
    }
  }

  // Photo
  const imgEl = document.getElementById('emp_photo_img');
  const fallbackEl = document.getElementById('emp_avatar_fallback');
  const photoUrl = cur.selfie_photo ? ('../' + cur.selfie_photo.replace(/^\//,'')) :
                   (cur.avatar_path ? ('../' + cur.avatar_path.replace(/^\//,'')) : null);

  if (photoUrl && imgEl) {
    imgEl.src = photoUrl;
    imgEl.style.display = 'block';
    if (fallbackEl) fallbackEl.style.display = 'none';
  } else if (fallbackEl) {
    const initials = (cur.firstname ? cur.firstname.charAt(0) : (cur.username ? cur.username.charAt(0) : 'E')).toUpperCase() +
                     (cur.lastname ? cur.lastname.charAt(0) : '').toUpperCase();
    fallbackEl.textContent = initials;
    fallbackEl.style.display = 'flex';
    if (imgEl) imgEl.style.display = 'none';
  }
}

// Approve Action Handler
async function handleMainApprove() {
  if (!pendingList || pendingList.length === 0) return;
  const cur = pendingList[activeIndex];
  const trustChecked = document.getElementById('trust_device_checkbox')?.checked ? 7 : 0;

  const btn = document.getElementById('btn_approve_main');
  btn.disabled = true;
  btn.style.opacity = '0.7';
  btn.textContent = 'Approving…';

  const formData = new FormData();
  formData.append('action', 'approve');
  formData.append('auth_id', cur.id);
  formData.append('trust_days', trustChecked);
  formData.append('is_ajax', '1');
  formData.append('csrf_token', CSRF_TOKEN);

  try {
    const res = await fetch('login_approvals.php', {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (data.ok) {
      pendingList.splice(activeIndex, 1);
      if (activeIndex >= pendingList.length) activeIndex = Math.max(0, pendingList.length - 1);
      
      document.getElementById('stat_pending_val').textContent = pendingList.length;
      const pill = document.getElementById('stat_badge_pill');
      if (pill) pill.textContent = pendingList.length;

      updateEmployeeCardUI();
      initRadarMap();
    } else {
      alert(data.error || 'Approval failed.');
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.textContent = 'APPROVE LOGIN';
    }
  } catch (err) {
    console.error(err);
    alert('Server error during approval.');
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.textContent = 'APPROVE LOGIN';
  }
}

// Reject Action Handler
function handleMainReject() {
  if (!pendingList || pendingList.length === 0) return;
  const cur = pendingList[activeIndex];
  const empName = `${cur.firstname || ''} ${cur.lastname || ''}`.trim() || cur.username;
  document.getElementById('reject_auth_id').value = cur.id;
  document.getElementById('reject_emp_name_modal').textContent = empName;
  document.getElementById('reject_modal').style.display = 'flex';
}

function closeRejectModal() {
  document.getElementById('reject_modal').style.display = 'none';
}

// Live Queue Refresh
async function refreshQueue() {
  const icon = document.getElementById('refresh_icon');
  if (icon) icon.style.transform = 'rotate(180deg)';

  try {
    const res = await fetch('login_approvals.php?action=get_pending_json', { cache: 'no-store' });
    const data = await res.json();
    if (data.ok) {
      pendingList = data.records;
      document.getElementById('stat_pending_val').textContent = data.count;
      const pill = document.getElementById('stat_badge_pill');
      if (pill) pill.textContent = data.count;

      if (activeIndex >= pendingList.length) activeIndex = Math.max(0, pendingList.length - 1);
      updateEmployeeCardUI();
      initRadarMap();
    }
  } catch (err) {
    console.warn('Queue refresh failed:', err);
  } finally {
    if (icon) icon.style.transform = 'rotate(0deg)';
  }
}

// Sub-Tab Switcher
function switchSubTab(tab) {
  document.getElementById('subtab_content_history').style.display = 'none';
  document.getElementById('subtab_content_settings').style.display = 'none';
  document.getElementById('subtab_content_trusted').style.display = 'none';

  document.getElementById('subtab_btn_history').classList.remove('active');
  document.getElementById('subtab_btn_settings').classList.remove('active');
  document.getElementById('subtab_btn_trusted').classList.remove('active');

  document.getElementById(`subtab_content_${tab}`).style.display = 'block';
  document.getElementById(`subtab_btn_${tab}`).classList.add('active');
}

// Geolocation Setting Helper: Detect Pin
function detectStorePin() {
  if (!('geolocation' in navigator)) {
    alert('Browser does not support geolocation.');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    pos => {
      document.getElementById('setting_lat').value = pos.coords.latitude.toFixed(7);
      document.getElementById('setting_lon').value = pos.coords.longitude.toFixed(7);
      alert('Store GPS Pin updated with your current location!');
    },
    err => {
      alert('Unable to detect location: ' + err.message);
    },
    { enableHighAccuracy: true, timeout: 8000 }
  );
}

// Auto-run on load (NO automatic page refresh)
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => {
    initRadarMap();
    if (radarMapInstance) radarMapInstance.invalidateSize();
  }, 250);
});
</script>
</body>
</html>
