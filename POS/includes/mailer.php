<?php
// ─────────────────────────────────────────────────────────────
//  includes/mailer.php
//  PHPMailer integration with SMTP configuration & templates
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/private_credentials.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Optional local override for SMTP credentials
$local_config = __DIR__ . '/config.local.php';
if (is_file($local_config)) {
    require_once $local_config;
}

// SMTP configuration defaults from constants or environment
if (!defined('SMTP_HOST'))       define('SMTP_HOST',       getenv('SMTP_HOST')       ?: '');
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER'))       define('SMTP_USER',       getenv('SMTP_USER')       ?: '');
if (!defined('SMTP_PASS'))       define('SMTP_PASS',       getenv('SMTP_PASS')       ?: '');
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE',     getenv('SMTP_SECURE')     ?: PHPMailer::ENCRYPTION_STARTTLS);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')  ?: 'Kofee Manila');

/**
 * Get active SMTP configuration combining constants, config file, and procurement_settings database table.
 *
 * @return array{host: string, port: int, user: string, pass: string, secure: string, from_email: string, from_name: string, configured: bool}
 */
function get_kofee_smtp_config(): array {
    $host = app_setting('SMTP_HOST', '');
    $port = (int)app_setting('SMTP_PORT', '587');
    $user = app_setting('SMTP_USER', '');
    $pass = app_setting('SMTP_PASS', '');
    $secure = app_setting('SMTP_SECURE', 'tls');
    $fromEmail = app_setting('SMTP_FROM_EMAIL', '');
    $fromName = app_setting('SMTP_FROM_NAME', 'Kofee Manila');

    // If credentials are missing in constants, check procurement_settings database table
    if (!app_production() && (empty($user) || empty($pass))) {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = get_db();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM procurement_settings WHERE setting_key LIKE 'smtp_%'");
            $dbRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($dbRows['smtp_user']))       $user      = trim($dbRows['smtp_user']);
            if (!empty($dbRows['smtp_pass']))       $pass      = decrypt_private_value(trim($dbRows['smtp_pass']));
            if (!empty($dbRows['smtp_host']))       $host      = trim($dbRows['smtp_host']);
            if (!empty($dbRows['smtp_port']))       $port      = (int)$dbRows['smtp_port'];
            if (!empty($dbRows['smtp_secure']))     $secure    = trim($dbRows['smtp_secure']);
            if (!empty($dbRows['smtp_from_email'])) $fromEmail = trim($dbRows['smtp_from_email']);
            if (!empty($dbRows['smtp_from_name']))  $fromName  = trim($dbRows['smtp_from_name']);
        } catch (Throwable $e) { error_log('request=' . request_id() . ' exception=' . get_class($e)); }
    }

    if (empty($host))      $host = 'smtp.gmail.com';
    if ($port <= 0)        $port = 587;
    if (empty($secure))    $secure = PHPMailer::ENCRYPTION_STARTTLS;
    if (empty($fromEmail)) $fromEmail = !empty($user) ? $user : 'noreply@kofeemanila.com';
    if (empty($fromName))  $fromName = 'Kofee Manila';

    return [
        'host'       => $host,
        'port'       => $port,
        'user'       => $user,
        'pass'       => $pass,
        'secure'     => $secure,
        'from_email' => $fromEmail,
        'from_name'  => $fromName,
        'configured' => (!empty($user) && !empty($pass)),
    ];
}

/**
 * Configure and instantiate a PHPMailer instance.
 */
function get_phpmailer_instance(?array $customConfig = null): PHPMailer {
    $cfg = $customConfig ?: get_kofee_smtp_config();
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    if (!empty($cfg['user']) && !empty($cfg['pass'])) {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['user'];
        $mail->Password   = $cfg['pass'];
        $mail->SMTPSecure = $cfg['secure'];
        $mail->Port       = $cfg['port'];
        $mail->Timeout    = 20;
        // Fix for Windows XAMPP environments where local CA certificates may not be registered
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false
            ]
        ];
    }

    $fromEmail = !empty($cfg['from_email']) ? $cfg['from_email'] : (!empty($cfg['user']) ? $cfg['user'] : 'noreply@kofeemanila.com');
    $mail->setFrom($fromEmail, $cfg['from_name']);
    return $mail;
}

/**
 * Send an HTML email via PHPMailer.
 * Dispatches via SMTP when credentials are provided, or returns simulated status with clear explanation.
 *
 * @return array{ok: bool, sent: bool, simulated?: bool, error?: string, message?: string}
 */
