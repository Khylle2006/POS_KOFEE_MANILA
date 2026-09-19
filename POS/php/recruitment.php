<?php
// ─────────────────────────────────────────────────────────────
//  php/recruitment.php — HR & Store Manager Recruitment Portal
//  Manage Candidate Applications & Open Job Vacancies
// ─────────────────────────────────────────────────────────────
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_login();

$pdo = get_db();
$user = current_user();
$role = $user['role'] ?? 'crew';
$roles = $user['roles'] ?? [$role];

// Require recruitment.manage permission, users.manage, or admin
$can_manage_recruitment = has_permission('recruitment.manage') || has_permission('users.manage') || in_array('admin', $roles, true);
if (!$can_manage_recruitment) {
    header('Location: no_access.php');
    exit;
}

$toast = '';
$toast_type = 'success';
$active_tab = $_GET['tab'] ?? 'applications';

// Helper: Slug Generator
function generate_unique_slug(string $title, PDO $pdo, int $exclude_id = 0): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (empty($slug)) $slug = 'job-position';
    $base = $slug;
    $i = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT id FROM job_postings WHERE slug = :s AND id != :id LIMIT 1');
        $stmt->execute([':s' => $slug, ':id' => $exclude_id]);
        if (!$stmt->fetch()) {
            break;
        }
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

