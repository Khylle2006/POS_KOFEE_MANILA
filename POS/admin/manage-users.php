<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role('admin');

$pdo = get_db();
$toast = '';
$toast_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Register ──────────────────────────────
    if ($action === 'register') {
        $username  = trim($_POST['username']  ?? '');
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = in_array($_POST['role'] ?? '', ['staff','admin']) ? $_POST['role'] : 'staff';
        $password  = $_POST['password']         ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        if (!$username || !$firstname || !$lastname || !$email || !$password) {
            $toast = '⚠️ Please fill in all required fields.'; $toast_type = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $toast = '⚠️ Please enter a valid email address.'; $toast_type = 'error';
        } elseif (strlen($password) < 6) {
            $toast = '⚠️ Password must be at least 6 characters.'; $toast_type = 'error';
        } elseif ($password !== $confirm) {
            $toast = '⚠️ Passwords do not match.'; $toast_type = 'error';
        } else {
            // Check username + email uniqueness
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1');
            $chk->execute([':u' => $username, ':e' => $email]);
            if ($chk->fetch()) {
                $toast = '⚠️ Username or email is already taken.'; $toast_type = 'error';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare(
                    'INSERT INTO users (username, firstname, lastname, email, password, role, status, created_at, updated_at)
                     VALUES (:u, :f, :l, :e, :p, :r, "active", NOW(), NOW())'
                )->execute([
                    ':u' => $username, ':f' => $firstname, ':l' => $lastname,
                    ':e' => $email,    ':p' => $hash,       ':r' => $role,
                ]);
                $toast = '✅ User "' . htmlspecialchars($username) . '" registered successfully!';
            }
        }
    }

    // ── Edit ──────────────────────────────────
    if ($action === 'edit') {
        $id        = (int)($_POST['user_id']  ?? 0);
        $username  = trim($_POST['username']  ?? '');
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = in_array($_POST['role'] ?? '', ['staff','admin']) ? $_POST['role'] : 'staff';
        $pw        = $_POST['password'] ?? '';

        if (!$username || !$firstname || !$lastname || !$email) {
            $toast = '⚠️ All fields except password are required.'; $toast_type = 'error';
        } else {
            if ($pw !== '') {
                $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare(
                    'UPDATE users SET username=:u, firstname=:f, lastname=:l, email=:e,
                     role=:r, password=:p, updated_at=NOW() WHERE id=:id'
                )->execute([':u'=>$username,':f'=>$firstname,':l'=>$lastname,
                            ':e'=>$email,':r'=>$role,':p'=>$hash,':id'=>$id]);
            } else {
                $pdo->prepare(
                    'UPDATE users SET username=:u, firstname=:f, lastname=:l, email=:e,
                     role=:r, updated_at=NOW() WHERE id=:id'
                )->execute([':u'=>$username,':f'=>$firstname,':l'=>$lastname,
                            ':e'=>$email,':r'=>$role,':id'=>$id]);
            }
            $toast = '✅ User updated successfully!';
        }
    }

    // ── Status ────────────────────────────────
    if ($action === 'set_status') {
        $id     = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['active','blocked','on_hold']) && $id) {
            $pdo->prepare('UPDATE users SET status=:s, updated_at=NOW() WHERE id=:id')
                ->execute([':s'=>$status,':id'=>$id]);
            $labels = ['active'=>'Activated','blocked'=>'Blocked','on_hold'=>'Put on Hold'];
            $toast  = '✅ User ' . $labels[$status] . '.';
        }
    }

    // ── Delete ────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id && $id !== (int)$_SESSION['user_id']) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id'=>$id]);
            $toast = '🗑️ User deleted.';
        } else {
            $toast = '⚠️ You cannot delete your own account.'; $toast_type = 'error';
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    $redirect_base = dirname($_SERVER['PHP_SELF']) . '/manage_users.php';
    header('Location: ' . $redirect_base . $q);
    exit;
}

