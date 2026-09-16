<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('permissions.manage');

// Ensure database tables and initial defaults (including procurement) exist
install_default_permissions();

$user = current_user();
$userRoles = $_SESSION['roles'] ?? (isset($_SESSION['role']) ? [$_SESSION['role']] : []);
$isAdmin = in_array('admin', $userRoles, true) || ($user['role'] ?? '') === 'admin';

if (!$isAdmin && !has_permission('permissions.manage')) {
    header('Location: dashboard.php');
    exit;
}

$allRoles    = get_all_roles();           // [{role_key, label, is_system}, ...]
$permissions = get_all_permissions();     // [{perm_key, label, category, description}, ...]
$grants      = get_all_role_permissions(); // role_key => [perm_key, ...]

// Admin always holds all permissions — only non-admin roles are configured
$editableRoles = array_values(array_filter($allRoles, fn($r) => $r['role_key'] !== 'admin'));

// Determine selected role from URL query param
$selected = $_GET['role'] ?? '';
$validKeys = array_column($editableRoles, 'role_key');
if (!in_array($selected, $validKeys, true)) {
    $selected = $validKeys[0] ?? '';
}

$selectedRole = null;
foreach ($editableRoles as $r) {
    if ($r['role_key'] === $selected) {
        $selectedRole = $r;
        break;
    }
}
$selectedLabel = $selectedRole['label'] ?? ucfirst($selected);
$isSystemRole  = (bool)($selectedRole['is_system'] ?? false);

// Standard category icons & grouping
$categoryIcons = [
    'Procurement' => '🛒',
    'Orders'      => '💳',
    'Inventory'   => '📦',
    'Menu'        => '☕',
    'Reports'     => '📊',
    'Users'       => '👥',
    'Hr'          => '⏰',
    'Settings'    => '🔐',
    'General'     => '🏠',
];

$permsByCategory = [];
foreach ($permissions as $p) {
    $cat = ucfirst(strtolower(trim($p['category'] ?? 'General')));
    if ($cat === 'Hr') $cat = 'HR';
    $permsByCategory[$cat][] = $p;
}

// Preferred category presentation order
$categoryOrder = ['Procurement', 'Orders', 'Inventory', 'Menu', 'Reports', 'Users', 'HR', 'Settings', 'General'];
uksort($permsByCategory, function($a, $b) use ($categoryOrder) {
    $posA = array_search($a, $categoryOrder);
    $posB = array_search($b, $categoryOrder);
    if ($posA === false) $posA = 99;
    if ($posB === false) $posB = 99;
    return $posA <=> $posB ?: strcmp($a, $b);
});

