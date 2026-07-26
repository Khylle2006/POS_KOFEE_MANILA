<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();

$user = current_user();
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

include("../includes/sidebar.php");

$roles       = get_all_roles();              // [{role_key, label, is_system}, ...]
$permissions = get_all_permissions();         // [{perm_key, label, category, description}, ...]
$grants      = get_all_role_permissions();    // role => [perm_key, ...]

// Group permissions by category for display
$grouped = [];
foreach ($permissions as $p) {
    $grouped[$p['category']][] = $p;
}

// Editable roles = every role except admin (admin always has everything)
$editableRoles = array_values(array_filter($roles, fn($r) => $r['role_key'] !== 'admin'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Permissions — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --accent:     #c47d3e;
      --accent-lt:  #fdf3ea;
      --card-bg:    #ffffff;
      --border:     #ecddc8;
      --text-main:  #2c1a0e;
      --text-muted: #9a7e65;
      --bg:         #faf5ef;
      --cream:      #fdf6ec;
      --green:      #2e7d32; --green-lt: #e8f5e9;
      --red:        #c62828; --red-lt:   #ffebee;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-main); }

    #page-permissions { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
    .page-header { padding: 22px 28px 0; flex-shrink: 0; }
    .page-header h1 { font-size: 22px; font-weight: 800; }
    .page-header p  { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
    .page-body { flex: 1; overflow-y: auto; padding: 18px 28px 28px; display: flex; flex-direction: column; gap: 20px; }

    /* ── Add role card ── */
    .add-role-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 16px; padding: 18px 20px;
      display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
    }
    .add-role-card h2 { flex-basis: 100%; font-size: 14px; font-weight: 700; margin-bottom: 4px; }
    .ar-field { display: flex; flex-direction: column; gap: 5px; }
    .ar-field label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
    .ar-field input {
      padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 9px;
      font-family: 'Poppins', sans-serif; font-size: 13px; background: var(--cream);
      outline: none; min-width: 180px;
    }
    .ar-field input:focus { border-color: var(--accent); background: #fff; }
    .btn-add-role {
      padding: 10px 20px; background: var(--accent); color: #fff; border: none;
      border-radius: 10px; font-family: 'Poppins', sans-serif; font-size: 13px;
      font-weight: 700; cursor: pointer; transition: background .15s;
    }
    .btn-add-role:hover { background: #7a4e2e; }
    .ar-msg { font-size: 12px; font-weight: 600; }
    .ar-msg.error   { color: var(--red); }
    .ar-msg.success { color: var(--green); }

    /* ── Matrix ── */
    .matrix-card {
      background: var(--card-bg); border: 1.5px solid var(--border);
      border-radius: 16px; overflow: auto; flex: 1;
    }
    table.perm-matrix { border-collapse: collapse; width: 100%; min-width: 640px; }
    .perm-matrix th, .perm-matrix td {
      padding: 10px 14px; border-bottom: 1px solid #f5ede0; text-align: left; font-size: 13px;
      white-space: nowrap;
    }
    .perm-matrix thead th {
      background: #f5ece0; position: sticky; top: 0; z-index: 2;
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
      color: var(--text-muted);
    }
    .perm-matrix th.perm-col-head {
      text-align: left; min-width: 260px; position: sticky; left: 0; z-index: 3; background: #f5ece0;
    }
    .perm-matrix td.perm-name-cell {
      position: sticky; left: 0; background: #fff; z-index: 1;
      max-width: 300px; white-space: normal;
    }
    .perm-matrix tr:hover td { background: #fffaf5; }
    .perm-matrix tr:hover td.perm-name-cell { background: #fffaf5; }

    .role-col-head { text-align: center !important; }
    .role-col-name { font-weight: 700; color: var(--text-main); font-size: 12px; }
    .role-col-del {
      display: block; margin: 4px auto 0; background: none; border: none;
      color: var(--red); font-size: 10px; font-weight: 700; cursor: pointer;
      text-transform: none; letter-spacing: 0;
    }
    .role-col-del:hover { text-decoration: underline; }
    .role-col-system { font-size: 10px; color: var(--text-muted); font-weight: 600; }

    .cat-row td {
      background: var(--cream); font-size: 11px; font-weight: 800;
      text-transform: uppercase; letter-spacing: .08em; color: var(--text-muted);
      position: sticky; left: 0;
    }

    .perm-label { font-weight: 700; font-size: 13px; }
    .perm-desc  { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

    .cell-check { text-align: center; }
    .toggle-switch {
      width: 38px; height: 21px; background: var(--border); border-radius: 12px;
      cursor: pointer; position: relative; transition: background .2s; display: inline-block;
    }
    .toggle-switch.on { background: var(--accent); }
    .toggle-switch::after {
      content: ''; position: absolute; width: 15px; height: 15px; background: #fff;
      border-radius: 50%; top: 3px; left: 3px; transition: left .2s; box-shadow: 0 1px 4px rgba(0,0,0,.2);
    }
    .toggle-switch.on::after { left: 20px; }
    .toggle-switch.disabled { opacity: .4; cursor: not-allowed; }

    .admin-pill {
      display: inline-block; padding: 3px 10px; border-radius: 20px;
      background: var(--green-lt); color: var(--green); font-size: 10px; font-weight: 700;
    }

    .toast {
      position: fixed; bottom: 24px; right: 24px; z-index: 9999;
      padding: 12px 18px; border-radius: 12px; font-size: 13px; font-weight: 600;
      box-shadow: 0 4px 20px rgba(0,0,0,.14); transition: opacity .4s;
    }
    .toast-success { background: var(--green-lt); color: var(--green); border: 1.5px solid #c8e6c9; }
    .toast-error   { background: var(--red-lt);   color: var(--red);   border: 1.5px solid #ffcdd2; }
  </style>
</head>
<body>

<div id="page-permissions" class="page active">
  <div class="page-header">
    <h1>Manage Permissions</h1>
    <p>Add or remove roles, and control exactly what each role can do</p>
  </div>

  <div class="page-body">

    <!-- ── Add role ── -->
    <div class="add-role-card">
      <h2>➕ Add a New Role</h2>
      <div class="ar-field">
        <label>Role Key</label>
        <input type="text" id="new-role-key" placeholder="e.g. supervisor"/>
      </div>
      <div class="ar-field">
        <label>Display Name</label>
        <input type="text" id="new-role-label" placeholder="e.g. Shift Supervisor"/>
      </div>
      <button class="btn-add-role" onclick="addRole()">➕ Create Role</button>
      <span class="ar-msg" id="ar-msg"></span>
    </div>

    <!-- ── Matrix ── -->
    <div class="matrix-card">
      <table class="perm-matrix">
        <thead>
          <tr>
            <th class="perm-col-head">Permission</th>
            <th class="role-col-head">
              <div class="role-col-name">Admin</div>
              <span class="admin-pill">Always Allowed</span>
            </th>
            <?php foreach ($editableRoles as $r): ?>
              <th class="role-col-head">
                <div class="role-col-name"><?= htmlspecialchars($r['label']) ?></div>
                <?php if ($r['is_system']): ?>
                  <span class="role-col-system">System role</span>
                <?php else: ?>
                  <button class="role-col-del" onclick="deleteRole('<?= htmlspecialchars($r['role_key']) ?>','<?= htmlspecialchars($r['label']) ?>')">🗑 Remove role</button>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grouped as $category => $perms): ?>
            <tr class="cat-row">
              <td colspan="<?= 2 + count($editableRoles) ?>"><?= htmlspecialchars($category) ?></td>
            </tr>
            <?php foreach ($perms as $p): ?>
              <tr>
                <td class="perm-name-cell">
                  <div class="perm-label"><?= htmlspecialchars($p['label']) ?></div>
                  <?php if ($p['description']): ?>
                    <div class="perm-desc"><?= htmlspecialchars($p['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="cell-check"><div class="toggle-switch on disabled" title="Admin always has full access"></div></td>
                <?php foreach ($editableRoles as $r):
                  $isOn = in_array($p['perm_key'], $grants[$r['role_key']] ?? []);
                ?>
                  <td class="cell-check">
                    <div class="toggle-switch <?= $isOn ? 'on' : '' ?>"
                         data-role="<?= htmlspecialchars($r['role_key']) ?>"
                         data-perm="<?= htmlspecialchars($p['perm_key']) ?>"
                         onclick="togglePermission(this)"></div>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<script>
function showToast(msg, type = 'success') {
  const existing = document.getElementById('rbac-toast');
  if (existing) existing.remove();
  const t = document.createElement('div');
  t.id = 'rbac-toast';
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
}

// ── Toggle a permission ──────────────────────
function togglePermission(el) {
  if (el.classList.contains('disabled')) return;
  const role = el.dataset.role;
  const perm = el.dataset.perm;
  const turningOn = !el.classList.contains('on');

  // optimistic UI
  el.classList.toggle('on');

  fetch('save_permissions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ role, perm_key: perm, granted: turningOn })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) {
      el.classList.toggle('on'); // revert
      showToast('⚠️ ' + res.error, 'error');
    }
  })
  .catch(() => {
    el.classList.toggle('on'); // revert
    showToast('⚠️ Network error.', 'error');
  });
}

// ── Add role ──────────────────────────────────
function addRole() {
  const key   = document.getElementById('new-role-key').value.trim();
  const label = document.getElementById('new-role-label').value.trim();
  const msg   = document.getElementById('ar-msg');
  msg.textContent = '';
  msg.className = 'ar-msg';

  fetch('manage_roles.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'add', role_key: key, label })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      showToast('✅ Role "' + label + '" created!');
      location.reload();
    } else {
      msg.textContent = '⚠️ ' + res.error;
      msg.className = 'ar-msg error';
    }
  })
  .catch(() => {
    msg.textContent = '⚠️ Network error.';
    msg.className = 'ar-msg error';
  });
}

// ── Delete role ───────────────────────────────
function deleteRole(key, label) {
  if (!confirm('Remove the "' + label + '" role? This cannot be undone, and only works if no users currently have this role.')) {
    return;
  }
  fetch('manage_roles.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', role_key: key })
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      showToast('🗑️ Role "' + label + '" removed.');
      location.reload();
    } else {
      showToast('⚠️ ' + res.error, 'error');
    }
  })
  .catch(() => showToast('⚠️ Network error.', 'error'));
}
</script>

</body>
</html>