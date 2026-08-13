<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('users.manage');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Shared role list — the account "Roles" checkboxes below draw from this,
// and it also stocks the employee "Department" dropdown.
$roles = [
    'hr'      => 'HR',
    'finance' => 'Finance',
    'crew'    => 'Crew',
    'manager' => 'Manager',
    'admin'   => 'Admin',
];
$emp_types = ['Full-time', 'Part-time', 'Contract'];

// ── Multi-role support ────────────────────────
// A user can now hold more than one role, so roles live in their own
// join table instead of the single users.role column. users.role is kept
// as a "primary" role for legacy code (session/dashboard greeting etc.)
// but user_roles is the source of truth for permissions/badges.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_roles (
        id      INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role    VARCHAR(50) NOT NULL,
        UNIQUE KEY uniq_user_role (user_id, role),
        CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// One-time backfill: give every existing user their current single role
// in the new table (harmless to re-run — duplicates are ignored).
$pdo->exec("
    INSERT IGNORE INTO user_roles (user_id, role)
    SELECT id, role FROM users WHERE role IS NOT NULL AND role <> ''
");

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add or Edit a person (account +/- employee profile) ──
    if ($action === 'save') {
        $emp_id            = (int)($_POST['emp_id'] ?? 0);
        $existing_user_id  = (int)($_POST['existing_user_id'] ?? 0);
        $want_account      = isset($_POST['want_account']);
        $want_employee     = isset($_POST['want_employee']);

        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $email     = trim($_POST['email']     ?? '');

        // Multiple account roles — sanitize against the allowed list
        $roles_selected = array_values(array_intersect($_POST['roles'] ?? [], array_keys($roles)));
        $primary_role   = $roles_selected[0] ?? 'crew'; // legacy single-role column

        // Account-only fields
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Employee-only fields
        $code       = trim($_POST['employee_code']  ?? '');
        $pos        = trim($_POST['position']       ?? '');
        $department = array_key_exists($_POST['department'] ?? '', $roles) ? $_POST['department'] : $primary_role;
        $phone      = trim($_POST['contact_number'] ?? '');
        $hire       = $_POST['hire_date']           ?? null;
        $etype      = in_array($_POST['employment_type'] ?? '', $emp_types) ? $_POST['employment_type'] : 'Full-time';
        $salary     = (float)($_POST['base_salary'] ?? 0);

        if (!$firstname || !$lastname) {
            $toast = '⚠️ First and last name are required.'; $toast_type = 'error';
        } elseif (!$want_account && !$want_employee) {
            $toast = '⚠️ Enable at least a login account or an employee profile.'; $toast_type = 'error';
        } elseif ($want_account && empty($roles_selected)) {
            $toast = '⚠️ Select at least one role for the login account.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $user_id = $existing_user_id ?: null;

                // ── Account side ──
                if ($want_account) {
                    if ($existing_user_id) {
                        // Update existing account
                        if ($password !== '') {
                            if ($password !== $confirm) throw new Exception('Passwords do not match.');
                            if (strlen($password) < 6) throw new Exception('Password must be at least 6 characters.');
                            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                            $pdo->prepare('UPDATE users SET username=:u, firstname=:f, lastname=:l, email=:e, role=:r, password=:p, updated_at=NOW() WHERE id=:id')
                                ->execute([':u'=>$username, ':f'=>$firstname, ':l'=>$lastname, ':e'=>$email, ':r'=>$primary_role, ':p'=>$hash, ':id'=>$existing_user_id]);
                        } else {
                            $pdo->prepare('UPDATE users SET username=:u, firstname=:f, lastname=:l, email=:e, role=:r, updated_at=NOW() WHERE id=:id')
                                ->execute([':u'=>$username, ':f'=>$firstname, ':l'=>$lastname, ':e'=>$email, ':r'=>$primary_role, ':id'=>$existing_user_id]);
                        }
                        $user_id = $existing_user_id;
                    } else {
                        // Create new account
                        if (!$username || !$password) throw new Exception('Username and password are required to create a login account.');
                        if (strlen($password) < 6)     throw new Exception('Password must be at least 6 characters.');
                        if ($password !== $confirm)    throw new Exception('Passwords do not match.');

                        $chk = $pdo->prepare('SELECT id FROM users WHERE username=:u LIMIT 1');
                        $chk->execute([':u'=>$username]);
                        if ($chk->fetch()) throw new Exception('That username is already taken.');

                        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                        $pdo->prepare('INSERT INTO users (username, firstname, lastname, email, password, role, status, created_at, updated_at)
                                       VALUES (:u,:f,:l,:e,:p,:r,"active",NOW(),NOW())')
                            ->execute([':u'=>$username, ':f'=>$firstname, ':l'=>$lastname, ':e'=>$email, ':p'=>$hash, ':r'=>$primary_role]);
                        $user_id = (int)$pdo->lastInsertId();
                    }

                    // Keep user_roles in sync with the checkboxes selected
                    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :u')->execute([':u' => $user_id]);
                    $ins_role = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (:u, :r)');
                    foreach ($roles_selected as $rl) {
                        $ins_role->execute([':u' => $user_id, ':r' => $rl]);
                    }
                }

                // ── Employee side ──
                if ($want_employee) {
                    if ($user_id) {
                        $link_chk = $pdo->prepare('SELECT id FROM employees WHERE user_id=:u AND id != :id');
                        $link_chk->execute([':u'=>$user_id, ':id'=>$emp_id]);
                        if ($link_chk->fetch()) throw new Exception('That login account is already linked to another employee profile.');
                    }

                    if ($emp_id) {
                        if (!$pos) throw new Exception('Position is required.');
                        $pdo->prepare('UPDATE employees SET user_id=:uid, firstname=:f, lastname=:l, position=:p, department=:d,
                                       contact_number=:ph, email=:e, hire_date=:h, employment_type=:et, base_salary=:s WHERE id=:id')
                            ->execute([':uid'=>$user_id, ':f'=>$firstname, ':l'=>$lastname, ':p'=>$pos, ':d'=>$department,
                                       ':ph'=>$phone, ':e'=>$email ?: null, ':h'=>$hire ?: null, ':et'=>$etype, ':s'=>$salary, ':id'=>$emp_id]);
                    } else {
                        if (!$code || !$pos) throw new Exception('Employee code and position are required.');
                        $pdo->prepare('INSERT INTO employees (user_id, employee_code, firstname, lastname, position, department, contact_number, email, hire_date, employment_type, base_salary, status)
                                       VALUES (:uid,:c,:f,:l,:p,:d,:ph,:e,:h,:et,:s,"active")')
                            ->execute([':uid'=>$user_id, ':c'=>$code, ':f'=>$firstname, ':l'=>$lastname, ':p'=>$pos, ':d'=>$department,
                                       ':ph'=>$phone, ':e'=>$email ?: null, ':h'=>$hire ?: null, ':et'=>$etype, ':s'=>$salary]);
                    }
                }

                $pdo->commit();
                $toast = '✅ "' . htmlspecialchars($firstname . ' ' . $lastname) . '" saved successfully!';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = str_contains($e->getMessage(), 'Duplicate') ? 'That employee code already exists.' : $e->getMessage();
                $toast = '⚠️ ' . $msg; $toast_type = 'error';
            }
        }

        // AJAX callers (the Add/Edit Staff modal) get JSON back so the
        // modal can show the error inline instead of losing the form
        // on a full-page redirect.
        $is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok'      => $toast_type !== 'error',
                'message' => $toast,
            ]);
            exit;
        }
    }

    // ── Account status (active / blocked / on_hold) ──
    if ($action === 'set_account_status') {
        $id     = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active','blocked','on_hold'], true)) {
            $pdo->prepare('UPDATE users SET status=:s, updated_at=NOW() WHERE id=:id')->execute([':s'=>$status, ':id'=>$id]);
            $labels = ['active'=>'Activated','blocked'=>'Blocked','on_hold'=>'Put on Hold'];
            $toast  = '✅ Account ' . $labels[$status] . '.';
        }
    }

    // ── Employee status (active / inactive) ──
    if ($action === 'set_employee_status') {
        $id     = (int)($_POST['emp_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active','inactive'], true)) {
            $pdo->prepare('UPDATE employees SET status=:s WHERE id=:id')->execute([':s'=>$status, ':id'=>$id]);
            $toast = $status === 'active' ? '✅ Employee reactivated.' : '🚫 Employee marked inactive.';
        }
    }

    // Single redirect point for every non-AJAX POST action.
    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: ' . basename($_SERVER['PHP_SELF']) . $q);
    exit;
}

