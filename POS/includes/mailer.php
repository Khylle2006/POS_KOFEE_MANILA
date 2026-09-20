<?php
// ─────────────────────────────────────────────────────────────
//  includes/mailer.php
//  PHPMailer integration with SMTP configuration & templates
// ─────────────────────────────────────────────────────────────

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

// SMTP configuration defaults
if (!defined('SMTP_HOST'))       define('SMTP_HOST',       getenv('SMTP_HOST')       ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT'))       define('SMTP_PORT',       (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER'))       define('SMTP_USER',       getenv('SMTP_USER')       ?: '');
if (!defined('SMTP_PASS'))       define('SMTP_PASS',       getenv('SMTP_PASS')       ?: '');
if (!defined('SMTP_SECURE'))     define('SMTP_SECURE',     getenv('SMTP_SECURE')     ?: PHPMailer::ENCRYPTION_STARTTLS);
if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: (!empty(SMTP_USER) ? SMTP_USER : 'noreply@kofeemanila.com'));
if (!defined('SMTP_FROM_NAME'))  define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')  ?: 'Kofee Manila');

/**
 * Configure and instantiate a PHPMailer instance.
 */
function get_phpmailer_instance(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    if (!empty(SMTP_USER) && !empty(SMTP_PASS)) {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout    = 15;
        // Fix for Windows XAMPP environments where local CA certificates may not be registered
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];
    }

    $fromEmail = !empty(SMTP_FROM_EMAIL) ? SMTP_FROM_EMAIL : (!empty(SMTP_USER) ? SMTP_USER : 'noreply@kofeemanila.com');
    $mail->setFrom($fromEmail, SMTP_FROM_NAME);
    return $mail;
}

/**
 * Send an HTML email via PHPMailer.
 * In local environment without SMTP credentials, it gracefully logs and simulates delivery.
 *
 * @return array{ok: bool, sent: bool, simulated?: bool, error?: string, message?: string}
 */
function send_kofee_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): array {
    $mail = get_phpmailer_instance();

    try {
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        // If SMTP credentials are provided, dispatch via SMTP
        if (!empty(SMTP_USER) && !empty(SMTP_PASS)) {
            $mail->send();
            return ['ok' => true, 'sent' => true, 'message' => 'Email sent successfully.'];
        }

        // In development / local environment without configured SMTP credentials:
        $sent = false;
        try {
            $sent = @$mail->send();
        } catch (Throwable $e) {
            // Local mail transfer agent not configured
        }

        return [
            'ok'        => true,
            'sent'      => $sent,
            'simulated' => !$sent,
            'message'   => $sent ? 'Email sent successfully.' : 'Email queued / simulated in local environment.'
        ];
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return ['ok' => false, 'sent' => false, 'error' => $e->getMessage()];
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

