<?php
// ─────────────────────────────────────────────────────────────
//  api/get_jobs.php — Fetch Active Job Openings
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_db();

    $search     = trim($_GET['search'] ?? '');
    $location   = trim($_GET['location'] ?? '');
    $department = trim($_GET['department'] ?? '');

    $query = 'SELECT * FROM job_postings WHERE is_active = 1';
    $params = [];

    if (!empty($search)) {
        $query .= ' AND (title LIKE :s OR description LIKE :s OR tagline LIKE :s)';
        $params[':s'] = '%' . $search . '%';
    }

    if (!empty($location) && strtolower($location) !== 'all locations') {
        $query .= ' AND location = :loc';
        $params[':loc'] = $location;
    }

    if (!empty($department) && strtolower($department) !== 'all departments') {
        $query .= ' AND department = :dept';
        $params[':dept'] = $department;
    }

    $query .= ' ORDER BY id ASC';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count'   => count($jobs),
        'jobs'    => $jobs,
    ]);

} catch (Throwable $e) {
    error_log('Get jobs error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load positions.']);
}
