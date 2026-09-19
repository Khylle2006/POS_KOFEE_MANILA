<?php
// ─────────────────────────────────────────────────────────────
//  php/recruitment.php — HR & Store Manager Recruitment Portal
// ─────────────────────────────────────────────────────────────
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();

$pdo = get_db();
$user = current_user();
$role = $user['role'] ?? 'crew';
$roles = $user['roles'] ?? [$role];

// Require admin, hr, or manager
$can_manage_recruitment = in_array('admin', $roles, true) || in_array('hr', $roles, true) || in_array('manager', $roles, true) || has_permission('users.manage');
if (!$can_manage_recruitment) {
    header('Location: no_access.php');
    exit;
}

$toast = '';
$toast_type = 'success';

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $app_id = (int)($_POST['application_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');
        $notes = trim($_POST['reviewer_notes'] ?? '');

        $valid_statuses = ['review', 'interview', 'decision', 'hired', 'rejected'];
        if ($app_id > 0 && in_array($new_status, $valid_statuses, true)) {
            $stmt = $pdo->prepare('UPDATE job_applications SET status = :s, reviewer_notes = :n, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':s' => $new_status, ':n' => $notes, ':id' => $app_id]);
            $toast = '✅ Application status updated to ' . strtoupper($new_status) . '.';
        }
    } elseif ($action === 'delete_application') {
        $app_id = (int)($_POST['application_id'] ?? 0);
        if ($app_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM job_applications WHERE id = :id');
            $stmt->execute([':id' => $app_id]);
            $toast = '🗑️ Application removed.';
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: recruitment.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

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

// Counts
$count_review = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'review'")->fetchColumn();
$count_interview = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'interview'")->fetchColumn();
$count_decision = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'decision'")->fetchColumn();
$count_hired = (int)$pdo->query("SELECT COUNT(*) FROM job_applications WHERE status = 'hired'")->fetchColumn();

// All active jobs for dropdown
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
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-recruitment" class="page active">
  <div class="page-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 style="font-family:'Playfair Display',serif; font-size:24px; margin-bottom:4px;">Recruitment &amp; Careers Portal</h1>
      <p style="color:var(--text-muted, #8B7C88); font-size:13px;">Manage job candidates, review uploaded resumes, and advance applicants through the hiring pipeline</p>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="../careers.php" target="_blank" class="btn btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px;">
        <span>🌐 View Live Careers Page</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
      </a>
    </div>
  </div>

  <?php if ($toast): ?>
    <div style="margin: 16px 0; padding: 12px 18px; border-radius: 8px; background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; font-size: 13.5px; font-weight: 500;">
      <?= $toast ?>
    </div>
  <?php endif; ?>

  <div class="page-body">

    <!-- Stat Row -->
    <div class="stat-row" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px;">
      <a href="recruitment.php?status=review" class="stat-card-link">
        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#FEF3C7; color:#B45309; display:flex; align-items:center; justify-content:center; font-size:20px;">📋</div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_review ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">1. Application Review</div>
          </div>
        </div>
      </a>

      <a href="recruitment.php?status=interview" class="stat-card-link">
        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#DBEAFE; color:#1D4ED8; display:flex; align-items:center; justify-content:center; font-size:20px;">🎤</div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_interview ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">2. Interview Stage</div>
          </div>
        </div>
      </a>

      <a href="recruitment.php?status=decision" class="stat-card-link">
        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#F3E8FF; color:#7E22CE; display:flex; align-items:center; justify-content:center; font-size:20px;">⚖️</div>
          <div>
            <div class="mini-stat-val" style="font-size:22px; font-weight:700;"><?= $count_decision ?></div>
            <div class="mini-stat-lbl" style="font-size:12px; color:#8B7C88;">3. Hiring Decision</div>
          </div>
        </div>
      </a>

      <a href="recruitment.php?status=hired" class="stat-card-link">
        <div class="mini-stat" style="background:#FFF; padding:18px; border-radius:12px; border:1px solid #E8DFD5; display:flex; align-items:center; gap:14px;">
          <div class="mini-stat-icon" style="width:44px; height:44px; border-radius:10px; background:#D1FAE5; color:#047857; display:flex; align-items:center; justify-content:center; font-size:20px;">🎉</div>
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
        <a href="recruitment.php" class="filter-pill <?= $filter_status==='all'?'active':'' ?>">All (<?= count($applications) ?>)</a>
        <a href="recruitment.php?status=review" class="filter-pill <?= $filter_status==='review'?'active':'' ?>">Review</a>
        <a href="recruitment.php?status=interview" class="filter-pill <?= $filter_status==='interview'?'active':'' ?>">Interview</a>
        <a href="recruitment.php?status=decision" class="filter-pill <?= $filter_status==='decision'?'active':'' ?>">Decision</a>
        <a href="recruitment.php?status=hired" class="filter-pill <?= $filter_status==='hired'?'active':'' ?>">Hired</a>
        <a href="recruitment.php?status=rejected" class="filter-pill <?= $filter_status==='rejected'?'active':'' ?>">Rejected</a>
      </div>

      <div>
        <form method="GET" action="recruitment.php" style="display:flex; gap:8px;">
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

  </div>
</div>

</body>
</html>
