<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function path_within(string $path, string $directory): bool
{
    $path = str_replace('\\', '/', $path);
    if (PHP_OS_FAMILY === 'Windows') $path = strtolower($path);
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    if (PHP_OS_FAMILY === 'Windows') $directory = strtolower($directory);
    return $path === $directory || str_starts_with($path, $directory . '/');
}

function private_storage_root(): string
{
    $configured = app_setting('PRIVATE_STORAGE_ROOT');
    if ($configured === '' || !preg_match('#^(?:[A-Za-z]:[/\\\\]|/)#', $configured)) {
        throw new SecurityFault('STORAGE_NOT_CONFIGURED', 'Private storage is unavailable.', 503);
    }
    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new SecurityFault('STORAGE_UNAVAILABLE', 'Private storage is unavailable.', 503);
    }
    $root = realpath($configured);
    $webRoot = realpath(app_setting('WEB_DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3)));
    if ($root === false || path_within($root, (string)realpath(dirname(__DIR__))) || ($webRoot !== false && path_within($root, $webRoot))) {
        throw new SecurityFault('STORAGE_CONFIGURATION_INVALID', 'Private storage must be outside the web document root.', 503);
    }
    return $root;
}

function validate_private_logical_path(string $logical): string
{
    if (!preg_match('#^uploads/(resumes|attendance|supplier_permits|invoices|receipts|avatars)/[A-Za-z0-9_.-]{1,200}$#D', $logical)
        || str_contains($logical, '..')) {
        throw new SecurityFault('FILE_PATH_INVALID', 'Invalid file path.', 400);
    }
    return $logical;
}

function private_upload_directory(string $kind): string
{
    validate_private_logical_path('uploads/' . $kind . '/check');
    $root = private_storage_root();
    $directory = $root . DIRECTORY_SEPARATOR . $kind;
    if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
        throw new SecurityFault('STORAGE_UNAVAILABLE', 'Private storage is unavailable.', 503);
    }
    $resolved = realpath($directory);
    if ($resolved === false || !path_within($resolved, $root)) {
        throw new SecurityFault('FILE_PATH_INVALID', 'Invalid storage directory.', 503);
    }
    return $resolved;
}

