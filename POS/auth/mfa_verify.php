<?php
// ==============================================================================
// FILE: auth/mfa_verify.php
// Multi-Factor Authentication (MFA) Verification Screen
// BrewVanti 2.5D Living Fluid Mesh Aurora & Glassmorphism Theme
// ==============================================================================

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/login_approval_helpers.php';
require_once '../includes/mfa_helpers.php';

secure_session_start();
send_security_headers();

// If already authenticated, redirect to workspace
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Resolve token from query, POST, or session
$token = trim($_GET['token'] ?? $_POST['token'] ?? ($_SESSION['pending_mfa']['mfa_token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $_SESSION['login_error'] = 'Invalid or expired verification session. Please sign in again.';
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$challenge = get_mfa_challenge($pdo, $token);

if (!$challenge || !empty($challenge['verified_at'])) {
    $_SESSION['login_error'] = 'Verification session has expired or is no longer valid. Please sign in.';
    header('Location: login.php');
    exit;
}

// Check account lockout throttle
$wait = login_lockout_seconds($challenge['username']);
if ($wait > 0) {
    $minutes = (int)ceil($wait / 60);
    $_SESSION['login_error'] = "Too many failed attempts. Try again in {$minutes} minute(s).";
    header('Location: login.php');
    exit;
}

// Handle Cancel action
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset($_SESSION['pending_mfa']);
    header('Location: login.php?reason=logout');
    exit;
}

$error   = '';
$success = '';

// Handle POST submissions (Verification or Resend)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

    if (!csrf_verify()) {
        if ($isAjax) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Security token expired. Please refresh the page.']);
            exit;
        }
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? 'verify';

        // ── Action: Resend Code ──
        if ($action === 'resend') {
            $resendRes = resend_mfa_challenge($pdo, $token);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($resendRes);
                exit;
            }
            if ($resendRes['ok']) {
                $success = $resendRes['message'];
            } else {
                $error = $resendRes['error'];
            }
        }
        // ── Action: Verify Code ──
        else {
            // Concatenate 6 individual digits if submitted separately
            $inputCode = trim($_POST['mfa_code'] ?? '');
            if ($inputCode === '' && isset($_POST['digit_0'])) {
                $inputCode = '';
                for ($i = 0; $i < 6; $i++) {
                    $inputCode .= trim($_POST["digit_{$i}"] ?? '');
                }
            }

            $verifyRes = verify_mfa_challenge($pdo, $token, $inputCode);

            if (!$verifyRes['ok']) {
                if (!empty($verifyRes['locked'])) {
                    // Record failed attempt against throttle on lockout
                    record_login_attempt($challenge['username'], false);
                    unset($_SESSION['pending_mfa']);
                    $_SESSION['login_error'] = 'Too many failed verification attempts. Please sign in again.';

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'redirect' => 'login.php', 'error' => $_SESSION['login_error']]);
                        exit;
                    }
                    header('Location: login.php');
                    exit;
                }

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'error' => $verifyRes['error']]);
                    exit;
                }
                $error = $verifyRes['error'];
            } else {
                // ── MFA Verification Succeeded! ──
                // Reset throttle upon full authentication success
                record_login_attempt($challenge['username'], true);

                // Fetch fresh user record
                $uStmt = $pdo->prepare('SELECT id, username, firstname, lastname, email, password, role, status, avatar_path FROM users WHERE id = :id LIMIT 1');
                $uStmt->execute([':id' => $challenge['user_id']]);
                $user = $uStmt->fetch(PDO::FETCH_ASSOC);

                // Multi-role resolution
                $user_roles = [];
                try {
                    $role_stmt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :id ORDER BY role');
                    $role_stmt->execute([':id' => $user['id']]);
                    $user_roles = $role_stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (PDOException $e) {
                    error_log('role load failed: ' . $e->getMessage());
                }
                $user['roles'] = $user_roles ?: [$user['role']];

                // Device Trust & Geolocation HR Authorization
                $ip = client_ip();
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $deviceHash = hash('sha256', $user['id'] . '|' . $userAgent . '|' . substr($ip, 0, strrpos($ip, '.')));

                $requiresApproval = does_user_require_login_approval($pdo, $user);
                $deviceTrusted    = is_device_trusted($pdo, (int)$user['id'], $deviceHash);

                $pendingData = $_SESSION['pending_mfa'] ?? [];
                unset($_SESSION['pending_mfa']);

                if ($requiresApproval && !$deviceTrusted) {
                    $lat = $pendingData['latitude'] ?? null;
                    $lon = $pendingData['longitude'] ?? null;
                    $acc = $pendingData['accuracy'] ?? null;
                    $locStatus = $pendingData['location_status'] ?? 'unknown';
                    $deviceInfo = $pendingData['device_info'] ?? '';

                    $authRes = create_login_authorization($pdo, $user, $lat, $lon, $acc, $locStatus, $deviceInfo);

                    if ($authRes['status'] === 'approved') {
                        // Auto-approved inside geofence
                        establish_user_session($pdo, $user);
                        if ($isAjax) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                            exit;
                        }
                        header('Location: index.php');
                        exit;
                    }

                    // Awaiting HR review
                    $_SESSION['pending_auth_token'] = $authRes['auth_token'];
                    $_SESSION['pending_auth_user_id'] = (int)$user['id'];
                    $redir = 'waiting_approval.php?token=' . urlencode($authRes['auth_token']);

                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => true, 'redirect' => $redir]);
                        exit;
                    }
                    header('Location: ' . $redir);
                    exit;
                }

                // Immediate authentication (Admin, HR, or trusted device)
                establish_user_session($pdo, $user);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                    exit;
                }
                header('Location: index.php');
                exit;
            }
        }
    }
}

