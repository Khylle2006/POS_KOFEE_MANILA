<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/email_mfa.php';
require_once __DIR__ . '/../includes/login_flow.php';
secure_session_start();
send_security_headers();
$error = '';
$notice = '';
$challenge = &$_SESSION['email_mfa_login'];
if (!is_array($challenge) || empty($challenge['user_id'])) {
    header('Location: login.php');
    exit;
}
$pdo = get_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $action = $_POST['action'] ?? 'verify';
        if ($action === 'cancel') {
            unset($_SESSION['email_mfa_login']);
            header('Location: login.php');
            exit;
        }
        $userId = (int)$challenge['user_id'];
        $user = email_mfa_user($pdo, $userId);
        if (!email_mfa_enabled($pdo, $userId)) {
            unset($_SESSION['email_mfa_login']);
            throw new SecurityFault('MFA_SETTING_CHANGED', 'Your sign-in settings changed. Please sign in again.', 409);
        }
        $context = $challenge['context'];
        if ($action === 'resend') {
            if ((int)$challenge['expires_at'] <= time()) {
                unset($_SESSION['email_mfa_login']);
                throw new SecurityFault('MFA_CODE_EXPIRED', 'This sign-in expired. Please sign in again.', 409);
            }
            start_email_mfa($pdo, $userId, 'login', $context);
            $notice = 'A new code was emailed. Use the latest code.';
        } elseif ($action === 'verify') {
            rate_limit('email-mfa-verify-login', (string)$userId, 5, 600);
            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
            verify_email_mfa_challenge($challenge, $user, 'login', $code);
            unset($_SESSION['email_mfa_login']);
            complete_password_login($pdo, $user, $context);
        } else {
            throw new SecurityFault('ACTION_INVALID', 'Unknown verification action.');
        }
    } catch (SecurityFault $exception) {
        $error = $exception->getMessage();
    }
}
$canVerify = !empty($_SESSION['email_mfa_login']) && (int)($_SESSION['email_mfa_login']['expires_at'] ?? 0) > time();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Email verification — BrewVanti</title>
  <link rel="stylesheet" href="../css/brand.css">
  <link rel="stylesheet" href="../css/email-mfa.css">
</head>
<body class="mfa-page">
  <main class="mfa-card">
    <p class="mfa-brand">BrewVanti</p>
    <h1>Check your email</h1>
    <p>Enter the six-digit code sent to your profile email. The code expires in 10 minutes.</p>
    <?php if ($error): ?><p class="mfa-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($notice): ?><p role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($canVerify): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <label for="code">Email verification code</label>
        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus>
        <button type="submit">Verify and continue</button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="resend" class="mfa-secondary">Send a new code</button>
      </form>
    <?php else: ?><p>This sign-in has expired. Return to login to request a new code.</p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit" name="action" value="cancel" class="mfa-secondary">Return to login</button>
    </form>
  </main>
</body>
</html>