// ─────────────────────────────────────────────────────────────
//  POST Request Handlers
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── 1. Update Candidate Application Status ──
    if ($action === 'update_status') {
        $app_id     = (int)($_POST['application_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');
        $notes      = trim($_POST['reviewer_notes'] ?? '');

        $valid_statuses = ['review', 'interview', 'decision', 'hired', 'rejected'];
        if ($app_id > 0 && in_array($new_status, $valid_statuses, true)) {
            $stmt = $pdo->prepare('UPDATE job_applications SET status = :s, reviewer_notes = :n, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':s' => $new_status, ':n' => $notes, ':id' => $app_id]);
            $toast = 'Application status updated to ' . strtoupper($new_status) . '.';
        }
        $active_tab = 'applications';
    }

    // ── 2. Delete Candidate Application ──
    elseif ($action === 'delete_application') {
        $app_id = (int)($_POST['application_id'] ?? 0);
        if ($app_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM job_applications WHERE id = :id');
            $stmt->execute([':id' => $app_id]);
            $toast = 'Candidate application record deleted.';
        }
        $active_tab = 'applications';
    }

    // ── 3. Add New Job Vacancy (HR Feature) ──
    elseif ($action === 'add_job') {
        $title            = trim($_POST['title'] ?? '');
        $department       = trim($_POST['department'] ?? 'Coffee & Barista');
        $location         = trim($_POST['location'] ?? 'Manila');
        $job_type         = trim($_POST['job_type'] ?? 'Full-time');
        $tagline          = trim($_POST['tagline'] ?? '');
        $about_role       = trim($_POST['about_role'] ?? '');
        $responsibilities = trim($_POST['responsibilities'] ?? '');
        $requirements     = trim($_POST['requirements'] ?? '');
        $benefits         = trim($_POST['benefits'] ?? '');
        $is_active        = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title)) {
            $toast = 'Job Title is required.';
            $toast_type = 'error';
        } else {
            if (empty($tagline)) $tagline = $title . ' position at Kofee Manila.';
            if (empty($about_role)) $about_role = $tagline;

            $slug = generate_unique_slug($title, $pdo);

            $insertSql = 'INSERT INTO job_postings (
                slug, title, department, location, job_type, tagline,
                description, about_role, responsibilities, requirements, benefits, is_active, created_at
            ) VALUES (
                :slug, :title, :dept, :loc, :type, :tagline,
                :tagline, :about, :resp, :reqs, :bens, :active, NOW()
            )';

            $stmt = $pdo->prepare($insertSql);
            $stmt->execute([
                ':slug'    => $slug,
                ':title'   => $title,
                ':dept'    => $department,
                ':loc'     => $location,
                ':type'    => $job_type,
                ':tagline' => $tagline,
                ':about'   => $about_role,
                ':resp'    => $responsibilities,
                ':reqs'    => $requirements,
                ':bens'    => $benefits,
                ':active'  => $is_active,
            ]);

            $toast = $is_active
                ? 'New vacancy for "' . htmlspecialchars($title) . '" has been posted and is live on the Careers portal.'
                : 'Position "' . htmlspecialchars($title) . '" saved as closed/filled.';
        }
        $active_tab = 'vacancies';
    }

    // ── 4. Edit Existing Job Vacancy ──
    elseif ($action === 'edit_job') {
        $job_id           = (int)($_POST['job_id'] ?? 0);
        $title            = trim($_POST['title'] ?? '');
        $department       = trim($_POST['department'] ?? 'Coffee & Barista');
        $location         = trim($_POST['location'] ?? 'Manila');
        $job_type         = trim($_POST['job_type'] ?? 'Full-time');
        $tagline          = trim($_POST['tagline'] ?? '');
        $about_role       = trim($_POST['about_role'] ?? '');
        $responsibilities = trim($_POST['responsibilities'] ?? '');
        $requirements     = trim($_POST['requirements'] ?? '');
        $benefits         = trim($_POST['benefits'] ?? '');
        $is_active        = isset($_POST['is_active']) ? 1 : 0;

        if ($job_id > 0 && !empty($title)) {
            if (empty($tagline)) $tagline = $title . ' position at Kofee Manila.';
            if (empty($about_role)) $about_role = $tagline;

            $updateSql = 'UPDATE job_postings SET
                title = :title, department = :dept, location = :loc, job_type = :type,
                tagline = :tagline, description = :tagline, about_role = :about,
                responsibilities = :resp, requirements = :reqs, benefits = :bens,
                is_active = :active
                WHERE id = :id';

            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                ':title'   => $title,
                ':dept'    => $department,
                ':loc'     => $location,
                ':type'    => $job_type,
                ':tagline' => $tagline,
                ':about'   => $about_role,
                ':resp'    => $responsibilities,
                ':reqs'    => $requirements,
                ':bens'    => $benefits,
                ':active'  => $is_active,
                ':id'      => $job_id,
            ]);

            $toast = 'Job vacancy "' . htmlspecialchars($title) . '" updated successfully.';
        }
        $active_tab = 'vacancies';
    }

    // ── 5. Toggle Vacancy Status (Open vs. Filled/Removed) ──
    elseif ($action === 'toggle_vacancy') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $curr   = (int)($_POST['current_active'] ?? 0);
        $new    = $curr ? 0 : 1;

        if ($job_id > 0) {
            $stmt = $pdo->prepare('UPDATE job_postings SET is_active = :new WHERE id = :id');
            $stmt->execute([':new' => $new, ':id' => $job_id]);

            // Fetch title for friendly message
            $nameStmt = $pdo->prepare('SELECT title FROM job_postings WHERE id = :id LIMIT 1');
            $nameStmt->execute([':id' => $job_id]);
            $jobTitle = $nameStmt->fetchColumn() ?: 'Position';

            if ($new === 0) {
                $toast = 'Vacancy for "' . htmlspecialchars($jobTitle) . '" marked as FILLED. It is now removed/hidden from the public Careers portal.';
            } else {
                $toast = 'Vacancy for "' . htmlspecialchars($jobTitle) . '" marked as OPEN. It is now live and accepting applications on the Careers portal.';
            }
        }
        $active_tab = 'vacancies';
    }

    // ── 6. Delete Job Vacancy ──
    elseif ($action === 'delete_job') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        if ($job_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM job_postings WHERE id = :id');
            $stmt->execute([':id' => $job_id]);
            $toast = 'Job position deleted.';
        }
        $active_tab = 'vacancies';
    }

    $q = '?tab=' . urlencode($active_tab);
    if ($toast) $q .= '&toast=' . urlencode($toast) . '&type=' . $toast_type;
    header('Location: recruitment.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ─────────────────────────────────────────────────────────────
//  Data Queries
// ─────────────────────────────────────────────────────────────

// Candidate Applications Query
$filter_status = $_GET['status'] ?? 'all';
$filter_job = (int)($_GET['job_id'] ?? 0);

$query = 'SELECT a.*, p.title AS job_title, p.location AS job_location, p.department
          FROM job_applications a
          JOIN job_postings p ON a.job_id = p.id
          WHERE 1=1';
$params = [];

if (in_array($filter_status, ['review', 'interview', 'decision', 'hired', 'rejected'], true)) {
    $query .= ' AND a.status = :st';
    $params[':st'] = $filter_status;
}

if ($filter_job > 0) {
    $query .= ' AND a.job_id = :jid';
    $params[':jid'] = $filter_job;
}

$query .= ' ORDER BY FIELD(a.status, "review", "interview", "decision", "hired", "rejected"), a.created_at DESC';

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Applicant Pipeline Counts
$count_review = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'review'")->fetchColumn();
$count_interview = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'interview'")->fetchColumn();
$count_decision = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'decision'")->fetchColumn();
$count_hired = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'hired'")->fetchColumn();

// Job Vacancies Query (with applicant count per role)
$vacanciesSql = 'SELECT p.*,
                 COUNT(a.id) AS total_applicants,
                 SUM(CASE WHEN a.status = "review" THEN 1 ELSE 0 END) AS count_review,
                 SUM(CASE WHEN a.status = "interview" THEN 1 ELSE 0 END) AS count_interview,
                 SUM(CASE WHEN a.status = "hired" THEN 1 ELSE 0 END) AS count_hired
                 FROM job_postings p
                 LEFT JOIN job_applications a ON p.id = a.job_id
                 GROUP BY p.id, p.slug, p.title, p.department, p.location, p.job_type, p.tagline, p.description, p.about_role, p.responsibilities, p.requirements, p.benefits, p.is_active, p.created_at
                 ORDER BY p.is_active DESC, p.id ASC';
$all_vacancies = $pdo->query($vacanciesSql)->fetchAll();

$open_vacancies_count = 0;
$filled_vacancies_count = 0;
foreach ($all_vacancies as $v) {
    if ($v['is_active']) $open_vacancies_count++;
    else $filled_vacancies_count++;
}

// All active jobs for dropdown filter
$jobs_list = $pdo->query("SELECT id, title, location FROM job_postings ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Recruitment &amp; Careers — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet"/>
  <style>
    .stage-badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .stage-review { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
    .stage-interview { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
    .stage-decision { background: #F3E8FF; color: #6B21A8; border: 1px solid #D8B4FE; }
    .stage-hired { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
    .stage-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }

    /* Vacancy Badges */
    .vacancy-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.03em;
    }
    .vacancy-open {
      background: #DCFCE7;
      color: #15803D;
      border: 1px solid #86EFAC;
    }
    .vacancy-filled {
      background: #FEE2E2;
      color: #991B1B;
      border: 1px solid #FCA5A5;
    }

    /* Tab navigation buttons */
    .recruitment-tabs {
      display: flex;
      gap: 12px;
      border-bottom: 2px solid #E8DFD5;
      margin-bottom: 24px;
      padding-bottom: 2px;
    }
    .tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      font-size: 13.5px;
      font-weight: 600;
      color: #786A77;
      border-bottom: 3px solid transparent;
      margin-bottom: -4px;
      text-decoration: none;
      transition: all 0.16s ease;
      cursor: pointer;
    }
    .tab-btn:hover {
      color: var(--caramel, #C97B3D);
    }
    .tab-btn.active {
      color: #201426;
      border-bottom-color: var(--caramel, #C97B3D);
      font-weight: 700;
    }
    .tab-badge {
      background: #EDE6DC;
      color: #5C4E59;
      padding: 2px 7px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
    }
    .tab-btn.active .tab-badge {
      background: var(--caramel, #C97B3D);
      color: #FFFFFF;
    }

    .stat-card-link {
      text-decoration: none;
      color: inherit;
    }
    .resume-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      color: var(--caramel, #C97B3D);
      font-weight: 600;
      font-size: 12px;
      text-decoration: none;
    }
    .resume-link:hover {
      text-decoration: underline;
    }

    /* Modals */
    .rec-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(22, 14, 27, 0.72);
      backdrop-filter: blur(4px);
      z-index: 9999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .rec-modal-backdrop.open {
      display: flex;
    }
    .rec-modal {
      background: #FFFFFF;
      border-radius: 16px;
      border: 1px solid #E8DFD5;
      box-shadow: 0 20px 40px rgba(0,0,0,0.2);
      width: 100%;
      max-width: 620px;
      max-height: 90vh;
      overflow-y: auto;
      padding: 28px;
      position: relative;
    }
    .rec-modal-close {
      position: absolute;
      top: 18px;
      right: 18px;
      background: #F3EFE9;
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .rec-modal-close:hover {
      background: #E8E0D5;
    }
    .form-label {
      display: block;
      font-size: 12.5px;
      font-weight: 600;
      color: #201426;
      margin-bottom: 5px;
    }
    .form-input, .form-select, .form-textarea {
      width: 100%;
      border: 1px solid #D6CBC1;
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 13px;
      color: #201426;
      outline: none;
      box-sizing: border-box;
      font-family: inherit;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
      border-color: #C97B3D;
      box-shadow: 0 0 0 3px rgba(201, 123, 61, 0.15);
    }
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 14px;
    }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-recruitment" class="page active">
  <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 style="font-family:'Playfair Display',serif; font-size:24px; margin-bottom:4px;">Recruitment &amp; Careers Management</h1>
      <p style="color:var(--text-muted, #8B7C88); font-size:13px;">Manage job vacancies, open/close full positions, review applicants, and track candidate pipeline</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <button type="button" class="btn btn-primary" onclick="openAddJobModal()" style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px;">
        <?= icon('plus', 14) ?>
        <span>Post New Vacancy</span>
      </button>
      <a href="../careers.php" target="_blank" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px;">
        <?= icon('external-link', 14) ?>
        <span>View Live Careers Portal</span>
      </a>
    </div>
  </div>

  <?php if ($toast): ?>
    <div style="margin: 16px 0; padding: 12px 18px; border-radius: 8px; background: <?= $toast_type==='error' ? '#FEE2E2' : '#ECFDF5' ?>; border: 1px solid <?= $toast_type==='error' ? '#FCA5A5' : '#A7F3D0' ?>; color: <?= $toast_type==='error' ? '#991B1B' : '#065F46' ?>; font-size: 13.5px; font-weight: 500;">
      <?= $toast ?>
    </div>
  <?php endif; ?>

  <div class="page-body">

    <!-- TAB NAVIGATION -->
    <div class="recruitment-tabs">
      <a href="recruitment.php?tab=applications" class="tab-btn <?= $active_tab === 'applications' ? 'active' : '' ?>">
        <?= icon('clipboard', 15) ?>
        <span>Candidate Applications</span>
        <span class="tab-badge"><?= count($applications) ?></span>
      </a>
      <a href="recruitment.php?tab=vacancies" class="tab-btn <?= $active_tab === 'vacancies' ? 'active' : '' ?>">
        <?= icon('briefcase', 15) ?>
        <span>Job Openings &amp; Vacancies</span>
        <span class="tab-badge"><?= count($all_vacancies) ?> (<?= $open_vacancies_count ?> Open)</span>
      </a>
    </div>

    <?php if ($active_tab === 'vacancies'): ?>
      <!-- ======================================================== -->
      <!-- TAB 1: JOB VACANCIES & OPENINGS MANAGEMENT               -->
      <!-- ======================================================== -->

      <!-- Vacancy Statistics Row -->
      <div class="stat-row" style="display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; margin-bottom:24px;">
        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#EFF6FF; color:#2563EB; display:flex; align-items:center; justify-content:center; font-size:20px;"><?= icon('briefcase', 22) ?></div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= count($all_vacancies) ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">Total Job Titles</div>
          </div>
        </div>

        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#DCFCE7; color:#16A34A; display:flex; align-items:center; justify-content:center; font-size:20px;"><?= icon('check-circle', 22) ?></div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $open_vacancies_count ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">Active Vacancies (Live on Site)</div>
          </div>
        </div>

        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#FEE2E2; color:#DC2626; display:flex; align-items:center; justify-content:center; font-size:20px;"><?= icon('lock', 22) ?></div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $filled_vacancies_count ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">Filled Vacancies (Hidden from Site)</div>
          </div>
        </div>
      </div>

      <!-- Vacancies Table Card -->
      <div class="table-card" style="background:#FFF; border-radius:12px; border:1px solid #E8DFD5; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
        <div style="padding:16px 20px; border-bottom:1px solid #E8DFD5; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <h3 style="font-size:15px; font-weight:700; color:#201426;">Positions &amp; Vacancy Status</h3>
            <p style="font-size:12px; color:#8B7C88;">Toggle "Mark as Filled" to automatically hide full positions from the careers page.</p>
          </div>
          <button type="button" class="btn btn-primary" onclick="openAddJobModal()" style="font-size:12px; padding:6px 14px;">
            + Post New Vacancy
          </button>
        </div>

        <div class="table-scroll-wrapper" style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
            <thead>
              <tr style="background:#FAF7F2; border-bottom:1px solid #E8DFD5; color:#786A77; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em;">
                <th style="padding:14px 16px;">Position</th>
                <th style="padding:14px 16px;">Department</th>
                <th style="padding:14px 16px;">Location</th>
                <th style="padding:14px 16px;">Type</th>
                <th style="padding:14px 16px; text-align:center;">Applicants</th>
                <th style="padding:14px 16px;">Vacancy Status</th>
                <th style="padding:14px 16px; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($all_vacancies)): ?>
                <tr>
                  <td colspan="7" style="padding:40px; text-align:center; color:#8B7C88;">
                    No job positions found. Click "Post New Vacancy" above to add your first job opening!
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($all_vacancies as $vac): ?>
                  <tr style="border-bottom:1px solid #F0EAE2; background: <?= $vac['is_active'] ? '#FFF' : '#FCFBF9' ?>;">
                    <td style="padding:14px 16px;">
                      <strong style="font-size:14px; color:#201426;"><?= htmlspecialchars($vac['title']) ?></strong>
                      <div style="font-size:11.5px; color:#8B7C88;">
                        Slug: <code style="background:#F0EAE1; padding:1px 5px; border-radius:4px;"><?= htmlspecialchars($vac['slug']) ?></code>
                      </div>
                    </td>
                    <td style="padding:14px 16px; color:#534551;">
                      <?= htmlspecialchars($vac['department']) ?>
                    </td>
                    <td style="padding:14px 16px; color:#534551;">
                      <?= htmlspecialchars($vac['location']) ?>
                    </td>
                    <td style="padding:14px 16px;">
                      <span style="background:#F0EAE1; color:#534551; font-size:11.5px; font-weight:600; padding:2px 8px; border-radius:6px;">
                        <?= htmlspecialchars($vac['job_type']) ?>
                      </span>
                    </td>
                    <td style="padding:14px 16px; text-align:center;">
                      <?php if ($vac['total_applicants'] > 0): ?>
                        <a href="recruitment.php?tab=applications&job_id=<?= (int)$vac['id'] ?>" style="display:inline-flex; align-items:center; gap:4px; font-weight:700; color:var(--caramel, #C97B3D); text-decoration:none;">
                          <span><?= (int)$vac['total_applicants'] ?> <?= $vac['total_applicants'] == 1 ? 'applicant' : 'applicants' ?></span>
                          <?= icon('chevron', 12) ?>
                        </a>
                      <?php else: ?>
                        <span style="color:#9E909D; font-size:12px;">0 applicants</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:14px 16px;">
                      <?php if ($vac['is_active']): ?>
                        <span class="vacancy-badge vacancy-open">
                          <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#16A34A;"></span> Open Vacancy
                        </span>
                      <?php else: ?>
                        <span class="vacancy-badge vacancy-filled">
                          <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#DC2626;"></span> Vacancy Filled
                        </span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:14px 16px; text-align:right;">
                      <div style="display:inline-flex; align-items:center; gap:8px;">

                        <!-- Toggle Open vs Filled Form -->
                        <form method="POST" action="recruitment.php" style="display:inline;">
                          <input type="hidden" name="action" value="toggle_vacancy">
                          <input type="hidden" name="job_id" value="<?= (int)$vac['id'] ?>">
                          <input type="hidden" name="current_active" value="<?= (int)$vac['is_active'] ?>">
                          <?php if ($vac['is_active']): ?>
                            <button type="submit" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px; padding:4px 9px; color:#991B1B; border-color:#FCA5A5; background:#FFF;" title="Mark position as filled (removes from careers page)">
                              <?= icon('lock', 12) ?>
                              <span>Mark as Filled</span>
                            </button>
                          <?php else: ?>
                            <button type="submit" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px; padding:4px 9px; color:#15803D; border-color:#86EFAC; background:#FFF;" title="Reopen position (shows on careers page)">
                              <?= icon('check-circle', 12) ?>
                              <span>Reopen Vacancy</span>
                            </button>
                          <?php endif; ?>
                        </form>

                        <!-- Edit Button -->
                        <button type="button" class="btn btn-secondary" onclick="openEditJobModal(<?= htmlspecialchars(json_encode($vac)) ?>)" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px; padding:4px 9px;" title="Edit position details">
                          <?= icon('edit', 12) ?>
                          <span>Edit</span>
                        </button>

                        <!-- Delete Button -->
                        <form method="POST" action="recruitment.php" onsubmit="return confirm('Are you sure you want to delete position \'<?= addslashes(htmlspecialchars($vac['title'])) ?>\'?');" style="display:inline;">
                          <input type="hidden" name="action" value="delete_job">
                          <input type="hidden" name="job_id" value="<?= (int)$vac['id'] ?>">
                          <button type="submit" class="btn btn-secondary" style="display:inline-flex;align-items:center;justify-content:center;font-size:11.5px; padding:5px 8px; color:#B91C1C;" title="Delete position">
                            <?= icon('trash', 13) ?>
                          </button>
                        </form>

                        <!-- Public Link -->
                        <?php if ($vac['is_active']): ?>
                          <a href="../apply.php?job=<?= urlencode($vac['slug']) ?>" target="_blank" style="font-size:12px; color:#8B7C88; text-decoration:none;" title="Preview public application page">
                            ↗
                          </a>
                        <?php endif; ?>

                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php else: ?>
      <!-- ======================================================== -->
      <!-- TAB 2: CANDIDATE APPLICATIONS & PIPELINE                 -->
      <!-- ======================================================== -->

      <!-- Stat Row -->
      <div class="stat-row" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px;">
        <a href="recruitment.php?tab=applications&status=review" class="stat-card-link">
          <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
            <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#FEF3C7; color:#B45309; display:flex; align-items:center; justify-content:center;"><?= icon('clipboard', 22) ?></div>
            <div>
              <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_review ?></div>
              <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">1. Application Review</div>
            </div>
          </div>
        </a>

        <a href="recruitment.php?tab=applications&status=interview" class="stat-card-link">
          <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
            <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#DBEAFE; color:#1D4ED8; display:flex; align-items:center; justify-content:center;"><?= icon('mic', 22) ?></div>
            <div>
              <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_interview ?></div>
              <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">2. Interview Stage</div>
            </div>
          </div>
        </a>

        <a href="recruitment.php?tab=applications&status=decision" class="stat-card-link">
          <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
            <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#F3E8FF; color:#7E22CE; display:flex; align-items:center; justify-content:center;"><?= icon('scale', 22) ?></div>
            <div>
              <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_decision ?></div>
              <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">3. Hiring Decision</div>
            </div>
          </div>
        </a>

        <a href="recruitment.php?tab=applications&status=hired" class="stat-card-link">
          <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
            <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#D1FAE5; color:#047857; display:flex; align-items:center; justify-content:center;"><?= icon('award', 22) ?></div>
            <div>
              <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_hired ?></div>
              <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">Hired Crew Members</div>
            </div>
          </div>
        </a>
      </div>

      <!-- Filter Bar -->
      <div class="filter-bar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
          <a href="recruitment.php?tab=applications" class="filter-pill <?= $filter_status==='all'?'active':'' ?>">All (<?= count($applications) ?>)</a>
          <a href="recruitment.php?tab=applications&status=review" class="filter-pill <?= $filter_status==='review'?'active':'' ?>">Review</a>
          <a href="recruitment.php?tab=applications&status=interview" class="filter-pill <?= $filter_status==='interview'?'active':'' ?>">Interview</a>
          <a href="recruitment.php?tab=applications&status=decision" class="filter-pill <?= $filter_status==='decision'?'active':'' ?>">Decision</a>
          <a href="recruitment.php?tab=applications&status=hired" class="filter-pill <?= $filter_status==='hired'?'active':'' ?>">Hired</a>
          <a href="recruitment.php?tab=applications&status=rejected" class="filter-pill <?= $filter_status==='rejected'?'active':'' ?>">Rejected</a>
        </div>

        <div>
          <form method="GET" action="recruitment.php" style="display:flex; gap:8px;">
            <input type="hidden" name="tab" value="applications">
            <?php if ($filter_status !== 'all'): ?>
              <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <?php endif; ?>
            <select name="job_id" onchange="this.form.submit()" style="padding:6px 12px; border-radius:8px; border:1px solid #D6CBC1; font-size:12.5px;">
              <option value="0">All Positions</option>
              <?php foreach ($jobs_list as $jl): ?>
                <option value="<?= $jl['id'] ?>" <?= $filter_job === (int)$jl['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($jl['title']) ?> (<?= htmlspecialchars($jl['location']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>

      <!-- Applications Table -->
      <div class="table-card" style="background:#FFF; border-radius:12px; border:1px solid #E8DFD5; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
        <div class="table-scroll-wrapper" style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
            <thead>
              <tr style="background:#FAF7F2; border-bottom:1px solid #E8DFD5; color:#786A77; font-size:11.5px; text-transform:uppercase; letter-spacing:0.05em;">
                <th style="padding:14px 16px;">Tracking Code</th>
                <th style="padding:14px 16px;">Candidate</th>
                <th style="padding:14px 16px;">Position</th>
                <th style="padding:14px 16px;">Location</th>
                <th style="padding:14px 16px;">Experience</th>
                <th style="padding:14px 16px;">Resume</th>
                <th style="padding:14px 16px;">Status</th>
                <th style="padding:14px 16px; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($applications)): ?>
                <tr>
                  <td colspan="8" style="padding:40px; text-align:center; color:#8B7C88;">
                    No job applications found matching this filter.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($applications as $app): ?>
                  <tr style="border-bottom:1px solid #F0EAE2;">
                    <td style="padding:14px 16px; font-family:monospace; font-weight:700; color:#201426;">
                      <?= htmlspecialchars($app['application_code']) ?>
                      <div style="font-size:11px; font-family:sans-serif; color:#9E909D; font-weight:normal;">
                        <?= date('M d, Y', strtotime($app['created_at'])) ?>
                      </div>
                    </td>
                    <td style="padding:14px 16px;">
                      <strong style="color:#201426;"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></strong>
                      <div style="font-size:11.5px; color:#786A77;">
                        <?= htmlspecialchars($app['email']) ?> &bull; <?= htmlspecialchars($app['phone']) ?>
                      </div>
                      <?php if (!empty($app['additional_message'])): ?>
                        <div style="font-size:11px; color:#8B7C88; margin-top:3px; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:4px;" title="<?= htmlspecialchars($app['additional_message']) ?>">
                          <?= icon('message', 11) ?> <span>"<?= htmlspecialchars($app['additional_message']) ?>"</span>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td style="padding:14px 16px;">
                      <span style="font-weight:600; color:#201426;"><?= htmlspecialchars($app['job_title']) ?></span>
                      <div style="font-size:11.5px; color:#8B7C88;"><?= htmlspecialchars($app['department']) ?></div>
                    </td>
                    <td style="padding:14px 16px; color:#534551;">
                      <?= htmlspecialchars($app['city']) ?>
                    </td>
                    <td style="padding:14px 16px; font-size:12px; color:#534551;">
                      <?= htmlspecialchars($app['experience']) ?>
                      <div style="font-size:11px; color:#9E909D;">Start: <?= date('M d, Y', strtotime($app['start_date'])) ?></div>
                    </td>
                    <td style="padding:14px 16px;">
                      <?php if (!empty($app['resume_path'])): ?>
                        <a href="../<?= htmlspecialchars($app['resume_path']) ?>" target="_blank" class="resume-link" download>
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                          <span>Download</span>
                        </a>
                      <?php else: ?>
                        <span style="color:#9E909D; font-size:11.5px;">No file</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:14px 16px;">
                      <?php
                        $badgeClass = 'stage-' . $app['status'];
                        $stageLabels = [
                          'review'    => '1. Review',
                          'interview' => '2. Interview',
                          'decision'  => '3. Decision',
                          'hired'     => 'Hired',
                          'rejected'  => 'Rejected'
                        ];
                      ?>
                      <span class="stage-badge <?= $badgeClass ?>">
                        <?= $stageLabels[$app['status']] ?? $app['status'] ?>
                      </span>
                    </td>
                    <td style="padding:14px 16px; text-align:right;">
                      <form method="POST" action="recruitment.php" style="display:inline-flex; align-items:center; gap:6px;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="font-size:11.5px; padding:4px 8px; border-radius:6px; border:1px solid #D6CBC1; background:#FFF; cursor:pointer;">
                          <option value="review" <?= $app['status']==='review'?'selected':'' ?>>Set Review</option>
                          <option value="interview" <?= $app['status']==='interview'?'selected':'' ?>>Set Interview</option>
                          <option value="decision" <?= $app['status']==='decision'?'selected':'' ?>>Set Decision</option>
                          <option value="hired" <?= $app['status']==='hired'?'selected':'' ?>>Mark Hired</option>
                          <option value="rejected" <?= $app['status']==='rejected'?'selected':'' ?>>Mark Rejected</option>
                        </select>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<!-- ── MODAL: POST NEW JOB VACANCY ────────────────────────── -->
<div class="rec-modal-backdrop" id="addJobModalBackdrop">
  <div class="rec-modal">
    <button type="button" class="rec-modal-close" onclick="closeAddJobModal()"><?= icon('x', 16) ?></button>
    <div style="margin-bottom: 20px;">
      <h3 style="font-family:'Playfair Display',serif; font-size:22px; font-weight:700; color:#201426;">Post New Job Vacancy</h3>
      <p style="font-size:13px; color:#8B7C88;">Create an open position. It will appear live on the public Careers portal.</p>
    </div>

    <form method="POST" action="recruitment.php">
      <input type="hidden" name="action" value="add_job">

      <div class="form-grid-2">
        <div>
          <label class="form-label" for="addJobTitle">Position Title <span style="color:#D23F3A;">*</span></label>
          <input type="text" id="addJobTitle" name="title" class="form-input" placeholder="e.g. Cashier, Shift Supervisor, Baker" required>
        </div>
        <div>
          <label class="form-label" for="addJobDepartment">Department <span style="color:#D23F3A;">*</span></label>
          <select id="addJobDepartment" name="department" class="form-select" required>
            <option value="Coffee &amp; Barista">Coffee &amp; Barista</option>
            <option value="Store Operations">Store Operations</option>
            <option value="Kitchen &amp; Food">Kitchen &amp; Food</option>
            <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>
            <option value="Management">Management</option>
            <option value="Customer Service">Customer Service</option>
          </select>
        </div>
      </div>

      <div class="form-grid-2">
        <div>
          <label class="form-label" for="addJobLocation">Store Location <span style="color:#D23F3A;">*</span></label>
          <input type="text" id="addJobLocation" name="location" class="form-input" placeholder="e.g. Manila, Quezon City, Makati" required>
        </div>
        <div>
          <label class="form-label" for="addJobType">Job Type <span style="color:#D23F3A;">*</span></label>
          <select id="addJobType" name="job_type" class="form-select" required>
            <option value="Full-time">Full-time</option>
            <option value="Part-time">Part-time</option>
            <option value="Seasonal / Temporary">Seasonal / Temporary</option>
            <option value="Contract">Contract</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="addJobTagline">Tagline / Short Summary <span style="color:#D23F3A;">*</span></label>
        <input type="text" id="addJobTagline" name="tagline" class="form-input" placeholder="e.g. Manage register transactions with warm and prompt service." required>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="addJobAbout">About the Role</label>
        <textarea id="addJobAbout" name="about_role" class="form-textarea" rows="2" placeholder="Brief overview of the role and team culture..."></textarea>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="addJobResp">Key Responsibilities <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="addJobResp" name="responsibilities" class="form-textarea" rows="3" placeholder="Greet customers warmly upon arrival&#10;Process orders accurately via POS&#10;Maintain a clean and sanitized counter"></textarea>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="addJobReqs">Qualifications &amp; Requirements <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="addJobReqs" name="requirements" class="form-textarea" rows="3" placeholder="Friendly personality and good communication skills&#10;Punctual and reliable team player&#10;Previous retail experience is a plus"></textarea>
      </div>

      <div style="margin-bottom: 18px;">
        <label class="form-label" for="addJobBens">Perks &amp; Benefits <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="addJobBens" name="benefits" class="form-textarea" rows="2" placeholder="Free shift coffee and staff food discount&#10;Daily tips distribution&#10;Career advancement opportunities"></textarea>
      </div>

      <div style="margin-bottom: 22px; display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="addJobActive" name="is_active" value="1" checked style="width:16px;height:16px;accent-color:#C97B3D;">
        <label for="addJobActive" style="font-size:13px; color:#201426; font-weight:600; cursor:pointer;">
          Open Vacancy (Publish immediately to public Careers portal)
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #E8DFD5; padding-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddJobModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;">Publish Vacancy</button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: EDIT JOB VACANCY ──────────────────────────── -->
<div class="rec-modal-backdrop" id="editJobModalBackdrop">
  <div class="rec-modal">
    <button type="button" class="rec-modal-close" onclick="closeEditJobModal()"><?= icon('x', 16) ?></button>
    <div style="margin-bottom: 20px;">
      <h3 style="font-family:'Playfair Display',serif; font-size:22px; font-weight:700; color:#201426;">Edit Job Vacancy</h3>
      <p style="font-size:13px; color:#8B7C88;">Update position requirements, details, or change its vacancy status.</p>
    </div>

    <form method="POST" action="recruitment.php">
      <input type="hidden" name="action" value="edit_job">
      <input type="hidden" name="job_id" id="editJobId" value="">

      <div class="form-grid-2">
        <div>
          <label class="form-label" for="editJobTitle">Position Title <span style="color:#D23F3A;">*</span></label>
          <input type="text" id="editJobTitle" name="title" class="form-input" required>
        </div>
        <div>
          <label class="form-label" for="editJobDepartment">Department <span style="color:#D23F3A;">*</span></label>
          <select id="editJobDepartment" name="department" class="form-select" required>
            <option value="Coffee &amp; Barista">Coffee &amp; Barista</option>
            <option value="Store Operations">Store Operations</option>
            <option value="Kitchen &amp; Food">Kitchen &amp; Food</option>
            <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>
            <option value="Management">Management</option>
            <option value="Customer Service">Customer Service</option>
          </select>
        </div>
      </div>

      <div class="form-grid-2">
        <div>
          <label class="form-label" for="editJobLocation">Store Location <span style="color:#D23F3A;">*</span></label>
          <input type="text" id="editJobLocation" name="location" class="form-input" required>
        </div>
        <div>
          <label class="form-label" for="editJobType">Job Type <span style="color:#D23F3A;">*</span></label>
          <select id="editJobType" name="job_type" class="form-select" required>
            <option value="Full-time">Full-time</option>
            <option value="Part-time">Part-time</option>
            <option value="Seasonal / Temporary">Seasonal / Temporary</option>
            <option value="Contract">Contract</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="editJobTagline">Tagline / Short Summary <span style="color:#D23F3A;">*</span></label>
        <input type="text" id="editJobTagline" name="tagline" class="form-input" required>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="editJobAbout">About the Role</label>
        <textarea id="editJobAbout" name="about_role" class="form-textarea" rows="2"></textarea>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="editJobResp">Key Responsibilities <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="editJobResp" name="responsibilities" class="form-textarea" rows="3"></textarea>
      </div>

      <div style="margin-bottom: 14px;">
        <label class="form-label" for="editJobReqs">Qualifications &amp; Requirements <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="editJobReqs" name="requirements" class="form-textarea" rows="3"></textarea>
      </div>

      <div style="margin-bottom: 18px;">
        <label class="form-label" for="editJobBens">Perks &amp; Benefits <span style="font-weight:normal;color:#8B7C88;">(one per line)</span></label>
        <textarea id="editJobBens" name="benefits" class="form-textarea" rows="2"></textarea>
      </div>

      <div style="margin-bottom: 22px; display:flex; align-items:center; gap:8px;">
        <input type="checkbox" id="editJobActive" name="is_active" value="1" style="width:16px;height:16px;accent-color:#C97B3D;">
        <label for="editJobActive" style="font-size:13px; color:#201426; font-weight:600; cursor:pointer;">
          Position is Open &amp; accepting applications (Uncheck to mark as Filled / Hide from Careers)
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #E8DFD5; padding-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="closeEditJobModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="padding:10px 24px;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openAddJobModal() {
    document.getElementById('addJobModalBackdrop').classList.add('open');
  }
  function closeAddJobModal() {
    document.getElementById('addJobModalBackdrop').classList.remove('open');
  }

  function openEditJobModal(job) {
    document.getElementById('editJobId').value = job.id;
    document.getElementById('editJobTitle').value = job.title;
    document.getElementById('editJobDepartment').value = job.department;
    document.getElementById('editJobLocation').value = job.location;
    document.getElementById('editJobType').value = job.job_type;
    document.getElementById('editJobTagline').value = job.tagline || job.description;
    document.getElementById('editJobAbout').value = job.about_role || '';
    document.getElementById('editJobResp').value = job.responsibilities || '';
    document.getElementById('editJobReqs').value = job.requirements || '';
    document.getElementById('editJobBens').value = job.benefits || '';
    document.getElementById('editJobActive').checked = (parseInt(job.is_active) === 1);

    document.getElementById('editJobModalBackdrop').classList.add('open');
  }
  function closeEditJobModal() {
    document.getElementById('editJobModalBackdrop').classList.remove('open');
  }

  window.addEventListener('click', function(e) {
    if (e.target.classList.contains('rec-modal-backdrop')) {
      e.target.classList.remove('open');
    }
  });
</script>

</body>
</html>