// Flash from redirect
if (!$toast && isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Combined roster (full-outer-join emulation) ──
// roles_list = comma-separated list of ALL roles assigned to the account
// (falls back to the legacy single users.role if user_roles has nothing yet).
$roster = $pdo->query("
    SELECT u.id AS user_id, u.username, u.email AS u_email,
           COALESCE(ur.roles_list, u.role) AS roles_list,
           u.status AS account_status, u.last_login,
           u.firstname AS u_fn, u.lastname AS u_ln,
           e.id AS emp_id, e.employee_code, e.position, e.department, e.contact_number,
           e.email AS e_email, e.hire_date, e.employment_type, e.base_salary, e.status AS emp_status,
           e.firstname AS e_fn, e.lastname AS e_ln
    FROM users u
    LEFT JOIN (SELECT user_id, GROUP_CONCAT(role ORDER BY role) AS roles_list FROM user_roles GROUP BY user_id) ur ON ur.user_id = u.id
    LEFT JOIN employees e ON e.user_id = u.id

    UNION

    SELECT u.id AS user_id, u.username, u.email AS u_email,
           COALESCE(ur.roles_list, u.role) AS roles_list,
           u.status AS account_status, u.last_login,
           u.firstname AS u_fn, u.lastname AS u_ln,
           e.id AS emp_id, e.employee_code, e.position, e.department, e.contact_number,
           e.email AS e_email, e.hire_date, e.employment_type, e.base_salary, e.status AS emp_status,
           e.firstname AS e_fn, e.lastname AS e_ln
    FROM employees e
    LEFT JOIN users u ON u.id = e.user_id
    LEFT JOIN (SELECT user_id, GROUP_CONCAT(role ORDER BY role) AS roles_list FROM user_roles GROUP BY user_id) ur ON ur.user_id = u.id
    WHERE e.user_id IS NULL
")->fetchAll();

// Normalize display fields (prefer employee profile name if present)
foreach ($roster as &$r) {
    $r['fn'] = $r['e_fn'] ?: $r['u_fn'];
    $r['ln'] = $r['e_ln'] ?: $r['u_ln'];
    $r['display_email'] = $r['e_email'] ?: $r['u_email'];
    // Roles this person's ACCOUNT holds (could be several); falls back to
    // the employee department when there's no account at all.
    $r['role_array'] = $r['roles_list'] ? explode(',', $r['roles_list']) : ($r['department'] ? [$r['department']] : []);
}
unset($r);

// ── Filters (applied in PHP since this is a UNION result) ──
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$filtered = array_filter($roster, function ($r) use ($search, $filter) {
    if ($search) {
        $hay = strtolower($r['fn'].' '.$r['ln'].' '.$r['username'].' '.$r['employee_code'].' '.$r['position']);
        if (!str_contains($hay, strtolower($search))) return false;
    }
    if ($filter !== 'all') {
        if (in_array($filter, ['hr','finance','crew','manager','admin'])
            && !in_array($filter, $r['role_array'])
            && $r['department'] !== $filter) return false;
        if ($filter === 'no_account'  && $r['user_id']) return false;
        if ($filter === 'no_profile'  && $r['emp_id'])  return false;
    }
    return true;
});
usort($filtered, fn($a,$b) => strcmp($a['ln'].$a['fn'], $b['ln'].$b['fn']));

$total       = count($roster);
$with_login  = count(array_filter($roster, fn($r) => $r['user_id']));
$with_profile= count(array_filter($roster, fn($r) => $r['emp_id']));
$active_acct = count(array_filter($roster, fn($r) => $r['account_status'] === 'active'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Staff — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/manage_users.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"/>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-users" class="page active">
  <div class="page-header">
    <div>
      <h1>Staff</h1>
      <p>Manage login accounts and employee profiles in one place</p>
    </div>
    <button class="btn-add-staff" onclick="openAdd()">➕ Add Staff Member</button>
  </div>

  <div class="page-body">
    <div class="users-wrap">

      <div class="stat-row">
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:#fdf3ea">👥</div>
          <div><div class="mini-stat-val"><?= $total ?></div><div class="mini-stat-lbl">Total People</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--green-lt)">🔑</div>
          <div><div class="mini-stat-val"><?= $with_login ?></div><div class="mini-stat-lbl">With Login</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--blue-lt)">🪪</div>
          <div><div class="mini-stat-val"><?= $with_profile ?></div><div class="mini-stat-lbl">Employee Profiles</div></div>
        </div>
        <div class="mini-stat">
          <div class="mini-stat-icon" style="background:var(--amber-lt)">✅</div>
          <div><div class="mini-stat-val"><?= $active_acct ?></div><div class="mini-stat-lbl">Active Accounts</div></div>
        </div>
      </div>

      <div class="table-card">
        <div class="table-toolbar">
          <h2>All Staff</h2>
          <form method="GET" style="display:contents">
            <div class="search-wrap">
              <span class="s-icon">🔍</span>
              <input type="text" name="search" placeholder="Name, username, code, position…" value="<?= htmlspecialchars($search) ?>"/>
            </div>
            <select class="filter-select" name="filter" onchange="this.form.submit()">
              <option value="all"        <?= $filter==='all'?'selected':'' ?>>All</option>
              <?php foreach ($roles as $val=>$label): ?>
                <option value="<?= $val ?>" <?= $filter===$val?'selected':'' ?>><?= $label ?></option>
              <?php endforeach; ?>
              <option value="no_account"  <?= $filter==='no_account'?'selected':'' ?>>No Login Account</option>
              <option value="no_profile"  <?= $filter==='no_profile'?'selected':'' ?>>No Employee Profile</option>
            </select>
            <button type="submit" class="act-btn act-activate">Search</button>
          </form>
        </div>

        <div class="table-scroll-wrapper">
          <table>
            <thead>
              <tr>
                <th>Person</th>
                <th>Login</th>
                <th>Code</th>
                <th>Position</th>
                <th>Role</th>
                <th>Account</th>
                <th>Employee</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($filtered)): ?>
              <tr class="empty-row"><td colspan="8">🫙 No staff found.</td></tr>
            <?php else:
              // Avatar tint matches the role badge colors — a glance at the
              // left edge of the table now tells you who's what.
              $role_colors = ['hr'=>'#6a3fa0','finance'=>'#00695c','crew'=>'#1565c0','manager'=>'#e65100','admin'=>'#c47d3e'];
              foreach ($filtered as $r):
                $full     = $r['fn'].' '.$r['ln'];
                $full_esc = htmlspecialchars($full);
                $full_js  = htmlspecialchars(json_encode($full), ENT_QUOTES);
                $initials = strtoupper(substr($r['fn'],0,1).substr($r['ln'],0,1)) ?: '?';
                $primary  = $r['role_array'][0] ?? $r['department'] ?? null;
                $color    = $role_colors[$primary] ?? '#9a7e65';
                $is_self  = ((int)$r['user_id'] === (int)$_SESSION['user_id']) && $r['user_id'];
            ?>
              <tr>
                <td>
                  <div class="user-cell">
                    <div class="avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                    <div>
                      <div class="user-name"><?= $full_esc ?><?= $is_self ? ' <span class="user-you">(you)</span>' : '' ?></div>
                      <div class="user-meta"><?= htmlspecialchars($r['display_email'] ?: '—') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <?= $r['username'] ? '@'.htmlspecialchars($r['username']) : '<span class="muted-cell">— No account</span>' ?>
                </td>
                <td style="font-weight:700"><?= $r['employee_code'] ? '#'.htmlspecialchars($r['employee_code']) : '—' ?></td>
                <td><?= htmlspecialchars($r['position'] ?: '—') ?></td>
                <td>
                  <?php if ($primary): ?>
                    <span class="role-text" style="color:<?= $color ?>"><?= htmlspecialchars(strtoupper($roles[$primary] ?? $primary)) ?></span>
                    <?php if (count($r['role_array']) > 1): ?>
                      <span class="muted-cell" title="<?= htmlspecialchars(implode(', ', array_map(fn($rl) => $roles[$rl] ?? $rl, array_slice($r['role_array'],1)))) ?>">+<?= count($r['role_array']) - 1 ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted-cell">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['account_status']): ?>
                    <span class="badge badge-<?= $r['account_status'] ?>"><?= ucfirst(str_replace('_',' ',$r['account_status'])) ?></span>
                  <?php else: ?>
                    <span class="muted-cell">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['emp_status']): ?>
                    <span class="badge badge-<?= $r['emp_status']==='active'?'active':'blocked' ?>"><?= ucfirst($r['emp_status']) ?></span>
                  <?php else: ?>
                    <span class="muted-cell">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="act-group">
                    <button class="act-btn act-edit"
                      onclick='openEdit(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>✏️ Edit</button>

                    <?php if ($r['user_id'] && !$is_self): ?>
                      <?php if ($r['account_status'] !== 'active'): ?>
                        <button type="button" class="act-btn act-activate"
                          onclick="askStatusConfirm('set_account_status', <?= (int)$r['user_id'] ?>, 'active', <?= $full_js ?>, 'account')">✅ Activate</button>
                      <?php endif; ?>
                      <?php if ($r['account_status'] !== 'blocked'): ?>
                        <button type="button" class="act-btn act-block"
                          onclick="askStatusConfirm('set_account_status', <?= (int)$r['user_id'] ?>, 'blocked', <?= $full_js ?>, 'account')">🚫 Block</button>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($r['emp_id']): ?>
                      <?php if ($r['emp_status'] === 'active'): ?>
                        <button type="button" class="act-btn act-hold"
                          onclick="askStatusConfirm('set_employee_status', <?= (int)$r['emp_id'] ?>, 'inactive', <?= $full_js ?>, 'employee')">⏸️ Deactivate</button>
                      <?php else: ?>
                        <button type="button" class="act-btn act-activate"
                          onclick="askStatusConfirm('set_employee_status', <?= (int)$r['emp_id'] ?>, 'active', <?= $full_js ?>, 'employee')">✅ Reactivate</button>
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

    </div>
  </div>