// Calculate remaining expiry in seconds
$remainingSec = max(0, strtotime($challenge['expires_at']) - time());

// Calculate cooldown for resend button (60s cooldown from last_sent_at)
$lastSentElapsed = time() - strtotime($challenge['last_sent_at']);
$cooldownSec = max(0, 60 - $lastSentElapsed);

$maskedEmail = mask_email_address($challenge['email']);
$fullName = trim(($challenge['firstname'] ?? '') . ' ' . ($challenge['lastname'] ?? '')) ?: $challenge['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Factor Verification — BrewVanti POS</title>
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

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

  /* ─── 2. Living Fluid Aurora Gradient Blobs ─── */
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

  .blob-amber {
    width: 550px;
    height: 550px;
    top: 20%;
    left: 18%;
    background: radial-gradient(circle, rgba(235, 175, 96, 0.45) 0%, rgba(209, 131, 65, 0.2) 50%, transparent 75%);
    animation: fluidDrift1 14s ease-in-out infinite alternate;
  }

  .blob-ruby {
    width: 620px;
    height: 620px;
    top: 35%;
    right: 15%;
    background: radial-gradient(circle, rgba(168, 50, 68, 0.42) 0%, rgba(98, 25, 38, 0.18) 50%, transparent 75%);
    animation: fluidDrift2 16s ease-in-out infinite alternate;
  }

  .blob-gold {
    width: 480px;
    height: 480px;
    bottom: 18%;
    left: 32%;
    background: radial-gradient(circle, rgba(242, 210, 154, 0.4) 0%, rgba(184, 139, 75, 0.18) 50%, transparent 75%);
    animation: fluidDrift3 18s ease-in-out infinite alternate;
  }

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
    100% { transform: translate(90px, 70px) scale(1.15) rotate(40deg); }
  }
  @keyframes fluidDrift2 {
    0% { transform: translate(0, 0) scale(1) rotate(0deg); }
    100% { transform: translate(-100px, 80px) scale(1.18) rotate(-35deg); }
  }
  @keyframes fluidDrift3 {
    0% { transform: translate(0, 0) scale(1); }
    100% { transform: translate(-70px, -90px) scale(1.22); }
  }
  @keyframes fluidDrift4 {
    0% { transform: translate(0, 0) scale(1) rotate(0deg); }
    100% { transform: translate(80px, -60px) scale(1.12) rotate(25deg); }
  }

  /* ─── 3. Interactive Cursor Glow ─── */
  .cursor-glow-follower {
    position: fixed;
    width: 440px;
    height: 440px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(236, 201, 143, 0.18) 0%, rgba(217, 186, 133, 0.05) 45%, transparent 70%);
    pointer-events: none;
    transform: translate(-50%, -50%);
    z-index: 2;
    transition: transform 0.08s ease-out;
    filter: blur(15px);
  }

  /* ─── 4. Floating Roasted Coffee Beans ─── */
  .floating-bean {
    position: fixed;
    pointer-events: none;
    z-index: 2;
    filter: drop-shadow(0 14px 22px rgba(0, 0, 0, 0.75));
    will-change: transform;
    opacity: 0.85;
  }
  .bean-1 { width: 52px; height: 70px; top: 12%; left: 12%; animation: floatBean1 22s ease-in-out infinite alternate; }
  .bean-2 { width: 44px; height: 58px; top: 22%; right: 14%; animation: floatBean2 26s ease-in-out infinite alternate; }
  .bean-3 { width: 62px; height: 82px; bottom: 16%; left: 16%; animation: floatBean3 24s ease-in-out infinite alternate; }
  .bean-4 { width: 48px; height: 64px; bottom: 20%; right: 16%; animation: floatBean4 28s ease-in-out infinite alternate; }

  @keyframes floatBean1 {
    0% { transform: translate(0, 0) rotate(15deg); }
    100% { transform: translate(45px, 60px) rotate(45deg); }
  }
  @keyframes floatBean2 {
    0% { transform: translate(0, 0) rotate(-25deg); }
    100% { transform: translate(-50px, 45px) rotate(10deg); }
  }
  @keyframes floatBean3 {
    0% { transform: translate(0, 0) rotate(35deg); }
    100% { transform: translate(55px, -50px) rotate(-15deg); }
  }
  @keyframes floatBean4 {
    0% { transform: translate(0, 0) rotate(-40deg); }
    100% { transform: translate(-40px, -65px) rotate(20deg); }
  }

  /* ─── 5. Glassmorphism Card ─── */
  .mfa-card-container {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 480px;
    background: rgba(30, 21, 23, 0.72);
    backdrop-filter: blur(28px) saturate(190%);
    -webkit-backdrop-filter: blur(28px) saturate(190%);
    border: 1px solid rgba(217, 186, 133, 0.28);
    border-radius: 26px;
    padding: 42px 36px 36px;
    box-shadow:
      0 28px 60px -15px rgba(0, 0, 0, 0.85),
      0 0 0 1px rgba(255, 255, 255, 0.06) inset,
      0 1px 0 0 rgba(255, 255, 255, 0.15) inset;
    text-align: center;
    transition: transform 0.15s ease-out, box-shadow 0.25s ease;
    overflow: hidden;
  }

  .card-gloss-sheen {
    position: absolute;
    top: 0;
    left: -100%;
    width: 200%;
    height: 100%;
    background: linear-gradient(
      115deg,
      transparent 30%,
      rgba(255, 255, 255, 0.04) 45%,
      rgba(236, 201, 143, 0.09) 50%,
      transparent 60%
    );
    pointer-events: none;
    animation: glossSheen 9s ease-in-out infinite;
  }

  @keyframes glossSheen {
    0%, 70% { transform: translateX(0); }
    100% { transform: translateX(100%); }
  }

  /* ─── 6. Brand Header ─── */
  .brand-logo-frame {
    width: 68px;
    height: 68px;
    margin: 0 auto 16px;
    border-radius: 20px;
    background: radial-gradient(circle at 35% 35%, rgba(236, 201, 143, 0.22) 0%, rgba(26, 15, 18, 0.8) 100%);
    border: 1px solid rgba(217, 186, 133, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4), 0 0 20px rgba(217, 186, 133, 0.15);
  }

  .shield-icon {
    font-size: 32px;
    color: var(--gold-bright);
    filter: drop-shadow(0 2px 6px rgba(217, 186, 133, 0.4));
  }

  .card-title {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 27px;
    font-weight: 700;
    letter-spacing: 0.3px;
    color: var(--cream);
    margin-bottom: 6px;
  }

  .card-subtitle {
    font-size: 13px;
    color: var(--latte);
    line-height: 1.5;
    margin-bottom: 24px;
  }

  .email-highlight {
    color: var(--gold-bright);
    font-weight: 700;
    word-break: break-all;
    background: rgba(217, 186, 133, 0.12);
    padding: 2px 8px;
    border-radius: 6px;
    display: inline-block;
    margin-top: 4px;
    border: 1px solid rgba(217, 186, 133, 0.25);
  }

  /* ─── 7. Alert Banners ─── */
  .alert-banner {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 12.5px;
    margin-bottom: 20px;
    line-height: 1.45;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }
  .alert-error {
    background: rgba(239, 68, 68, 0.16);
    border: 1px solid rgba(248, 113, 113, 0.45);
    color: #FECDD3;
    font-weight: 600;
  }
  .alert-success {
    background: rgba(16, 185, 129, 0.16);
    border: 1px solid rgba(52, 211, 153, 0.45);
    color: #A7F3D0;
    font-weight: 600;
  }

  /* ─── 8. 6-Digit OTP Box Grid ─── */
  .otp-grid {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin: 24px 0 16px;
  }

  .otp-input {
    width: 48px;
    height: 58px;
    background: rgba(255, 255, 255, 0.06);
    border: 1.5px solid rgba(217, 186, 133, 0.28);
    border-radius: 13px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 26px;
    font-weight: 700;
    text-align: center;
    color: #FAF7F2;
    outline: none;
    transition: all 0.2s cubic-bezier(0.2, 0.8, 0.2, 1);
    box-sizing: border-box;
    caret-color: var(--gold-bright);
  }

  .otp-input:focus {
    background: rgba(255, 255, 255, 0.1);
    border-color: var(--gold-bright);
    box-shadow: 0 0 0 3px rgba(217, 186, 133, 0.28), 0 0 16px rgba(217, 186, 133, 0.22);
    transform: translateY(-2px);
  }

  .otp-input.filled {
    border-color: rgba(217, 186, 133, 0.6);
    background: rgba(217, 186, 133, 0.08);
  }

  /* ─── 9. Timer & Expiry Indicator ─── */
  .timer-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: var(--latte);
    margin: 14px 4px 24px;
    padding: 0 4px;
  }

  .timer-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
  }

  .timer-clock {
    font-family: 'JetBrains Mono', monospace;
    color: var(--gold-bright);
    font-weight: 700;
    letter-spacing: 0.5px;
  }

  .timer-clock.expired {
    color: #F87171;
  }

  /* ─── 10. Submit Button ─── */
  .btn-submit {
    width: 100%;
    height: 50px;
    background: linear-gradient(135deg, #ECC98F 0%, #D9BA85 50%, #BD9659 100%);
    border: none;
    border-radius: 13px;
    color: #120C0D;
    font-size: 13.5px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    cursor: pointer;
    box-shadow: 0 10px 24px -4px rgba(217, 186, 133, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.45);
    transition: all 0.22s cubic-bezier(0.2, 0.8, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }

  .btn-submit:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 14px 30px -4px rgba(217, 186, 133, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.6);
    filter: brightness(1.04);
  }

  .btn-submit:active:not(:disabled) {
    transform: translateY(1px) scale(0.99);
  }

  .btn-submit:disabled {
    opacity: 0.65;
    cursor: not-allowed;
    filter: grayscale(0.3);
  }

  /* ─── 11. Resend and Footer Controls ─── */
  .mfa-actions {
    margin-top: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
  }

  .resend-btn {
    background: none;
    border: none;
    color: var(--latte);
    font-size: 12.5px;
    cursor: pointer;
    transition: color 0.18s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
  }

  .resend-btn strong {
    color: var(--gold);
    border-bottom: 1px dotted rgba(217, 186, 133, 0.5);
    transition: all 0.18s;
  }

  .resend-btn:hover:not(:disabled) strong {
    color: var(--gold-bright);
    border-bottom-color: var(--gold-bright);
  }

  .resend-btn:disabled {
    cursor: not-allowed;
    opacity: 0.5;
  }

  .cancel-link {
    font-size: 12px;
    color: rgba(232, 223, 213, 0.7);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color 0.18s;
    padding: 4px 10px;
    border-radius: 8px;
  }

  .cancel-link:hover {
    color: #FAF7F2;
    background: rgba(255, 255, 255, 0.05);
  }

  /* Spinner */
  .spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(18, 12, 13, 0.3);
    border-top-color: #120C0D;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
    display: none;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  /* Responsive Adjustments */
  @media (max-width: 480px) {
    .mfa-card-container {
      padding: 32px 20px 28px;
      border-radius: 22px;
    }
    .card-title {
      font-size: 23px;
    }
    .otp-grid {
      gap: 6px;
    }
    .otp-input {
      width: 42px;
      height: 52px;
      font-size: 22px;
      border-radius: 10px;
    }
  }
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

  <!-- Interactive Cursor Follower -->
  <div class="cursor-glow-follower" id="mouseGlow"></div>

  <!-- ─── Ultra-Realistic Roasted Coffee Beans ─── -->
  <svg class="floating-bean bean-1" viewBox="0 0 60 80" fill="none">
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

  <svg class="floating-bean bean-2" viewBox="0 0 60 80" fill="none">
    <defs>
      <radialGradient id="beanGrad2" cx="35%" cy="35%" r="70%">
        <stop offset="0%" stop-color="#4E2E1D" />
        <stop offset="50%" stop-color="#2D170E" />
        <stop offset="100%" stop-color="#130704" />
      </radialGradient>
    </defs>
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="url(#beanGrad2)" />
    <path d="M30 10 Q22 40 30 70" stroke="#050101" stroke-width="4" stroke-linecap="round" />
  </svg>

  <svg class="floating-bean bean-3" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#351C12" />
    <path d="M30 10 Q37 40 30 70" stroke="#120503" stroke-width="4.5" stroke-linecap="round" />
  </svg>

  <svg class="floating-bean bean-4" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#2A150D" />
    <path d="M30 10 Q23 40 30 70" stroke="#0A0301" stroke-width="4.2" stroke-linecap="round" />
  </svg>

  <!-- ─── 2.5D Glassmorphic MFA Verification Card ─── -->
  <div class="mfa-card-container" id="mfaCard">
    <div class="card-gloss-sheen"></div>

    <!-- Security Brand Header -->
    <div class="brand-logo-frame">
      <span class="shield-icon">🛡️</span>
    </div>
    <h1 class="card-title">Verify Identity</h1>
    <p class="card-subtitle">
      A 6-digit verification code was dispatched to:<br>
      <span class="email-highlight" id="maskedEmailBadge"><?= htmlspecialchars($maskedEmail) ?></span>
    </p>

    <!-- Alert Messages -->
    <div id="alertBox" style="<?= ($error || $success) ? '' : 'display:none;' ?>">
      <?php if ($error): ?>
        <div class="alert-banner alert-error" id="serverAlert">
          <span>⚠️</span> <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php elseif ($success): ?>
        <div class="alert-banner alert-success" id="serverAlert">
          <span>✓</span> <span><?= htmlspecialchars($success) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- OTP Form -->
    <form id="otpForm" method="POST" action="mfa_verify.php">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="mfa_code" id="hiddenOtpCode" value="">

      <!-- 6-Digit OTP Inputs -->
      <div class="otp-grid" id="otpGrid">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="0" autofocus autocomplete="one-time-code">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="1">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="2">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="3">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="4">
        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" data-index="5">
      </div>

      <!-- Expiry Countdown -->
      <div class="timer-row">
        <span class="timer-pill">
          ⏱️ Code expires in: <span class="timer-clock" id="expiryTimer">--:--</span>
        </span>
        <span style="font-size:11px;color:rgba(232,223,213,0.5);">BrewVanti MFA</span>
      </div>

      <!-- Submit Verification Button -->
      <button type="submit" id="verifyBtn" class="btn-submit">
        <span class="spinner" id="btnSpinner"></span>
        <span id="btnText">Verify &amp; Sign In</span>
      </button>

      <!-- Resend & Navigation Actions -->
      <div class="mfa-actions">
        <button type="button" class="resend-btn" id="resendBtn" <?= $cooldownSec > 0 ? 'disabled' : '' ?>>
          Didn't receive the email? <strong id="resendLabel"><?= $cooldownSec > 0 ? "Resend in {$cooldownSec}s" : 'Resend Code' ?></strong>
        </button>

        <a href="mfa_verify.php?token=<?= urlencode($token) ?>&action=cancel" class="cancel-link">
          &larr; Sign in with a different account
        </a>
      </div>
    </form>
  </div>

  <script>
    // ─── 1. Mouse Parallax Tilt & Glow ───
    const card = document.getElementById('mfaCard');
    const mouseGlow = document.getElementById('mouseGlow');

    document.addEventListener('mousemove', (e) => {
      if (mouseGlow) {
        mouseGlow.style.left = e.clientX + 'px';
        mouseGlow.style.top = e.clientY + 'px';
      }
      if (card) {
        const rect = card.getBoundingClientRect();
        const cardCenterX = rect.left + rect.width / 2;
        const cardCenterY = rect.top + rect.height / 2;
        const deltaX = (e.clientX - cardCenterX) / (window.innerWidth / 2);
        const deltaY = (e.clientY - cardCenterY) / (window.innerHeight / 2);
        const rotX = deltaY * -5;
        const rotY = deltaX * 5;
        card.style.transform = `perspective(1000px) rotateX(${rotX.toFixed(2)}deg) rotateY(${rotY.toFixed(2)}deg) translateZ(6px)`;
      }
    });

    document.addEventListener('mouseleave', () => {
      if (card) {
        card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateZ(0px)';
      }
    });

    // ─── 2. OTP Input Navigation & Paste Handling ───
    const inputs = Array.from(document.querySelectorAll('.otp-input'));
    const hiddenOtp = document.getElementById('hiddenOtpCode');
    const form = document.getElementById('otpForm');
    const verifyBtn = document.getElementById('verifyBtn');
    const btnSpinner = document.getElementById('btnSpinner');
    const btnText = document.getElementById('btnText');
    const alertBox = document.getElementById('alertBox');

    function syncHiddenCode() {
      const code = inputs.map(i => i.value).join('');
      hiddenOtp.value = code;
      return code;
    }

    inputs.forEach((input, idx) => {
      // Focus styling
      input.addEventListener('focus', () => {
        input.select();
      });

      // Character typing
      input.addEventListener('input', (e) => {
        const val = input.value.replace(/\D/g, '');
        input.value = val ? val[0] : '';

        if (input.value) {
          input.classList.add('filled');
          if (idx < inputs.length - 1) {
            inputs[idx + 1].focus();
          }
        } else {
          input.classList.remove('filled');
        }

        const fullCode = syncHiddenCode();
        if (fullCode.length === 6) {
          // Auto submit when all 6 digits entered
          handleSubmit();
        }
      });

      // Backspace & navigation keys
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace') {
          if (!input.value && idx > 0) {
            inputs[idx - 1].focus();
            inputs[idx - 1].value = '';
            inputs[idx - 1].classList.remove('filled');
            e.preventDefault();
          } else {
            input.value = '';
            input.classList.remove('filled');
          }
          syncHiddenCode();
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          inputs[idx - 1].focus();
        } else if (e.key === 'ArrowRight' && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }
      });

      // Paste full code
      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text');
        const digits = pasted.replace(/\D/g, '').slice(0, 6);
        if (!digits) return;

        digits.split('').forEach((d, i) => {
          if (inputs[i]) {
            inputs[i].value = d;
            inputs[i].classList.add('filled');
          }
        });

        const nextFocus = Math.min(digits.length, inputs.length - 1);
        inputs[nextFocus].focus();

        const fullCode = syncHiddenCode();
        if (fullCode.length === 6) {
          handleSubmit();
        }
      });
    });

    // ─── 3. Expiry Countdown Timer ───
    let remainingSec = <?= $remainingSec ?>;
    const expiryTimer = document.getElementById('expiryTimer');

    function formatTime(s) {
      const mins = Math.floor(s / 60);
      const secs = s % 60;
      return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }

    function tickTimer() {
      if (remainingSec <= 0) {
        expiryTimer.textContent = '00:00 (Expired)';
        expiryTimer.classList.add('expired');
        showAlert('error', 'The verification code has expired. Please request a new one.');
        return;
      }
      expiryTimer.textContent = formatTime(remainingSec);
      remainingSec--;
      setTimeout(tickTimer, 1000);
    }
    tickTimer();

    // ─── 4. Resend Cooldown Countdown ───
    let cooldownSec = <?= $cooldownSec ?>;
    const resendBtn = document.getElementById('resendBtn');
    const resendLabel = document.getElementById('resendLabel');

    function tickCooldown() {
      if (cooldownSec > 0) {
        resendBtn.disabled = true;
        resendLabel.textContent = `Resend in ${cooldownSec}s`;
        cooldownSec--;
        setTimeout(tickCooldown, 1000);
      } else {
        resendBtn.disabled = false;
        resendLabel.textContent = 'Resend Code';
      }
    }
    if (cooldownSec > 0) tickCooldown();

    // ─── 5. Resend Code AJAX Handler ───
    resendBtn.addEventListener('click', async () => {
      if (resendBtn.disabled) return;
      resendBtn.disabled = true;
      resendLabel.textContent = 'Sending code…';

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const formData = new FormData();
        formData.append('token', '<?= htmlspecialchars($token) ?>');
        formData.append('action', 'resend');
        formData.append('_csrf', csrfToken);

        const resp = await fetch('mfa_verify.php', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: formData
        });

        const data = await resp.json();
        if (data.ok) {
          showAlert('success', data.message || 'A new verification code has been dispatched to your email.');
          // Reset expiry timer to 10 minutes (600s)
          remainingSec = 600;
          expiryTimer.classList.remove('expired');

          // Reset inputs
          inputs.forEach(i => { i.value = ''; i.classList.remove('filled'); });
          inputs[0].focus();
          syncHiddenCode();

          // Start 60s cooldown
          cooldownSec = 60;
          tickCooldown();
        } else {
          showAlert('error', data.error || 'Failed to resend code.');
          if (data.wait_seconds) {
            cooldownSec = data.wait_seconds;
            tickCooldown();
          } else {
            resendBtn.disabled = false;
            resendLabel.textContent = 'Resend Code';
          }
        }
      } catch (err) {
        showAlert('error', 'Network error while requesting code. Please try again.');
        resendBtn.disabled = false;
        resendLabel.textContent = 'Resend Code';
      }
    });

    // ─── 6. Verification Form Submit ───
    function showAlert(type, msg) {
      alertBox.style.display = 'block';
      alertBox.innerHTML = `
        <div class="alert-banner ${type === 'success' ? 'alert-success' : 'alert-error'}">
          <span>${type === 'success' ? '✓' : '⚠️'}</span>
          <span>${msg}</span>
        </div>
      `;
    }

    function setSubmitting(submitting) {
      verifyBtn.disabled = submitting;
      btnSpinner.style.display = submitting ? 'inline-block' : 'none';
      btnText.textContent = submitting ? 'Verifying…' : 'Verify & Sign In';
    }

    async function handleSubmit() {
      const code = syncHiddenCode();
      if (code.length !== 6) {
        showAlert('error', 'Please enter all 6 digits of your verification code.');
        inputs.find(i => !i.value)?.focus();
        return;
      }

      setSubmitting(true);

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const formData = new FormData(form);
        formData.set('_csrf', csrfToken);

        const resp = await fetch('mfa_verify.php', {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: formData
        });

        const data = await resp.json();

        if (data.ok && data.redirect) {
          showAlert('success', 'Verification confirmed! Redirecting…');
          window.location.href = data.redirect;
          return;
        }

        if (data.redirect && !data.ok) {
          // Locked out / max attempts reached
          window.location.href = data.redirect;
          return;
        }

        showAlert('error', data.error || 'Verification failed. Please try again.');
        setSubmitting(false);

        // Clear and focus first input
        inputs.forEach(i => { i.value = ''; i.classList.remove('filled'); });
        inputs[0].focus();
        syncHiddenCode();

      } catch (e) {
        // Fallback to standard form submission
        form.submit();
      }
    }

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      handleSubmit();
    });

    // Initial focus
    window.addEventListener('DOMContentLoaded', () => {
      if (inputs[0]) inputs[0].focus();
    });
  </script>
</body>
</html>
