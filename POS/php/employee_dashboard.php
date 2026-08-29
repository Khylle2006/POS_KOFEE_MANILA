<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
// Self-service page — every logged-in role with an employee profile can use
// this, so it's gated on login only, not a specific admin/HR permission.

$pdo   = get_db();
$user  = current_user();
$today = date('Y-m-d');

const ANNUAL_LEAVE_DAYS = 15; // placeholder pool — adjust to your real policy
$leave_types = ['Vacation','Sick','Emergency','Unpaid','Other'];

$stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $user['id']]);
$my_employee = $stmt->fetch() ?: null;

// ── Today's attendance row ─────────────────────
$today_att = null;
if ($my_employee) {
    $s = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=:e AND attendance_date=:d');
    $s->execute([':e'=>$my_employee['id'], ':d'=>$today]);
    $today_att = $s->fetch() ?: null;
}

// ── This month's metrics ───────────────────────
$metrics = ['present'=>0, 'absent'=>0, 'work_days'=>0, 'leave_balance'=>ANNUAL_LEAVE_DAYS];
if ($my_employee) {
    $month_start = date('Y-m-01');
    $month_end   = date('Y-m-t');

    $st = $pdo->prepare("
        SELECT status, COUNT(*) c FROM attendance
        WHERE employee_id=:e AND attendance_date BETWEEN :s AND :en
        GROUP BY status
    ");
    $st->execute([':e'=>$my_employee['id'], ':s'=>$month_start, ':en'=>$month_end]);
    $byStatus = [];
    foreach ($st->fetchAll() as $row) $byStatus[$row['status']] = (int)$row['c'];

    $metrics['present']   = ($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0) + ($byStatus['half_day'] ?? 0);
    $metrics['absent']    = $byStatus['absent'] ?? 0;
    $metrics['work_days'] = array_sum($byStatus);

    $used = $pdo->prepare("
        SELECT COALESCE(SUM(days_count),0) FROM leave_requests
        WHERE employee_id=:e AND status='approved' AND YEAR(start_date)=YEAR(CURDATE())
    ");
    $used->execute([':e'=>$my_employee['id']]);
    $metrics['leave_balance'] = max(0, ANNUAL_LEAVE_DAYS - (float)$used->fetchColumn());
}

// ── Recent attendance (last 10) ────────────────
$att_rows = [];
if ($my_employee) {
    $ar = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=:e ORDER BY attendance_date DESC LIMIT 10');
    $ar->execute([':e'=>$my_employee['id']]);
    $att_rows = $ar->fetchAll();
}

// ── My leave requests (last 8) ─────────────────
$leaves = [];
if ($my_employee) {
    $lr = $pdo->prepare("
        SELECT * FROM leave_requests WHERE employee_id=:e
        ORDER BY FIELD(status,'pending','approved','rejected'), created_at DESC LIMIT 8
    ");
    $lr->execute([':e'=>$my_employee['id']]);
    $leaves = $lr->fetchAll();
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$fname = $user['firstname'] ?: $user['username'];
$initials = strtoupper(substr($fname, 0, 1));

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Dashboard — Kofee Manila</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/home.css"/>
  <link rel="stylesheet" href="../css/employee_dashboard.css"/>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>

<div id="page-employee-home" class="page active">
  <div class="page-header">
    <div>
      <h1><?= $greeting ?>, <?= htmlspecialchars($fname) ?> <?= icon('sun', 20) ?></h1>
      <p><?= date('l, F j, Y') ?> — Your attendance &amp; leave overview</p>
    </div>
    <div class="signed-in-pill">
      Signed in as <b>@<?= htmlspecialchars($user['username']) ?></b>
      <div class="signed-in-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
  </div>

  <div class="page-body">

    <?php if (!$my_employee): ?>
      <div class="notice-banner">
        ⚠️ Your account isn't linked to an employee profile yet, so clock in/out and leave filing are disabled. Ask HR to link your account.
      </div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="home-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--accent-lt)"><?= icon('attendance') ?></div>
        <div class="stat-label">Today's Status</div>
        <div class="stat-value" id="today-status-val" style="font-size:16px">
          <?php if ($today_att && $today_att['time_in'] && !$today_att['time_out']): ?>
            Clocked In
          <?php elseif ($today_att && $today_att['time_out']): ?>
            Clocked Out
          <?php else: ?>
            Not Clocked In
          <?php endif; ?>
        </div>
        <div class="stat-sub" id="today-status-sub">
          <?= $today_att && $today_att['time_in'] ? date('g:i A', strtotime($today_att['time_in'])) : 'Tap Clock In to start' ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--green-lt);color:var(--green)"><?= icon('order') ?></div>
        <div class="stat-label">Present Days</div>
        <div class="stat-value"><?= $metrics['present'] ?></div>
        <div class="stat-sub">This month</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--red-lt);color:var(--red)"><?= icon('pending') ?></div>
        <div class="stat-label">Absent Days</div>
        <div class="stat-value"><?= $metrics['absent'] ?></div>
        <div class="stat-sub">This month</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--blue-lt);color:var(--blue)"><?= icon('leave') ?></div>
        <div class="stat-label">Leave Balance</div>
        <div class="stat-value"><?= $metrics['leave_balance'] ?></div>
        <div class="stat-sub">Days remaining</div>
      </div>
    </div>

    <!-- Quick access -->
    <div>
      <div class="section-title">Quick Access</div>
      <div class="home-shortcuts">
        <button type="button" class="shortcut-card" id="btn-clockin"
                onclick="openCamera('clock_in')" <?= (!$my_employee || ($today_att && $today_att['time_in'])) ? 'disabled' : '' ?>>
          <div class="shortcut-icon"><?= icon('attendance') ?></div>
          <div><h3>Clock In</h3><p>Snap a photo to start your shift</p></div>
        </button>
        <button type="button" class="shortcut-card" id="btn-clockout"
                onclick="openCamera('clock_out')" <?= (!$my_employee || !$today_att || !$today_att['time_in'] || $today_att['time_out']) ? 'disabled' : '' ?>>
          <div class="shortcut-icon"><?= icon('attendance') ?></div>
          <div><h3>Clock Out</h3><p>Snap a photo to end your shift</p></div>
        </button>
        <button type="button" class="shortcut-card" onclick="openLeaveModal()" <?= !$my_employee ? 'disabled' : '' ?>>
          <div class="shortcut-icon"><?= icon('leave') ?></div>
          <div><h3>File Leave Request</h3><p>Submit a new leave application</p></div>
        </button>
        <a href="menu.php" class="shortcut-card">
          <div class="shortcut-icon"><?= icon('order') ?></div>
          <div><h3>New Order</h3><p>Go to the POS order screen</p></div>
        </a>
      </div>
    </div>

    <!-- Attendance table -->
    <div class="recent-section">
      <div class="recent-header">
        <h2>My Attendance</h2>
      </div>
      <div class="table-scroll-wrapper">
        <table class="recent-table" id="attendance-table">
          <thead>
            <tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Proof</th></tr>
          </thead>
          <tbody id="attendance-tbody">
            <?php if (empty($att_rows)): ?>
              <tr class="empty-row"><td colspan="5">🫙 No attendance records yet.</td></tr>
            <?php else: foreach ($att_rows as $r): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($r['attendance_date'])) ?></td>
                <td><?= $r['time_in']  ? date('g:i A', strtotime($r['time_in']))  : '—' ?></td>
                <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
                <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucwords(str_replace('_',' ',$r['status'])) ?></span></td>
                <td>
                  <div class="proof-thumbs">
                    <?php if ($r['time_in_photo']): ?>
                      <img src="../<?= htmlspecialchars($r['time_in_photo']) ?>" class="proof-thumb" onclick="openPhotoPreview('../<?= htmlspecialchars($r['time_in_photo']) ?>')" title="Clock-in photo"/>
                    <?php endif; ?>
                    <?php if ($r['time_out_photo']): ?>
                      <img src="../<?= htmlspecialchars($r['time_out_photo']) ?>" class="proof-thumb" onclick="openPhotoPreview('../<?= htmlspecialchars($r['time_out_photo']) ?>')" title="Clock-out photo"/>
                    <?php endif; ?>
                    <?php if (!$r['time_in_photo'] && !$r['time_out_photo']): ?>—<?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Leave requests list -->
    <div class="recent-section">
      <div class="recent-header"><h2>My Leave Requests</h2></div>
      <div class="table-scroll-wrapper">
        <table class="recent-table" id="leave-table">
          <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th></tr></thead>
          <tbody id="leave-tbody">
            <?php if (empty($leaves)): ?>
              <tr class="empty-row"><td colspan="5">🫙 No leave requests yet.</td></tr>
            <?php else: foreach ($leaves as $l): ?>
              <tr>
                <td style="font-weight:700"><?= htmlspecialchars($l['leave_type']) ?></td>
                <td><?= date('M d', strtotime($l['start_date'])) ?> – <?= date('M d, Y', strtotime($l['end_date'])) ?></td>
                <td><?= $l['days_count'] ?></td>
                <td class="muted-cell" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['reason'] ?: '—') ?></td>
                <td><span class="status-badge status-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- ── Camera / Attendance Modal ── -->
