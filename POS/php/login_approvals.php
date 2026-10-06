<?php
// ==============================================================================
// FILE: php/login_approvals.php
// HR Geolocation & Staff Login Authorizations Control Center
// Reviews, approves, or rejects out-of-workplace or untrusted login attempts.
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
        $tab = htmlspecialchars($_POST['current_tab'] ?? 'queue');
        header('Location: login_approvals.php?tab=' . urlencode($tab) . '&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }
}

// Flash Toast Messages
if (!$toast && isset($_GET['toast'])) {
    $toast = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$activeTab = $_GET['tab'] ?? 'queue';

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
$todayApproved = (int)$pdo->query("SELECT COUNT(*) FROM login_authorizations WHERE status = 'approved' AND DATE(approved_at) = CURDATE()")->fetchColumn();
$todayRejected = (int)$pdo->query("SELECT COUNT(*) FROM login_authorizations WHERE status = 'rejected' AND DATE(rejected_at) = CURDATE()")->fetchColumn();
$totalTrusted = count($trustedDevices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Login Approvals & Geolocation — Kofee Café</title>
<?= csrf_meta() ?>
<link rel="stylesheet" href="../css/index.css">
<link rel="stylesheet" href="../css/sidebar.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,500&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Leaflet OpenStreetMap CDN for Interactive Geofence Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<style>
  :root {
    --espresso: #241A2E;
    --espresso-deep: #181120;
    --cream: #FBF3E9;
    --caramel: #C97B3D;
    --caramel-light: #E6A25C;
    --latte: #EFE0CC;
  }
  * { font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif; }
  .font-display { font-family: 'Playfair Display', serif; }

  .nav-tab-btn.active {
    background: #fff;
    color: var(--espresso);
    box-shadow: 0 4px 12px -2px rgba(0,0,0,0.08);
    font-weight: 600;
  }

  .pulse-badge {
    animation: badgePulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  }
  @keyframes badgePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(1.08); }
  }

  .leaflet-popup-content-wrapper {
    border-radius: 12px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.25);
    font-family: 'Poppins', sans-serif;
  }
</style>
</head>
<body class="bg-[#F8F5F1] text-[var(--espresso)] min-h-screen">

<?php include("../includes/sidebar.php"); ?>

