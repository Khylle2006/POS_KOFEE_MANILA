<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.suppliers.manage');

$pdo   = get_db();
$toast = '';
$toast_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $user_id = (int)($_POST['user_id'] ?? 0) ?: null;

        // A login account should only ever drive the Supplier Portal for
        // ONE supplier record. Block linking it to a second one.
        $link_conflict = false;
        if ($user_id) {
            $chk = $pdo->prepare('SELECT id FROM suppliers WHERE user_id = :u AND id != :id');
            $chk->execute([':u' => $user_id, ':id' => $id]);
            $link_conflict = (bool)$chk->fetch();
        }

        if (!$name) {
            $toast = 'Supplier name is required.'; $toast_type = 'error';
        } elseif ($link_conflict) {
            $toast = 'That login account is already linked to another supplier.'; $toast_type = 'error';
        } elseif ($id) {
            $pdo->prepare('UPDATE suppliers SET name=:n, contact_person=:c, email=:e, phone=:p, address=:a, user_id=:u WHERE id=:id')
                ->execute([':n'=>$name, ':c'=>$contact, ':e'=>$email, ':p'=>$phone, ':a'=>$address, ':u'=>$user_id, ':id'=>$id]);
            $toast = 'Supplier updated!';
        } else {
            $pdo->prepare('INSERT INTO suppliers (name, contact_person, email, phone, address, status, user_id) VALUES (:n,:c,:e,:p,:a,"active",:u)')
                ->execute([':n'=>$name, ':c'=>$contact, ':e'=>$email, ':p'=>$phone, ':a'=>$address, ':u'=>$user_id]);
            $toast = '"' . htmlspecialchars($name) . '" added to your supplier directory!';
        }
    }

    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active','inactive'])) {
            $pdo->prepare('UPDATE suppliers SET status=:s WHERE id=:id')->execute([':s'=>$status, ':id'=>$id]);
            $toast = $status === 'active' ? 'Supplier reactivated.' : 'Supplier marked inactive.';
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: suppliers.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$search = trim($_GET['search'] ?? '');
$where  = '1=1';
$params = [];
if ($search) {
    $where .= ' AND (name LIKE :s OR contact_person LIKE :s2 OR email LIKE :s3)';
    $params[':s'] = $params[':s2'] = $params[':s3'] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT s.*, u.username AS login_username
    FROM suppliers s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE $where ORDER BY s.name
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$total  = count($suppliers);
$active = count(array_filter($suppliers, fn($s) => $s['status'] === 'active'));

// Login accounts eligible to be linked: anyone holding the 'supplier' role
// who isn't already linked to a DIFFERENT supplier record.
$login_stmt = $pdo->query("
    SELECT DISTINCT u.id, u.username, u.firstname, u.lastname,
           EXISTS(SELECT 1 FROM suppliers s2 WHERE s2.user_id = u.id) AS already_linked
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    WHERE ur.role = 'supplier'
    ORDER BY u.username
");
$eligible_logins = $login_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Suppliers — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-suppliers" class="page active">
  <div class="page-header">
    <div>
      <h1>Suppliers</h1>
      <p>Your procurement supplier directory</p>
    </div>
    <button class="btn-add" onclick="openAdd()"><?= icon('plus', 14) ?> Add Supplier</button>
  </div>

  <div class="page-body">

    <div class="stat-row" style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea"><?= icon('briefcase', 18, '', 'color:var(--caramel)') ?></div><div><div class="mini-stat-val"><?= $total ?></div><div class="mini-stat-lbl">Total Suppliers</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:var(--green-lt)"><?= icon('check', 18, '', 'color:var(--green)') ?></div><div><div class="mini-stat-val"><?= $active ?></div><div class="mini-stat-lbl">Active</div></div></div>
    </div>

    <div class="filter-bar" style="padding:0">
      <form method="GET" style="display:flex;gap:8px;width:100%;flex-wrap:wrap">
        <input class="filter-input" type="text" name="search" placeholder="Search name, contact, or email…"
               value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:200px"/>
        <button type="submit" class="act-btn act-activate"><?= icon('search', 13) ?> Search</button>
      </form>
    </div>

    <div class="table-scroll-hint">
      <span><?= icon('chevron-right', 12) ?> Swipe to view all 8 columns</span>
    </div>

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr><th class="col-sticky">Supplier</th><th>Contact</th><th>Email</th><th>Phone</th><th>Login</th><th>Rating</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($suppliers)): ?>
          <tr class="empty-row"><td colspan="8"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No suppliers yet — add your first one.</td></tr>
        <?php else: foreach ($suppliers as $s): ?>
          <tr>
            <td class="col-sticky" style="font-weight:700"><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['contact_person'] ?: '—') ?></td>
            <td class="muted-cell"><?= htmlspecialchars($s['email'] ?: '—') ?></td>
            <td class="muted-cell"><?= htmlspecialchars($s['phone'] ?: '—') ?></td>
            <td><?= $s['login_username'] ? '<span style="display:inline-flex;align-items:center;gap:4px">' . icon('key', 12) . ' ' . htmlspecialchars($s['login_username']) . '</span>' : '<span class="muted-cell">Not linked</span>' ?></td>
            <td><?= $s['rating_avg'] ? '<span style="display:inline-flex;align-items:center;gap:4px;color:var(--amber,#b45309)">' . icon('star', 12, '', 'fill:currentColor') . ' ' . number_format($s['rating_avg'],1) . ' (' . $s['rating_count'] . ')</span>' : '<span class="muted-cell">Not rated</span>' ?></td>
            <td><span class="badge badge-<?= $s['status']==='active'?'active':'blocked' ?>"><?= ucfirst($s['status']) ?></span></td>
            <td>
              <div class="act-group">
                <button class="act-btn" onclick='openEdit(<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'><?= icon('edit', 13) ?> Edit</button>
                <?php if ($s['status'] === 'active'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="set_status"/>
                    <input type="hidden" name="id" value="<?= $s['id'] ?>"/>
                    <input type="hidden" name="status" value="inactive"/>
                    <button type="submit" class="act-btn act-block"><?= icon('x', 13) ?> Deactivate</button>
                  </form>
                <?php else: ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="set_status"/>
                    <input type="hidden" name="id" value="<?= $s['id'] ?>"/>
                    <input type="hidden" name="status" value="active"/>
                    <button type="submit" class="act-btn act-activate"><?= icon('check', 13) ?> Activate</button>
                  </form>
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

<!-- Add / Edit modal -->
<div class="modal-overlay" id="supplier-modal">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-title" style="display:flex;align-items:center;gap:6px"><span id="modal-icon"><?= icon('plus', 16) ?></span> <span id="modal-title-text">Add Supplier</span></h3>
      <button class="modal-close" onclick="closeModal()" aria-label="Close"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="supplier-form">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="id" id="f-id" value=""/>
      <div class="modal-body">
        <div class="field-group">
          <label class="field-label">Supplier Name <span style="color:var(--red)">*</span></label>
          <input class="field-input" type="text" name="name" id="f-name" required/>
        </div>
        <div class="field-group">
          <label class="field-label">Contact Person</label>
          <input class="field-input" type="text" name="contact_person" id="f-contact"/>
        </div>
        <div class="field-row">
          <div class="field-group">
            <label class="field-label">Email</label>
            <input class="field-input" type="email" name="email" id="f-email"/>
          </div>
          <div class="field-group">
            <label class="field-label">Phone</label>
            <input class="field-input" type="text" name="phone" id="f-phone"/>
          </div>
        </div>
        <div class="field-group">
          <label class="field-label">Address</label>
          <input class="field-input" type="text" name="address" id="f-address"/>
        </div>
        <div class="field-group">
          <label class="field-label">Linked Login Account</label>
          <select class="field-input" name="user_id" id="f-user-id">
            <option value="">— Not linked —</option>
            <?php foreach ($eligible_logins as $u): ?>
              <option value="<?= $u['id'] ?>" data-linked="<?= $u['already_linked'] ? '1' : '0' ?>">
                <?= htmlspecialchars($u['username']) ?><?= $u['firstname'] ? ' — ' . htmlspecialchars(trim($u['firstname'].' '.$u['lastname'])) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="muted-cell" style="font-size:11.5px;margin-top:4px">Connects this record to a Supplier-role login so they can use the Supplier Portal.</p>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save" id="save-btn"><?= icon('plus', 14) ?> Add Supplier</button>
      </div>
    </form>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function resetLoginOptions(currentUserId) {
  // Disable logins already linked to a *different* supplier so admins
  // can't accidentally double-link one account to two supplier records.
  document.querySelectorAll('#f-user-id option[data-linked]').forEach(opt => {
    const isCurrent = currentUserId && String(opt.value) === String(currentUserId);
    opt.disabled = opt.dataset.linked === '1' && !isCurrent;
  });
}
function openAdd() {
  document.getElementById('modal-title-text').textContent = 'Add Supplier';
  document.getElementById('modal-icon').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
  document.getElementById('save-btn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Supplier';
  document.getElementById('f-id').value = '';
  document.getElementById('f-name').value = '';
  document.getElementById('f-contact').value = '';
  document.getElementById('f-email').value = '';
  document.getElementById('f-phone').value = '';
  document.getElementById('f-address').value = '';
  document.getElementById('f-user-id').value = '';
  resetLoginOptions(null);
  document.getElementById('supplier-modal').classList.add('open');
}
function openEdit(s) {
  document.getElementById('modal-title-text').textContent = 'Edit Supplier';
  document.getElementById('modal-icon').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
  document.getElementById('save-btn').innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align:middle;margin-right:4px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes';
  document.getElementById('f-id').value = s.id;
  document.getElementById('f-name').value = s.name;
  document.getElementById('f-contact').value = s.contact_person || '';
  document.getElementById('f-email').value = s.email || '';
  document.getElementById('f-phone').value = s.phone || '';
  document.getElementById('f-address').value = s.address || '';
  resetLoginOptions(s.user_id);
  document.getElementById('f-user-id').value = s.user_id || '';
  document.getElementById('supplier-modal').classList.add('open');
}
function closeModal() {
  document.getElementById('supplier-modal').classList.remove('open');
  if (window.KofeeValidator) {
    document.querySelectorAll('#supplier-form input, #supplier-form select').forEach(inp => {
      KofeeValidator.clearError(inp);
    });
  }
}
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

const supForm = document.getElementById('supplier-form');
if (supForm && window.KofeeValidator) {
  KofeeValidator.attach(supForm, {
    customValidate: function(form) {
      const name = form.querySelector('[name="name"]');
      if (!name || !name.value.trim() || name.value.trim().length < 2) {
        return { field: name, message: 'Supplier name must be at least 2 characters.' };
      }
      const email = form.querySelector('[name="email"]');
      if (email && email.value.trim()) {
        const reg = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!reg.test(email.value.trim())) {
          return { field: email, message: 'Please enter a valid email address.' };
        }
      }
      return true;
    },
    loadingText: 'Saving…'
  });
}
</script>
<script src="../js/validator.js"></script>
</body>
</html>