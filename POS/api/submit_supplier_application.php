<?php
// ─────────────────────────────────────────────────────────────
//  api/submit_supplier_application.php — Process Supplier Onboarding
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

try {
    $pdo = get_db();
    ensure_procurement_tables($pdo);

    // ── 1. Extract and Sanitize Form Inputs ──
    $company_name   = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $tax_id         = trim($_POST['tax_id'] ?? '');

    $product_type   = trim($_POST['product_type'] ?? 'existing_ingredient');
    if (!in_array($product_type, ['existing_ingredient', 'custom_product'], true)) {
        $product_type = 'existing_ingredient';
    }

    $ingredient_id  = (int)($_POST['ingredient_id'] ?? 0);
    $product_name   = trim($_POST['product_name'] ?? '');
    $product_cat    = trim($_POST['product_category'] ?? '');
    $product_desc   = trim($_POST['product_description'] ?? '');
    $proposed_price = !empty($_POST['proposed_price']) ? (float)$_POST['proposed_price'] : null;
    $price_unit     = trim($_POST['price_unit'] ?? 'per kg') ?: 'per kg';
    $supply_capacity= trim($_POST['supply_capacity'] ?? '');
    $profile_notes  = trim($_POST['company_profile_notes'] ?? '');
    $privacy        = isset($_POST['privacy_consent']) && ($_POST['privacy_consent'] === '1' || $_POST['privacy_consent'] === 'true' || $_POST['privacy_consent'] === 'on');

    // If an existing ingredient was selected, fetch its official title & category
    if ($product_type === 'existing_ingredient' && $ingredient_id > 0) {
        $stmtIng = $pdo->prepare('
            SELECT i.name, i.unit, c.name AS cat_name
            FROM ingredients i
            LEFT JOIN ingredient_categories c ON c.id = i.cat_id
            WHERE i.id = :id AND i.archived_at IS NULL
            LIMIT 1
        ');
        $stmtIng->execute([':id' => $ingredient_id]);
        $ingRow = $stmtIng->fetch();
        if ($ingRow) {
            if (empty($product_name)) {
                $product_name = $ingRow['name'];
            }
            if (empty($product_cat) && !empty($ingRow['cat_name'])) {
                $product_cat = $ingRow['cat_name'];
            }
        }
    }

    // ── 2. Validation ──
    $errors = [];
    if (empty($company_name))   $errors[] = 'Company or business name is required.';
    if (empty($contact_person)) $errors[] = 'Contact person name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid business email address is required.';
    if (empty($phone))          $errors[] = 'Phone / mobile number is required.';
    if (empty($address))        $errors[] = 'Business address is required.';
    if (empty($product_name))   $errors[] = 'Selected product / material name is required.';
    if (!$privacy)              $errors[] = 'You must agree to the Terms & Privacy Notice to submit your application.';

    // Check for recent duplicate pending application from the same email
    $dupCheck = $pdo->prepare("SELECT id FROM supplier_applications WHERE email = :e AND status IN ('review', 'under_review') LIMIT 1");
    $dupCheck->execute([':e' => $email]);
    if ($dupCheck->fetch()) {
        $errors[] = 'An active supplier application with this email is already under review. You can track its status using the tracking code.';
    }

    // ── 3. Helper for Document Uploads ──
    $uploadDir = __DIR__ . '/../uploads/supplier_permits';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Storage directory unavailable. Please try again.']);
        exit;
    }

    $mime_to_ext = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $process_upload = function(string $field_key, string $label, bool $required) use ($mime_to_ext, $uploadDir, &$errors): ?array {
        if (!isset($_FILES[$field_key]) || $_FILES[$field_key]['error'] === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $errors[] = "{$label} is required (PDF, DOCX, JPG, or PNG up to 10 MB).";
            }
            return null;
        }

        $file = $_FILES[$field_key];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading {$label} (code {$file['error']}). Please try again.";
            return null;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = "Invalid upload for {$label}.";
            return null;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = "{$label} file size exceeds the 10 MB limit.";
            return null;
        }

        // Trust magic bytes, not file extension
        $mime = detect_upload_mime($file['tmp_name']);
        if (!isset($mime_to_ext[$mime])) {
            $errors[] = "{$label} must be a valid PDF, Word document, or image (JPG/PNG).";
            return null;
        }

        $ext = $mime_to_ext[$mime];
        $origName = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $origName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $origName) ?? '');
        $origName = substr($origName, 0, 250);
        if ($origName === '') $origName = 'document.' . $ext;

        $storedName = 'sup_' . bin2hex(random_bytes(10)) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $storedName;
        $dbPath     = 'uploads/supplier_permits/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $errors[] = "Failed to save {$label} on server.";
            return null;
        }
        @chmod($targetPath, 0644);

        return [
            'original_name' => $origName,
            'stored_path'   => $dbPath,
        ];
    };

    $permitData  = $process_upload('business_permit', 'Business Permit / Registration Document', true);
    $authDocData = $process_upload('authenticity_cert', 'Product Authenticity / FDA / COA Certificate', true);
    $addlDocData = $process_upload('additional_documents', 'Additional Documents', false);

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
        exit;
    }

    // ── 4. Generate Unique Application Tracking Code ──
    $year = date('Y');
    $randNum = mt_rand(1000, 9999);
    $trackingCode = sprintf('KM-SUP-%s-%s', $year, $randNum);

    $codeCheck = $pdo->prepare('SELECT id FROM supplier_applications WHERE application_code = :c LIMIT 1');
    $codeCheck->execute([':c' => $trackingCode]);
    if ($codeCheck->fetch()) {
        $trackingCode = sprintf('KM-SUP-%s-%s', $year, mt_rand(10000, 99999));
    }

    // ── 5. Insert Record ──
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    $insertSql = 'INSERT INTO supplier_applications (
        application_code, company_name, contact_person, email, phone, address, tax_id,
        product_type, ingredient_id, product_name, product_category, product_description,
        proposed_price, price_unit, supply_capacity,
        business_permit_filename, business_permit_path,
        authenticity_cert_filename, authenticity_cert_path,
        additional_documents_filename, additional_documents_path,
        company_profile_notes, status, ip_address, created_at
    ) VALUES (
        :code, :comp, :contact, :email, :phone, :addr, :tax,
        :ptype, :ing_id, :pname, :pcat, :pdesc,
        :price, :unit, :cap,
        :bp_name, :bp_path,
        :ac_name, :ac_path,
        :ad_name, :ad_path,
        :notes, "review", :ip, NOW()
    )';

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':code'     => $trackingCode,
        ':comp'     => $company_name,
        ':contact'  => $contact_person,
        ':email'    => $email,
        ':phone'    => $phone,
        ':addr'     => $address,
        ':tax'      => $tax_id ?: null,
        ':ptype'    => $product_type,
        ':ing_id'   => $ingredient_id > 0 ? $ingredient_id : null,
        ':pname'    => $product_name,
        ':pcat'     => $product_cat ?: null,
        ':pdesc'    => $product_desc ?: null,
        ':price'    => $proposed_price,
        ':unit'     => $price_unit,
        ':cap'      => $supply_capacity ?: null,
        ':bp_name'  => $permitData['original_name'],
        ':bp_path'  => $permitData['stored_path'],
        ':ac_name'  => $authDocData['original_name'],
        ':ac_path'  => $authDocData['stored_path'],
        ':ad_name'  => $addlDocData['original_name'] ?? null,
        ':ad_path'  => $addlDocData['stored_path'] ?? null,
        ':notes'    => $profile_notes ?: null,
        ':ip'       => $clientIp,
    ]);

    $appId = (int)$pdo->lastInsertId();

    // ── 6. Public Tracking URL & Confirmation Email ──
    $baseUrl = trim((string)(defined('APP_BASE_URL') ? APP_BASE_URL : (getenv('APP_BASE_URL') ?: '')));
    $baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])
        || !in_array(strtolower($baseParts['scheme']), ['http', 'https'], true)) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::\d{1,5})?$/i', $host)) {
            $host = 'localhost';
        }
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $appPath = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/submit_supplier_application.php'))));
        $appPath = ($appPath === '/' || $appPath === '.') ? '' : '/' . trim($appPath, '/');
        $baseUrl = ($isHttps ? 'https' : 'http') . '://' . $host . $appPath;
    } else {
        $baseUrl = rtrim($baseUrl, '/');
    }
    $trackingUrl = $baseUrl . '/supplier_partnership.php?track=' . rawurlencode($trackingCode);

    $emailSent = false;
    try {
        $mailRes = send_supplier_application_confirmation_email(
            $email,
            $company_name,
            $contact_person,
            $product_name,
            $trackingCode,
            $trackingUrl
        );
        $emailSent = !empty($mailRes['sent']);
    } catch (Throwable $mailEx) {
        error_log('Supplier confirmation email error for #' . $appId . ': ' . $mailEx->getMessage());
    }

    // ── 7. Notify Procurement Managers ──
    notify_role_by_permission(
        'procurement.suppliers.manage',
        'supplier_application_received',
        'New Supplier Application: ' . $company_name,
        'Application for ' . $product_name . ' (' . $trackingCode . ') is ready for review.',
        'suppliers.php?tab=applications'
    );

    audit_log('supplier_application', $appId, 'submitted', "Application {$trackingCode} submitted by {$company_name}");

    echo json_encode([
        'success'        => true,
        'message'        => 'Your supplier application and documents have been received successfully!',
        'tracking_code'  => $trackingCode,
        'tracking_url'   => $trackingUrl,
        'company_name'   => $company_name,
        'product_name'   => $product_name,
        'email'          => $email,
        'email_sent'     => $emailSent,
    ]);

} catch (Throwable $e) {
    error_log('Supplier application error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'A server error occurred while processing your application. Please try again.',
    ]);
}
