<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

$user = current_user();
$user_roles = [];
if (!empty($_SESSION['roles']) && is_array($_SESSION['roles'])) {
    $user_roles = $_SESSION['roles'];
} elseif (!empty($_SESSION['role'])) {
    $user_roles = [$_SESSION['role']];
}
if (empty($user_roles)) {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT role FROM user_roles WHERE user_id = :id UNION SELECT role FROM users WHERE id = :id AND role IS NOT NULL AND role <> ''");
        $stmt->execute([':id' => $user['id']]);
        $user_roles = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN))); 
    } catch (Throwable $e) {
        $user_roles = [];
    }
}
$is_admin = in_array('admin', $user_roles, true) || ($user['role'] ?? '') === 'admin';
if (!$is_admin) {
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

// ── Group permissions by category ──────────────
// Shown as clickable category tabs above the list so managing a role with
// lots of permissions (e.g. Procurement) doesn't mean scrolling through
// every category at once.
$permsByCategory = [];
foreach ($permissions as $p) {
    $permsByCategory[$p['category']][] = $p;
}
ksort($permsByCategory);
$categoryKeys = array_keys($permsByCategory);

$currentGrants  = $grants[$selected] ?? [];
$grantedTotal   = count(array_intersect(array_column($permissions, 'perm_key'), $currentGrants));

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
      <div class="perms-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <span>Permissions for <?= htmlspecialchars(strtoupper($selectedLabel)) ?></span>
        <?php if (!empty($permissions)): ?>
          <span id="perm-progress" style="font-size:12.5px;font-weight:600;color:var(--text-muted)">
            <?= $grantedTotal ?> of <?= count($permissions) ?> granted
          </span>
        <?php endif; ?>
      </div>

      <?php if (empty($permissions)): ?>
        <div class="perm-empty">🫙 No permissions defined yet.</div>
      <?php else: ?>

        <div style="padding:14px 0 4px">
          <input type="text" id="perm-search" class="field-input" placeholder="🔍 Search permissions…" style="margin-bottom:12px"/>

          <div id="perm-category-tabs" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
            <button type="button" class="perm-cat-tab" data-cat="all" data-active="1"
              style="padding:7px 14px;border-radius:999px;border:1px solid var(--border);background:var(--text-dark,#3a2f28);color:#fff;font-size:12.5px;font-weight:600;cursor:pointer">
              All (<span class="perm-cat-count-granted"><?= $grantedTotal ?></span>/<?= count($permissions) ?>)
            </button>
            <?php foreach ($categoryKeys as $cat):
              $catGranted = count(array_intersect(array_column($permsByCategory[$cat], 'perm_key'), $currentGrants));
            ?>
              <button type="button" class="perm-cat-tab" data-cat="<?= htmlspecialchars($cat) ?>" data-active="0"
                style="padding:7px 14px;border-radius:999px;border:1px solid var(--border);background:#fff;color:var(--text-dark,#3a2f28);font-size:12.5px;font-weight:600;cursor:pointer">
                <?= htmlspecialchars($cat) ?> (<span class="perm-cat-count-granted"><?= $catGranted ?></span>/<?= count($permsByCategory[$cat]) ?>)
              </button>
            <?php endforeach; ?>
          </div>

          <div style="display:flex;gap:8px;margin-bottom:14px">
            <button type="button" class="act-btn act-activate" onclick="setVisiblePerms(true)">✅ Grant all shown</button>
            <button type="button" class="act-btn act-block" onclick="setVisiblePerms(false)">🚫 Revoke all shown</button>
          </div>
        </div>

        <div id="perm-no-results" class="perm-empty" style="display:none">🔍 No permissions match your search.</div>

        <?php foreach ($categoryKeys as $cat): ?>
          <?php foreach ($permsByCategory[$cat] as $p):
            $isOn = in_array($p['perm_key'], $currentGrants);
          ?>
          <div class="perm-row" data-category="<?= htmlspecialchars($cat) ?>"
               data-search="<?= htmlspecialchars(strtolower($p['label'].' '.$p['description'])) ?>">
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
          <?php endforeach; ?>
        <?php endforeach; ?>

      <?php endif; ?>
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

<!-- Save changes confirm modal -->
  <div class="modal-bg" id="save-confirm-modal" onclick="if(event.target===this) closeSaveConfirm()">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <h3>💾 Save Permission Changes?</h3>
      <button class="modal-close" onclick="closeSaveConfirm()">✕</button>
    </div>
    <div class="section-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
        You're about to update permissions for <strong id="save-confirm-role"></strong>:
      </p>
      <div id="save-confirm-list"
           style="max-height:220px;overflow-y:auto;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:10px 0;margin-bottom:6px"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeSaveConfirm()">Cancel</button>
      <button type="button" class="btn-save" id="save-confirm-btn" onclick="doSaveChanges()">✔ Confirm &amp; Save</button>
    </div>
  </div>
</div>

<script>
    window.CONFIG = {
        role: <?= json_encode($selected) ?>,
        userId: <?= json_encode($user['id']) ?>,
        permissions: <?= json_encode($grants[$selected] ?? []) ?>,
        apiUrl: '../api/'
    };
</script>

<script src="../js/manage_permissions.js"></script>

<!-- Category tabs + search + bulk actions + live progress count.
     Everything here is a client-side show/hide or a simulated click on the
     existing toggle button — the actual grant/revoke state, and the save
     flow in manage_permissions.js, are never touched directly, so nothing
     about how changes get saved changes. -->
<script>
(function () {
  const tabs      = document.querySelectorAll('.perm-cat-tab');
  const searchEl  = document.getElementById('perm-search');
  const rows      = document.querySelectorAll('.perm-row');
  const noResults = document.getElementById('perm-no-results');
  const progress  = document.getElementById('perm-progress');
  let activeCat   = 'all';

  function applyFilters() {
    const q = (searchEl ? searchEl.value : '').trim().toLowerCase();
    let anyVisible = false;

    rows.forEach(function (row) {
      const matchesCat    = (activeCat === 'all' || row.dataset.category === activeCat);
      const matchesSearch = !q || row.dataset.search.includes(q);
      const show = matchesCat && matchesSearch;
      row.style.display = show ? '' : 'none';
      if (show) anyVisible = true;
    });

    if (noResults) noResults.style.display = anyVisible ? 'none' : '';
  }

  function setActiveTab(tab) {
    tabs.forEach(function (t) {
      const isActive = (t === tab);
      t.dataset.active = isActive ? '1' : '0';
      t.style.background = isActive ? 'var(--text-dark,#3a2f28)' : '#fff';
      t.style.color      = isActive ? '#fff' : 'var(--text-dark,#3a2f28)';
    });
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      activeCat = tab.dataset.cat;
      setActiveTab(tab);
      applyFilters();
    });
  });

  if (searchEl) searchEl.addEventListener('input', applyFilters);

  // Bulk grant/revoke for whatever rows are currently visible — reuses the
  // page's own togglePerm() so change-tracking for the save modal stays correct.
  window.setVisiblePerms = function (grant) {
    rows.forEach(function (row) {
      if (row.style.display === 'none') return;
      const btn = row.querySelector('.perm-toggle');
      const isOn = btn.classList.contains('on');
      if (isOn !== grant) togglePerm(btn);
    });
  };

  // Keep the "granted so far" counters honest as the user clicks toggles,
  // without needing to know how manage_permissions.js tracks state internally.
  document.querySelectorAll('.perm-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      setTimeout(updateCounts, 0); // after togglePerm's own class change runs
    });
  });

  function updateCounts() {
    const total = document.querySelectorAll('.perm-toggle').length;
    const grantedNow = document.querySelectorAll('.perm-toggle.on').length;
    if (progress) progress.textContent = grantedNow + ' of ' + total + ' granted';

    tabs.forEach(function (tab) {
      const cat = tab.dataset.cat;
      const countEl = tab.querySelector('.perm-cat-count-granted');
      if (!countEl) return;
      const scoped = cat === 'all'
        ? document.querySelectorAll('.perm-toggle.on')
        : document.querySelectorAll('.perm-row[data-category="' + CSS.escape(cat) + '"] .perm-toggle.on');
      countEl.textContent = scoped.length;
    });
  }

  applyFilters();
})();
</script>
</body>
</html>