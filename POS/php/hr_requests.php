<?php
require_once '../includes/db.php';
require_once '../includes/auth_check.php';
require_role();

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

$request_types = ['Certificate of Employment','ID Replacement','Schedule Change','Payslip Copy','Document Correction','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'file') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $type   = trim($_POST['request_type'] ?? '');
        $type   = in_array($type, $request_types) ? $type : ($type ?: 'Other');
        $details = trim($_POST['details'] ?? '');

        if (!$emp_id || !$type) {
            $toast = '⚠️ Employee and request type are required.'; $toast_type = 'error';
        } else {
            $pdo->prepare('INSERT INTO hr_requests (employee_id, request_type, details, status) VALUES (:e,:t,:d,"pending")')
                ->execute([':e'=>$emp_id, ':t'=>$type, ':d'=>$details]);
            $toast = '✅ Request filed.';
        }
    }

    if ($action === 'review') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id && in_array($status, ['approved','rejected','completed'])) {
            $pdo->prepare('UPDATE hr_requests SET status=:s, reviewed_by=:u, reviewed_at=NOW() WHERE id=:id')
                ->execute([':s'=>$status, ':u'=>$user['id'], ':id'=>$id]);
            $labels = ['approved'=>'✅ Request approved.', 'rejected'=>'❌ Request rejected.', 'completed'=>'📦 Request marked completed.'];
            $toast = $labels[$status];
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM hr_requests WHERE id=:id')->execute([':id'=>$id]);
            $toast = '🗑️ Request deleted.';
        }
    }

    $q = $toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '';
    header('Location: hr_requests.php' . $q);
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$filter    = $_GET['status'] ?? 'all';
$employees = $pdo->query("SELECT id, employee_code, firstname, lastname FROM employees WHERE status='active' ORDER BY firstname")->fetchAll();

$where = '1=1';
$params = [];
if (in_array($filter, ['pending','approved','rejected','completed'])) {
    $where .= ' AND r.status = :st';
    $params[':st'] = $filter;
}

$stmt = $pdo->prepare("
    SELECT r.*, e.firstname, e.lastname, e.employee_code
    FROM hr_requests r
    JOIN employees e ON e.id = r.employee_id
    WHERE $where
    ORDER BY FIELD(r.status,'pending','approved','completed','rejected'), r.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pending_c = 0; $approved_c = 0; $completed_c = 0;
foreach ($requests as $r) {
    if ($r['status']==='pending') $pending_c++;
    elseif ($r['status']==='approved') $approved_c++;
    elseif ($r['status']==='completed') $completed_c++;
}

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>HR Requests — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link rel="stylesheet" href="../css/hr_requests.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
</head>
<body>

<div id="page-hrrequests" class="page active">
  <div class="page-header">
    <div>
      <h1>Requests</h1>
      <p>Employee document & administrative requests</p>
    </div>
    <button class="btn-add" onclick="openFile()">➕ New Request</button>
  </div>

  <div class="page-body">

    <div class="stat-row">
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#fff3e0">⏳</div><div><div class="mini-stat-val"><?= $pending_c ?></div><div class="mini-stat-lbl">Pending</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e8f5e9">✅</div><div><div class="mini-stat-val"><?= $approved_c ?></div><div class="mini-stat-lbl">Approved</div></div></div>
      <div class="mini-stat"><div class="mini-stat-icon" style="background:#e3f2fd">📦</div><div><div class="mini-stat-val"><?= $completed_c ?></div><div class="mini-stat-lbl">Completed</div></div></div>
    </div>

    <div class="filter-bar">
      <a href="hr_requests.php" class="filter-pill <?= $filter==='all'?'active':'' ?>">All</a>
      <a href="hr_requests.php?status=pending" class="filter-pill <?= $filter==='pending'?'active':'' ?>">Pending</a>
      <a href="hr_requests.php?status=approved" class="filter-pill <?= $filter==='approved'?'active':'' ?>">Approved</a>
      <a href="hr_requests.php?status=completed" class="filter-pill <?= $filter==='completed'?'active':'' ?>">Completed</a>
      <a href="hr_requests.php?status=rejected" class="filter-pill <?= $filter==='rejected'?'active':'' ?>">Rejected</a>
    </div>

    <div class="table-card">
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>Employee</th><th>Request Type</th><th>Details</th><th>Filed</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (empty($requests)): ?>
            <tr class="empty-row"><td colspan="6">🫙 No requests found.</td></tr>
          <?php else: foreach ($requests as $r): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?> <span style="color:var(--text-muted);font-weight:400">#<?= htmlspecialchars($r['employee_code']) ?></span></td>
              <td><?= htmlspecialchars($r['request_type']) ?></td>
              <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted)"><?= htmlspecialchars($r['details'] ?: '—') ?></td>
              <td style="color:var(--text-muted);font-size:12px"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
              <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td>
                <div class="act-group">
                  <?php if ($r['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="review"/>
                      <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                      <input type="hidden" name="status" value="approved"/>
                      <button type="submit" class="act-btn act-activate">✅ Approve</button>
                    </form>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="review"/>
                      <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                      <input type="hidden" name="status" value="rejected"/>
                      <button type="submit" class="act-btn act-block">❌ Reject</button>
                    </form>
                  <?php elseif ($r['status'] === 'approved'): ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="review"/>
                      <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                      <input type="hidden" name="status" value="completed"/>
                      <button type="submit" class="act-btn act-hold">📦 Mark Completed</button>
                    </form>
                  <?php endif; ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this request?')">
                    <input type="hidden" name="action" value="delete"/>
                    <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                    <button type="submit" class="act-btn act-delete">🗑️</button>
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

<!-- File request modal -->
<div class="modal-bg" id="file-modal" onclick="if(event.target===this) closeFile()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ New HR Request</h3>
      <button class="modal-close" onclick="closeFile()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="file"/>

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
        <label class="field-label">Request Type <span class="req">*</span></label>
        <select class="field-input" name="request_type" required>
          <?php foreach ($request_types as $t): ?><option value="<?= $t ?>"><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="field-group mg-b">
        <label class="field-label">Details</label>
        <textarea class="field-input" name="details" rows="3" placeholder="Optional notes"></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeFile()">Cancel</button>
        <button type="submit" class="btn-save">✔ Submit Request</button>
      </div>
    </form>
  </div>
</div>

<?php if ($toast): ?>
<div class="toast toast-<?= $toast_type ?>" id="toast-msg"><?= $toast ?></div>
<script>setTimeout(()=>{const t=document.getElementById('toast-msg'); if(t) t.style.opacity='0';},3500);</script>
<?php endif; ?>

<script>
function openFile()  { document.getElementById('file-modal').classList.add('open'); }
function closeFile() { document.getElementById('file-modal').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeFile(); });
</script>

</body>
</html>