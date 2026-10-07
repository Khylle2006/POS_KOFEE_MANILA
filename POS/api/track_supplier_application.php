<?php
// ─────────────────────────────────────────────────────────────
//  api/track_supplier_application.php — Look up Supplier Application Status
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

$query = trim($_GET['query'] ?? '');
if (empty($query)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide an application code or email address.']);
    exit;
}

try {
    $pdo = get_db();

    $stmt = $pdo->prepare('
        SELECT *
        FROM supplier_applications
        WHERE application_code = :q OR email = :q2
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([':q' => $query, ':q2' => $query]);
    $app = $stmt->fetch();

    if (!$app) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'No application record found matching "' . htmlspecialchars($query) . '". Please check your code or email.',
        ]);
        exit;
    }

    $status = $app['status'];
    $step = 1;
    $stage_label = 'Application Received';
    $status_msg = 'Your application and submitted permits have been safely received and are queued for initial review.';

    switch ($status) {
        case 'review':
            $step = 1;
            $stage_label = 'Under Review';
            $status_msg = 'Our procurement team is currently reviewing your business permits and product credentials.';
            break;

        case 'under_review':
            $step = 2;
            $stage_label = 'Compliance & Quality Evaluation';
            $status_msg = 'Your product specifications and authenticity certificates are undergoing quality and supplier capacity verification.';
            break;

        case 'approved':
            $step = 3;
            $stage_label = 'Approved & Accredited';
            $status_msg = 'Congratulations! Your supplier application has been approved. A welcome notification with your Supplier Portal login credentials has been sent to your email (' . htmlspecialchars($app['email']) . ').';
            break;

        case 'rejected':
            $step = 3;
            $stage_label = 'Application Concluded';
            $status_msg = 'Thank you for your interest. After evaluation, we are unable to approve your application for our current procurement cycle. A letter detailing the review outcome has been sent to your registered email.';
            break;
    }

    echo json_encode([
        'success'          => true,
        'application_code' => $app['application_code'],
        'company_name'     => $app['company_name'],
        'contact_person'   => $app['contact_person'],
        'email'            => $app['email'],
        'product_name'     => $app['product_name'],
        'product_category' => $app['product_category'] ?: 'General Supplies',
        'status'           => $status,
        'current_step'     => $step,
        'stage_label'      => $stage_label,
        'status_message'   => $status_msg,
        'submitted_at'     => date('F j, Y', strtotime($app['created_at'])),
        'reviewed_at'      => $app['reviewed_at'] ? date('F j, Y', strtotime($app['reviewed_at'])) : null,
    ]);

} catch (Throwable $e) {
    error_log('track_supplier_application error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A server error occurred while retrieving tracking records.']);
}
