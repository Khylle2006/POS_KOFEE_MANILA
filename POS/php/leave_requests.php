<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo   = get_db();
$user  = current_user();
$role  = $user['role'] ?? 'crew';
$toast = '';
$toast_type = 'success';

// Admin/HR = reviewers only. Everyone else = requesters only.
$is_reviewer = in_array($role, ['admin', 'hr']);

$leave_types = ['Vacation','Sick','Emergency','Unpaid','Other'];

// ── Resolve the logged-in user's own employee profile (for self-service filing) ──
$my_employee = null;
if (!$is_reviewer) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $user['id']]);
    $my_employee = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── File leave — requesters only, always tied to THEIR OWN employee record ──
    if ($action === 'file') {
        if ($is_reviewer) {
            $toast = '⚠️ HR/Admin accounts review leave and cannot file new requests.'; $toast_type = 'error';
        } elseif (!$my_employee) {
            $toast = "⚠️ Your account isn't linked to an employee profile yet. Ask HR to link it first."; $toast_type = 'error';
        } else {
            $type   = in_array($_POST['leave_type'] ?? '', $leave_types) ? $_POST['leave_type'] : 'Vacation';
            $start  = $_POST['start_date'] ?? '';
            $end    = $_POST['end_date']   ?? '';
            $reason = trim($_POST['reason'] ?? '');

            if (!$start || !$end) {
                $toast = '⚠️ Start date and end date are required.'; $toast_type = 'error';
            } elseif (strtotime($end) < strtotime($start)) {
                $toast = '⚠️ End date cannot be before start date.'; $toast_type = 'error';
            } else {
                $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
                $pdo->prepare(
                    'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days_count, reason, status)
                     VALUES (:e,:t,:s,:en,:d,:r,"pending")'
                )->execute([':e'=>$my_employee['id'], ':t'=>$type, ':s'=>$start, ':en'=>$end, ':d'=>$days, ':r'=>$reason]);
                $toast = '✅ Leave request filed.';
            }
        }
    }

    // ── Approve / Reject — reviewers only ──
    if ($action === 'review') {
        if (!$is_reviewer) {
            $toast = '⚠️ Only HR/Admin can review leave requests.'; $toast_type = 'error';
        } else {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($id && in_array($status, ['approved','rejected'])) {
                $pdo->prepare('UPDATE leave_requests SET status=:s, reviewed_by=:u, reviewed_at=NOW() WHERE id=:id')
                    ->execute([':s'=>$status, ':u'=>$user['id'], ':id'=>$id]);

                if ($status === 'approved') {
                    $lr = $pdo->prepare('SELECT * FROM leave_requests WHERE id=:id');
                    $lr->execute([':id'=>$id]);
                    $row = $lr->fetch();
                    if ($row) {
                        $cur = strtotime($row['start_date']);
                        $end = strtotime($row['end_date']);
                        $ins = $pdo->prepare("
                            INSERT INTO attendance (employee_id, attendance_date, status)
                            VALUES (:e,:d,'on_leave')
                            ON DUPLICATE KEY UPDATE status='on_leave'
                        ");
                        while ($cur <= $end) {
                            $ins->execute([':e'=>$row['employee_id'], ':d'=>date('Y-m-d',$cur)]);
                            $cur = strtotime('+1 day', $cur);
                        }
                    }
                }
                $toast = $status === 'approved' ? '✅ Leave approved.' : '❌ Leave rejected.';
            }
        }
    }

    // ── Delete — reviewers can remove any; requesters can cancel their OWN pending request ──
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            if ($is_reviewer) {
                $pdo->prepare('DELETE FROM leave_requests WHERE id=:id')->execute([':id'=>$id]);
                $toast = '🗑️ Leave request deleted.';
            } elseif ($my_employee) {
                $del = $pdo->prepare("DELETE FROM leave_requests WHERE id=:id AND employee_id=:e AND status='pending'");
                $del->execute([':id'=>$id, ':e'=>$my_employee['id']]);
                $toast = $del->rowCount() ? '🗑️ Leave request cancelled.' : '⚠️ Only your own pending requests can be cancelled.';
                if (!$del->rowCount()) $toast_type = 'error';
            }
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: leave_requests.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$filter = $_GET['status'] ?? 'all';

$where  = '1=1';
$params = [];

if (!$is_reviewer) {
    $where .= ' AND lr.employee_id = :myid';
    $params[':myid'] = $my_employee['id'] ?? 0;
}

if (in_array($filter, ['pending','approved','rejected'])) {
    $where .= ' AND lr.status = :st';
    $params[':st'] = $filter;
}

$stmt = $pdo->prepare("
    SELECT lr.*, e.firstname, e.lastname, e.employee_code, e.department
    FROM leave_requests lr
    JOIN employees e ON e.id = lr.employee_id
    WHERE $where
    ORDER BY FIELD(lr.status,'pending','approved','rejected'), lr.created_at DESC
");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

$pending_c  = 0; $approved_c = 0; $rejected_c = 0;
foreach ($leaves as $l) {
    if ($l['status']==='pending') $pending_c++;
    elseif ($l['status']==='approved') $approved_c++;
    elseif ($l['status']==='rejected') $rejected_c++;
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Leave Requests — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/leave_requests.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
</head>
<body>

<div id="page-leave" class="page active">
  <div class="page-header">
    <div>
      <h1>Leave Requests</h1>
      <p><?= $is_reviewer ? 'Review and approve employee leave' : 'File and track your leave requests' ?></p>
    </div>
    <?php if (!$is_reviewer && $my_employee): ?>
      <button class="btn-add" onclick="openFile()">➕ File Leave</button>
    <?php endif; ?>
  </div>

  <div class="page-body">

    <?php if (!$is_reviewer && !$my_employee): ?>
      <div class="notice-banner">
        ⚠️ Your account isn't linked to an employee profile yet, so you can't file leave. Ask HR to link your account on the Employees page.
      </div>
    <?php endif; ?>

    <div class="stat-row">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fff3e0">⏳</div><div><div class="mini-stat-val"><?= $pending_c ?></div><div class="mini-stat-lbl">Pending</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e8f5e9">✅</div><div><div class="mini-stat-val"><?= $approved_c ?></div><div class="mini-stat-lbl">Approved</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#ffebee">❌</div><div><div class="mini-stat-val"><?= $rejected_c ?></div><div class="mini-stat-lbl">Rejected</div></div></div>
    </div>

    <div class="filter-bar">
      <a href="leave_requests.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
      <a href="leave_requests.php?status=pending" class="filter-pill <?= $filter==='pending'?'active':'' ?>">Pending</a>
      <a href="leave_requests.php?status=approved" class="filter-pill <?= $filter==='approved'?'active':'' ?>">Approved</a>
      <a href="leave_requests.php?status=rejected" class="filter-pill <?= $filter==='rejected'?'active':'' ?>">Rejected</a>
    </div>

    <div class="leave-grid">
      <?php if (empty($leaves)): ?>
        <div class="empty-state">🫙 No leave requests found.</div>
      <?php else: foreach ($leaves as $l): ?>
        <div class="leave-card">
          <div class="lc-head">
            <div>
              <div class="lc-name"><?= htmlspecialchars($l['firstname'].' '.$l['lastname']) ?></div>
              <div class="lc-sub">#<?= htmlspecialchars($l['employee_code']) ?> · <?= htmlspecialchars($l['department']) ?></div>
            </div>
            <span class="status-badge status-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span>
          </div>
          <div class="lc-body">
            <div class="lc-row"><span>Type</span><strong><?= htmlspecialchars($l['leave_type']) ?></strong></div>
            <div class="lc-row"><span>Dates</span><strong><?= date('M d', strtotime($l['start_date'])) ?> – <?= date('M d, Y', strtotime($l['end_date'])) ?></strong></div>
            <div class="lc-row"><span>Days</span><strong><?= $l['days_count'] ?></strong></div>
            <?php if ($l['reason']): ?><div class="lc-reason">"<?= htmlspecialchars($l['reason']) ?>"</div><?php endif; ?>
          </div>
          <div class="lc-actions">
            <?php if ($is_reviewer): ?>
              <?php if ($l['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;flex:1">
                  <input type="hidden" name="action" value="review"/>
                  <input type="hidden" name="id" value="<?= $l['id'] ?>"/>
                  <input type="hidden" name="status" value="approved"/>
                  <button type="submit" class="act-btn act-activate" style="width:100%">✅ Approve</button>
                </form>
                <form method="POST" style="display:inline;flex:1">
                  <input type="hidden" name="action" value="review"/>
                  <input type="hidden" name="id" value="<?= $l['id'] ?>"/>
                  <input type="hidden" name="status" value="rejected"/>
                  <button type="submit" class="act-btn act-block" style="width:100%">❌ Reject</button>
                </form>
              <?php else: ?>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($l['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;flex:1" onsubmit="return confirm('Cancel this leave request?')">
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="id" value="<?= $l['id'] ?>"/>
                  <button type="submit" class="act-btn act-block" style="width:100%">✕ Cancel</button>
                </form>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px;padding:8px 0">No actions available</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php if (!$is_reviewer && $my_employee): ?>
<!-- File leave modal (requesters only) -->
<div class="modal-bg" id="file-modal" onclick="if(event.target===this) closeFile()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ File Leave Request</h3>
      <button class="modal-close" onclick="closeFile()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="file"/>

      <div class="field-group mg-b">
        <label class="field-label">Filing as</label>
        <input class="field-input" type="text" value="<?= htmlspecialchars($my_employee['firstname'].' '.$my_employee['lastname'].' (#'.$my_employee['employee_code'].')') ?>" disabled/>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Leave Type</label>
        <select class="field-input" name="leave_type">
          <?php foreach ($leave_types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="field-row mg-b">
        <div class="field-group">
          <label class="field-label">Start Date <span class="req">*</span></label>
          <input class="field-input" type="date" name="start_date" required/>
        </div>
        <div class="field-group">
          <label class="field-label">End Date <span class="req">*</span></label>
          <input class="field-input" type="date" name="end_date" required/>
        </div>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Reason</label>
        <textarea class="field-input" name="reason" rows="3" placeholder="Optional"></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeFile()">Cancel</button>
        <button type="submit" class="btn-save">✔ Submit Request</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function openFile()  { document.getElementById('file-modal')?.classList.add('open'); }
function closeFile() { document.getElementById('file-modal')?.classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFile(); });
</script>

</body>
</html>