<div id="page-login-approvals" class="page active pl-0 lg:pl-64 pt-16 min-h-screen transition-all">
  <div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">

    <!-- Toast Notification -->
    <?php if ($toast): ?>
    <div id="toast-banner" class="mb-6 flex items-center justify-between p-4 rounded-xl border <?= $toast_type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?> shadow-sm">
      <div class="flex items-center gap-2.5">
        <span><?= $toast_type === 'success' ? '✓' : '⚠️' ?></span>
        <span class="text-sm font-medium"><?= htmlspecialchars($toast) ?></span>
      </div>
      <button onclick="document.getElementById('toast-banner').remove()" class="text-xs font-bold opacity-60 hover:opacity-100">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
      <div>
        <div class="flex items-center gap-2.5">
          <span class="px-2.5 py-1 rounded-md bg-[var(--latte)] text-[var(--espresso)] text-xs font-semibold uppercase tracking-wider">
            HR Security Gate
          </span>
          <?php if (!$settings['enabled']): ?>
          <span class="px-2 py-0.5 rounded-md bg-stone-200 text-stone-600 text-xs font-medium">
            Authorization Disabled
          </span>
          <?php endif; ?>
        </div>
        <h1 class="text-2xl sm:text-3xl font-bold font-display text-[var(--espresso)] mt-1">
          Staff Login Approvals & Geolocation
        </h1>
        <p class="text-xs sm:text-sm text-stone-500 mt-0.5">
          Restricts staff logins to the workplace store premises with real-time HR authorization review.
        </p>
      </div>

      <div class="flex items-center gap-2.5">
        <button type="button" onclick="refreshQueue()" class="flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white border border-stone-200 text-stone-700 text-xs font-semibold hover:bg-stone-50 shadow-xs cursor-pointer">
          <svg id="refresh_icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
          </svg>
          <span id="refresh_label">Refresh Queue</span>
        </button>

        <a href="#settings" onclick="switchTab('settings')" class="flex items-center gap-2 px-4 py-2 rounded-xl bg-[linear-gradient(135deg,var(--caramel)_0%,var(--espresso-deep)_120%)] text-white text-xs font-semibold shadow-sm hover:opacity-95 transition-opacity">
          <?= icon('lock', 14) ?>
          <span>Geofence Settings</span>
        </a>
      </div>
    </div>

    <!-- Quick Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 sm:gap-4 mb-7">
      
      <!-- Pending Requests -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-stone-200/80 shadow-xs flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-700 flex items-center justify-center shrink-0 <?= $totalPending > 0 ? 'pulse-badge' : '' ?>">
          <?= icon('bell', 22) ?>
        </div>
        <div>
          <div class="text-xs text-stone-500 font-medium">Pending Requests</div>
          <div class="text-2xl font-bold text-[var(--espresso)] mt-0.5 flex items-center gap-2">
            <span id="stat_pending_count"><?= $totalPending ?></span>
            <?php if ($totalPending > 0): ?>
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-ping"></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Approved Today -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-stone-200/80 shadow-xs flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center shrink-0">
          <?= icon('shield-check', 22) ?>
        </div>
        <div>
          <div class="text-xs text-stone-500 font-medium">Approved Today</div>
          <div class="text-2xl font-bold text-emerald-700 mt-0.5"><?= $todayApproved ?></div>
        </div>
      </div>

      <!-- Denied Today -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-stone-200/80 shadow-xs flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-xl bg-rose-50 text-rose-700 flex items-center justify-center shrink-0">
          <?= icon('logout', 22) ?>
        </div>
        <div>
          <div class="text-xs text-stone-500 font-medium">Rejected Today</div>
          <div class="text-2xl font-bold text-rose-700 mt-0.5"><?= $todayRejected ?></div>
        </div>
      </div>

      <!-- Store Geofence Radius -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-stone-200/80 shadow-xs flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center shrink-0">
          <?= icon('home', 22) ?>
        </div>
        <div class="truncate">
          <div class="text-xs text-stone-500 font-medium">Store Geofence</div>
          <div class="text-base font-bold text-[var(--espresso)] mt-0.5 truncate">
            &plusmn;<?= (int)$settings['geofence_radius'] ?>m Radius
          </div>
        </div>
      </div>

    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-1.5 p-1.5 rounded-2xl bg-stone-200/70 w-fit mb-6 text-xs text-stone-600 font-medium overflow-x-auto">
      <button type="button" onclick="switchTab('queue')" id="tab_btn_queue" class="nav-tab-btn flex items-center gap-2 px-4 py-2 rounded-xl cursor-pointer transition-all <?= $activeTab === 'queue' ? 'active' : '' ?>">
        <span>Active Queue</span>
        <span id="tab_badge_queue" class="px-1.5 py-0.2 rounded-full text-[10px] font-bold <?= $totalPending > 0 ? 'bg-amber-500 text-white' : 'bg-stone-300 text-stone-700' ?>">
          <?= $totalPending ?>
        </span>
      </button>

      <button type="button" onclick="switchTab('map')" id="tab_btn_map" class="nav-tab-btn flex items-center gap-2 px-4 py-2 rounded-xl cursor-pointer transition-all <?= $activeTab === 'map' ? 'active' : '' ?>">
        <span>Workplace Map Radar</span>
      </button>

      <button type="button" onclick="switchTab('history')" id="tab_btn_history" class="nav-tab-btn flex items-center gap-2 px-4 py-2 rounded-xl cursor-pointer transition-all <?= $activeTab === 'history' ? 'active' : '' ?>">
        <span>Audit History</span>
      </button>

      <button type="button" onclick="switchTab('settings')" id="tab_btn_settings" class="nav-tab-btn flex items-center gap-2 px-4 py-2 rounded-xl cursor-pointer transition-all <?= $activeTab === 'settings' ? 'active' : '' ?>">
        <span>Geofence Settings</span>
      </button>
    </div>

    <!-- TAB 1: PENDING REVIEW QUEUE -->
    <div id="tab_content_queue" class="tab-pane <?= $activeTab === 'queue' ? '' : 'hidden' ?>">
      <div id="queue_cards_container" class="space-y-4">
        <?php if (empty($pendingRequests)): ?>
        <div class="bg-white rounded-3xl p-10 text-center border border-stone-200/70 shadow-xs">
          <div class="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 mx-auto mb-3 flex items-center justify-center text-2xl">
            ✓
          </div>
          <h3 class="font-display text-xl font-bold text-[var(--espresso)]">No Pending Login Requests</h3>
          <p class="text-xs text-stone-500 mt-1 max-w-md mx-auto">
            All staff login attempts have been reviewed. When an employee signs in from their terminal, their GPS location and device will appear here for HR review.
          </p>
        </div>
        <?php else: ?>
          <?php foreach ($pendingRequests as $req):
            $empName = trim(($req['firstname'] ?? '') . ' ' . ($req['lastname'] ?? '')) ?: $req['username'];
            $reqInitials = strtoupper(substr($req['firstname'] ?: $req['username'], 0, 1));
            $isInside = ($req['distance_meters'] !== null && (float)$req['distance_meters'] <= $settings['geofence_radius']);
            $hasCoords = ($req['latitude'] !== null && $req['longitude'] !== null);
          ?>
          <div class="bg-white rounded-2xl p-5 border border-stone-200 shadow-xs hover:shadow-md transition-shadow" id="req_card_<?= $req['id'] ?>">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
              
              <!-- Employee & Location Overview -->
              <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-2xl bg-[var(--caramel)] text-white font-bold flex items-center justify-center text-base shrink-0 shadow-xs">
                  <?= htmlspecialchars($reqInitials) ?>
                </div>

                <div>
                  <div class="flex items-center gap-2.5 flex-wrap">
                    <h3 class="text-base font-bold text-[var(--espresso)]"><?= htmlspecialchars($empName) ?></h3>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-md bg-stone-100 text-stone-700 capitalize">
                      <?= htmlspecialchars($req['role']) ?>
                    </span>
                    
                    <!-- Geolocation badge -->
                    <?php if ($isInside): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                      <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                      Inside Premises (<?= round((float)$req['distance_meters']) ?>m)
                    </span>
                    <?php elseif ($req['distance_meters'] !== null): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800">
                      <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                      Outside Store (<?= round((float)$req['distance_meters'] / 1000, 2) ?> km)
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                      <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                      GPS Denied / IP Only
                    </span>
                    <?php endif; ?>
                  </div>

                  <!-- Location Details -->
                  <div class="mt-2 text-xs text-stone-600 flex flex-wrap items-center gap-y-1 gap-x-4">
                    <span class="flex items-center gap-1.5 font-medium">
                      📍 <?= htmlspecialchars($req['location_name'] ?: 'Current Coordinates Logged') ?>
                    </span>
                    <?php if ($hasCoords): ?>
                    <span class="text-stone-400 font-mono text-[11px]">
                      Lat: <?= number_format((float)$req['latitude'], 5) ?>, Lon: <?= number_format((float)$req['longitude'], 5) ?> (&plusmn;<?= (int)$req['accuracy_meters'] ?>m)
                    </span>
                    <?php endif; ?>
                  </div>

                  <!-- Device & Time Details -->
                  <div class="mt-1 text-[11px] text-stone-500 flex flex-wrap items-center gap-x-4">
                    <span>💻 <?= htmlspecialchars($req['device_info'] ?: 'Browser Device') ?></span>
                    <span>🌐 IP: <?= htmlspecialchars($req['ip_address']) ?></span>
                    <span class="text-amber-700 font-medium">🕒 Requested <?= date('h:i:s A', strtotime($req['created_at'])) ?></span>
                  </div>
                </div>
              </div>

              <!-- Action Controls -->
              <div class="flex items-center gap-2 self-end lg:self-center shrink-0">
                
                <?php if ($hasCoords): ?>
                <button type="button" onclick="openLocationModal(<?= $req['id'] ?>, '<?= addslashes($empName) ?>', <?= (float)$req['latitude'] ?>, <?= (float)$req['longitude'] ?>, <?= (float)($req['distance_meters'] ?? 0) ?>)"
                        class="px-3 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-700 text-xs font-semibold flex items-center gap-1.5 transition-colors cursor-pointer">
                  🗺️ Map
                </button>
                <?php endif; ?>

                <!-- Approve Button & Menu -->
                <div class="relative inline-block text-left">
                  <button type="button" onclick="submitApprove(<?= $req['id'] ?>, 0)"
                          class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs flex items-center gap-1.5 transition-all cursor-pointer">
                    ✓ Approve Once
                  </button>
                </div>

                <button type="button" onclick="submitApprove(<?= $req['id'] ?>, 7)"
                        class="px-3 py-2 rounded-xl bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-xs font-semibold transition-colors cursor-pointer"
                        title="Approve and trust device for 7 days">
                  + Trust (7D)
                </button>

                <!-- Reject Button -->
                <button type="button" onclick="openRejectModal(<?= $req['id'] ?>, '<?= addslashes($empName) ?>')"
                        class="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors cursor-pointer">
                  ✕ Reject
                </button>

              </div>

            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB 2: INTERACTIVE WORKPLACE MAP RADAR -->
    <div id="tab_content_map" class="tab-pane <?= $activeTab === 'map' ? '' : 'hidden' ?>">
      <div class="bg-white rounded-3xl p-5 border border-stone-200/80 shadow-xs">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="font-display text-lg font-bold text-[var(--espresso)]">Workplace Store & Staff Geolocation Radar</h3>
            <p class="text-xs text-stone-500">Live visualization of the Kofee Manila store perimeter circle and employee login coordinates.</p>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-blue-600 inline-block"></span> Store Pin</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span> Inside Geofence</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span> Outside Store</span>
          </div>
        </div>

        <div id="radarMap" class="w-full h-[520px] rounded-2xl border border-stone-200 z-10"></div>
      </div>
    </div>

    <!-- TAB 3: AUDIT HISTORY -->
    <div id="tab_content_history" class="tab-pane <?= $activeTab === 'history' ? '' : 'hidden' ?>">
      <div class="bg-white rounded-3xl border border-stone-200/80 shadow-xs overflow-hidden">
        <div class="p-5 border-b border-stone-100 flex items-center justify-between">
          <h3 class="font-display text-lg font-bold text-[var(--espresso)]">Recent Authorization History</h3>
          <span class="text-xs text-stone-500"><?= count($historyRecords) ?> recent login events</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-stone-700">
            <thead class="bg-stone-50 text-stone-500 uppercase tracking-wider text-[10px] font-semibold">
              <tr>
                <th class="p-3.5 pl-6">Staff Member</th>
                <th class="p-3.5">Status</th>
                <th class="p-3.5">Location & Distance</th>
                <th class="p-3.5">Device / IP</th>
                <th class="p-3.5">Requested At</th>
                <th class="p-3.5">Reviewer & Notes</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
              <?php if (empty($historyRecords)): ?>
              <tr>
                <td colspan="6" class="p-8 text-center text-stone-400">No authorization history logged yet.</td>
              </tr>
              <?php else: foreach ($historyRecords as $h):
                $hName = trim(($h['firstname'] ?? '') . ' ' . ($h['lastname'] ?? '')) ?: $h['username'];
                $st = $h['status'];
                $stClass = match($st) {
                  'approved'  => 'bg-emerald-100 text-emerald-800',
                  'rejected'  => 'bg-rose-100 text-rose-800',
                  'expired'   => 'bg-stone-200 text-stone-700',
                  'cancelled' => 'bg-stone-100 text-stone-600',
                  default     => 'bg-amber-100 text-amber-800',
                };
              ?>
              <tr class="hover:bg-stone-50/70 transition-colors">
                <td class="p-3.5 pl-6 font-semibold text-[var(--espresso)]">
                  <div><?= htmlspecialchars($hName) ?></div>
                  <div class="text-[10px] text-stone-400 font-normal">@<?= htmlspecialchars($h['username']) ?> &bull; <?= htmlspecialchars($h['role']) ?></div>
                </td>
                <td class="p-3.5">
                  <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $stClass ?>">
                    <?= htmlspecialchars($st) ?>
                  </span>
                </td>
                <td class="p-3.5">
                  <div class="truncate max-w-xs font-medium text-stone-800"><?= htmlspecialchars($h['location_name'] ?: 'No GPS') ?></div>
                  <?php if ($h['distance_meters'] !== null): ?>
                  <div class="text-[10px] text-stone-400">Distance: <?= round((float)$h['distance_meters']) ?>m</div>
                  <?php endif; ?>
                </td>
                <td class="p-3.5">
                  <div class="truncate max-w-xs"><?= htmlspecialchars($h['device_info'] ?: 'Browser') ?></div>
                  <div class="text-[10px] font-mono text-stone-400"><?= htmlspecialchars($h['ip_address']) ?></div>
                </td>
                <td class="p-3.5 whitespace-nowrap text-stone-500">
                  <?= date('M d, Y h:i A', strtotime($h['created_at'])) ?>
                </td>
                <td class="p-3.5 max-w-xs">
                  <?php if ($st === 'approved'): ?>
                  <span class="text-emerald-700 font-medium">Approved by <?= htmlspecialchars($h['approved_by_name'] ?: 'HR') ?></span>
                  <?php elseif ($st === 'rejected'): ?>
                  <span class="text-rose-700 font-medium truncate block" title="<?= htmlspecialchars($h['rejection_reason'] ?? '') ?>">
                    Denial: <?= htmlspecialchars($h['rejection_reason'] ?: 'Unauthorized') ?>
                  </span>
                  <?php else: ?>
                  <span class="text-stone-400">&mdash;</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 4: GEOFENCE & SECURITY SETTINGS -->
    <div id="tab_content_settings" class="tab-pane <?= $activeTab === 'settings' ? '' : 'hidden' ?>">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Settings Form -->
        <div class="lg:col-span-2 bg-white rounded-3xl p-6 sm:p-7 border border-stone-200/80 shadow-xs">
          <div class="flex items-center justify-between pb-4 border-b border-stone-100 mb-6">
            <div>
              <h3 class="font-display text-lg font-bold text-[var(--espresso)]">Workplace Geofencing & Access Rules</h3>
              <p class="text-xs text-stone-500">Configure store coordinates, radius threshold, and exemption policies.</p>
            </div>
            <span class="text-xl">📍</span>
          </div>

          <form method="POST" action="login_approvals.php" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="current_tab" value="settings">

            <!-- Main Toggle -->
            <div class="flex items-start justify-between p-4 rounded-2xl bg-stone-50 border border-stone-200/70">
              <div class="pr-4">
                <label for="require_approval_enabled" class="text-sm font-bold text-[var(--espresso)] block cursor-pointer">
                  Require HR Authorization for Staff Logins
                </label>
                <p class="text-xs text-stone-500 mt-0.5">
                  When enabled, all non-exempt staff logging into the POS or workplace system must obtain HR review and approval with location verification.
                </p>
              </div>
              <input type="checkbox" id="require_approval_enabled" name="require_approval_enabled" value="1"
                     <?= $settings['enabled'] ? 'checked' : '' ?>
                     class="w-5 h-5 rounded text-[var(--caramel)] focus:ring-[var(--caramel)] cursor-pointer mt-1">
            </div>

            <!-- Auto Approve Inside Zone -->
            <div class="flex items-start justify-between p-4 rounded-2xl bg-stone-50 border border-stone-200/70">
              <div class="pr-4">
                <label for="auto_approve_within_geofence" class="text-sm font-bold text-[var(--espresso)] block cursor-pointer">
                  Auto-Approve Inside Store Geofence
                </label>
                <p class="text-xs text-stone-500 mt-0.5">
                  If enabled, staff who log in physically inside the store radius (&plusmn;<?= (int)$settings['geofence_radius'] ?>m) will be automatically authenticated without waiting for manual HR review.
                </p>
              </div>
              <input type="checkbox" id="auto_approve_within_geofence" name="auto_approve_within_geofence" value="1"
                     <?= $settings['auto_approve_in_zone'] ? 'checked' : '' ?>
                     class="w-5 h-5 rounded text-[var(--caramel)] focus:ring-[var(--caramel)] cursor-pointer mt-1">
            </div>

            <!-- Store Details -->
            <div class="space-y-4 pt-2">
              <div>
                <label class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1.5">
                  Store / Branch Name
                </label>
                <input type="text" name="store_name" value="<?= htmlspecialchars($settings['store_name']) ?>" required
                       class="w-full px-3.5 py-2.5 rounded-xl border border-stone-200 text-xs text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1.5">
                    Store Latitude
                  </label>
                  <input type="text" id="setting_lat" name="store_latitude" value="<?= htmlspecialchars((string)$settings['store_lat']) ?>" required
                         class="w-full px-3.5 py-2.5 rounded-xl border border-stone-200 text-xs font-mono text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">
                </div>
                <div>
                  <label class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1.5">
                    Store Longitude
                  </label>
                  <input type="text" id="setting_lon" name="store_longitude" value="<?= htmlspecialchars((string)$settings['store_lon']) ?>" required
                         class="w-full px-3.5 py-2.5 rounded-xl border border-stone-200 text-xs font-mono text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">
                </div>
              </div>

              <!-- Quick Geo Detection Button -->
              <div>
                <button type="button" onclick="detectStorePin()" class="text-xs px-3.5 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-800 font-semibold flex items-center gap-1.5 cursor-pointer">
                  <span>📍</span>
                  <span id="detect_pin_label">Use My Current Location as Store Pin</span>
                </button>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                <div>
                  <label class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1.5">
                    Geofence Radius (Meters)
                  </label>
                  <input type="number" name="store_geofence_radius_meters" value="<?= htmlspecialchars((string)$settings['geofence_radius']) ?>" min="20" max="10000" required
                         class="w-full px-3.5 py-2.5 rounded-xl border border-stone-200 text-xs text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">
                  <p class="text-[11px] text-stone-400 mt-1">Recommended: 150m &ndash; 300m for store accuracy.</p>
                </div>

                <div>
                  <label class="block text-xs font-semibold text-stone-600 uppercase tracking-wider mb-1.5">
                    Exempt Roles (Bypass Approval)
                  </label>
                  <input type="text" name="exempt_roles" value="<?= htmlspecialchars(implode(', ', $settings['exempt_roles'])) ?>"
                         class="w-full px-3.5 py-2.5 rounded-xl border border-stone-200 text-xs text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">
                  <p class="text-[11px] text-stone-400 mt-1">Comma-separated: e.g. <span class="font-mono">admin, hr, manager</span></p>
                </div>
              </div>

            </div>

            <div class="pt-4 border-t border-stone-100 flex justify-end">
              <button type="submit" class="px-6 py-2.5 rounded-xl bg-[linear-gradient(135deg,var(--caramel)_0%,var(--espresso-deep)_120%)] text-white text-xs font-semibold shadow-md hover:opacity-95 cursor-pointer">
                Save Geofence Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Trusted Devices Card -->
        <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between pb-3 border-b border-stone-100 mb-4">
              <h4 class="font-display text-base font-bold text-[var(--espresso)]">Active Trusted Devices</h4>
              <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-stone-100 text-stone-600"><?= $totalTrusted ?></span>
            </div>

            <p class="text-xs text-stone-500 mb-4 leading-relaxed">
              Devices explicitly trusted by HR can bypass daily approval until their expiration date.
            </p>

            <div class="space-y-3 max-h-[460px] overflow-y-auto pr-1">
              <?php if (empty($trustedDevices)): ?>
              <div class="p-6 text-center text-stone-400 text-xs">
                No active trusted devices recorded.
              </div>
              <?php else: foreach ($trustedDevices as $td): ?>
              <div class="p-3 rounded-2xl bg-stone-50 border border-stone-200/60 text-xs flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="font-bold text-[var(--espresso)] truncate"><?= htmlspecialchars($td['firstname'] . ' ' . $td['lastname']) ?></div>
                  <div class="text-[11px] text-stone-500 truncate"><?= htmlspecialchars($td['device_name']) ?></div>
                  <div class="text-[10px] text-emerald-700 mt-1">Expires: <?= date('M d, Y', strtotime($td['expires_at'])) ?></div>
                </div>

                <form method="POST" action="login_approvals.php" onsubmit="return confirm('Revoke trust for this device?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke_device">
                  <input type="hidden" name="device_id" value="<?= $td['id'] ?>">
                  <input type="hidden" name="current_tab" value="settings">
                  <button type="submit" class="text-[11px] text-rose-600 hover:text-rose-800 font-semibold p-1">Revoke</button>
                </form>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL: LOCATION MAP PREVIEW                                                   -->
