<?php
// ─────────────────────────────────────────────────────────────
//  php/download_file.php
//  Permission-gated reader for files under /uploads.
//  Direct web access to sensitive uploads is denied by .htaccess.
// ─────────────────────────────────────────────────────────────

require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

$rel = ltrim(trim($_GET['f'] ?? ''), '/');

// Only these trees are ever served, and only these extensions.
$allowed_prefixes  = ['uploads/resumes/', 'uploads/attendance/', 'uploads/supplier_permits/'];
$allowed_exts      = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];

$user  = current_user();
$roles = $user['roles'] ?? [$user['role'] ?? ''];

// Each tree needs appropriate permissions; allow HR/Admin/recruitment/procurement managers
$can_download = in_array('admin', $roles, true)
    || has_permission('files.download')
    || (str_starts_with($rel, 'uploads/resumes/') && (
        has_permission('recruitment.manage')
        || has_permission('users.manage')
        || in_array('hr', $roles, true)
        || in_array('manager', $roles, true)
    ))
    || (str_starts_with($rel, 'uploads/attendance/') && (
        has_permission('attendance.view')
        || has_permission('employee_dashboard.view')
        || in_array('hr', $roles, true)
        || in_array('manager', $roles, true)
    ))
    || (str_starts_with($rel, 'uploads/supplier_permits/') && (
        has_permission('procurement.suppliers.manage')
        || has_permission('procurement.manage')
        || has_permission('procurement.view')
        || in_array('procurement', $roles, true)
        || in_array('manager', $roles, true)
    ));

if (!$can_download) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to download this file.');
}

// ── Path validation ───────────────────────────
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
$candidate = __DIR__ . '/../' . $rel;
$full = realpath($candidate);

if ($full === false || !is_file($full)) {
    http_response_code(404);
    exit('File not found on server.');
}

if ($base !== false && !str_starts_with($full, $base)) {
    http_response_code(403);
    exit('Access denied.');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_exts, true)) {
    http_response_code(403);
    exit('File extension not permitted.');
}

// ── Serve File ────────────────────────────────
$mimes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

$mime = $mimes[$ext] ?? 'application/octet-stream';
$download_name = !empty($_GET['name']) ? basename($_GET['name']) : basename($full);
if (!str_ends_with(strtolower($download_name), '.' . $ext)) {
    $download_name .= '.' . $ext;
}

$disposition = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'inline' : 'attachment';

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($download_name) . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');

readfile($full);
exit;