$currentGrants   = $grants[$selected] ?? [];
$allPermKeys     = array_column($permissions, 'perm_key');
$grantedTotal    = count(array_intersect($allPermKeys, $currentGrants));
$totalPermsCount = count($permissions);
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-permissions" class="page active">
  <div class="page-header">
    <div>
      <h1>Manage Permissions</h1>
      <p>Configure role-based access control (RBAC), POS operations, and complete procurement workflows</p>
    </div>
  </div>

  <div class="page-body">

    <!-- ── Roles Card ── -->
    <div class="roles-card">
      <div class="roles-card-head">
        <h2>🏛️ System &amp; Custom Roles</h2>
        <button type="button" class="btn-add-role" onclick="openAddRole()">➕ Add New Role</button>
      </div>

      <?php if (empty($editableRoles)): ?>
        <p class="muted-cell">No editable roles found — click Add Role to create one.</p>
      <?php else: ?>
        <label class="field-label" for="role-picker">
          Select a role to inspect and edit permissions
          <?php if ($selectedRole): ?>
            <span class="role-info-badge">
              <?= $isSystemRole ? '🔒 System Role' : '✨ Custom Role' ?>
            </span>
          <?php endif; ?>
        </label>
        <div class="role-picker-row">
          <form method="GET" id="role-picker-form" style="flex:1">
            <select class="role-picker" name="role" id="role-picker" onchange="document.getElementById('role-picker-form').submit()">
              <?php foreach ($editableRoles as $r):
                $rGrants = $grants[$r['role_key']] ?? [];
                $rCount = count(array_intersect($allPermKeys, $rGrants));
              ?>
                <option value="<?= htmlspecialchars($r['role_key']) ?>" <?= $r['role_key'] === $selected ? 'selected' : '' ?>>
                  <?= htmlspecialchars($r['label']) ?><?= $r['is_system'] ? ' (system role)' : '' ?> — <?= $rCount ?> active permissions
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <button type="button" class="btn-remove-role" id="btn-remove-role" onclick="removeRole()"
                  <?= $isSystemRole ? 'disabled style="opacity:0.4;cursor:not-allowed;" title="System roles cannot be removed"' : 'title="Delete custom role"' ?>>
            🗑️ Remove Role
          </button>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($selected): ?>
    <!-- ── Permissions Card for Selected Role ── -->
    <div class="perms-card" style="margin-top: 18px;">
      <div class="perms-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span>PERMISSIONS FOR <?= htmlspecialchars(strtoupper($selectedLabel)) ?></span>
        <span id="granted-counter" style="font-size:11px;font-weight:800;color:var(--caramel)">
          <?= $grantedTotal ?> of <?= $totalPermsCount ?> GRANTED
        </span>
      </div>

      <!-- ── Category Filter Tabs ── -->
      <div class="category-tabs" id="categoryTabs">
        <button type="button" class="cat-tab active" data-cat="all" onclick="selectCategory('all', this)">
          <span>🌟 All</span>
          <span class="cat-badge" id="badge-all"><?= $grantedTotal ?>/<?= $totalPermsCount ?></span>
        </button>

        <?php foreach ($permsByCategory as $catName => $catPerms):
          $icon = $categoryIcons[$catName] ?? '📁';
          $catPermKeys = array_column($catPerms, 'perm_key');
          $catGrantedCount = count(array_intersect($catPermKeys, $currentGrants));
          $catTotalCount = count($catPerms);
        ?>
        <button type="button" class="cat-tab" data-cat="<?= htmlspecialchars($catName) ?>" onclick="selectCategory('<?= htmlspecialchars($catName) ?>', this)">
          <span><?= $icon ?> <?= htmlspecialchars($catName) ?></span>
          <span class="cat-badge" id="badge-cat-<?= htmlspecialchars($catName) ?>"><?= $catGrantedCount ?>/<?= $catTotalCount ?></span>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- ── Search & Fast Batch Toolbar ── -->
      <div class="perm-toolbar">
        <div class="perm-search-box">
          <span class="perm-search-icon">🔍</span>
          <input type="text" id="perm-search" placeholder="Search permission, action, or slug…" oninput="filterPerms()"/>
        </div>

        <div class="perm-actions-group">
          <button type="button" class="btn-cat-action" onclick="bulkSetVisible(true)" title="Grant all visible permissions in current view">
            ✅ Grant All Shown
          </button>
          <button type="button" class="btn-cat-action" onclick="bulkSetVisible(false)" title="Revoke all visible permissions in current view">
            ❌ Revoke All Shown
          </button>
        </div>
      </div>

      <!-- ── Permission Rows Grouped by Category ── -->
      <div id="permissions-container">
        <?php if (empty($permissions)): ?>
          <div class="perm-empty">🫙 No permissions defined in the database.</div>
        <?php else: ?>
          <?php foreach ($permsByCategory as $catName => $catPerms):
            $catIcon = $categoryIcons[$catName] ?? '📁';
          ?>
            <div class="perm-category-group" data-cat="<?= htmlspecialchars($catName) ?>">
              <div class="perm-category-header">
                <span><?= $catIcon ?> <?= htmlspecialchars($catName) ?></span>
                <span class="category-group-stat" style="font-weight:600;opacity:0.8">
                  <?= count($catPerms) ?> permissions
                </span>
              </div>

              <?php foreach ($catPerms as $p):
                $isOn = in_array($p['perm_key'], $currentGrants, true);
                $searchContent = strtolower($p['label'] . ' ' . $p['perm_key'] . ' ' . ($p['description'] ?? '') . ' ' . $catName);
              ?>
                <div class="perm-row" data-cat="<?= htmlspecialchars($catName) ?>" data-search="<?= htmlspecialchars($searchContent) ?>">
                  <div class="perm-text">
                    <div class="perm-label"><?= htmlspecialchars($p['label']) ?></div>
                    <?php if (!empty($p['description'])): ?>
                      <div class="perm-desc"><?= htmlspecialchars($p['description']) ?></div>
                    <?php endif; ?>
                    <span class="perm-slug"><?= htmlspecialchars($p['perm_key']) ?></span>
                  </div>

                  <button type="button"
                          class="perm-toggle <?= $isOn ? 'on' : '' ?>"
                          data-perm="<?= htmlspecialchars($p['perm_key']) ?>"
                          data-cat="<?= htmlspecialchars($catName) ?>"
                          data-original="<?= $isOn ? '1' : '0' ?>"
                          onclick="togglePerm(this)"
                          title="<?= $isOn ? 'Granted — click to revoke' : 'Not granted — click to grant' ?>">
                    ✓
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Save Changes Bar ── -->
    <div class="save-row-sticky">
      <div class="dirty-indicator">
        <span class="dirty-dot" id="dirty-dot"></span>
        <span id="dirty-text">All changes saved to database</span>
      </div>
      <button class="btn-save-changes" id="save-btn" onclick="saveChanges()">
        💾 Save Changes
      </button>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ── Add Role Modal ── -->
<div class="modal-bg" id="add-role-modal" onclick="if(event.target===this) closeAddRole()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Add a New Role</h3>
      <button type="button" class="modal-close" onclick="closeAddRole()">✕</button>
    </div>
    <div class="section-body">
      <div class="field-group" style="margin-bottom:14px">
        <label class="field-label" for="new-role-key">
          Role Key <span class="req">*</span> <span class="field-hint">— lowercase, letters and underscores only</span>
        </label>
        <input class="field-input" type="text" id="new-role-key" placeholder="e.g. procurement_lead" pattern="[a-z0-9_]{2,30}"/>
      </div>
      <div class="field-group" style="margin-bottom:14px">
        <label class="field-label" for="new-role-label">
          Display Label <span class="req">*</span>
        </label>
        <input class="field-input" type="text" id="new-role-label" placeholder="e.g. Procurement Lead"/>
      </div>
      <span class="ar-msg" id="ar-msg"></span>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeAddRole()">Cancel</button>
      <button type="button" class="btn-save" id="btn-create-role" onclick="addRole()">➕ Create Role</button>
    </div>
  </div>
</div>

<!-- ── Save Changes Confirmation Modal ── -->
<div class="modal-bg" id="save-confirm-modal" onclick="if(event.target===this) closeSaveConfirm()">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <h3>💾 Save Permission Changes?</h3>
      <button type="button" class="modal-close" onclick="closeSaveConfirm()">✕</button>
    </div>
    <div class="section-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
        Review changes for role <strong id="save-confirm-role" style="color:var(--espresso)"></strong>:
      </p>
      <div id="save-confirm-list" style="max-height:240px;overflow-y:auto;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:10px 0;margin-bottom:8px"></div>
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
    permissions: <?= json_encode($currentGrants) ?>,
    apiUrl: '../api/'
};
</script>

<script src="../js/manage_permissions.js"></script>
</body>
</html>