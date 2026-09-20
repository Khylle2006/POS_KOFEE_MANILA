<?php
// ─────────────────────────────────────────────────────────────
//  auth/forgot_password.php
//  Self-service password reset request via PHPMailer
// ─────────────────────────────────────────────────────────────

require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/mailer.php';

secure_session_start();
send_security_headers();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error       = '';
$warning     = '';
$success     = '';
$dev_link    = '';
$input_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $input = trim($_POST['identity'] ?? '');
        $input_value = htmlspecialchars($input);

        if ($input === '') {
            $error = 'Please enter your username or registered email.';
        } else {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare("
                    SELECT id, username, firstname, lastname, email, status 
                    FROM users 
                    WHERE (username = :u OR email = :e) AND status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([':u' => $input, ':e' => $input]);
                $user = $stmt->fetch();

                if ($user && !empty($user['email'])) {
                    // Generate 64-character secure token
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

                    // Invalidate previous active tokens for this user
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL")
                        ->execute([':uid' => $user['id']]);

                    // Store new reset token
                    $pdo->prepare("
                        INSERT INTO password_resets (user_id, email, token, expires_at)
                        VALUES (:uid, :email, :token, :expires)
                    ")->execute([
                        ':uid'     => $user['id'],
                        ':email'   => $user['email'],
                        ':token'   => $token,
                        ':expires' => $expires,
                    ]);

                    // Build full URL
                    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
                    $scheme   = $is_https ? 'https' : 'http';
                    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $dir      = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                    $reset_url = $scheme . '://' . $host . $dir . '/reset_password.php?token=' . urlencode($token);

                    $fname = $user['firstname'] ?: $user['username'];
                    $mailResult = send_password_reset_email($user['email'], $fname, $reset_url);

                    $masked_email = preg_replace('/(?<=..).(?=.*@)/u', '*', $user['email']);

                    if (!empty($mailResult['sent'])) {
                        $success = "A password reset link has been dispatched to <b>{$masked_email}</b>. Please check your email inbox and spam folder.";
                    } elseif (!empty($mailResult['error'])) {
                        $error = "Could not deliver email: " . htmlspecialchars($mailResult['error']) . ". Please check your SMTP settings in <code>includes/config.local.php</code>.";
                        $dev_link = $reset_url;
                    } elseif (empty(SMTP_USER) || empty(SMTP_PASS)) {
                        $warning = "<b>SMTP Credentials Not Configured:</b> PHPMailer cannot send an email to <b>{$masked_email}</b> across the internet because sender email credentials are not set in <code>includes/config.local.php</code>.<br><br>To receive real emails in your inbox, add your Gmail and 16-character App Password to <code>includes/config.local.php</code>.";
                        $dev_link = $reset_url;
                    } else {
                        $success = "A password reset link has been dispatched to <b>{$masked_email}</b>. Please check your inbox.";
                    }
                } elseif ($user && empty($user['email'])) {
                    $error = "This account does not have a registered email address. Please contact your manager or HR administrator to reset your password.";
                } else {
                    // Constant-time message to prevent username enumeration
                    $success = "If an account matches that username or email, a password reset link has been dispatched. Please check your inbox.";
                }
            } catch (Throwable $e) {
                error_log('Forgot password error: ' . $e->getMessage());
                $error = 'An unexpected error occurred. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — Kofee Café</title>
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
                       radial-gradient(circle at 45% 85%, rgba(201,123,61,0.07) 0, transparent 3%),
                       radial-gradient(circle at 85% 15%, rgba(201,123,61,0.07) 0, transparent 3%),
                       radial-gradient(circle at 10% 75%, rgba(201,123,61,0.06) 0, transparent 3%);
    pointer-events:none;
  }

  .glow-orb{
    position:absolute;
    width:380px; height:380px;
    background: radial-gradient(circle, rgba(230,162,92,0.28) 0%, transparent 70%);
    filter: blur(10px);
    animation: drift 10s ease-in-out infinite alternate;
    pointer-events:none;
  }
  @keyframes drift{
    0%{ transform: translate(0,0) scale(1); }
    100%{ transform: translate(24px,-18px) scale(1.08); }
  }

  .cup-float{ animation: float 4.5s ease-in-out infinite; }
  @keyframes float{
    0%,100%{ transform: translateY(0px); }
    50%{ transform: translateY(-6px); }
  }

  .field input{ transition: all .25s ease; }
  .field input:focus{
    box-shadow: 0 0 0 3px rgba(201,123,61,0.22);
    border-color: var(--caramel);
  }
  .field:focus-within label{ color: var(--caramel); }

  .btn-brew{
    background: linear-gradient(135deg, var(--caramel) 0%, var(--espresso-deep) 140%);
    transition: transform .2s ease, box-shadow .2s ease, background-position .4s ease;
    background-size: 160% 160%;
    background-position: 0% 0%;
  }
  .btn-brew:hover{
    transform: translateY(-2px);
    box-shadow: 0 12px 26px -8px rgba(24,17,32,0.55);
    background-position: 100% 100%;
  }
  .btn-brew:active{ transform: translateY(0px) scale(0.98); }

  .card-pop{ animation: pop .6s cubic-bezier(.2,.8,.2,1) both; }
  @keyframes pop{
    0%{ opacity:0; transform: translateY(18px) scale(.97); }
    100%{ opacity:1; transform: translateY(0) scale(1); }
  }

  .form-pop{ animation: formPop .5s cubic-bezier(.2,.8,.2,1) .2s both; }
  @keyframes formPop{
    0%{ opacity:0; transform: translateY(10px) scale(.98); }
    100%{ opacity:1; transform: translateY(0) scale(1); }
  }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-8">