<!-- ============================================================================== -->
<div id="location_modal" class="hidden fixed inset-0 z-[500] flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
  <div class="bg-white rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-pop">
    <div class="p-4 sm:p-5 border-b border-stone-200 flex items-center justify-between">
      <div>
        <h3 id="modal_map_title" class="font-display text-lg font-bold text-[var(--espresso)]">Employee Location Radar</h3>
        <p id="modal_map_subtitle" class="text-xs text-stone-500">Comparing employee GPS coordinates with Store Branch perimeter.</p>
      </div>
      <button onclick="closeLocationModal()" class="w-8 h-8 rounded-full bg-stone-100 hover:bg-stone-200 text-stone-600 flex items-center justify-center font-bold text-sm cursor-pointer">&times;</button>
    </div>

    <div class="p-4">
      <div id="modalMap" class="w-full h-96 rounded-2xl border border-stone-200"></div>
    </div>

    <div class="p-4 bg-stone-50 border-t border-stone-200 flex items-center justify-between text-xs">
      <span id="modal_distance_text" class="font-medium text-stone-700">Distance from store: Calculating…</span>
      <button onclick="closeLocationModal()" class="px-4 py-2 rounded-xl bg-stone-200 hover:bg-stone-300 font-semibold text-stone-800 cursor-pointer">Close</button>
    </div>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL: REJECT AUTHORIZATION                                                   -->
