<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'php/dashboard.php' : 'php/menu.php'));
    exit;
}

$error = '';
if (!empty($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$info = match($_GET['reason'] ?? '') {
    'logout'          => 'You have been signed out.',
    'unauthenticated' => 'Please sign in to continue.',
    default           => '',
};

$saved_username = htmlspecialchars($_POST['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Kofee POS – Sign In</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/login.css"/>

</head>
<body>

<div class="login-card">

  <div class="card-header">
    <div class="brand-icon">🧋</div>
    <h1>Kofee POS</h1>
    <p>Sign in to your account</p>
  </div>

  <div class="card-body">

    <?php if ($info): ?>
      <div class="banner banner-info">ℹ️ <?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="banner banner-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="auth/login_process.php">

      <div class="field">
        <label for="username">Username</label>
        <div class="input-wrap">
          <span class="ico">👤</span>
          <input type="text" id="username" name="username"
                 placeholder="Enter your username"
                 value="<?= $saved_username ?>"
                 autocomplete="username" required/>
        </div>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="input-wrap">
          <span class="ico">🔒</span>
          <input type="password" id="password" name="password"
                 placeholder="Enter your password"
                 autocomplete="current-password" required/>
          <button class="toggle-pw" type="button"
                  onclick="togglePw(this)" title="Show / hide">👁️</button>
        </div>
      </div>

      <button type="submit" class="btn-login">Sign In</button>

    </form>

  </div>

  <div class="card-footer">
    Trouble signing in? Contact your <a href="mailto:roque.khyllechester.roque@ncst.edu.ph">manager</a>.
  </div>

</div>

<script>
  function togglePw(btn) {
    const pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    btn.textContent = pw.type === 'password' ? '👁️' : '🙈';
  }
</script>

</body>
</html>