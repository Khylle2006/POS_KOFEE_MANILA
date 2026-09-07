<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('attendance.view');

$pdo   = get_db();
$toast = '';
$toast_type = 'success';

$ALLOWED_STATUSES = ['present', 'late', 'absent', 'on_leave', 'half_day'];

// ── Helpers ────────────────────────────────────
function is_valid_date_string($s) {
    if (!$s) return false;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

function is_valid_time_string($s) {
    // Accepts HH:MM or HH:MM:SS (native <input type="time"> sends HH:MM)
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s);
}

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Mark / upsert attendance for an employee on a date (used by both
    // the "Mark Attendance" modal and the per-employee "Review" modal)
    if ($action === 'mark') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $date   = $_POST['attendance_date'] ?? date('Y-m-d');
        $tin    = ($_POST['time_in']  ?? '') !== '' ? $_POST['time_in']  : null;
        $tout   = ($_POST['time_out'] ?? '') !== '' ? $_POST['time_out'] : null;
        $status = $_POST['status'] ?? 'present';
        $notes  = trim($_POST['notes'] ?? '');
        $notes  = mb_substr($notes, 0, 255); // matches column length

        $errors = [];

        // Employee must exist and be active
        if (!$emp_id) {
            $errors[] = 'Select an employee.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM employees WHERE id = :id AND status = 'active'");
            $chk->execute([':id' => $emp_id]);
            if (!$chk->fetch()) {
                $errors[] = 'Employee not found or is not active.';
            }
        }

        // Date must be valid
        if (!is_valid_date_string($date)) {
            $errors[] = 'Invalid attendance date.';
        }

        // Status must be one of the allowed values
        if (!in_array($status, $ALLOWED_STATUSES, true)) {
            $errors[] = 'Invalid status.';
        }

        // Time values, if provided, must be valid
        if ($tin !== null && !is_valid_time_string($tin)) {
            $errors[] = 'Invalid Time In.';
            $tin = null;
        }
        if ($tout !== null && !is_valid_time_string($tout)) {
            $errors[] = 'Invalid Time Out.';
            $tout = null;
        }

        // Time Out should not be earlier than Time In (no overnight-shift support)
        if (!$errors && $tin && $tout && $tout < $tin) {
            $errors[] = 'Time Out cannot be earlier than Time In.';
        }

        if ($errors) {
            $toast = '⚠️ ' . implode(' ', $errors);
            $toast_type = 'error';
        } else {
            // Unique key on (employee_id, attendance_date) makes this a safe upsert
            $pdo->prepare("
                INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, status, notes)
                VALUES (:e, :d, :ti, :to_, :s, :n)
                ON DUPLICATE KEY UPDATE time_in=:ti2, time_out=:to2, status=:s2, notes=:n2
            ")->execute([
                ':e'=>$emp_id, ':d'=>$date, ':ti'=>$tin, ':to_'=>$tout, ':s'=>$status, ':n'=>$notes,
                ':ti2'=>$tin, ':to2'=>$tout, ':s2'=>$status, ':n2'=>$notes,
            ]);
            $toast = '✅ Attendance recorded.';
        }
    }

    // Quick clock-out (set time_out to now for a record)
    if ($action === 'clock_out') {
        $id = (int)($_POST['record_id'] ?? 0);
        if ($id) {
            $chk = $pdo->prepare('SELECT time_out, status FROM attendance WHERE id = :id');
            $chk->execute([':id' => $id]);
            $rec = $chk->fetch();
            if ($rec && !$rec['time_out'] && $rec['status'] !== 'absent') {
                $pdo->prepare('UPDATE attendance SET time_out = CURTIME() WHERE id = :id')->execute([':id'=>$id]);
                $toast = '✅ Clocked out.';
            } else {
                $toast = '⚠️ Unable to clock out this record.';
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['record_id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM attendance WHERE id = :id')->execute([':id'=>$id]);
            $toast = '🗑️ Record deleted.';
        }
    }

    $date_q = $_POST['redirect_date'] ?? date('Y-m-d');
    if (!is_valid_date_string($date_q)) $date_q = date('Y-m-d');
    $q = ($toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type . '&' : '?') . 'date=' . urlencode($date_q);
    header('Location: attendance.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$view_date = $_GET['date'] ?? date('Y-m-d');
if (!is_valid_date_string($view_date)) $view_date = date('Y-m-d');

// ── Active employees for the "Mark Attendance" picker ─────────
$employees = $pdo->query("SELECT id, employee_code, firstname, lastname, department FROM employees WHERE status='active' ORDER BY firstname")->fetchAll();

// ── ALL active employees + their attendance (if any) for the selected date ──
// LEFT JOIN keeps every active employee visible even without a record.
$stmt = $pdo->prepare("
    SELECT
        e.id AS employee_id, e.employee_code, e.firstname, e.lastname, e.department,
        a.id AS attendance_id, a.attendance_date, a.time_in, a.time_out, a.status, a.notes,
        a.time_in_photo, a.time_out_photo
    FROM employees e
    LEFT JOIN attendance a
        ON a.employee_id = e.id
        AND a.attendance_date = :d
    WHERE e.status = 'active'
    ORDER BY e.firstname, e.lastname
");
$stmt->execute([':d' => $view_date]);
$rows = $stmt->fetchAll();

// ── Stats derived from ALL active employees, not just marked ones ──
$present    = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
$late       = count(array_filter($rows, fn($r) => $r['status'] === 'late'));
$absent     = count(array_filter($rows, fn($r) => $r['status'] === 'absent'));
$leave_c    = count(array_filter($rows, fn($r) => $r['status'] === 'on_leave'));
$not_marked = count(array_filter($rows, fn($r) => $r['attendance_id'] === null));

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Attendance — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/attendance.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  /* Small additive rules for the new review dashboard pieces.
     Existing classes/colors from attendance.css are reused wherever possible;
     these only fill gaps for brand-new elements. */
  .status-not-marked { background:#eceff1; color:#546e7a; }
  .status-half_day { background:#ede7f6; color:#5e35b1; }
  .act-view { background:#e3f2fd; color:#1565c0; }
  .search-filter-bar { display:flex; gap:10px; margin:14px 0; flex-wrap:wrap; }
  .search-filter-bar input[type="text"] { flex:1; min-width:220px; }
  .search-filter-bar select { max-width:200px; }

  /* Review modal */
  .review-modal { max-width:460px; padding:0; display:flex; flex-direction:column; max-height:85vh; }
  .review-modal .modal-header { padding:18px 20px 14px; }
  .review-profile { display:flex; align-items:center; gap:12px; padding:0 20px 16px; }
  .review-avatar {
    width:40px; height:40px; border-radius:50%; flex-shrink:0;
    background:var(--accent-lt); color:var(--espresso);
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:14px;
  }
  .review-profile-text { min-width:0; flex:1; }
  .review-profile-name { font-weight:700; font-size:15px; line-height:1.3; }
  .review-profile-meta { font-size:12px; color:var(--text-muted); }
  .review-scroll { overflow-y:auto; padding:2px 20px 4px; flex:1; }
  .review-group { padding:14px 0; }
  .review-group:first-child { padding-top:0; }
  .review-divider { height:1px; background:rgba(0,0,0,.07); margin:0 -20px; }
  .review-hours { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
  .review-proof-grid { display:flex; gap:12px; }
  .review-photo-card { display:flex; flex-direction:column; align-items:center; gap:6px; }
  .review-photo-card img {
    width:72px; height:72px; object-fit:cover; border-radius:10px; cursor:pointer;
    border:1px solid rgba(0,0,0,.08);
  }
  .review-photo-card span { font-size:11px; color:var(--text-muted); }
  .review-no-photos { font-size:12.5px; color:var(--text-muted); margin:0; }
  .review-footer {
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:14px 20px; border-top:1px solid rgba(0,0,0,.07); flex-wrap:wrap;
  }
  .review-footer-secondary { display:flex; gap:16px; }
  .review-footer-primary { display:flex; gap:8px; }
  .text-action {
    background:none; border:none; padding:0; cursor:pointer;
    font-size:13px; font-weight:600; color:var(--text-muted);
  }
  .text-action:hover { color:var(--espresso); text-decoration:underline; }
  .text-action.danger { color:var(--red); }
  .text-action.danger:hover { color:var(--red); }
  @media (max-width:480px) {
    .review-footer { flex-direction:column; align-items:stretch; }
    .review-footer-primary, .review-footer-secondary { justify-content:center; }
  }
</style>
</head>
<body>

<div id="page-attendance" class="page active">
  <div class="page-header">
    <div>
      <h1>Attendance</h1>
      <p>Employee attendance review dashboard</p>
    </div>
  </div>

  <div class="page-body">

    <div class="stat-row">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e8f5e9">✅</div><div><div class="mini-stat-val"><?= $present ?></div><div class="mini-stat-lbl">Present</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fff3e0">⏰</div><div><div class="mini-stat-val"><?= $late ?></div><div class="mini-stat-lbl">Late</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#ffebee">❌</div><div><div class="mini-stat-val"><?= $absent ?></div><div class="mini-stat-lbl">Absent</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e3f2fd">🏖️</div><div><div class="mini-stat-val"><?= $leave_c ?></div><div class="mini-stat-lbl">On Leave</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#eceff1">📋</div><div><div class="mini-stat-val"><?= $not_marked ?></div><div class="mini-stat-lbl">Not Marked</div></div></div>
    </div>

    <div class="date-bar">
      <form method="GET" style="display:flex;gap:10px;align-items:center">
        <label style="font-size:12px;font-weight:700;color:var(--text-muted)">Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($view_date) ?>" onchange="this.form.submit()"/>
      </form>
      <div class="unmarked-note">
        <?= count($rows) ?> active employee(s) for this date
      </div>
    </div>

    <div class="search-filter-bar">
      <input type="text" id="emp-search" class="field-input" placeholder="🔍 Search employee..." oninput="filterTable()"/>
      <select id="status-filter" class="field-input" onchange="filterTable()">
        <option value="all">All Status</option>
        <option value="present">Present</option>
        <option value="late">Late</option>
        <option value="absent">Absent</option>
        <option value="on_leave">On Leave</option>
        <option value="half_day">Half Day</option>
        <option value="not_marked">Not Marked</option>
      </select>
    </div>

    <div class="table-card">
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr><th>Employee</th><th>Department</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Notes</th><th>Proof</th><th>Actions</th></tr>
          </thead>
          <tbody id="attendance-tbody">
          <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="8">🫙 No active employees found.</td></tr>
          <?php else: foreach ($rows as $r):
              $has_record   = $r['attendance_id'] !== null;
              $status_key   = $has_record ? $r['status'] : 'not_marked';
              $status_label = $has_record ? ucwords(str_replace('_',' ',$r['status'])) : 'Not Marked';
              $full_name    = $r['firstname'].' '.$r['lastname'];
              $dept         = $r['department'] ?: '—';
              $search_blob  = mb_strtolower($full_name.' '.$r['employee_code'].' '.$r['department']);
              $time_in_24   = $r['time_in']  ? date('H:i', strtotime($r['time_in']))  : '';
              $time_out_24  = $r['time_out'] ? date('H:i', strtotime($r['time_out'])) : '';
              // Photos are captured by the employee self-clock-in flow (api/mark_attendance.php)
              // and stored as paths relative to the app root, resolved the same way as employee_dashboard.php.
              $time_in_photo_url  = $r['time_in_photo']  ? '../'.$r['time_in_photo']  : '';
              $time_out_photo_url = $r['time_out_photo'] ? '../'.$r['time_out_photo'] : '';
          ?>
            <tr class="emp-row" data-status="<?= htmlspecialchars($status_key) ?>" data-search="<?= htmlspecialchars($search_blob) ?>">
              <td style="font-weight:700"><?= htmlspecialchars($full_name) ?> <span style="color:var(--text-muted);font-weight:400">#<?= htmlspecialchars($r['employee_code']) ?></span></td>
              <td><?= htmlspecialchars($dept) ?></td>
              <td><?= $r['time_in']  ? date('g:i A', strtotime($r['time_in']))  : '—' ?></td>
              <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
              <td><span class="status-badge status-<?= htmlspecialchars($status_key) ?>"><?= htmlspecialchars($status_label) ?></span></td>
              <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($r['notes'] ?: '—') ?></td>
              <td>
                <div class="proof-thumbs" style="display:flex;gap:6px">
                  <?php if ($time_in_photo_url): ?>
                    <img src="<?= htmlspecialchars($time_in_photo_url) ?>" class="proof-thumb" title="Clock-in photo" style="width:36px;height:36px;object-fit:cover;border-radius:6px;cursor:pointer" onclick="openReviewPhoto('<?= htmlspecialchars($time_in_photo_url, ENT_QUOTES) ?>')"/>
                  <?php endif; ?>
                  <?php if ($time_out_photo_url): ?>
                    <img src="<?= htmlspecialchars($time_out_photo_url) ?>" class="proof-thumb" title="Clock-out photo" style="width:36px;height:36px;object-fit:cover;border-radius:6px;cursor:pointer" onclick="openReviewPhoto('<?= htmlspecialchars($time_out_photo_url, ENT_QUOTES) ?>')"/>
                  <?php endif; ?>
                  <?php if (!$time_in_photo_url && !$time_out_photo_url): ?>—<?php endif; ?>
                </div>
              </td>
              <td>
                <div class="act-group">
                  <button type="button" class="act-btn act-view"
                    onclick="openReview(this)"
                    data-employee-id="<?= $r['employee_id'] ?>"
                    data-attendance-id="<?= $has_record ? $r['attendance_id'] : '' ?>"
                    data-name="<?= htmlspecialchars($full_name) ?>"
                    data-code="<?= htmlspecialchars($r['employee_code']) ?>"
                    data-department="<?= htmlspecialchars($dept) ?>"
                    data-time-in="<?= htmlspecialchars($time_in_24) ?>"
                    data-time-out="<?= htmlspecialchars($time_out_24) ?>"
                    data-status="<?= htmlspecialchars($has_record ? $r['status'] : 'present') ?>"
                    data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>"
                    data-has-record="<?= $has_record ? '1' : '0' ?>"
                    data-time-in-photo="<?= htmlspecialchars($time_in_photo_url) ?>"
                    data-time-out-photo="<?= htmlspecialchars($time_out_photo_url) ?>"
                  >🔍 Review</button>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div id="no-results-row" style="display:none;padding:24px;text-align:center;color:var(--text-muted)">No employees match your search/filter.</div>
      </div>
    </div>
  </div>
</div>

<!-- Mark attendance modal (existing quick-add flow, unchanged) -->

<!-- Review attendance modal (new: one row per employee, mark/update/clock-out/delete) -->
<div class="modal-bg" id="review-modal" onclick="if(event.target===this) closeReview()">
  <div class="modal review-modal">
    <div class="modal-header">
      <h3>Review Attendance</h3>
      <button class="modal-close" onclick="closeReview()">✕</button>
    </div>

    <!-- Who + when, always visible, not part of the scrolling form -->
    <div class="review-profile">
      <div class="review-avatar" id="review-avatar">—</div>
      <div class="review-profile-text">
        <div class="review-profile-name" id="review-emp-name">—</div>
        <div class="review-profile-meta" id="review-emp-meta">—</div>
      </div>
      <span class="status-badge" id="review-current-badge">—</span>
    </div>

    <form method="POST" id="review-form" class="review-scroll">
      <input type="hidden" name="action" value="mark"/>
      <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>
      <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($view_date) ?>"/>
      <input type="hidden" name="employee_id" id="review-employee-id" value=""/>

      <div class="review-group">
        <div class="field-row mg-b">
          <div class="field-group">
            <label class="field-label">Time In</label>
            <input class="field-input" type="time" name="time_in" id="review-time-in" oninput="updateTotalHours()"/>
          </div>
          <div class="field-group">
            <label class="field-label">Time Out</label>
            <input class="field-input" type="time" name="time_out" id="review-time-out" oninput="updateTotalHours()"/>
          </div>
        </div>
        <div class="review-hours">Total: <strong id="review-total-hours">—</strong></div>
      </div>

      <div class="review-divider"></div>

      <div class="review-group" id="review-proof-wrap">
        <div class="review-proof-grid" id="review-proof-thumbs">
          <div class="review-photo-card" id="review-photo-in-wrap" style="display:none">
            <img id="review-photo-in" onclick="openReviewPhoto(this.src)"/>
            <span>Clock in</span>
          </div>
          <div class="review-photo-card" id="review-photo-out-wrap" style="display:none">
            <img id="review-photo-out" onclick="openReviewPhoto(this.src)"/>
            <span>Clock out</span>
          </div>
        </div>
        <p class="review-no-photos" id="review-no-photos">No photos submitted for this date.</p>
      </div>

      <div class="review-divider"></div>

      <div class="review-group">
        <div class="field-group mg-b">
          <label class="field-label">Status</label>
          <select class="field-input" name="status" id="review-status">
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="absent">Absent</option>
            <option value="on_leave">On Leave</option>
            <option value="half_day">Half Day</option>
          </select>
        </div>

        <div class="field-group">
          <label class="field-label">Notes</label>
          <textarea class="field-input" name="notes" id="review-notes" rows="2" placeholder="Optional"></textarea>
        </div>
      </div>
    </form>

    <div class="review-footer">
      <div class="review-footer-secondary">
        <button type="button" class="text-action" id="review-clockout-btn" style="display:none" onclick="submitClockOut()">Clock out</button>
        <button type="button" class="text-action danger" id="review-delete-btn" style="display:none" onclick="submitDelete()">Delete record</button>
      </div>
      <div class="review-footer-primary">
        <button type="button" class="btn-cancel" onclick="closeReview()">Cancel</button>
        <button type="submit" form="review-form" class="btn-save">✔ Save changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Photo proof preview modal -->
<div class="modal-bg" id="photo-preview-modal" onclick="if(event.target===this) closeReviewPhoto()">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3>Attendance Proof</h3>
      <button class="modal-close" onclick="closeReviewPhoto()">✕</button>
    </div>
    <div style="text-align:center;padding:0 20px 20px">
      <img id="photo-preview-img" style="max-width:100%;border-radius:10px"/>
    </div>
  </div>
</div>

<!-- Hidden helper forms used by the Review modal for clock-out / delete -->
<form method="POST" id="clockout-form" style="display:none">
  <input type="hidden" name="action" value="clock_out"/>
  <input type="hidden" name="record_id" id="clockout-record-id" value=""/>
  <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>
</form>
<form method="POST" id="delete-form" style="display:none">
  <input type="hidden" name="action" value="delete"/>
  <input type="hidden" name="record_id" id="delete-record-id" value=""/>
  <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>
</form>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function openMark()  { document.getElementById('mark-modal').classList.add('open'); }
function closeMark() { document.getElementById('mark-modal').classList.remove('open'); }

function openReview(btn) {
  const d = btn.dataset;

  document.getElementById('review-avatar').textContent = initials(d.name);
  document.getElementById('review-emp-name').textContent = d.name;
  document.getElementById('review-emp-meta').textContent =
    '#' + d.code + ' · ' + (d.department || '—') + ' · ' + formatDisplayDate('<?= htmlspecialchars($view_date) ?>');

  const hasRecord = d.hasRecord === '1';
  const currentStatusKey   = hasRecord ? d.status : 'not_marked';
  const currentStatusLabel = hasRecord ? statusLabel(d.status) : 'Not Marked';
  const badge = document.getElementById('review-current-badge');
  badge.textContent = currentStatusLabel;
  badge.className = 'status-badge status-' + currentStatusKey;

  document.getElementById('review-employee-id').value = d.employeeId;
  document.getElementById('review-time-in').value  = d.timeIn  || '';
  document.getElementById('review-time-out').value = d.timeOut || '';
  document.getElementById('review-status').value   = d.status || 'present';
  document.getElementById('review-notes').value    = d.notes  || '';

  const clockoutBtn = document.getElementById('review-clockout-btn');
  const deleteBtn   = document.getElementById('review-delete-btn');

  clockoutBtn.style.display = (hasRecord && !d.timeOut && d.status !== 'absent') ? 'inline-block' : 'none';
  deleteBtn.style.display   = hasRecord ? 'inline-block' : 'none';

  document.getElementById('clockout-record-id').value = d.attendanceId || '';
  document.getElementById('delete-record-id').value   = d.attendanceId || '';

  // Proof photos, captured via the employee self-clock-in flow (if any)
  const photoInWrap  = document.getElementById('review-photo-in-wrap');
  const photoOutWrap = document.getElementById('review-photo-out-wrap');
  const noPhotos     = document.getElementById('review-no-photos');

  if (d.timeInPhoto) {
    document.getElementById('review-photo-in').src = d.timeInPhoto;
    photoInWrap.style.display = 'flex';
  } else {
    photoInWrap.style.display = 'none';
  }
  if (d.timeOutPhoto) {
    document.getElementById('review-photo-out').src = d.timeOutPhoto;
    photoOutWrap.style.display = 'flex';
  } else {
    photoOutWrap.style.display = 'none';
  }
  noPhotos.style.display = (d.timeInPhoto || d.timeOutPhoto) ? 'none' : '';

  updateTotalHours();
  document.getElementById('review-modal').classList.add('open');
}

function initials(name) {
  return (name || '')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map(p => p[0].toUpperCase())
    .join('');
}

function statusLabel(status) {
  return (status || '').split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function closeReview() { document.getElementById('review-modal').classList.remove('open'); }

function openReviewPhoto(url) {
  document.getElementById('photo-preview-img').src = url;
  document.getElementById('photo-preview-modal').classList.add('open');
}
function closeReviewPhoto() { document.getElementById('photo-preview-modal').classList.remove('open'); }

function submitClockOut() {
  document.getElementById('clockout-form').submit();
}

function submitDelete() {
  if (confirm('Are you sure you want to delete this attendance record?')) {
    document.getElementById('delete-form').submit();
  }
}

function updateTotalHours() {
  const tin  = document.getElementById('review-time-in').value;
  const tout = document.getElementById('review-time-out').value;
  const out  = document.getElementById('review-total-hours');
  if (!tin || !tout) { out.textContent = '—'; return; }

  const [inH, inM]   = tin.split(':').map(Number);
  const [outH, outM] = tout.split(':').map(Number);
  const startMin = inH * 60 + inM;
  const endMin   = outH * 60 + outM;
  const diff     = endMin - startMin;

  if (diff <= 0) { out.textContent = '—'; return; }

  const h = Math.floor(diff / 60);
  const m = diff % 60;
  out.textContent = h + 'h ' + String(m).padStart(2, '0') + 'm';
}

function formatDisplayDate(isoDate) {
  const [y, m, d] = isoDate.split('-').map(Number);
  const dt = new Date(y, m - 1, d);
  return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

function filterTable() {
  const q = document.getElementById('emp-search').value.trim().toLowerCase();
  const statusVal = document.getElementById('status-filter').value;
  const rowsEls = document.querySelectorAll('#attendance-tbody tr.emp-row');
  let visibleCount = 0;

  rowsEls.forEach(function (tr) {
    const matchesSearch = !q || (tr.dataset.search || '').includes(q);
    const matchesStatus = statusVal === 'all' || tr.dataset.status === statusVal;
    const show = matchesSearch && matchesStatus;
    tr.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });

  document.getElementById('no-results-row').style.display = (visibleCount === 0 && rowsEls.length > 0) ? 'block' : 'none';
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeMark(); closeReview(); closeReviewPhoto(); }
});
</script>

</body>
</html>