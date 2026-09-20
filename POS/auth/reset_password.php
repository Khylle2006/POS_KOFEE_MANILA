<?php
// ─────────────────────────────────────────────────────────────
//  auth/reset_password.php
//  Validates reset token and sets new user password
// ─────────────────────────────────────────────────────────────

require_once '../includes/db.php';
require_once '../includes/security.php';

secure_session_start();
send_security_headers();

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$pdo   = get_db();
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';
$valid = false;
$reset_entry = null;

// Validate token
if ($token !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT pr.*, u.username, u.firstname, u.lastname, u.email 
            FROM password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.token = :token 
              AND pr.used_at IS NULL 
              AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $reset_entry = $stmt->fetch();
        $valid = (bool)$reset_entry;
    } catch (Throwable $e) {
        error_log('Token lookup error: ' . $e->getMessage());
        $error = 'A database error occurred while verifying the reset token.';
    }
}

// Process new password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $new_pass  = $_POST['new_password'] ?? '';
        $conf_pass = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 6) {
            $error = 'Password must be at least 6 characters in length.';
        } elseif ($new_pass !== $conf_pass) {
            $error = 'Passwords do not match. Please verify and retype.';
        } else {
            try {
                $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $user_id = (int)$reset_entry['user_id'];

                // Update user password
                $upd = $pdo->prepare('UPDATE users SET password = :p, updated_at = NOW() WHERE id = :uid');
                $upd->execute([':p' => $hash, ':uid' => $user_id]);

                // Invalidate this token and any other active tokens for this user
                $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
                    ->execute([':uid' => $user_id]);

                // Clear any leftover throttle locks for this username
                if (function_exists('clear_login_lockout')) {
                    clear_login_lockout($reset_entry['username']);
                }

                $_SESSION['login_username'] = $reset_entry['username'];
                header('Location: login.php?reason=password_reset_success');
                exit;
            } catch (Throwable $e) {
                error_log('Password update failed: ' . $e->getMessage());
                $error = 'Failed to update password. Please try again.';
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
<title>Reset Password — Kofee Café</title>
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

    <!-- Form container -->
    <div class="form-pop bg-[color:var(--cream)] rounded-2xl shadow-2xl w-full sm:w-[320px] px-6 py-7 shrink-0"
         style="box-shadow:0 18px 40px -12px rgba(0,0,0,0.35);">

      <h1 class="font-display text-xl mb-2" style="color:var(--espresso)">Set New Password</h1>

      <?php if (!$valid): ?>
        <div class="mb-5 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-xs text-red-700 font-medium leading-relaxed">
          <p class="font-bold mb-1">Invalid or Expired Link</p>
          <p>This password reset link has expired or has already been used. Please request a fresh reset link.</p>
        </div>

        <a href="forgot_password.php" class="btn-brew block text-center w-full text-white text-sm font-semibold rounded-lg py-2.5 shadow-lg">
          Request New Link
        </a>
      <?php else: ?>
        <p class="text-[12px] text-stone-600 mb-4 leading-relaxed">
          Resetting password for <b>@<?= htmlspecialchars($reset_entry['username']) ?></b>.
        </p>

        <?php if ($error): ?>
        <div class="mb-4 rounded-lg border-2 border-red-300 bg-red-50 px-3 py-2 text-[12px] text-red-600 font-medium">
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form class="space-y-4" method="POST" action="reset_password.php">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>

          <div class="field">
            <label class="block text-[11px] font-semibold uppercase tracking-wide mb-1" style="color:var(--espresso)">New Password</label>
            <input type="password" name="new_password" id="new_password" placeholder="Minimum 6 characters" minlength="6" required autofocus
              class="w-full rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-sm outline-none placeholder:text-stone-400"
              style="color:var(--espresso)">
          </div>

          <div class="field">
            <label class="block text-[11px] font-semibold uppercase tracking-wide mb-1" style="color:var(--espresso)">Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-type new password" minlength="6" required
              class="w-full rounded-lg border-2 border-[#EFE0CC] bg-white/70 px-3 py-2 text-sm outline-none placeholder:text-stone-400"
              style="color:var(--espresso)">
          </div>

          <button type="submit" class="btn-brew w-full text-white text-sm font-semibold rounded-lg py-2.5 mt-2 shadow-lg">
            Update Password
          </button>
        </form>
      <?php endif; ?>

      <p class="text-center text-[11px] text-stone-500 pt-4">
        Back to <a href="login.php" class="font-semibold hover:underline" style="color:var(--caramel)">Sign In</a>
      </p>
    </div>

    <!-- Illustration Panel -->
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
        Secure Credential Update
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

