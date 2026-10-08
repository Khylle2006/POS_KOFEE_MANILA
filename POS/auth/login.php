<?php
require_once '../includes/security.php';
secure_session_start();
send_security_headers();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if (!empty($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$info = match($_GET['reason'] ?? '') {
    'logout'                 => 'You have been signed out.',
    'unauthenticated'        => 'Please sign in to continue.',
    'terminated'             => 'This account is no longer active.',
    'error'                  => 'A session error occurred. Please sign in again.',
    'password_reset_success' => 'Your password has been updated! Please sign in with your new password.',
    default                  => '',
};

$saved_username = htmlspecialchars($_SESSION['login_username'] ?? ($_POST['username'] ?? ''));
unset($_SESSION['login_username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BrewVanti — POS Terminal Sign In</title>
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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

  /* ─── 4. Glassmorphism 2.5D Transparent Login Card ─── */
  .login-card-container {
    width: 100%;
    max-width: 450px;
    background: rgba(22, 14, 18, 0.64);
    backdrop-filter: blur(32px) saturate(180%);
    -webkit-backdrop-filter: blur(32px) saturate(180%);
    border-radius: 28px;
    padding: 44px 38px 38px;
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

  /* ─── 5. Perfectly Symmetrical Card Header ─── */
  .card-brand-header {
    text-align: center;
    margin-bottom: 26px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }

  .brand-logo-frame {
    width: 66px;
    height: 66px;
    margin: 0 auto 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    background: transparent;
    border: none;
    box-shadow: none;
    animation: logoPulse 4s ease-in-out infinite alternate;
  }

  @keyframes logoPulse {
    0% { transform: scale(1); filter: drop-shadow(0 0 10px rgba(217, 186, 133, 0.3)); }
    100% { transform: scale(1.05); filter: drop-shadow(0 0 22px rgba(217, 186, 133, 0.55)); }
  }

  .brand-logo-frame img {
    width: 60px;
    height: 60px;
    object-fit: contain;
    filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.6));
  }

  .card-brand-header h1 {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 29px;
    font-weight: 700;
    color: var(--cream);
    letter-spacing: 0.6px;
    line-height: 1.15;
    margin-bottom: 5px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
  }

  .card-brand-header p {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gold);
    opacity: 0.95;
  }

  /* ─── 6. Clean Alerts ─── */
  .alert-banner {
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 12px;
    margin-bottom: 18px;
    line-height: 1.45;
    text-align: center;
  }
  .alert-error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(248, 113, 113, 0.45);
    color: #FECDD3;
    font-weight: 600;
  }
  .alert-info {
    background: rgba(217, 186, 133, 0.15);
    border: 1px solid rgba(217, 186, 133, 0.4);
    color: #FAF7F2;
    font-weight: 500;
  }

  /* ─── 7. Balanced Form Fields ─── */
  .field-group {
    margin-bottom: 18px;
    text-align: left;
    width: 100%;
  }

  .field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.4px;
    color: var(--gold);
    margin-bottom: 8px;
  }

  .field-input {
    width: 100%;
    height: 48px;
    background: rgba(255, 255, 255, 0.05);
    border: 1.5px solid rgba(217, 186, 133, 0.22);
    border-radius: 13px;
    padding: 0 16px;
    font-size: 14px;
    color: #FAF7F2;
    font-weight: 500;
    outline: none;
    transition: all 0.22s ease;
    box-sizing: border-box;
  }

  .field-input::placeholder {
    color: rgba(232, 223, 213, 0.35);
    font-weight: 400;
  }

  .field-input:focus {
    background: rgba(255, 255, 255, 0.09);
    border-color: var(--gold-bright);
    box-shadow: 0 0 0 3px rgba(217, 186, 133, 0.24), 0 0 18px rgba(217, 186, 133, 0.18);
  }

  /* ─── 8. Workplace Location Pill Badge ─── */
  #geo_badge {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    width: 100% !important;
    box-sizing: border-box !important;
    margin: 12px 0 20px 0 !important;
    padding: 9px 14px !important;
    border-radius: 12px !important;
    font-size: 11.5px !important;
    line-height: 1.3 !important;
    background: rgba(217, 186, 133, 0.09) !important;
    border: 1px solid rgba(217, 186, 133, 0.25) !important;
    color: #FAF7F2 !important;
    transition: all 0.25s ease !important;
  }

  #geo_badge.bg-emerald-50,
  #geo_badge[class*="emerald"] {
    background: rgba(16, 185, 129, 0.12) !important;
    border-color: rgba(16, 185, 129, 0.35) !important;
    color: #A7F3D0 !important;
  }

  #geo_badge.bg-rose-50,
  #geo_badge[class*="rose"] {
    background: rgba(244, 63, 94, 0.14) !important;
    border-color: rgba(244, 63, 94, 0.4) !important;
    color: #FECDD3 !important;
  }

  #geo_badge.bg-amber-50,
  #geo_badge[class*="amber"] {
    background: rgba(245, 158, 11, 0.14) !important;
    border-color: rgba(245, 158, 11, 0.4) !important;
    color: #FDE68A !important;
  }

  .geo-badge-left {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: left;
  }

  .geo-retry-btn {
    flex-shrink: 0;
    background: rgba(217, 186, 133, 0.18) !important;
    border: 1px solid rgba(217, 186, 133, 0.4) !important;
    color: var(--gold-bright) !important;
    border-radius: 8px !important;
    padding: 4px 10px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.18s ease !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    text-decoration: none !important;
    display: inline-block !important;
  }

  .geo-retry-btn:hover {
    background: rgba(217, 186, 133, 0.32) !important;
    border-color: var(--gold-bright) !important;
    color: #FAF7F2 !important;
  }

  /* ─── 9. Radiant Gold Submit Button ─── */
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
    position: relative;
    overflow: hidden;
    margin-top: 4px;
  }

  .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 30px -4px rgba(217, 186, 133, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.6);
    filter: brightness(1.04);
  }

  .btn-submit:active {
    transform: translateY(1px) scale(0.99);
    box-shadow: 0 5px 15px -2px rgba(217, 186, 133, 0.3);
  }

  /* ─── 10. Clean Centered Footer Links ─── */
  .card-footer-links {
    margin-top: 24px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: center;
  }

  .forgot-link {
    font-size: 12.5px;
    color: rgba(232, 223, 213, 0.7);
    text-decoration: none;
    transition: color 0.18s;
  }

  .forgot-link strong {
    color: var(--gold);
    font-weight: 600;
    border-bottom: 1px dotted rgba(217, 186, 133, 0.5);
    padding-bottom: 1px;
    transition: all 0.18s;
  }

  .forgot-link:hover strong {
    color: var(--gold-bright);
    border-bottom-color: var(--gold-bright);
  }

  .back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(217, 186, 133, 0.8);
    text-decoration: none;
    transition: all 0.18s;
    padding: 4px 10px;
    border-radius: 8px;
  }

  .back-link:hover {
    color: #FAF7F2;
    background: rgba(255, 255, 255, 0.06);
  }

  /* Responsive Adjustments */
  @media (max-width: 480px) {
    .login-card-container {
      padding: 34px 24px 28px;
      border-radius: 22px;
    }
    .card-brand-header h1 {
      font-size: 25px;
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

  <!-- Interactive Cursor Follower Spotlight -->
  <div class="cursor-glow-follower" id="mouseGlow"></div>

  <!-- ─── Ultra-Realistic 2.5D Roasted Coffee Beans ─── -->
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

  <svg class="floating-bean bean-3" viewBox="0 0 60 80" fill="none">
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

  <svg class="floating-bean bean-4" viewBox="0 0 60 80" fill="none">
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

  <svg class="floating-bean bean-5" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#2E170E" />
    <path d="M30 10 Q37 40 30 70" stroke="#0B0402" stroke-width="4" stroke-linecap="round" />
  </svg>

  <svg class="floating-bean bean-6" viewBox="0 0 60 80" fill="none">
    <path d="M30 6 C46 6 54 22 54 42 C54 62 44 74 30 74 C16 74 6 62 6 42 C6 22 14 6 30 6 Z" fill="#3A1F15" />
    <path d="M30 10 Q23 40 30 70" stroke="#0D0502" stroke-width="3.8" stroke-linecap="round" />
  </svg>

  <!-- ─── Transparent Glassmorphism 2.5D Centered Login Card ─── -->
  <div class="login-card-container" id="loginCard">
    <div class="card-gloss-sheen"></div>

    <!-- Symmetrical Brand Header -->
    <div class="card-brand-header">
      <div class="brand-logo-frame">
        <img src="../assets/brewvanti_logo.png" alt="BrewVanti Logo" onerror="this.src='../assets/brewvanti_pos_logo.svg'">
      </div>
      <h1>BrewVanti</h1>
      <p>Artisan Coffee &bull; POS Sign In</p>
    </div>

    <!-- Alert Notifications -->
    <?php if ($error): ?>
      <div class="alert-banner alert-error">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php elseif ($info): ?>
      <div class="alert-banner alert-info">
        <?= htmlspecialchars($info) ?>
      </div>
    <?php endif; ?>

    <!-- Login Form -->
    <form id="loginForm" method="POST" action="login_process.php">
      <?= csrf_field() ?>
      <input type="hidden" name="latitude" id="geo_latitude" value="">
      <input type="hidden" name="longitude" id="geo_longitude" value="">
      <input type="hidden" name="accuracy" id="geo_accuracy" value="">
      <input type="hidden" name="location_status" id="geo_status" value="unrequested">
      <input type="hidden" name="device_info" id="geo_device_info" value="">

      <!-- Username Field -->
      <div class="field-group">
        <label class="field-label" for="usernameInput">Username</label>
        <input type="text" id="usernameInput" name="username" class="field-input" placeholder="Enter your username" value="<?= $saved_username ?>" required autocomplete="username">
      </div>

      <!-- Password Field -->
      <div class="field-group">
        <label class="field-label" for="passwordInput">Password</label>
        <input type="password" id="passwordInput" name="password" class="field-input" placeholder="••••••••" required autocomplete="current-password">
      </div>

      <!-- Geolocation Workplace Status Badge -->
      <div id="geo_badge">
        <div class="geo-badge-left">
          <span id="geo_icon">📍</span>
          <span id="geo_label">Checking workplace location…</span>
        </div>
        <button type="button" class="geo-retry-btn" onclick="window.retryLocation && window.retryLocation()">
          Retry
        </button>
      </div>

      <!-- Submit Button -->
      <button type="submit" id="submitBtn" class="btn-submit">
        <span id="btnText">Sign In</span>
      </button>

      <!-- Balanced Footer Links -->
      <div class="card-footer-links">
        <a href="forgot_password.php" class="forgot-link">
          Trouble signing in? <strong>Forgot password</strong>
        </a>
        <a href="../index.html" class="back-link">
          &larr; Back to Storefront
        </a>
      </div>
    </form>
  </div>

  <!-- ─── Interactive 2.5D Mouse Parallax Tilt & Cursor Follower ─── -->
  <script>
    const card = document.getElementById('loginCard');
    const mouseGlow = document.getElementById('mouseGlow');

    document.addEventListener('mousemove', (e) => {
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
        const rotX = deltaY * -6;
        const rotY = deltaX * 6;
        card.style.transform = `perspective(1000px) rotateX(${rotX.toFixed(2)}deg) rotateY(${rotY.toFixed(2)}deg) translateZ(8px)`;
      }
    });

    document.addEventListener('mouseleave', () => {
      if (card) {
        card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateZ(0px)';
      }
    });
  </script>

  <script src="js/login.js"></script>
</body>
</html>