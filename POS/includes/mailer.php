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
 * Send a branded Multi-Factor Authentication (MFA) verification code email.
 */
function send_mfa_code_email(string $toEmail, string $userName, string $code, int $expiresMinutes = 10): array {
    $safeName = htmlspecialchars($userName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject  = "Your BrewVanti Sign In Code: {$safeCode}";

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #0D0709; margin: 0; padding: 24px; color: #FAF7F2; }
        .container { max-width: 520px; margin: 0 auto; background: #1E1517; border-radius: 18px; overflow: hidden; box-shadow: 0 14px 40px rgba(0,0,0,0.5); border: 1px solid rgba(217, 186, 133, 0.25); }
        .header { background: linear-gradient(135deg, #14080D 0%, #2A131A 100%); padding: 32px 24px; text-align: center; border-bottom: 1px solid rgba(217, 186, 133, 0.2); }
        .header h1 { font-family: Georgia, serif; color: #FAF7F2; font-size: 26px; margin: 0; letter-spacing: 0.5px; }
        .header p { color: #ECC98F; font-size: 12px; margin: 6px 0 0; text-transform: uppercase; letter-spacing: 2px; font-weight: 700; }
        .body { padding: 32px 28px; }
        .body h2 { font-size: 19px; color: #FAF7F2; margin-top: 0; font-weight: 700; }
        .body p { font-size: 14px; line-height: 1.6; color: #E8DFD5; margin-bottom: 16px; }
        .code-box { background: rgba(255, 255, 255, 0.05); border: 2px dashed #ECC98F; border-radius: 14px; padding: 22px 16px; text-align: center; margin: 26px 0; }
        .code-digits { font-family: "Courier New", Courier, monospace; font-size: 38px; font-weight: 800; letter-spacing: 12px; color: #ECC98F; text-shadow: 0 0 15px rgba(236, 201, 143, 0.4); }
        .code-sub { font-size: 12px; color: #C4B5A5; margin-top: 10px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 600; }
        .tip-box { background: rgba(217, 186, 133, 0.08); border-left: 3px solid #ECC98F; padding: 14px 16px; border-radius: 0 10px 10px 0; margin-top: 24px; }
        .tip-box p { font-size: 12.5px; line-height: 1.5; color: #E8DFD5; margin: 0; }
        .footer { background: #12090B; padding: 20px 24px; text-align: center; font-size: 12px; color: #9C8C7E; border-top: 1px solid rgba(217, 186, 133, 0.15); }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h1>BrewVanti</h1>
          <p>Two-Factor Authentication &bull; Sign In Verification</p>
        </div>
        <div class="body">
          <h2>Sign In Verification Code</h2>
          <p>Hello <b>' . $safeName . '</b>,</p>
          <p>A sign-in attempt was detected for your BrewVanti account. Use the 6-digit verification code below to authorize your session:</p>
          <div class="code-box">
            <div class="code-digits">' . $safeCode . '</div>
            <div class="code-sub">Valid for ' . (int)$expiresMinutes . ' minutes</div>
          </div>
          <div class="tip-box">
            <p>🔒 <b>Security Reminder:</b> Never share this code with anyone. BrewVanti and Kofee Manila staff will never ask for your verification code.</p>
          </div>
          <p style="margin-top:22px;font-size:12px;color:#A99A8E;">If you did not initiate this sign-in attempt, please notify your manager or change your account credentials immediately.</p>
        </div>
        <div class="footer">
          &copy; ' . date('Y') . ' BrewVanti &bull; Kofee Manila POS System. All rights reserved.
        </div>
      </div>
    </body>
    </html>';

    $altBody = "Hello {$userName},\n\nYour BrewVanti sign-in verification code is: {$code}\n\nThis code is valid for {$expiresMinutes} minutes.\nNever share this code with anyone.\n\nBrewVanti POS";
    return send_kofee_email($toEmail, $userName, $subject, $html, $altBody);
}

