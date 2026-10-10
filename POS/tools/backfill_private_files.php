<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/private_storage.php';
try {
    $pdo = get_db();
    require_runtime_schema($pdo);
    $count = 0;
    foreach (['resumes', 'attendance', 'supplier_permits', 'invoices', 'receipts', 'avatars'] as $kind) {
        $directory = __DIR__ . '/../uploads/' . $kind;
        if (!is_dir($directory)) continue;
        foreach (new DirectoryIterator($directory) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getFilename() === '.gitkeep') continue;
            $logical = validate_private_logical_path('uploads/' . $kind . '/' . $file->getFilename());
            $target = private_upload_directory($kind) . DIRECTORY_SEPARATOR . $file->getFilename();
            if (!is_file($target) && !copy($file->getPathname(), $target)) throw new RuntimeException('Copy failed.');
            if (hash_file('sha256', $file->getPathname()) !== hash_file('sha256', $target)) throw new RuntimeException('Checksum mismatch.');
            chmod($target, 0600);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($target);
            if (!is_string($mime)) throw new RuntimeException('MIME detection failed.');
            register_private_file($pdo, $logical, $target, $mime);
            $count++;
        }
    }
    echo $count . " private files copied and verified; originals preserved.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backfill failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}