function send_kofee_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = '', ?array $customConfig = null): array {
    if (PHP_SAPI !== 'cli' && $customConfig === null) {
        require_once __DIR__ . '/jobs.php';
        enqueue_job(get_db(), 'email-' . bin2hex(random_bytes(16)), 'email', ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'html' => $htmlBody, 'alt' => $altBody]);
        return ['ok' => true, 'sent' => false, 'queued' => true, 'error' => null];
    }
    $cfg = $customConfig ?: get_kofee_smtp_config();
    $mail = get_phpmailer_instance($cfg);

    try {
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        // If SMTP credentials are provided, dispatch via SMTP
        if (!empty($cfg['user']) && !empty($cfg['pass'])) {
            $mail->send();
            return [
                'ok'      => true,
                'sent'    => true,
                'message' => 'Email sent successfully via SMTP.'
            ];
        }

        // Development / local environment without configured SMTP credentials:
        $sent = false;
        try {
            $sent = @$mail->send();
        } catch (Throwable $e) { error_log('request=' . request_id() . ' exception=' . get_class($e)); }

        return [
            'ok'        => $sent,
            'sent'      => $sent,
            'simulated' => !$sent,
            'error'     => $sent ? null : 'SMTP credentials (Email & App Password) are not configured.',
            'message'   => $sent ? 'Email sent successfully.' : 'Email was not sent because SMTP credentials are not configured.'
        ];
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . 'Service temporarily unavailable.');
        return [
            'ok'      => false,
            'sent'    => false,
            'error'   => 'Service temporarily unavailable.',
            'message' => 'PHPMailer error: ' . 'Service temporarily unavailable.'
        ];
    }
}

/**
 * Test SMTP connection by sending a diagnostic test message to the specified recipient.
 */
function test_smtp_connection(string $testEmail, ?array $overrideConfig = null): array {
    $cfg = $overrideConfig ?: get_kofee_smtp_config();
    if (empty($cfg['user']) || empty($cfg['pass'])) {
        return [
            'ok'    => false,
            'error' => 'SMTP Username (email) and Password (app password) are required.'
        ];
    }

    $subject = 'Kofee Manila — SMTP Email Diagnostic Test';
    $htmlBody = '
    <div style="font-family:Arial,sans-serif;background:#FAF7F2;padding:24px;border-radius:12px;border:1px solid #EFE0CC;max-width:520px;">
      <h2 style="color:#241A2E;margin-top:0;">☕ Kofee Manila SMTP Test</h2>
      <p style="color:#5B4F5E;font-size:14px;line-height:1.6;">
        This is a diagnostic verification email sent from your Kofee Manila procurement portal.
      </p>
      <div style="background:#DCFCE7;border:1px solid #86EFAC;color:#166534;padding:12px 16px;border-radius:8px;font-size:13px;font-weight:bold;margin:16px 0;">
        ✓ SMTP Connection and Authentication Succeeded!
      </div>
      <table style="font-size:12.5px;color:#5B4F5E;line-height:1.6;">
        <tr><td style="font-weight:bold;padding-right:12px;">Host:</td><td>' . htmlspecialchars($cfg['host']) . ':' . htmlspecialchars($cfg['port']) . '</td></tr>
        <tr><td style="font-weight:bold;padding-right:12px;">User:</td><td>' . htmlspecialchars($cfg['user']) . '</td></tr>
        <tr><td style="font-weight:bold;padding-right:12px;">Security:</td><td>' . htmlspecialchars($cfg['secure']) . '</td></tr>
        <tr><td style="font-weight:bold;padding-right:12px;">Timestamp:</td><td>' . date('Y-m-d H:i:s T') . '</td></tr>
      </table>
      <p style="font-size:12px;color:#8B7C88;margin-top:20px;border-top:1px dashed #E2D9CE;padding-top:12px;">
        Supplier approval notifications and rejection letters will now be delivered reliably.
      </p>
    </div>';

    return send_kofee_email($testEmail, 'Kofee Admin Test', $subject, $htmlBody, '', $cfg);
}

/**
 * Persist SMTP settings to both procurement_settings table and includes/config.local.php.
 */
