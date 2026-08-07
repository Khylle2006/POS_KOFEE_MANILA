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
      font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .btn-logout:hover { background: #7a4e2e; }
  </style>
</head>
<body>
  <div class="icon">🔒</div>
  <h1>No access yet, <?= htmlspecialchars($user['firstname'] ?: $user['username']) ?></h1>
  <p>
    Your account (<?= htmlspecialchars($user['role']) ?>) doesn't have any
    permissions assigned right now. Ask an admin to grant access from
    Manage Permissions, then try logging in again.
  </p>
  <button class="btn-logout" onclick="window.location.href='../auth/logout.php'">Log Out</button>
</body>
</html>