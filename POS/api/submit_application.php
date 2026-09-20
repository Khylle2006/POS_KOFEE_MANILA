<?php
// ─────────────────────────────────────────────────────────────
//  api/submit_application.php — Process Job Applications
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_db();

    // ── Extract form fields ──
    $job_id      = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    $job_slug    = trim($_POST['job_slug'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $experience  = trim($_POST['experience'] ?? '');
    $start_date  = trim($_POST['start_date'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $privacy     = isset($_POST['privacy_consent']) && ($_POST['privacy_consent'] === '1' || $_POST['privacy_consent'] === 'true' || $_POST['privacy_consent'] === 'on');

    // ── Lookup job if only slug was provided ──
    if ($job_id <= 0 && !empty($job_slug)) {
        $stmt = $pdo->prepare('SELECT id, title, slug FROM job_postings WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $stmt->execute([':slug' => $job_slug]);
        $jobRow = $stmt->fetch();
        if ($jobRow) {
            $job_id = (int)$jobRow['id'];
        }
    }

    // ── Validate Job ──
    if ($job_id <= 0) {
        $stmt = $pdo->prepare('SELECT id, title, slug FROM job_postings WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $jobRow = $stmt->fetch();
        if ($jobRow) {
            $job_id = (int)$jobRow['id'];
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing job position.']);
            exit;
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, title, slug FROM job_postings WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $job_id]);
        $jobRow = $stmt->fetch();
        if (!$jobRow) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'The selected position is no longer active.']);
            exit;
        }
    }

    // ── Validate Required Inputs ──
    $errors = [];
    if (empty($first_name))  $errors[] = 'First name is required.';
    if (empty($last_name))   $errors[] = 'Last name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (empty($phone))       $errors[] = 'Mobile number is required.';
    if (empty($city))        $errors[] = 'City / Municipality is required.';
    if (empty($experience))  $errors[] = 'Relevant experience is required.';
    if (empty($start_date))  $errors[] = 'Available start date is required.';
    if (!$privacy)           $errors[] = 'You must agree to the Privacy Notice to submit your application.';

    // ── Validate Resume File ──
    // ── Validate Resume File ──
    if (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Resume file is required (PDF or DOCX, maximum 5 MB).';
    } else {
        $file = $_FILES['resume'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Error uploading file (code ' . (int)$file['error'] . '). Please try again.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid upload.';
        } else {
            if ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Resume file size exceeds the 5 MB limit.';
            }

            // Trust the file's magic bytes, not its name. An extension
            // check alone lets an attacker upload anything they like.
            require_once __DIR__ . '/../includes/security.php';
            $mime = detect_upload_mime($file['tmp_name']);

            $mime_to_ext = [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];

            if (!isset($mime_to_ext[$mime])) {
                $errors[] = 'Resume must be a real PDF or Word document.';
            } else {
                $ext = $mime_to_ext[$mime];   // derived from content, never from the filename
            }
        }
    }

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
        exit;
    }

    // ── Generate Unique Application Code ──
    // e.g., KM-2026-BAR-8291
    $slugInitials = strtoupper(substr(str_replace('-', '', $jobRow['slug']), 0, 3));
    if (strlen($slugInitials) < 3) $slugInitials = 'JOB';
    $year = date('Y');
    $randNum = mt_rand(1000, 9999);
    $trackingCode = sprintf('KM-%s-%s-%s', $year, $slugInitials, $randNum);

    // Ensure code is unique in database
    $checkStmt = $pdo->prepare('SELECT id FROM job_applications WHERE application_code = :c LIMIT 1');
    $checkStmt->execute([':c' => $trackingCode]);
    if ($checkStmt->fetch()) {
        $trackingCode = sprintf('KM-%s-%s-%s', $year, $slugInitials, mt_rand(10000, 99999));
    }

    // ── Handle Resume Upload Storage ──
    // 0755, not 0777. Files here are never web-readable
    // (see uploads/.htaccess); php/download_file.php serves them.
    $uploadDir = __DIR__ . '/../uploads/resumes';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Storage unavailable. Please try again.']);
        exit;
    }

    // Filename is fully generated — nothing from the client survives.
    $uniqueFilename = 'resume_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $targetPath     = $uploadDir . '/' . $uniqueFilename;
    $relativeDbPath = 'uploads/resumes/' . $uniqueFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save resume file. Please try again.']);
        exit;
    }
    @chmod($targetPath, 0644);

    // ── Insert into Database ──
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $insertSql = 'INSERT INTO job_applications (
        application_code, job_id, first_name, last_name, email, phone, city,
        experience, start_date, resume_filename, resume_path, additional_message,
        privacy_accepted, status, ip_address, created_at
    ) VALUES (
        :code, :job_id, :first_name, :last_name, :email, :phone, :city,
        :experience, :start_date, :filename, :path, :message,
        1, "review", :ip, NOW()
    )';

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':code'        => $trackingCode,
        ':job_id'      => $job_id,
        ':first_name'  => $first_name,
        ':last_name'   => $last_name,
        ':email'       => $email,
        ':phone'       => $phone,
        ':city'        => $city,
        ':experience'  => $experience,
        ':start_date'  => $start_date,
        ':filename'    => $cleanOrigName,
        ':path'        => $relativeDbPath,
        ':message'     => $message,
        ':ip'          => $clientIp,
    ]);

    $appId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'         => true,
        'message'         => 'Your application has been received successfully!',
        'tracking_code'   => $trackingCode,
        'application_id'  => $appId,
        'job_title'       => $jobRow['title'],
        'candidate_name'  => $first_name . ' ' . $last_name,
        'email'           => $email,
    ]);

} catch (Throwable $e) {
    error_log('Application submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'A server error occurred while processing your application. Please try again.',
    ]);
}
