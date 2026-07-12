<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo = get_db();
$toast = '';
$toast_type = 'success';

$allowed_roles = ['hr', 'finance', 'crew', 'manager', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Register ──────────────────────────────
    if ($action === 'register') {
        $username  = trim($_POST['username']  ?? '');
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $role      = in_array($_POST['role'] ?? '', $allowed_roles) ? $_POST['role'] : 'crew';
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
        $role      = in_array($_POST['role'] ?? '', $allowed_roles) ? $_POST['role'] : 'crew';
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

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: ' . basename($_SERVER['PHP_SELF']) . $q);
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
if (in_array($filter, $allowed_roles)) {
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
  <link rel="stylesheet" href="../css/manage_users.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

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

            <div class="field-group mg-b">
              <label class="field-label">Role <span style="color:var(--red)">*</span></label>
              <select class="field-input" name="role" required>
                <option value="">— Select Role —</option>
                <option value="hr">HR</option>
                <option value="finance">Finance</option>
                <option value="crew">Crew</option>
                <option value="manager">Manager</option>
                <option value="admin">Admin</option>
              </select>
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
                <option value="hr"      <?= $filter==='hr'      ?'selected':'' ?>>HR</option>
                <option value="finance" <?= $filter==='finance' ?'selected':'' ?>>Finance</option>
                <option value="crew"    <?= $filter==='crew'    ?'selected':'' ?>>Crew</option>
                <option value="manager" <?= $filter==='manager' ?'selected':'' ?>>Manager</option>
                <option value="admin"   <?= $filter==='admin'   ?'selected':'' ?>>Admin</option>
                <option value="active"  <?= $filter==='active'  ?'selected':'' ?>>Active</option>
                <option value="on_hold" <?= $filter==='on_hold' ?'selected':'' ?>>On Hold</option>
                <option value="blocked" <?= $filter==='blocked' ?'selected':'' ?>>Blocked</option>
              </select>
              <button type="submit" class="act-btn act-activate">Search</button>
            </form>
          </div>

          <div class="table-scroll-wrapper">
          <table table="table-overflow">
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
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
          </div>
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

      <div class="field-group mg-b">
        <label class="field-label">Role</label>
        <select class="field-input" name="role" id="edit-role" required>
          <option value="hr">HR</option>
          <option value="finance">Finance</option>
          <option value="crew">Crew</option>
          <option value="manager">Manager</option>
          <option value="admin">Admin</option>
        </select>
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

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(() => { const t = document.getElementById('toast-msg'); if(t) t.style.opacity='0'; }, 3500);</script>
<?php endif; ?>

<script src="../js/manage_users.js"></script>

</body>
</html>