/** @return array{mime:string,size:int} */
function validate_private_file(string $path, string $extension, int $maximum): array
{
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > $maximum) {
        throw new SecurityFault('FILE_SIZE_INVALID', 'File size exceeds the allowed limit.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed = ['pdf' => ['application/pdf'], 'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif']];
    if ($extension === 'docx') {
        if (!in_array($mime, ['application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            throw new SecurityFault('FILE_TYPE_INVALID', 'File content does not match an allowed format.');
        }
        if (!class_exists('ZipArchive')) {
            throw new SecurityFault('FILE_VALIDATION_UNAVAILABLE', 'Document validation is unavailable.', 503);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new SecurityFault('FILE_TYPE_INVALID', 'File content does not match an allowed format.');
        }
        try {
            $valid = $zip->numFiles < 1000 && $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
            $expanded = 0;
            for ($index = 0; $valid && $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    $valid = false;
                    break;
                }
                $expanded += $entry['size'];
                $valid = $expanded <= 50 * 1024 * 1024;
            }
            if (!$valid) {
                throw new SecurityFault('FILE_TYPE_INVALID', 'Document contents exceed the allowed limits or are incomplete.');
            }
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        } finally {
            $zip->close();
        }
    }
    if (!is_string($mime) || !isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
        throw new SecurityFault('FILE_TYPE_INVALID', 'File content does not match an allowed format.');
    }
    if (str_starts_with($mime, 'image/')) {
        $dimensions = getimagesize($path);
        if (!$dimensions || $dimensions[0] > 4096 || $dimensions[1] > 4096 || $dimensions[0] * $dimensions[1] > 16000000) {
            throw new SecurityFault('IMAGE_INVALID', 'Image dimensions are invalid or too large.');
        }
    }
    return ['mime' => $mime, 'size' => $size];
}

function register_private_file(PDO $pdo, string $logical, string $physical, string $mime): void
{
    validate_private_logical_path($logical);
    $resolved = realpath($physical);
    if ($resolved === false || !path_within($resolved, private_storage_root())) {
        throw new SecurityFault('FILE_PATH_INVALID', 'Invalid storage path.', 503);
    }
    $hash = hash_file('sha256', $physical);
    if ($hash === false) throw new SecurityFault('STORAGE_UNAVAILABLE', 'File could not be verified.', 503);
    $pdo->prepare('INSERT INTO private_files (logical_path, storage_name, sha256, mime_type, byte_size) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE sha256 = VALUES(sha256), mime_type = VALUES(mime_type), byte_size = VALUES(byte_size)')
        ->execute([$logical, hash('sha256', $logical), $hash, $mime, filesize($physical)]);
}

function private_move_uploaded_file(string $source, string $destination): bool
{
    if (!is_uploaded_file($source)) throw new SecurityFault('UPLOAD_INVALID', 'Invalid upload.');
    $kind = basename(dirname($destination));
    $logical = validate_private_logical_path('uploads/' . $kind . '/' . basename($destination));
    $expected = private_upload_directory($kind) . DIRECTORY_SEPARATOR . basename($destination);
    $file = validate_private_file($source, strtolower(pathinfo($logical, PATHINFO_EXTENSION)), in_array($kind, ['resumes', 'avatars', 'attendance'], true) ? 5 * 1024 * 1024 : 10 * 1024 * 1024);
    if (is_file($expected) || !move_uploaded_file($source, $expected)) {
        throw new SecurityFault('STORAGE_UNAVAILABLE', 'File could not be saved.', 503);
    }
    chmod($expected, 0600);
    register_private_file(get_db(), $logical, $expected, $file['mime']);
    return true;
}

function private_write_file(string $destination, string $content, bool $generatedHtml = false): int
{
    $kind = basename(dirname($destination));
    $logical = validate_private_logical_path('uploads/' . $kind . '/' . basename($destination));
    $physical = private_upload_directory($kind) . DIRECTORY_SEPARATOR . basename($destination);
    if (strlen($content) > 5 * 1024 * 1024 || is_file($physical)) throw new SecurityFault('FILE_SIZE_INVALID', 'File cannot be saved.');
    $result = file_put_contents($physical, $content, LOCK_EX);
    if ($result === false) throw new SecurityFault('STORAGE_UNAVAILABLE', 'File could not be saved.', 503);
    $mime = $generatedHtml ? 'text/html' : validate_private_file($physical, strtolower(pathinfo($logical, PATHINFO_EXTENSION)), 5 * 1024 * 1024)['mime'];
    chmod($physical, 0600);
    register_private_file(get_db(), $logical, $physical, $mime);
    return $result;
}

function can_read_private_file(PDO $pdo, string $logical, int $userId): bool
{
    validate_private_logical_path($logical);
    $kind = explode('/', $logical)[1];
    if ($kind === 'avatars') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE avatar_path = ?');
        $stmt->execute([$logical]);
        $owner = $stmt->fetchColumn();
        return $owner !== false && ((int)$owner === $userId || has_permission('users.manage') || has_permission('login_approval.manage'));
    }
    if ($kind === 'attendance') {
        $stmt = $pdo->prepare('SELECT e.user_id FROM attendance a JOIN employees e ON e.id = a.employee_id WHERE a.time_in_photo = ? OR a.time_out_photo = ? LIMIT 1');
        $stmt->execute([$logical, $logical]);
        $owner = $stmt->fetchColumn();
        return $owner !== false && ((int)$owner === $userId || has_permission('attendance.view'));
    }
    if ($kind === 'resumes') {
        $stmt = $pdo->prepare('SELECT id FROM job_applications WHERE resume_path = ? LIMIT 1');
        $stmt->execute([$logical]);
        return (bool)$stmt->fetchColumn() && has_permission('recruitment.manage');
    }
    if ($kind === 'supplier_permits') {
        $stmt = $pdo->prepare('SELECT id FROM supplier_applications WHERE business_permit_path = ? OR authenticity_cert_path = ? OR additional_documents_path = ? LIMIT 1');
        $stmt->execute([$logical, $logical, $logical]);
        if ((bool)$stmt->fetchColumn() && has_permission('procurement.suppliers.manage')) {
            return true;
        }
        try {
            $stmtAtt = $pdo->prepare('SELECT id FROM supplier_application_attachments WHERE file_path = ? LIMIT 1');
            $stmtAtt->execute([$logical]);
            return (bool)$stmtAtt->fetchColumn() && has_permission('procurement.suppliers.manage');
        } catch (Throwable $e) {
            return false;
        }
    }
    if ($kind === 'invoices') {
        $stmt = $pdo->prepare('SELECT s.user_id FROM invoices i JOIN suppliers s ON s.id = i.supplier_id WHERE i.attachment_path = ? LIMIT 1');
        $stmt->execute([$logical]);
    } else {
        $stmt = $pdo->prepare('SELECT s.user_id FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN suppliers s ON s.id = i.supplier_id WHERE p.receipt_attachment_path = ? OR p.supplier_dispute_attachment = ? LIMIT 1');
        $stmt->execute([$logical, $logical]);
    }
    $owner = $stmt->fetchColumn();
    return $owner !== false && ((int)$owner === $userId || has_permission('procurement.view'));
}
