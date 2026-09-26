<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Ensure tables exist
ensure_procurement_tables($pdo);

// ── POST actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_rfq') {
        require_permission('procurement.rfq.manage');

        $req_id     = (int)($_POST['requisition_id'] ?? 0);
        $due_date   = $_POST['due_date'] ?: null;
        $suppliers  = array_map('intval', $_POST['suppliers'] ?? []);
        $title      = trim($_POST['title'] ?? '');
        $terms      = trim($_POST['terms_and_conditions'] ?? '');
        $letter     = trim($_POST['invitation_letter'] ?? '');
        $buyer_sig  = trim($_POST['buyer_signature'] ?? '');

        // Guard: Requisition must be in 'approved' status before an RFQ can be opened
        $req_stmt = $pdo->prepare('SELECT * FROM purchase_requisitions WHERE id = :id');
        $req_stmt->execute([':id' => $req_id]);
        $req = $req_stmt->fetch();

        if (!$req_id || !$req) {
            $toast = 'Invalid requisition.'; $toast_type = 'error';
        } elseif ($req['status'] !== 'approved') {
            $toast = 'Only approved requisitions can go out for RFQ.'; $toast_type = 'error';
        } elseif (empty($suppliers)) {
            $toast = 'Please invite at least one supplier.'; $toast_type = 'error';
        } elseif (!$due_date) {
            $toast = 'Please set a quote submission deadline (due date).'; $toast_type = 'error';
        } elseif (strtotime($due_date) < strtotime(date('Y-m-d'))) {
            $toast = 'Due date cannot be in the past.'; $toast_type = 'error';
        } else {
            $res = create_rfq_invitation(
                $req_id,
                (int)$user['id'],
                $title ?: ('RFQ for ' . $req['title']),
                $due_date,
                $suppliers,
                $terms,
                $letter,
                $buyer_sig ?: null
            );

            if ($res['ok']) {
                header('Location: rfq.php?id=' . $res['rfq_id'] . '&toast=' . urlencode("RFQ {$res['rfq_ref']} created with buyer e-signature and invitation letter sent to " . count($suppliers) . ' supplier(s).'));
                exit;
            } else {
                $toast = $res['error'] ?? 'Could not create RFQ.';
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'record_bid') {
        require_permission('procurement.rfq.manage');

        $rfq_id = (int)($_POST['rfq_id'] ?? 0);
        $sup_id = (int)($_POST['supplier_id'] ?? 0);
        $total  = (float)($_POST['quoted_total'] ?? 0);
        $lead   = (int)($_POST['lead_time_days'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');

        $rfq_stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id = :id');
        $rfq_stmt->execute([':id' => $rfq_id]);
        $target_rfq = $rfq_stmt->fetch();

        if (!$target_rfq || !$sup_id || $total <= 0) {
            $toast = 'Select a supplier and enter a valid quote.'; $toast_type = 'error';
        } else {
            // Due date enforcement
            $check = can_supplier_bid($target_rfq);
            if (!$check['can_bid']) {
                $toast = $check['reason'];
                $toast_type = 'error';
            } else {
                $pdo->prepare('
                    INSERT INTO bids (rfq_id, supplier_id, quoted_total, lead_time_days, notes, status)
                    VALUES (:r,:s,:t,:l,:n,"submitted")
                    ON DUPLICATE KEY UPDATE quoted_total=:t2, lead_time_days=:l2, notes=:n2, status="submitted"
                ')->execute([
                    ':r'=>$rfq_id, ':s'=>$sup_id, ':t'=>$total, ':l'=>$lead, ':n'=>$notes,
                    ':t2'=>$total, ':l2'=>$lead, ':n2'=>$notes,
                ]);
                audit_log('bid', $rfq_id, 'bid_recorded', "Manual quote recorded for supplier ID #$sup_id: ₱" . number_format($total, 2));
                $toast = 'Quote successfully recorded.';
            }
        }
        header('Location: rfq.php?id=' . $rfq_id . ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : ''));
        exit;
    }

    if ($action === 'withdraw_bid') {
        require_permission('procurement.rfq.manage');

        $bid_id = (int)($_POST['bid_id'] ?? 0);
        $rfq_id = (int)($_POST['rfq_id'] ?? 0);

        $res = withdraw_supplier_bid($bid_id);
        if ($res['ok']) {
            $toast = $res['message'];
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }
        header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }

    if ($action === 'shortlist') {
        require_permission('procurement.bidding.review');
        $bid_id = (int)($_POST['bid_id'] ?? 0);
        $rfq_id = (int)($_POST['rfq_id'] ?? 0);
        $pdo->prepare("UPDATE bids SET status='shortlisted' WHERE id=:id")->execute([':id'=>$bid_id]);
        audit_log('bid', $bid_id, 'shortlisted', "Bid #$bid_id shortlisted by {$user['username']}");
        header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode('Bid shortlisted.'));
        exit;
    }

    if ($action === 'select_quote') {
        require_permission('procurement.po.manage');

        $rfq_id  = (int)($_POST['rfq_id'] ?? 0);
        $bid_id  = (int)($_POST['bid_id'] ?? 0);
        $reason  = trim($_POST['selection_reason'] ?? '');

        if (!$rfq_id || !$bid_id) {
            $toast = 'Invalid selection.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $b_stmt = $pdo->prepare('SELECT b.*, s.name AS supplier_name FROM bids b JOIN suppliers s ON s.id = b.supplier_id WHERE b.id = :id');
                $b_stmt->execute([':id' => $bid_id]);
                $selected_bid = $b_stmt->fetch();

                $rfq_stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id = :id');
                $rfq_stmt->execute([':id' => $rfq_id]);
                $current_rfq = $rfq_stmt->fetch();

                if (!$selected_bid || !$current_rfq) {
                    throw new Exception('Quotation or RFQ not found.');
                }

                $threshold = (float)get_procurement_setting('finance_approval_threshold', 10000.00);
                $needs_finance = ((float)$selected_bid['quoted_total'] >= $threshold);
                $fin_status = $needs_finance ? 'pending' : 'approved';

                // Mark selected bid
                $pdo->prepare("
                    UPDATE bids 
                    SET status = 'selected', selection_reason = :r, finance_status = :fs
                    WHERE id = :id
                ")->execute([':r' => $reason, ':fs' => $fin_status, ':id' => $bid_id]);

                // Mark other bids as not_selected
                $pdo->prepare("
                    UPDATE bids 
                    SET status = 'not_selected' 
                    WHERE rfq_id = :r AND id != :id AND status NOT IN ('withdrawn', 'rejected')
                ")->execute([':r' => $rfq_id, ':id' => $bid_id]);

                // Update RFQ status
                $rfq_new_status = $needs_finance ? 'open' : 'awarded';
                $pdo->prepare('UPDATE rfqs SET status = :st WHERE id = :id')
                    ->execute([':st' => $rfq_new_status, ':id' => $rfq_id]);

                $pdo->commit();

                audit_log('bid', $bid_id, 'quote_selected', "Selected quote from {$selected_bid['supplier_name']} (₱" . number_format($selected_bid['quoted_total'], 2) . ") — Finance Status: $fin_status");

                if ($needs_finance) {
                    notify_role_by_permission(
                        'procurement.finance.review',
                        'finance_review_needed',
                        'Purchase Proposal Requires Finance Review',
                        "Selected quote for RFQ {$current_rfq['rfq_ref']} (₱" . number_format($selected_bid['quoted_total'], 2) . ") exceeds threshold (₱" . number_format($threshold, 2) . ").",
                        'finance_purchase_approvals.php',
                        $user['id']
                    );
                    $toast = 'Quote selected! Amount exceeds financial threshold (₱' . number_format($threshold, 2) . ') — routed to Finance for review.';
                } else {
                    $toast = 'Quote selected and approved! Next step: Generate Purchase Contract.';
                }

                header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode($toast));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage(); $toast_type = 'error';
                header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode($toast) . '&type=error');
                exit;
            }
        }
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Route: requisition_id (new RFQ) vs id (existing RFQ) vs neither (list) ──
$rfq = null;
$requisition = null;
$req_items = [];

