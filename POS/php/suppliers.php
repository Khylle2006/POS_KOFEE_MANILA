<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/paymongo_disbursement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.suppliers.manage');

$pdo   = get_db();
ensure_procurement_tables($pdo);

$toast = '';
$toast_type = 'success';
$active_tab = $_GET['tab'] ?? 'directory';
if (!in_array($active_tab, ['directory', 'applications'], true)) {
    $active_tab = 'directory';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. Save or Edit Existing Supplier ──
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

        // Check login account linking conflict
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
        $active_tab = 'directory';
    }

    // ── 2. Toggle Active / Inactive Status ──
    if ($action === 'set_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['active','inactive'])) {
            $pdo->prepare('UPDATE suppliers SET status=:s WHERE id=:id')->execute([':s'=>$status, ':id'=>$id]);
            $toast = $status === 'active' ? 'Supplier reactivated.' : 'Supplier marked inactive.';
        }
        $active_tab = 'directory';
    }

    // ── 3. Approve Supplier Application & Provision Account ──
    if ($action === 'approve_application') {
        $app_id     = (int)($_POST['application_id'] ?? 0);
        $notes      = trim($_POST['reviewer_notes'] ?? '');
        $req_user   = trim($_POST['custom_username'] ?? '');

        $chk = $pdo->prepare('SELECT * FROM supplier_applications WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $app_id]);
        $app = $chk->fetch();

        if (!$app) {
            $toast = 'Application record not found.'; $toast_type = 'error';
        } elseif ($app['status'] === 'approved') {
            $toast = 'This application is already approved and the supplier account is active.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Determine Unique Username
                if ($req_user !== '') {
                    $username = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $req_user));
                } else {
                    $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($app['company_name'])));
                    $username  = trim($cleanName, '_');
                    if (strlen($username) < 3) $username = 'supplier_' . $app['id'];
                }
                if (strlen($username) > 30) $username = substr($username, 0, 30);

                // Ensure username uniqueness
                $uStmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
                $uStmt->execute([':u' => $username]);
                if ($uStmt->fetch()) {
                    $username = substr($username, 0, 24) . '_' . mt_rand(100, 999);
                }

                // 2. Generate Temporary Password
                $tempPass = 'KM-Sup!' . mt_rand(1000, 9999) . chr(mt_rand(97, 122));
                $hashedPass = password_hash($tempPass, PASSWORD_DEFAULT);

                // 3. Name parsing
                $parts = explode(' ', trim($app['contact_person']));
                $fname = array_shift($parts) ?: $app['company_name'];
                $lname = !empty($parts) ? implode(' ', $parts) : 'Partner';

                // 4. Create User Account
                $pdo->prepare('
                    INSERT INTO users (username, email, phone, password, firstname, lastname, role, status, created_at)
                    VALUES (:u, :e, :p, :pass, :fn, :ln, "supplier", "active", NOW())
                ')->execute([
                    ':u' => $username,
                    ':e' => $app['email'],
                    ':p' => $app['phone'],
                    ':pass' => $hashedPass,
                    ':fn' => $fname,
                    ':ln' => $lname,
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // 5. Assign Supplier Role
                $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (:uid, "supplier")')
                    ->execute([':uid' => $newUserId]);

                // 6. Create Supplier Profile Record
                $pdo->prepare('
                    INSERT INTO suppliers (name, contact_person, email, phone, address, status, user_id)
                    VALUES (:n, :c, :e, :p, :a, "active", :u)
                ')->execute([
                    ':n' => $app['company_name'],
                    ':c' => $app['contact_person'],
                    ':e' => $app['email'],
                    ':p' => $app['phone'],
                    ':a' => $app['address'],
                    ':u' => $newUserId,
                ]);
                $newSupId = (int)$pdo->lastInsertId();

                // 7. Update Application Record
                $pdo->prepare('
                    UPDATE supplier_applications
                    SET status = "approved",
                        supplier_id = :sid,
                        created_user_id = :uid,
                        reviewer_notes = :notes,
                        reviewed_by = :revby,
                        reviewed_at = NOW()
                    WHERE id = :id
                ')->execute([
                    ':sid'   => $newSupId,
                    ':uid'   => $newUserId,
                    ':notes' => $notes ?: null,
                    ':revby' => $_SESSION['user_id'] ?? null,
                    ':id'    => $app_id
                ]);

                $pdo->commit();

                // 8. Base URL for Login
                $baseUrl = trim((string)(defined('APP_BASE_URL') ? APP_BASE_URL : (getenv('APP_BASE_URL') ?: '')));
                if (!$baseUrl) {
                    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
                    $appPath = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/php/suppliers.php'))));
                    $appPath = ($appPath === '/' || $appPath === '.') ? '' : '/' . trim($appPath, '/');
                    $baseUrl = ($isHttps ? 'https' : 'http') . '://' . $host . $appPath;
                } else {
                    $baseUrl = rtrim($baseUrl, '/');
                }
                $loginUrl = $baseUrl . '/auth/login.php';

                // 9. Dispatch Approval Email with Credentials
                $mailSent = false;
                $mailErr = '';
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $mailRes = send_supplier_approval_email(
                        $app['email'],
                        $app['company_name'],
                        $app['contact_person'],
                        $app['product_name'],
                        $username,
                        $tempPass,
                        $loginUrl
                    );
                    $mailSent = !empty($mailRes['sent']);
                    $mailErr = $mailRes['error'] ?? '';
                } catch (Throwable $mailEx) {
                    $mailErr = $mailEx->getMessage();
                    error_log('Approval email dispatch error: ' . $mailEx->getMessage());
                }

                audit_log('supplier_application', $app_id, 'approved', "Approved supplier '{$app['company_name']}', account '{$username}' provisioned", $_SESSION['user_id'] ?? null);

                if ($mailSent) {
                    $toast = 'Application approved! Supplier account "' . htmlspecialchars($username) . '" created and credentials emailed to ' . htmlspecialchars($app['email']) . '.';
                } else {
                    $toast = 'Application approved and account "' . htmlspecialchars($username) . '" created. NOTE: Credentials email was NOT delivered (' . htmlspecialchars($mailErr ?: 'SMTP unconfigured') . '). Please configure SMTP in Email Settings and use "Resend Email".';
                    $toast_type = 'warning';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = 'Failed to approve application: ' . $e->getMessage();
                $toast_type = 'error';
            }
        }
        $active_tab = 'applications';
    }

    // ── 4. Reject Supplier Application & Send Formal Letter ──
    if ($action === 'reject_application') {
        $app_id = (int)($_POST['application_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');
        $notes  = trim($_POST['reviewer_notes'] ?? '');

        if (!$reason) {
            $toast = 'A rejection reason is required to dispatch the formal notification letter.';
            $toast_type = 'error';
        } else {
            $chk = $pdo->prepare('SELECT * FROM supplier_applications WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $app_id]);
            $app = $chk->fetch();

            if (!$app) {
                $toast = 'Application record not found.'; $toast_type = 'error';
            } elseif ($app['status'] === 'approved') {
                $toast = 'Cannot reject an already approved supplier partnership.'; $toast_type = 'error';
            } else {
                $pdo->prepare('
                    UPDATE supplier_applications
                    SET status = "rejected",
                        rejection_reason = :r,
                        reviewer_notes = :n,
                        reviewed_by = :revby,
                        reviewed_at = NOW()
                    WHERE id = :id
                ')->execute([
                    ':r'     => $reason,
                    ':n'     => $notes ?: null,
                    ':revby' => $_SESSION['user_id'] ?? null,
                    ':id'    => $app_id
                ]);

                // Send formal rejection letter email
                $mailSent = false;
                $mailErr = '';
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $mailRes = send_supplier_rejection_email(
                        $app['email'],
                        $app['company_name'],
                        $app['contact_person'],
                        $app['product_name'],
                        $reason
                    );
                    $mailSent = !empty($mailRes['sent']);
                    $mailErr = $mailRes['error'] ?? '';
                } catch (Throwable $mailEx) {
                    $mailErr = $mailEx->getMessage();
                    error_log('Rejection email error: ' . $mailEx->getMessage());
                }

                audit_log('supplier_application', $app_id, 'rejected', "Application rejected with formal letter", $_SESSION['user_id'] ?? null);

                if ($mailSent) {
                    $toast = 'Application marked as declined and formal notification letter emailed to ' . htmlspecialchars($app['email']) . '.';
                } else {
                    $toast = 'Application marked as declined. NOTE: Letter was NOT emailed (' . htmlspecialchars($mailErr ?: 'SMTP unconfigured') . '). Please configure SMTP in Email Settings and click "Resend Email".';
                    $toast_type = 'warning';
                }
            }
        }
        $active_tab = 'applications';
    }

    // ── 5. Resend Notification Email (Approval or Rejection) ──
    if ($action === 'resend_application_email') {
        $app_id = (int)($_POST['application_id'] ?? 0);
        $chk = $pdo->prepare('
            SELECT a.*, u.username AS created_username
            FROM supplier_applications a
            LEFT JOIN users u ON u.id = a.created_user_id
            WHERE a.id = :id LIMIT 1
        ');
        $chk->execute([':id' => $app_id]);
        $app = $chk->fetch();

        if (!$app) {
            $toast = 'Application record not found.';
            $toast_type = 'error';
        } elseif ($app['status'] === 'approved') {
            require_once __DIR__ . '/../includes/mailer.php';

            // Generate fresh temporary password for the user
            $tempPass = 'KM-Sup!' . mt_rand(1000, 9999) . chr(mt_rand(97, 122));
            $hashedPass = password_hash($tempPass, PASSWORD_DEFAULT);
            if (!empty($app['created_user_id'])) {
                $pdo->prepare('UPDATE users SET password = :p WHERE id = :id')
                    ->execute([':p' => $hashedPass, ':id' => $app['created_user_id']]);
            }
            $username = $app['created_username'] ?: ('supplier_' . $app['id']);

            $baseUrl = trim((string)(defined('APP_BASE_URL') ? APP_BASE_URL : (getenv('APP_BASE_URL') ?: '')));
            if (!$baseUrl) {
                $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
                $appPath = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/php/suppliers.php'))));
                $appPath = ($appPath === '/' || $appPath === '.') ? '' : '/' . trim($appPath, '/');
                $baseUrl = ($isHttps ? 'https' : 'http') . '://' . $host . $appPath;
            } else {
                $baseUrl = rtrim($baseUrl, '/');
            }
            $loginUrl = $baseUrl . '/auth/login.php';

            $mailRes = send_supplier_approval_email(
                $app['email'],
                $app['company_name'],
                $app['contact_person'],
                $app['product_name'],
                $username,
                $tempPass,
                $loginUrl
            );

            if (!empty($mailRes['sent'])) {
                $toast = 'Approval email and newly generated login credentials re-sent to ' . htmlspecialchars($app['email']) . '!';
            } else {
                $toast = 'Email dispatch failed: ' . htmlspecialchars($mailRes['error'] ?? 'SMTP unconfigured. Please configure Email / SMTP settings.');
                $toast_type = 'error';
            }
        } elseif ($app['status'] === 'rejected') {
            require_once __DIR__ . '/../includes/mailer.php';
            $reason = $app['rejection_reason'] ?: 'Procurement standards or quota criteria were not met at this time.';

            $mailRes = send_supplier_rejection_email(
                $app['email'],
                $app['company_name'],
                $app['contact_person'],
                $app['product_name'],
                $reason
            );

            if (!empty($mailRes['sent'])) {
                $toast = 'Formal rejection letter email successfully re-sent to ' . htmlspecialchars($app['email']) . '!';
            } else {
                $toast = 'Email dispatch failed: ' . htmlspecialchars($mailRes['error'] ?? 'SMTP unconfigured. Please configure Email / SMTP settings.');
                $toast_type = 'error';
            }
        } else {
            $toast = 'Only approved or declined applications can receive decision notification emails.';
            $toast_type = 'error';
        }
        $active_tab = 'applications';
    }

    // ── 6. Save In-App SMTP Configuration ──
    if ($action === 'save_smtp_settings') {
        require_once __DIR__ . '/../includes/mailer.php';
        $res = save_kofee_smtp_settings($_POST);
        if (!empty($res['ok'])) {
            $toast = 'SMTP email settings saved successfully!';
        } else {
            $toast = 'Failed to save SMTP settings: ' . htmlspecialchars($res['error'] ?? 'Unknown error');
            $toast_type = 'error';
        }
        $active_tab = $_POST['redirect_tab'] ?? 'applications';
    }

    // ── 7. Send SMTP Diagnostic Test Email ──
    if ($action === 'test_smtp_email') {
        require_once __DIR__ . '/../includes/mailer.php';
        $testEmail = trim($_POST['test_recipient_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $toast = 'Please provide a valid test recipient email address.';
            $toast_type = 'error';
        } else {
            // Optional custom overrides from form
            $customCfg = [
                'host'       => trim($_POST['smtp_host'] ?? '') ?: 'smtp.gmail.com',
                'port'       => (int)($_POST['smtp_port'] ?? 587) ?: 587,
                'user'       => trim($_POST['smtp_user'] ?? ''),
                'pass'       => trim($_POST['smtp_pass'] ?? ''),
                'secure'     => trim($_POST['smtp_secure'] ?? 'tls') ?: 'tls',
                'from_email' => trim($_POST['smtp_from_email'] ?? '') ?: trim($_POST['smtp_user'] ?? ''),
                'from_name'  => trim($_POST['smtp_from_name'] ?? 'Kofee Manila') ?: 'Kofee Manila',
            ];
            $testRes = test_smtp_connection($testEmail, $customCfg);
            if (!empty($testRes['sent'])) {
                $toast = 'Diagnostic test email sent successfully to ' . htmlspecialchars($testEmail) . '! Check your inbox.';
            } else {
                $toast = 'Test email failed: ' . htmlspecialchars($testRes['error'] ?? $testRes['message'] ?? 'Could not establish connection to SMTP server.');
                $toast_type = 'error';
            }
        }
        $active_tab = $_POST['redirect_tab'] ?? 'applications';
    }

    $q = $toast ? '?tab=' . urlencode($active_tab) . '&toast=' . urlencode($toast) . '&type=' . $toast_type : '?tab=' . urlencode($active_tab);
    header('Location: suppliers.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Badge Counter for Pending Applications ──
$pending_apps_count = 0;
try {
    $pending_apps_count = (int)$pdo->query("SELECT COUNT(*) FROM supplier_applications WHERE status IN ('review', 'under_review')")->fetchColumn();
} catch (Throwable $e) {}

// ── SMTP Email Status ──
require_once __DIR__ . '/../includes/mailer.php';
$smtp_cfg = get_kofee_smtp_config();
$smtp_configured = !empty($smtp_cfg['configured']);

// ── Tab 1: Directory Data ──
$search = trim($_GET['search'] ?? '');
$where  = '1=1';
$params = [];
if ($search && $active_tab === 'directory') {
    $where .= ' AND (s.name LIKE :s OR s.contact_person LIKE :s2 OR s.email LIKE :s3)';
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

$total_suppliers = count($suppliers);
$active_suppliers = count(array_filter($suppliers, fn($s) => $s['status'] === 'active'));

// Login accounts eligible to be linked
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

// ── Tab 2: Applications Data ──
$app_status_filter = $_GET['status'] ?? 'all';
$app_search = trim($_GET['search'] ?? '');

$app_where = '1=1';
$app_params = [];

if ($active_tab === 'applications') {
    if ($app_status_filter === 'pending') {
        $app_where .= " AND a.status IN ('review', 'under_review')";
    } elseif (in_array($app_status_filter, ['review', 'approved', 'rejected'], true)) {
        $app_where .= " AND a.status = :st";
        $app_params[':st'] = $app_status_filter;
    }

    if ($app_search !== '') {
        $app_where .= " AND (a.company_name LIKE :as OR a.contact_person LIKE :as2 OR a.product_name LIKE :as3 OR a.application_code LIKE :as4 OR a.email LIKE :as5)";
        $app_params[':as'] = $app_params[':as2'] = $app_params[':as3'] = $app_params[':as4'] = $app_params[':as5'] = "%$app_search%";
    }
}

$appStmt = $pdo->prepare("
    SELECT a.*,
           u_rev.username AS reviewer_username,
           u_rev.firstname AS reviewer_firstname,
           u_created.username AS created_username
    FROM supplier_applications a
    LEFT JOIN users u_rev ON u_rev.id = a.reviewed_by
    LEFT JOIN users u_created ON u_created.id = a.created_user_id
    WHERE $app_where
    ORDER BY a.created_at DESC
");
$appStmt->execute($app_params);
$applications = $appStmt->fetchAll();

// Applications statistics
$total_apps = 0;
$pending_apps = 0;
$approved_apps = 0;
$rejected_apps = 0;
try {
    $statRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM supplier_applications GROUP BY status")->fetchAll();
    foreach ($statRows as $sr) {
        $c = (int)$sr['cnt'];
        $total_apps += $c;
        if (in_array($sr['status'], ['review', 'under_review'], true)) $pending_apps += $c;
        if ($sr['status'] === 'approved') $approved_apps += $c;
        if ($sr['status'] === 'rejected') $rejected_apps += $c;
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Suppliers &amp; Accreditation — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    /* Tab navigation buttons matching recruitment.php */
    .recruitment-tabs {
      display: flex;
      gap: 12px;
      border-bottom: 2px solid #E8DFD5;
      margin-bottom: 22px;
      padding-bottom: 2px;
    }
    .tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      font-size: 13.5px;
      font-weight: 600;
      color: #786A77;
      border-bottom: 3px solid transparent;
      margin-bottom: -4px;
      text-decoration: none;
      transition: all 0.16s ease;
      cursor: pointer;
    }
    .tab-btn:hover {
      color: var(--caramel, #C97B3D);
    }
    .tab-btn.active {
      color: #201426;
      border-bottom-color: var(--caramel, #C97B3D);
      font-weight: 700;
    }
    .tab-badge {
      background: #EDE6DC;
      color: #5C4E59;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
    }
    .tab-btn.active .tab-badge {
      background: var(--caramel, #C97B3D);
      color: #FFFFFF;
    }

    /* Filter Chips */
    .filter-chip-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .filter-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
      background: #FFFFFF;
      color: #5C4E59;
      border: 1px solid #E8DFD5;
      transition: all 0.15s ease;
    }
    .filter-chip:hover {
      border-color: var(--caramel, #C97B3D);
      color: var(--caramel, #C97B3D);
    }
    .filter-chip.active {
      background: var(--espresso, #241A2E);
      color: #FFFFFF;
      border-color: var(--espresso, #241A2E);
    }

    /* Dossier Review Modal Styles */
    .km-dossier-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
      margin-bottom: 16px;
    }
    @media (max-width: 640px) {
      .km-dossier-grid { grid-template-columns: 1fr; }
    }
    .km-dossier-card {
      background: #FAF7F2;
      border: 1px solid #EAE1D5;
      border-radius: 10px;
      padding: 14px;
    }
    .km-dossier-card h4 {
      margin: 0 0 6px;
      font-size: 11.5px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--caramel, #C97B3D);
    }
    .km-doc-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      background: #FFFFFF;
      border: 1px solid var(--border, #EFE0CC);
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--espresso, #241A2E);
      text-decoration: none;
      transition: border-color 0.15s, background 0.15s;
    }
    .km-doc-chip:hover {
      border-color: var(--caramel, #C97B3D);
      background: #FAF7F2;
    }

    /* In-modal view transition panels */
    .dossier-panel {
      display: none;
    }
    .dossier-panel.active {
      display: block;
      animation: fadeIn 0.18s ease;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(4px); }
      to   { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-suppliers" class="page active">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;">
    <div>
      <h1>Procurement &amp; Suppliers</h1>
      <p>Manage active supplier profiles, review partnership applications, and oversee automated payouts</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <!-- Email / SMTP Settings Button -->
      <button type="button" class="btn" onclick="openSmtpModal()" style="display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;border:1px solid #D8CFC4;background:#FFF;font-size:12.5px;font-weight:600;color:var(--espresso,#241A2E);cursor:pointer;transition:border-color 0.15s, background 0.15s;" title="Configure and test mail transfer agent credentials">
        <span style="width:8px;height:8px;border-radius:50%;background:<?= $smtp_configured ? '#16A34A' : '#F59E0B' ?>;display:inline-block;box-shadow:0 0 0 2px <?= $smtp_configured ? '#DCFCE7' : '#FEF3C7' ?>;"></span>
        <?= icon('mail', 14) ?>
        <span><?= $smtp_configured ? 'SMTP: Connected' : 'Email / SMTP: Setup' ?></span>
      </button>

      <?php if ($active_tab === 'directory'): ?>
        <button class="btn-add" onclick="openAdd()"><?= icon('plus', 14) ?> Add Supplier</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Toast Notification Banner -->
    <?php if ($toast): ?>
      <div style="margin-bottom: 20px; padding: 13px 18px; border-radius: 9px; background: <?= $toast_type==='error' ? '#FEE2E2' : ($toast_type==='warning' ? '#FEF3C7' : '#ECFDF5') ?>; border: 1px solid <?= $toast_type==='error' ? '#FCA5A5' : ($toast_type==='warning' ? '#FCD34D' : '#A7F3D0') ?>; color: <?= $toast_type==='error' ? '#991B1B' : ($toast_type==='warning' ? '#92400E' : '#065F46') ?>; font-size: 13.5px; font-weight: 500; display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <span style="font-size:16px;"><?= $toast_type==='error' ? '❌' : ($toast_type==='warning' ? '⚠️' : '✅') ?></span>
          <span><?= $toast ?></span>
        </div>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:none;cursor:pointer;font-size:18px;color:inherit;line-height:1;" aria-label="Close">&times;</button>
      </div>
    <?php endif; ?>

    <!-- SMTP Warning Notice (Shown on applications tab if unconfigured) -->
    <?php if (!$smtp_configured && $active_tab === 'applications'): ?>
      <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:12px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:18px;">⚠️</span>
          <div style="font-size:13px;color:#92400E;line-height:1.4;">
            <strong>Live email delivery is currently inactive:</strong> Set your Gmail / SMTP App Password in <strong>Email &amp; SMTP Settings</strong> above so partnership approval credentials and rejection feedback letters reach applicants.
          </div>
        </div>
        <button type="button" class="act-btn act-activate" onclick="openSmtpModal()" style="font-size:12.5px;padding:6px 14px;">
          <?= icon('mail', 13) ?> Configure SMTP
        </button>
      </div>
    <?php endif; ?>

    <!-- Top Tab Navigation -->
    <div class="recruitment-tabs">
      <a href="suppliers.php?tab=directory" class="tab-btn <?= $active_tab === 'directory' ? 'active' : '' ?>">
        <?= icon('briefcase', 15) ?>
        <span>Supplier Directory</span>
        <span class="tab-badge"><?= $total_suppliers ?></span>
      </a>
      <a href="suppliers.php?tab=applications" class="tab-btn <?= $active_tab === 'applications' ? 'active' : '' ?>">
        <?= icon('file-text', 15) ?>
        <span>Partnership Applications</span>
        <span class="tab-badge" style="<?= $pending_apps > 0 ? 'background:#EF4444;color:#FFFFFF;' : '' ?>">
          <?= $total_apps ?><?= $pending_apps > 0 ? " ({$pending_apps} Pending)" : '' ?>
        </span>
      </a>
    </div>

    <?php if ($active_tab === 'directory'): ?>
      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB 1: SUPPLIERS DIRECTORY -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div class="stat-row" style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea"><?= icon('briefcase', 18, '', 'color:var(--caramel)') ?></div><div><div class="mini-stat-val"><?= $total_suppliers ?></div><div class="mini-stat-lbl">Total Suppliers</div></div></div>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:var(--green-lt)"><?= icon('check', 18, '', 'color:var(--green)') ?></div><div><div class="mini-stat-val"><?= $active_suppliers ?></div><div class="mini-stat-lbl">Active</div></div></div>
      </div>

      <div class="filter-bar" style="padding:0">
        <form method="GET" style="display:flex;gap:8px;width:100%;flex-wrap:wrap">
          <input type="hidden" name="tab" value="directory"/>
          <input class="filter-input" type="text" name="search" placeholder="Search name, contact, or email…"
                 value="<?= htmlspecialchars($search) ?>" style="flex:1;min-width:200px"/>
          <button type="submit" class="act-btn act-activate"><?= icon('search', 13) ?> Search</button>
        </form>
      </div>

      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view all columns</span>
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
            <tr class="empty-row"><td colspan="9"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No suppliers found in directory.</td></tr>
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

    <?php else: ?>
      <!-- ══════════════════════════════════════════════════════════ -->
      <!-- TAB 2: ACCREDITATION APPLICATIONS -->
      <!-- ══════════════════════════════════════════════════════════ -->
      <div class="stat-row" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#fdf3ea"><?= icon('file-text', 18, '', 'color:var(--caramel)') ?></div><div><div class="mini-stat-val"><?= $total_apps ?></div><div class="mini-stat-lbl">Total Applications</div></div></div>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#FEF3C7"><?= icon('clock', 18, '', 'color:#B45309') ?></div><div><div class="mini-stat-val"><?= $pending_apps ?></div><div class="mini-stat-lbl">Pending Review</div></div></div>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:var(--green-lt)"><?= icon('check', 18, '', 'color:var(--green)') ?></div><div><div class="mini-stat-val"><?= $approved_apps ?></div><div class="mini-stat-lbl">Approved Partners</div></div></div>
        <div class="mini-stat"><div class="mini-stat-icon" style="background:#FEE2E2"><?= icon('x', 18, '', 'color:#DC2626') ?></div><div><div class="mini-stat-val"><?= $rejected_apps ?></div><div class="mini-stat-lbl">Declined</div></div></div>
      </div>

      <div class="filter-bar" style="padding:0;margin-bottom:18px;">
        <form method="GET" style="display:flex;gap:12px;width:100%;flex-wrap:wrap;align-items:center;">
          <input type="hidden" name="tab" value="applications"/>
          
          <div class="filter-chip-row">
            <a href="suppliers.php?tab=applications&status=all" class="filter-chip <?= $app_status_filter === 'all' ? 'active' : '' ?>">
              All <span class="tab-badge" style="padding:1px 6px;"><?= $total_apps ?></span>
            </a>
            <a href="suppliers.php?tab=applications&status=pending" class="filter-chip <?= $app_status_filter === 'pending' ? 'active' : '' ?>">
              Pending <span class="tab-badge" style="padding:1px 6px;<?= $pending_apps > 0 ? 'background:#EF4444;color:#FFFFFF;' : '' ?>"><?= $pending_apps ?></span>
            </a>
            <a href="suppliers.php?tab=applications&status=approved" class="filter-chip <?= $app_status_filter === 'approved' ? 'active' : '' ?>">
              Approved <span class="tab-badge" style="padding:1px 6px;"><?= $approved_apps ?></span>
            </a>
            <a href="suppliers.php?tab=applications&status=rejected" class="filter-chip <?= $app_status_filter === 'rejected' ? 'active' : '' ?>">
              Declined <span class="tab-badge" style="padding:1px 6px;"><?= $rejected_apps ?></span>
            </a>
          </div>

          <input class="filter-input" type="text" name="search" placeholder="Search company, product, contact, or tracking code…"
                 value="<?= htmlspecialchars($app_search) ?>" style="flex:1;min-width:240px"/>
          <button type="submit" class="act-btn act-activate"><?= icon('search', 13) ?> Filter</button>
        </form>
      </div>

      <div class="table-scroll-hint">
        <span><?= icon('chevron-right', 12) ?> Swipe to view details &amp; documents</span>
      </div>

      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr>
              <th class="col-sticky">Tracking Code</th>
              <th>Company &amp; Contact</th>
              <th>Product / Category</th>
              <th>Capacity &amp; Proposed Price</th>
              <th>Submitted Documents</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($applications)): ?>
            <tr class="empty-row"><td colspan="8"><?= icon('inbox', 18, '', 'vertical-align:middle;margin-right:6px') ?> No partnership applications found.</td></tr>
          <?php else: foreach ($applications as $a): ?>
            <tr>
              <td class="col-sticky" style="font-weight:700">
                <span style="font-family:monospace;font-size:12px;color:var(--caramel);letter-spacing:0.04em;">
                  <?= htmlspecialchars($a['application_code']) ?>
                </span>
              </td>
              <td>
                <strong style="color:var(--espresso);display:block;"><?= htmlspecialchars($a['company_name']) ?></strong>
                <span style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($a['contact_person']) ?> &bull; <?= htmlspecialchars($a['email']) ?></span>
              </td>
              <td>
                <strong style="color:var(--espresso);"><?= htmlspecialchars($a['product_name']) ?></strong>
                <span class="muted-cell" style="display:block;font-size:11.5px;"><?= htmlspecialchars($a['product_category'] ?: 'Supplies') ?></span>
              </td>
              <td>
                <?php if ($a['proposed_price']): ?>
                  <span style="font-weight:700;color:var(--espresso)">₱<?= number_format($a['proposed_price'], 2) ?></span>
                  <span style="font-size:11px;color:var(--text-muted)">/ <?= htmlspecialchars($a['price_unit'] ?: 'unit') ?></span>
                <?php else: ?>
                  <span class="muted-cell">Quote on RFQ</span>
                <?php endif; ?>
                <?php if ($a['supply_capacity']): ?>
                  <div style="font-size:11.5px;color:var(--text-muted);"><?= htmlspecialchars($a['supply_capacity']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="download_file.php?f=<?= urlencode($a['business_permit_path']) ?>&name=<?= urlencode($a['business_permit_filename']) ?>"
                     target="_blank" class="km-doc-chip" title="View Business Permit">
                    📄 Permit
                  </a>
                  <a href="download_file.php?f=<?= urlencode($a['authenticity_cert_path']) ?>&name=<?= urlencode($a['authenticity_cert_filename']) ?>"
                     target="_blank" class="km-doc-chip" title="View Authenticity Certificate">
                    🏅 Authenticity
                  </a>
                  <?php if (!empty($a['additional_documents_path'])): ?>
                    <a href="download_file.php?f=<?= urlencode($a['additional_documents_path']) ?>&name=<?= urlencode($a['additional_documents_filename'] ?: 'document') ?>"
                       target="_blank" class="km-doc-chip" title="Additional Document">
                      📎 Extra
                    </a>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($a['status'] === 'approved'): ?>
                  <span class="badge badge-active">Approved</span>
                <?php elseif ($a['status'] === 'rejected'): ?>
                  <span class="badge badge-blocked">Declined</span>
                <?php else: ?>
                  <span class="badge badge-amber" style="background:#FEF3C7;color:#92400E;">Pending Review</span>
                <?php endif; ?>
              </td>
              <td class="muted-cell" style="font-size:12px;white-space:nowrap;">
                <?= date('M d, Y', strtotime($a['created_at'])) ?>
              </td>
              <td style="white-space:nowrap;">
                <button type="button" class="act-btn act-activate" onclick='openAppReview(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'>
                  <?= icon('eye', 13) ?> Review Dossier
                </button>
                <?php if (in_array($a['status'], ['approved', 'rejected'], true)): ?>
                  <form method="POST" style="display:inline;margin-left:4px;" onsubmit="return confirm('Resend official decision notification email to <?= htmlspecialchars($a['email']) ?>?');">
                    <input type="hidden" name="action" value="resend_application_email"/>
                    <input type="hidden" name="application_id" value="<?= (int)$a['id'] ?>"/>
                    <button type="submit" class="act-btn" style="background:#FAF7F2;border:1px solid #D8CFC4;color:var(--espresso,#241A2E);" title="Resend notification email to supplier">
                      <?= icon('mail', 12) ?> Resend
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: ADD / EDIT SUPPLIER IN DIRECTORY -->
<!-- ══════════════════════════════════════════════════════════════ -->
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
          <div id="payout-bank-fields">
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">Bank</label>
                <select class="field-input" name="bank_code" id="f-bank-code" onchange="onBankSelect(this)">
                  <option value="">Select bank…</option>
                  <?php foreach ($payout_dests['banks'] as $b): ?>
                    <option value="<?= htmlspecialchars($b['code']) ?>"><?= htmlspecialchars($b['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="bank_name" id="f-bank-name"/>
              </div>
              <div class="field-group">
                <label class="field-label">Account Number</label>
                <input class="field-input" type="text" name="account_number" id="f-account-number" placeholder="e.g. 1234567890"/>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Account Holder Name</label>
              <input class="field-input" type="text" name="account_name" id="f-account-name" placeholder="Exact name registered with bank"/>
            </div>
          </div>

          <!-- E-Wallet Fields -->
          <div id="payout-ewallet-fields" style="display:none">
            <div class="field-row">
              <div class="field-group">
                <label class="field-label">E-Wallet Provider</label>
                <select class="field-input" name="ewallet_provider" id="f-ewallet-provider">
                  <?php foreach ($payout_dests['ewallets'] as $ew): ?>
                    <option value="<?= htmlspecialchars($ew['code']) ?>"><?= htmlspecialchars($ew['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field-group">
                <label class="field-label">Registered Mobile Number</label>
                <input class="field-input" type="text" name="ewallet_mobile_number" id="f-ewallet-phone" placeholder="09171234567"/>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label">Account Holder Name</label>
              <input class="field-input" type="text" name="ewallet_account_name" id="f-ewallet-name" placeholder="Registered full name on e-wallet"/>
            </div>
          </div>
        </div>

        <!-- Supplier Portal Login Account -->
        <div class="field-group">
          <label class="field-label">Linked Login Account (Supplier Portal)</label>
          <select class="field-input" name="user_id" id="f-user-id">
            <option value="">— No linked login account —</option>
            <?php foreach ($eligible_logins as $u): ?>
              <option value="<?= (int)$u['id'] ?>" data-linked="<?= (int)$u['already_linked'] ?>">
                <?= htmlspecialchars($u['username']) ?>
                (<?= htmlspecialchars(trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''))) ?: 'no name' ?>)
                <?= $u['already_linked'] ? '— [already linked]' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:3px">
            Users holding the "supplier" role can log in and view only purchase orders, RFQs, and invoices belonging to this supplier profile.
          </small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-save" id="save-btn"><?= icon('check', 14) ?> Save Supplier</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: APPLICATION DOSSIER REVIEW (UNIFIED VIEW & ACTIONS)     -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="app-review-modal">
  <div class="modal" style="max-width:760px;width:95%;">
    <div class="modal-header">
      <div>
        <span style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase;letter-spacing:0.05em;" id="revModalAppCode"></span>
        <h3 id="revModalTitle" style="margin:2px 0 0;font-size:18px;"></h3>
      </div>
      <button class="modal-close" onclick="closeAppReview()" aria-label="Close"><?= icon('x', 14) ?></button>
    </div>

    <!-- PANEL 1: DOSSIER VIEW -->
    <div id="revPanelDossier" class="dossier-panel active">
      <div class="modal-body">
        <!-- Dossier Info Grid -->
        <div class="km-dossier-grid">
          <div class="km-dossier-card">
            <h4>Business Identity</h4>
            <div style="font-size:14px;font-weight:700;color:var(--espresso)" id="revModalCompany"></div>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:4px;line-height:1.5;">
              Contact: <strong id="revModalContact" style="color:var(--espresso)"></strong><br/>
              Email: <span id="revModalEmail"></span><br/>
              Phone: <span id="revModalPhone"></span><br/>
              TIN / Tax ID: <span id="revModalTax"></span>
            </div>
          </div>

          <div class="km-dossier-card">
            <h4>Product &amp; Supply Scope</h4>
            <div style="font-size:14px;font-weight:700;color:var(--espresso)" id="revModalProduct"></div>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:4px;line-height:1.5;">
              Category: <strong id="revModalCat" style="color:var(--espresso)"></strong><br/>
              Proposed Unit Price: <strong id="revModalPrice" style="color:#16A34A"></strong><br/>
              Capacity: <span id="revModalCap"></span><br/>
              Specs / Origin: <span id="revModalDesc"></span>
            </div>
          </div>
        </div>

        <div class="km-dossier-card" style="margin-bottom:16px;">
          <h4>Registered Business Address</h4>
          <div style="font-size:13px;color:var(--espresso);" id="revModalAddress"></div>
        </div>

        <!-- Compliance Documents Area -->
        <div style="background:#FFFFFF;border:1px solid #EAE1D5;border-radius:10px;padding:16px;margin-bottom:16px;">
          <h4 style="margin:0 0 10px;font-size:11.5px;text-transform:uppercase;color:var(--espresso);">
            Verified Compliance &amp; Authenticity Documents
          </h4>
          <div style="display:flex;gap:10px;flex-wrap:wrap;" id="revModalDocChips"></div>
        </div>

        <!-- Notes / Remarks -->
        <div id="revModalNotesBox" style="background:#FAF7F2;border:1px solid #EAE1D5;border-radius:10px;padding:14px;margin-bottom:16px;display:none;">
          <h4 style="margin:0 0 6px;font-size:11.5px;text-transform:uppercase;color:var(--caramel);">Applicant's Cover Remarks</h4>
          <div style="font-size:13px;color:var(--text-muted);line-height:1.5;" id="revModalNotesText"></div>
        </div>

        <!-- Outcome / Status Box -->
        <div id="revModalStatusBox" style="padding:14px;border-radius:10px;font-size:13px;line-height:1.5;"></div>
      </div>

      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-cancel" onclick="closeAppReview()">Close</button>
        <div style="display:flex;gap:10px;" id="revModalActionButtons">
          <button type="button" class="act-btn act-block" style="padding:9px 18px;font-size:13px;" onclick="switchToRejectPanel()">
            <?= icon('x', 13) ?> Decline Application
          </button>
          <button type="button" class="act-btn act-activate" style="padding:9px 20px;font-size:13px;" onclick="switchToApprovePanel()">
            <?= icon('check', 13) ?> Approve &amp; Provision Account
          </button>
        </div>
      </div>
    </div>

    <!-- PANEL 2: APPROVE INLINE WORKFLOW -->
    <div id="revPanelApprove" class="dossier-panel">
      <form method="POST">
        <input type="hidden" name="action" value="approve_application"/>
        <input type="hidden" name="application_id" id="approveAppId" value=""/>

        <div class="modal-body">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #EAE1D5;">
            <div style="width:36px;height:36px;border-radius:8px;background:#DCFCE7;color:#166534;display:flex;align-items:center;justify-content:center;font-size:18px;">
              <?= icon('check', 20) ?>
            </div>
            <div>
              <h4 style="margin:0;font-size:15px;color:#166534;">Approve Supplier Partnership</h4>
              <p style="margin:2px 0 0;font-size:12.5px;color:var(--text-muted);">Accredit this supplier and provision their official portal login.</p>
            </div>
          </div>

          <div style="background:#FAF7F2;border:1px solid #EAE1D5;border-radius:8px;padding:14px;margin-bottom:16px;">
            <span style="font-size:11.5px;font-weight:700;color:var(--caramel);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:6px;">Automated Actions on Approval</span>
            <ul style="font-size:12.5px;color:var(--espresso);margin:0;padding-left:18px;line-height:1.6;">
              <li>Creates an active Supplier Profile record in the directory.</li>
              <li>Provisions a login account with the <strong>supplier</strong> role.</li>
              <li>Generates a secure temporary password and emails credentials to <strong id="approveTargetEmail" style="color:var(--caramel);"></strong>.</li>
            </ul>
          </div>

          <div class="field-group" style="margin-bottom:14px;">
            <label class="field-label">Suggested Username (Supplier Portal) <span style="color:var(--red)">*</span></label>
            <input type="text" name="custom_username" id="approveUsername" class="field-input" required pattern="[a-zA-Z0-9_]+" />
            <small style="font-size:11.5px;color:var(--text-muted);">Alphanumeric and underscores only. Supplier will use this to sign in.</small>
          </div>

          <div class="field-group">
            <label class="field-label">Internal Management Notes (Optional)</label>
            <textarea name="reviewer_notes" class="field-input" rows="2" placeholder="e.g., Verified FDA certification and Mayor's permit. Target initial PO in Q4."></textarea>
          </div>
        </div>

        <div class="modal-footer" style="justify-content:space-between;">
          <button type="button" class="btn-cancel" onclick="showDossierPanel()">
            &larr; Back to Dossier
          </button>
          <button type="submit" class="btn-save" style="background:#16A34A;border-color:#16A34A;">
            <?= icon('check', 14) ?> Confirm &amp; Dispatch Credentials Email
          </button>
        </div>
      </form>
    </div>

    <!-- PANEL 3: DECLINE & FORMAL LETTER COMPOSER -->
    <div id="revPanelReject" class="dossier-panel">
      <form method="POST">
        <input type="hidden" name="action" value="reject_application"/>
        <input type="hidden" name="application_id" id="rejectAppId" value=""/>

        <div class="modal-body">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #EAE1D5;">
            <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;color:#DC2626;display:flex;align-items:center;justify-content:center;font-size:18px;">
              <?= icon('x', 20) ?>
            </div>
            <div>
              <h4 style="margin:0;font-size:15px;color:#DC2626;">Decline Supplier Partnership</h4>
              <p style="margin:2px 0 0;font-size:12.5px;color:var(--text-muted);">Draft the formal evaluation feedback letter emailed to the applicant.</p>
            </div>
          </div>

          <div class="field-group" style="margin-bottom:14px;">
            <label class="field-label">Choose Preset Feedback</label>
            <select class="field-input" id="rejectPresetSelect" onchange="handleRejectPreset(this)">
              <option value="">-- Choose a standard reason preset or customize below --</option>
              <option value="Incomplete or expired business permits: The submitted business permits could not be verified with local municipal / tax registries or have lapsed.">Incomplete or expired business permits</option>
              <option value="Product Authenticity & Quality Standards: The product certification / FDA / COA documentation does not satisfy our specialty coffee and food safety benchmark requirements.">Product Authenticity & Quality Standards</option>
              <option value="Sourcing Quota Reached: Kofee Manila is currently at maximum supplier quota for this ingredient/category for the current operating period.">Sourcing Quota Currently Met</option>
              <option value="Pricing outside procurement parameters: The proposed supply unit price exceeds our target ingredient budget allocation for this item.">Pricing outside procurement parameters</option>
            </select>
          </div>

          <div class="field-group" style="margin-bottom:14px;">
            <label class="field-label">Formal Feedback Reason (Included in Supplier's Email) <span style="color:var(--red)">*</span></label>
            <textarea name="rejection_reason" id="rejectReasonText" class="field-input" rows="4" required placeholder="State the clear, polite reason for declining this application…"></textarea>
            <small style="font-size:11.5px;color:var(--text-muted);">This text will be formatted directly into the official letter sent to <strong id="rejectTargetEmail"></strong>.</small>
          </div>

          <div class="field-group">
            <label class="field-label">Internal Committee Notes (Private / Not sent to supplier)</label>
            <textarea name="reviewer_notes" class="field-input" rows="2" placeholder="Private internal notes for procurement committee…"></textarea>
          </div>
        </div>

        <div class="modal-footer" style="justify-content:space-between;">
          <button type="button" class="btn-cancel" onclick="showDossierPanel()">
            &larr; Back to Dossier
          </button>
          <button type="submit" class="btn-save" style="background:#DC2626;border-color:#DC2626;">
            <?= icon('x', 14) ?> Send Rejection Letter Email
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- MODAL: EMAIL & SMTP SETTINGS                                   -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="smtp-settings-modal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:32px;height:32px;border-radius:8px;background:#FAF5EE;color:var(--caramel);display:flex;align-items:center;justify-content:center;">
          <?= icon('mail', 18) ?>
        </div>
        <div>
          <h3 style="margin:0;font-size:16px;">Email &amp; SMTP Configuration</h3>
          <p style="margin:2px 0 0;font-size:12px;color:var(--text-muted);">Configure credentials for live applicant approvals and letters</p>
        </div>
      </div>
      <button class="modal-close" onclick="closeSmtpModal()" aria-label="Close"><?= icon('x', 14) ?></button>
    </div>

    <!-- SMTP Credentials Form -->
    <form method="POST">
      <input type="hidden" name="action" value="save_smtp_settings"/>
      <input type="hidden" name="redirect_tab" value="<?= htmlspecialchars($active_tab) ?>"/>

      <div class="modal-body">
        <div style="background:#FAF7F2;border:1px solid #E8DFD5;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#5B4F5E;line-height:1.5;">
          <strong style="color:var(--espresso);">Gmail Setup Tip:</strong> If using Gmail (<code>smtp.gmail.com</code>), you must generate a <strong>16-character Google App Password</strong> at <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:var(--caramel);font-weight:700;">Google Account &rarr; App Passwords</a> (requires 2-Step Verification enabled).
        </div>

        <div class="field-row">
          <div class="field-group" style="flex:2;">
            <label class="field-label">SMTP Host</label>
            <input type="text" name="smtp_host" class="field-input" value="<?= htmlspecialchars($smtp_cfg['host']) ?>" required placeholder="smtp.gmail.com" />
          </div>
          <div class="field-group" style="flex:1;">
            <label class="field-label">Port</label>
            <input type="number" name="smtp_port" class="field-input" value="<?= (int)$smtp_cfg['port'] ?>" required placeholder="587" />
          </div>
          <div class="field-group" style="flex:1;">
            <label class="field-label">Encryption</label>
            <select name="smtp_secure" class="field-input">
              <option value="tls" <?= $smtp_cfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
              <option value="ssl" <?= $smtp_cfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label">SMTP Username / Email Address <span style="color:var(--red)">*</span></label>
          <input type="email" name="smtp_user" class="field-input" value="<?= htmlspecialchars($smtp_cfg['user']) ?>" required placeholder="e.g. kofeemanilaph@gmail.com" />
        </div>

        <div class="field-group">
          <label class="field-label">SMTP Password / Google App Password <span style="color:var(--red)">*</span></label>
          <input type="password" name="smtp_pass" class="field-input" value="<?= htmlspecialchars($smtp_cfg['pass']) ?>" required placeholder="16-character Google App Password" autocomplete="new-password" />
          <small style="font-size:11.5px;color:var(--text-muted);">Stored securely in your local environment config &amp; database.</small>
        </div>

        <div class="field-row">
          <div class="field-group" style="flex:1;">
            <label class="field-label">Sender Display Name</label>
            <input type="text" name="smtp_from_name" class="field-input" value="<?= htmlspecialchars($smtp_cfg['from_name']) ?>" placeholder="Kofee Manila Procurement" />
          </div>
          <div class="field-group" style="flex:1;">
            <label class="field-label">Sender From Email</label>
            <input type="email" name="smtp_from_email" class="field-input" value="<?= htmlspecialchars($smtp_cfg['from_email']) ?>" placeholder="Leave blank to use SMTP Username" />
          </div>
        </div>
      </div>

      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-cancel" onclick="closeSmtpModal()">Cancel</button>
        <button type="submit" class="btn-save">
          <?= icon('check', 14) ?> Save SMTP Credentials
        </button>
      </div>
    </form>

    <!-- Diagnostics / Test Connection Section -->
    <div style="padding:16px 20px;background:#FAF7F2;border-top:1px solid #E8DFD5;border-radius:0 0 12px 12px;">
      <h4 style="margin:0 0 8px;font-size:12.5px;text-transform:uppercase;color:var(--espresso);">
        Diagnostic Mail Test
      </h4>
      <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;">
        Send a test verification message to ensure your credentials authenticate with the mail server:
      </p>
      <form method="POST" style="display:flex;gap:8px;">
        <input type="hidden" name="action" value="test_smtp_email"/>
        <input type="hidden" name="redirect_tab" value="<?= htmlspecialchars($active_tab) ?>"/>
        <input type="email" name="test_recipient_email" class="field-input" style="flex:1;font-size:12.5px;padding:7px 12px;" required placeholder="recipient@domain.com (your email to receive test)" value="<?= htmlspecialchars($smtp_cfg['user']) ?>" />
        <button type="submit" class="act-btn act-activate" style="padding:7px 14px;font-size:12.5px;white-space:nowrap;">
          <?= icon('send', 13) ?> Send Test Email
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// ── Directory PayMongo Helpers ──
function toggleSupplierPayoutType(type) {
  const bankFields = document.getElementById('payout-bank-fields');
  const ewFields   = document.getElementById('payout-ewallet-fields');
  if (type === 'ewallet') {
    bankFields.style.display = 'none';
    ewFields.style.display   = 'block';
  } else {
    bankFields.style.display = 'block';
    ewFields.style.display   = 'none';
  }
}
function onBankSelect(sel) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('f-bank-name').value = opt ? opt.text : '';
}
function resetLoginOptions(currentUserId) {
  const select = document.getElementById('f-user-id');
  if (!select) return;
  select.querySelectorAll('option').forEach(opt => {
    if (!opt.value) return;
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
}

// ── Application Review Dossier Helpers ──
let currentAppDossier = null;

function showDossierPanel() {
  document.getElementById('revPanelDossier').classList.add('active');
  document.getElementById('revPanelApprove').classList.remove('active');
  document.getElementById('revPanelReject').classList.remove('active');
}

function switchToApprovePanel() {
  if (!currentAppDossier) return;
  document.getElementById('approveAppId').value = currentAppDossier.id;
  document.getElementById('approveTargetEmail').innerText = currentAppDossier.email;

  // Clean suggested username
  let suggested = currentAppDossier.company_name.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
  if (suggested.length < 3) suggested = 'sup_' + currentAppDossier.id;
  document.getElementById('approveUsername').value = suggested.substring(0, 24);

  document.getElementById('revPanelDossier').classList.remove('active');
  document.getElementById('revPanelReject').classList.remove('active');
  document.getElementById('revPanelApprove').classList.add('active');
}

function switchToRejectPanel() {
  if (!currentAppDossier) return;
  document.getElementById('rejectAppId').value = currentAppDossier.id;
  document.getElementById('rejectTargetEmail').innerText = currentAppDossier.email;
  document.getElementById('rejectPresetSelect').value = '';
  document.getElementById('rejectReasonText').value = '';

  document.getElementById('revPanelDossier').classList.remove('active');
  document.getElementById('revPanelApprove').classList.remove('active');
  document.getElementById('revPanelReject').classList.add('active');
}

function openAppReview(app) {
  currentAppDossier = app;
  showDossierPanel();

  document.getElementById('revModalAppCode').innerText = app.application_code;
  document.getElementById('revModalTitle').innerText = app.company_name + ' — Partnership Application';
  document.getElementById('revModalCompany').innerText = app.company_name;
  document.getElementById('revModalContact').innerText = app.contact_person;
  document.getElementById('revModalEmail').innerText = app.email;
  document.getElementById('revModalPhone').innerText = app.phone;
  document.getElementById('revModalTax').innerText = app.tax_id || 'Not provided';

  document.getElementById('revModalProduct').innerText = app.product_name;
  document.getElementById('revModalCat').innerText = app.product_category || 'General Supplies';
  document.getElementById('revModalPrice').innerText = app.proposed_price ? '₱' + Number(app.proposed_price).toFixed(2) + ' / ' + (app.price_unit || 'unit') : 'Quoted per RFQ';
  document.getElementById('revModalCap').innerText = app.supply_capacity || 'Not specified';
  document.getElementById('revModalDesc').innerText = app.product_description || 'Standard specifications';
  document.getElementById('revModalAddress').innerText = app.address;

  // Render Document Chips
  const chipsArea = document.getElementById('revModalDocChips');
  chipsArea.innerHTML = '';

  const addChip = (href, label, iconText) => {
    const a = document.createElement('a');
    a.href = href;
    a.target = '_blank';
    a.className = 'km-doc-chip';
    a.innerHTML = iconText + ' ' + label + ' &nearr;';
    chipsArea.appendChild(a);
  };

  addChip('download_file.php?f=' + encodeURIComponent(app.business_permit_path) + '&name=' + encodeURIComponent(app.business_permit_filename), 'Business Permit', '📄');
  addChip('download_file.php?f=' + encodeURIComponent(app.authenticity_cert_path) + '&name=' + encodeURIComponent(app.authenticity_cert_filename), 'Authenticity Certificate', '🏅');

  if (app.additional_documents_path) {
    addChip('download_file.php?f=' + encodeURIComponent(app.additional_documents_path) + '&name=' + encodeURIComponent(app.additional_documents_filename || 'document'), 'Additional Document', '📎');
  }

  // Cover notes
  const notesBox = document.getElementById('revModalNotesBox');
  const notesText = document.getElementById('revModalNotesText');
  if (app.company_profile_notes && app.company_profile_notes.trim()) {
    notesBox.style.display = 'block';
    notesText.innerText = app.company_profile_notes;
  } else {
    notesBox.style.display = 'none';
    notesText.innerText = '';
  }

  // Outcome status box & buttons
  const statusBox = document.getElementById('revModalStatusBox');
  const actionBtns = document.getElementById('revModalActionButtons');

  if (app.status === 'approved') {
    actionBtns.style.display = 'none';
    statusBox.style.display = 'block';
    statusBox.style.background = '#DCFCE7';
    statusBox.style.border = '1px solid #86EFAC';
    statusBox.style.color = '#166534';
    statusBox.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
          <strong>✓ Application Approved</strong> &bull; Partner account provisioned (Username: <code>${app.created_username || 'linked'}</code>).
        </div>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Resend login credentials email to ${app.email}?');">
          <input type="hidden" name="action" value="resend_application_email"/>
          <input type="hidden" name="application_id" value="${app.id}"/>
          <button type="submit" class="act-btn" style="background:#FFF;border:1px solid #86EFAC;color:#166534;padding:5px 12px;font-size:12px;">
            ✉ Resend Credentials Email
          </button>
        </form>
      </div>`;
  } else if (app.status === 'rejected') {
    actionBtns.style.display = 'none';
    statusBox.style.display = 'block';
    statusBox.style.background = '#FEE2E2';
    statusBox.style.border = '1px solid #FCA5A5';
    statusBox.style.color = '#991B1B';
    statusBox.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
        <div>
          <strong>✕ Application Declined</strong><br/>
          <span style="font-size:12px;margin-top:4px;display:block;"><strong>Feedback Reason:</strong> ${app.rejection_reason || 'Standards not met'}</span>
        </div>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Resend rejection letter email to ${app.email}?');">
          <input type="hidden" name="action" value="resend_application_email"/>
          <input type="hidden" name="application_id" value="${app.id}"/>
          <button type="submit" class="act-btn" style="background:#FFF;border:1px solid #FCA5A5;color:#991B1B;padding:5px 12px;font-size:12px;">
            ✉ Resend Rejection Letter
          </button>
        </form>
      </div>`;
  } else {
    actionBtns.style.display = 'flex';
    statusBox.style.display = 'block';
    statusBox.style.background = '#FEF3C7';
    statusBox.style.border = '1px solid #FCD34D';
    statusBox.style.color = '#92400E';
    statusBox.innerHTML = '<strong>⏳ Pending Management Review</strong> &bull; Review business credentials and product authenticity above before deciding.';
  }

  document.getElementById('app-review-modal').classList.add('open');
}

function closeAppReview() {
  document.getElementById('app-review-modal').classList.remove('open');
}

function handleRejectPreset(sel) {
  if (sel.value) {
    document.getElementById('rejectReasonText').value = sel.value;
  }
}

// ── SMTP Settings Modal Helpers ──
function openSmtpModal() {
  document.getElementById('smtp-settings-modal').classList.add('open');
}

function closeSmtpModal() {
  document.getElementById('smtp-settings-modal').classList.remove('open');
}

// Global modal close on backdrop / escape
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) {
      el.classList.remove('open');
    }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(el => el.classList.remove('open'));
  }
});
</script>
</body>
</html>