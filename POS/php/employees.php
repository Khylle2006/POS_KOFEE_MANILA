<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Same role set as manage_users.php — keeps Employees and Accounts consistent.
// value => display label
$roles = [
    'hr'      => 'HR',
    'finance' => 'Finance',
    'crew'    => 'Crew',
    'manager' => 'Manager',
    'admin'   => 'Admin',
];
$emp_types = ['Full-time','Part-time','Contract'];

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $code    = trim($_POST['employee_code'] ?? '');
        $fn      = trim($_POST['firstname']     ?? '');
        $ln      = trim($_POST['lastname']      ?? '');
        $pos     = trim($_POST['position']      ?? '');
        $dept    = array_key_exists($_POST['department'] ?? '', $roles) ? $_POST['department'] : 'crew';
        $phone   = trim($_POST['contact_number']?? '');
        $email   = trim($_POST['email']         ?? '');
        $hire    = $_POST['hire_date']          ?? null;
        $etype   = in_array($_POST['employment_type'] ?? '', $emp_types) ? $_POST['employment_type'] : 'Full-time';
        $salary  = (float)($_POST['base_salary'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0) ?: null;

        $link_taken = false;
        if ($user_id) {
            $chk = $pdo->prepare('SELECT id FROM employees WHERE user_id = :u');
            $chk->execute([':u' => $user_id]);
            $link_taken = (bool)$chk->fetch();
        }

        if (!$code || !$fn || !$ln || !$pos) {
            $toast = '⚠️ Employee code, name, and position are required.'; $toast_type = 'error';
        } elseif ($link_taken) {
            $toast = '⚠️ That login account is already linked to another employee.'; $toast_type = 'error';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO employees (user_id, employee_code, firstname, lastname, position, department, contact_number, email, hire_date, employment_type, base_salary, status)
                     VALUES (:uid,:c,:f,:l,:p,:d,:ph,:e,:h,:et,:s,"active")'
                )->execute([
                    ':uid'=>$user_id, ':c'=>$code, ':f'=>$fn, ':l'=>$ln, ':p'=>$pos, ':d'=>$dept,
                    ':ph'=>$phone, ':e'=>$email ?: null, ':h'=>$hire ?: null, ':et'=>$etype, ':s'=>$salary,
                ]);
                $toast = '✅ Employee "' . htmlspecialchars($fn . ' ' . $ln) . '" added!';
            } catch (PDOException $e) {
                $toast = str_contains($e->getMessage(), 'Duplicate') ? '⚠️ Employee code already exists.' : '⚠️ DB error: ' . $e->getMessage();
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $fn      = trim($_POST['firstname']     ?? '');
        $ln      = trim($_POST['lastname']      ?? '');
        $pos     = trim($_POST['position']      ?? '');
        $dept    = array_key_exists($_POST['department'] ?? '', $roles) ? $_POST['department'] : 'crew';
        $phone   = trim($_POST['contact_number']?? '');
        $email   = trim($_POST['email']         ?? '');
        $hire    = $_POST['hire_date']          ?? null;
        $etype   = in_array($_POST['employment_type'] ?? '', $emp_types) ? $_POST['employment_type'] : 'Full-time';
        $salary  = (float)($_POST['base_salary'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0) ?: null;

        $link_taken = false;
        if ($user_id) {
            $chk = $pdo->prepare('SELECT id FROM employees WHERE user_id = :u AND id != :id');
            $chk->execute([':u' => $user_id, ':id' => $id]);
            $link_taken = (bool)$chk->fetch();
        }

        if (!$id || !$fn || !$ln || !$pos) {
            $toast = '⚠️ Missing required fields.'; $toast_type = 'error';
        } elseif ($link_taken) {
            $toast = '⚠️ That login account is already linked to another employee.'; $toast_type = 'error';
        } else {
            $pdo->prepare(
                'UPDATE employees SET user_id=:uid, firstname=:f, lastname=:l, position=:p, department=:d,
                 contact_number=:ph, email=:e, hire_date=:h, employment_type=:et, base_salary=:s
                 WHERE id=:id'
            )->execute([
                ':uid'=>$user_id, ':f'=>$fn, ':l'=>$ln, ':p'=>$pos, ':d'=>$dept, ':ph'=>$phone,
                ':e'=>$email ?: null, ':h'=>$hire ?: null, ':et'=>$etype, ':s'=>$salary, ':id'=>$id,
            ]);
            $toast = '✅ Employee updated!';
        }
    }

    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active','inactive'])) {
            $pdo->prepare('UPDATE employees SET status=:s WHERE id=:id')->execute([':s'=>$status, ':id'=>$id]);
            $toast = $status === 'active' ? '✅ Employee reactivated.' : '🚫 Employee marked inactive.';
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM employees WHERE id=:id')->execute([':id'=>$id]);
            $toast = '🗑️ Employee record deleted.';
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: employees.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Fetch ──────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$role_f  = $_GET['role'] ?? '';

$where  = '1=1';
$params = [];
if ($search) {
    $where .= ' AND (firstname LIKE :s OR lastname LIKE :s2 OR employee_code LIKE :s3 OR position LIKE :s4)';
    $params[':s'] = $params[':s2'] = $params[':s3'] = $params[':s4'] = "%$search%";
}
if ($role_f && array_key_exists($role_f, $roles)) {
    $where .= ' AND department = :role';
    $params[':role'] = $role_f;
}

$stmt = $pdo->prepare("SELECT * FROM employees WHERE $where ORDER BY status ASC, lastname ASC");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$total    = count($employees);
$active   = count(array_filter($employees, fn($e) => $e['status'] === 'active'));
$inactive = $total - $active;
$fulltime = count(array_filter($employees, fn($e) => $e['employment_type'] === 'Full-time'));

// Login accounts available to link (full list kept so the currently-linked
// account still shows correctly when editing)
$login_accounts = $pdo->query("SELECT id, username, firstname, lastname, role FROM users ORDER BY firstname")->fetchAll();
$linked_ids     = array_filter(array_column($employees, 'user_id'));

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Employees — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/employees.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
</head>
<body>

<div id="page-employees" class="page active">
  <div class="page-header">
    <div>
      <h1>Employees</h1>
      <p>Manage staff profiles and employment records</p>
    </div>
    <button class="btn-add" onclick="openAdd()">➕ Add Employee</button>
  </div>

  <div class="page-body">

    <div class="stat-row">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea">👥</div><div><div class="mini-stat-val"><?= $total ?></div><div class="mini-stat-lbl">Total Employees</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e8f5e9">✅</div><div><div class="mini-stat-val"><?= $active ?></div><div class="mini-stat-lbl">Active</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#ffebee">🚫</div><div><div class="mini-stat-val"><?= $inactive ?></div><div class="mini-stat-lbl">Inactive</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e3f2fd">🕒</div><div><div class="mini-stat-val"><?= $fulltime ?></div><div class="mini-stat-lbl">Full-time</div></div></div>
    </div>

    <div class="table-card">
      <div class="table-toolbar">
        <h2>All Employees</h2>
        <form method="GET" style="display:contents">
          <div class="search-wrap">
            <span class="s-icon">🔍</span>
            <input type="text" name="search" placeholder="Name, code, position…" value="<?= htmlspecialchars($search) ?>"/>
          </div>
          <select class="filter-select" name="role" onchange="this.form.submit()">
            <option value="">All Roles</option>
            <?php foreach ($roles as $val => $label): ?>
              <option value="<?= $val ?>" <?= $role_f === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="act-btn act-activate">Search</button>
        </form>
      </div>

      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr><th>Employee</th><th>Code</th><th>Position</th><th>Role</th><th>Type</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($employees)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No employees found.</td></tr>
          <?php else:
            $colors = ['#c47d3e','#2e7d32','#1565c0','#7b1fa2','#c62828','#00695c'];
            foreach ($employees as $i => $e):
              $full = htmlspecialchars($e['firstname'].' '.$e['lastname']);
              $initials = strtoupper(substr($e['firstname'],0,1).substr($e['lastname'],0,1));
              $color = $colors[$i % count($colors)];
              $role_label = $roles[$e['department']] ?? htmlspecialchars($e['department']);
          ?>
            <tr id="row-<?= $e['id'] ?>">
              <td>
                <div class="user-cell">
                  <div class="avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                  <div>
                    <div class="user-name"><?= $full ?></div>
                    <div class="user-meta"><?= htmlspecialchars($e['email'] ?: '—') ?></div>
                  </div>
                </div>
              </td>
              <td style="font-weight:700">#<?= htmlspecialchars($e['employee_code']) ?></td>
              <td><?= htmlspecialchars($e['position']) ?></td>
              <td><span class="badge badge-role-<?= htmlspecialchars($e['department']) ?>"><?= $role_label ?></span></td>
              <td><?= htmlspecialchars($e['employment_type']) ?></td>
              <td><span class="badge badge-<?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span></td>
              <td>
                <div class="act-group">
                  <button class="act-btn act-edit" onclick='openEdit(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)'>✏️ Edit</button>
                  <?php if ($e['status'] === 'active'): ?>
                    <button class="act-btn act-hold" onclick="setStatus(<?= $e['id'] ?>,'inactive')">🚫 Deactivate</button>
                  <?php else: ?>
                    <button class="act-btn act-activate" onclick="setStatus(<?= $e['id'] ?>,'active')">✅ Activate</button>
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

<!-- Add/Edit modal -->
<div class="modal-bg" id="emp-modal" onclick="closeModalBg(event)">
  <div class="modal">
    <div class="modal-header">
      <h3 id="emp-modal-title">➕ Add Employee</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <form method="POST" id="emp-form">
      <input type="hidden" name="action" id="f-action" value="add"/>
      <input type="hidden" name="id" id="f-id" value=""/>

      <div class="field-group mg-b" id="code-field">
        <label class="field-label">Employee Code <span class="req">*</span></label>
        <input class="field-input" type="text" name="employee_code" id="f-code" placeholder="e.g. EMP-0001" required/>
      </div>

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

      <div class="field-group mg-b">
        <label class="field-label">Position <span class="req">*</span></label>
        <input class="field-input" type="text" name="position" id="f-pos" placeholder="e.g. Barista" required/>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">Role</label>
          <select class="field-input" name="department" id="f-dept">
            <?php foreach ($roles as $val => $label): ?><option value="<?= $val ?>"><?= $label ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field-group">
          <label class="field-label">Employment Type</label>
          <select class="field-input" name="employment_type" id="f-etype">
            <?php foreach ($emp_types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">Contact Number</label>
          <input class="field-input" type="text" name="contact_number" id="f-phone" placeholder="09XXXXXXXXX"/>
        </div>
        <div class="field-group">
          <label class="field-label">Email</label>
          <input class="field-input" type="email" name="email" id="f-email"/>
        </div>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">Hire Date</label>
          <input class="field-input" type="date" name="hire_date" id="f-hire"/>
        </div>
        <div class="field-group">
          <label class="field-label">Base Salary (₱)</label>
          <input class="field-input" type="number" step="0.01" min="0" name="base_salary" id="f-salary"/>
        </div>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Linked Login Account
          <span style="color:var(--text-muted);font-weight:400;text-transform:none;font-size:10px"> — lets this person file their own requests/leave</span>
        </label>
        <select class="field-input" name="user_id" id="f-user">
          <option value="">— Not linked —</option>
          <?php foreach ($login_accounts as $u):
            $already_linked = in_array($u['id'], $linked_ids);
          ?>
            <option value="<?= $u['id'] ?>" data-linked="<?= $already_linked ? '1' : '0' ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
              <?= htmlspecialchars($u['firstname'].' '.$u['lastname']) ?> (@<?= htmlspecialchars($u['username']) ?> · <?= htmlspecialchars($u['role']) ?>)<?= $already_linked ? ' — already linked' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save" id="emp-save-btn">➕ Add Employee</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirm modal -->

<!-- Status change form (hidden, submitted via JS) -->
<form method="POST" id="status-form" style="display:none">
  <input type="hidden" name="action" value="set_status"/>
  <input type="hidden" name="id" id="status-id"/>
  <input type="hidden" name="status" id="status-value"/>
</form>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function openAdd() {
  document.getElementById('emp-modal-title').textContent = '➕ Add Employee';
  document.getElementById('emp-save-btn').textContent    = '➕ Add Employee';
  document.getElementById('f-action').value = 'add';
  document.getElementById('f-id').value     = '';
  document.getElementById('emp-form').reset();
  document.getElementById('code-field').style.display = '';
  document.getElementById('f-code').required = true;
  document.getElementById('f-user').value = '';
  document.getElementById('emp-modal').classList.add('open');
}

function openEdit(e) {
  document.getElementById('emp-modal-title').textContent = '✏️ Edit Employee';
  document.getElementById('emp-save-btn').textContent    = '💾 Save Changes';
  document.getElementById('f-action').value = 'edit';
  document.getElementById('f-id').value     = e.id;
  document.getElementById('f-fn').value     = e.firstname;
  document.getElementById('f-ln').value     = e.lastname;
  document.getElementById('f-pos').value    = e.position;
  document.getElementById('f-dept').value   = e.department;
  document.getElementById('f-etype').value  = e.employment_type;
  document.getElementById('f-phone').value  = e.contact_number || '';
  document.getElementById('f-email').value  = e.email || '';
  document.getElementById('f-hire').value   = e.hire_date || '';
  document.getElementById('f-salary').value = e.base_salary;
  document.getElementById('f-user').value   = e.user_id || '';
  document.getElementById('code-field').style.display = 'none';
  document.getElementById('f-code').required = false;
  document.getElementById('emp-modal').classList.add('open');
}

function closeModal() { document.getElementById('emp-modal').classList.remove('open'); }

function setStatus(id, status) {
  document.getElementById('status-id').value = id;
  document.getElementById('status-value').value = status;
  document.getElementById('status-form').submit();
}

function confirmDelete(id, name) {
  document.getElementById('del-id').value = id;
  document.getElementById('del-msg').textContent = 'This will permanently remove "' + name + '" from employee records.';
  document.getElementById('del-modal').classList.add('open');
}
function closeDelete() { document.getElementById('del-modal').classList.remove('open'); }

function closeModalBg(e) { if (e.target === e.currentTarget) { closeModal(); closeDelete(); } }
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeDelete(); } });

// Auto-suggest matching Role when a login account is linked (still editable — just a convenience default)
document.getElementById('f-user').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const role = opt?.getAttribute('data-role');
  if (role && document.getElementById('f-dept').querySelector(`option[value="${role}"]`)) {
    document.getElementById('f-dept').value = role;
  }
});
</script>

</body>
</html>