<?php
// ─────────────────────────────────────────────────────────────
//  api/track_application.php — Look up Job Application Status
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

$codeOrEmail = trim($_GET['query'] ?? $_GET['code'] ?? $_POST['query'] ?? $_POST['code'] ?? '');

if (empty($codeOrEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter your Application Code or registered Email Address.']);
    exit;
}

try {
    $pdo = get_db();

    $sql = 'SELECT a.*, p.title AS job_title, p.location AS job_location, p.job_type, p.department
            FROM job_applications a
            JOIN job_postings p ON a.job_id = p.id
            WHERE a.application_code = :query1 OR LOWER(a.email) = LOWER(:query2)
            ORDER BY a.id DESC
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':query1' => $codeOrEmail, ':query2' => $codeOrEmail]);
    $app = $stmt->fetch();

    if (!$app) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No application found with the provided code or email. Please check your information and try again.']);
        exit;
    }

    // Determine current pipeline step
    // 1: Application Review, 2: Interview, 3: Hiring Decision / Completed
    $step = 1;
    $stageName = 'Application Review';
    $stepStatus = 'In Progress';
    $message = "We're currently reviewing your application and resume to see how your skills and background align with the role.";

    switch ($app['status']) {
        case 'review':
            $step = 1;
            $stageName = 'Application review';
            $message = "Our hiring team is currently evaluating your qualifications and background. Shortlisted candidates will be contacted for an interview.";
            break;
        case 'interview':
            $step = 2;
            $stageName = 'Interview';
            $message = "Congratulations! You have been shortlisted for an interview. Our talent team has or will be reaching out to you via email/phone with scheduling details.";
            break;
        case 'decision':
            $step = 3;
            $stageName = 'Hiring decision';
            $message = "Your interview feedback is being reviewed by store management. We'll be in touch very soon regarding the final decision.";
            break;
        case 'hired':
            $step = 3;
            $stageName = 'Offer / Welcome to Kofee Manila';
            $message = "Welcome aboard! An official offer of employment has been prepared. We're excited to have you join the Kofee Manila family!";
            break;
        case 'rejected':
            $step = 1;
            $stageName = 'Application completed';
            $message = "Thank you for your interest in Kofee Manila. While we are unable to advance your application at this time, we will keep your details on file for future openings.";
            break;
    }

    // Mask email for privacy (e.g. k***e@example.com)
    $emailParts = explode('@', $app['email']);
    $maskedEmail = substr($emailParts[0], 0, 1) . '***' . substr($emailParts[0], -1) . '@' . ($emailParts[1] ?? 'domain.com');

    echo json_encode([
        'success'           => true,
        'application_code'  => $app['application_code'],
        'candidate_name'    => $app['first_name'] . ' ' . $app['last_name'],
        'email_masked'      => $maskedEmail,
        'job_title'         => $app['job_title'],
        'job_location'      => $app['job_location'],
        'job_type'          => $app['job_type'],
        'department'        => $app['department'],
        'submitted_at'      => date('M d, Y', strtotime($app['created_at'])),
        'status'            => $app['status'],
        'current_step'      => $step,
        'stage_label'       => $stageName,
        'status_message'    => $message,
    ]);

} catch (Throwable $e) {
    error_log('Tracking error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A server error occurred while retrieving your application.']);
}
