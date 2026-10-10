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

$pdo = get_db();
ensure_login_approval_tables($pdo);

$user = current_user();
$roles = $user['roles'] ?? [$user['role'] ?? 'crew'];
$canManage = has_permission('login_approval.manage');

if (!$canManage) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || !empty($_POST['is_ajax'])
           || (isset($_GET['action']) && in_array($_GET['action'], ['get_pending_json', 'check_new'], true));
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to manage login approvals.']);
        exit;
    }
    header('Location: no_access.php?perm=login_approval.manage');
    exit;
}

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
$availableRoles = get_all_roles();
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
        try {
            save_login_approval_settings($pdo, validate_login_approval_settings($_POST, $availableRoles));
            $toast = 'Geofence & security settings saved successfully!';
        } catch (InvalidArgumentException $error) {
            $toast = $error->getMessage();
            $toast_type = 'error';
        }
    }

    if ($action === 'revoke_device') {
        $devId = (int)($_POST['device_id'] ?? 0);
        if ($devId > 0) {
            $pdo->prepare("DELETE FROM auth_devices WHERE id = :id")->execute([':id' => $devId]);
            $toast = 'Trusted device revoked.';
            $toast_type = 'success';
        }
    }

    if (!$isAjax) {
        $tab = $action === 'save_settings' ? 'settings' : htmlspecialchars($_POST['current_tab'] ?? 'dashboard');
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
    FROM auth_devices td
    JOIN users u ON u.id = td.user_id
    LEFT JOIN users hr ON hr.id = td.approved_by
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
    <div id="kfs_toast_alert" class="kfs-toast-alert <?= $toast_type === 'success' ? 'toast-success' : 'toast-error' ?>" role="status" aria-live="polite">
      <div class="kfs-toast-left">
        <span class="kfs-toast-icon">
          <?= $toast_type === 'success' ? icon('check-circle', 18) : icon('alert-triangle', 18) ?>
        </span>
        <span><?= htmlspecialchars($toast) ?></span>
      </div>
      <button type="button" onclick="dismissToast(this)" class="kfs-toast-close" aria-label="Dismiss message">
        <?= icon('x', 14) ?>
      </button>
    </div>
    <?php endif; ?>

    <!-- Top Header: Title & Real-Time Clock Bar -->
    <div class="kfs-header-row">
      <div>
        <h1 class="kfs-page-title">Staff Login Approvals - Current Status</h1>
        <p class="kfs-page-subtitle">Review real-time employee sign-in attempts, verify store geofence proximity, and manage trusted devices.</p>
      </div>
      <div class="kfs-time-badge">
        <span id="current_date_display"><?= date('M d, Y') ?></span>
        <span style="color:#D1D5DB;">|</span>
        <span id="current_time_display" style="font-family:monospace; font-size:13.5px; color:#1F1626; font-weight:600;"><?= date('h:i A') ?></span>
        <button type="button" onclick="refreshQueue()" class="kfs-refresh-btn" title="Refresh Live Queue" aria-label="Refresh Queue">
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
          <div class="kfs-metric-label">Store Geofence (<?= htmlspecialchars($settings['store_name']) ?>)</div>
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

          <!-- Top right recenter button -->
          <button type="button" class="kfs-map-recenter-btn" onclick="resetRadarView()" title="Center camera on store & employee">
            <?= icon('crosshair', 14) ?>
            <span>Center View</span>
          </button>

          <!-- Bottom floating legend pill -->
          <div class="kfs-map-legend-pill">
            <span class="dot"></span>
            <span>Live Radar | <strong><?= (int)$settings['geofence_radius'] ?>m Store Geofence</strong></span>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: PENDING LOGIN REQUEST CARD (~38% width) -->
      <div class="kfs-request-card" id="employee_card_container">
        
        <?php if (empty($pendingRequests)): ?>
        <!-- EMPTY STATE (All Caught Up) -->
        <div class="kfs-empty-state">
          <div class="kfs-empty-icon-wrap">
            <?= icon('shield-check', 32) ?>
          </div>
          <h3 class="kfs-empty-title">No Pending Requests</h3>
          <p class="kfs-empty-desc">
            All staff logins have been verified. When an employee signs in, their GPS proximity and selfie proof will appear here.
          </p>
          <button type="button" onclick="refreshQueue()" class="kfs-empty-btn">
            <?= icon('refresh', 14) ?>
            <span>Check for Requests</span>
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
            <div class="kfs-queue-controls">
              <span id="queue_counter_label" class="kfs-queue-label">1 of <?= count($pendingRequests) ?></span>
              <div class="kfs-queue-nav-btns">
                <button type="button" onclick="navigateQueue(-1)" class="kfs-queue-btn" aria-label="Previous request" title="Previous request">
                  <?= icon('chevron-left', 14) ?>
                </button>
                <button type="button" onclick="navigateQueue(1)" class="kfs-queue-btn" aria-label="Next request" title="Next request">
                  <?= icon('chevron-right', 14) ?>
                </button>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Multiple Queue Thumbnails if > 1 -->
          <?php if (count($pendingRequests) > 1): ?>
          <div class="kfs-queue-thumbs">
            <?php foreach ($pendingRequests as $idx => $pr):
              $pInside = $pr['distance_meters'] !== null && (float)$pr['distance_meters'] <= (float)$settings['geofence_radius'];
              $prThumb = null;
              if (!empty($pr['avatar_path']) && file_exists(__DIR__ . '/../' . ltrim($pr['avatar_path'], '/'))) {
                  $prThumb = '../' . ltrim($pr['avatar_path'], '/');
              } elseif (!empty($pr['selfie_photo']) && file_exists(__DIR__ . '/../' . ltrim($pr['selfie_photo'], '/'))) {
                  $prThumb = '../' . ltrim($pr['selfie_photo'], '/');
              }
              $prInitials = strtoupper(substr($pr['firstname'] ?: $pr['username'], 0, 1) . substr($pr['lastname'] ?? '', 0, 1));
            ?>
            <button type="button" onclick="selectQueueIndex(<?= $idx ?>)" id="queue_thumb_<?= $idx ?>"
                    class="kfs-queue-thumb <?= $idx === 0 ? 'active' : '' ?>">
              <span class="kfs-thumb-indicator <?= $pInside ? 'inside' : 'outside' ?>"></span>
              <?php if ($prThumb): ?>
                <img src="<?= htmlspecialchars($prThumb) ?>" alt="avatar" class="kfs-thumb-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                <span class="kfs-thumb-initials" style="display:none;"><?= htmlspecialchars($prInitials) ?></span>
              <?php else: ?>
                <span class="kfs-thumb-initials"><?= htmlspecialchars($prInitials) ?></span>
              <?php endif; ?>
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
                  <img id="emp_photo_img" src="<?= htmlspecialchars($photoUrl) ?>" alt="Employee Photo" class="kfs-photo-img" onerror="this.style.display='none'; document.getElementById('emp_avatar_fallback').style.display='flex';">
                  <div id="emp_avatar_fallback" class="kfs-photo-avatar" style="display:none;"><?= htmlspecialchars($empInitials) ?></div>
                  <?php else: ?>
                  <img id="emp_photo_img" src="" alt="Employee Photo" class="kfs-photo-img" style="display:none;" onerror="this.style.display='none'; document.getElementById('emp_avatar_fallback').style.display='flex';">
                  <div id="emp_avatar_fallback" class="kfs-photo-avatar"><?= htmlspecialchars($empInitials) ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <span class="kfs-photo-caption" id="kfs_photo_label"><?= !empty($activeReq['avatar_path']) ? 'Profile Picture' : (!empty($activeReq['selfie_photo']) ? 'Selfie Proof' : 'Staff Proof') ?></span>
            </div>

            <!-- Details List -->
            <div class="kfs-details-list">
              <div class="kfs-detail-row">
                <span class="kfs-detail-icon-wrap"><?= icon('tag', 13) ?></span>
                <span class="kfs-detail-lbl">ID:</span>
                <span id="detail_emp_code" class="kfs-detail-val"><?= htmlspecialchars($empCode) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-icon-wrap"><?= icon('building', 13) ?></span>
                <span class="kfs-detail-lbl">Dept:</span>
                <span id="detail_dept" class="kfs-detail-val"><?= htmlspecialchars($dept) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-icon-wrap"><?= icon('laptop', 13) ?></span>
                <span class="kfs-detail-lbl">Device:</span>
                <span id="detail_device" class="kfs-detail-val"><?= htmlspecialchars($deviceModel) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-icon-wrap"><?= icon('dashboard', 13) ?></span>
                <span class="kfs-detail-lbl">OS:</span>
                <span id="detail_os" class="kfs-detail-val"><?= htmlspecialchars($deviceOS) ?></span>
              </div>
              <div class="kfs-detail-row">
                <span class="kfs-detail-icon-wrap"><?= icon('globe', 13) ?></span>
                <span class="kfs-detail-lbl">IP:</span>
                <span id="detail_ip" class="kfs-detail-val mono"><?= htmlspecialchars($activeReq['ip_address']) ?></span>
              </div>
              <div class="kfs-detail-row" style="margin-top:2px;">
                <span class="kfs-detail-icon-wrap"><?= icon('pin', 13) ?></span>
                <span class="kfs-detail-lbl">Proximity:</span>
                <span id="detail_proximity" class="kfs-detail-val" style="color:#1F1626;"><?= htmlspecialchars($proximityText) ?></span>
              </div>
            </div>

          </div>

          <!-- Geofence Status Pill -->
          <div>
            <?php if ($isInsideGeofence): ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill inside">
              <span class="kfs-pill-icon"><?= icon('check-circle', 14) ?></span>
              <span class="kfs-pill-text">INSIDE STORE GEOFENCE</span>
            </div>
            <?php elseif ($distMeters !== null): ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill outside">
              <span class="kfs-pill-icon"><?= icon('alert-triangle', 14) ?></span>
              <span class="kfs-pill-text">OUTSIDE STORE GEOFENCE (<?= round($distMeters / 1000, 2) ?> km)</span>
            </div>
            <?php else: ?>
            <div id="geofence_status_pill" class="kfs-geofence-pill unknown">
              <span class="kfs-pill-icon"><?= icon('alert-circle', 14) ?></span>
              <span class="kfs-pill-text">GPS SIGNAL UNAVAILABLE (IP ONLY)</span>
            </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- Prominent Action Buttons -->
        <div class="kfs-actions-wrap">
          <div class="kfs-trust-toggle">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;" title="Skip location approval on this browser for 7 days">
              <input type="checkbox" id="trust_device_checkbox" style="width:14px; height:14px; accent-color:#16A34A; cursor:pointer;">
              <span>Trust this device for 7 days (Bypass daily check)</span>
            </label>
            <span style="font-size:10px; color:#9CA3AF;">ID #<?= $activeReq['id'] ?></span>
          </div>

          <!-- Big Vibrant Green Button -->
          <button type="button" id="btn_approve_main" onclick="handleMainApprove()" class="kfs-btn-approve">
            <span class="kfs-btn-icon"><?= icon('check', 17) ?></span>
            <span class="kfs-btn-text">APPROVE LOGIN</span>
          </button>

          <!-- Big Vibrant Red Button -->
          <button type="button" id="btn_reject_main" onclick="handleMainReject()" class="kfs-btn-reject">
            <span class="kfs-btn-icon"><?= icon('x', 17) ?></span>
            <span class="kfs-btn-text">REJECT</span>
          </button>
        </div>

        <?php endif; ?>
      </div>

    </div>

    <!-- Bottom Section: Tabs for Audit History, Geofence Settings & Trusted Devices -->
    <div class="kfs-bottom-card">
      <div class="kfs-tab-bar">
        <div class="kfs-tab-pills" role="tablist">
          <button type="button" role="tab" aria-selected="true" onclick="switchSubTab('history')" id="subtab_btn_history" class="kfs-tab-pill active">
            <?= icon('history', 15, 'kfs-tab-svg') ?>
            <span>Audit History (<?= count($historyRecords) ?>)</span>
          </button>
          <button type="button" role="tab" aria-selected="false" onclick="switchSubTab('settings')" id="subtab_btn_settings" class="kfs-tab-pill">
            <?= icon('settings', 15, 'kfs-tab-svg') ?>
            <span>Geofence & Store Pin Settings</span>
          </button>
          <button type="button" role="tab" aria-selected="false" onclick="switchSubTab('trusted')" id="subtab_btn_trusted" class="kfs-tab-pill">
            <?= icon('laptop', 15, 'kfs-tab-svg') ?>
            <span>Trusted Devices (<?= $totalTrusted ?>)</span>
          </button>
        </div>

        <span class="kfs-store-pin-indicator">
          <?= icon('pin', 14) ?>
          <span>Store Pin: <strong class="kfs-coords"><?= number_format($settings['store_lat'], 5) ?>, <?= number_format($settings['store_lon'], 5) ?></strong></span>
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
                <td colspan="6" style="text-align:center; padding:44px 16px;">
                  <div style="display:flex; flex-direction:column; align-items:center; gap:8px; color:#9CA3AF;">
                    <span style="display:inline-flex; padding:12px; border-radius:50%; background:#F3EFEA; color:#78716C;">
                      <?= icon('history', 24) ?>
                    </span>
                    <strong style="color:#4B5563; font-size:13px;">No authorization events logged yet</strong>
                    <p style="margin:0; font-size:11.5px; color:#9CA3AF;">Completed approvals and rejections will appear in this audit log.</p>
                  </div>
                </td>
              </tr>
              <?php else: foreach ($historyRecords as $h):
                $hName = trim(($h['firstname'] ?? '') . ' ' . ($h['lastname'] ?? '')) ?: $h['username'];
                $st = $h['status'];
                $hThumb = null;
                if (!empty($h['avatar_path']) && file_exists(__DIR__ . '/../' . ltrim($h['avatar_path'], '/'))) {
                    $hThumb = '../' . ltrim($h['avatar_path'], '/');
                }
                $hInitials = strtoupper(substr($h['firstname'] ?: $h['username'], 0, 1) . substr($h['lastname'] ?? '', 0, 1));
              ?>
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:10px;">
                    <?php if ($hThumb): ?>
                      <img src="<?= htmlspecialchars($hThumb) ?>" alt="avatar" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid #E5E7EB; flex-shrink:0;">
                    <?php else: ?>
                      <div style="width:34px; height:34px; border-radius:50%; background:#7A1C1C; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:11.5px; font-weight:700; flex-shrink:0;">
                        <?= htmlspecialchars($hInitials) ?>
                      </div>
                    <?php endif; ?>
                    <div>
                      <strong style="color:#1F1626;"><?= htmlspecialchars($hName) ?></strong>
                      <div style="font-size:10.5px; color:#78716C;">@<?= htmlspecialchars($h['username']) ?> &bull; <?= htmlspecialchars($h['role']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge-app <?= $st ?>">
                    <?php if ($st === 'approved'): ?>
                      <?= icon('check', 11) ?>
                    <?php elseif ($st === 'rejected'): ?>
                      <?= icon('x', 11) ?>
                    <?php elseif ($st === 'pending'): ?>
                      <?= icon('clock', 11) ?>
                    <?php endif; ?>
                    <span><?= htmlspecialchars(ucfirst($st)) ?></span>
                  </span>
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
                  <span style="color:#059669; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                    <?= icon('check-circle', 13) ?>
                    <span>Approved by <?= htmlspecialchars($h['approved_by_name'] ?: 'HR') ?></span>
                  </span>
                  <?php elseif ($st === 'rejected'): ?>
                  <span style="color:#DC2626; font-weight:500; display:inline-flex; align-items:center; gap:4px;" title="<?= htmlspecialchars($h['rejection_reason'] ?? '') ?>">
                    <?= icon('alert-circle', 13) ?>
                    <span><?= htmlspecialchars($h['rejection_reason'] ?: 'Denied') ?></span>
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
        <form id="geofence-settings-form" method="POST" action="login_approvals.php" style="max-width:1040px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_settings">

          <div class="kfs-settings-grid" style="gap:16px; margin-bottom:18px;">
            <div style="background:#FAF7F2; border:1px solid #EFE6DC; border-radius:14px; padding:16px;">
              <label for="require_approval_enabled" style="font-size:12.5px; font-weight:700; color:#1F1626; display:block; cursor:pointer;">
                Require HR Login Authorization
              </label>
              <p style="font-size:11.5px; color:#6B7280; margin:4px 0 10px 0;">Staff on new devices wait for HR review unless their role is selected for bypass below.</p>
              <input type="checkbox" id="require_approval_enabled" name="require_approval_enabled" value="1"
                     <?= $settings['enabled'] ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:#C97B3D; cursor:pointer;">
            </div>

            <div style="background:#FAF7F2; border:1px solid #EFE6DC; border-radius:14px; padding:16px;">
              <strong style="font-size:12.5px; color:#1F1626;">Location for HR Review</strong>
              <p style="font-size:11.5px; color:#6B7280; margin:4px 0 0;">The store pin and radius help HR review staff locations. GPS alone does not approve a new device.</p>
            </div>
          </div>

          <!-- Map Toolbar: Detect Location button & guidance -->
          <div class="kfs-map-toolbar">
            <button type="button" id="detect-store-pin" class="kfs-btn-detect">
              <?= icon('crosshair', 14) ?>
              <span>Use My Current Location</span>
            </button>
            <p id="store-pin-status" role="status" aria-live="polite" class="kfs-settings-help">Click the map, drag the pin, or enter coordinates to choose a new store location. Save to apply it.</p>
          </div>

          <!-- Side-by-Side: Map on the left, Exempt Roles (Bypass) on the right (same height, scrollable roles) -->
          <div class="kfs-geofence-map-row">
            <div class="kfs-map-col">
              <div id="storePinMap" class="kfs-store-pin-map" aria-label="Store pin location map"></div>
            </div>

            <div class="kfs-bypass-col">
              <section class="kfs-bypass-roles" aria-labelledby="bypass-roles-heading">
                <div class="kfs-bypass-header">
                  <div>
                    <h2 id="bypass-roles-heading">Exempt Roles (Bypass)</h2>
                    <span class="kfs-bypass-sub">Skip HR approval upon sign-in</span>
                  </div>
                  <button type="button" id="choose-bypass-roles" class="kfs-role-button" aria-haspopup="dialog" aria-controls="bypass-roles-modal">Choose roles</button>
                </div>
                <?php $selectedBypassRoles = array_filter($availableRoles, fn($role) => in_array($role['role_key'], $settings['exempt_roles'], true)); ?>
                <div class="kfs-bypass-body" aria-live="polite" aria-atomic="true">
                  <ul id="bypass-roles-summary" class="kfs-role-summary" aria-label="Selected bypass roles">
                    <?php foreach ($selectedBypassRoles as $role): ?>
                    <li><?= htmlspecialchars($role['label']) ?></li>
                    <?php endforeach; ?>
                  </ul>
                  <p id="bypass-roles-empty" class="kfs-settings-help" <?= $selectedBypassRoles ? 'hidden' : '' ?>>No roles selected for bypass.</p>
                </div>
              </section>
            </div>
          </div>

          <!-- Below Map: Store/Branch Name, Store Latitude, Store Longitude, Geofence Radius (Meters) -->
          <div class="kfs-geofence-fields-card">
            <div class="kfs-geofence-fields-grid">
              <div class="kfs-field-group kfs-field-store-name">
                <label for="setting_store_name" class="kfs-field-label">
                  <?= icon('home', 14) ?>
                  <span>Store / Branch Name</span>
                </label>
                <input type="text" id="setting_store_name" name="store_name" value="<?= htmlspecialchars($settings['store_name']) ?>" maxlength="120" required
                       class="kfs-form-input" placeholder="e.g. Dasmariñas Branch">
              </div>

              <div class="kfs-field-group">
                <label for="setting_lat" class="kfs-field-label">
                  <?= icon('crosshair', 14) ?>
                  <span>Store Latitude</span>
                </label>
                <input type="number" id="setting_lat" name="store_latitude" value="<?= htmlspecialchars((string)$settings['store_lat']) ?>" min="-90" max="90" step="any" required
                       class="kfs-form-input font-mono" placeholder="14.3294000">
              </div>

              <div class="kfs-field-group">
                <label for="setting_lon" class="kfs-field-label">
                  <?= icon('crosshair', 14) ?>
                  <span>Store Longitude</span>
                </label>
                <input type="number" id="setting_lon" name="store_longitude" value="<?= htmlspecialchars((string)$settings['store_lon']) ?>" min="-180" max="180" step="any" required
                       class="kfs-form-input font-mono" placeholder="120.9367000">
              </div>

              <div class="kfs-field-group">
                <label for="setting_radius" class="kfs-field-label">
                  <?= icon('shield', 14) ?>
                  <span>Geofence Radius (Meters)</span>
                </label>
                <input type="number" id="setting_radius" name="store_geofence_radius_meters" value="<?= htmlspecialchars((string)$settings['geofence_radius']) ?>" min="20" max="10000" step="any" required
                       class="kfs-form-input font-mono" placeholder="200">
              </div>
            </div>
          </div>

          <!-- Exempt Roles Modal (Dialog) Centered in Viewport -->
          <dialog id="bypass-roles-modal" class="kfs-role-modal" aria-labelledby="bypass-roles-modal-title" aria-describedby="bypass-role-help">
            <div class="kfs-role-modal-header">
              <div class="kfs-role-modal-title-wrap">
                <div class="kfs-role-modal-icon">
                  <?= icon('shield', 18) ?>
                </div>
                <div>
                  <h2 id="bypass-roles-modal-title">Choose Bypass Roles</h2>
                  <p id="bypass-role-help" class="kfs-settings-help">Select roles that can sign in without HR approval. Save your selection, then use Save Geofence Settings to apply it.</p>
                </div>
              </div>
              <button type="button" class="kfs-role-modal-close" onclick="document.getElementById('bypass-roles-modal').close()" aria-label="Close dialog">
                <?= icon('x', 16) ?>
              </button>
            </div>
            <fieldset class="kfs-role-checklist">
              <legend>Existing roles</legend>
              <?php foreach ($availableRoles as $role): ?>
              <label class="kfs-role-option">
                <input type="checkbox" name="exempt_roles[]" value="<?= htmlspecialchars($role['role_key']) ?>" data-role-label="<?= htmlspecialchars($role['label']) ?>" <?= in_array($role['role_key'], $settings['exempt_roles'], true) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($role['label']) ?> <small>(<?= htmlspecialchars($role['role_key']) ?>)</small></span>
              </label>
              <?php endforeach; ?>
            </fieldset>
            <div class="kfs-role-modal-actions">
              <button type="button" id="cancel-bypass-roles" class="kfs-role-button">Cancel</button>
              <button type="button" id="save-bypass-roles" class="kfs-role-button kfs-role-button-primary">Save</button>
            </div>
          </dialog>

          <button type="submit" class="kfs-btn-save-settings">
            <?= icon('save', 15) ?>
            <span>Save Geofence Settings</span>
          </button>
        </form>
      </div>

      <!-- SUBTAB 3: TRUSTED DEVICES -->
      <div id="subtab_content_trusted" style="display:none;">
        <div style="max-width:640px; display:flex; flex-direction:column; gap:10px;">
          <?php if (empty($trustedDevices)): ?>
          <div style="text-align:center; padding:36px 16px; background:#FAF7F2; border-radius:14px; border:1px dashed #E5D7C5;">
            <div style="width:44px; height:44px; border-radius:50%; background:#EFE0CC; color:#78716C; margin:0 auto 10px auto; display:flex; align-items:center; justify-content:center;">
              <?= icon('laptop', 20) ?>
            </div>
            <strong style="display:block; color:#4B5563; font-size:13px; margin-bottom:4px;">No Active Trusted Devices</strong>
            <p style="font-size:11.5px; color:#78716C; margin:0; max-width:320px; margin:0 auto;">
              When staff devices are approved with trust enabled, their authorization tokens appear here.
            </p>
          </div>
          <?php else: foreach ($trustedDevices as $td): ?>
          <div class="kfs-trusted-card">
            <div class="kfs-trusted-icon-wrap">
              <?= icon('laptop', 20) ?>
            </div>
            <div class="kfs-trusted-info">
              <strong class="kfs-trusted-name"><?= htmlspecialchars($td['firstname'] . ' ' . $td['lastname']) ?></strong>
              <div class="kfs-trusted-meta">Approved browser &bull; ID #<?= $td['id'] ?></div>
              <div class="kfs-trusted-expiry">
                <?= icon('clock', 12) ?>
                <span>Expires: <?= date('M d, Y', strtotime($td['expires_at'])) ?></span>
              </div>
            </div>
            <form method="POST" action="login_approvals.php" onsubmit="return confirm('Revoke trust for this device?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke_device">
              <input type="hidden" name="device_id" value="<?= $td['id'] ?>">
              <button type="submit" class="kfs-btn-revoke" title="Revoke device access">
                <?= icon('trash', 13) ?>
                <span>Revoke</span>
              </button>
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
<div id="reject_modal" class="kfs-modal-backdrop" onclick="if(event.target===this)closeRejectModal()">
  <div class="kfs-modal-box" role="dialog" aria-modal="true" aria-labelledby="reject_modal_title">
    <div class="kfs-modal-head">
      <div class="kfs-modal-head-title-wrap">
        <span class="kfs-modal-head-icon"><?= icon('alert-triangle', 18) ?></span>
        <h3 id="reject_modal_title" class="kfs-modal-title">Reject Login Attempt</h3>
      </div>
      <button type="button" onclick="closeRejectModal()" class="kfs-modal-close" aria-label="Close reject modal">
        <?= icon('x', 16) ?>
      </button>
    </div>

    <form method="POST" action="login_approvals.php" class="kfs-reject-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" id="reject_auth_id" name="auth_id" value="">

      <p class="kfs-reject-desc">
        Rejecting sign-in attempt for <strong id="reject_emp_name_modal">Staff</strong>. Select a reason preset or provide specific instructions:
      </p>

      <div class="kfs-preset-list">
        <label class="kfs-preset-card">
          <input type="radio" name="reason_preset" value="Outside workplace branch geofence radius." checked onchange="document.getElementById('custom_reason').value = this.value">
          <span class="kfs-preset-content">
            <span class="kfs-preset-icon"><?= icon('pin', 15) ?></span>
            <span class="kfs-preset-label">Outside workplace branch geofence radius</span>
          </span>
        </label>
        <label class="kfs-preset-card">
          <input type="radio" name="reason_preset" value="Staff is not scheduled for a shift today." onchange="document.getElementById('custom_reason').value = this.value">
          <span class="kfs-preset-content">
            <span class="kfs-preset-icon"><?= icon('calendar', 15) ?></span>
            <span class="kfs-preset-label">Staff is not scheduled for a shift today</span>
          </span>
        </label>
        <label class="kfs-preset-card">
          <input type="radio" name="reason_preset" value="Unrecognized personal device or suspicious IP." onchange="document.getElementById('custom_reason').value = this.value">
          <span class="kfs-preset-content">
            <span class="kfs-preset-icon"><?= icon('shield', 15) ?></span>
            <span class="kfs-preset-label">Unrecognized personal device or suspicious IP</span>
          </span>
        </label>
      </div>

      <div class="kfs-custom-reason-wrap">
        <label for="custom_reason" class="kfs-input-label">
          Message to Employee:
        </label>
        <textarea id="custom_reason" name="reason" rows="2" required class="kfs-textarea">Outside workplace branch geofence radius.</textarea>
      </div>

      <div class="kfs-modal-foot">
        <button type="button" onclick="closeRejectModal()" class="kfs-btn-secondary">Cancel</button>
        <button type="submit" class="kfs-btn-danger">
          <?= icon('x', 14) ?>
          <span>Confirm Rejection</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script src="../js/login-approval-settings.js?v=<?= filemtime(__DIR__ . '/../js/login-approval-settings.js') ?>"></script>
<script>
const STORE_LAT = <?= json_encode((float)$settings['store_lat']) ?>;
const STORE_LON = <?= json_encode((float)$settings['store_lon']) ?>;
const STORE_NAME = <?= json_encode($settings['store_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
  // Keep an in-progress pin or role selection intact while HR edits settings.
  if (document.getElementById('subtab_content_settings').style.display !== 'none') return;
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
  if (!mapEl || typeof L === 'undefined') return;

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
        <div style="position:absolute; inset:0; background:radial-gradient(circle, rgba(201,123,61,0.45) 0%, transparent 70%); border-radius:50%;"></div>
        <div style="width:36px; height:36px; border-radius:50%; background:#C97B3D; color:#ffffff; display:flex; align-items:center; justify-content:center; border:2.5px solid #ffffff; box-shadow:0 6px 14px rgba(0,0,0,0.28);">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>
          </svg>
        </div>
      </div>
    `,
    iconSize: [44, 44],
    iconAnchor: [22, 22]
  });

  const storePopup = document.createElement('div');
  const storeTitle = document.createElement('strong');
  storeTitle.textContent = STORE_NAME;
  storePopup.append(storeTitle, document.createElement('br'), 'Workplace Store Center');
  L.marker([STORE_LAT, STORE_LON], { icon: storeIcon }).addTo(radarMapInstance).bindPopup(storePopup);

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
      const empPhoto = cur.avatar_path ? kofeePrivateFileUrl(cur.avatar_path.replace(/^\//,'')) :
                       (cur.selfie_photo ? ('../' + cur.selfie_photo.replace(/^\//,'')) : null);

      // Employee Marker (Photo or round badge with initials)
      const empIcon = L.divIcon({
        className: 'custom-emp-pin',
        html: empPhoto ? `
          <div style="width:36px; height:36px; border-radius:50%; overflow:hidden; border:2.5px solid ${isInside ? '#10B981' : '#EF4444'}; box-shadow:0 6px 14px rgba(0,0,0,0.3); background:#ffffff;">
            <img src="${empPhoto}" style="width:100%; height:100%; object-fit:cover;" onerror="this.parentElement.innerHTML='<div style=\\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:${isInside ? '#10B981' : '#EF4444'};color:#ffffff;font-size:12px;font-weight:700;\\'>${initials}</div>'">
          </div>
        ` : `
          <div style="width:34px; height:34px; border-radius:50%; background:${isInside ? '#10B981' : '#EF4444'}; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; border:2.5px solid #ffffff; box-shadow:0 6px 14px rgba(0,0,0,0.3);">
            ${initials}
          </div>
        `,
        iconSize: [36, 36],
        iconAnchor: [18, 18]
      });

      const statusTag = isInside
        ? `<span style="display:inline-flex;align-items:center;gap:5px;color:#059669;font-weight:600;"><span style="width:7px;height:7px;border-radius:50%;background:#10B981;display:inline-block;"></span> Inside Geofence (${Math.round(dist)}m)</span>`
        : `<span style="display:inline-flex;align-items:center;gap:5px;color:#DC2626;font-weight:600;"><span style="width:7px;height:7px;border-radius:50%;background:#EF4444;display:inline-block;"></span> Outside Store (${(dist/1000).toFixed(2)} km)</span>`;

      const empMarker = L.marker([lat, lon], { icon: empIcon }).addTo(radarMapInstance)
        .bindPopup(`<b>${cur.firstname || cur.username}</b><br>${statusTag}`)
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
  document.querySelectorAll('.kfs-queue-thumb').forEach((btn, i) => {
    btn.classList.toggle('active', i === idx);
  });
  updateEmployeeCardUI();
  initRadarMap();
}

function navigateQueue(step) {
  let next = activeIndex + step;
  if (next < 0) next = pendingList.length - 1;
  if (next >= pendingList.length) next = 0;
  selectQueueIndex(next);
}

// Center Radar View on active targets
function resetRadarView() {
  if (!radarMapInstance) return;
  if (pendingList && pendingList.length > 0) {
    const cur = pendingList[activeIndex];
    if (cur && cur.latitude !== null && cur.longitude !== null) {
      const bounds = L.latLngBounds([[STORE_LAT, STORE_LON], [parseFloat(cur.latitude), parseFloat(cur.longitude)]]);
      radarMapInstance.fitBounds(bounds.pad(0.35));
      return;
    }
  }
  radarMapInstance.setView([STORE_LAT, STORE_LON], 16);
}

// Dismiss Toast Notification
function dismissToast(btn) {
  const toast = btn.closest('.kfs-toast-alert');
  if (toast) {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-6px)';
    setTimeout(() => toast.remove(), 250);
  }
}

// Update the Employee Details Panel UI to reflect the selected request
function updateEmployeeCardUI() {
  const cardContainer = document.getElementById('employee_card_container');
  if (!pendingList || pendingList.length === 0) {
    if (cardContainer) {
      cardContainer.innerHTML = `
        <div class="kfs-empty-state">
          <div class="kfs-empty-icon-wrap">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>
            </svg>
          </div>
          <h3 class="kfs-empty-title">No Pending Requests</h3>
          <p class="kfs-empty-desc">
            All staff logins have been verified. When an employee signs in, their GPS proximity and selfie proof will appear here.
          </p>
          <button type="button" onclick="refreshQueue()" class="kfs-empty-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
            </svg>
            <span>Check for Requests</span>
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

  // Status Pill with Offline Vector SVG
  const pill = document.getElementById('geofence_status_pill');
  if (pill) {
    if (isInside) {
      pill.className = 'kfs-geofence-pill inside';
      pill.innerHTML = `
        <span class="kfs-pill-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
        <span class="kfs-pill-text">INSIDE STORE GEOFENCE</span>
      `;
    } else if (dist !== null) {
      pill.className = 'kfs-geofence-pill outside';
      pill.innerHTML = `
        <span class="kfs-pill-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
        <span class="kfs-pill-text">OUTSIDE STORE GEOFENCE (${(dist / 1000).toFixed(2)} km)</span>
      `;
    } else {
      pill.className = 'kfs-geofence-pill unknown';
      pill.innerHTML = `
        <span class="kfs-pill-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
        <span class="kfs-pill-text">GPS SIGNAL UNAVAILABLE (IP ONLY)</span>
      `;
    }
  }

  // Photo
  const imgEl = document.getElementById('emp_photo_img');
  const fallbackEl = document.getElementById('emp_avatar_fallback');
  const labelEl = document.getElementById('kfs_photo_label');
  const photoUrl = cur.avatar_path ? kofeePrivateFileUrl(cur.avatar_path.replace(/^\//,'')) :
                   (cur.selfie_photo ? ('../' + cur.selfie_photo.replace(/^\//,'')) : null);

  if (labelEl) {
    labelEl.textContent = cur.avatar_path ? 'Profile Picture' : (cur.selfie_photo ? 'Selfie Proof' : 'Staff Proof');
  }

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
  btn.innerHTML = `
    <svg class="spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/>
    </svg>
    <span>Approving…</span>
  `;

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
      btn.innerHTML = `
        <span class="kfs-btn-icon"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
        <span class="kfs-btn-text">APPROVE LOGIN</span>
      `;
    }
  } catch (err) {
    console.error(err);
    alert('Server error during approval.');
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.innerHTML = `
      <span class="kfs-btn-icon"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
      <span class="kfs-btn-text">APPROVE LOGIN</span>
    `;
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
  if (icon) icon.classList.add('spin');

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
    if (icon) icon.classList.remove('spin');
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
  document.getElementById('subtab_btn_history').setAttribute('aria-selected', 'false');
  document.getElementById('subtab_btn_settings').setAttribute('aria-selected', 'false');
  document.getElementById('subtab_btn_trusted').setAttribute('aria-selected', 'false');

  document.getElementById(`subtab_content_${tab}`).style.display = 'block';
  document.getElementById(`subtab_btn_${tab}`).classList.add('active');
  document.getElementById(`subtab_btn_${tab}`).setAttribute('aria-selected', 'true');
  if (tab === 'settings') window.storePinSettings.showMap();
}

// Close reject modal on Escape key
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeRejectModal();
});

// Auto-dismiss toast after 6 seconds
const toastEl = document.getElementById('kfs_toast_alert');
if (toastEl) {
  setTimeout(() => {
    toastEl.style.opacity = '0';
    toastEl.style.transform = 'translateY(-6px)';
    setTimeout(() => toastEl.remove(), 250);
  }, 6000);
}

// Auto-run on load (NO automatic page refresh)
document.addEventListener('DOMContentLoaded', () => {
  if (new URLSearchParams(window.location.search).get('tab') === 'settings') switchSubTab('settings');
  setTimeout(() => {
    initRadarMap();
    if (radarMapInstance) radarMapInstance.invalidateSize();
  }, 250);
});
</script>
</body>
</html>