// Flash from redirect
if (!$toast && isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Fetch users ───────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$where  = '1=1';
$params = [];

if ($search) {
    $where .= ' AND (username LIKE :s OR firstname LIKE :s2 OR lastname LIKE :s3 OR email LIKE :s4)';
    $params[':s']  = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
    $params[':s4'] = "%$search%";
}
if (in_array($filter, ['staff','admin'])) {
    $where .= ' AND role = :r'; $params[':r'] = $filter;
}
if (in_array($filter, ['active','blocked','on_hold'])) {
    $where .= ' AND status = :st'; $params[':st'] = $filter;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$total   = count($users);
$active  = count(array_filter($users, fn($u) => ($u['status'] ?? 'active') === 'active'));
$blocked = count(array_filter($users, fn($u) => ($u['status'] ?? '') === 'blocked'));
$on_hold = count(array_filter($users, fn($u) => ($u['status'] ?? '') === 'on_hold'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Users — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/add-items.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:     #c47d3e;
      --accent-lt:  #fdf3ea;
      --card-bg:    #ffffff;
      --border:     #ecddc8;
      --text-main:  #2c1a0e;
      --text-muted: #9a7e65;
      --bg:         #faf5ef;
      --green:      #2e7d32;  --green-lt:  #e8f5e9;
      --red:        #c62828;  --red-lt:    #ffebee;
      --amber:      #e65100;  --amber-lt:  #fff3e0;
      --blue:       #1565c0;  --blue-lt:   #e3f2fd;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    #page-users { display: flex; flex-direction: column; height: 100vh; }
    #page-users .page-body { flex: 1; overflow-y: auto; padding: 28px; }
    .users-wrap { max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 22px; }

    /* stat row */
    .stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
    .mini-stat { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 16px 18px; display: flex; align-items: center; gap: 12px; }
    .mini-stat-icon { font-size: 22px; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .mini-stat-val  { font-size: 22px; font-weight: 700; line-height: 1; }
    .mini-stat-lbl  { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

    /* two-col */
    .main-cols { display: grid; grid-template-columns: 360px 1fr; gap: 20px; align-items: start; }

    /* form card */
    .form-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 18px; padding: 28px; display: flex; flex-direction: column; gap: 14px; }
    .form-card h2 { font-size: 15px; font-weight: 700; }
    .field-group { display: flex; flex-direction: column; gap: 6px; }
    .field-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; }
    .field-input, .field-select {
      padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px;
      font-family: 'Poppins', sans-serif; font-size: 13px;
      background: #fdf6ec; color: var(--text-main); outline: none;
      transition: border-color .15s; width: 100%;
    }
    .field-input:focus, .field-select:focus { border-color: var(--accent); background: #fff; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .pw-wrap { position: relative; }
    .pw-wrap .field-input { padding-right: 40px; }
    .pw-eye { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 15px; color: var(--text-muted); }

    .role-pills { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .role-pill-opt { padding: 10px; border: 2px solid var(--border); border-radius: 10px; background: #fdf6ec; cursor: pointer; text-align: center; transition: all .15s; font-size: 13px; font-weight: 600; color: var(--text-muted); }
    .role-pill-opt.selected { border-color: var(--accent); background: var(--accent-lt); color: var(--accent); }

    .submit-btn { width: 100%; padding: 13px; background: var(--accent); color: #fff; border: none; border-radius: 14px; font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700; cursor: pointer; transition: background .15s, transform .12s; letter-spacing: .03em; margin-top: 4px; }
    .submit-btn:hover { background: #7a4e2e; transform: translateY(-2px); }
    .submit-btn:active { transform: scale(.98); }

    /* table card */
    .table-card { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 18px; overflow: hidden; }
    .table-toolbar { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1.5px solid var(--border); flex-wrap: wrap; }
    .table-toolbar h2 { font-size: 15px; font-weight: 700; flex: 1; }
    .search-wrap { position: relative; }
    .search-wrap input { padding: 9px 14px 9px 34px; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'Poppins', sans-serif; font-size: 13px; background: #fdf6ec; outline: none; width: 200px; transition: border-color .15s; }
    .search-wrap input:focus { border-color: var(--accent); background: #fff; }
    .search-wrap .s-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; }
    .filter-select { padding: 9px 10px; border: 1.5px solid var(--border); border-radius: 9px; font-family: 'Poppins', sans-serif; font-size: 12px; background: #fdf6ec; color: var(--text-main); outline: none; cursor: pointer; }

    table { width: 100%; border-collapse: collapse; }
    thead tr { background: #fdf6ec; }
    th { padding: 11px 16px; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; text-align: left; border-bottom: 1.5px solid var(--border); white-space: nowrap; }
    td { padding: 13px 16px; font-size: 13px; border-bottom: 1px solid #f5ede0; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fffaf5; }

    .avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
    .user-cell { display: flex; align-items: center; gap: 10px; }
    .user-name  { font-weight: 600; font-size: 13px; }
    .user-meta  { font-size: 11px; color: var(--text-muted); }

    .badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
    .badge-active  { background: var(--green-lt); color: var(--green); }
    .badge-blocked { background: var(--red-lt);   color: var(--red); }
    .badge-on_hold { background: var(--amber-lt); color: var(--amber); }
    .badge-staff   { background: var(--blue-lt);  color: var(--blue); }
    .badge-admin   { background: var(--accent-lt);color: var(--accent); }

    .act-group { display: flex; gap: 5px; flex-wrap: wrap; }
    .act-btn { padding: 6px 10px; border-radius: 8px; border: 1.5px solid transparent; font-family: 'Poppins', sans-serif; font-size: 11px; font-weight: 600; cursor: pointer; transition: all .14s; white-space: nowrap; }
    .act-edit     { background: var(--blue-lt);   color: var(--blue);   border-color: #bbdefb; }
    .act-block    { background: var(--red-lt);    color: var(--red);    border-color: #ffcdd2; }
    .act-hold     { background: var(--amber-lt);  color: var(--amber);  border-color: #ffe0b2; }
    .act-activate { background: var(--green-lt);  color: var(--green);  border-color: #c8e6c9; }
    .act-delete   { background: #f5f5f5; color: #757575; border-color: #e0e0e0; }
    .act-btn:hover { filter: brightness(.92); transform: translateY(-1px); }

    .empty-row td { text-align: center; padding: 44px; color: var(--text-muted); font-size: 14px; }

    /* toast */
    .toast { position: fixed; bottom: 28px; right: 28px; z-index: 9999; padding: 14px 20px; border-radius: 12px; font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif; box-shadow: 0 4px 20px rgba(0,0,0,.14); animation: slideUp .3s ease; max-width: 340px; transition: opacity .4s; }
    .toast-success { background: var(--green-lt); color: var(--green); border: 1.5px solid #c8e6c9; }
    .toast-error   { background: var(--red-lt);   color: var(--red);   border: 1.5px solid #ffcdd2; }
    @keyframes slideUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

    /* modal */
    .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
    .modal-bg.open { display: flex; }
    .modal { background: var(--card-bg); border-radius: 20px; padding: 30px; width: 100%; max-width: 480px; box-shadow: 0 12px 48px rgba(0,0,0,.18); animation: popIn .22s ease; max-height: 90vh; overflow-y: auto; }
    @keyframes popIn { from { opacity:0; transform:scale(.93); } to { opacity:1; transform:scale(1); } }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-header h3 { font-size: 16px; font-weight: 700; }
    .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); line-height: 1; }
    .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
    .btn-cancel { flex: 1; padding: 12px; border: 1.5px solid var(--border); border-radius: 12px; background: none; font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; color: var(--text-muted); }
    .btn-cancel:hover { background: #f5ede0; }
    .btn-save { flex: 2; padding: 12px; background: var(--accent); color: #fff; border: none; border-radius: 12px; font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save:hover { background: #7a4e2e; }

    .mg-b { margin-bottom: 12px; }

    @media (max-width: 900px) {
      .main-cols { grid-template-columns: 1fr; }
      .stat-row  { grid-template-columns: repeat(2,1fr); }
    }
  </style>
</head>
<body>

<?php include('../includes/admin_sidebar.php'); ?>

<div id="page-users" class="page active">
  <div class="page-header">
    <div>
      <h1>Manage Users</h1>
      <p>Register new staff and manage all accounts</p>
    </div>
  </div>

  <div class="page-body">
    <div class="users-wrap">

      <!-- Stat cards -->
      <div class="stat-row">
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:#fdf3ea">👥</div>
          <div><div class="mini-stat-val"><?= $total ?></div><div class="mini-stat-lbl">Total Users</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--green-lt)">✅</div>
          <div><div class="mini-stat-val"><?= $active ?></div><div class="mini-stat-lbl">Active</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--amber-lt)">⏸️</div>
          <div><div class="mini-stat-val"><?= $on_hold ?></div><div class="mini-stat-lbl">On Hold</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--red-lt)">🚫</div>
          <div><div class="mini-stat-val"><?= $blocked ?></div><div class="mini-stat-lbl">Blocked</div></div>
        </div>
      </div>

      <div class="main-cols">

        <!-- LEFT: Register form -->
        <div class="form-card">
          <h2>➕ Register New User</h2>
          <form method="POST">
            <input type="hidden" name="action" value="register"/>
            <input type="hidden" name="role" id="reg-role-val" value="staff"/>

            <div class="field-group mg-b">
              <label class="field-label">Role</label>
              <div class="role-pills">
                <div class="role-pill-opt selected" onclick="setRegRole('staff',this)">👤 Staff</div>
                <div class="role-pill-opt"           onclick="setRegRole('admin',this)">🛡️ Admin</div>
              </div>
            </div>

            <div class="field-group mg-b">
              <label class="field-label">Username <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="text" name="username" placeholder="e.g. jdelacruz" required/>
            </div>

            <div class="field-row mg-b">
              <div class="field-group">
                <label class="field-label">First Name <span style="color:var(--red)">*</span></label>
                <input class="field-input" type="text" name="firstname" placeholder="Juan" required/>
              </div>
              <div class="field-group">
                <label class="field-label">Last Name <span style="color:var(--red)">*</span></label>
                <input class="field-input" type="text" name="lastname" placeholder="dela Cruz" required/>
              </div>
            </div>

            <div class="field-group mg-b">
              <label class="field-label">Email Address <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="email" name="email" placeholder="juan@kofee.com" required/>
            </div>

            <div class="field-row mg-b">
              <div class="field-group">
                <label class="field-label">Password <span style="color:var(--red)">*</span></label>
                <div class="pw-wrap">
                  <input class="field-input" type="password" name="password" id="reg-pw" placeholder="Min. 6 chars" required/>
                  <button type="button" class="pw-eye" onclick="togglePw('reg-pw',this)">👁️</button>
                </div>
              </div>
              <div class="field-group">
                <label class="field-label">Confirm <span style="color:var(--red)">*</span></label>
                <div class="pw-wrap">
                  <input class="field-input" type="password" name="confirm_password" id="reg-cpw" placeholder="Repeat" required/>
                  <button type="button" class="pw-eye" onclick="togglePw('reg-cpw',this)">👁️</button>
                </div>
              </div>
            </div>

            <button type="submit" class="submit-btn">➕ Register User</button>
          </form>
        </div>

        <!-- RIGHT: Users table -->
        <div class="table-card">
          <div class="table-toolbar">
            <h2>All Users</h2>
            <form method="GET" style="display:contents">
              <div class="search-wrap">
                <span class="s-icon">🔍</span>
                <input type="text" name="search" placeholder="Name, username, email…"
                       value="<?= htmlspecialchars($search) ?>"/>
              </div>
              <select class="filter-select" name="filter" onchange="this.form.submit()">
                <option value="all"     <?= $filter==='all'     ?'selected':'' ?>>All</option>
                <option value="staff"   <?= $filter==='staff'   ?'selected':'' ?>>Staff</option>
                <option value="admin"   <?= $filter==='admin'   ?'selected':'' ?>>Admin</option>
                <option value="active"  <?= $filter==='active'  ?'selected':'' ?>>Active</option>
                <option value="on_hold" <?= $filter==='on_hold' ?'selected':'' ?>>On Hold</option>
                <option value="blocked" <?= $filter==='blocked' ?'selected':'' ?>>Blocked</option>
              </select>
              <button type="submit" class="act-btn act-activate">Search</button>
            </form>
          </div>

          <table>
            <thead>
              <tr>
                <th>User</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
              <tr class="empty-row"><td colspan="6">🫙 No users found.</td></tr>
            <?php else:
              $colors = ['#c47d3e','#2e7d32','#1565c0','#7b1fa2','#c62828','#00695c'];
              foreach ($users as $i => $u):
                $fullname = htmlspecialchars($u['firstname'] . ' ' . $u['lastname']);
                $initials = strtoupper(substr($u['firstname'], 0, 1) . substr($u['lastname'], 0, 1));
                $color    = $colors[$i % count($colors)];
                $status   = $u['status'] ?? 'active';
                $slabel   = ['active'=>'Active','blocked'=>'Blocked','on_hold'=>'On Hold'][$status] ?? $status;
                $is_self  = ((int)$u['id'] === (int)$_SESSION['user_id']);
                $lastlogin = $u['lastlogin'] ?? $u['last_login'] ?? null;
            ?>
              <tr>
                <td>
                  <div class="user-cell">
                    <div class="avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                    <div>
                      <div class="user-name">
                        <?= $fullname ?>
                        <?= $is_self ? '<span style="font-size:10px;color:var(--text-muted)"> (you)</span>' : '' ?>
                      </div>
                      <div class="user-meta"><?= htmlspecialchars($u['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="color:var(--text-muted);font-size:12px">@<?= htmlspecialchars($u['username']) ?></td>
                <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                <td><span class="badge badge-<?= $status ?>"><?= $slabel ?></span></td>
                <td style="color:var(--text-muted);font-size:12px">
                  <?= $lastlogin ? date('M d, Y g:i A', strtotime($lastlogin)) : '—' ?>
                </td>
                <td>
                  <div class="act-group">
                    <button class="act-btn act-edit"
                      onclick="openEdit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">✏️ Edit</button>

                    <?php if (!$is_self): ?>
                      <?php if ($status !== 'active'): ?>
                        <form method="POST" style="display:inline">
                          <input type="hidden" name="action"  value="set_status"/>
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                          <input type="hidden" name="status"  value="active"/>
                          <button type="submit" class="act-btn act-activate">✅ Activate</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($status !== 'on_hold'): ?>
                        <form method="POST" style="display:inline">
                          <input type="hidden" name="action"  value="set_status"/>
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                          <input type="hidden" name="status"  value="on_hold"/>
                          <button type="submit" class="act-btn act-hold">⏸️ Hold</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($status !== 'blocked'): ?>
                        <form method="POST" style="display:inline">
                          <input type="hidden" name="action"  value="set_status"/>
                          <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                          <input type="hidden" name="status"  value="blocked"/>
                          <button type="submit" class="act-btn act-block">🚫 Block</button>
                        </form>
                      <?php endif; ?>
                      <button class="act-btn act-delete"
                        onclick="confirmDelete(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($fullname, ENT_QUOTES) ?>')">🗑️</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div><!-- /main-cols -->
    </div>
  </div>
</div>

<!-- Edit modal -->
<div class="modal-bg" id="edit-modal" onclick="closeModalBg(event)">
  <div class="modal">
    <div class="modal-header">
      <h3>✏️ Edit User</h3>
      <button class="modal-close" onclick="closeEdit()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action"  value="edit"/>
      <input type="hidden" name="user_id" id="edit-id"/>
      <input type="hidden" name="role"    id="edit-role-val" value="staff"/>

      <div class="field-group mg-b">
        <label class="field-label">Role</label>
        <div class="role-pills">
          <div class="role-pill-opt" id="edit-pill-staff" onclick="setEditRole('staff',this)">👤 Staff</div>
          <div class="role-pill-opt" id="edit-pill-admin" onclick="setEditRole('admin',this)">🛡️ Admin</div>
        </div>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Username</label>
        <input class="field-input" type="text" name="username" id="edit-username" required/>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">First Name</label>
          <input class="field-input" type="text" name="firstname" id="edit-firstname" required/>
        </div>
        <div class="field-group">
          <label class="field-label">Last Name</label>
          <input class="field-input" type="text" name="lastname" id="edit-lastname" required/>
        </div>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Email</label>
        <input class="field-input" type="email" name="email" id="edit-email" required/>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">New Password
          <span style="color:var(--text-muted);font-weight:400;text-transform:none;font-size:10px"> — leave blank to keep current</span>
        </label>
        <div class="pw-wrap">
          <input class="field-input" type="password" name="password" id="edit-pw" placeholder="••••••"/>
          <button type="button" class="pw-eye" onclick="togglePw('edit-pw',this)">👁️</button>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeEdit()">Cancel</button>
        <button type="submit" class="btn-save">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-bg" id="delete-modal" onclick="closeModalBg(event)">
  <div class="modal" style="max-width:360px;text-align:center">
    <div style="font-size:46px;margin-bottom:12px">🗑️</div>
    <h3 style="font-size:17px;margin-bottom:8px">Delete User?</h3>
    <p id="delete-msg" style="font-size:13px;color:var(--text-muted);margin-bottom:22px"></p>
    <form method="POST">
      <input type="hidden" name="action"  value="delete"/>
      <input type="hidden" name="user_id" id="delete-id"/>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeDelete()">Cancel</button>
        <button type="submit" class="btn-save" style="background:var(--red)">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(() => { const t = document.getElementById('toast-msg'); if(t) t.style.opacity='0'; }, 3500);</script>
<?php endif; ?>

<script>
  function setRegRole(role, el) {
    document.getElementById('reg-role-val').value = role;
    document.querySelectorAll('#page-users .form-card .role-pills .role-pill-opt')
      .forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
  }

  function setEditRole(role, el) {
    document.getElementById('edit-role-val').value = role;
    document.getElementById('edit-pill-staff').classList.remove('selected');
    document.getElementById('edit-pill-admin').classList.remove('selected');
    el.classList.add('selected');
  }

  function togglePw(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁️' : '🙈';
  }

  function openEdit(u) {
    document.getElementById('edit-id').value        = u.id;
    document.getElementById('edit-username').value  = u.username;
    document.getElementById('edit-firstname').value = u.firstname;
    document.getElementById('edit-lastname').value  = u.lastname;
    document.getElementById('edit-email').value     = u.email;
    document.getElementById('edit-pw').value        = '';
    // Set role pill
    document.getElementById('edit-pill-staff').classList.remove('selected');
    document.getElementById('edit-pill-admin').classList.remove('selected');
    document.getElementById('edit-pill-' + u.role).classList.add('selected');
    document.getElementById('edit-role-val').value = u.role;
    document.getElementById('edit-modal').classList.add('open');
  }
  function closeEdit()   { document.getElementById('edit-modal').classList.remove('open'); }

  function confirmDelete(id, name) {
    document.getElementById('delete-id').value  = id;
    document.getElementById('delete-msg').textContent =
      'This will permanently remove "' + name + '". This cannot be undone.';
    document.getElementById('delete-modal').classList.add('open');
  }
  function closeDelete() { document.getElementById('delete-modal').classList.remove('open'); }

  function closeModalBg(e) {
    if (e.target === e.currentTarget) { closeEdit(); closeDelete(); }
  }
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeEdit(); closeDelete(); }
  });
</script>

</body>
</html>