<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_login();
require_permission('menu.manage');

$pdo   = get_db();
$user  = current_user();
$role  = $user['role'] ?? 'crew';
$toast = '';
$toast_type = 'success';

// Admin/HR = reviewers only. Everyone else = requesters only.
$is_reviewer = in_array($role, ['admin', 'hr']);

$request_types = [
    'Certificate of Employment',
    'ID Replacement',
    'Schedule Change',
    'Payslip Copy',
    'Document Correction',
    'Inventory Restock',
    'Inventory Adjustment',
    'Other'
];




function createInventoryRequest($pdo, $employeeId, $ingredientName, $qty, $reason) {
    $details = "Ingredient: {$ingredientName} | Qty: {$qty} | Reason: {$reason}";
    $stmt = $pdo->prepare("
        INSERT INTO hr_requests (employee_id, request_type, details, status)
        VALUES (:employee_id, 'Inventory Restock', :details, 'pending')
    ");
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':details' => $details
    ]);
}






function parseInventoryRequestDetails($details) {
    preg_match('/Ingredient ID:\s*(\d+)/i', $details, $ingredientMatch);
    preg_match('/Qty:\s*([0-9]+(?:\.[0-9]+)?)/i', $details, $qtyMatch);
    preg_match('/Type:\s*([^;]+)/i', $details, $typeMatch);

    return [
        'ingredient_id' => isset($ingredientMatch[1]) ? (int)$ingredientMatch[1] : 0,
        'qty' => isset($qtyMatch[1]) ? (float)$qtyMatch[1] : 0.0,
        'type' => isset($typeMatch[1]) ? trim($typeMatch[1]) : '',
    ];
}

function applyApprovedInventoryRequest($pdo, $request) {
    $parsed = parseInventoryRequestDetails($request['details'] ?? '');
    if (!$parsed['ingredient_id'] || $parsed['qty'] <= 0) {
        return false;
    }

    $type = $parsed['type'];
    if ($type === 'Inventory Adjustment') {
        $stmt = $pdo->prepare('UPDATE ingredients SET quantity = :q WHERE id = :id');
        $stmt->execute([
            ':q' => $parsed['qty'],
            ':id' => $parsed['ingredient_id'],
        ]);

        $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
            ->execute([
                ':i' => $parsed['ingredient_id'],
                ':q' => $parsed['qty'],
                ':u' => $request['reviewed_by'] ?? null,
            ]);

        return true;
    }

    if ($type === 'Inventory Restock') {
        $stmt = $pdo->prepare('UPDATE ingredients SET quantity = quantity + :q WHERE id = :id');
        $stmt->execute([
            ':q' => $parsed['qty'],
            ':id' => $parsed['ingredient_id'],
        ]);

        $pdo->prepare('INSERT INTO restock_log (ingredient_id, added_qty, processed_by) VALUES (:i,:q,:u)')
            ->execute([
                ':i' => $parsed['ingredient_id'],
                ':q' => $parsed['qty'],
                ':u' => $request['reviewed_by'] ?? null,
            ]);

        return true;
    }

    return false;
}