if (!empty($_GET['requisition_id'])) {
    $req_id = (int)$_GET['requisition_id'];
    $existing = $pdo->prepare('SELECT id FROM rfqs WHERE requisition_id = :r ORDER BY id DESC LIMIT 1');
    $existing->execute([':r' => $req_id]);
    $existing_id = $existing->fetchColumn();
    if ($existing_id) {
        header('Location: rfq.php?id=' . $existing_id);
        exit;
    }
    $stmt = $pdo->prepare('
        SELECT pr.*, u.firstname, u.lastname
        FROM purchase_requisitions pr
        JOIN users u ON u.id = pr.requested_by
        WHERE pr.id = :id AND pr.status = "approved"
    ');
    $stmt->execute([':id' => $req_id]);
    $requisition = $stmt->fetch();

    if ($requisition) {
        $it_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id ORDER BY id ASC');
        $it_stmt->execute([':id' => $requisition['id']]);
        $req_items = $it_stmt->fetchAll();
    }
} elseif (!empty($_GET['id'])) {
    $rfq_id = (int)$_GET['id'];
    $stmt = $pdo->prepare('
        SELECT rfq.*, pr.title AS req_title, pr.department, pr.estimated_total AS req_estimated, pr.notes AS req_notes,
               bu.firstname AS buyer_fname, bu.lastname AS buyer_lname, bu.username AS buyer_uname
        FROM rfqs rfq
        JOIN purchase_requisitions pr ON pr.id = rfq.requisition_id
        LEFT JOIN users bu ON bu.id = rfq.buyer_signed_by
        WHERE rfq.id = :id
    ');
    $stmt->execute([':id' => $rfq_id]);
    $rfq = $stmt->fetch();

    if ($rfq) {
        $it_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id ORDER BY id ASC');
        $it_stmt->execute([':id' => $rfq['requisition_id']]);
        $req_items = $it_stmt->fetchAll();
    }
}

// Active suppliers with scorecard pre-aggregation
$active_suppliers = $pdo->query("
    SELECT s.id, s.name, s.contact_person, s.email, s.phone,
           (SELECT COUNT(*) FROM supplier_performance_ratings WHERE supplier_id = s.id) AS review_count,
           (SELECT ROUND(AVG((quality_score + timeliness_score + price_score + communication_score)/4), 1) FROM supplier_performance_ratings WHERE supplier_id = s.id) AS avg_rating
    FROM suppliers s
    WHERE s.status = 'active'
    ORDER BY s.name ASC
")->fetchAll();

$bids = [];
$invited = [];
$can_quote = ['can_bid' => true, 'is_expired' => false, 'reason' => ''];

if ($rfq) {
    $can_quote = can_supplier_bid($rfq);

    $b = $pdo->prepare('
        SELECT bids.*, s.name AS supplier_name, s.contact_person,
               (SELECT ROUND(AVG((quality_score + timeliness_score + price_score + communication_score)/4), 1) FROM supplier_performance_ratings WHERE supplier_id = s.id) AS supplier_score
        FROM bids 
        JOIN suppliers s ON s.id = bids.supplier_id
        WHERE bids.rfq_id = :id 
        ORDER BY FIELD(bids.status, "selected", "shortlisted", "submitted", "under_review", "not_selected", "withdrawn", "rejected"), bids.quoted_total ASC
    ');
    $b->execute([':id' => $rfq['id']]);
    $bids = $b->fetchAll();

    $inv = $pdo->prepare('
        SELECT s.*,
               (SELECT ROUND(AVG((quality_score + timeliness_score + price_score + communication_score)/4), 1) FROM supplier_performance_ratings WHERE supplier_id = s.id) AS supplier_score
        FROM rfq_invites ri
        JOIN suppliers s ON s.id = ri.supplier_id 
        WHERE ri.rfq_id = :id
    ');
    $inv->execute([':id' => $rfq['id']]);
    $invited = $inv->fetchAll();
}

// ── List view when nothing is selected ─────────
$rfq_list = [];
if (!$rfq && !$requisition) {
    $rfq_list = $pdo->query("
        SELECT rfqs.*, pr.title AS req_title, pr.department, pr.estimated_total AS req_estimated,
               (SELECT COUNT(*) FROM bids WHERE bids.rfq_id = rfqs.id) AS bid_count,
               (SELECT MIN(quoted_total) FROM bids WHERE bids.rfq_id = rfqs.id AND bids.status != 'withdrawn') AS lowest_quote
        FROM rfqs 
        JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        ORDER BY FIELD(rfqs.status,'open','awarded','closed'), rfqs.created_at DESC
    ")->fetchAll();
}

$finance_threshold = (float)get_procurement_setting('finance_approval_threshold', 10000.00);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RFQ &amp; Bidding — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .bid-row {
      display:flex; align-items:center; gap:12px; padding:12px 14px;
      border:1.5px solid var(--border); border-radius:12px; margin-bottom:8px; background:#fff;
    }
    .bid-row.top { border-color: var(--green); background:var(--green-lt); }
    .bid-row.selected { border-color: var(--green); background:#f0fdf4; border-width: 2px; }
    .bid-row.withdrawn { opacity: 0.65; background:#f9fafb; }
    .bid-rank { width:28px; height:28px; border-radius:50%; background:var(--accent-lt); color:var(--caramel); font-weight:800; font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .bid-info { flex:1; min-width:0; }
    .bid-supplier { font-weight:700; font-size:14px; color:var(--espresso); display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .bid-meta { font-size:12px; color:var(--text-muted); margin-top:2px; }
    .bid-amount { font-weight:800; font-size:16px; color:var(--espresso); text-align:right; min-width:120px; }
    .invite-checks { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:10px; margin-top:8px; }
    .invite-card-opt { display:flex; align-items:center; justify-content:space-between; border:1.5px solid var(--border); border-radius:10px; padding:10px 12px; background:#fff; cursor:pointer; transition:all 0.15s ease; }
    .invite-card-opt:hover { border-color:var(--caramel); }
    .invite-card-opt.selected { border-color:var(--caramel); background:#FAF5EE; }

    /* RFQ Invitation Letter Container */
    .letter-preview-box {
      background:#FAF5EE; border:1.5px solid #E5D5C5; border-radius:12px; padding:20px 24px; margin-bottom:18px;
    }
    .letterhead-header {
      display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1.5px solid #E5D5C5; padding-bottom:14px; margin-bottom:14px;
    }
    .letterhead-title {
      font-family:'Playfair Display', Georgia, serif; font-size:18px; font-weight:800; color:var(--espresso); text-transform:uppercase; letter-spacing:0.8px;
    }
    .sig-pad-wrap {
      background:#fff; border:1.5px solid var(--border); border-radius:8px; overflow:hidden; position:relative;
    }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-rfq" class="page active">
  <div class="page-header">
    <div>
      <h1>RFQ &amp; Bidding</h1>
      <p>Issue formal RFQ invitation letters with buyer e-signature, compare quotations, and select suppliers</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:14px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($requisition): ?>
      <!-- ── Create RFQ & Dispatch Invitation Letter ── -->
      <div class="table-card" style="padding:22px 24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px">
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase;letter-spacing:0.5px">Requisition Approved — Draft RFQ Invitation</div>
            <h2 style="margin:2px 0 4px"><?= htmlspecialchars($requisition['title']) ?></h2>
            <p class="muted-cell" style="margin:0">
              Department: <strong><?= htmlspecialchars($requisition['department']) ?></strong> ·
              Estimated Value: <strong>₱<?= number_format($requisition['estimated_total'], 2) ?></strong> ·
              Requested by <?= htmlspecialchars($requisition['firstname'] . ' ' . $requisition['lastname']) ?>
            </p>
          </div>
          <div>
            <button type="button" class="act-btn" onclick="openItemsModal()"><?= icon('eye', 13) ?> Inspect Items &amp; Specs (<?= count($req_items) ?>)</button>
          </div>
        </div>

        <?php if (empty($active_suppliers)): ?>
          <p class="muted-cell">No active suppliers registered. <a href="suppliers.php" style="color:var(--caramel);font-weight:700">Add an active supplier first →</a></p>
        <?php else: ?>
        <form method="POST" id="rfq-create-form" onsubmit="return validateRfqForm()">
          <input type="hidden" name="action" value="create_rfq"/>
          <input type="hidden" name="requisition_id" value="<?= $requisition['id'] ?>"/>
          <input type="hidden" name="title" value="RFQ - <?= htmlspecialchars($requisition['title']) ?>"/>
          <input type="hidden" name="buyer_signature" id="buyer-sig-data"/>

          <!-- 1. Submission Deadline -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:18px">
            <div class="field-group" style="margin:0">
              <label class="field-label">Quotation Submission Deadline (Due Date) <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="date" name="due_date" id="rfq-due-date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required/>
              <span class="field-hint">Suppliers cannot submit or edit quotes after this deadline.</span>
            </div>
            <div class="field-group" style="margin:0">
              <label class="field-label">Delivery Address / Destination</label>
              <input class="field-input" type="text" id="rfq-destination" value="Kofee Manila Commissary, 108 Maginhawa St, Quezon City"/>
            </div>
          </div>

          <!-- 2. Supplier Selection with Scorecards -->
          <div class="field-group" style="margin-bottom:18px">
            <label class="field-label">Select Suppliers to Invite <span style="color:var(--red)">*</span></label>
            <div class="invite-checks">
              <?php foreach ($active_suppliers as $s): ?>
                <div class="invite-card-opt" id="opt-wrap-<?= $s['id'] ?>">
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1">
                    <input type="checkbox" name="suppliers[]" value="<?= $s['id'] ?>" onchange="toggleSupplierCard(<?= $s['id'] ?>)"/>
                    <div>
                      <div style="font-weight:700;color:var(--espresso);font-size:13px"><?= htmlspecialchars($s['name']) ?></div>
                      <div style="font-size:11.5px;color:var(--text-muted)"><?= htmlspecialchars($s['contact_person'] ?: 'No contact') ?></div>
                    </div>
                  </label>
                  <button type="button" class="act-btn" style="padding:3px 8px;font-size:11px" onclick="openScorecard(<?= $s['id'] ?>, '<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                    <?= icon('star', 11) ?> <?= $s['avg_rating'] ? $s['avg_rating'] . ' ★' : 'Scorecard' ?>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- 3. Terms & Conditions -->
          <div class="field-group" style="margin-bottom:18px">
            <label class="field-label">Terms, Conditions &amp; Delivery Instructions</label>
            <textarea class="field-input" name="terms_and_conditions" id="rfq-terms" rows="3">1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.
2. Quoted lead time and delivery schedule must be strictly observed.
3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.
4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.</textarea>
          </div>

          <!-- 4. Formal RFQ Invitation Letter Preview & Signature -->
          <div class="letter-preview-box">
            <div class="letterhead-header">
              <div>
                <div class="letterhead-title">Kofee Manila Corporation</div>
                <div style="font-size:12px;color:var(--text-muted)">Formal Request for Quotation (RFQ) Invitation Letter</div>
              </div>
              <div style="text-align:right">
                <span class="status-badge status-pending" style="font-size:11px;font-weight:700">Official RFQ Dispatch</span>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Date: <?= date('F d, Y') ?></div>
              </div>
            </div>

            <div style="font-size:13px;line-height:1.6;color:var(--espresso);margin-bottom:14px">
              <p style="margin:0 0 6px"><strong>Subject:</strong> Invitation to Bid — <?= htmlspecialchars($requisition['title']) ?></p>
              <p style="margin:0 0 10px">Dear Invited Supplier,</p>
              <p style="margin:0 0 10px">You are formally invited to submit a competitive quotation for the supply of the following requisition line items to Kofee Manila Corporation:</p>
            </div>

            <!-- Items summary table in letter -->
            <div class="table-scroll-wrapper" style="margin-bottom:14px;background:#fff;border-radius:8px">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Item Description</th>
                    <th>Requested Qty</th>
                    <th>Unit</th>
                    <th>Est. Price</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($req_items as $idx => $it): ?>
                    <tr>
                      <td style="font-weight:700"><?= $idx + 1 ?></td>
                      <td style="font-weight:700"><?= htmlspecialchars($it['item_name']) ?></td>
                      <td><?= (float)$it['quantity'] ?></td>
                      <td><?= htmlspecialchars($it['unit']) ?></td>
                      <td>₱<?= number_format($it['est_unit_price'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <!-- Buyer E-Signature Pad -->
            <div style="border-top:1px dashed #D6C2AF;padding-top:14px;margin-top:10px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <label class="field-label" style="margin:0">Buyer Authorized Representative E-Signature <span style="color:var(--red)">*</span></label>
                <div style="display:flex;gap:4px">
                  <button type="button" class="act-btn" style="padding:2px 8px;font-size:11px" onclick="setSigMode('draw')">Draw</button>
                  <button type="button" class="act-btn" style="padding:2px 8px;font-size:11px" onclick="setSigMode('type')">Type Name</button>
                  <button type="button" class="act-btn" style="padding:2px 8px;font-size:11px;color:var(--red)" onclick="clearSigCanvas()">Clear</button>
                </div>
              </div>

              <div class="sig-pad-wrap" id="sig-draw-container">
                <canvas id="sig-pad" width="500" height="120" style="display:block;width:100%;height:120px;cursor:crosshair;touch-action:none;background:#fff"></canvas>
                <div id="sig-placeholder" style="position:absolute;bottom:8px;left:12px;font-size:11.5px;color:#A99EA9;pointer-events:none">
                  Draw your authorized e-signature above to sign this RFQ invitation letter
                </div>
              </div>

              <div id="sig-type-container" style="display:none;margin-top:6px">
                <input class="field-input" type="text" id="sig-type-input" value="<?= htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '') ?: $user['username']) ?>" placeholder="Type your full name" oninput="renderTypedSig()"/>
              </div>

              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:11.5px;color:var(--text-muted)">
                <span id="sig-status">E-Signature required to dispatch RFQ invitation letter.</span>
                <span>Authorized Signer: <strong><?= htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '') ?: $user['username']) ?></strong></span>
              </div>
            </div>
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" class="btn-cancel" onclick="window.location.href='requisitions.php'">Cancel</button>
            <button type="submit" class="btn-save"><?= icon('send', 14) ?> Dispatch RFQ &amp; Sign Invitation Letter</button>
          </div>
        </form>
        <?php endif; ?>
      </div>

    <?php elseif ($rfq): ?>
      <!-- ── RFQ Detail: Quotes, Comparison & Award ── -->
      <div class="table-card" style="padding:22px 24px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:12px">
          <div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <span style="font-family:monospace;font-size:13px;font-weight:800;background:#EFE0CC;padding:2px 8px;border-radius:6px;color:var(--espresso)">
                <?= htmlspecialchars($rfq['rfq_ref'] ?: ('KM-RFQ-' . $rfq['id'])) ?>
              </span>
              <h2 style="margin:0"><?= htmlspecialchars($rfq['req_title']) ?></h2>
              <span class="status-badge status-<?= $rfq['status']==='awarded'?'approved':'pending' ?>"><?= ucfirst($rfq['status']) ?></span>
            </div>
            <p class="muted-cell" style="margin:6px 0 0">
              Requisition Est: <strong>₱<?= number_format($rfq['req_estimated'], 2) ?></strong> ·
              Department: <strong><?= htmlspecialchars($rfq['department']) ?></strong> ·
              Created <?= date('M d, Y', strtotime($rfq['created_at'])) ?>
            </p>
          </div>

          <div style="text-align:right">
            <!-- Due date badge with deadline enforcement indication -->
            <?php if (!empty($rfq['due_date'])):
              $due_ts = strtotime($rfq['due_date']);
              $today_ts = strtotime(date('Y-m-d'));
              $is_expired = ($today_ts > $due_ts);
              $days_left = ceil(($due_ts - $today_ts) / 86400);
            ?>
              <?php if ($is_expired): ?>
                <span class="status-badge status-rejected" style="font-weight:700;display:inline-flex;align-items:center;gap:4px">
                  <?= icon('alert-circle', 12) ?> Deadline Expired (<?= date('M d, Y', $due_ts) ?>)
                </span>
              <?php else: ?>
                <span class="status-badge status-approved" style="font-weight:700;display:inline-flex;align-items:center;gap:4px">
                  <?= icon('clock', 12) ?> Due: <?= date('M d, Y', $due_ts) ?> (<?= $days_left ?> day<?= $days_left == 1 ? '' : 's' ?> left)
                </span>
              <?php endif; ?>
            <?php else: ?>
              <span class="status-badge status-pending">No deadline set</span>
            <?php endif; ?>

            <div style="margin-top:8px;display:flex;gap:6px;justify-content:flex-end">
              <button class="act-btn" onclick="toggleLetterView()"><?= icon('file-text', 13) ?> View Invitation Letter</button>
              <button class="act-btn" onclick="openItemsModal()"><?= icon('eye', 13) ?> Inspect Items (<?= count($req_items) ?>)</button>
            </div>
          </div>
        </div>

        <!-- Collapsible Official Invitation Letter View -->
        <div id="invitation-letter-box" style="display:none;background:#FAF5EE;border:1.5px solid #E5D5C5;border-radius:10px;padding:16px 20px;margin-bottom:18px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #E5D5C5;padding-bottom:10px;margin-bottom:10px">
            <div>
              <strong style="color:var(--espresso);text-transform:uppercase;letter-spacing:0.5px;font-size:13px">Official RFQ Invitation Letterhead</strong>
              <div style="font-size:12px;color:var(--text-muted)">Dispatched to invited suppliers</div>
            </div>
            <button type="button" class="modal-close" onclick="toggleLetterView()"><?= icon('x', 14) ?></button>
          </div>

          <div style="font-size:13px;line-height:1.6;color:var(--espresso)">
            <p><strong>Invited Suppliers:</strong> <?= implode(', ', array_map(fn($s)=>htmlspecialchars($s['name']), $invited)) ?: 'None' ?></p>
            <?php if (!empty($rfq['terms_and_conditions'])): ?>
              <p><strong>Terms &amp; Instructions:</strong><br/><?= nl2br(htmlspecialchars($rfq['terms_and_conditions'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($rfq['buyer_signature'])): ?>
              <div style="margin-top:14px;display:flex;align-items:flex-end;gap:14px">
                <div style="border-top:1px solid #A99EA9;padding-top:4px">
                  <img src="<?= htmlspecialchars($rfq['buyer_signature']) ?>" alt="Buyer E-Signature" style="max-height:50px;display:block;margin-bottom:4px"/>
                  <span style="font-size:11.5px;color:var(--text-muted)">Signed by Authorized Buyer</span>
                  <?php if (!empty($rfq['buyer_signed_at'])): ?>
                    <div style="font-size:11px;color:var(--text-muted)"><?= date('M d, Y H:i', strtotime($rfq['buyer_signed_at'])) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Due Date Expiration Banner -->
        <?php if ($can_quote['is_expired']): ?>
          <div style="padding:12px 16px;background:var(--red-lt);border:1.5px solid var(--red);border-radius:10px;color:var(--red);font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;margin-bottom:16px">
            <?= icon('alert-circle', 16) ?>
            <span>The quote submission deadline for this RFQ expired on <?= date('M d, Y', strtotime($rfq['due_date'])) ?>. New quotations and edits are now closed.</span>
          </div>
        <?php endif; ?>

        <!-- Record Quote Manually (Only if open and not expired) -->
        <?php if (has_permission('procurement.rfq.manage') && $rfq['status'] === 'open' && $can_quote['can_bid']): ?>
        <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;padding:16px;background:#FBF6EF;border-radius:12px">
          <input type="hidden" name="action" value="record_bid"/>
          <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?>"/>
          <div class="field-group" style="margin:0;min-width:180px">
            <label class="field-label">Invited Supplier</label>
            <select class="field-input" name="supplier_id" required>
              <option value="">Select Invited Supplier…</option>
              <?php foreach ($invited as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?><?= $s['supplier_score'] ? ' (' . $s['supplier_score'] . '★)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field-group" style="margin:0;max-width:150px">
            <label class="field-label">Quoted Total (₱)</label>
            <input class="field-input" type="number" step="0.01" min="0.01" name="quoted_total" required/>
          </div>
          <div class="field-group" style="margin:0;max-width:120px">
            <label class="field-label">Lead Time (days)</label>
            <input class="field-input" type="number" min="0" name="lead_time_days" value="3"/>
          </div>
          <div class="field-group" style="margin:0;flex:1;min-width:160px">
            <label class="field-label">Notes / Offer Details</label>
            <input class="field-input" type="text" name="notes" placeholder="Optional notes"/>
          </div>
          <button type="submit" class="btn-save"><?= icon('plus', 14) ?> Record Offer</button>
        </form>
        <?php endif; ?>

        <!-- Side-by-Side Comparison (if 2 or more bids) -->
        <?php 
          $active_bids = array_filter($bids, fn($b) => $b['status'] !== 'withdrawn');
          if (count($active_bids) >= 2): 
            $best_total = min(array_column($active_bids, 'quoted_total')); 
            $best_lead = min(array_column($active_bids, 'lead_time_days')); 
        ?>
        <h3 style="font-size:14px;margin-bottom:10px;display:flex;align-items:center;gap:6px">
          <?= icon('scale', 16, '', 'color:var(--caramel)') ?> Compare Quotations Side-by-Side
        </h3>
        <div class="table-scroll-wrapper" style="margin-bottom:24px">
          <table>
            <thead>
              <tr>
                <th style="width:140px">Criteria</th>
                <?php foreach ($active_bids as $b): ?>
                  <th>
                    <?= htmlspecialchars($b['supplier_name']) ?>
                    <?php if ($b['supplier_score']): ?>
                      <span style="font-size:11px;font-weight:700;color:var(--caramel)"> (<?= $b['supplier_score'] ?>★)</span>
                    <?php endif; ?>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="muted-cell" style="font-weight:700">Quoted Total</td>
                <?php foreach ($active_bids as $b): ?>
                  <td style="font-weight:800;font-size:15px<?= $b['quoted_total'] == $best_total ? ';color:var(--green)' : '' ?>">
                    ₱<?= number_format($b['quoted_total'], 2) ?>
                    <?php if ($b['quoted_total'] == $best_total): ?>
                      <span class="status-badge status-approved" style="font-size:10.5px;padding:2px 6px">Lowest</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:700">Lead Time</td>
                <?php foreach ($active_bids as $b): ?>
                  <td style="font-weight:700<?= $b['lead_time_days'] == $best_lead ? ';color:var(--green)' : '' ?>">
                    <?= $b['lead_time_days'] ?> day(s)
                    <?php if ($b['lead_time_days'] == $best_lead): ?>
                      <span class="status-badge status-approved" style="font-size:10.5px;padding:2px 6px">Fastest</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:700">Notes</td>
                <?php foreach ($active_bids as $b): ?>
                  <td class="muted-cell"><?= $b['notes'] ? htmlspecialchars($b['notes']) : '—' ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="muted-cell" style="font-weight:700">Status</td>
                <?php foreach ($active_bids as $b): ?>
                  <td>
                    <?php if ($b['status'] === 'selected'): ?>
                      <span class="status-badge status-approved">Selected</span>
                    <?php elseif ($b['status'] === 'shortlisted'): ?>
                      <span class="status-badge status-pending"><?= icon('star', 11) ?> Shortlisted</span>
                    <?php else: ?>
                      <span class="status-badge status-pending"><?= ucfirst($b['status']) ?></span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- List of Offers -->
        <h3 style="font-size:14px;margin-bottom:12px">Received Quotations (<?= count($bids) ?>)</h3>
        <?php if (empty($bids)): ?>
          <p class="muted-cell">No quotations recorded yet. Invited suppliers can submit quotes through their portal.</p>
        <?php else: foreach ($bids as $i => $b): ?>
          <div class="bid-row <?= $b['status'] === 'selected' ? 'selected' : ($i === 0 && $b['status'] !== 'withdrawn' ? 'top' : '') ?> <?= $b['status'] === 'withdrawn' ? 'withdrawn' : '' ?>">
            <div class="bid-rank">#<?= $i + 1 ?></div>
            <div class="bid-info">
              <div class="bid-supplier">
                <span><?= htmlspecialchars($b['supplier_name']) ?></span>
                <button type="button" class="act-btn" style="padding:2px 7px;font-size:11px" onclick="openScorecard(<?= $b['supplier_id'] ?>, '<?= htmlspecialchars($b['supplier_name'], ENT_QUOTES) ?>')">
                  <?= icon('star', 11) ?> Scorecard
                </button>
                <?php if ($b['status'] === 'selected'): ?>
                  <span class="status-badge status-approved" style="font-weight:800">✓ Selected Winner</span>
                  <?php if ($b['finance_status'] === 'pending'): ?>
                    <span class="status-badge status-pending" title="Awaiting Finance Review">Pending Finance Review</span>
                  <?php elseif ($b['finance_status'] === 'approved'): ?>
                    <span class="status-badge status-approved">Finance Approved</span>
                  <?php endif; ?>
                <?php elseif ($b['status'] === 'shortlisted'): ?>
                  <span class="status-badge status-pending"><?= icon('star', 11) ?> Shortlisted</span>
                <?php elseif ($b['status'] === 'withdrawn'): ?>
                  <span class="status-badge status-rejected">Withdrawn</span>
                <?php endif; ?>
              </div>
              <div class="bid-meta">
                <?= $b['lead_time_days'] ?> days lead time
                <?= $b['notes'] ? ' · "' . htmlspecialchars($b['notes']) . '"' : '' ?>
                <?php if ($b['selection_reason']): ?>
                  <div style="color:var(--green);font-weight:600;margin-top:2px">Reason: <?= htmlspecialchars($b['selection_reason']) ?></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="bid-amount">
              ₱<?= number_format($b['quoted_total'], 2) ?>
            </div>

            <div class="act-group" style="margin-left:8px">
              <?php if ($rfq['status'] === 'open' && $b['status'] !== 'withdrawn' && $b['status'] !== 'selected'): ?>
                <?php if (has_permission('procurement.bidding.review') && $b['status'] === 'submitted'): ?>
                  <form method="POST">
                    <input type="hidden" name="action" value="shortlist"/>
                    <input type="hidden" name="bid_id" value="<?= $b['id'] ?>"/>
                    <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?>"/>
                    <button type="submit" class="act-btn"><?= icon('star', 12) ?> Shortlist</button>
                  </form>
                <?php endif; ?>

                <?php if (has_permission('procurement.po.manage')): ?>
                  <button type="button" class="act-btn act-activate" onclick="openSelectQuote(<?= $b['id'] ?>, '<?= htmlspecialchars($b['supplier_name'], ENT_QUOTES) ?>', <?= (float)$b['quoted_total'] ?>)">
                    <?= icon('check', 12) ?> Select Quote
                  </button>
                <?php endif; ?>

                <!-- Withdraw button (before deadline) -->
                <?php if ($can_quote['can_bid']): ?>
                  <form method="POST" onsubmit="return confirm('Are you sure you want to mark this quote as withdrawn?');">
                    <input type="hidden" name="action" value="withdraw_bid"/>
                    <input type="hidden" name="bid_id" value="<?= $b['id'] ?>"/>
                    <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?>"/>
                    <button type="submit" class="act-btn" style="color:var(--red)"><?= icon('x', 12) ?> Withdraw</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($b['status'] === 'selected'): ?>
                <?php if ($b['finance_status'] === 'approved'): ?>
                  <a href="purchase_contracts.php?rfq_id=<?= $rfq['id'] ?>&bid_id=<?= $b['id'] ?>" class="btn-save" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;font-size:12px">
                    <?= icon('file-text', 13) ?> Generate Contract
                  </a>
                <?php elseif ($b['finance_status'] === 'pending'): ?>
                  <a href="finance_purchase_approvals.php" class="act-btn act-activate" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                    <?= icon('scale', 13) ?> View Finance Queue
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

    <?php else: ?>
      <!-- ── List of All RFQs ── -->
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr>
              <th>RFQ Ref</th>
              <th>Requisition</th>
              <th>Department</th>
              <th>Due Date</th>
              <th>Offers</th>
              <th>Status</th>
              <th style="text-align:center;width:95px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rfq_list)): ?>
            <tr class="empty-row">
              <td colspan="7"><?= icon('inbox', 18) ?> No RFQs found. Approve a requisition first, then click "Start RFQ".</td>
            </tr>
          <?php else: foreach ($rfq_list as $r): 
            $due_ts = $r['due_date'] ? strtotime($r['due_date']) : 0;
            $today_ts = strtotime(date('Y-m-d'));
            $is_expired = ($due_ts && $today_ts > $due_ts);
          ?>
            <tr>
              <td style="font-family:monospace;font-weight:700">
                <?= htmlspecialchars($r['rfq_ref'] ?: ('KM-RFQ-' . $r['id'])) ?>
              </td>
              <td style="font-weight:700"><?= htmlspecialchars($r['req_title']) ?></td>
              <td><?= htmlspecialchars($r['department']) ?></td>
              <td>
                <?php if ($r['due_date']): ?>
                  <span style="<?= $is_expired ? 'color:var(--red);font-weight:700' : '' ?>">
                    <?= date('M d, Y', $due_ts) ?>
                    <?php if ($is_expired): ?><span class="status-badge status-rejected" style="font-size:10px;padding:1px 5px;margin-left:4px">Expired</span><?php endif; ?>
                  </span>
                <?php else: ?>
                  <span class="muted-cell">—</span>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= $r['bid_count'] ?></strong>
                <?php if ($r['lowest_quote']): ?>
                  <span class="muted-cell">(from ₱<?= number_format($r['lowest_quote'], 2) ?>)</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-badge status-<?= $r['status'] === 'awarded' ? 'approved' : ($r['status'] === 'closed' ? 'rejected' : 'pending') ?>">
                  <?= ucfirst($r['status']) ?>
                </span>
              </td>
              <td style="text-align:center">
                <button class="act-btn" onclick="window.location.href='rfq.php?id=<?= $r['id'] ?>'"><?= icon('eye', 13) ?> View</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal 1: Inspect Requisition Items & Specs -->
<div class="modal-overlay" id="items-modal">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <h3><?= icon('eye', 16) ?> Requisition Specifications &amp; Line Items</h3>
      <button class="modal-close" onclick="closeItemsModal()"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal-body">
      <div class="table-scroll-wrapper" style="margin-bottom:14px">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Item Name</th>
              <th>Requested Qty</th>
              <th>Est. Unit Price</th>
              <th>Est. Total</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $spec_total = 0;
              foreach ($req_items as $idx => $it): 
                $lt = (float)$it['quantity'] * (float)$it['est_unit_price'];
                $spec_total += $lt;
            ?>
              <tr>
                <td><?= $idx + 1 ?></td>
                <td style="font-weight:700"><?= htmlspecialchars($it['item_name']) ?></td>
                <td><?= (float)$it['quantity'] ?> <?= htmlspecialchars($it['unit']) ?></td>
                <td>₱<?= number_format($it['est_unit_price'], 2) ?></td>
                <td style="font-weight:700">₱<?= number_format($lt, 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="text-align:right;font-weight:800;font-size:15px;color:var(--espresso)">
        Total Requisition Value: ₱<?= number_format($spec_total, 2) ?>
      </div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeItemsModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal 2: Supplier Performance Scorecard Preview -->
<div class="modal-overlay" id="scorecard-modal">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h3><?= icon('star', 16) ?> Supplier Performance Scorecard</h3>
      <button class="modal-close" onclick="closeScorecardModal()"><?= icon('x', 14) ?></button>
    </div>
    <div class="modal-body">
      <h4 id="sc-sup-name" style="margin:0 0 12px;color:var(--espresso);font-size:16px"></h4>
      <div id="sc-content"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="closeScorecardModal()">Close</button>
    </div>
  </div>
</div>

<!-- Modal 3: Select Quote & Route to Finance / Contract -->
<div class="modal-overlay" id="select-quote-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3><?= icon('check', 16) ?> Select Winning Quotation</h3>
      <button class="modal-close" onclick="closeSelectQuote()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="select_quote"/>
      <input type="hidden" name="rfq_id" value="<?= $rfq['id'] ?? '' ?>"/>
      <input type="hidden" name="bid_id" id="sel-bid-id"/>
      <div class="modal-body">
        <p style="margin-bottom:12px">
          You are selecting the quotation from <strong id="sel-sup-name"></strong> in the amount of <strong id="sel-amount" style="color:var(--espresso)"></strong>.
        </p>

        <div id="sel-finance-notice" style="display:none;padding:10px 14px;border-radius:8px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;font-size:12.5px;font-weight:600;margin-bottom:12px">
          <?= icon('alert-circle', 14) ?> This amount exceeds the financial authorization threshold (₱<?= number_format($finance_threshold, 2) ?>). This proposal will be automatically routed to Finance for review before a contract can be sent.
        </div>

        <div class="field-group">
          <label class="field-label">Selection Rationale / Decision Notes <span style="color:var(--red)">*</span></label>
          <textarea class="field-input" name="selection_reason" rows="3" placeholder="Explain why this quotation was selected (e.g. best price-to-quality ratio, acceptable lead time, strong supplier track record)" required></textarea>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeSelectQuote()">Cancel</button>
        <button type="submit" class="btn-save"><?= icon('check', 14) ?> Confirm Quote Selection</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modals ──
function openItemsModal() { document.getElementById('items-modal').classList.add('open'); }
function closeItemsModal() { document.getElementById('items-modal').classList.remove('open'); }

function toggleLetterView() {
  const box = document.getElementById('invitation-letter-box');
  if (box) box.style.display = (box.style.display === 'none') ? 'block' : 'none';
}

function openSelectQuote(bidId, supName, amount) {
  document.getElementById('sel-bid-id').value = bidId;
  document.getElementById('sel-sup-name').textContent = supName;
  document.getElementById('sel-amount').textContent = '₱' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  const finThreshold = <?= json_encode($finance_threshold) ?>;
  const notice = document.getElementById('sel-finance-notice');
  if (notice) {
    notice.style.display = (parseFloat(amount) >= parseFloat(finThreshold)) ? 'block' : 'none';
  }

  document.getElementById('select-quote-modal').classList.add('open');
}
function closeSelectQuote() { document.getElementById('select-quote-modal').classList.remove('open'); }

// Supplier scorecard popover / modal
const SCORECARDS = <?= json_encode(array_combine(
  array_column($active_suppliers, 'id'),
  array_map(fn($s) => get_supplier_scorecard_summary((int)$s['id']), $active_suppliers)
)) ?>;

function openScorecard(supId, supName) {
  document.getElementById('sc-sup-name').textContent = supName;
  const data = SCORECARDS[supId];
  const wrap = document.getElementById('sc-content');

  if (!data || !data.has_history) {
    wrap.innerHTML = `
      <div style="text-align:center;padding:24px 10px;color:var(--text-muted)">
        <div style="font-size:32px;margin-bottom:8px">★</div>
        <p style="font-size:13.5px;font-weight:600;margin:0 0 4px">No Performance History Yet</p>
        <p style="font-size:12px;margin:0">This supplier has no completed orders or ratings recorded in the system.</p>
      </div>
    `;
  } else {
    wrap.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px;margin-bottom:14px">
        <div style="background:#FAF5EE;padding:12px;border-radius:8px;border:1px solid var(--border);text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--espresso)">${data.overall_score} ★</div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:600">Overall Rating (${data.total_reviews} reviews)</div>
        </div>
        <div style="background:#FAF5EE;padding:12px;border-radius:8px;border:1px solid var(--border);text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--green)">${data.otif_pct}%</div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:600">On-Time / In-Full (OTIF)</div>
        </div>
        <div style="background:#FAF5EE;padding:12px;border-radius:8px;border:1px solid var(--border);text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--espresso)">${data.quality_pct}%</div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:600">Quality Compliance</div>
        </div>
        <div style="background:#FAF5EE;padding:12px;border-radius:8px;border:1px solid var(--border);text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--espresso)">${data.responsiveness_pct}%</div>
          <div style="font-size:11px;color:var(--text-muted);font-weight:600">Communication &amp; Support</div>
        </div>
      </div>
    `;
  }
  document.getElementById('scorecard-modal').classList.add('open');
}
function closeScorecardModal() { document.getElementById('scorecard-modal').classList.remove('open'); }

function toggleSupplierCard(supId) {
  const card = document.getElementById('opt-wrap-' + supId);
  const chk = card ? card.querySelector('input[type="checkbox"]') : null;
  if (card && chk) {
    if (chk.checked) card.classList.add('selected');
    else card.classList.remove('selected');
  }
}

// ── HTML5 Canvas E-Signature Pad ──
let sigCanvas, sigCtx, isDrawing = false, hasSig = false, sigMode = 'draw';

function initSigPad() {
  sigCanvas = document.getElementById('sig-pad');
  if (!sigCanvas) return;
  sigCtx = sigCanvas.getContext('2d');
  sigCtx.strokeStyle = '#241A2E';
  sigCtx.lineWidth = 2.5;
  sigCtx.lineCap = 'round';
  sigCtx.lineJoin = 'round';

  function getCoords(e) {
    const rect = sigCanvas.getBoundingClientRect();
    const scaleX = sigCanvas.width / rect.width;
    const scaleY = sigCanvas.height / rect.height;
    if (e.touches && e.touches[0]) {
      return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
    }
    return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
  }

  function startDraw(e) {
    isDrawing = true;
    const { x, y } = getCoords(e);
    sigCtx.beginPath();
    sigCtx.moveTo(x, y);
    const pl = document.getElementById('sig-placeholder');
    if (pl) pl.style.display = 'none';
  }
  function draw(e) {
    if (!isDrawing) return;
    e.preventDefault();
    const { x, y } = getCoords(e);
    sigCtx.lineTo(x, y);
    sigCtx.stroke();
    hasSig = true;
    updateSigStatus();
  }
  function stopDraw() { isDrawing = false; }

  sigCanvas.addEventListener('mousedown', startDraw);
  sigCanvas.addEventListener('mousemove', draw);
  window.addEventListener('mouseup', stopDraw);
  sigCanvas.addEventListener('touchstart', startDraw, { passive: false });
  sigCanvas.addEventListener('touchmove', draw, { passive: false });
  window.addEventListener('touchend', stopDraw);
}

function clearSigCanvas() {
  if (!sigCtx || !sigCanvas) return;
  sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
  hasSig = false;
  const pl = document.getElementById('sig-placeholder');
  if (pl) pl.style.display = 'block';
  updateSigStatus();
}

function setSigMode(mode) {
  sigMode = mode;
  const drawWrap = document.getElementById('sig-draw-container');
  const typeWrap = document.getElementById('sig-type-container');
  if (mode === 'draw') {
    if (drawWrap) drawWrap.style.display = 'block';
    if (typeWrap) typeWrap.style.display = 'none';
  } else {
    if (drawWrap) drawWrap.style.display = 'none';
    if (typeWrap) typeWrap.style.display = 'block';
    renderTypedSig();
  }
}

function renderTypedSig() {
  const input = document.getElementById('sig-type-input');
  const text = (input ? input.value : '').trim();
  if (!sigCanvas || !sigCtx) return;
  sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
  if (!text) { hasSig = false; updateSigStatus(); return; }

  sigCtx.font = "italic 32px 'Brush Script MT', 'Dancing Script', 'Playfair Display', cursive";
  sigCtx.fillStyle = '#241A2E';
  sigCtx.fillText(text, 30, 70);

  sigCtx.beginPath();
  sigCtx.lineWidth = 1.8;
  sigCtx.strokeStyle = '#C97B3D';
  sigCtx.moveTo(25, 82);
  sigCtx.quadraticCurveTo(sigCanvas.width / 2, 94, sigCanvas.width - 35, 80);
  sigCtx.stroke();

  hasSig = true;
  updateSigStatus();
}

function updateSigStatus() {
  const el = document.getElementById('sig-status');
  if (el) {
    if (hasSig) {
      el.innerHTML = '<span style="color:var(--green);font-weight:700">✓ E-Signature Captured</span>';
    } else {
      el.textContent = 'E-Signature required to dispatch RFQ invitation letter.';
      el.style.color = 'var(--text-muted)';
    }
  }
}

function validateRfqForm() {
  const due = document.getElementById('rfq-due-date');
  if (!due || !due.value) {
    alert('Please specify a quotation due date.');
    if (due) due.focus();
    return false;
  }

  const chks = document.querySelectorAll('input[name="suppliers[]"]:checked');
  if (!chks.length) {
    alert('Please invite at least one supplier to quote.');
    return false;
  }

  if (!hasSig || !sigCanvas) {
    alert('Buyer authorized e-signature is required. Please sign or type your name.');
    return false;
  }

  document.getElementById('buyer-sig-data').value = sigCanvas.toDataURL('image/png');
  return true;
}

document.addEventListener('DOMContentLoaded', () => {
  initSigPad();
});

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => {
    if (e.target === el) {
      closeItemsModal();
      closeScorecardModal();
      closeSelectQuote();
    }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeItemsModal();
    closeScorecardModal();
    closeSelectQuote();
  }
});
</script>
</body>
</html>
