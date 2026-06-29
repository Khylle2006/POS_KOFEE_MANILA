<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/home.php'));
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
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:        #f5f6fa;
      --surface:   #ffffff;
      --border:    #e8eaef;
      --text:      #1a1d23;
      --muted:     #7a7f8e;
      --accent:    #f57c00;
      --accent-lt: #fff3e0;
      --red:       #e53935;
      --green:     #2e7d32;
      --green-lt:  #e8f5e9;
      --input-bg:  #f8f9fc;
      --radius:    14px;
      --shadow:    0 4px 24px rgba(0,0,0,.08);
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .login-card {
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 420px;
      overflow: hidden;
    }

    .card-header {
      background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
      padding: 36px 36px 28px;
      text-align: center;
      border-bottom: 1px solid var(--border);
    }

    .brand-icon {
      width: 68px; height: 68px;
      background: var(--surface);
      border-radius: 20px;
      display: flex; align-items: center; justify-content: center;
      font-size: 34px;
      margin: 0 auto 14px;
      box-shadow: 0 2px 14px rgba(245,124,0,.18);
    }

    .card-header h1 { font-size: 22px; font-weight: 700; color: var(--text); letter-spacing: -.3px; }
    .card-header p  { font-size: 13px; color: var(--muted); margin-top: 4px; }

    .card-body { padding: 30px 36px 36px; }

    .banner {
      display: flex; align-items: flex-start; gap: 9px;
      border-radius: 9px; padding: 11px 13px;
      font-size: 13px; margin-bottom: 18px; line-height: 1.4;
    }
    .banner-error { background: #fdecea; border: 1px solid #f5c6cb; color: var(--red); }
    .banner-info  { background: var(--green-lt); border: 1px solid #c8e6c9; color: var(--green); }

    .field + .field { margin-top: 15px; }

    .field label {
      display: block; font-size: 12.5px; font-weight: 600;
      color: var(--text); margin-bottom: 6px; letter-spacing: .2px;
    }

    .input-wrap { position: relative; }

    .input-wrap .ico {
      position: absolute; left: 13px; top: 50%;
      transform: translateY(-50%);
      font-size: 15px; pointer-events: none; line-height: 1;
    }

    .input-wrap input {
      width: 100%;
      padding: 11px 12px 11px 40px;
      border: 1.5px solid var(--border);
      border-radius: 9px;
      background: var(--input-bg);
      font-size: 14px; font-family: inherit; color: var(--text);
      outline: none;
      transition: border-color .18s, box-shadow .18s;
    }
    .input-wrap input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(245,124,0,.12);
      background: #fff;
    }
    .input-wrap input::placeholder { color: #b0b5c3; }

    .toggle-pw {
      position: absolute; right: 12px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      font-size: 15px; color: var(--muted); padding: 2px; line-height: 1;
    }

    .btn-login {
      width: 100%; margin-top: 22px; padding: 13px;
      background: var(--accent); color: #fff; border: none;
      border-radius: 9px;
      font-size: 15px; font-weight: 700; font-family: inherit;
      cursor: pointer; letter-spacing: .1px;
      transition: background .18s, transform .1s;
    }
    .btn-login:hover  { background: #e65100; }
    .btn-login:active { transform: scale(.98); }

    .card-footer {
      text-align: center; font-size: 12px;
      color: var(--muted); padding: 0 36px 26px;
    }
    .card-footer a { color: var(--accent); text-decoration: none; font-weight: 600; }
  </style>
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