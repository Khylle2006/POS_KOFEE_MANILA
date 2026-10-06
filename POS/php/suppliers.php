<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/paymongo_disbursement_helpers.php';
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

        $payout_type           = in_array($_POST['payout_type'] ?? '', ['bank','ewallet'], true) ? $_POST['payout_type'] : 'bank';
        $bank_name             = trim($_POST['bank_name'] ?? '');
        $bank_code             = trim($_POST['bank_code'] ?? '');
        $account_name          = trim($_POST['account_name'] ?? '');
        $account_number        = trim($_POST['account_number'] ?? '');
        $ewallet_provider      = trim($_POST['ewallet_provider'] ?? '');
        $ewallet_account_name  = trim($_POST['ewallet_account_name'] ?? '');
        $ewallet_mobile_number = trim($_POST['ewallet_mobile_number'] ?? '');

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
            $pdo->prepare('
                UPDATE suppliers 
                SET name=:n, contact_person=:c, email=:e, phone=:p, address=:a, user_id=:u,
                    payout_type=:pt, bank_name=:bn, bank_code=:bc, account_name=:an, account_number=:num,
                    ewallet_provider=:ep, ewallet_account_name=:ean, ewallet_mobile_number=:emn
                WHERE id=:id
            ')->execute([
                ':n'=>$name, ':c'=>$contact, ':e'=>$email, ':p'=>$phone, ':a'=>$address, ':u'=>$user_id,
                ':pt'=>$payout_type, ':bn'=>$bank_name ?: null, ':bc'=>$bank_code ?: null, ':an'=>$account_name ?: null,
                ':num'=>$account_number ?: null, ':ep'=>$ewallet_provider ?: null, ':ean'=>$ewallet_account_name ?: null,
                ':emn'=>$ewallet_mobile_number ?: null, ':id'=>$id
            ]);
            $toast = 'Supplier updated!';
        } else {
            $pdo->prepare('
                INSERT INTO suppliers (
                    name, contact_person, email, phone, address, status, user_id,
                    payout_type, bank_name, bank_code, account_name, account_number,
                    ewallet_provider, ewallet_account_name, ewallet_mobile_number
                ) VALUES (
                    :n, :c, :e, :p, :a, "active", :u,
                    :pt, :bn, :bc, :an, :num, :ep, :ean, :emn
                )
            ')->execute([
                ':n'=>$name, ':c'=>$contact, ':e'=>$email, ':p'=>$phone, ':a'=>$address, ':u'=>$user_id,
                ':pt'=>$payout_type, ':bn'=>$bank_name ?: null, ':bc'=>$bank_code ?: null, ':an'=>$account_name ?: null,
                ':num'=>$account_number ?: null, ':ep'=>$ewallet_provider ?: null, ':ean'=>$ewallet_account_name ?: null,
                ':emn'=>$ewallet_mobile_number ?: null
            ]);
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
$payout_dests = get_supported_payout_destinations();
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
      <p>Your procurement supplier directory &amp; PayMongo payout registry</p>
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
      <span><?= icon('chevron-right', 12) ?> Swipe to view all 9 columns</span>
    </div>

    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr>
            <th class="col-sticky">Supplier</th>
            <th>Contact</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Payout Account (PayMongo)</th>
            <th>Login</th>
            <th>Rating</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($suppliers)): ?>
          <tr class="empty-row"><td colspan="9"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No suppliers yet — add your first one.</td></tr>
        <?php else: foreach ($suppliers as $s): ?>
          <tr>
            <td class="col-sticky" style="font-weight:700"><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['contact_person'] ?: '—') ?></td>
            <td class="muted-cell"><?= htmlspecialchars($s['email'] ?: '—') ?></td>
            <td class="muted-cell"><?= htmlspecialchars($s['phone'] ?: '—') ?></td>
            <td>
              <?php if (!empty($s['bank_name']) || !empty($s['ewallet_provider'])): ?>
                <?php if (($s['payout_type'] ?? 'bank') === 'bank'): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;color:var(--espresso)">
                    <span style="color:#16a34a">⚡</span> <?= htmlspecialchars($s['bank_name'] ?: 'Bank') ?>
                    <?php if (!empty($s['account_number'])): ?>
                      <span style="font-family:monospace;font-size:11px;color:var(--text-muted)">•••• <?= substr($s['account_number'], -4) ?></span>
                    <?php endif; ?>
                  </span>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;color:var(--espresso)">
                    <span style="color:#16a34a">⚡</span> <?= strtoupper(htmlspecialchars($s['ewallet_provider'] ?: 'GCash')) ?>
                    <?php if (!empty($s['ewallet_mobile_number'])): ?>
                      <span style="font-family:monospace;font-size:11px;color:var(--text-muted)"><?= substr($s['ewallet_mobile_number'], 0, 4) ?> ••• <?= substr($s['ewallet_mobile_number'], -4) ?></span>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted-cell" style="font-size:11.5px">Not set</span>
              <?php endif; ?>
            </td>
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

        <!-- PayMongo Disbursement Destination Section -->
        <div style="background:#FAF7F2;border:1px solid #E8DED2;border-radius:10px;padding:14px;margin-top:14px;margin-bottom:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <label class="field-label" style="font-weight:700;color:var(--espresso);margin:0;display:flex;align-items:center;gap:6px">
              <span>⚡ PayMongo Payout Account</span>
            </label>
            <span style="font-size:11px;color:var(--text-muted)">Default receiving account for automated payments</span>
          </div>

          <div style="display:flex;gap:16px;margin-bottom:10px">
            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;cursor:pointer">
              <input type="radio" name="payout_type" value="bank" id="f-payout-bank" checked onchange="toggleSupplierPayoutType(this.value)"/> Bank Account
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;cursor:pointer">
              <input type="radio" name="payout_type" value="ewallet" id="f-payout-ewallet" onchange="toggleSupplierPayoutType(this.value)"/> E-Wallet (GCash / Maya)
            </label>
          </div>

          <!-- Bank Fields -->
          <div id="sup-fields-bank">
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">Bank Name</label>
                <select class="field-input" name="bank_name" id="f-bank-name" onchange="syncSupBankCode(this)">
                  <option value="">— Select Bank —</option>
                  <?php foreach ($payout_dests['banks'] as $bkey => $binfo): ?>
                    <option value="<?= htmlspecialchars($binfo['name']) ?>" data-code="<?= $binfo['code'] ?>">
                      <?= htmlspecialchars($binfo['name']) ?> (<?= $binfo['code'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Bank Code</label>
                <input class="field-input" type="text" name="bank_code" id="f-bank-code" placeholder="e.g. BDO, BPI"/>
              </div>
            </div>
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">Account Holder Name</label>
                <input class="field-input" type="text" name="account_name" id="f-account-name" placeholder="Name on bank account"/>
              </div>
              <div class="field-group">
                <label class="field-label">Account Number</label>
                <input class="field-input" type="text" name="account_number" id="f-account-number" placeholder="e.g. 1042889210"/>
              </div>
            </div>
          </div>

          <!-- E-Wallet Fields -->
          <div id="sup-fields-ewallet" style="display:none">
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">E-Wallet Provider</label>
                <select class="field-input" name="ewallet_provider" id="f-ewallet-provider">
                  <?php foreach ($payout_dests['ewallets'] as $wkey => $winfo): ?>
                    <option value="<?= $wkey ?>"><?= htmlspecialchars($winfo['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Mobile Number (09XXXXXXXXX)</label>
                <input class="field-input" type="text" name="ewallet_mobile_number" id="f-ewallet-phone" placeholder="09171234567"/>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Registered Account Name</label>
              <input class="field-input" type="text" name="ewallet_account_name" id="f-ewallet-name" placeholder="Name on GCash / Maya"/>
            </div>
          </div>
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
function toggleSupplierPayoutType(type) {
  const fBank = document.getElementById('sup-fields-bank');
  const fEwallet = document.getElementById('sup-fields-ewallet');
  if (fBank) fBank.style.display = (type === 'bank') ? 'block' : 'none';
  if (fEwallet) fEwallet.style.display = (type === 'ewallet') ? 'block' : 'none';
}

function syncSupBankCode(select) {
  const opt = select.options[select.selectedIndex];
  if (opt && opt.dataset.code) {
    const codeInp = document.getElementById('f-bank-code');
    if (codeInp) codeInp.value = opt.dataset.code;
  }
}

function resetLoginOptions(currentUserId) {
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
  
  document.getElementById('f-payout-bank').checked = true;
  document.getElementById('f-payout-ewallet').checked = false;
  toggleSupplierPayoutType('bank');
  document.getElementById('f-bank-name').value = '';
  document.getElementById('f-bank-code').value = '';
  document.getElementById('f-account-name').value = '';
  document.getElementById('f-account-number').value = '';
  document.getElementById('f-ewallet-provider').value = 'gcash';
  document.getElementById('f-ewallet-phone').value = '';
  document.getElementById('f-ewallet-name').value = '';

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

  const isEwallet = (s.payout_type === 'ewallet');
  document.getElementById('f-payout-bank').checked = !isEwallet;
  document.getElementById('f-payout-ewallet').checked = isEwallet;
  toggleSupplierPayoutType(isEwallet ? 'ewallet' : 'bank');
  document.getElementById('f-bank-name').value = s.bank_name || '';
  document.getElementById('f-bank-code').value = s.bank_code || '';
  document.getElementById('f-account-name').value = s.account_name || '';
  document.getElementById('f-account-number').value = s.account_number || '';
  document.getElementById('f-ewallet-provider').value = s.ewallet_provider || 'gcash';
  document.getElementById('f-ewallet-phone').value = s.ewallet_mobile_number || '';
  document.getElementById('f-ewallet-name').value = s.ewallet_account_name || '';

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