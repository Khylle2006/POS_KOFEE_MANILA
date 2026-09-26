<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();

$user = current_user();

// Accessible to finance officers, admins, or staff with finance review permission
if (!has_permission('procurement.finance.review') && !has_permission('finance.view') && ($user['role'] ?? '') !== 'admin') {
    require_permission('procurement.finance.review');
}

$pdo   = get_db();
$toast = '';
$toast_type = 'success';

// Ensure tables & settings exist
ensure_procurement_tables($pdo);

// ── POST Actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'finance_decision') {
        $bid_id   = (int)($_POST['bid_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $notes    = trim($_POST['review_notes'] ?? '');

        if (!$bid_id || !in_array($decision, ['approved', 'rejected'], true)) {
            $toast = 'Invalid review submission.';
            $toast_type = 'error';
        } elseif ($decision === 'rejected' && !$notes) {
            $toast = 'Please provide a justification for rejecting this purchase proposal.';
            $toast_type = 'error';
        } else {
            try {
                $bid_stmt = $pdo->prepare('
                    SELECT b.*, s.name AS supplier_name, rfq.rfq_ref, rfq.requisition_id, rfq.created_by AS buyer_id,
                           pr.title AS req_title
                    FROM bids b
                    JOIN suppliers s ON s.id = b.supplier_id
                    JOIN rfqs rfq ON rfq.id = b.rfq_id
                    JOIN purchase_requisitions pr ON pr.id = rfq.requisition_id
                    WHERE b.id = :id
                ');
                $bid_stmt->execute([':id' => $bid_id]);
                $bid = $bid_stmt->fetch();

                if (!$bid) throw new Exception('Bid record not found.');

                $pdo->prepare('
                    UPDATE bids 
                    SET finance_status = :st,
                        finance_reviewed_by = :u,
                        finance_reviewed_at = NOW(),
                        finance_review_notes = :n
                    WHERE id = :id
                ')->execute([
                    ':st' => $decision,
                    ':u'  => $user['id'],
                    ':n'  => $notes,
                    ':id' => $bid_id,
                ]);

                audit_log(
                    'finance_approval',
                    $bid_id,
                    $decision,
                    "Finance {$decision} quote for {$bid['supplier_name']} (" . php_currency((float)$bid['quoted_total']) . "). Notes: {$notes}"
                );

                if ($decision === 'approved') {
                    if (!empty($bid['buyer_id'])) {
                        notify_user(
                            (int)$bid['buyer_id'],
                            'finance_approved',
                            'Finance Approved Purchase Quote',
                            "Quotation from {$bid['supplier_name']} (" . php_currency((float)$bid['quoted_total']) . ") for {$bid['req_title']} has been approved by Finance. You can now issue the Purchase Contract.",
                            'rfq.php?id=' . $bid['rfq_id']
                        );
                    }
                    notify_role_by_permission(
                        'procurement.rfq.manage',
                        'finance_approved',
                        'Finance Approved Purchase Quote',
                        "Quotation from {$bid['supplier_name']} (" . php_currency((float)$bid['quoted_total']) . ") for {$bid['req_title']} has been authorized by Finance.",
                        'rfq.php?id=' . $bid['rfq_id']
                    );
                    $toast = 'Purchase proposal approved! Buyer has been notified to proceed with the Purchase Contract.';
                } else {
                    if (!empty($bid['buyer_id'])) {
                        notify_user(
                            (int)$bid['buyer_id'],
                            'finance_rejected',
                            'Finance Rejected Purchase Quote',
                            "Quotation from {$bid['supplier_name']} (" . php_currency((float)$bid['quoted_total']) . ") was rejected by Finance: \"{$notes}\". Please re-evaluate quotations.",
                            'rfq.php?id=' . $bid['rfq_id']
                        );
                    }
                    notify_role_by_permission(
                        'procurement.rfq.manage',
                        'finance_rejected',
                        'Finance Rejected Purchase Quote',
                        "Quotation from {$bid['supplier_name']} (" . php_currency((float)$bid['quoted_total']) . ") was rejected by Finance: \"{$notes}\".",
                        'rfq.php?id=' . $bid['rfq_id']
                    );
                    $toast = 'Purchase proposal rejected and returned to Procurement with rejection notes.';
                }

                header('Location: finance_purchase_approvals.php?toast=' . urlencode($toast));
                exit;
            } catch (Exception $e) {
                $toast = $e->getMessage();
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'save_settings') {
        $threshold = (float)($_POST['threshold'] ?? 10000.00);
        $price_tol = (float)($_POST['price_tol'] ?? 3.0);
        $qty_tol   = (float)($_POST['qty_tol'] ?? 0.0);

        set_procurement_setting('finance_approval_threshold', $threshold, 'Purchases exceeding this amount require explicit Finance approval before contract issuance');
        set_procurement_setting('three_way_match_price_tolerance_pct', $price_tol, 'Maximum acceptable percentage variance between PO total and Invoice total');
        set_procurement_setting('three_way_match_qty_tolerance_units', $qty_tol, 'Maximum acceptable unit variance between received quantity and invoiced quantity');

        audit_log('procurement_settings', 1, 'updated', "Threshold set to ₱{$threshold}, price tolerance {$price_tol}%, qty tolerance {$qty_tol}");
        $toast = 'Procurement & Finance policy settings updated successfully.';
        header('Location: finance_purchase_approvals.php?toast=' . urlencode($toast));
        exit;
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$filter = $_GET['filter'] ?? 'pending';

// Query proposals
$sql = "
    SELECT b.*, s.name AS supplier_name, s.contact_person, s.email, s.phone,
           rfq.rfq_ref, rfq.title AS rfq_title, rfq.due_date, rfq.requisition_id,
           pr.title AS req_title, pr.department, pr.estimated_total AS req_estimated,
           u_buyer.firstname AS buyer_fname, u_buyer.lastname AS buyer_lname,
           u_fin.firstname AS fin_fname, u_fin.lastname AS fin_lname
    FROM bids b
    JOIN suppliers s ON s.id = b.supplier_id
    JOIN rfqs rfq ON rfq.id = b.rfq_id
    JOIN purchase_requisitions pr ON pr.id = rfq.requisition_id
    LEFT JOIN users u_buyer ON u_buyer.id = rfq.created_by
    LEFT JOIN users u_fin ON u_fin.id = b.finance_reviewed_by
    WHERE b.status = 'selected'
";

if ($filter === 'pending') {
    $sql .= " AND b.finance_status = 'pending'";
} elseif ($filter === 'approved') {
    $sql .= " AND b.finance_status IN ('approved', 'not_required')";
} elseif ($filter === 'rejected') {
    $sql .= " AND b.finance_status = 'rejected'";
}

$sql .= " ORDER BY b.submitted_at DESC";

$proposals = $pdo->query($sql)->fetchAll();

// Attach line items to each proposal
foreach ($proposals as &$prop) {
    $b_items = $pdo->prepare("SELECT item_name, quoted_qty AS quantity, unit, unit_price, line_total FROM bid_items WHERE bid_id = :b");
    $b_items->execute([':b' => $prop['id']]);
    $items = $b_items->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) {
        $r_items = $pdo->prepare("SELECT item_name, quantity, unit, est_unit_price AS unit_price, (quantity * est_unit_price) AS line_total FROM requisition_items WHERE requisition_id = :r");
        $r_items->execute([':r' => $prop['requisition_id']]);
        $items = $r_items->fetchAll(PDO::FETCH_ASSOC);
    }
    $prop['items'] = $items;
}
unset($prop);

// Counts for filter badges
$counts = [
    'pending'  => (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'selected' AND finance_status = 'pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'selected' AND finance_status IN ('approved', 'not_required')")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'selected' AND finance_status = 'rejected'")->fetchColumn(),
    'all'      => (int)$pdo->query("SELECT COUNT(*) FROM bids WHERE status = 'selected'")->fetchColumn(),
];

// Current Settings
$current_threshold = (float)get_procurement_setting('finance_approval_threshold', 10000.00);
$current_price_tol = (float)get_procurement_setting('three_way_match_price_tolerance_pct', 3.0);
$current_qty_tol   = (float)get_procurement_setting('three_way_match_qty_tolerance_units', 0.0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Finance Purchase Approvals — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .drawer-overlay { position:fixed; inset:0; background:rgba(36,26,46,0.45); z-index:900; opacity:0; pointer-events:none; transition:opacity 0.2s ease; }
    .drawer-overlay.open { opacity:1; pointer-events:auto; }
    .drawer { position:fixed; top:0; right:0; bottom:0; width:440px; max-width:90vw; background:#fff; z-index:901; box-shadow:-4px 0 24px rgba(0,0,0,0.15); transform:translateX(100%); transition:transform 0.25s cubic-bezier(0.4,0,0.2,1); display:flex; flex-direction:column; }
    .drawer.open { transform:translateX(0); }
    .drawer-header { padding:18px 20px; border-bottom:1.5px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    .drawer-body { padding:20px; overflow-y:auto; flex:1; }
    .drawer-footer { padding:14px 20px; border-top:1.5px solid var(--border); display:flex; justify-content:flex-end; gap:8px; background:#fafafa; }
    .item-schedule-table { width:100%; border-collapse:collapse; margin-top:8px; font-size:12px; }
    .item-schedule-table th { background:#f5efe6; color:var(--espresso); padding:6px 10px; font-weight:700; text-align:left; border-bottom:1.5px solid var(--border); }
    .item-schedule-table td { padding:7px 10px; border-bottom:1px solid #f0ebe4; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-finance-approvals" class="page active">
  <div class="page-header">
    <div>
      <h1>Finance Purchase Approvals</h1>
      <p>Review high-value quotations exceeding the finance threshold before purchase contracts are issued</p>
    </div>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn-cancel" onclick="openSettingsDrawer()" style="display:inline-flex;align-items:center;gap:6px">
        <?= icon('sliders', 14) ?> Policy Thresholds &amp; Tolerances
      </button>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:12px"><?= $toast ?></div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="stat-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:16px">
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:#fff3cd;color:#856404"><?= icon('clock', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $counts['pending'] ?></div>
          <div class="mini-stat-lbl">Pending Finance Review</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:#d4edda;color:#155724"><?= icon('check', 18) ?></div>
        <div>
          <div class="mini-stat-val">₱<?= number_format($current_threshold, 2) ?></div>
          <div class="mini-stat-lbl">Active Approval Threshold</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:#e2e3e5;color:#383d41"><?= icon('award', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $counts['approved'] ?></div>
          <div class="mini-stat-lbl">Authorized Purchases</div>
        </div>
      </div>
      <div class="mini-stat">
        <div class="mini-stat-icon" style="background:#f8d7da;color:#721c24"><?= icon('x', 18) ?></div>
        <div>
          <div class="mini-stat-val"><?= $counts['rejected'] ?></div>
          <div class="mini-stat-lbl">Rejected Proposals</div>
        </div>
      </div>
    </div>

    <!-- Filter Pills -->
    <div class="filter-bar" style="padding:0;margin-bottom:14px">
      <a href="finance_purchase_approvals.php?filter=pending" class="filter-pill <?= $filter === 'pending' ? 'active' : '' ?>">
        Pending Review (<?= $counts['pending'] ?>)
      </a>
      <a href="finance_purchase_approvals.php?filter=approved" class="filter-pill <?= $filter === 'approved' ? 'active' : '' ?>">
        Approved (<?= $counts['approved'] ?>)
      </a>
      <a href="finance_purchase_approvals.php?filter=rejected" class="filter-pill <?= $filter === 'rejected' ? 'active' : '' ?>">
        Rejected (<?= $counts['rejected'] ?>)
      </a>
      <a href="finance_purchase_approvals.php?filter=all" class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
        All Proposals (<?= $counts['all'] ?>)
      </a>
    </div>

    <!-- Proposals Table -->
    <div class="table-scroll-wrapper">
      <table>
        <thead>
          <tr>
            <th>RFQ Reference</th>
            <th>Requisition / Project</th>
            <th>Selected Supplier</th>
            <th>Total Cost</th>
            <th>Threshold Variance</th>
            <th>Review Status</th>
            <th style="text-align:center;width:120px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($proposals)): ?>
            <tr class="empty-row">
              <td colspan="7"><?= icon('inbox', 18) ?> No purchase proposals found matching this filter.</td>
            </tr>
          <?php else: foreach ($proposals as $p): 
            $tot = (float)$p['quoted_total'];
            $est = (float)$p['req_estimated'];
            $diff = $tot - $est;
            $diff_pct = $est > 0 ? ($diff / $est) * 100 : 0;
          ?>
            <tr>
              <td>
                <div style="font-weight:700"><?= htmlspecialchars($p['rfq_ref'] ?: ('RFQ #' . $p['rfq_id'])) ?></div>
                <div class="muted-cell" style="font-size:11px">Submitted <?= date('M d, Y', strtotime($p['submitted_at'])) ?></div>
              </td>
              <td>
                <div style="font-weight:700"><?= htmlspecialchars($p['req_title']) ?></div>
                <div class="muted-cell" style="font-size:11.5px">Dept: <?= htmlspecialchars(ucfirst($p['department'])) ?> · Buyer: <?= htmlspecialchars($p['buyer_fname'] . ' ' . $p['buyer_lname']) ?></div>
              </td>
              <td>
                <div style="font-weight:700"><?= htmlspecialchars($p['supplier_name']) ?></div>
                <div class="muted-cell" style="font-size:11px">Lead: <?= (int)$p['lead_time_days'] ?> day(s)</div>
              </td>
              <td>
                <div style="font-weight:800;font-size:14px;color:var(--espresso)">₱<?= number_format($tot, 2) ?></div>
                <div class="muted-cell" style="font-size:11px">Est: ₱<?= number_format($est, 2) ?></div>
              </td>
              <td>
                <?php if ($tot > $current_threshold): ?>
                  <span style="color:var(--red);font-weight:700;font-size:12px">+₱<?= number_format($tot - $current_threshold, 2) ?> over limit</span>
                <?php else: ?>
                  <span style="color:var(--green);font-weight:700;font-size:12px">Within threshold</span>
                <?php endif; ?>
                <div class="muted-cell" style="font-size:10.5px"><?= ($diff >= 0 ? '+' : '') . number_format($diff_pct, 1) ?>% vs requisition</div>
              </td>
              <td>
                <?php if ($p['finance_status'] === 'pending'): ?>
                  <span class="status-badge status-pending" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba">
                    <?= icon('clock', 11) ?> Pending Review
                  </span>
                <?php elseif ($p['finance_status'] === 'approved' || $p['finance_status'] === 'not_required'): ?>
                  <span class="status-badge status-approved">
                    <?= icon('check', 11) ?> Approved
                  </span>
                  <?php if (!empty($p['fin_fname'])): ?>
                    <div class="muted-cell" style="font-size:10px">By <?= htmlspecialchars($p['fin_fname'] . ' ' . $p['fin_lname']) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="status-badge status-rejected">
                    <?= icon('x', 11) ?> Rejected
                  </span>
                <?php endif; ?>
              </td>
              <td style="text-align:center">
                <button type="button" class="act-btn" onclick='openReviewModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
                  <?= icon('eye', 12) ?> <?= $p['finance_status'] === 'pending' ? 'Review' : 'Details' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- Review & Decision Modal -->
<div class="modal-overlay" id="modal-review">
  <div class="modal" style="max-width:620px">
    <div class="modal-header">
      <h3><?= icon('shield-check', 16) ?> Finance Review &amp; Decision</h3>
      <button class="modal-close" onclick="closeReviewModal()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="form-review">
      <input type="hidden" name="action" value="finance_decision"/>
      <input type="hidden" name="bid_id" id="rev-bid-id" value=""/>
      <input type="hidden" name="decision" id="rev-decision" value=""/>

      <div class="modal-body">
        <div style="background:#FAF5EE;border:1px solid #E5D5C5;border-radius:10px;padding:14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">PROPOSED PURCHASE</div>
              <div id="rev-title" style="font-weight:800;font-size:15px;color:var(--espresso);margin-top:2px"></div>
              <div id="rev-supplier" style="font-size:12.5px;color:var(--text-muted);margin-top:2px"></div>
            </div>
            <div style="text-align:right">
              <div id="rev-amount" style="font-size:18px;font-weight:800;color:var(--espresso)"></div>
              <div id="rev-var" style="font-size:11.5px;font-weight:700"></div>
            </div>
          </div>
        </div>

        <!-- Line Item Breakdown -->
        <div class="field-group" style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <label class="field-label" style="margin-bottom:0">Requested Line Items &amp; Pricing</label>
            <a id="rev-rfq-link" href="#" target="_blank" style="font-size:11.5px;color:var(--caramel);font-weight:600;text-decoration:underline">Open Full RFQ &rarr;</a>
          </div>
          <div id="rev-items-container" style="max-height:160px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-top:4px">
            <table class="item-schedule-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Qty / Unit</th>
                  <th>Unit Price</th>
                  <th style="text-align:right">Total</th>
                </tr>
              </thead>
              <tbody id="rev-items-tbody"></tbody>
            </table>
          </div>
        </div>

        <div class="field-group" style="margin-bottom:12px">
          <label class="field-label">Procurement Selection Rationale</label>
          <div id="rev-buyer-notes" style="background:#fcfcfc;border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:12.5px;color:var(--espresso);min-height:36px"></div>
        </div>

        <div class="field-group">
          <label class="field-label">Finance Review Notes <span id="rev-notes-req" style="color:var(--red);display:none">* Required for rejection</span></label>
          <textarea class="field-input" name="review_notes" id="rev-notes" rows="3" placeholder="Provide financial comments or reason for decision"></textarea>
        </div>

        <div id="rev-decision-history" style="display:none;background:#f8f9fa;border-radius:8px;padding:10px 12px;font-size:12px;color:var(--text-muted);margin-top:12px"></div>
      </div>

      <div class="modal-actions" id="rev-actions">
        <button type="button" class="btn-cancel" onclick="closeReviewModal()">Cancel</button>
        <button type="button" class="btn-save" style="background:var(--red)" onclick="submitDecision('rejected')"><?= icon('x', 13) ?> Reject Proposal</button>
        <button type="button" class="btn-save" style="background:var(--green)" onclick="submitDecision('approved')"><?= icon('check', 13) ?> Approve Purchase</button>
      </div>
    </form>
  </div>
</div>

<!-- Settings Drawer -->
<div class="drawer-overlay" id="drawer-overlay" onclick="closeSettingsDrawer()"></div>
<div class="drawer" id="settings-drawer">
  <div class="drawer-header">
    <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:6px">
      <?= icon('sliders', 16) ?> Policy Thresholds &amp; Tolerances
    </h3>
    <button class="modal-close" onclick="closeSettingsDrawer()"><?= icon('x', 14) ?></button>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="save_settings"/>
    <div class="drawer-body">
      <p style="font-size:12.5px;color:var(--text-muted);margin-bottom:16px">
        Configure system-wide limits for purchase authorization and automated three-way invoice matching tolerances.
      </p>

      <div class="field-group" style="margin-bottom:16px">
        <label class="field-label">Finance Approval Threshold (₱)</label>
        <input class="field-input" type="number" step="100" min="0" name="threshold" value="<?= htmlspecialchars((string)$current_threshold) ?>" required/>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
          Quotations selected by procurement exceeding this amount route into this Finance Review Queue prior to contract creation.
        </div>
      </div>

      <div class="field-group" style="margin-bottom:16px">
        <label class="field-label">Three-Way Match Price Tolerance (%)</label>
        <input class="field-input" type="number" step="0.1" min="0" max="100" name="price_tol" value="<?= htmlspecialchars((string)$current_price_tol) ?>" required/>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
          Maximum permissible price percentage discrepancy between Purchase Order total and Supplier Invoice total before putting invoice on hold.
        </div>
      </div>

      <div class="field-group" style="margin-bottom:16px">
        <label class="field-label">Three-Way Match Quantity Tolerance (units)</label>
        <input class="field-input" type="number" step="0.1" min="0" name="qty_tol" value="<?= htmlspecialchars((string)$current_qty_tol) ?>" required/>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
          Maximum allowable unit difference between received goods (GRN) and invoiced quantity (default 0 units for strict inventory accuracy).
        </div>
      </div>
    </div>
    <div class="drawer-footer">
      <button type="button" class="btn-cancel" onclick="closeSettingsDrawer()">Cancel</button>
      <button type="submit" class="btn-save"><?= icon('check', 14) ?> Save Policy Settings</button>
    </div>
  </form>
</div>

<script>
function openReviewModal(p) {
  document.getElementById('rev-bid-id').value = p.id;
  document.getElementById('rev-title').textContent = p.req_title + ' (' + (p.rfq_ref || ('RFQ #' + p.rfq_id)) + ')';
  document.getElementById('rev-supplier').textContent = 'Supplier: ' + p.supplier_name + ' · Lead Time: ' + p.lead_time_days + ' days';
  document.getElementById('rev-amount').textContent = '₱' + parseFloat(p.quoted_total).toFixed(2);
  document.getElementById('rev-buyer-notes').textContent = p.selection_reason || 'No selection notes entered by buyer.';
  document.getElementById('rev-notes').value = p.finance_review_notes || '';
  document.getElementById('rev-rfq-link').href = 'rfq.php?id=' + p.rfq_id;

  const est = parseFloat(p.req_estimated);
  const tot = parseFloat(p.quoted_total);
  const varEl = document.getElementById('rev-var');
  if (tot > est) {
    varEl.textContent = '+₱' + (tot - est).toFixed(2) + ' over estimate';
    varEl.style.color = 'var(--red)';
  } else {
    varEl.textContent = '₱' + (est - tot).toFixed(2) + ' under estimate';
    varEl.style.color = 'var(--green)';
  }

  // Populate line items
  const tbody = document.getElementById('rev-items-tbody');
  tbody.innerHTML = '';
  if (p.items && p.items.length > 0) {
    p.items.forEach(it => {
      const tr = document.createElement('tr');
      const unitP = parseFloat(it.unit_price || 0);
      const lTot = parseFloat(it.line_total || (parseFloat(it.quantity) * unitP));
      tr.innerHTML = `
        <td style="font-weight:600">${escapeHtml(it.item_name)}</td>
        <td>${parseFloat(it.quantity)} ${escapeHtml(it.unit || '')}</td>
        <td>₱${unitP.toFixed(2)}</td>
        <td style="text-align:right;font-weight:700">₱${lTot.toFixed(2)}</td>
      `;
      tbody.appendChild(tr);
    });
  } else {
    tbody.innerHTML = '<tr><td colspan="4" class="muted-cell" style="text-align:center">No line items recorded.</td></tr>';
  }

  const actionsEl = document.getElementById('rev-actions');
  const histEl = document.getElementById('rev-decision-history');

  if (p.finance_status === 'pending') {
    actionsEl.style.display = 'flex';
    histEl.style.display = 'none';
    document.getElementById('rev-notes-req').style.display = 'none';
  } else {
    actionsEl.innerHTML = `<button type="button" class="btn-cancel" onclick="closeReviewModal()">Close</button>`;
    histEl.style.display = 'block';
    histEl.innerHTML = `<strong>Decision recorded:</strong> ${p.finance_status.toUpperCase()} by ${p.fin_fname || 'Finance'} on ${p.finance_reviewed_at || '—'}`;
  }

  document.getElementById('modal-review').classList.add('open');
}
function closeReviewModal() { document.getElementById('modal-review').classList.remove('open'); }

function submitDecision(decision) {
  const notes = document.getElementById('rev-notes').value.trim();
  if (decision === 'rejected' && !notes) {
    alert('Please enter a rejection reason in Finance Review Notes.');
    document.getElementById('rev-notes').focus();
    return;
  }
  document.getElementById('rev-decision').value = decision;
  document.getElementById('form-review').submit();
}

function openSettingsDrawer() {
  document.getElementById('drawer-overlay').classList.add('open');
  document.getElementById('settings-drawer').classList.add('open');
}
function closeSettingsDrawer() {
  document.getElementById('drawer-overlay').classList.remove('open');
  document.getElementById('settings-drawer').classList.remove('open');
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeReviewModal(); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeReviewModal(); closeSettingsDrawer(); }
});
</script>
</body>
</html>