<!-- ============================================================================== -->
<div id="reject_modal" class="hidden fixed inset-0 z-[500] flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
  <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl animate-pop p-6">
    <div class="flex items-center justify-between pb-3 border-b border-stone-100">
      <h3 class="font-display text-lg font-bold text-rose-700">Reject Login Attempt</h3>
      <button onclick="closeRejectModal()" class="w-8 h-8 rounded-full bg-stone-100 hover:bg-stone-200 text-stone-600 flex items-center justify-center font-bold text-sm cursor-pointer">&times;</button>
    </div>

    <form method="POST" action="login_approvals.php" class="mt-4 space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" id="reject_auth_id" name="auth_id" value="">
      <input type="hidden" name="current_tab" value="queue">

      <p class="text-xs text-stone-600">
        You are rejecting the login attempt for <strong id="reject_emp_name" class="text-[var(--espresso)]">Staff</strong>. Please select or state the reason:
      </p>

      <div class="space-y-2 text-xs">
        <label class="flex items-center gap-2 p-2 rounded-xl border border-stone-200 hover:bg-stone-50 cursor-pointer">
          <input type="radio" name="reason_preset" value="Outside workplace branch geofence radius." checked onchange="document.getElementById('custom_reason').value = this.value">
          <span>Outside workplace branch geofence radius</span>
        </label>
        <label class="flex items-center gap-2 p-2 rounded-xl border border-stone-200 hover:bg-stone-50 cursor-pointer">
          <input type="radio" name="reason_preset" value="Staff is not scheduled for a shift today." onchange="document.getElementById('custom_reason').value = this.value">
          <span>Not scheduled for work shift today</span>
        </label>
        <label class="flex items-center gap-2 p-2 rounded-xl border border-stone-200 hover:bg-stone-50 cursor-pointer">
          <input type="radio" name="reason_preset" value="Unrecognized personal device or untrusted network." onchange="document.getElementById('custom_reason').value = this.value">
          <span>Unrecognized personal device or suspicious IP</span>
        </label>
      </div>

      <div>
        <label class="block text-[11px] font-semibold text-stone-500 uppercase tracking-wider mb-1">
          Message to Employee (Displayed on their screen):
        </label>
        <textarea id="custom_reason" name="reason" rows="2" required
                  class="w-full p-2.5 rounded-xl border border-stone-200 text-xs text-[var(--espresso)] focus:outline-none focus:border-[var(--caramel)]">Outside workplace branch geofence radius.</textarea>
      </div>

      <div class="flex items-center justify-end gap-2 pt-2">
        <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-700 text-xs font-semibold">Cancel</button>
        <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-sm">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<script>
