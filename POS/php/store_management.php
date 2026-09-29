<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/store_helpers.php';
require_once '../includes/icons.php';

require_login();
require_permission('store.view');

$pdo  = get_db();
$user = current_user();

$can_manage_hours = has_permission('store.manage');
$can_view_ops     = has_permission('operations.activity.view');

$toast = '';
$toast_type = 'success';

// Handle Store Hours Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage_hours) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_store_hours') {
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');
        $days_data = $_POST['days'] ?? [];

        $res = update_branch_store_hours(
            $pdo,
            $branch_id,
            $days_data,
            (int)$user['id'],
            trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: $user['username'],
            $reason
        );

        if ($res['ok']) {
            $toast = "Operating hours updated successfully ({$res['changes_count']} day(s) adjusted).";
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }

        header('Location: store_management.php?branch_id=' . $branch_id . '&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$branches = get_all_branches($pdo);
$selected_branch_id = (int)($_GET['branch_id'] ?? ($branches[0]['id'] ?? 1));
$selected_branch = get_branch_by_id($pdo, $selected_branch_id) ?: ($branches[0] ?? null);

$store_hours = $selected_branch ? get_branch_store_hours($pdo, $selected_branch['id']) : [];
$status_info = calculate_store_status($store_hours);

// Activity feed filters
$filter_branch = $_GET['act_branch'] ?? $selected_branch_id;
$filter_module = $_GET['act_module'] ?? 'all';
$filter_status = $_GET['act_status'] ?? 'all';
$filter_search = trim($_GET['act_search'] ?? '');
$filter_from   = $_GET['act_from'] ?? '';
$filter_to     = $_GET['act_to'] ?? '';

$activities = get_operations_activity($pdo, [
    'branch_id' => $filter_branch === 'all' ? null : (int)$filter_branch,
    'module'    => $filter_module,
    'status'    => $filter_status,
    'search'    => $filter_search,
    'date_from' => $filter_from,
    'date_to'   => $filter_to,
], 100);

$hour_logs = get_store_hour_logs($pdo, $selected_branch ? $selected_branch['id'] : null, 30);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Store Management & Operations — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .ops-grid { display: grid; grid-template-columns: 1fr 1.35fr; gap: 20px; align-items: start; }
    @media (max-width: 1024px) { .ops-grid { grid-template-columns: 1fr; } }

    .status-banner {
      padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex;
      align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;
    }
    .status-banner.open { background: #E8F8F0; border: 1.5px solid #27AE60; }
    .status-banner.closed { background: #FDF2E9; border: 1.5px solid #E67E22; }
    .status-banner.closing_soon { background: #FEF9E7; border: 1.5px solid #F1C40F; }
    .status-banner.opening_soon { background: #EBF5FB; border: 1.5px solid #3498DB; }

    .schedule-table th, .schedule-table td { padding: 10px 12px; vertical-align: middle; }
    .schedule-table tr:hover { background: #FAF7F2; }

    .feed-item {
      padding: 12px 14px; border-bottom: 1px solid var(--border, #EDE8E1);
      display: grid; grid-template-columns: auto 1fr auto; gap: 12px; align-items: start;
    }
    .feed-item:last-child { border-bottom: none; }
    .feed-icon {
      width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0;
    }
    .feed-title { font-weight: 700; font-size: 13px; color: var(--espresso, #2D1810); }
    .feed-meta { font-size: 11.5px; color: var(--text-muted, #718096); margin-top: 2px; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-store-management" class="page active">
  <div class="page-header">
    <div>
      <h1>Store Management &amp; Operations</h1>
      <p>Configure branch opening hours, manage schedules, and monitor real-time operational activity</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <label for="branch-select" style="font-size:12.5px;font-weight:700;color:var(--text-muted)">Branch:</label>
      <select id="branch-select" class="field-input" style="padding:6px 12px;font-weight:700;min-width:180px" onchange="window.location.href='store_management.php?branch_id=' + this.value">
        <?php foreach ($branches as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $b['id'] == $selected_branch_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?> (<?= htmlspecialchars($b['code']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
      <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:14px"><?= $toast ?></div>
    <?php endif; ?>

    <!-- Real-time Status Indicator Banner -->
    <div class="status-banner <?= htmlspecialchars($status_info['status']) ?>">
      <div style="display:flex;align-items:center;gap:14px">
        <div style="font-size:24px">
          <?= match($status_info['status']) {
            'open'         => icon('check-circle', 28, '', 'color:#27AE60'),
            'closing_soon' => icon('clock', 28, '', 'color:#D4AC0D'),
            'opening_soon' => icon('sun', 28, '', 'color:#2980B9'),
            default        => icon('moon', 28, '', 'color:#E67E22'),
          } ?>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <h2 style="margin:0;font-size:17px;color:var(--espresso)"><?= htmlspecialchars($selected_branch['name']) ?></h2>
            <span class="status-badge <?= $status_info['badge'] ?>"><?= $status_info['label'] ?></span>
          </div>
          <div style="font-size:13px;margin-top:3px;color:var(--text-main)"><?= htmlspecialchars($status_info['message']) ?></div>
        </div>
      </div>
      <div>
        <button type="button" class="btn-ghost" onclick="openLogsModal()" style="font-size:12px;padding:6px 12px">
          <?= icon('clock', 13) ?> View Schedule Change History
        </button>
      </div>
    </div>

    <div class="ops-grid">
      
      <!-- LEFT: Store Operating Hours Management -->
      <div class="table-card" style="padding:20px 22px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <?= icon('calendar', 16, '', 'color:var(--caramel)') ?> Weekly Operating Schedule
          </h3>
          <span style="font-size:11.5px;color:var(--text-muted)">Philippine Time (Asia/Manila)</span>
        </div>

        <form method="POST" id="hours-form" onsubmit="return validateHoursForm()">
          <input type="hidden" name="action" value="update_store_hours"/>
          <input type="hidden" name="branch_id" value="<?= $selected_branch['id'] ?>"/>

          <div id="form-error" style="display:none;background:#FDE8E8;color:#C81E1E;padding:10px 14px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:12px"></div>

          <div class="table-scroll-wrapper">
            <table class="schedule-table" style="width:100%;font-size:12.5px">
              <thead>
                <tr>
                  <th style="width:105px">Day</th>
                  <th>Opening</th>
                  <th>Closing</th>
                  <th style="text-align:center;width:75px">Closed?</th>
                  <th style="text-align:center;width:95px" title="Closing extends past midnight into next morning">Overnight?</th>
                </tr>
              </thead>
              <tbody>
                <?php for ($d = 0; $d <= 6; $d++): 
                  $sh = $store_hours[$d] ?? ['open_time'=>'07:00:00','close_time'=>'22:00:00','is_closed'=>0,'is_overnight'=>0];
                  $open_val = substr($sh['open_time'], 0, 5);
                  $close_val = substr($sh['close_time'], 0, 5);
                  $is_closed = !empty($sh['is_closed']);
                  $is_overnight = !empty($sh['is_overnight']);
                ?>
                  <tr id="row-day-<?= $d ?>">
                    <td>
                      <strong style="color:var(--espresso)"><?= get_day_name($d) ?></strong>
                      <?php if ($d === (int)date('w')): ?>
                        <span style="font-size:10px;background:var(--caramel-lt,#f8ede2);color:var(--caramel,#8b4513);padding:1px 5px;border-radius:4px;display:inline-block;margin-left:2px">Today</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <input type="time" class="field-input open-input" id="open-<?= $d ?>" name="days[<?= $d ?>][open_time]" value="<?= $open_val ?>" <?= $is_closed ? 'disabled' : '' ?> style="padding:4px 8px;font-size:12px"/>
                    </td>
                    <td>
                      <input type="time" class="field-input close-input" id="close-<?= $d ?>" name="days[<?= $d ?>][close_time]" value="<?= $close_val ?>" <?= $is_closed ? 'disabled' : '' ?> style="padding:4px 8px;font-size:12px"/>
                    </td>
                    <td style="text-align:center">
                      <input type="checkbox" id="closed-<?= $d ?>" name="days[<?= $d ?>][is_closed]" value="1" <?= $is_closed ? 'checked' : '' ?> onchange="toggleDayClosed(<?= $d ?>, this.checked)"/>
                    </td>
                    <td style="text-align:center">
                      <input type="checkbox" id="overnight-<?= $d ?>" name="days[<?= $d ?>][is_overnight]" value="1" <?= $is_overnight ? 'checked' : '' ?> <?= $is_closed ? 'disabled' : '' ?> title="Check if schedule closes past midnight"/>
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>

          <div style="margin-top:14px">
            <label for="change-reason" class="field-label" style="font-size:12px">Reason for hours change (logged to audit trail):</label>
            <input type="text" id="change-reason" name="reason" class="field-input" placeholder="e.g. Extended holiday hours or summer weekend schedule" style="padding:8px 12px;font-size:12px" required/>
          </div>

          <?php if ($can_manage_hours): ?>
            <div style="margin-top:16px;text-align:right">
              <button type="submit" class="btn-save" style="padding:8px 18px">
                <?= icon('check', 14) ?> Save Operating Hours
              </button>
            </div>
          <?php endif; ?>
        </form>
      </div>

      <!-- RIGHT: Operations Activity Feed Panel -->
      <div class="table-card" style="padding:20px 22px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
            <?= icon('activity', 16, '', 'color:var(--caramel)') ?> Operations Activity Feed
          </h3>
          <span class="status-badge" style="background:#EDF2F7;color:#4A5568"><?= count($activities) ?> Events</span>
        </div>

        <!-- Filters Form -->
        <form method="GET" style="background:#FAF8F5;border:1px solid #EDE8E1;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:12px">
          <input type="hidden" name="branch_id" value="<?= $selected_branch_id ?>"/>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:8px;margin-bottom:8px">
            <div>
              <label style="display:block;font-weight:600;margin-bottom:2px">Module</label>
              <select name="act_module" class="field-input" style="padding:4px 8px;font-size:11.5px;width:100%" onchange="this.form.submit()">
                <option value="all">All Modules</option>
                <option value="store_hours" <?= $filter_module==='store_hours'?'selected':'' ?>>Store Hours</option>
                <option value="pos_shifts" <?= $filter_module==='pos_shifts'?'selected':'' ?>>Cashier Shifts</option>
                <option value="sales" <?= $filter_module==='sales'?'selected':'' ?>>Sales &amp; POS</option>
                <option value="inventory" <?= $filter_module==='inventory'?'selected':'' ?>>Inventory</option>
                <option value="procurement" <?= $filter_module==='procurement'?'selected':'' ?>>Procurement</option>
                <option value="payroll" <?= $filter_module==='payroll'?'selected':'' ?>>Payroll</option>
              </select>
            </div>
            <div>
              <label style="display:block;font-weight:600;margin-bottom:2px">Status</label>
              <select name="act_status" class="field-input" style="padding:4px 8px;font-size:11.5px;width:100%" onchange="this.form.submit()">
                <option value="all">All Statuses</option>
                <option value="success" <?= $filter_status==='success'?'selected':'' ?>>Success</option>
                <option value="pending" <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
                <option value="warning" <?= $filter_status==='warning'?'selected':'' ?>>Warning</option>
                <option value="failed" <?= $filter_status==='failed'?'selected':'' ?>>Failed</option>
              </select>
            </div>
            <div>
              <label style="display:block;font-weight:600;margin-bottom:2px">Reference</label>
              <input type="text" name="act_search" class="field-input" placeholder="PO #, Order #..." value="<?= htmlspecialchars($filter_search) ?>" style="padding:4px 8px;font-size:11.5px;width:100%"/>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px">
            <a href="store_management.php?branch_id=<?= $selected_branch_id ?>" class="btn-ghost" style="padding:3px 10px;font-size:11.5px">Reset</a>
            <button type="submit" class="act-btn act-activate" style="padding:4px 12px;font-size:11.5px">Filter Activity</button>
          </div>
        </form>

        <!-- Events List -->
        <div style="max-height:480px;overflow-y:auto;border:1px solid #EDE8E1;border-radius:8px">
          <?php if (empty($activities)): ?>
            <div style="padding:28px;text-align:center;color:var(--text-muted);font-size:13px">
              No operational events recorded matching current filters.
            </div>
          <?php else: foreach ($activities as $act): ?>
            <div class="feed-item">
              <div class="feed-icon" style="background:<?= match($act['status']) {
                'success' => '#E8F8F0;color:#27AE60',
                'warning' => '#FEF9E7;color:#D4AC0D',
                'failed'  => '#FDE8E8;color:#E74C3C',
                default   => '#EBF5FB;color:#2980B9',
              } ?>">
                <?= match($act['module']) {
                  'store_hours' => icon('clock', 16),
                  'pos_shifts'  => icon('user-check', 16),
                  'sales'       => icon('shopping-bag', 16),
                  'inventory'   => icon('package', 16),
                  'procurement' => icon('file-text', 16),
                  'payroll'     => icon('credit-card', 16),
                  default       => icon('activity', 16),
                } ?>
              </div>
              <div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                  <span class="feed-title"><?= ucwords(str_replace('_', ' ', $act['action'])) ?></span>
                  <?php if (!empty($act['record_ref'])): ?>
                    <span style="font-size:10.5px;padding:1px 6px;border-radius:4px;background:#EDE8E1;color:var(--espresso);font-weight:700">
                      <?= htmlspecialchars($act['record_ref']) ?>
                    </span>
                  <?php endif; ?>
                  <span class="status-badge status-<?= $act['status']==='success'?'approved':($act['status']==='failed'?'rejected':'pending') ?>" style="font-size:10px;padding:1px 6px">
                    <?= ucfirst($act['status']) ?>
                  </span>
                </div>
                <div style="font-size:12px;color:var(--text-main);margin-top:3px;line-height:1.4">
                  <?= htmlspecialchars($act['details'] ?: 'No additional details logged.') ?>
                </div>
                <div class="feed-meta">
                  <?= htmlspecialchars($act['user_name'] ?: 'System') ?> · <?= date('M d, Y g:i A', strtotime($act['created_at'])) ?>
                  <?= !empty($act['branch_name']) ? ' · ' . htmlspecialchars($act['branch_name']) : '' ?>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>

  </div>
</div>

<!-- Store Hours Change History Modal -->
<div class="modal-bg" id="modal-logs" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div class="modal" style="background:#FFF;border-radius:12px;max-width:680px;width:90%;max-height:85vh;display:flex;flex-direction:column;padding:20px 24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #EDE8E1;padding-bottom:10px">
      <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:6px">
        <?= icon('clock', 16, '', 'color:var(--caramel)') ?> Store Hours Change History
      </h3>
      <button type="button" class="btn-ghost" style="padding:4px 8px;font-size:14px" onclick="closeLogsModal()">✕</button>
    </div>
    <div style="overflow-y:auto;flex:1">
      <?php if (empty($hour_logs)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:30px">No recorded changes to store hours yet.</p>
      <?php else: ?>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead>
            <tr style="border-bottom:1.5px solid #EDE8E1;text-align:left;color:var(--text-muted)">
              <th style="padding:8px">Date / Time</th>
              <th>Changed By</th>
              <th>Day</th>
              <th>Prior Hours</th>
              <th>New Hours</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($hour_logs as $l): ?>
              <tr style="border-bottom:1px solid #F0ECE4">
                <td style="padding:8px"><?= date('M d, Y g:i A', strtotime($l['created_at'])) ?></td>
                <td><strong><?= htmlspecialchars(trim(($l['firstname'] ?? '') . ' ' . ($l['lastname'] ?? '')) ?: ($l['username'] ?? 'User')) ?></strong></td>
                <td><?= get_day_name((int)$l['day_of_week']) ?></td>
                <td class="muted-cell">
                  <?= !empty($l['prior_is_closed']) ? '<span style="color:#C81E1E">Closed</span>' : (date('g:i A', strtotime($l['prior_open_time'])) . ' &ndash; ' . date('g:i A', strtotime($l['prior_close_time']))) ?>
                </td>
                <td>
                  <strong><?= !empty($l['new_is_closed']) ? '<span style="color:#C81E1E">Closed</span>' : (date('g:i A', strtotime($l['new_open_time'])) . ' &ndash; ' . date('g:i A', strtotime($l['new_close_time']))) ?></strong>
                  <?= !empty($l['new_is_overnight']) ? '<span style="font-size:10px;background:#EBF5FB;color:#2980B9;padding:1px 4px;border-radius:3px">Overnight</span>' : '' ?>
                </td>
                <td style="font-style:italic;color:var(--text-muted)"><?= htmlspecialchars($l['reason'] ?: '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <div style="margin-top:14px;text-align:right">
      <button type="button" class="btn-ghost" onclick="closeLogsModal()">Close</button>
    </div>
  </div>
</div>

<script>
function toggleDayClosed(day, isClosed) {
  const openInp = document.getElementById('open-' + day);
  const closeInp = document.getElementById('close-' + day);
  const overnightInp = document.getElementById('overnight-' + day);
  if (openInp) openInp.disabled = isClosed;
  if (closeInp) closeInp.disabled = isClosed;
  if (overnightInp) overnightInp.disabled = isClosed;
}

function validateHoursForm() {
  const errBox = document.getElementById('form-error');
  if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

  for (let d = 0; d <= 6; d++) {
    const isClosed = document.getElementById('closed-' + d)?.checked;
    if (isClosed) continue;

    const openVal = document.getElementById('open-' + d)?.value;
    const closeVal = document.getElementById('close-' + d)?.value;
    const isOvernight = document.getElementById('overnight-' + d)?.checked;

    if (!openVal || !closeVal) {
      showError('Please specify opening and closing times for all active days.');
      return false;
    }

    if (closeVal <= openVal && !isOvernight) {
      const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
      showError('For ' + days[d] + ', closing time must be later than opening time unless an approved overnight schedule is checked.');
      return false;
    }
  }

  const reason = document.getElementById('change-reason')?.value.trim();
  if (!reason) {
    showError('Please enter a brief reason for updating the operating schedule.');
    return false;
  }

  return true;
}

function showError(msg) {
  const errBox = document.getElementById('form-error');
  if (errBox) {
    errBox.textContent = msg;
    errBox.style.display = 'block';
    errBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } else {
    alert(msg);
  }
}

function openLogsModal() {
  const m = document.getElementById('modal-logs');
  if (m) { m.style.display = 'flex'; }
}

function closeLogsModal() {
  const m = document.getElementById('modal-logs');
  if (m) { m.style.display = 'none'; }
}
</script>
</body>
</html>
