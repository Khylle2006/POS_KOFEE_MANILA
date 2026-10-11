<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/profile_helpers.php';
require_once '../includes/paymongo_disbursement_helpers.php';
require_once '../includes/icons.php';
require_once '../includes/email_mfa.php';

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
$mfa_available = email_mfa_available($pdo);
$mfa_enabled = email_mfa_enabled($pdo, (int)$user['id']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['mfa_start', 'mfa_verify', 'mfa_disable', 'mfa_cancel'], true)) {
        require_csrf();
        try {
            if (!$mfa_available) throw new SecurityFault('MFA_UNAVAILABLE', 'Email MFA settings are not installed yet.', 503);
            $userId = (int)$user['id'];
            if ($action === 'mfa_cancel') {
                unset($_SESSION['email_mfa_enroll']);
                $toast = 'Email MFA setup cancelled. Your setting is unchanged.';
            } elseif ($action === 'mfa_verify') {
                rate_limit('email-mfa-verify-enroll', (string)$userId, 5, 600);
                $challenge = &$_SESSION['email_mfa_enroll'];
                if (!is_array($challenge)) $challenge = [];
                $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
                enable_email_mfa($pdo, $userId, $challenge, $code);
                unset($_SESSION['email_mfa_enroll']);
                $toast = 'Email MFA is on. Future logins will require an email code.';
            } else {
                rate_limit('email-mfa-password', (string)$userId, 5, 600);
                $password = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
                if ($action === 'mfa_disable') {
                    disable_email_mfa($pdo, $userId, $password);
                    $toast = 'Email MFA is off.';
                } else {
                    $account = email_mfa_user($pdo, $userId);
                    if (!verify_login_password($password, (string)$account['password'])) {
                        throw new SecurityFault('PASSWORD_INVALID', 'Your current password is incorrect.');
                    }
                    start_email_mfa($pdo, $userId, 'enroll');
                    $toast = 'A verification code was sent to your profile email. Enter it below to turn on MFA.';
                }
            }
        } catch (SecurityFault $exception) {
            $toast = $exception->getMessage();
            $toast_type = 'error';
        }
        header('Location: profile.php?tab=personal&toast=' . urlencode($toast) . '&type=' . $toast_type . '#email-mfa');
        exit;
    }

    if ($action === 'update_profile' && $can_edit_profile) {
        $res = update_user_profile(
            $pdo,
            (int)$user['id'],
            [
                'firstname'     => $_POST['firstname'] ?? '',
                'lastname'      => $_POST['lastname'] ?? '',
                'email'         => $_POST['email'] ?? '',
                'phone'         => $_POST['phone'] ?? '',
                'address'       => $_POST['address'] ?? '',
                'remove_avatar' => !empty($_POST['remove_avatar']),
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

    if ($action === 'update_avatar_only' && $can_edit_profile) {
        $cur_p = get_user_profile($pdo, (int)$user['id']);
        $remove = !empty($_POST['remove_avatar']);
        $avatar_file = $_FILES['avatar_quick'] ?? ($_FILES['avatar'] ?? null);

        if (!$remove && (empty($avatar_file) || empty($avatar_file['tmp_name']))) {
            header('Location: profile.php?toast=' . urlencode('Please select a valid image file.') . '&type=error');
            exit;
        }

        $res = update_user_profile(
            $pdo,
            (int)$user['id'],
            [
                'firstname'     => $cur_p['firstname'] ?? '',
                'lastname'      => $cur_p['lastname'] ?? '',
                'email'         => $cur_p['email'] ?? '',
                'phone'         => $cur_p['phone'] ?? '',
                'address'       => $cur_p['address'] ?? '',
                'remove_avatar' => $remove,
            ],
            $remove ? null : $avatar_file
        );

        if ($res['ok']) {
            $toast = $remove ? 'Profile photo removed successfully.' : 'Profile picture updated successfully!';
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
      position: relative; width: 118px; height: 118px; margin: 0 auto 12px auto;
      border-radius: 50%; border: 3px solid var(--caramel, #c47d3e); overflow: hidden;
      background: #F8F4EE; display: flex; align-items: center; justify-content: center;
      cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 4px 14px rgba(44, 26, 14, 0.12);
    }
    .avatar-wrapper:hover {
      transform: scale(1.04);
      box-shadow: 0 8px 24px rgba(196, 125, 62, 0.28);
    }
    .avatar-img { width: 100%; height: 100%; object-fit: cover; }
    .avatar-placeholder { font-size: 38px; color: var(--caramel, #c47d3e); font-weight: 700; user-select: none; }
    .avatar-overlay {
      position: absolute; inset: 0; background: rgba(28, 17, 8, 0.68);
      color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center;
      opacity: 0; transition: opacity 0.2s ease; font-size: 11px; font-weight: 600; text-align: center;
      padding: 6px; gap: 4px; border-radius: 50%;
    }
    .avatar-wrapper:hover .avatar-overlay {
      opacity: 1;
    }

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
        <!-- Quick Avatar Forms -->
        <form id="avatar_quick_form" method="POST" enctype="multipart/form-data" style="display:none;">
          <input type="hidden" name="action" value="update_avatar_only"/>
          <input type="file" id="quick_avatar_input" name="avatar_quick" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="submitQuickAvatar(this)"/>
        </form>

        <form id="avatar_remove_form" method="POST" style="display:none;">
          <input type="hidden" name="action" value="update_avatar_only"/>
          <input type="hidden" name="remove_avatar" value="1"/>
        </form>

        <div class="avatar-wrapper" onclick="triggerQuickAvatar()" title="Click to change profile picture">
          <?php if (!empty($profile['avatar_path'])): ?>
            <img id="card-avatar-img" src="../<?= htmlspecialchars(ltrim($profile['avatar_path'], '/')) ?>" alt="Avatar" class="avatar-img" onerror="this.style.display='none'; document.getElementById('card-avatar-placeholder').style.display='block';"/>
            <span id="card-avatar-placeholder" class="avatar-placeholder" style="display:none;"><?= strtoupper(substr($profile['firstname'] ?: $profile['username'], 0, 1)) ?></span>
          <?php else: ?>
            <img id="card-avatar-img" src="" alt="Avatar" class="avatar-img" style="display:none;"/>
            <span id="card-avatar-placeholder" class="avatar-placeholder"><?= strtoupper(substr($profile['firstname'] ?: $profile['username'], 0, 1)) ?></span>
          <?php endif; ?>
          <div class="avatar-overlay">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <span id="quick-avatar-overlay-text">Change Photo</span>
          </div>
        </div>

        <div id="avatar-quick-loading" style="display:none; align-items:center; justify-content:center; gap:6px; font-size:11.5px; color:var(--caramel,#c47d3e); font-weight:700; margin-bottom:10px;">
          <svg style="animation:spin 0.9s linear infinite; width:14px; height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10"></path></svg>
          <span id="avatar-quick-loading-text">Optimizing photo...</span>
        </div>

        <div style="display:flex; justify-content:center; gap:8px; margin-bottom:14px;">
          <button type="button" onclick="triggerQuickAvatar()" style="background:#F8F4EE; border:1px solid #E2D4C3; color:var(--caramel,#c47d3e); border-radius:8px; padding:5px 12px; font-size:11.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all 0.15s;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Change Photo
          </button>
          <?php if (!empty($profile['avatar_path'])): ?>
          <button type="button" onclick="confirmRemoveAvatar()" style="background:#FFF5F5; border:1px solid #FED7D7; color:#E53E3E; border-radius:8px; padding:5px 10px; font-size:11.5px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all 0.15s;" title="Remove current photo and restore initials">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            Remove
          </button>
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

          <form method="POST" enctype="multipart/form-data" id="profile_personal_form" onsubmit="return handleProfileFormSubmit(event)">
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
              <label class="field-label" style="display:flex; justify-content:space-between; align-items:center;">
                <span>Upload Profile Photo (Avatar)</span>
                <?php if (!empty($profile['avatar_path'])): ?>
                  <span style="font-size:11px; font-weight:600; color:var(--caramel,#c47d3e);">Current photo active</span>
                <?php endif; ?>
              </label>

              <div style="display:flex; align-items:center; gap:16px; background:#FDFBF7; border:1.5px dashed #E2D4C3; border-radius:12px; padding:14px 16px;">
                <div style="width:52px; height:52px; border-radius:50%; border:2px solid var(--caramel,#c47d3e); overflow:hidden; background:#F8F4EE; flex-shrink:0; display:flex; align-items:center; justify-content:center;">
                  <?php if (!empty($profile['avatar_path'])): ?>
                    <img id="form-avatar-thumb" src="../<?= htmlspecialchars(ltrim($profile['avatar_path'], '/')) ?>" alt="Preview" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'; document.getElementById('form-avatar-placeholder').style.display='block';"/>
                    <span id="form-avatar-placeholder" style="font-size:18px; font-weight:700; color:var(--caramel); display:none;"><?= strtoupper(substr($profile['firstname'] ?: $profile['username'], 0, 1)) ?></span>
                  <?php else: ?>
                    <img id="form-avatar-thumb" src="" alt="Preview" style="width:100%; height:100%; object-fit:cover; display:none;"/>
                    <span id="form-avatar-placeholder" style="font-size:18px; font-weight:700; color:var(--caramel);"><?= strtoupper(substr($profile['firstname'] ?: $profile['username'], 0, 1)) ?></span>
                  <?php endif; ?>
                </div>

                <div style="flex:1; min-width:0;">
                  <input type="file" id="form-avatar-file" name="avatar" class="field-input" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="previewSelectedAvatar(this)" style="padding:6px; font-size:12px;"/>
                  <div id="avatar-preview-status" style="display:none; font-size:11.5px; color:#059669; font-weight:600; margin-top:4px;"></div>
                  <small style="font-size:11px;color:var(--text-muted);display:block;margin-top:2px">Max size: 5MB. Formats: JPG, PNG, WEBP, GIF. Updates your topbar avatar and login approvals.</small>
                </div>
              </div>

              <?php if (!empty($profile['avatar_path'])): ?>
              <label style="display:inline-flex; align-items:center; gap:6px; margin-top:8px; font-size:12px; color:#B91C1C; cursor:pointer;">
                <input type="checkbox" name="remove_avatar" value="1"/>
                <span>Remove current photo and restore default initials</span>
              </label>
              <?php endif; ?>
            </div>

            <div style="text-align:right">
              <button type="submit" class="btn-save"><?= icon('check', 14) ?> Save Profile Changes</button>
            </div>
          </form>

          <!-- ─── Enhanced Two-Factor Authentication (Email MFA) UI ─── -->
          <section id="email-mfa" aria-labelledby="email-mfa-title" style="margin-top:32px;border-top:1.5px dashed var(--border,#EDE8E1);padding-top:24px">
            
            <!-- Security Card Container -->
            <div style="background:#FAF7F2;border:1.5px solid var(--border,#EDE8E1);border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(44,26,14,0.03);">
              
              <!-- Card Header: Title, Icon, and Live Status Badge -->
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:12px;">
                  <div style="width:42px;height:42px;border-radius:10px;background:#F0EAE1;border:1.5px solid #E0D3C1;color:var(--caramel,#8B4513);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <?= icon('shield-check', 22) ?>
                  </div>
                  <div>
                    <h3 id="email-mfa-title" style="margin:0;font-size:16px;font-weight:700;color:var(--espresso,#1E1517);letter-spacing:-0.2px;">
                      Two-Factor Authentication (Email MFA)
                    </h3>
                    <p style="margin:2px 0 0 0;font-size:12px;color:var(--text-muted,#718096);">
                      Strengthen your staff sign-in security with one-time verification passcodes.
                    </p>
                  </div>
                </div>

                <div>
                  <?php if ($mfa_enabled): ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#DCFCE7;color:#166534;border:1px solid #86EFAC;font-size:11.5px;font-weight:700;padding:4px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.5px;">
                      <span style="width:7px;height:7px;border-radius:50%;background:#16A34A;box-shadow:0 0 0 2px rgba(22,163,74,0.2);"></span>
                      Status: Active &amp; Protected
                    </span>
                  <?php else: ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;background:#F3F4F6;color:#4B5563;border:1px solid #E5E7EB;font-size:11.5px;font-weight:700;padding:4px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.5px;">
                      <span style="width:7px;height:7px;border-radius:50%;background:#9CA3AF;"></span>
                      Status: Disabled (Off)
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Context Explainer Banner -->
              <div style="background:#FFFFFF;border:1px solid var(--border,#EDE8E1);border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;font-size:12.5px;color:var(--text-muted,#555);line-height:1.5;">
                <div style="color:var(--caramel,#8B4513);flex-shrink:0;margin-top:2px;">
                  <?= icon('pending', 16) ?>
                </div>
                <div>
                  <?php if ($mfa_enabled): ?>
                    Your account requires a temporary 6-digit code sent to <strong style="color:var(--text-main,#1E1517)"><?= htmlspecialchars($profile['email'] ?: 'your profile email') ?></strong> every time you log in.
                    <span style="display:block;margin-top:3px;font-size:11.5px;color:#92400E;">Notice: Please disable MFA before changing your email address, then verify your new email to turn it back on.</span>
                  <?php else: ?>
                    When enabled, signing in requires your master password plus a 6-digit verification code sent to <strong style="color:var(--text-main,#1E1517)"><?= htmlspecialchars($profile['email'] ?: 'your profile email') ?></strong>.
                    <span style="display:block;margin-top:3px;font-size:11.5px;color:var(--text-muted);">Off by default. Confirm your password below to receive a verification code and activate protection.</span>
                  <?php endif; ?>
                </div>
              </div>

              <?php if (!$mfa_available): ?>
                <div style="background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;padding:12px 16px;border-radius:10px;font-size:12.5px;">
                  Email MFA settings will be available after the database migration is installed.
                </div>

              <?php elseif (!$mfa_enabled && !empty($_SESSION['email_mfa_enroll'])): ?>
                <!-- STEP 2: VERIFICATION CODE ENTRY -->
                <div style="background:#FFFFFF;border:1.5px solid #FCD34D;border-radius:12px;padding:20px;box-shadow:0 4px 12px rgba(245,158,11,0.06);">
                  <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                    <div style="width:24px;height:24px;border-radius:50%;background:#FEF3C7;color:#D97706;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;">2</div>
                    <h4 style="margin:0;font-size:14.5px;font-weight:700;color:var(--espresso,#1E1517);">Enter 6-Digit Email Verification Code</h4>
                  </div>
                  <p style="margin:0 0 16px 0;font-size:12.5px;color:var(--text-muted);line-height:1.5;">
                    A verification code was dispatched to <strong style="color:var(--text-main)"><?= htmlspecialchars($profile['email']) ?></strong>. Enter the 6-digit passcode below to complete setup. Codes expire in 10 minutes.
                  </p>

                  <form method="post" style="display:flex;flex-direction:column;gap:14px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mfa_verify">
                    
                    <div style="max-width:280px;">
                      <label for="mfa-code" class="field-label" style="font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;display:block;">
                        Verification Code
                      </label>
                      <input id="mfa-code" name="code" class="field-input" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus style="letter-spacing:8px;font-size:20px;font-weight:800;text-align:center;font-family:monospace;padding:10px 14px;background:#FDFBF7;border:1.5px solid var(--caramel,#8B4513);border-radius:10px;" />
                    </div>

                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:4px;">
                      <button type="submit" class="btn-save" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;font-size:13px;font-weight:700;">
                        <?= icon('check', 15) ?> Verify &amp; Enable Email MFA
                      </button>
                    </div>
                  </form>

                  <form method="post" style="margin-top:10px;">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="mfa_cancel" style="background:none;border:none;color:#6B7280;font-size:12px;font-weight:600;cursor:pointer;padding:4px 0;text-decoration:underline;display:inline-flex;align-items:center;gap:4px;">
                      Cancel setup and keep MFA disabled
                    </button>
                  </form>
                </div>

              <?php elseif (!$mfa_enabled): ?>
                <!-- ENROLLMENT: START MFA SETUP -->
                <form method="post" style="background:#FFFFFF;border:1px solid var(--border,#EDE8E1);border-radius:12px;padding:18px 20px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mfa_start">
                  
                  <div class="field-group" style="margin-bottom:14px;max-width:440px;">
                    <label for="mfa-password" class="field-label" style="display:flex;align-items:center;justify-content:space-between;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">
                      <span>Current Staff Password</span>
                      <span style="font-size:11px;color:var(--text-muted);font-weight:normal;text-transform:none;">Required to verify identity</span>
                    </label>
                    <div style="position:relative;display:flex;align-items:center;">
                      <input id="mfa-password" name="current_password" class="field-input" type="password" autocomplete="current-password" placeholder="Enter your current password" required style="padding-right:40px;background:#FAF7F2;border:1.5px solid var(--border,#EDE8E1);border-radius:10px;font-size:13px;" />
                      <button type="button" onclick="toggleMfaPassword('mfa-password', this)" style="position:absolute;right:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;align-items:center;" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                    </div>
                  </div>

                  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn-save" style="display:inline-flex;align-items:center;gap:6px;padding:10px 22px;font-size:13px;font-weight:700;">
                      <?= icon('shield-check', 15) ?> Enable Email MFA &rarr;
                    </button>
                    <span style="font-size:11.5px;color:var(--text-muted);">A 6-digit setup code will be emailed immediately.</span>
                  </div>
                </form>

              <?php else: ?>
                <!-- DEACTIVATION: TURN MFA OFF -->
                <form method="post" style="background:#FFFFFF;border:1px solid var(--border,#EDE8E1);border-radius:12px;padding:18px 20px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mfa_disable">
                  
                  <div class="field-group" style="margin-bottom:14px;max-width:440px;">
                    <label for="mfa-password" class="field-label" style="display:flex;align-items:center;justify-content:space-between;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">
                      <span>Confirm Current Password</span>
                      <span style="font-size:11px;color:#B91C1C;font-weight:600;text-transform:none;">Required to turn off MFA</span>
                    </label>
                    <div style="position:relative;display:flex;align-items:center;">
                      <input id="mfa-password" name="current_password" class="field-input" type="password" autocomplete="current-password" placeholder="Enter your current password" required style="padding-right:40px;background:#FAF7F2;border:1.5px solid var(--border,#EDE8E1);border-radius:10px;font-size:13px;" />
                      <button type="button" onclick="toggleMfaPassword('mfa-password', this)" style="position:absolute;right:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;display:flex;align-items:center;" aria-label="Toggle password visibility">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                    </div>
                  </div>

                  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <button type="submit" style="background:#FFF5F5;border:1.5px solid #FED7D7;color:#B91C1C;border-radius:9px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;">
                      <?= icon('lock', 14) ?> Disable Email MFA
                    </button>
                    <span style="font-size:11.5px;color:#9CA3AF;">Future sign-ins will only require your master password.</span>
                  </div>
                </form>
              <?php endif; ?>

            </div>
          </section>

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

function triggerQuickAvatar() {
  const input = document.getElementById('quick_avatar_input');
  if (input) input.click();
}

function confirmRemoveAvatar() {
  if (confirm('Are you sure you want to remove your profile photo and restore your default initials?')) {
    document.getElementById('avatar_remove_form').submit();
  }
}

function setFileInputFiles(input, file) {
  try {
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return true;
  } catch (err) {
    console.warn('DataTransfer unavailable:', err);
    return false;
  }
}

async function optimizeImageForAvatar(file, maxDimension = 1600, quality = 0.88) {
  if (!file || !file.type || !file.type.startsWith('image/')) {
    return file;
  }

  // Preserve animated GIFs if within safe size limit
  if (file.type === 'image/gif' && file.size <= 4 * 1024 * 1024) {
    return file;
  }

  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onerror = () => resolve(file);
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => resolve(file);
      img.onload = () => {
        const width = img.naturalWidth || img.width;
        const height = img.naturalHeight || img.height;

        // If dimensions and file size are already well within server limits, keep original
        if (width <= maxDimension && height <= maxDimension && file.size < 2 * 1024 * 1024) {
          resolve(file);
          return;
        }

        let targetW = width;
        let targetH = height;
        if (targetW > maxDimension || targetH > maxDimension) {
          if (targetW > targetH) {
            targetH = Math.round((targetH * maxDimension) / targetW);
            targetW = maxDimension;
          } else {
            targetW = Math.round((targetW * maxDimension) / targetH);
            targetH = maxDimension;
          }
        }

        const canvas = document.createElement('canvas');
        canvas.width = targetW;
        canvas.height = targetH;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
          resolve(file);
          return;
        }

        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, targetW, targetH);

        const isPng = file.type === 'image/png';
        const outputMime = isPng ? 'image/png' : 'image/jpeg';

        canvas.toBlob((blob) => {
          if (!blob) {
            resolve(file);
            return;
          }

          let outName = file.name;
          if (!isPng && !/\.(jpe?g)$/i.test(outName)) {
            outName = outName.replace(/\.[^.]+$/, '') + '.jpg';
          }

          const resizedFile = new File([blob], outName, {
            type: blob.type || outputMime,
            lastModified: Date.now()
          });

          resolve(resizedFile);
        }, outputMime, quality);
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

let isQuickAvatarSubmitting = false;

async function submitQuickAvatar(input) {
  if (isQuickAvatarSubmitting) return;
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];

  const overlaySpan = document.getElementById('quick-avatar-overlay-text');
  const quickLoading = document.getElementById('avatar-quick-loading');
  const loadingText = document.getElementById('avatar-quick-loading-text');

  if (overlaySpan) overlaySpan.textContent = 'Optimizing...';
  if (quickLoading) quickLoading.style.display = 'inline-flex';
  if (loadingText) loadingText.textContent = 'Optimizing photo...';
  isQuickAvatarSubmitting = true;

  try {
    const optimizedFile = await optimizeImageForAvatar(file, 1600, 0.88);
    if (optimizedFile.size > 5 * 1024 * 1024) {
      alert('Profile picture is too large (maximum 5MB). Please choose a smaller photo.');
      input.value = '';
      if (overlaySpan) overlaySpan.textContent = 'Change Photo';
      if (quickLoading) quickLoading.style.display = 'none';
      isQuickAvatarSubmitting = false;
      return;
    }

    if (overlaySpan) overlaySpan.textContent = 'Uploading...';
    if (loadingText) loadingText.textContent = 'Uploading photo...';

    const updated = setFileInputFiles(input, optimizedFile);
    if (updated) {
      document.getElementById('avatar_quick_form').submit();
    } else {
      // Fallback submission via FormData if DataTransfer is not available
      const formData = new FormData();
      formData.append('action', 'update_avatar_only');
      formData.append('avatar_quick', optimizedFile, optimizedFile.name);

      fetch(window.location.href, {
        method: 'POST',
        body: formData
      }).then(() => {
        window.location.href = 'profile.php?toast=' + encodeURIComponent('Profile picture updated successfully!') + '&type=success';
      }).catch(() => {
        alert('Failed to upload profile picture. Please try again.');
        if (overlaySpan) overlaySpan.textContent = 'Change Photo';
        if (quickLoading) quickLoading.style.display = 'none';
        isQuickAvatarSubmitting = false;
      });
    }
  } catch (err) {
    console.error('Error optimizing avatar:', err);
    document.getElementById('avatar_quick_form').submit();
  }
}

let isFormAvatarOptimizing = false;

async function previewSelectedAvatar(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];

  const status = document.getElementById('avatar-preview-status');
  if (status) {
    status.style.display = 'block';
    status.style.color = 'var(--caramel, #c47d3e)';
    status.textContent = 'Processing & optimizing photo...';
  }

  isFormAvatarOptimizing = true;

  try {
    const optimizedFile = await optimizeImageForAvatar(file, 1600, 0.88);
    if (optimizedFile.size > 5 * 1024 * 1024) {
      alert('Profile picture is too large (maximum 5MB). Please select a smaller photo.');
      input.value = '';
      if (status) status.style.display = 'none';
      isFormAvatarOptimizing = false;
      return;
    }

    setFileInputFiles(input, optimizedFile);

    const reader = new FileReader();
    reader.onload = function(e) {
      const dataUrl = e.target.result;
      const thumb = document.getElementById('form-avatar-thumb');
      const formPl = document.getElementById('form-avatar-placeholder');
      const cardImg = document.getElementById('card-avatar-img');
      const cardPl = document.getElementById('card-avatar-placeholder');

      if (thumb) { thumb.src = dataUrl; thumb.style.display = 'block'; }
      if (formPl) { formPl.style.display = 'none'; }
      if (cardImg) { cardImg.src = dataUrl; cardImg.style.display = 'block'; }
      if (cardPl) { cardPl.style.display = 'none'; }
      if (status) {
        const sizeKb = Math.round(optimizedFile.size / 1024);
        status.style.color = '#059669';
        status.textContent = `Selected: ${optimizedFile.name} (Optimized: ${sizeKb} KB — Ready to save)`;
        status.style.display = 'block';
      }
      isFormAvatarOptimizing = false;
    };
    reader.readAsDataURL(optimizedFile);
  } catch (err) {
    console.error('Error optimizing preview avatar:', err);
    isFormAvatarOptimizing = false;
    if (status) {
      status.style.color = '#059669';
      status.textContent = `Selected: ${file.name} (Ready to save)`;
      status.style.display = 'block';
    }
  }
}

function handleProfileFormSubmit(e) {
  if (isFormAvatarOptimizing) {
    e.preventDefault();
    alert('Please wait a moment while your photo finishes optimizing.');
    return false;
  }
  return true;
}

function toggleMfaPassword(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  const isPass = inp.type === 'password';
  inp.type = isPass ? 'text' : 'password';
  btn.innerHTML = isPass ?
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>' :
    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  btn.setAttribute('aria-label', isPass ? 'Hide password' : 'Show password');
}
</script>
</body>
</html>