</div>

<!-- ── Add / Edit modal ── -->
<div class="modal-bg" id="staff-modal" onclick="closeModalBg(event)">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title">➕ Add Staff Member</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>

    <form method="POST" id="staff-form">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="emp_id" id="f-emp-id" value=""/>
      <input type="hidden" name="existing_user_id" id="f-user-id" value=""/>

      <div id="staff-form-error" style="display:none;margin:14px 22px 0;padding:11px 14px;border-radius:var(--radius-sm);background:var(--red-lt);color:var(--red);font-size:12.5px;font-weight:600"></div>

      <div class="modal-top-fields">
      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">First Name <span class="req">*</span></label>
          <input class="field-input" type="text" name="firstname" id="f-fn" required/>
        </div>
        <div class="field-group">
          <label class="field-label">Last Name <span class="req">*</span></label>
          <input class="field-input" type="text" name="lastname" id="f-ln" required/>
        </div>
      </div>

      <div class="field-group">
        <label class="field-label">Email</label>
        <input class="field-input" type="email" name="email" id="f-email"/>
      </div>
      </div>

      <!-- ── Login account section (toggle) ── -->
      <label class="section-toggle" for="f-want-account">
        <span class="section-toggle-icon">🔑</span>
        <span class="section-toggle-text">
          <strong>Login Account</strong>
          <small>Lets this person sign in to the POS</small>
        </span>
        <span class="toggle-switch-wrap">
          <input type="checkbox" name="want_account" id="f-want-account" checked onchange="toggleSection('account')"/>
          <span class="toggle-visual"></span>
        </span>
      </label>
      <div id="account-fields" class="section-body">
        <div class="field-group mg-b">
          <label class="field-label">Username</label>
          <input class="field-input" type="text" name="username" id="f-username"/>
        </div>
        <div class="field-group mg-b">
          <label class="field-label">Roles <span class="req">*</span>
            <span class="field-hint"> — select all that apply</span>
          </label>
          <div class="role-checks" id="f-roles">
            <?php foreach ($roles as $val=>$label): ?>
              <label class="role-check-opt">
                <input type="checkbox" name="roles[]" value="<?= $val ?>"/> <?= $label ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field-row mg-b">
          <div class="field-group">
            <label class="field-label">Password <span id="pw-hint" class="field-hint"></span></label>
            <div class="pw-wrap">
              <input class="field-input" type="password" name="password" id="f-password"/>
              <button type="button" class="pw-eye" onclick="togglePw('f-password',this)">👁️</button>
            </div>
          </div>
          <div class="field-group">
            <label class="field-label">Confirm Password</label>
            <div class="pw-wrap">
              <input class="field-input" type="password" name="confirm_password" id="f-confirm"/>
              <button type="button" class="pw-eye" onclick="togglePw('f-confirm',this)">👁️</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Employee profile section (toggle) ── -->
      <label class="section-toggle" for="f-want-employee">
        <span class="section-toggle-icon">🪪</span>
        <span class="section-toggle-text">
          <strong>Employee Profile</strong>
          <small>Position, pay, and HR details</small>
        </span>
        <span class="toggle-switch-wrap">
          <input type="checkbox" name="want_employee" id="f-want-employee" checked onchange="toggleSection('employee')"/>
          <span class="toggle-visual"></span>
        </span>
      </label>
      <div id="employee-fields" class="section-body">
        <div class="field-group mg-b" id="code-field">
          <label class="field-label">Employee Code <span class="req">*</span></label>
          <input class="field-input" type="text" name="employee_code" id="f-code" placeholder="e.g. EMP-0001"/>
        </div>
        <div class="field-group mg-b">
          <label class="field-label">Position <span class="req">*</span></label>
          <input class="field-input" type="text" name="position" id="f-pos" placeholder="e.g. Barista"/>
        </div>
        <div class="field-group mg-b">
          <label class="field-label">Department</label>
          <select class="field-input" name="department" id="f-department">
            <?php foreach ($roles as $val=>$label): ?>
              <option value="<?= $val ?>"><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row mg-b">
          <div class="field-group">
            <label class="field-label">Employment Type</label>
            <select class="field-input" name="employment_type" id="f-etype">
              <?php foreach ($emp_types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field-group">
            <label class="field-label">Contact Number</label>
            <input class="field-input" type="text" name="contact_number" id="f-phone" placeholder="09XXXXXXXXX"/>
          </div>
        </div>
        <div class="field-row mg-b">
          <div class="field-group">
            <label class>="field-label">Hire Date</label>
            <input class="field-input" type="date" name="hire_date" id="f-hire"/>
          </div>
          <div class="field-group">
            <label class="field-label">Base Salary (₱)</label>
            <input class="field-input" type="number" step="0.01" min="0" name="base_salary" id="f-salary"/>
          </div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save" id="save-btn">➕ Add Staff Member</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Status change confirm modal ── -->
<div class="modal-bg" id="status-confirm-modal" onclick="if(event.target===this) closeStatusConfirm()">
  <div class="modal" style="max-width:380px;text-align:center">
    <div style="padding:26px 22px 6px">
      <div id="sc-icon" style="font-size:44px;margin-bottom:12px">❓</div>
      <h3 id="sc-title" style="margin-bottom:8px">Confirm Action</h3>
      <p id="sc-message" style="font-size:13px;color:var(--text-muted)"></p>
    </div>
    <form method="POST" id="status-confirm-form">
      <input type="hidden" name="action" id="sc-action"/>
      <input type="hidden" name="user_id" id="sc-user-id"/>
      <input type="hidden" name="emp_id" id="sc-emp-id"/>
      <input type="hidden" name="status" id="sc-status"/>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeStatusConfirm()">Cancel</button>
        <button type="submit" class="btn-save" id="sc-confirm-btn">Confirm</button>
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