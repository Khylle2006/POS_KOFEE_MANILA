<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_login();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>No Access — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: #faf5ef; color: #2c1a0e;
      height: 100vh; display: flex; align-items: center; justify-content: center;
      flex-direction: column; gap: 14px; text-align: center; padding: 20px;
    }
    .icon { font-size: 56px; }
    h1 { font-size: 20px; font-weight: 800; }
    p  { font-size: 13px; color: #9a7e65; max-width: 360px; }
    .btn-logout {
      margin-top: 8px; padding: 11px 24px; background: #c47d3e; color: #fff;
      border: none; border-radius: 12px; font-family: 'Poppins', sans-serif;
    }
    .btn-logout:hover { background: #7a4e2e; }
  </style>
</head>
<body>
  <div class="icon">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#c47d3e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    </svg>
  </div>
  <h1>Access Restricted, <?= htmlspecialchars($user['firstname'] ?: $user['username']) ?></h1>
  <p>
    Your account (<?= htmlspecialchars(implode(', ', $user['roles'] ?? [$user['role']])) ?>) doesn't have permission to access this page<?= !empty($_GET['perm']) ? ' (<code>' . htmlspecialchars($_GET['perm']) . '</code>)' : '' ?>.
    If you need access to this section, please ask a store administrator to grant the required permission in <strong>Manage Permissions</strong>.
  </p>
  <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap">
    <button class="btn-logout" style="background:#8b7c88" onclick="history.back()">Go Back</button>
    <button class="btn-logout" onclick="window.location.href='<?= htmlspecialchars(get_dashboard_url()) ?>'">Home / Dashboard</button>
    <button class="btn-logout" style="background:#555" onclick="window.location.href='../auth/logout.php'">Log Out</button>
  </div>
</body>
</html>