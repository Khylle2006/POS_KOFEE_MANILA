<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo   = get_db();
$toast = '';
$toast_type = 'success';

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Mark / upsert attendance for an employee on a date
    if ($action === 'mark') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $date   = $_POST['attendance_date'] ?? date('Y-m-d');
        $tin    = $_POST['time_in']  ?: null;
        $tout   = $_POST['time_out'] ?: null;
        $status = $_POST['status'] ?? 'present';
        $notes  = trim($_POST['notes'] ?? '');

        $allowed = ['present','late','absent','on_leave','half_day'];
        if (!$emp_id || !in_array($status, $allowed)) {
            $toast = '⚠️ Select an employee and a valid status.'; $toast_type = 'error';
        } else {
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
            $pdo->prepare('UPDATE attendance SET time_out = CURTIME() WHERE id = :id')->execute([':id'=>$id]);
            $toast = '✅ Clocked out.';
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
    $q = ($toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type . '&' : '?') . 'date=' . urlencode($date_q);
    header('Location: attendance.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$view_date = $_GET['date'] ?? date('Y-m-d');

// ── Active employees for the picker ───────────
$employees = $pdo->query("SELECT id, employee_code, firstname, lastname, department FROM employees WHERE status='active' ORDER BY firstname")->fetchAll();

// ── Attendance for the selected date ──────────
$stmt = $pdo->prepare("
    SELECT a.*, e.firstname, e.lastname, e.employee_code, e.department
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    WHERE a.attendance_date = :d
    ORDER BY e.firstname
");
$stmt->execute([':d' => $view_date]);
$records = $stmt->fetchAll();

$marked_ids = array_column($records, 'employee_id');
$unmarked   = array_filter($employees, fn($e) => !in_array($e['id'], $marked_ids));

$present = count(array_filter($records, fn($r) => $r['status'] === 'present'));
$late    = count(array_filter($records, fn($r) => $r['status'] === 'late'));
$absent  = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
$leave_c = count(array_filter($records, fn($r) => $r['status'] === 'on_leave'));

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
</head>
<body>

<div id="page-attendance" class="page active">
  <div class="page-header">
    <div>
      <h1>Attendance</h1>
      <p>Daily time-in / time-out records</p>
    </div>
    <button class="btn-add" onclick="openMark()">➕ Mark Attendance</button>
  </div>

  <div class="page-body">

    <div class="stat-row">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e8f5e9">✅</div><div><div class="mini-stat-val"><?= $present ?></div><div class="mini-stat-lbl">Present</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fff3e0">⏰</div><div><div class="mini-stat-val"><?= $late ?></div><div class="mini-stat-lbl">Late</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#ffebee">❌</div><div><div class="mini-stat-val"><?= $absent ?></div><div class="mini-stat-lbl">Absent</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e3f2fd">🏖️</div><div><div class="mini-stat-val"><?= $leave_c ?></div><div class="mini-stat-lbl">On Leave</div></div></div>
    </div>

    <div class="date-bar">
      <form method="GET" style="display:flex;gap:10px;align-items:center">
        <label style="font-size:12px;font-weight:700;color:var(--text-muted)">Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($view_date) ?>" onchange="this.form.submit()"/>
      </form>
      <div class="unmarked-note">
        <?= count($unmarked) ?> employee(s) not yet marked for this date
      </div>
    </div>

    <div class="table-card">
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr><th>Employee</th><th>Department</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Notes</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (empty($records)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No attendance recorded for this date yet.</td></tr>
          <?php else: foreach ($records as $r): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?> <span style="color:var(--text-muted);font-weight:400">#<?= htmlspecialchars($r['employee_code']) ?></span></td>
              <td><?= htmlspecialchars($r['department']) ?></td>
              <td><?= $r['time_in']  ? date('g:i A', strtotime($r['time_in']))  : '—' ?></td>
              <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—' ?></td>
              <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucwords(str_replace('_',' ',$r['status'])) ?></span></td>
              <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($r['notes'] ?: '—') ?></td>
              <td>
                <div class="act-group">
                  <?php if (!$r['time_out'] && $r['status'] !== 'absent'): ?>
                  <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="clock_out"/>
                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>"/>
                    <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>
                    <button type="submit" class="act-btn act-activate">⏱️ Clock Out</button>
                  </form>
                  <?php endif; ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this attendance record?')">
                    <input type="hidden" name="action" value="delete"/>
                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>"/>
                    <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>
                    <button type="submit" class="act-btn act-block">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Mark attendance modal -->
<div class="modal-bg" id="mark-modal" onclick="if(event.target===this) closeMark()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ Mark Attendance</h3>
      <button class="modal-close" onclick="closeMark()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="mark"/>
      <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($view_date) ?>"/>

      <div class="field-group mg-b">
        <label class="field-label">Employee <span class="req">*</span></label>
        <select class="field-input" name="employee_id" required>
          <option value="">Select employee…</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['firstname'].' '.$e['lastname']) ?> (#<?= htmlspecialchars($e['employee_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Date</label>
        <input class="field-input" type="date" name="attendance_date" value="<?= htmlspecialchars($view_date) ?>"/>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">Time In</label>
          <input class="field-input" type="time" name="time_in"/>
        </div>
        <div class="field-group">
          <label class="field-label">Time Out</label>
          <input class="field-input" type="time" name="time_out"/>
        </div>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Status</label>
        <select class="field-input" name="status">
          <option value="present">Present</option>
          <option value="late">Late</option>
          <option value="absent">Absent</option>
          <option value="on_leave">On Leave</option>
          <option value="half_day">Half Day</option>
        </select>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Notes</label>
        <input class="field-input" type="text" name="notes" placeholder="Optional"/>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeMark()">Cancel</button>
        <button type="submit" class="btn-save">✔ Save Record</button>
      </div>
    </form>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function openMark()  { document.getElementById('mark-modal').classList.add('open'); }
function closeMark() { document.getElementById('mark-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMark(); });
</script>

</body>
</html>