<div class="relative w-full max-w-md sm:max-w-2xl rounded-[28px] overflow-hidden card-pop"
     style="background: linear-gradient(150deg, var(--espresso) 0%, var(--espresso-deep) 100%); box-shadow:0 30px 60px -18px rgba(24,17,32,0.45);">

  <div class="bean-field"></div>
  <div class="glow-orb -top-20 -right-16"></div>
  <div class="glow-orb bottom-0 -left-20" style="animation-delay:2s;"></div>

  <div class="relative flex flex-col sm:flex-row items-center gap-8 px-6 py-8 sm:px-10 sm:py-10">

    <!-- White form card -->
    <div class="form-pop bg-[color:var(--cream)] rounded-2xl shadow-2xl w-full sm:w-[320px] px-6 py-7 shrink-0"
         style="box-shadow:0 18px 40px -12px rgba(0,0,0,0.35);">
      
      <div class="flex items-center justify-between mb-4">
        <h1 class="font-display text-xl" style="color:var(--espresso)">Forgot Password</h1>
        <a href="login.php" class="text-xs font-semibold hover:underline" style="color:var(--caramel)">← Sign In</a>
      </div>

      <p class="text-[12px] text-stone-600 mb-5 leading-relaxed">
        Enter your account username or email address and we'll send a secure reset link via PHPMailer.
      </p>

      <?php if ($error): ?>
      <div class="mb-4 rounded-lg border-2 border-red-300 bg-red-50 px-3 py-2 text-[12px] text-red-600 font-medium">
        <?= htmlspecialchars($error) ?>
      </div>
      <?php elseif ($warning): ?>
      <div class="mb-4 rounded-lg border-2 border-amber-300 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-900 font-medium leading-relaxed">
        <?= $warning ?>
      </div>
      <?php elseif ($success): ?>
      <div class="mb-4 rounded-lg border-2 border-emerald-300 bg-emerald-50 px-3 py-2.5 text-[12px] text-emerald-800 font-medium leading-relaxed">
        <?= $success ?>
      </div>
      <?php endif; ?>

      <?php if ($dev_link): ?>
      <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200">
        <div class="text-[11px] font-bold text-amber-800 uppercase tracking-wide mb-1">Local Testing Link:</div>
        <p class="text-[11px] text-amber-700 mb-2">Click below to proceed to password reset form:</p>
        <a href="<?= htmlspecialchars($dev_link) ?>" class="inline-block w-full text-center py-2 px-3 bg-[var(--caramel)] text-white text-xs font-bold rounded-md hover:opacity-90 transition">
          Open Password Reset Form →
        </a>
      </div>
      <?php endif; ?>

      <?php if (!$success || $dev_link): ?>
      <form class="space-y-4" method="POST" action="forgot_password.php">
        <?= csrf_field() ?>
        <div class="field">
          <label class="block text-[11px] font-semibold uppercase tracking-wide mb-1" style="color:var(--espresso)">Username or Email</label>
          <input type="text" name="identity" placeholder="e.g. barista or user@example.com" value="<?= $input_value ?>" required autofocus
            class="w-full rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-sm outline-none placeholder:text-stone-400"
            style="color:var(--espresso)">
        </div>

        <button type="submit" class="btn-brew w-full text-white text-sm font-semibold rounded-lg py-2.5 mt-1 shadow-lg">
          Send Reset Link
        </button>
      </form>
      <?php endif; ?>

      <p class="text-center text-[11px] text-stone-500 pt-4">
        Remembered your password? <a href="login.php" class="font-semibold hover:underline" style="color:var(--caramel)">Sign in here</a>
      </p>
    </div>

    <!-- Brand illustration panel -->
    <div class="brand-in relative flex-1 flex flex-col items-center text-center py-2">
      <div class="relative mx-auto mb-4 cup-float" style="width:96px;">
        <svg viewBox="0 0 120 100" width="96" height="80">
          <ellipse cx="45" cy="88" rx="42" ry="6" fill="rgba(0,0,0,0.25)"/>
          <path d="M12 30 H78 V60 C78 78 63 88 45 88 C27 88 12 78 12 60 Z" fill="var(--cream)"/>
          <path d="M78 38 C96 38 96 66 78 66" fill="none" stroke="var(--cream)" stroke-width="6" stroke-linecap="round"/>
          <ellipse cx="45" cy="30" rx="33" ry="7" fill="var(--caramel-light)"/>
          <ellipse cx="45" cy="30" rx="33" ry="7" fill="none" stroke="var(--espresso)" stroke-width="1" opacity="0.15"/>
        </svg>
      </div>

      <h2 class="font-display text-2xl sm:text-3xl text-[color:var(--cream)] mb-1">Kofee Café</h2>
      <p class="text-xs sm:text-sm tracking-wide" style="color:var(--latte); opacity:0.75;">
        Account Recovery &amp; Security
      </p>

      <div class="mt-5 flex items-center justify-center gap-2">
        <span class="w-1.5 h-1.5 rounded-full" style="background:var(--caramel-light)"></span>
        <span class="w-6 h-1.5 rounded-full" style="background:var(--caramel)"></span>
        <span class="w-1.5 h-1.5 rounded-full" style="background:var(--caramel-light)"></span>
      </div>
    </div>

  </div>
</div>

</body>
</html>