const STORE_LAT = <?= json_encode($settings['store_lat']) ?>;
const STORE_LON = <?= json_encode($settings['store_lon']) ?>;
const STORE_NAME = <?= json_encode($settings['store_name']) ?>;
const GEOFENCE_RADIUS = <?= json_encode($settings['geofence_radius']) ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

let modalMapInstance = null;
let radarMapInstance = null;
let pollInterval = null;

// Tab switcher
function switchTab(tab) {
  document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('.nav-tab-btn').forEach(el => el.classList.remove('active'));

  const pane = document.getElementById(`tab_content_${tab}`);
  const btn = document.getElementById(`tab_btn_${tab}`);
  if (pane) pane.classList.remove('hidden');
  if (btn) btn.classList.add('active');

  if (tab === 'map') {
    setTimeout(initRadarMap, 150);
  }
}

// Auto-refresh queue via AJAX
async function refreshQueue() {
  const icon = document.getElementById('refresh_icon');
  const label = document.getElementById('refresh_label');
  if (icon) icon.classList.add('animate-spin');
  if (label) label.textContent = 'Updating…';

  try {
    const res = await fetch('login_approvals.php?action=get_pending_json', { cache: 'no-store' });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('stat_pending_count').textContent = data.count;
      document.getElementById('tab_badge_queue').textContent = data.count;
      renderQueueCards(data.records);
    }
  } catch (e) {
    console.warn('Queue refresh error:', e);
  } finally {
    if (icon) icon.classList.remove('animate-spin');
    if (label) label.textContent = 'Refresh Queue';
  }
}