// ── Resolve the logged-in user's own employee profile (for self-service filing) ──
$my_employee = null;
if (!$is_reviewer) {
    $stmt = $pdo->prepare('SELECT * FROM employees WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $user['id']]);
    $my_employee = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── File a request — requesters only, always tied to THEIR OWN employee record ──
    if ($action === 'file') {
        if ($is_reviewer) {
            $toast = '⚠️ HR/Admin accounts review requests and cannot file new ones.'; $toast_type = 'error';
        } elseif (!$my_employee) {
            $toast = "⚠️ Your account isn't linked to an employee profile yet. Ask HR to link it first."; $toast_type = 'error';
        } else {
            $type    = trim($_POST['request_type'] ?? '');
            $type    = $type ?: 'Other';
            $details = trim($_POST['details'] ?? '');

            $pdo->prepare('INSERT INTO hr_requests (employee_id, request_type, details, status) VALUES (:e,:t,:d,"pending")')
                ->execute([':e' => $my_employee['id'], ':t' => $type, ':d' => $details]);
            $toast = '✅ Request filed.';
        }
    }

    // ── Approve / Reject / Complete — reviewers only ──
    if ($action === 'review') {
        if (!$is_reviewer) {
            $toast = '⚠️ Only HR/Admin can review requests.'; $toast_type = 'error';
        } else {
            $id     = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($id && in_array($status, ['approved','rejected','completed'])) {
                $request = $pdo->prepare('SELECT * FROM hr_requests WHERE id=:id LIMIT 1');
                $request->execute([':id' => $id]);
                $row = $request->fetch();

                if ($status === 'approved' && $row && in_array($row['request_type'], ['Inventory Restock', 'Inventory Adjustment'])) {
                    applyApprovedInventoryRequest($pdo, $row);
                }

                $pdo->prepare('UPDATE hr_requests SET status=:s, reviewed_by=:u, reviewed_at=NOW() WHERE id=:id')
                    ->execute([':s'=>$status, ':u'=>$user['id'], ':id'=>$id]);

                $labels = ['approved'=>'✅ Request approved.', 'rejected'=>'❌ Request rejected.', 'completed'=>'📦 Request marked completed.'];
                $toast  = $labels[$status];
            }
        }
    }

    // ── Delete — reviewers can remove any; requesters can cancel their OWN pending request ──
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            if ($is_reviewer) {
                $pdo->prepare('DELETE FROM hr_requests WHERE id=:id')->execute([':id'=>$id]);
                $toast = '🗑️ Request deleted.';
            } elseif ($my_employee) {
                $del = $pdo->prepare("DELETE FROM hr_requests WHERE id=:id AND employee_id=:e AND status='pending'");
                $del->execute([':id'=>$id, ':e'=>$my_employee['id']]);
                $toast = $del->rowCount() ? '🗑️ Request cancelled.' : '⚠️ Only your own pending requests can be cancelled.';
                if (!$del->rowCount()) $toast_type = 'error';
            }
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

$filter = $_GET['status'] ?? 'all';

$where  = '1=1';
$params = [];

if (!$is_reviewer) {
    // Requesters only ever see their own requests
    $where .= ' AND r.employee_id = :myid';
    $params[':myid'] = $my_employee['id'] ?? 0;
}

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
<title>Requests — Kofee POS</title>
<link rel="stylesheet" href="../css/style.css"/>
<link rel="stylesheet" href="../css/sidebar.css"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  /* hr_requests.css was referenced but never shipped — these are the
     few bits this page needs beyond the shared style.css tokens. */
  .notice-banner {
    background: var(--amber-lt); color: var(--amber);
    border: 1.5px solid #f0dcb8; border-radius: var(--radius-sm);
    padding: 12px 16px; font-size: 13px; font-weight: 600;
  }
  .req { color: var(--red); }
</style>
</head>
<body>

<div id="page-hrrequests" class="page active">
  <div class="page-header">
    <div>
      <h1>Requests</h1>
      <p><?= $is_reviewer ? 'Review employee document & administrative requests' : 'File and track your requests' ?></p>
    </div>
    <?php if (!$is_reviewer && $my_employee): ?>
      <button class="btn btn-primary" onclick="openFile()">➕ New Request</button>
    <?php endif; ?>
  </div>

  <div class="page-body">

    <?php if (!$is_reviewer && !$my_employee): ?>
      <div class="notice-banner">
        ⚠️ Your account isn't linked to an employee profile yet, so you can't file requests. Ask HR to link your account on the Employees page.
      </div>
    <?php endif; ?>

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
          <thead>
            <tr>
              <?php if ($is_reviewer): ?><th>Employee</th><?php endif; ?>
              <th>Request Type</th><th>Details</th><th>Filed</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($requests)): ?>
            <tr class="empty-row"><td colspan="<?= $is_reviewer ? 6 : 5 ?>">🫙 No requests found.</td></tr>
          <?php else: foreach ($requests as $r): ?>
            <tr>
              <?php if ($is_reviewer): ?>
                <td style="font-weight:700"><?= htmlspecialchars($r['firstname'].' '.$r['lastname']) ?> <span style="color:var(--text-muted);font-weight:400">#<?= htmlspecialchars($r['employee_code']) ?></span></td>
              <?php endif; ?>
              <td><?= htmlspecialchars($r['request_type']) ?></td>
              <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted)"><?= htmlspecialchars($r['details'] ?: '—') ?></td>
              <td style="color:var(--text-muted);font-size:12px"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
              <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td>
                <div class="act-group">
                  <?php if ($is_reviewer): ?>
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
                  <?php else: ?>
                    <?php if ($r['status'] === 'pending'): ?>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this request?')">
                        <input type="hidden" name="action" value="delete"/>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                        <button type="submit" class="act-btn act-block">✕ Cancel</button>
                      </form>
                    <?php else: ?>
                      <span style="color:var(--text-muted);font-size:12px">—</span>
                    <?php endif; ?>
                  <?php endif; ?>
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

<?php if (!$is_reviewer && $my_employee): ?>
<!-- File request modal (requesters only) -->
<div class="modal-overlay" id="file-modal" onclick="if(event.target===this) closeFile()">
  <div class="modal">
    <div class="modal-header">
      <h3>➕ New Request</h3>
      <button class="modal-close" onclick="closeFile()">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="file"/>

      <div class="field-group mg-b">
        <label class="field-label">Filing as</label>
        <input class="field-input" type="text" value="<?= htmlspecialchars($my_employee['firstname'].' '.$my_employee['lastname'].' (#'.$my_employee['employee_code'].')') ?>" disabled/>
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