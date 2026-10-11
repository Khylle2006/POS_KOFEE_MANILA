<?php
// ─────────────────────────────────────────────────────────────
//  api/get_jobs.php — Fetch Active Job Openings
// ─────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cache.php';

try {
    $search     = trim($_GET['search'] ?? '');
    $location   = trim($_GET['location'] ?? '');
    $department = trim($_GET['department'] ?? '');

    $cacheKey = 'jobs_list_' . md5($search . '|' . $location . '|' . $department);

    $jobs = cache_remember($cacheKey, 180, function() use ($search, $location, $department) {
        $pdo = get_db();
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
        return $stmt->fetchAll();
    });

    echo json_encode([
        'success' => true,
        'count'   => count($jobs),
        'jobs'    => $jobs,
    ]);

} catch (Throwable $e) {
    error_log('Get jobs error: ' . 'Service temporarily unavailable.');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load positions.']);
}