function save_kofee_smtp_settings(array $params): array {
    if (app_production()) throw new SecurityFault('CREDENTIALS_ENVIRONMENT_MANAGED', 'Production credentials must be provisioned through the server environment.', 409);
    require_recent_password();
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_db();

        $host      = trim($params['smtp_host'] ?? 'smtp.gmail.com') ?: 'smtp.gmail.com';
        $port      = (int)($params['smtp_port'] ?? 587) ?: 587;
        $user      = trim($params['smtp_user'] ?? '');
        $pass      = trim($params['smtp_pass'] ?? '');
        $secure    = trim($params['smtp_secure'] ?? 'tls') ?: 'tls';
        $fromEmail = trim($params['smtp_from_email'] ?? '') ?: $user;
        $fromName  = trim($params['smtp_from_name'] ?? 'Kofee Manila') ?: 'Kofee Manila';

        // 1. Save in procurement_settings table
        $settingsMap = [
            'smtp_host'       => $host,
            'smtp_port'       => (string)$port,
            'smtp_user'       => $user,
            'smtp_pass'       => encrypt_private_value($pass),
            'smtp_secure'     => $secure,
            'smtp_from_email' => $fromEmail,
            'smtp_from_name'  => $fromName,
        ];

        $ins = $pdo->prepare('
            INSERT INTO procurement_settings (setting_key, setting_value, description)
            VALUES (:k, :v, :d)
            ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()
        ');

        foreach ($settingsMap as $k => $v) {
            $ins->execute([
                ':k'  => $k,
                ':v'  => $v,
                ':d'  => 'SMTP Configuration: ' . $k,
                ':v2' => $v,
            ]);
        }

        security_audit($pdo, 'smtp_settings_changed', 'configuration');

        return ['ok' => true, 'message' => 'SMTP settings saved successfully!'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Service temporarily unavailable.'];
    }
}

/**
 * Send a branded password reset email.
 */
function send_password_reset_email(string $toEmail, string $userName, string $resetLink): array {
    $subject = 'Reset Your Kofee Manila Password';

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #F5EDE0; margin: 0; padding: 24px; }
        .container { max-width: 520px; margin: 0 auto; background: #FFFFFF; border-radius: 18px; overflow: hidden; box-shadow: 0 10px 30px rgba(36,26,46,0.1); border: 1px solid #EFE0CC; }
        .header { background: linear-gradient(135deg, #241A2E 0%, #181120 100%); padding: 32px 24px; text-align: center; }
        .header h1 { font-family: Georgia, serif; color: #FBF3E9; font-size: 24px; margin: 0; letter-spacing: 0.5px; }
        .header p { color: #E6A25C; font-size: 13px; margin: 6px 0 0; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; }
        .body { padding: 32px 28px; color: #2B2130; }
        .body h2 { font-size: 18px; color: #241A2E; margin-top: 0; font-weight: 700; }
        .body p { font-size: 14px; line-height: 1.6; color: #5B4F5E; margin-bottom: 16px; }
        .btn-wrap { text-align: center; margin: 30px 0; }
        .btn { display: inline-block; background: linear-gradient(135deg, #C97B3D 0%, #181120 140%); color: #FFFFFF !important; text-decoration: none; padding: 14px 32px; font-size: 14px; font-weight: 700; border-radius: 10px; box-shadow: 0 6px 16px rgba(201,123,61,0.35); }
        .footer { background: #FAF5EE; padding: 20px 24px; text-align: center; font-size: 12px; color: #8B7C88; border-top: 1px solid #EFE0CC; }
        .link-alt { word-break: break-all; font-size: 12px; color: #C97B3D; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h1>Kofee Manila</h1>
          <p>Security &amp; Account Access</p>
        </div>
        <div class="body">
          <h2>Password Reset Request</h2>
          <p>Hello <b>' . htmlspecialchars($userName) . '</b>,</p>
          <p>We received a request to reset the password for your Kofee Manila account. Click the button below to choose a new password:</p>
          <div class="btn-wrap">
            <a href="' . htmlspecialchars($resetLink) . '" class="btn" target="_blank">Reset Password</a>
          </div>
          <p>This password reset link will expire in <b>30 minutes</b>. If you did not request a password reset, you can safely ignore this email — your password will remain unchanged.</p>
          <p style="margin-top:24px;font-size:12px;color:#8B7C88;">If the button above does not work, copy and paste this link into your browser:</p>
          <p class="link-alt">' . htmlspecialchars($resetLink) . '</p>
        </div>
        <div class="footer">
          &copy; ' . date('Y') . ' Kofee Manila POS System. All rights reserved.
        </div>
      </div>
    </body>
    </html>
    ';

    return send_kofee_email($toEmail, $userName, $subject, $html);
}

/** Send an application receipt with the candidate's tracking code and status link. */
function send_application_confirmation_email(
    string $toEmail,
    string $candidateName,
    string $jobTitle,
    string $trackingCode,
    string $trackingUrl
): array {
    $safeName = htmlspecialchars($candidateName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeJob = htmlspecialchars($jobTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($trackingCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = 'Application Received — Kofee Manila';

    $html = '
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body style="margin:0;padding:24px;background:#F5EDE0;font-family:Arial,sans-serif;color:#2B2130">
      <div style="max-width:560px;margin:auto;background:#fff;border:1px solid #EFE0CC;border-radius:14px;overflow:hidden">
        <div style="padding:24px;background:#241A2E;color:#FBF3E9;text-align:center">
          <h1 style="margin:0;font-family:Georgia,serif">Kofee Manila</h1>
          <p style="margin:8px 0 0;color:#E6A25C">CAREERS</p>
        </div>
        <div style="padding:28px">
          <h2 style="margin-top:0;color:#241A2E">Application received</h2>
          <p>Hello ' . $safeName . ',</p>
          <p>We received your application for <strong>' . $safeJob . '</strong>. Our recruitment team will review it and contact you if you are shortlisted.</p>
          <p>Your application tracking code:</p>
          <p style="padding:14px;background:#FAF7F2;border:1px dashed #C97B3D;border-radius:8px;text-align:center;font-family:monospace;font-size:20px;font-weight:bold">' . $safeCode . '</p>
          <p style="text-align:center;margin:26px 0">
            <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 22px;background:#241A2E;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">Track my application</a>
          </p>
          <p style="font-size:13px;color:#6B5D6B">You can also track your application from the Careers page using the code above or this email address.</p>
        </div>
        <div style="padding:16px;background:#FAF5EE;border-top:1px solid #EFE0CC;text-align:center;color:#8B7C88;font-size:12px">&copy; ' . date('Y') . ' Kofee Manila</div>
      </div>
    </body></html>';

    $alt = "Hello {$candidateName},\n\nWe received your application for {$jobTitle}.\nTracking code: {$trackingCode}\nTrack its status: {$trackingUrl}\n\nKofee Manila Careers";
    return send_kofee_email($toEmail, $candidateName, $subject, $html, $alt);
}

/**
 * Send an acknowledgement email to a supplier applicant with tracking code.
 */
function send_supplier_application_confirmation_email(
    string $toEmail,
    string $companyName,
    string $contactPerson,
    string $productName,
    string $trackingCode,
    string $trackingUrl
): array {
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeContact = htmlspecialchars($contactPerson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeProduct = htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode    = htmlspecialchars($trackingCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl     = htmlspecialchars($trackingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject     = 'Supplier Application Received — Kofee Manila Partnership';

    $html = '
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body style="margin:0;padding:24px;background:#F5EDE0;font-family:Arial,sans-serif;color:#2B2130">
      <div style="max-width:580px;margin:auto;background:#fff;border:1px solid #EFE0CC;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(36,26,46,0.06)">
        <div style="padding:28px 24px;background:linear-gradient(135deg, #241A2E 0%, #181120 100%);color:#FBF3E9;text-align:center">
          <h1 style="margin:0;font-family:Georgia,serif;font-size:24px;letter-spacing:0.5px">Kofee Manila</h1>
          <p style="margin:6px 0 0;color:#E6A25C;font-size:12px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase">PROCUREMENT &amp; PARTNERSHIPS</p>
        </div>
        <div style="padding:32px 28px">
          <h2 style="margin-top:0;color:#241A2E;font-size:20px">Supplier Application Acknowledged</h2>
          <p style="font-size:14.5px;line-height:1.6;color:#5B4F5E">Dear ' . $safeContact . ' (' . $safeCompany . '),</p>
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">Thank you for submitting your application to become an accredited supplier for <strong>' . $safeProduct . '</strong> at Kofee Manila.</p>
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">Our procurement and quality assurance team has received your business permits and product authenticity credentials. We will conduct a thorough compliance and quality review.</p>
          
          <div style="margin:24px 0;padding:18px;background:#FAF7F2;border:1.5px dashed #C97B3D;border-radius:10px;text-align:center">
            <span style="font-size:11px;font-weight:700;color:#8B7C88;letter-spacing:1px;text-transform:uppercase">Your Application Tracking Code</span>
            <div style="font-family:monospace;font-size:22px;font-weight:bold;color:#241A2E;margin-top:4px">' . $safeCode . '</div>
          </div>

          <p style="text-align:center;margin:28px 0">
            <a href="' . $safeUrl . '" style="display:inline-block;padding:13px 26px;background:linear-gradient(135deg,#C97B3D,#241A2E);color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;box-shadow:0 4px 12px rgba(201,123,61,0.3)">Track Application Status</a>
          </p>

          <p style="font-size:12.5px;line-height:1.6;color:#8B7C88">You can also track your application status anytime at our public portal using this code or your registered email address.</p>
        </div>
        <div style="padding:16px;background:#FAF5EE;border-top:1px solid #EFE0CC;text-align:center;color:#8B7C88;font-size:12px">&copy; ' . date('Y') . ' Kofee Manila Procurement Team. All rights reserved.</div>
      </div>
    </body></html>';

    $alt = "Dear {$contactPerson} ({$companyName}),\n\nThank you for applying to be an accredited supplier for {$productName} at Kofee Manila.\n\nYour application tracking code: {$trackingCode}\nTrack status: {$trackingUrl}\n\nKofee Manila Procurement Team";
    return send_kofee_email($toEmail, $companyName, $subject, $html, $alt);
}

/**
 * Send an approval notification with login credentials and supplier portal onboarding guide.
 */
function send_supplier_approval_email(
    string $toEmail,
    string $companyName,
    string $contactPerson,
    string $productName,
    string $username,
    string $tempPassword,
    string $loginUrl
): array {
    $safeCompany  = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeContact  = htmlspecialchars($contactPerson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeProduct  = htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePassword = htmlspecialchars($tempPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl      = htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject      = 'Partnership Approved: Welcome to Kofee Manila Supplier Network';

    $html = '
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body style="margin:0;padding:24px;background:#F5EDE0;font-family:Arial,sans-serif;color:#2B2130">
      <div style="max-width:580px;margin:auto;background:#fff;border:1px solid #EFE0CC;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(36,26,46,0.06)">
        <div style="padding:28px 24px;background:linear-gradient(135deg, #1A3E2A 0%, #241A2E 100%);color:#FBF3E9;text-align:center">
          <h1 style="margin:0;font-family:Georgia,serif;font-size:24px;letter-spacing:0.5px">Kofee Manila</h1>
          <p style="margin:6px 0 0;color:#78D692;font-size:12px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase">SUPPLIER PARTNERSHIP APPROVED</p>
        </div>
        <div style="padding:32px 28px">
          <div style="display:inline-block;padding:6px 14px;background:#DCFCE7;color:#166534;font-size:12px;font-weight:700;border-radius:999px;margin-bottom:14px">&#10003; Accreditation Approved</div>
          <h2 style="margin-top:0;color:#241A2E;font-size:21px">Welcome to Our Supplier Network!</h2>
          <p style="font-size:14.5px;line-height:1.6;color:#5B4F5E">Dear ' . $safeContact . ' (' . $safeCompany . '),</p>
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">Congratulations! Following careful review of your business permits, authenticity certificates, and product specifications for <strong>' . $safeProduct . '</strong>, we are pleased to inform you that your application has been <strong>approved</strong>.</p>
          
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">Your official supplier account and portal profile have been created. You can now log into the <strong>Kofee Manila Supplier Portal</strong> to participate in Requests for Quotations (RFQs), view purchase orders, issue delivery notices, and track invoice disbursements.</p>

          <!-- Credentials Box -->
          <div style="margin:22px 0;background:#FAF7F2;border:1px solid #E2D9CE;border-radius:10px;padding:20px">
            <h4 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:0.8px;color:#8B7C88">Your Login Credentials</h4>
            <div style="display:flex;margin-bottom:8px">
              <span style="width:120px;font-size:13px;color:#8B7C88">Username:</span>
              <strong style="font-size:14px;color:#241A2E;font-family:monospace">' . $safeUsername . '</strong>
            </div>
            <div style="display:flex;margin-bottom:8px">
              <span style="width:120px;font-size:13px;color:#8B7C88">Temporary Pass:</span>
              <strong style="font-size:14px;color:#241A2E;font-family:monospace;background:#FFF;padding:2px 8px;border-radius:4px;border:1px dashed #C97B3D">' . $safePassword . '</strong>
            </div>
            <div style="font-size:11.5px;color:#8B7C88;margin-top:8px;font-style:italic">Please change your temporary password after logging in for the first time.</div>
          </div>

          <p style="text-align:center;margin:28px 0">
            <a href="' . $safeUrl . '" style="display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#C97B3D,#241A2E);color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;box-shadow:0 4px 14px rgba(201,123,61,0.35)">Log into Supplier Portal</a>
          </p>

          <p style="font-size:13px;line-height:1.6;color:#5B4F5E">We look forward to an honest, quality-driven, and long-term partnership with ' . $safeCompany . '.</p>
        </div>
        <div style="padding:16px;background:#FAF5EE;border-top:1px solid #EFE0CC;text-align:center;color:#8B7C88;font-size:12px">&copy; ' . date('Y') . ' Kofee Manila Procurement Management</div>
      </div>
    </body></html>';

    $alt = "Dear {$contactPerson} ({$companyName}),\n\nCongratulations! Your supplier application for {$productName} has been approved by Kofee Manila.\n\nYour Portal Credentials:\nUsername: {$username}\nTemporary Password: {$tempPassword}\nLogin Portal: {$loginUrl}\n\nPlease change your temporary password upon logging in.\n\nKofee Manila Procurement Management";
    return send_kofee_email($toEmail, $companyName, $subject, $html, $alt);
}

/**
 * Send a formal rejection letter with management's specific feedback and reason.
 */
function send_supplier_rejection_email(
    string $toEmail,
    string $companyName,
    string $contactPerson,
    string $productName,
    string $rejectionReason
): array {
    $safeCompany = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeContact = htmlspecialchars($contactPerson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeProduct = htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeReason  = nl2br(htmlspecialchars($rejectionReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $subject     = 'Update on Your Supplier Application — Kofee Manila';

    $html = '
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"></head>
    <body style="margin:0;padding:24px;background:#F5EDE0;font-family:Arial,sans-serif;color:#2B2130">
      <div style="max-width:580px;margin:auto;background:#fff;border:1px solid #EFE0CC;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(36,26,46,0.06)">
        <div style="padding:28px 24px;background:linear-gradient(135deg, #241A2E 0%, #181120 100%);color:#FBF3E9;text-align:center">
          <h1 style="margin:0;font-family:Georgia,serif;font-size:24px;letter-spacing:0.5px">Kofee Manila</h1>
          <p style="margin:6px 0 0;color:#E6A25C;font-size:12px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase">PROCUREMENT DEPARTMENT</p>
        </div>
        <div style="padding:32px 28px">
          <h2 style="margin-top:0;color:#241A2E;font-size:20px">Supplier Application Evaluation Notice</h2>
          <p style="font-size:14.5px;line-height:1.6;color:#5B4F5E">Dear ' . $safeContact . ' (' . $safeCompany . '),</p>
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">Thank you for taking the time to submit your application and supporting documentation to supply <strong>' . $safeProduct . '</strong> to Kofee Manila.</p>
          <p style="font-size:14px;line-height:1.6;color:#5B4F5E">After careful review of our current procurement demands, supplier quotas, and quality/compliance criteria, we regret to inform you that we are unable to approve your application at this time.</p>
          
          <!-- Rejection Letter / Reason Card -->
          <div style="margin:22px 0;background:#FFF9F9;border:1px solid #FEE2E2;border-left:4px solid #EF4444;border-radius:8px;padding:18px">
            <h4 style="margin:0 0 8px;font-size:12.5px;text-transform:uppercase;letter-spacing:0.8px;color:#991B1B">Reason for Rejection / Procurement Feedback</h4>
            <div style="font-size:13.5px;line-height:1.6;color:#374151">' . $safeReason . '</div>
          </div>

          <p style="font-size:13.5px;line-height:1.6;color:#5B4F5E">We genuinely appreciate your interest in collaborating with Kofee Manila. You are welcome to address any feedback outlined above and submit a new application during our subsequent procurement review cycles.</p>
          <p style="font-size:13.5px;line-height:1.6;color:#5B4F5E">We wish ' . $safeCompany . ' continued success in all your business operations.</p>
        </div>
        <div style="padding:16px;background:#FAF5EE;border-top:1px solid #EFE0CC;text-align:center;color:#8B7C88;font-size:12px">&copy; ' . date('Y') . ' Kofee Manila Procurement Department</div>
      </div>
    </body></html>';

    $alt = "Dear {$contactPerson} ({$companyName}),\n\nThank you for applying to supply {$productName} to Kofee Manila.\n\nAfter review, we regret to inform you that we are unable to proceed with your application at this time.\n\nReason for Rejection / Feedback:\n{$rejectionReason}\n\nWe appreciate your interest in Kofee Manila.\n\nKofee Manila Procurement Department";
    return send_kofee_email($toEmail, $companyName, $subject, $html, $alt);
}


