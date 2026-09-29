<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/profile_helpers.php';
require_once '../includes/paymongo_disbursement_helpers.php';
require_once '../includes/icons.php';

require_login();
require_permission('profile.view');

$pdo  = get_db();
$user = current_user();

$can_edit_profile = has_permission('profile.edit');
$can_manage_hr    = has_permission('employee.payment.manage');

$toast = '';
$toast_type = 'success';

$profile = get_user_profile($pdo, (int)$user['id']);
$employee_id = (int)($profile['employee_id'] ?? 0);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile' && $can_edit_profile) {
        $res = update_user_profile(
            $pdo,
            (int)$user['id'],
            [
                'firstname' => $_POST['firstname'] ?? '',
                'lastname'  => $_POST['lastname'] ?? '',
                'email'     => $_POST['email'] ?? '',
                'phone'     => $_POST['phone'] ?? '',
                'address'   => $_POST['address'] ?? '',
            ],
            $_FILES['avatar'] ?? null
        );

        if ($res['ok']) {
            $toast = 'Personal profile updated successfully.';
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }
        header('Location: profile.php?toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }

    if ($action === 'request_payment_change' && $employee_id > 0) {
        $res = submit_payment_change_request($pdo, $employee_id, (int)$user['id'], [
            'payout_type'           => $_POST['payout_type'] ?? 'bank',
            'bank_name'             => $_POST['bank_name'] ?? '',
            'bank_code'             => $_POST['bank_code'] ?? '',
            'account_name'          => $_POST['account_name'] ?? '',
            'account_number'        => $_POST['account_number'] ?? '',
            'ewallet_provider'      => $_POST['ewallet_provider'] ?? '',
            'ewallet_account_name'  => $_POST['ewallet_account_name'] ?? '',
            'ewallet_mobile_number' => $_POST['ewallet_mobile_number'] ?? '',
            'employee_confirmed'    => !empty($_POST['employee_confirmed']),
        ]);

        if ($res['ok']) {
            $toast = 'Payment destination change request submitted for HR approval (' . $res['masked'] . ').';
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }
        header('Location: profile.php?toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }

    if ($action === 'hr_review_request' && $can_manage_hr) {
        $req_id   = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['decision'] === 'approved' ? 'approved' : 'rejected';
        $notes    = trim($_POST['review_notes'] ?? '');

        $res = review_payment_change_request($pdo, $req_id, (int)$user['id'], $decision, $notes);
        if ($res['ok']) {
            $toast = "Payment destination change request $decision.";
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }
        header('Location: profile.php?tab=hr&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$active_payment = $employee_id ? get_employee_active_payment_details($pdo, $employee_id) : null;
$pending_request = $employee_id ? get_pending_payment_change_request($pdo, $employee_id) : null;

// If HR manager, load pending review requests across employees
$hr_pending_requests = [];
if ($can_manage_hr) {
    $hr_stmt = $pdo->query("
        SELECT req.*, e.employee_code, e.firstname, e.lastname, e.department, e.position, u.username
        FROM `employee_payment_change_requests` req
        JOIN `employees` e ON e.id = req.employee_id
        JOIN `users` u ON u.id = req.user_id
        WHERE req.status = 'pending_review'
        ORDER BY req.created_at ASC
    ");
    $hr_pending_requests = $hr_stmt->fetchAll();
}

$active_tab = $_GET['tab'] ?? 'personal';
$supported_destinations = get_supported_payout_destinations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>User Profile &amp; Payment Details — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .profile-layout { display: grid; grid-template-columns: 320px 1fr; gap: 24px; align-items: start; }
    @media (max-width: 900px) { .profile-layout { grid-template-columns: 1fr; } }

    .avatar-wrapper {
      position: relative; width: 110px; height: 110px; margin: 0 auto 16px auto;
      border-radius: 50%; border: 3px solid var(--caramel, #8B4513); overflow: hidden;
      background: #F8F4EE; display: flex; align-items: center; justify-content: center;
    }
    .avatar-img { width: 100%; height: 100%; object-fit: cover; }
    .avatar-placeholder { font-size: 38px; color: var(--caramel, #8B4513); font-weight: 700; }

    .tab-pills { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1.5px solid var(--border, #EDE8E1); padding-bottom: 8px; }
    .tab-pill {
      padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700;
      color: var(--text-muted, #718096); background: none; border: none; cursor: pointer; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
    }
    .tab-pill.active { background: var(--caramel, #8B4513); color: #FFF; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-profile" class="page active">
  <div class="page-header">
    <div>
      <h1>My Profile &amp; Account</h1>
      <p>Manage your personal details, profile picture, and salary disbursement destination</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
      <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:16px"><?= $toast ?></div>
    <?php endif; ?>

    <div class="tab-pills">
      <a href="profile.php?tab=personal" class="tab-pill <?= $active_tab==='personal'?'active':'' ?>">
        <?= icon('user', 14) ?> Personal Details
      </a>
      <?php if ($employee_id > 0): ?>
        <a href="profile.php?tab=salary" class="tab-pill <?= $active_tab==='salary'?'active':'' ?>">
          <?= icon('credit-card', 14) ?> Salary Payment Destination
          <?php if ($pending_request): ?>
            <span style="background:#F59E0B;color:#FFF;font-size:10px;padding:1px 6px;border-radius:10px">Pending</span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
      <?php if ($can_manage_hr): ?>
        <a href="profile.php?tab=hr" class="tab-pill <?= $active_tab==='hr'?'active':'' ?>">
          <?= icon('shield-check', 14) ?> HR Review Queue
          <?php if (count($hr_pending_requests) > 0): ?>
            <span style="background:var(--red,#c62828);color:#FFF;font-size:10px;padding:1px 6px;border-radius:10px"><?= count($hr_pending_requests) ?></span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    </div>

    <div class="profile-layout">

      <!-- LEFT: Profile Snapshot Card -->
      <div class="table-card" style="padding:24px 20px;text-align:center">
        <div class="avatar-wrapper">
          <?php if (!empty($profile['avatar_path'])): ?>
            <img src="../<?= htmlspecialchars($profile['avatar_path']) ?>" alt="Avatar" class="avatar-img"/>
          <?php else: ?>
            <span class="avatar-placeholder"><?= strtoupper(substr($profile['firstname'] ?: $profile['username'], 0, 1)) ?></span>
          <?php endif; ?>
        </div>

        <h3 style="margin:0;font-size:16px;color:var(--espresso)">
          <?= htmlspecialchars(trim(($profile['firstname'] ?? '') . ' ' . ($profile['lastname'] ?? '')) ?: $profile['username']) ?>
        </h3>
        <p class="muted-cell" style="margin:4px 0 12px 0;font-size:12px">@<?= htmlspecialchars($profile['username']) ?></p>

        <span class="status-badge status-approved" style="text-transform:uppercase;font-size:11px;padding:3px 10px">
          <?= htmlspecialchars($profile['role']) ?>
        </span>

        <?php if (!empty($profile['position'])): ?>
          <div style="font-size:12px;font-weight:600;color:var(--caramel);margin-top:10px">
            <?= htmlspecialchars($profile['position']) ?> · <?= htmlspecialchars($profile['department'] ?: 'Staff') ?>
          </div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px">
            Branch: <strong><?= htmlspecialchars($profile['branch'] ?: 'Main Branch') ?></strong>
          </div>
        <?php endif; ?>

        <hr style="border:none;border-top:1px dashed var(--border,#EDE8E1);margin:16px 0"/>

        <div style="text-align:left;font-size:12px;color:var(--text-muted);line-height:1.6">
          <div>Email: <strong style="color:var(--text-main)"><?= htmlspecialchars($profile['email'] ?: 'Not set') ?></strong></div>
          <div>Phone: <strong style="color:var(--text-main)"><?= htmlspecialchars($profile['phone'] ?: ($profile['contact_number'] ?: 'Not set')) ?></strong></div>
          <div>Employee Code: <strong style="color:var(--text-main)"><?= htmlspecialchars($profile['employee_code'] ?: 'N/A') ?></strong></div>
          <div>Joined: <strong><?= date('M d, Y', strtotime($profile['user_created_at'])) ?></strong></div>
        </div>
      </div>

      <!-- RIGHT: Tab Content Panel -->
      <div class="table-card" style="padding:24px">

        <?php if ($active_tab === 'personal'): ?>
          <h3 style="margin:0 0 16px 0;font-size:16px;display:flex;align-items:center;gap:6px">
            <?= icon('user', 16, '', 'color:var(--caramel)') ?> Personal Details
          </h3>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile"/>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
              <div class="field-group" style="margin:0">
                <label class="field-label">First Name <span style="color:var(--red)">*</span></label>
                <input type="text" name="firstname" class="field-input" value="<?= htmlspecialchars($profile['firstname'] ?? '') ?>" required/>
              </div>
              <div class="field-group" style="margin:0">
                <label class="field-label">Last Name <span style="color:var(--red)">*</span></label>
                <input type="text" name="lastname" class="field-input" value="<?= htmlspecialchars($profile['lastname'] ?? '') ?>" required/>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
              <div class="field-group" style="margin:0">
                <label class="field-label">Email Address <span style="color:var(--red)">*</span></label>
                <input type="email" name="email" class="field-input" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required/>
              </div>
              <div class="field-group" style="margin:0">
                <label class="field-label">Phone Number (Mobile)</label>
                <input type="text" name="phone" class="field-input" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($profile['phone'] ?: ($profile['contact_number'] ?? '')) ?>"/>
              </div>
            </div>

            <div class="field-group" style="margin-bottom:14px">
              <label class="field-label">Residential Address</label>
              <textarea name="address" class="field-input" style="min-height:55px"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
            </div>

            <div class="field-group" style="margin-bottom:18px">
              <label class="field-label">Upload Profile Photo (Avatar)</label>
              <input type="file" name="avatar" class="field-input" accept=".jpg,.jpeg,.png,.webp"/>
              <small style="font-size:11px;color:var(--text-muted);display:block;margin-top:3px">Max size: 5MB. Formats: JPG, PNG, WEBP.</small>
            </div>

            <div style="text-align:right">
              <button type="submit" class="btn-save"><?= icon('check', 14) ?> Save Profile Changes</button>
            </div>
          </form>

        <?php elseif ($active_tab === 'salary' && $employee_id > 0): ?>
          <h3 style="margin:0 0 8px 0;font-size:16px;display:flex;align-items:center;gap:6px">
            <?= icon('credit-card', 16, '', 'color:var(--caramel)') ?> Salary Payment Destination
          </h3>
          <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 18px 0">
            Configure where your net salary payouts will be disbursed. For your security, account numbers are masked and changes require HR verification.
          </p>

          <!-- Current Active Payout Details -->
          <div style="background:#F8F4EE;border:1.5px solid var(--border,#EDE8E1);border-radius:10px;padding:16px 20px;margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
              <div>
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Active Salary Account</span>
                <div style="font-size:15px;font-weight:700;color:var(--espresso);margin-top:2px">
                  <?php if ($active_payment): ?>
                    <?= $active_payment['payout_type'] === 'bank' 
                        ? htmlspecialchars($active_payment['bank_name']) . ' &bull;&bull;&bull;&bull; ' . htmlspecialchars($active_payment['account_number_last4'])
                        : strtoupper($active_payment['ewallet_provider']) . ' ' . mask_mobile_number($active_payment['ewallet_mobile_number']) ?>
                  <?php else: ?>
                    Cash Payout (No Bank/E-Wallet Configured)
                  <?php endif; ?>
                </div>
                <?php if ($active_payment): ?>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                    Account Name: <strong><?= htmlspecialchars($active_payment['account_name'] ?: $active_payment['ewallet_account_name']) ?></strong>
                  </div>
                <?php endif; ?>
              </div>
              <span class="status-badge status-approved"><?= icon('check', 11) ?> Active for Payroll</span>
            </div>
          </div>

          <!-- Pending Change Warning Banner -->
          <?php if ($pending_request): ?>
            <div style="background:#FFFBEB;border:1.5px solid #F59E0B;border-radius:10px;padding:14px 18px;margin-bottom:20px;color:#92400E">
              <strong style="display:flex;align-items:center;gap:6px">
                <?= icon('clock', 15) ?> Pending HR Approval
              </strong>
              <div style="font-size:12.5px;margin-top:4px">
                You requested a change to: <strong>
                  <?= $pending_request['payout_type'] === 'bank' 
                      ? htmlspecialchars($pending_request['bank_name']) . ' (•••• ' . htmlspecialchars($pending_request['account_number_last4']) . ')' 
                      : strtoupper($pending_request['ewallet_provider']) . ' (' . mask_mobile_number($pending_request['ewallet_mobile_number']) . ')' ?>
                </strong> on <?= date('M d, Y g:i A', strtotime($pending_request['created_at'])) ?>.
                This will take effect once reviewed by the Payroll / HR team.
              </div>
            </div>
          <?php endif; ?>

          <!-- Change Request Form -->
          <div style="border:1px solid #EDE8E1;border-radius:10px;padding:18px 20px">
            <h4 style="margin:0 0 14px 0;font-size:14px;color:var(--espresso)">Update Salary Payment Destination</h4>

            <form method="POST" id="salary-form" onsubmit="return validateSalaryForm()">
              <input type="hidden" name="action" value="request_payment_change"/>

              <div class="field-group" style="margin-bottom:14px">
                <label class="field-label">Disbursement Method <span style="color:var(--red)">*</span></label>
                <div style="display:flex;gap:16px;margin-top:4px">
                  <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer">
                    <input type="radio" name="payout_type" value="bank" checked onchange="togglePayoutFields('bank')"/> Bank Account
                  </label>
                  <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer">
                    <input type="radio" name="payout_type" value="ewallet" onchange="togglePayoutFields('ewallet')"/> E-Wallet (GCash, Maya, GrabPay)
                  </label>
                </div>
              </div>

              <!-- Bank Fields -->
              <div id="bank-fields">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                  <div class="field-group" style="margin:0">
                    <label class="field-label">Select Bank <span style="color:var(--red)">*</span></label>
                    <select name="bank_name" id="bank-name-sel" class="field-input" style="padding:7px 10px" onchange="updateBankCode(this)">
                      <option value="">-- Choose Philippine Bank --</option>
                      <?php foreach ($supported_destinations['banks'] as $bkey => $binfo): ?>
                        <option value="<?= htmlspecialchars($binfo['name']) ?>" data-code="<?= $binfo['code'] ?>">
                          <?= htmlspecialchars($binfo['name']) ?> (<?= $binfo['code'] ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="bank_code" id="bank-code-inp"/>
                  </div>
                  <div class="field-group" style="margin:0">
                    <label class="field-label">Account Holder Name <span style="color:var(--red)">*</span></label>
                    <input type="text" name="account_name" id="bank-acc-name" class="field-input" placeholder="Exact name as in bank passbook/app"/>
                  </div>
                </div>
                <div class="field-group" style="margin-bottom:14px">
                  <label class="field-label">Bank Account Number <span style="color:var(--red)">*</span></label>
                  <input type="text" name="account_number" id="bank-acc-num" class="field-input" placeholder="e.g. 001234567890 (no dashes or spaces)"/>
                  <small style="font-size:11px;color:var(--text-muted)">Stored securely and masked in all logs and reports.</small>
                </div>
              </div>

              <!-- E-Wallet Fields -->
              <div id="ewallet-fields" style="display:none">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                  <div class="field-group" style="margin:0">
                    <label class="field-label">E-Wallet Provider <span style="color:var(--red)">*</span></label>
                    <select name="ewallet_provider" id="ewallet-provider-sel" class="field-input" style="padding:7px 10px">
                      <option value="gcash">GCash</option>
                      <option value="paymaya">Maya (PayMaya)</option>
                      <option value="grabpay">GrabPay</option>
                    </select>
                  </div>
                  <div class="field-group" style="margin:0">
                    <label class="field-label">Registered E-Wallet Name <span style="color:var(--red)">*</span></label>
                    <input type="text" name="ewallet_account_name" id="ewallet-acc-name" class="field-input" placeholder="Account owner full name"/>
                  </div>
                </div>
                <div class="field-group" style="margin-bottom:14px">
                  <label class="field-label">Mobile Number <span style="color:var(--red)">*</span></label>
                  <input type="text" name="ewallet_mobile_number" id="ewallet-mobile-num" class="field-input" placeholder="09XXXXXXXXX (11 digits)"/>
                </div>
              </div>

              <!-- Confirmation Checkbox -->
              <div style="margin:16px 0;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px">
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:12.5px;color:var(--espresso)">
                  <input type="checkbox" name="employee_confirmed" id="emp-confirm-chk" value="1" required style="margin-top:2px"/>
                  <span>
                    <strong>I confirm that the salary disbursement details above are accurate and belong to me.</strong>
                    <br/><span style="font-size:11px;color:var(--text-muted)">I understand that submitting incorrect information may delay payroll release.</span>
                  </span>
                </label>
              </div>

              <div style="text-align:right">
                <button type="submit" class="btn-save">
                  <?= icon('send', 13) ?> Submit for HR Review
                </button>
              </div>
            </form>
          </div>

        <?php elseif ($active_tab === 'hr' && $can_manage_hr): ?>
          <h3 style="margin:0 0 8px 0;font-size:16px;display:flex;align-items:center;gap:6px">
            <?= icon('shield-check', 16, '', 'color:var(--caramel)') ?> HR Payment Destination Review Queue
          </h3>
          <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 18px 0">
            Review and approve employee requests to update their salary disbursement accounts before PayMongo payouts are initiated.
          </p>

          <?php if (empty($hr_pending_requests)): ?>
            <div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;border:1px dashed #EDE8E1;border-radius:10px">
              <?= icon('check-circle', 24, '', 'color:#27AE60') ?>
              <div style="margin-top:8px;font-weight:600">All caught up!</div>
              <div style="font-size:12px;margin-top:2px">No pending salary payment destination requests awaiting review.</div>
            </div>
          <?php else: foreach ($hr_pending_requests as $req): ?>
            <div style="border:1.5px solid #FCD34D;background:#FFFDF5;border-radius:10px;padding:16px 18px;margin-bottom:14px">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                <div>
                  <div style="display:flex;align-items:center;gap:8px">
                    <strong style="font-size:14px;color:var(--espresso)">
                      <?= htmlspecialchars(trim($req['firstname'] . ' ' . $req['lastname'])) ?>
                    </strong>
                    <span style="font-size:11px;background:#EDE8E1;padding:1px 6px;border-radius:4px"><?= htmlspecialchars($req['employee_code']) ?></span>
                    <span style="font-size:11.5px;color:var(--text-muted)"><?= htmlspecialchars($req['position']) ?> &middot; <?= htmlspecialchars($req['department']) ?></span>
                  </div>
                  <div style="font-size:13px;margin-top:6px;color:var(--text-main)">
                    Requested Payout: <strong>
                      <?= $req['payout_type'] === 'bank' 
                          ? htmlspecialchars($req['bank_name']) . ' &bull;&bull;&bull;&bull; ' . htmlspecialchars($req['account_number_last4']) . ' (' . htmlspecialchars($req['account_name']) . ')'
                          : strtoupper($req['ewallet_provider']) . ' ' . mask_mobile_number($req['ewallet_mobile_number']) . ' (' . htmlspecialchars($req['ewallet_account_name']) . ')' ?>
                    </strong>
                  </div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                    Submitted on <?= date('M d, Y g:i A', strtotime($req['created_at'])) ?>
                  </div>
                </div>

                <!-- Review Actions Form -->
                <form method="POST" style="display:flex;align-items:center;gap:8px">
                  <input type="hidden" name="action" value="hr_review_request"/>
                  <input type="hidden" name="request_id" value="<?= $req['id'] ?>"/>
                  <input type="text" name="review_notes" class="field-input" placeholder="Notes (optional)" style="padding:5px 8px;font-size:11.5px;width:150px"/>
                  <button type="submit" name="decision" value="approved" class="act-btn act-activate" style="padding:6px 12px;font-size:12px">
                    <?= icon('check', 12) ?> Approve
                  </button>
                  <button type="submit" name="decision" value="rejected" class="act-btn act-reject" style="padding:6px 12px;font-size:12px">
                    <?= icon('x', 12) ?> Reject
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; endif; ?>

        <?php endif; ?>

      </div>

    </div>

  </div>
</div>

<script>
function togglePayoutFields(type) {
  document.getElementById('bank-fields').style.display = (type === 'bank') ? 'block' : 'none';
  document.getElementById('ewallet-fields').style.display = (type === 'ewallet') ? 'block' : 'none';
}

function updateBankCode(sel) {
  const code = sel.options[sel.selectedIndex]?.getAttribute('data-code') || '';
  document.getElementById('bank-code-inp').value = code;
}

function validateSalaryForm() {
  const type = document.querySelector('input[name="payout_type"]:checked')?.value || 'bank';
  if (type === 'bank') {
    const bName = document.getElementById('bank-name-sel')?.value;
    const aName = document.getElementById('bank-acc-name')?.value.trim();
    const aNum = document.getElementById('bank-acc-num')?.value.trim();
    if (!bName) { alert('Please select a bank.'); return false; }
    if (!aName) { alert('Please enter account holder name.'); return false; }
    if (!aNum || aNum.length < 8) { alert('Please enter a valid bank account number.'); return false; }
  } else {
    const eaName = document.getElementById('ewallet-acc-name')?.value.trim();
    const ePhone = document.getElementById('ewallet-mobile-num')?.value.trim();
    if (!eaName) { alert('Please enter e-wallet account name.'); return false; }
    if (!ePhone || ePhone.length !== 11 || !ePhone.startsWith('09')) {
      alert('Please enter a valid 11-digit mobile number (09XXXXXXXXX).');
      return false;
    }
  }

  const confirmed = document.getElementById('emp-confirm-chk')?.checked;
  if (!confirmed) {
    alert('Please confirm that the payment details belong to you.');
    return false;
  }

  return true;
}
</script>
</body>
</html>