function renderQueueCards(records) {
  const container = document.getElementById('queue_cards_container');
  if (!records || records.length === 0) {
    container.innerHTML = `
      <div class="bg-white rounded-3xl p-10 text-center border border-stone-200/70 shadow-xs">
        <div class="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 mx-auto mb-3 flex items-center justify-center text-2xl">✓</div>
        <h3 class="font-display text-xl font-bold text-[var(--espresso)]">No Pending Login Requests</h3>
        <p class="text-xs text-stone-500 mt-1 max-w-md mx-auto">
          All staff login attempts have been reviewed. When an employee signs in from their terminal, their GPS location and device will appear here for HR review.
        </p>
      </div>
    `;
    return;
  }

  let html = '';
  records.forEach(req => {
    const empName = `${req.firstname || ''} ${req.lastname || ''}`.trim() || req.username;
    const reqInitials = (req.firstname || req.username || '?').charAt(0).toUpperCase();
    const isInside = req.distance_meters !== null && parseFloat(req.distance_meters) <= GEOFENCE_RADIUS;
    const hasCoords = req.latitude !== null && req.longitude !== null;

    let badgeHtml = '';
    if (isInside) {
      badgeHtml = `<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Inside Premises (${Math.round(req.distance_meters)}m)</span>`;
    } else if (req.distance_meters !== null) {
      badgeHtml = `<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800"><span class="w-2 h-2 rounded-full bg-rose-500"></span>Outside Store (${(parseFloat(req.distance_meters)/1000).toFixed(2)} km)</span>`;
    } else {
      badgeHtml = `<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800"><span class="w-2 h-2 rounded-full bg-amber-500"></span>GPS Denied / IP Only</span>`;
    }

    html += `
      <div class="bg-white rounded-2xl p-5 border border-stone-200 shadow-xs hover:shadow-md transition-shadow" id="req_card_${req.id}">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-2xl bg-[var(--caramel)] text-white font-bold flex items-center justify-center text-base shrink-0 shadow-xs">${reqInitials}</div>
            <div>
              <div class="flex items-center gap-2.5 flex-wrap">
                <h3 class="text-base font-bold text-[var(--espresso)]">${empName}</h3>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-md bg-stone-100 text-stone-700 capitalize">${req.role}</span>
                ${badgeHtml}
              </div>
              <div class="mt-2 text-xs text-stone-600 flex flex-wrap items-center gap-y-1 gap-x-4">
                <span class="flex items-center gap-1.5 font-medium">📍 ${req.location_name || 'Coordinates Logged'}</span>
                ${hasCoords ? `<span class="text-stone-400 font-mono text-[11px]">Lat: ${parseFloat(req.latitude).toFixed(5)}, Lon: ${parseFloat(req.longitude).toFixed(5)} (&plusmn;${Math.round(req.accuracy_meters)}m)</span>` : ''}
              </div>
              <div class="mt-1 text-[11px] text-stone-500 flex flex-wrap items-center gap-x-4">
                <span>💻 ${req.device_info || 'Browser'}</span>
                <span>🌐 IP: ${req.ip_address}</span>
                <span class="text-amber-700 font-medium">🕒 Requested ${req.created_at}</span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-2 self-end lg:self-center shrink-0">
            ${hasCoords ? `<button type="button" onclick="openLocationModal(${req.id}, '${empName.replace(/'/g,"\\'")}', ${req.latitude}, ${req.longitude}, ${req.distance_meters || 0})" class="px-3 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-700 text-xs font-semibold transition-colors cursor-pointer">🗺️ Map</button>` : ''}
            <button type="button" onclick="submitApprove(${req.id}, 0)" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs transition-all cursor-pointer">✓ Approve Once</button>
            <button type="button" onclick="submitApprove(${req.id}, 7)" class="px-3 py-2 rounded-xl bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-xs font-semibold transition-colors cursor-pointer">+ Trust (7D)</button>
            <button type="button" onclick="openRejectModal(${req.id}, '${empName.replace(/'/g,"\\'")}')" class="px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-colors cursor-pointer">✕ Reject</button>
          </div>
        </div>
      </div>
    `;
  });
  container.innerHTML = html;
}

// 1-Click Approve via AJAX
async function submitApprove(authId, trustDays) {
  const card = document.getElementById(`req_card_${authId}`);
  if (card) card.style.opacity = '0.5';

  const formData = new FormData();
  formData.append('action', 'approve');
  formData.append('auth_id', authId);
  formData.append('trust_days', trustDays);
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
      if (card) card.remove();
      refreshQueue();
    } else {
      alert(data.error || 'Failed to approve login.');
      if (card) card.style.opacity = '1';
    }
  } catch (e) {
    console.error(e);
    if (card) card.style.opacity = '1';
  }
}

// Reject Modal
function openRejectModal(authId, empName) {
  document.getElementById('reject_auth_id').value = authId;
  document.getElementById('reject_emp_name').textContent = empName;
  document.getElementById('reject_modal').classList.remove('hidden');
}
function closeRejectModal() {
  document.getElementById('reject_modal').classList.add('hidden');
}

// Location Map Modal
function openLocationModal(authId, empName, lat, lon, distance) {
  document.getElementById('modal_map_title').textContent = `${empName}'s Location`;
  const km = (distance / 1000).toFixed(2);
  document.getElementById('modal_distance_text').textContent = `Distance from ${STORE_NAME}: ${distance >= 1000 ? km + ' km' : Math.round(distance) + ' meters'}`;
  document.getElementById('location_modal').classList.remove('hidden');

  setTimeout(() => {
    if (!modalMapInstance) {
      modalMapInstance = L.map('modalMap');
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(modalMapInstance);
    }

    modalMapInstance.eachLayer(layer => {
      if (layer instanceof L.Marker || layer instanceof L.Circle || layer instanceof L.Polyline) {
        modalMapInstance.removeLayer(layer);
      }
    });

    const storeIcon = L.divIcon({
      className: 'store-pin',
      html: '<div style="background:#241A2E; color:#FBF3E9; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; border:2px solid #E6A25C; box-shadow:0 4px 10px rgba(0,0,0,0.3);">☕</div>',
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });

    const isInside = distance <= GEOFENCE_RADIUS;
    const empIcon = L.divIcon({
      className: 'emp-pin',
      html: `<div style="background:${isInside ? '#10B981' : '#EF4444'}; color:#fff; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; border:2px solid #fff; box-shadow:0 4px 10px rgba(0,0,0,0.3);">👤</div>`,
      iconSize: [34, 34],
      iconAnchor: [17, 17]
    });

    const storeMarker = L.marker([STORE_LAT, STORE_LON], { icon: storeIcon }).addTo(modalMapInstance)
      .bindPopup(`<b>${STORE_NAME}</b><br>Store Location`).openPopup();

    L.circle([STORE_LAT, STORE_LON], {
      color: '#C97B3D',
      fillColor: '#E6A25C',
      fillOpacity: 0.18,
      radius: GEOFENCE_RADIUS
    }).addTo(modalMapInstance);

    const empMarker = L.marker([lat, lon], { icon: empIcon }).addTo(modalMapInstance)
      .bindPopup(`<b>${empName}</b><br>${isInside ? '🟢 Inside Premises' : '🔴 Outside Store'}`);

    L.polyline([[STORE_LAT, STORE_LON], [lat, lon]], {
      color: isInside ? '#10B981' : '#EF4444',
      weight: 3,
      dashArray: '6, 8'
    }).addTo(modalMapInstance);

    const group = L.featureGroup([storeMarker, empMarker]);
    modalMapInstance.fitBounds(group.getBounds().pad(0.3));
  }, 100);
}

