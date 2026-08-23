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

$roles       = get_all_roles();              // [{role_key, label, is_system}, ...]
$permissions = get_all_permissions();         // [{perm_key, label, category, description}, ...]
$grants      = get_all_role_permissions();    // role_key => [perm_key, ...]

// Admin always has everything — it's never something you "manage",
// so it doesn't appear in the picker.
$editableRoles = array_values(array_filter($roles, fn($r) => $r['role_key'] !== 'admin'));

// Which role is currently selected in the dropdown?
$selected = $_GET['role'] ?? '';
$validKeys = array_column($editableRoles, 'role_key');
if (!in_array($selected, $validKeys, true)) {
    $selected = $validKeys[0] ?? '';
}
$selectedLabel = '';
foreach ($editableRoles as $r) {
    if ($r['role_key'] === $selected) { $selectedLabel = $r['label']; break; }
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Permissions — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/manage_permissions.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
</head>
<body>

<div id="page-permissions" class="page active">
  <div class="page-header">
    <div>
      <h1>Manage Permissions</h1>
      <p>Add or remove roles, and control exactly what each role can do</p>
    </div>
  </div>

  <div class="page-body">

    <!-- ── Roles card ── -->
    <div class="roles-card">
      <div class="roles-card-head">
        <h2>🏛️ Roles</h2>
        <button class="btn-add-role" onclick="openAddRole()">➕ Add role</button>
      </div>

      <?php if (empty($editableRoles)): ?>
        <p class="muted-cell">No editable roles yet — add one to get started.</p>
      <?php else: ?>
        <label class="field-label" for="role-picker">Select a role to manage</label>
        <div class="role-picker-row">
          <form method="GET" id="role-picker-form" style="flex:1">
            <select class="role-picker" name="role" id="role-picker" onchange="document.getElementById('role-picker-form').submit()">
              <?php foreach ($editableRoles as $r): ?>
                <option value="<?= htmlspecialchars($r['role_key']) ?>" <?= $r['role_key']===$selected?'selected':'' ?>>
                  <?= htmlspecialchars($r['label']) ?><?= $r['is_system'] ? ' (system role)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <button class="btn-remove-role" onclick="removeRole()">🗑️ Remove role</button>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($selected): ?>
    <!-- ── Permissions for selected role ── -->
    <div class="perms-card">
      <div class="perms-card-head">Permissions for <?= htmlspecialchars(strtoupper($selectedLabel)) ?></div>

      <?php if (empty($permissions)): ?>
        <div class="perm-empty">🫙 No permissions defined yet.</div>
      <?php else: foreach ($permissions as $p):
        $isOn = in_array($p['perm_key'], $grants[$selected] ?? []);
      ?>
        <div class="perm-row">
          <div class="perm-text">
            <div class="perm-label"><?= htmlspecialchars($p['label']) ?></div>
            <?php if ($p['description']): ?><div class="perm-desc"><?= htmlspecialchars($p['description']) ?></div><?php endif; ?>
          </div>
          <button type="button"
            class="perm-toggle <?= $isOn ? 'on' : '' ?>"
            data-perm="<?= htmlspecialchars($p['perm_key']) ?>"
            data-original="<?= $isOn ? '1' : '0' ?>"
            onclick="togglePerm(this)"
            title="<?= $isOn ? 'Granted — click to revoke' : 'Not granted — click to grant' ?>">✓</button>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="save-row">
      <button class="btn-save-changes" id="save-btn" onclick="saveChanges()">Save changes</button>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ── Add role modal ── -->
<div class="modal-bg" id="add-role-modal" onclick="if(event.target===this) closeAddRole()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add a New Role</h3>
      <button class="modal-close" onclick="closeAddRole()">✕</button>
    </div>
    <div class="section-body">
      <div class="field-group mg-b">
        <label class="field-label">Role Key <span class="req">*</span> <span class="field-hint">— lowercase, no spaces</span></label>
        <input class="field-input" type="text" id="new-role-key" placeholder="e.g. supervisor"/>
      </div>
      <div class="field-group mg-b">
        <label class="field-label">Display Name <span class="req">*</span></label>
        <input class="field-input" type="text" id="new-role-label" placeholder="e.g. Shift Supervisor"/>
      </div>
      <span class="ar-msg" id="ar-msg"></span>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeAddRole()">Cancel</button>
      <button type="button" class="btn-save" onclick="addRole()">➕ Create Role</button>
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

// ── Toggle a permission (local only — Save changes commits it) ──
function togglePerm(el) {
  el.classList.toggle('on');
}

// ── Save changes: diff every row against its original state,
//    then push each change through the existing per-permission endpoint ──
function saveChanges() {
  const role = <?= json_encode($selected) ?>;
  const rows = [...document.querySelectorAll('.perm-toggle')];
  const changed = rows.filter(el => (el.classList.contains('on') ? '1' : '0') !== el.dataset.original);

  if (!changed.length) { showToast('Nothing to save.'); return; }

  const btn = document.getElementById('save-btn');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  Promise.all(changed.map(el => {
    const granted = el.classList.contains('on');
    return fetch('save_permissions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role, perm_key: el.dataset.perm, granted })
    }).then(r => r.json()).then(res => ({ el, granted, res }));
  }))
  .then(results => {
    let failed = 0;
    results.forEach(({ el, granted, res }) => {
      if (res.ok) {
        el.dataset.original = granted ? '1' : '0';
      } else {
        failed++;
        el.classList.toggle('on'); // revert this one
      }
    });
    btn.disabled = false;
    btn.textContent = 'Save changes';
    if (failed) showToast('⚠️ ' + failed + ' change(s) failed to save.', 'error');
    else showToast('✅ Changes saved!');
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    showToast('⚠️ Network error — nothing was saved.', 'error');
  });
}

// ── Add role ──
function openAddRole() {
  document.getElementById('new-role-key').value = '';
  document.getElementById('new-role-label').value = '';
  document.getElementById('ar-msg').textContent = '';
  document.getElementById('add-role-modal').classList.add('open');
}
function closeAddRole() { document.getElementById('add-role-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAddRole(); });

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
      window.location.href = 'manage_permissions.php?role=' + encodeURIComponent(key);
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

// ── Remove the currently-selected role ──
function removeRole() {
  const select = document.getElementById('role-picker');
  const key   = select.value;
  const label = select.options[select.selectedIndex].text;
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
      window.location.href = 'manage_permissions.php';
    } else {
      showToast('⚠️ ' + res.error, 'error');
    }
  })
  .catch(() => showToast('⚠️ Network error.', 'error'));
}
</script>

</body>
</html>