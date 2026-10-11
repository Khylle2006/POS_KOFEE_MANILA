<?php
require_once __DIR__ . '/../includes/request_security.php';
secure_session_start();
send_security_headers();
start_browser_security_output();
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

require_pending_approval($token);
$pdo = get_db();
$auth = get_login_authorization_by_token($pdo, $token);

if (!$auth) {
    header('Location: login.php?reason=unauthenticated');
    exit;
}

// Handle manual cancel request
if (($_POST['action'] ?? '') === 'cancel') {
    require_method('POST');
    require_csrf();
    $pdo->prepare("UPDATE login_authorizations SET status = 'cancelled' WHERE id = :id AND status = 'pending'")
        ->execute([':id' => $auth['id']]);
    unset($_SESSION['pending_auth_token'], $_SESSION['pending_auth_user_id']);
    header('Location: login.php?reason=logout');
    exit;
}

// If already approved, establish session & forward immediately
if ($auth['status'] === 'approved') {
    if (consume_login_approval($pdo, $token)) {
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
<title>Awaiting HR Approval — BrewVanti POS</title>
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
  :root {
    --espresso: #1E1517;
    --espresso-deep: #0D0709;
    --cream: #FAF7F2;
    --gold: #D9BA85;
    --gold-bright: #ECC98F;
    --mocha: #68584B;
    --latte: #E8DFD5;
  }

  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  }

  /* ─── 1. Truly Live Multi-Stop Flowing Mesh Gradient ─── */
  body {
    background-color: #0D0709;
    background: linear-gradient(
      135deg,
      #090406 0%,
      #1F0E14 20%,
      #351A18 40%,
      #14080D 60%,
      #3B1F17 80%,
      #090406 100%
    );
    background-size: 300% 300%;
    animation: liveMeshFlow 12s ease-in-out infinite alternate;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    position: relative;
    overflow: hidden;
    perspective: 1200px;
    color: var(--cream);
  }

  @keyframes liveMeshFlow {
    0% { background-position: 0% 10%; }
    50% { background-position: 100% 90%; }
    100% { background-position: 0% 10%; }
  }

  /* ─── 2. Living Fluid Aurora Gradient Canvas Blobs ─── */
  .fluid-aurora-stage {
    position: fixed;
    inset: -30%;
    width: 160%;
    height: 160%;
    pointer-events: none;
    z-index: 1;
    overflow: hidden;
    filter: blur(85px);
    opacity: 0.88;
    mix-blend-mode: screen;
  }

  .aurora-blob {
    position: absolute;
    border-radius: 50%;
    will-change: transform;
  }

  /* Amber Gold Aurora Blob */
  .blob-amber {
    width: 550px;
    height: 550px;
    top: 20%;
    left: 18%;
    background: radial-gradient(circle, rgba(235, 175, 96, 0.45) 0%, rgba(209, 131, 65, 0.2) 50%, transparent 75%);
    animation: fluidDrift1 14s ease-in-out infinite alternate;
  }

  /* Deep Ruby Espresso Glow */
  .blob-ruby {
    width: 620px;
    height: 620px;
    top: 35%;
    right: 15%;
    background: radial-gradient(circle, rgba(168, 50, 68, 0.42) 0%, rgba(98, 25, 38, 0.18) 50%, transparent 75%);
    animation: fluidDrift2 16s ease-in-out infinite alternate;
  }

  /* Radiant Warm Oat Gold */
  .blob-gold {
    width: 480px;
    height: 480px;
    bottom: 18%;
    left: 32%;
    background: radial-gradient(circle, rgba(242, 210, 154, 0.4) 0%, rgba(184, 139, 75, 0.18) 50%, transparent 75%);
    animation: fluidDrift3 18s ease-in-out infinite alternate;
  }

  /* Rich Chocolate Mocha */
  .blob-mocha {
    width: 590px;
    height: 590px;
    top: 12%;
    right: 28%;
    background: radial-gradient(circle, rgba(125, 76, 48, 0.4) 0%, rgba(69, 36, 21, 0.16) 50%, transparent 75%);
    animation: fluidDrift4 20s ease-in-out infinite alternate;
  }

  @keyframes fluidDrift1 {
    0% { transform: translate(0, 0) scale(1) rotate(0deg); }
    50% { transform: translate(110px, 90px) scale(1.22) rotate(80deg); }
    100% { transform: translate(-70px, 130px) scale(0.92) rotate(160deg); }
  }

  @keyframes fluidDrift2 {
    0% { transform: translate(0, 0) scale(1) rotate(0deg); }
    50% { transform: translate(-130px, -80px) scale(1.25) rotate(-100deg); }
    100% { transform: translate(90px, -60px) scale(0.96) rotate(-200deg); }
  }

  @keyframes fluidDrift3 {
    0% { transform: translate(0, 0) scale(0.95) rotate(0deg); }
    50% { transform: translate(-80px, 100px) scale(1.18) rotate(120deg); }
    100% { transform: translate(120px, -70px) scale(1.04) rotate(240deg); }
  }

  @keyframes fluidDrift4 {
    0% { transform: translate(0, 0) scale(1.08) rotate(0deg); }
    50% { transform: translate(95px, -100px) scale(0.88) rotate(-70deg); }
    100% { transform: translate(-90px, 70px) scale(1.16) rotate(-160deg); }
  }

  /* Interactive Cursor Ambient Follower */
  .cursor-glow-follower {
    position: fixed;
    width: 600px;
    height: 600px;
    border-radius: 50%;
    pointer-events: none;
    z-index: 1;
    background: radial-gradient(circle, rgba(235, 185, 115, 0.15) 0%, rgba(217, 186, 133, 0.04) 40%, transparent 70%);
    transform: translate(-50%, -50%);
    filter: blur(50px);
    transition: opacity 0.3s ease;
    opacity: 0.8;
  }

  /* ─── 3. Realistic 2.5D Floating Roasted Coffee Beans ─── */
  .floating-bean {
    position: absolute;
    pointer-events: none;
    filter: drop-shadow(0 18px 28px rgba(0, 0, 0, 0.88));
    z-index: 3;
  }

  .bean-1 {
    top: 10%;
    left: 8%;
    width: 56px;
    animation: beanDrift1 12s ease-in-out infinite alternate;
  }

  .bean-2 {
    bottom: 12%;
    left: 9%;
    width: 48px;
    animation: beanDrift2 14s ease-in-out infinite alternate;
  }

  .bean-3 {
    top: 15%;
    right: 9%;
    width: 62px;
    filter: drop-shadow(0 22px 30px rgba(0, 0, 0, 0.92)) blur(0.8px);
    animation: beanDrift3 11s ease-in-out infinite alternate;
  }

  .bean-4 {
    bottom: 14%;
    right: 11%;
    width: 50px;
    animation: beanDrift1 13s ease-in-out infinite alternate -6s;
  }

  .bean-5 {
    top: 50%;
    left: 4%;
    width: 38px;
    opacity: 0.55;
    filter: drop-shadow(0 14px 20px rgba(0, 0, 0, 0.8)) blur(2px);
    animation: beanDrift2 16s ease-in-out infinite alternate -4s;
  }

  .bean-6 {
    top: 52%;
    right: 5%;
    width: 42px;
    opacity: 0.65;
    filter: drop-shadow(0 16px 22px rgba(0, 0, 0, 0.8)) blur(1.5px);
    animation: beanDrift3 10s ease-in-out infinite alternate -8s;
  }

  @keyframes beanDrift1 {
    0% { transform: translateY(0px) rotate(18deg); }
    100% { transform: translateY(-40px) translateX(20px) rotate(38deg); }
  }
  @keyframes beanDrift2 {
    0% { transform: translateY(0px) rotate(-22deg); }
    100% { transform: translateY(-34px) translateX(-18px) rotate(-6deg); }
  }
  @keyframes beanDrift3 {
    0% { transform: translateY(0px) rotate(42deg); }
    100% { transform: translateY(-46px) translateX(-22px) rotate(22deg); }
  }

  /* ─── 4. Glassmorphism 2.5D Transparent Card ─── */
  .waiting-card-container {
    width: 100%;
    max-width: 520px;
    background: rgba(22, 14, 18, 0.64);
    backdrop-filter: blur(32px) saturate(180%);
    -webkit-backdrop-filter: blur(32px) saturate(180%);
    border-radius: 28px;
    padding: 38px 34px 32px;
    border: 1px solid rgba(217, 186, 133, 0.35);
    box-shadow:
      0 35px 85px rgba(0, 0, 0, 0.85),
      0 0 45px rgba(217, 186, 133, 0.12),
      inset 0 1px 0 rgba(255, 255, 255, 0.22),
      inset 0 0 25px rgba(217, 186, 133, 0.06);
    position: relative;
    z-index: 10;
    transform-style: preserve-3d;
    transition: transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.25s ease;
    animation: cardPop 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) both;
  }

  @keyframes cardPop {
    0% { opacity: 0; transform: translateY(28px) scale(0.96); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
  }

  /* Specular Light Sheen Reflection */
  .card-gloss-sheen {
    position: absolute;
    inset: 0;
    border-radius: 28px;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.18) 0%, rgba(217, 186, 133, 0.06) 35%, transparent 65%);
    pointer-events: none;
  }

  /* ─── 5. Top Brand Header ─── */
  .card-top-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 20px;
    border-bottom: 1px solid rgba(217, 186, 133, 0.18);
    margin-bottom: 24px;
    gap: 16px;
  }

  .brand-left {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .brand-logo-frame {
    width: 44px;
    height: 44px;
    background: transparent;
    border: none;
    box-shadow: none;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .brand-logo-frame img {
    width: 40px;
    height: 40px;
    object-fit: contain;
    filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.5));
  }

  .brand-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 19px;
    font-weight: 700;
    color: var(--cream);
    letter-spacing: 0.4px;
    line-height: 1.2;
  }

  .brand-sub {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.8px;
    color: var(--gold);
    opacity: 0.95;
  }

  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    background: rgba(217, 186, 133, 0.16);
    border: 1px solid rgba(217, 186, 133, 0.38);
    color: var(--gold-bright);
    white-space: nowrap;
    box-shadow: 0 0 14px rgba(217, 186, 133, 0.14);
  }

  .pulse-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--gold-bright);
    box-shadow: 0 0 8px var(--gold-bright);
    animation: pingDot 1.6s cubic-bezier(0, 0, 0.2, 1) infinite;
  }

  @keyframes pingDot {
    0% { transform: scale(0.9); opacity: 0.8; }
    50% { transform: scale(1.4); opacity: 1; }
    100% { transform: scale(0.9); opacity: 0.8; }
  }

  /* ─── 6. Center Radar & Status Info ─── */
  .status-center-wrap {
    text-align: center;
    padding: 6px 0 16px;
  }

  .radar-wrapper {
    position: relative;
    width: 90px;
    height: 90px;
    margin: 0 auto 18px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .radar-ring {
    position: absolute;
    inset: -8px;
    border-radius: 50%;
    border: 1.5px solid rgba(217, 186, 133, 0.55);
    animation: radarPulse 2.6s cubic-bezier(0.2, 0.8, 0.4, 1) infinite;
    opacity: 0;
  }
  .radar-ring-2 { animation-delay: 0.85s; }
  .radar-ring-3 { animation-delay: 1.7s; }

  @keyframes radarPulse {
    0% { transform: scale(0.6); opacity: 0.85; }
    50% { opacity: 0.35; }
    100% { transform: scale(1.85); opacity: 0; }
  }

  .radar-shield {
    position: relative;
    z-index: 10;
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(217, 186, 133, 0.22) 0%, rgba(24, 16, 19, 0.95) 80%);
    border: 1.5px solid rgba(217, 186, 133, 0.48);
    box-shadow: 0 0 24px rgba(217, 186, 133, 0.32), inset 0 0 14px rgba(217, 186, 133, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .radar-shield svg {
    color: var(--gold-bright);
    filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4));
  }

  .status-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 26px;
    font-weight: 700;
    color: var(--cream);
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
  }

  .status-desc {
    font-size: 12.5px;
    color: var(--latte);
    opacity: 0.88;
    line-height: 1.55;
    max-width: 390px;
    margin: 0 auto;
  }

  .timer-pill {
    margin-top: 16px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(217, 186, 133, 0.25);
    font-size: 11.5px;
    font-weight: 600;
    color: var(--gold-bright);
    letter-spacing: 0.5px;
  }

  .timer-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--gold);
    box-shadow: 0 0 6px var(--gold);
  }

  /* ─── 7. Glassmorphism Employee & Telemetry Details Card ─── */
  .details-glass-card {
    margin-top: 18px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(217, 186, 133, 0.22);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 18px 20px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.1);
    display: flex;
    flex-direction: column;
    gap: 14px;
    text-align: left;
  }

  /* Employee Info Row */
  .employee-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 12px;
    border-bottom: 1px solid rgba(217, 186, 133, 0.14);
    gap: 12px;
  }

  .employee-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }

  .employee-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold) 0%, var(--mocha) 100%);
    border: 1.5px solid var(--gold-bright);
    box-shadow: 0 0 12px rgba(217, 186, 133, 0.35);
    color: #120C0D;
    font-weight: 800;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .employee-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--cream);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .employee-meta {
    font-size: 11.5px;
    color: var(--latte);
    opacity: 0.75;
    margin-top: 2px;
  }

  .account-badge {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 4px 10px;
    border-radius: 8px;
    background: rgba(217, 186, 133, 0.15);
    border: 1px solid rgba(217, 186, 133, 0.3);
    color: var(--gold-bright);
    white-space: nowrap;
  }

  /* Telemetry Item Rows */
  .telemetry-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
  }

  .telemetry-icon {
    font-size: 16px;
    line-height: 1.3;
    flex-shrink: 0;
  }

  .telemetry-content {
    flex: 1;
    min-width: 0;
  }

  .telemetry-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 3px;
  }

  .telemetry-label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--gold);
    opacity: 0.95;
  }

  .geo-state-pill {
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;
    white-space: nowrap;
  }

  .geo-state-inside {
    background: rgba(16, 185, 129, 0.16);
    border: 1px solid rgba(16, 185, 129, 0.42);
    color: #A7F3D0;
  }

  .geo-state-outside {
    background: rgba(245, 158, 11, 0.16);
    border: 1px solid rgba(245, 158, 11, 0.42);
    color: #FDE68A;
  }

  .geo-state-pending {
    background: rgba(244, 63, 94, 0.16);
    border: 1px solid rgba(244, 63, 94, 0.42);
    color: #FECDD3;
  }

  .telemetry-title {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--cream);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .telemetry-sub {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10.5px;
    color: var(--latte);
    opacity: 0.7;
    margin-top: 2px;
  }

  .telemetry-divider {
    height: 1px;
    background: rgba(217, 186, 133, 0.12);
  }

  /* ─── 8. Balanced Action Footer ─── */
  .actions-footer {
    margin-top: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 14px;
    border-top: 1px solid rgba(217, 186, 133, 0.15);
  }

  .cancel-link {
    font-size: 12px;
    font-weight: 600;
    color: var(--latte);
    opacity: 0.8;
    text-decoration: underline;
    text-underline-offset: 4px;
    transition: all 0.18s;
  }

  .cancel-link:hover {
    color: #FAF7F2;
    opacity: 1;
  }

  .btn-check-now {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: 11px;
    background: rgba(217, 186, 133, 0.16);
    border: 1px solid rgba(217, 186, 133, 0.38);
    color: var(--cream);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  .btn-check-now:hover {
    background: rgba(217, 186, 133, 0.3);
    border-color: var(--gold-bright);
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(217, 186, 133, 0.25);
  }

  .btn-check-now:active {
    transform: translateY(1px);
  }

  @media (max-width: 480px) {
    .waiting-card-container {
      padding: 28px 20px 24px;
      border-radius: 22px;
    }
    .card-top-head {
      flex-direction: column;
      align-items: flex-start;
      gap: 12px;
    }
    .status-title {
      font-size: 22px;
    }
  }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
:where(a, button, input):focus-visible { outline: 3px solid #ECC98F; outline-offset: 3px; }
body { overflow-y: auto; }
.cancel-link { background: transparent; border: 0; cursor: pointer; font: inherit; min-height: 44px; }
</style>
</head>
<body>

  <!-- ─── Living Fluid Mesh Aurora Stage ─── -->
  <div class="fluid-aurora-stage">
    <div class="aurora-blob blob-amber"></div>
    <div class="aurora-blob blob-ruby"></div>
    <div class="aurora-blob blob-gold"></div>
    <div class="aurora-blob blob-mocha"></div>
  </div>

  <!-- Interactive Cursor Follower Spotlight -->
  <div class="cursor-glow-follower" id="mouseGlow"></div>

  <!-- ─── Ultra-Realistic 2.5D Roasted Coffee Beans ─── -->
  <svg aria-hidden="true" class="floating-bean bean-1" viewBox="0 0 60 80" fill="none">
    <defs>
      <radialGradient id="beanGrad1" cx="35%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#5E3825" />
        <stop offset="45%" stop-color="#3A2016" />
        <stop offset="100%" stop-color="#180B06" />
      </radialGradient>
      <linearGradient id="creaseGrad1" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stop-color="#120603" />
        <stop offset="50%" stop-color="#080201" />
        <stop offset="100%" stop-color="#150804" />
      </linearGradient>
    </defs>
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="url(#beanGrad1)" />
    <path d="M30 9 C42 9 48 20 48 35 C42 22 32 15 20 18 C23 12 26 9 30 9 Z" fill="rgba(255,225,185,0.18)" />
    <path d="M30 10 Q38 40 30 70" stroke="url(#creaseGrad1)" stroke-width="4.5" stroke-linecap="round" />
    <path d="M30 11 Q37 40 30 69" stroke="#7A4B2F" stroke-width="1.2" stroke-linecap="round" opacity="0.6" />
  </svg>

  <svg aria-hidden="true" class="floating-bean bean-2" viewBox="0 0 60 80" fill="none">
    <defs>
      <radialGradient id="beanGrad2" cx="35%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#4E2E1D" />
        <stop offset="50%" stop-color="#2D170E" />
        <stop offset="100%" stop-color="#130704" />
      </radialGradient>
      <linearGradient id="creaseGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stop-color="#100502" />
        <stop offset="50%" stop-color="#050101" />
        <stop offset="100%" stop-color="#120603" />
      </linearGradient>
    </defs>
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="url(#beanGrad2)" />
    <path d="M30 9 C42 9 48 20 48 35 C42 22 32 15 20 18 C23 12 26 9 30 9 Z" fill="rgba(255,225,185,0.15)" />
    <path d="M30 10 Q22 40 30 70" stroke="url(#creaseGrad2)" stroke-width="4" stroke-linecap="round" />
    <path d="M30 11 Q23 40 30 69" stroke="#6E4126" stroke-width="1" stroke-linecap="round" opacity="0.5" />
  </svg>

  <svg aria-hidden="true" class="floating-bean bean-3" viewBox="0 0 60 80" fill="none">
    <defs>
      <radialGradient id="beanGrad3" cx="35%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#583522" />
        <stop offset="45%" stop-color="#351C12" />
        <stop offset="100%" stop-color="#150804" />
      </radialGradient>
      <linearGradient id="creaseGrad3" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stop-color="#0E0402" />
        <stop offset="100%" stop-color="#120503" />
      </linearGradient>
    </defs>
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="url(#beanGrad3)" />
    <path d="M30 9 C42 9 48 20 48 35 C42 22 32 15 20 18 C23 12 26 9 30 9 Z" fill="rgba(255,225,185,0.2)" />
    <path d="M30 10 Q37 40 30 70" stroke="url(#creaseGrad3)" stroke-width="4.5" stroke-linecap="round" />
    <path d="M30 11 Q36 40 30 69" stroke="#7A4B2F" stroke-width="1.2" stroke-linecap="round" opacity="0.6" />
  </svg>

  <svg aria-hidden="true" class="floating-bean bean-4" viewBox="0 0 60 80" fill="none">
    <defs>
      <radialGradient id="beanGrad4" cx="35%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#4B2B1B" />
        <stop offset="50%" stop-color="#2A150D" />
        <stop offset="100%" stop-color="#110603" />
      </radialGradient>
    </defs>
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="url(#beanGrad4)" />
    <path d="M30 9 C42 9 48 20 48 35 C42 22 32 15 20 18 C23 12 26 9 30 9 Z" fill="rgba(255,225,185,0.14)" />
    <path d="M30 10 Q23 40 30 70" stroke="#0A0301" stroke-width="4.2" stroke-linecap="round" />
    <path d="M30 11 Q24 40 30 69" stroke="#683D24" stroke-width="1" stroke-linecap="round" opacity="0.5" />
  </svg>

  <svg aria-hidden="true" class="floating-bean bean-5" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#2E170E" />
    <path d="M30 10 Q37 40 30 70" stroke="#0B0402" stroke-width="4" stroke-linecap="round" />
  </svg>

  <svg aria-hidden="true" class="floating-bean bean-6" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#3A1F15" />
    <path d="M30 10 Q23 40 30 70" stroke="#0D0502" stroke-width="3.8" stroke-linecap="round" />
  </svg>

  <!-- ─── Transparent Glassmorphism 2.5D Waiting Screen Card ─── -->
  <div class="waiting-card-container" id="waitingCard">
    <div class="card-gloss-sheen"></div>

    <!-- Top Brand Header -->
    <div class="card-top-head">
      <div class="brand-left">
        <div class="brand-logo-frame">
          <img src="../assets/brewvanti_logo.png" alt="BrewVanti Logo" onerror="this.src='../assets/brewvanti_pos_logo.svg'">
        </div>
        <div>
          <h1 class="brand-title">BrewVanti</h1>
          <p class="brand-sub">Workplace Security Gate</p>
        </div>
      </div>
      <div class="status-pill">
        <span class="pulse-dot"></span>
        Pending HR Review
      </div>
    </div>

    <!-- Active Status Center -->
    <div id="status_container" role="status" aria-live="polite" class="status-center-wrap">

      <!-- Radar Pulse -->
      <div id="radar_wrapper" class="radar-wrapper">
        <div class="radar-ring"></div>
        <div class="radar-ring radar-ring-2"></div>
        <div class="radar-ring radar-ring-3"></div>
        <div class="radar-shield">
          <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            <circle cx="12" cy="11" r="3"/>
          </svg>
        </div>
      </div>

      <h2 id="status_title" class="status-title">
        Awaiting HR Approval
      </h2>
      <p id="status_desc" class="status-desc">
        Your login attempt has been submitted. The HR Manager is reviewing your workplace location and device.
      </p>

      <!-- Live Waiting Timer -->
      <div class="timer-pill">
        <span class="timer-dot"></span>
        <span id="elapsed_counter">Waiting: 00:01</span>
      </div>
    </div>

    <!-- Details Card (Frosted Glass Inset) -->
    <div class="details-glass-card">

      <!-- Employee Info -->
      <div class="employee-row">
        <div class="employee-left">
          <div class="employee-avatar">
            <?= htmlspecialchars($initials) ?>
          </div>
          <div>
            <div class="employee-name"><?= htmlspecialchars($fullName) ?></div>
            <div class="employee-meta">@<?= htmlspecialchars($auth['username']) ?> &bull; <span class="capitalize" style="color:var(--gold-bright); font-weight:600;"><?= htmlspecialchars($auth['role']) ?></span></div>
          </div>
        </div>
        <span class="account-badge">
          Staff Account
        </span>
      </div>

      <!-- Geolocation / Distance Info -->
      <div class="telemetry-row">
        <div class="telemetry-icon">📍</div>
        <div class="telemetry-content">
          <div class="telemetry-header">
            <span class="telemetry-label">Detected Location</span>
            <?php if ($isWithinGeofence): ?>
            <span class="geo-state-pill geo-state-inside">
              Inside Premises (<?= round((float)$auth['distance_meters']) ?>m)
            </span>
            <?php elseif ($auth['distance_meters'] !== null): ?>
            <span class="geo-state-pill geo-state-outside">
              Outside Store (<?= round((float)$auth['distance_meters'] / 1000, 2) ?> km)
            </span>
            <?php else: ?>
            <span class="geo-state-pill geo-state-pending">
              GPS Signal Pending
            </span>
            <?php endif; ?>
          </div>
          <div class="telemetry-title">
            <?= htmlspecialchars($auth['location_name'] ?: 'Current Coordinates Logged') ?>
          </div>
          <?php if ($auth['latitude'] && $auth['longitude']): ?>
          <div class="telemetry-sub">
            Lat: <?= number_format((float)$auth['latitude'], 5) ?>, Lon: <?= number_format((float)$auth['longitude'], 5) ?> (Acc: &plusmn;<?= (int)$auth['accuracy_meters'] ?>m)
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="telemetry-divider"></div>

      <!-- Device & Network -->
      <div class="telemetry-row">
        <div class="telemetry-icon">💻</div>
        <div class="telemetry-content">
          <div class="telemetry-header">
            <span class="telemetry-label">Device & Network</span>
          </div>
          <div class="telemetry-title">
            <?= htmlspecialchars($auth['device_info'] ?: 'Browser Terminal') ?>
          </div>
          <div class="telemetry-sub">
            IP: <?= htmlspecialchars($auth['ip_address']) ?>
          </div>
        </div>
      </div>

    </div>

    <!-- Actions Footer -->
    <div class="actions-footer">
      <form method="POST" action="waiting_approval.php" onsubmit="return confirm('Cancel this login request and return to the sign in page?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="cancel-link">Cancel Request</button>
      </form>

      <button id="refresh_btn" type="button" onclick="checkStatus(true)" class="btn-check-now">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
        </svg>
        Check Now
      </button>
    </div>

  </div>

  <!-- ─── Interactive 2.5D Mouse Parallax Tilt & Cursor Follower ─── -->
  <script>
    const card = document.getElementById('waitingCard');
    const mouseGlow = document.getElementById('mouseGlow');

    document.addEventListener('mousemove', (e) => {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || window.matchMedia('(pointer: coarse)').matches) return;
      // 1. Cursor Glow Follower
      if (mouseGlow) {
        mouseGlow.style.left = e.clientX + 'px';
        mouseGlow.style.top = e.clientY + 'px';
      }

      // 2. 2.5D Parallax Tilt
      if (card) {
        const rect = card.getBoundingClientRect();
        const cardCenterX = rect.left + rect.width / 2;
        const cardCenterY = rect.top + rect.height / 2;
        const deltaX = (e.clientX - cardCenterX) / (window.innerWidth / 2);
        const deltaY = (e.clientY - cardCenterY) / (window.innerHeight / 2);
        const rotX = deltaY * -5.5;
        const rotY = deltaX * 5.5;
        card.style.transform = `perspective(1000px) rotateX(${rotX.toFixed(2)}deg) rotateY(${rotY.toFixed(2)}deg) translateZ(6px)`;
      }
    });

    document.addEventListener('mouseleave', () => {
      if (card) {
        card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateZ(0px)';
      }
    });
  </script>

  <!-- Polling & Live Status Logic -->
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
      refreshBtn.style.opacity = '0.5';
      refreshBtn.style.pointerEvents = 'none';
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
          <div style="position:relative; z-index:10; width:72px; height:72px; border-radius:50%; background:#10B981; color:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 0 30px rgba(16,185,129,0.6); transform:scale(1.1); transition:all 0.3s ease;">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
          </div>
        `;
        statusTitle.textContent = "Login Approved!";
        statusTitle.style.color = "#A7F3D0";
        statusDesc.textContent = "HR has authorized your login session. Opening your workspace…";
        elapsedCounter.parentElement.style.background = "rgba(16, 185, 129, 0.2)";
        elapsedCounter.parentElement.style.borderColor = "rgba(16, 185, 129, 0.4)";
        elapsedCounter.parentElement.style.color = "#A7F3D0";
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
          <div style="position:relative; z-index:10; width:72px; height:72px; border-radius:50%; background:#E11D48; color:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 0 30px rgba(225,29,72,0.6);">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </div>
        `;
        statusTitle.textContent = "Login Attempt Denied";
        statusTitle.style.color = "#FECDD3";
        statusDesc.textContent = `Reason: ${data.reason || 'Location or device not approved by HR.'}`;
        elapsedCounter.parentElement.style.background = "rgba(225, 29, 72, 0.2)";
        elapsedCounter.parentElement.style.borderColor = "rgba(225, 29, 72, 0.4)";
        elapsedCounter.parentElement.style.color = "#FECDD3";
        elapsedCounter.textContent = "Rejected by HR";

        if (refreshBtn) {
          refreshBtn.outerHTML = `<a href="login.php?reason=unauthenticated" style="text-decoration:none; padding:8px 16px; border-radius:10px; background:#E11D48; color:#fff; font-size:12px; font-weight:700;">Return to Sign In</a>`;
        }
        return;
      }

      if (data.status === 'expired') {
        clearInterval(pollTimer);
        clearInterval(elapsedInterval);

        statusTitle.textContent = "Request Timed Out";
        statusDesc.textContent = "HR did not respond in time. Please verify with management and try again.";
        if (refreshBtn) {
          refreshBtn.outerHTML = `<a href="login.php" style="text-decoration:none; padding:8px 16px; border-radius:10px; background:rgba(217,186,133,0.2); border:1px solid rgba(217,186,133,0.4); color:#FAF7F2; font-size:12px; font-weight:700;">Return to Sign In</a>`;
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
        refreshBtn.style.opacity = '1';
        refreshBtn.style.pointerEvents = 'auto';
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