<div class="modal-overlay" id="camera-modal">
  <div class="modal camera-modal">
    <div class="modal-header">
      <h3 id="camera-title">📸 Clock In</h3>
      <button class="modal-close" onclick="closeCamera()">✕</button>
    </div>
    <div class="modal-body">
      <div class="camera-frame">
        <video id="camera-video" autoplay playsinline></video>
        <canvas id="camera-canvas" style="display:none"></canvas>
        <img id="camera-preview" style="display:none"/>
      </div>
      <p class="camera-hint" id="camera-hint">Position yourself in frame, then capture.</p>
      <p class="camera-error" id="camera-error" style="display:none"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-mcancel" onclick="closeCamera()">Cancel</button>
      <button type="button" class="btn-msave" id="camera-capture-btn" onclick="capturePhoto()">📷 Capture</button>
      <button type="button" class="btn-msave" id="camera-confirm-btn" style="display:none;background:var(--green)" onclick="confirmCapture()">✔ Confirm &amp; Submit</button>
      <button type="button" class="btn-mcancel" id="camera-retake-btn" style="display:none" onclick="retakePhoto()">↺ Retake</button>
    </div>
  </div>
</div>

<!-- ── Photo Preview Modal ── -->
<div class="modal-overlay" id="photo-preview-modal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3>Attendance Proof</h3>
      <button class="modal-close" onclick="closePhotoPreview()">✕</button>
    </div>
    <div class="modal-body" style="text-align:center">
      <img id="photo-preview-img" style="max-width:100%;border-radius:10px"/>
    </div>
  </div>
