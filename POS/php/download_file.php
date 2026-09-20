<?php
// ─────────────────────────────────────────────────────────────
//  php/download_file.php
//  Permission-gated reader for files under /uploads.
//  Direct web access to that folder is denied by .htaccess.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

$rel = $_GET['f'] ?? '';

// Only these two trees are ever served, and only these extensions.
$allowed_prefixes  = ['uploads/resumes/', 'uploads/attendance/'];
$allowed_exts      = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

// Each tree needs its own permission.
$required_perm = str_starts_with($rel, 'uploads/resumes/')
    ? 'recruitment.manage'
    : 'attendance.view';

if (!has_permission($required_perm) && !has_permission('files.download') && !($required_perm === 'attendance.view' && has_permission('employee_dashboard.view'))) {
    http_response_code(403);
    exit('Forbidden.');
}

// ── Path validation ───────────────────────────
// Reject anything containing traversal sequences before touching
// the filesystem, then confirm the resolved real path is still
// inside the uploads directory.
if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
    http_response_code(400);
    exit('Bad request.');
}

$prefix_ok = false;
foreach ($allowed_prefixes as $p) {
    if (str_starts_with($rel, $p)) { $prefix_ok = true; break; }
}
if (!$prefix_ok) {
    http_response_code(400);
    exit('Bad request.');
}

$base = realpath(__DIR__ . '/../uploads');
$full = realpath(__DIR__ . '/../' . $rel);

if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_exts, true) || !is_file($full)) {
    http_response_code(404);
    exit('Not found.');
}

// ── Serve ─────────────────────────────────────
$mimes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

// Images render inline; documents always download, so a crafted
// file can never be rendered as HTML in the app's origin.
$disposition = in_array($ext, ['jpg', 'jpeg', 'png'], true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . $disposition . '; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');

readfile($full);
exit;