function closeLocationModal() {
  document.getElementById('location_modal').classList.add('hidden');
}

// Radar Map Tab
function initRadarMap() {
  if (!radarMapInstance) {
    radarMapInstance = L.map('radarMap').setView([STORE_LAT, STORE_LON], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(radarMapInstance);
  } else {
    radarMapInstance.invalidateSize();
  }

  radarMapInstance.eachLayer(layer => {
    if (layer instanceof L.Marker || layer instanceof L.Circle) {
      radarMapInstance.removeLayer(layer);
    }
  });

  const storeIcon = L.divIcon({
    className: 'store-pin',
    html: '<div style="background:#241A2E; color:#FBF3E9; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; border:2px solid #E6A25C; box-shadow:0 4px 10px rgba(0,0,0,0.3);">☕</div>',
    iconSize: [36, 36],
    iconAnchor: [18, 18]
  });

  L.marker([STORE_LAT, STORE_LON], { icon: storeIcon }).addTo(radarMapInstance)
    .bindPopup(`<b>${STORE_NAME}</b><br>Store Headquarters & Geofence Center`).openPopup();

  L.circle([STORE_LAT, STORE_LON], {
    color: '#C97B3D',
    fillColor: '#E6A25C',
    fillOpacity: 0.15,
    radius: GEOFENCE_RADIUS
  }).addTo(radarMapInstance);
}

// Geolocation Setting Helper: Detect Pin
function detectStorePin() {
  const lbl = document.getElementById('detect_pin_label');
  if (!('geolocation' in navigator)) {
    alert('Browser does not support geolocation.');
    return;
  }
  lbl.textContent = 'Detecting current GPS…';
  navigator.geolocation.getCurrentPosition(
    pos => {
      document.getElementById('setting_lat').value = pos.coords.latitude.toFixed(7);
      document.getElementById('setting_lon').value = pos.coords.longitude.toFixed(7);
      lbl.textContent = '✓ GPS Coordinates Updated!';
      setTimeout(() => { lbl.textContent = 'Use My Current Location as Store Pin'; }, 3000);
    },
    err => {
      alert('Unable to detect location: ' + err.message);
      lbl.textContent = 'Use My Current Location as Store Pin';
    },
    { enableHighAccuracy: true, timeout: 8000 }
  );
}

// Start live polling every 5 seconds on this page
document.addEventListener('DOMContentLoaded', () => {
  pollInterval = setInterval(refreshQueue, 5000);
});
</script>
</body>
</html>
