<?php
// ==============================================================================
// FILE: auth/waiting_approval.php
// Live Waiting Screen for Employees Awaiting HR Geolocation Login Approval
// ==============================================================================

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/login_approval_helpers.php';

secure_session_start();
send_security_headers();

$token = trim($_GET['token'] ?? $_SESSION['pending_auth_token'] ?? '');

// If user is already logged in, redirect to workspace
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    header('Location: login.php?reason=error');
    exit;
}

$pdo = get_db();
$auth = get_login_authorization_by_token($pdo, $token);

if (!$auth) {
    header('Location: login.php?reason=unauthenticated');
    exit;
}

// Handle manual cancel request
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    $pdo->prepare("UPDATE login_authorizations SET status = 'cancelled' WHERE id = :id AND status = 'pending'")
        ->execute([':id' => $auth['id']]);
    unset($_SESSION['pending_auth_token'], $_SESSION['pending_auth_user_id']);
    header('Location: login.php?reason=logout');
    exit;
}

// If already approved, establish session & forward immediately
if ($auth['status'] === 'approved') {
    $userStmt = $pdo->prepare('SELECT id, username, firstname, lastname, email, role, status FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $auth['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if ($user && $user['status'] === 'active') {
        establish_user_session($pdo, $user);
        $pdo->prepare("UPDATE login_authorizations SET session_created = 1 WHERE id = :id")->execute([':id' => $auth['id']]);
        header('Location: index.php');
        exit;
    }
}

$cfg = get_login_approval_settings($pdo);
$fullName = trim(($auth['firstname'] ?? '') . ' ' . ($auth['lastname'] ?? '')) ?: $auth['username'];
$initials = strtoupper(substr($auth['firstname'] ?: $auth['username'], 0, 1));
$isWithinGeofence = ($auth['distance_meters'] !== null && (float)$auth['distance_meters'] <= $cfg['geofence_radius']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Awaiting HR Approval — Kofee Café</title>
<?= csrf_meta() ?>
<link rel="stylesheet" href="../css/index.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --espresso:#241A2E;
    --espresso-deep:#181120;
    --cream:#FBF3E9;
    --caramel:#C97B3D;
    --caramel-light:#E6A25C;
    --latte:#EFE0CC;
  }
  *{font-family:'Inter',sans-serif;}
  .font-display{font-family:'Playfair Display',serif;}

  body{
    background: radial-gradient(circle at 30% 20%, #f5ede0 0%, #eee3d1 60%, #e7dac4 100%);
  }

  .bean-field{
    position:absolute; inset:0;
    background-image: radial-gradient(circle at 20% 30%, rgba(201,123,61,0.10) 0, transparent 3%),
                       radial-gradient(circle at 70% 60%, rgba(201,123,61,0.10) 0, transparent 3%),
                       radial-gradient(circle at 45% 85%, rgba(201,123,61,0.07) 0, transparent 3%);
    pointer-events:none;
  }

  /* Radar Pulse Animation */
  .radar-ring {
    position: absolute;
    inset: -12px;
    border-radius: 9999px;
    border: 2px solid var(--caramel-light);
    animation: radarPulse 2.4s cubic-bezier(0.2, 0.8, 0.4, 1) infinite;
    opacity: 0;
  }
  .radar-ring-2 {
    animation-delay: 0.8s;
  }
  .radar-ring-3 {
    animation-delay: 1.6s;
  }

  @keyframes radarPulse {
    0% { transform: scale(0.6); opacity: 0.8; }
    50% { opacity: 0.35; }
    100% { transform: scale(1.75); opacity: 0; }
  }

  .card-pop {
    animation: pop .6s cubic-bezier(.2,.8,.2,1) both;
  }
  @keyframes pop {
    0% { opacity:0; transform: translateY(18px) scale(.98); }
    100% { opacity:1; transform: translateY(0) scale(1); }
  }

  .steam-dot {
    animation: bounceDot 1.4s infinite ease-in-out both;
  }
  .steam-dot:nth-child(1) { animation-delay: -0.32s; }
  .steam-dot:nth-child(2) { animation-delay: -0.16s; }
  @keyframes bounceDot {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
  }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6 text-[var(--espresso)]">

<div class="relative w-full max-w-lg rounded-[28px] overflow-hidden card-pop shadow-2xl"
     style="background: linear-gradient(155deg, var(--espresso) 0%, var(--espresso-deep) 100%); box-shadow:0 30px 60px -18px rgba(24,17,32,0.55);">

  <div class="bean-field"></div>

  <!-- Content Box -->
  <div class="relative p-6 sm:p-8 text-white">

    <!-- Header / Brand -->
    <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-[linear-gradient(135deg,var(--caramel)_0%,var(--espresso-deep)_120%)] flex items-center justify-center text-xl shadow-md">
          ☕
        </div>
        <div>
          <h1 class="font-display text-lg font-bold tracking-wide text-[var(--cream)]">Kofee Manila</h1>
          <p class="text-[11px] text-[var(--latte)]/80 uppercase tracking-widest font-medium">Workplace Security Gate</p>
        </div>
      </div>
      <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-amber-500/20 text-amber-300 border border-amber-500/30">
        <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
        Pending HR Review
      </span>
    </div>

    <!-- Active Status Center -->
    <div id="status_container" class="text-center py-4">

      <!-- Radar Icon Container -->
      <div id="radar_wrapper" class="relative w-24 h-24 mx-auto mb-5 flex items-center justify-center">
        <div class="radar-ring"></div>
        <div class="radar-ring radar-ring-2"></div>
        <div class="radar-ring radar-ring-3"></div>
        <div class="relative z-10 w-20 h-20 rounded-full bg-[linear-gradient(145deg,rgba(201,123,61,0.25),rgba(24,17,32,0.9))] border-2 border-[var(--caramel-light)]/50 flex items-center justify-center shadow-inner">
          <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="var(--caramel-light)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            <circle cx="12" cy="11" r="3"/>
          </svg>
        </div>
      </div>

      <h2 id="status_title" class="font-display text-2xl font-bold text-[var(--cream)] mb-2">
        Awaiting HR Approval
      </h2>
      <p id="status_desc" class="text-xs text-[var(--latte)]/85 max-w-sm mx-auto leading-relaxed">
        Your login attempt has been submitted. The HR Manager is reviewing your workplace location and device.
      </p>

      <!-- Live Waiting Timer -->
      <div class="mt-4 inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/5 border border-white/10 text-[11px] text-stone-300">
        <span class="w-1.5 h-1.5 rounded-full bg-[var(--caramel-light)] animate-pulse"></span>
        <span id="elapsed_counter">Waiting: 00:01</span>
      </div>
    </div>

    <!-- Details Card (White inset) -->
    <div class="mt-4 rounded-2xl bg-[var(--cream)] text-[var(--espresso)] p-4 sm:p-5 shadow-lg space-y-3.5 text-xs">

      <!-- Employee Info -->
      <div class="flex items-center justify-between pb-3 border-b border-[var(--latte)]">
        <div class="flex items-center gap-2.5">
          <div class="w-8 h-8 rounded-full bg-[var(--caramel)] text-white font-bold flex items-center justify-center text-xs shadow-xs">
            <?= htmlspecialchars($initials) ?>
          </div>
          <div>
            <div class="font-bold text-[13px] text-[var(--espresso)]"><?= htmlspecialchars($fullName) ?></div>
            <div class="text-[11px] text-stone-500">@<?= htmlspecialchars($auth['username']) ?> &bull; <span class="capitalize font-semibold"><?= htmlspecialchars($auth['role']) ?></span></div>
          </div>
        </div>
        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-md bg-stone-200 text-stone-700">
          Staff Account
        </span>
      </div>

      <!-- Geolocation / Distance Info -->
      <div class="flex items-start gap-2.5">
        <div class="mt-0.5 text-base">📍</div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between gap-2">
            <span class="font-semibold text-[11px] text-stone-600 uppercase tracking-wider">Detected Location</span>
            <?php if ($isWithinGeofence): ?>
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">
              Inside Premises (<?= round((float)$auth['distance_meters']) ?>m)
            </span>
            <?php elseif ($auth['distance_meters'] !== null): ?>
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">
              Outside Store (<?= round((float)$auth['distance_meters'] / 1000, 2) ?> km)
            </span>
            <?php else: ?>
            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 text-rose-800">
              GPS Signal Pending
            </span>
            <?php endif; ?>
          </div>
          <p class="text-[12px] font-medium text-stone-800 mt-0.5 truncate">
            <?= htmlspecialchars($auth['location_name'] ?: 'Current Coordinates Logged') ?>
          </p>
          <?php if ($auth['latitude'] && $auth['longitude']): ?>
          <p class="text-[10px] text-stone-500 font-mono mt-0.5">
            Lat: <?= number_format((float)$auth['latitude'], 5) ?>, Lon: <?= number_format((float)$auth['longitude'], 5) ?> (Acc: &plusmn;<?= (int)$auth['accuracy_meters'] ?>m)
          </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Device & Network -->
      <div class="flex items-start gap-2.5 pt-2 border-t border-[var(--latte)]/60">
        <div class="mt-0.5 text-base">💻</div>
        <div class="flex-1 min-w-0">
          <div class="font-semibold text-[11px] text-stone-600 uppercase tracking-wider">Device & Network</div>
          <p class="text-[12px] font-medium text-stone-800 truncate">
            <?= htmlspecialchars($auth['device_info'] ?: 'Browser Terminal') ?>
          </p>
          <p class="text-[10px] text-stone-500 font-mono">
            IP: <?= htmlspecialchars($auth['ip_address']) ?>
          </p>
        </div>
      </div>

    </div>

    <!-- Actions Footer -->
    <div class="mt-6 flex items-center justify-between pt-2">
      <a href="waiting_approval.php?token=<?= urlencode($token) ?>&action=cancel"
         onclick="return confirm('Cancel this login request and return to the sign in page?');"
         class="text-xs text-[var(--latte)] hover:text-white underline underline-offset-4 transition-colors">
        Cancel Request
      </a>

      <button id="refresh_btn" type="button" onclick="checkStatus(true)"
              class="inline-flex items-center gap-1.5 text-xs px-3.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white font-medium transition-colors cursor-pointer">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
        </svg>
        Check Now
      </button>
    </div>

  </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
let elapsedSeconds = 0;
let pollTimer = null;
let elapsedInterval = null;

const statusContainer = document.getElementById('status_container');
const statusTitle = document.getElementById('status_title');
const statusDesc = document.getElementById('status_desc');
const elapsedCounter = document.getElementById('elapsed_counter');
const radarWrapper = document.getElementById('radar_wrapper');
const refreshBtn = document.getElementById('refresh_btn');

function formatTime(sec) {
  const m = Math.floor(sec / 60).toString().padStart(2, '0');
  const s = (sec % 60).toString().padStart(2, '0');
  return `${m}:${s}`;
}

function startTimer() {
  elapsedInterval = setInterval(() => {
    elapsedSeconds++;
    if (elapsedCounter) {
      elapsedCounter.textContent = `Waiting: ${formatTime(elapsedSeconds)}`;
    }
  }, 1000);
}

async function checkStatus(isManual = false) {
  if (isManual && refreshBtn) {
    refreshBtn.classList.add('opacity-60', 'pointer-events-none');
  }

  try {
    const res = await fetch(`check_login_status.php?token=${encodeURIComponent(TOKEN)}`, {
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    });
    const data = await res.json();

    if (data.status === 'approved') {
      clearInterval(pollTimer);
      clearInterval(elapsedInterval);

      // Render Approved Celebration
      radarWrapper.innerHTML = `
        <div class="relative z-10 w-20 h-20 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg transform transition-transform scale-110">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
      `;
      statusTitle.textContent = "Login Approved!";
      statusTitle.className = "font-display text-2xl font-bold text-emerald-300 mb-2";
      statusDesc.textContent = "HR has authorized your login session. Opening your workspace…";
      elapsedCounter.parentElement.className = "mt-4 inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-emerald-500/20 text-emerald-200 border border-emerald-500/30 text-[11px] font-semibold";
      elapsedCounter.textContent = "✓ Authorized • Redirecting…";

      setTimeout(() => {
        window.location.href = data.redirect || 'index.php';
      }, 1200);
      return;
    }

    if (data.status === 'rejected') {
      clearInterval(pollTimer);
      clearInterval(elapsedInterval);

      radarWrapper.innerHTML = `
        <div class="relative z-10 w-20 h-20 rounded-full bg-rose-600 text-white flex items-center justify-center shadow-lg">
          <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </div>
      `;
      statusTitle.textContent = "Login Attempt Denied";
      statusTitle.className = "font-display text-2xl font-bold text-rose-300 mb-2";
      statusDesc.innerHTML = `<span class="text-rose-200 font-semibold">Reason:</span> ${data.reason || 'Location or device not approved by HR.'}`;
      elapsedCounter.parentElement.className = "mt-4 inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-rose-500/20 text-rose-200 border border-rose-500/30 text-[11px]";
      elapsedCounter.textContent = "Rejected by HR";

      if (refreshBtn) {
        refreshBtn.outerHTML = `<a href="login.php?reason=unauthenticated" class="text-xs px-3.5 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white font-semibold transition-colors">Return to Sign In</a>`;
      }
      return;
    }

    if (data.status === 'expired') {
      clearInterval(pollTimer);
      clearInterval(elapsedInterval);

      statusTitle.textContent = "Request Timed Out";
      statusDesc.textContent = "HR did not respond in time. Please verify with management and try again.";
      if (refreshBtn) {
        refreshBtn.outerHTML = `<a href="login.php" class="text-xs px-3.5 py-1.5 rounded-lg bg-stone-700 hover:bg-stone-600 text-white font-semibold transition-colors">Return to Sign In</a>`;
      }
      return;
    }

    // Pending - update elapsed if returned from server
    if (data.elapsed_sec !== undefined) {
      elapsedSeconds = Math.max(elapsedSeconds, data.elapsed_sec);
    }
  } catch (err) {
    console.warn('Status poll error:', err);
  } finally {
    if (isManual && refreshBtn) {
      refreshBtn.classList.remove('opacity-60', 'pointer-events-none');
    }
  }
}

// Start polling every 2.5 seconds
document.addEventListener('DOMContentLoaded', () => {
  startTimer();
  pollTimer = setInterval(checkStatus, 2500);
});
</script>
</body>
</html>