</div>

<!-- ── Leave Request Modal ── -->
<div class="modal-overlay" id="leave-modal">
  <div class="modal">
    <div class="modal-header">
      <h3>🏖️ File Leave Request</h3>
      <button class="modal-close" onclick="closeLeaveModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="field-group">
        <label class="field-label">Leave Type</label>
        <select class="field-input" id="lv-type">
          <?php foreach ($leave_types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-row">
        <div class="field-group">
          <label class="field-label">Start Date</label>
          <input class="field-input" type="date" id="lv-start"/>
        </div>
        <div class="field-group">
          <label class="field-label">End Date</label>
          <input class="field-input" type="date" id="lv-end"/>
        </div>
      </div>
      <div class="field-group">
        <label class="field-label">Reason</label>
        <textarea class="field-input" id="lv-reason" rows="3" placeholder="Optional"></textarea>
      </div>
      <p class="camera-error" id="leave-error" style="display:none"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-mcancel" onclick="closeLeaveModal()">Cancel</button>
      <button type="button" class="btn-msave" id="leave-submit-btn" onclick="submitLeave()">✔ Submit Request</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" style="display:none"></div>

<script>
window.EMPLOYEE_NAME = <?= json_encode(trim($fname . ' ' . ($user['lastname'] ?? ''))) ?>;
window.HAS_EMPLOYEE   = <?= json_encode((bool)$my_employee) ?>;
</script>
<script src="../js/employee_dashboard.js?v=<?= filemtime(__DIR__.'/../js/employee_dashboard.js') ?>"></script>

</body>
</html>