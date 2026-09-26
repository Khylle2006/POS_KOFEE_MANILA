<?php
// Copy to config.local.php and edit. config.local.php is gitignored.
define('DB_HOST', 'localhost');
define('DB_NAME', 'kofeedb');
define('DB_USER', 'root');   // a least-privilege user, not root
define('DB_PASS', '');
define('APP_ENV', 'development');

// ── PayMongo Payment Gateway Configuration ────────────────────
// To accept real online payments (GCash, Maya, Card, QRPH):
// 1. Log in to your PayMongo Dashboard: https://dashboard.paymongo.com/
// 2. Go to Developers > API Keys
// 3. Paste your keys below (starts with sk_test_... or sk_live_...):
//
// For local testing without a PayMongo account:
// Set to 'demo' to enable the built-in demo simulator.
define('PAYMONGO_SECRET_KEY', 'demo');
define('PAYMONGO_PUBLIC_KEY', 'demo');
define('PAYMONGO_WEBHOOK_SECRET', '');

// ── SMTP Email Configuration (PHPMailer) ──────────────────────
// To send real password reset emails to your actual email inbox:
// If using Gmail:
// 1. Enable 2-Step Verification: https://myaccount.google.com/signinoptions/two-step-verification
// 2. Generate a 16-character App Password: https://myaccount.google.com/apppasswords
// 3. Enter your Gmail address and App Password below:
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'roque.khyllechester@ncst.edu.ph'); // Your Gmail address (e.g. kofeemanila@gmail.com)
define('SMTP_PASS', 'sgxl dhaf meby ogwb'); // Your 16-character Google App Password (not your normal password)
define('SMTP_SECURE', 'tls');
define('SMTP_FROM_EMAIL', '');
define('SMTP_FROM_NAME', 'Kofee Manila');