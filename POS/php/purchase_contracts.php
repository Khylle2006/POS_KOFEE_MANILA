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

// ── POST Actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_contract') {
        require_permission('procurement.po.manage');

        $req_id     = (int)($_POST['requisition_id'] ?? 0);
        $rfq_id     = !empty($_POST['rfq_id']) ? (int)$_POST['rfq_id'] : null;
        $bid_id     = !empty($_POST['bid_id']) ? (int)$_POST['bid_id'] : null;
        $sup_id     = (int)($_POST['supplier_id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $deliv_term = trim($_POST['delivery_terms'] ?? '');
        $pay_term   = trim($_POST['payment_terms'] ?? '');
        $terms      = trim($_POST['contract_terms'] ?? '');
        $sig_name   = trim($_POST['buyer_signed_name'] ?? '');
        $sig_title  = trim($_POST['buyer_signed_title'] ?? '');
        $buyer_sig  = trim($_POST['buyer_signature'] ?? '');

        // Validation & Finance Threshold Gate
        if ($bid_id) {
            $bid_stmt = $pdo->prepare('SELECT * FROM bids WHERE id = :id');
            $bid_stmt->execute([':id' => $bid_id]);
            $bid = $bid_stmt->fetch();
            $threshold = (float)get_procurement_setting('finance_approval_threshold', 10000.00);

            if ($bid && (float)$bid['quoted_total'] > $threshold && $bid['finance_status'] !== 'approved') {
                $toast = 'Cannot issue contract: This purchase exceeds ₱' . number_format($threshold, 2) . ' and requires Finance Approval first.';
                $toast_type = 'error';
                header('Location: rfq.php?id=' . $rfq_id . '&toast=' . urlencode($toast) . '&type=error');
                exit;
            }
        }

        if (!$req_id || !$sup_id || !$title || !$buyer_sig) {
            $toast = 'Please complete all required fields and provide the buyer electronic signature.';
            $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Compute total amount and gather line items
                $total_amt = 0.0;
                $items = [];

                if ($bid_id) {
                    $bi_stmt = $pdo->prepare('SELECT * FROM bid_items WHERE bid_id = :id');
                    $bi_stmt->execute([':id' => $bid_id]);
                    $items = $bi_stmt->fetchAll();
                    foreach ($items as $it) {
                        $total_amt += (float)$it['line_total'];
                    }
                }

                if (empty($items)) {
                    // Fallback to requisition items
                    $ri_stmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = :id');
                    $ri_stmt->execute([':id' => $req_id]);
                    $req_items = $ri_stmt->fetchAll();
                    foreach ($req_items as $ri) {
                        $qty = (float)$ri['quantity'];
                        $price = (float)$ri['est_unit_price'];
                        $lt = round($qty * $price, 2);
                        $total_amt += $lt;
                        $items[] = [
                            'requisition_item_id' => $ri['id'],
                            'item_name' => $ri['item_name'],
                            'quantity' => $qty,
                            'unit' => $ri['unit'],
                            'unit_price' => $price,
                            'line_total' => $lt,
                        ];
                    }
                }

                // Reference: KM-CNT-YYYY-XXXX
                $contract_ref = sprintf('KM-CNT-%s-%04d', date('Y'), $req_id);
                $suffix = 1;
                while (true) {
                    $chk = $pdo->prepare('SELECT id FROM purchase_contracts WHERE contract_ref = :ref');
                    $chk->execute([':ref' => $contract_ref]);
                    if (!$chk->fetch()) break;
                    $suffix++;
                    $contract_ref = sprintf('KM-CNT-%s-%04d-%d', date('Y'), $req_id, $suffix);
                }

                $stmt = $pdo->prepare('
                    INSERT INTO purchase_contracts 
                        (contract_ref, requisition_id, rfq_id, bid_id, supplier_id, title, total_amount,
                         delivery_terms, payment_terms, contract_terms, buyer_signed_by, buyer_signed_name,
                         buyer_signed_title, buyer_signature, buyer_signed_at, status)
                    VALUES 
                        (:ref, :req, :rfq, :bid, :sup, :t, :amt, :dt, :pt, :ct, :bby, :bname, :btitle, :bsig, NOW(), "sent_to_supplier")
                ');
                $stmt->execute([
                    ':ref'    => $contract_ref,
                    ':req'    => $req_id,
                    ':rfq'    => $rfq_id,
                    ':bid'    => $bid_id,
                    ':sup'    => $sup_id,
                    ':t'      => $title,
                    ':amt'    => $total_amt,
                    ':dt'     => $deliv_term,
                    ':pt'     => $pay_term,
                    ':ct'     => $terms,
                    ':bby'    => $user['id'],
                    ':bname'  => $sig_name ?: ($user['firstname'] . ' ' . $user['lastname']),
                    ':btitle' => $sig_title ?: 'Authorized Purchasing Officer',
                    ':bsig'   => $buyer_sig,
                ]);
                $contract_id = (int)$pdo->lastInsertId();

                // Insert contract line items
                $ins_item = $pdo->prepare('
                    INSERT INTO purchase_contract_items 
                        (contract_id, requisition_item_id, item_name, quantity, unit, unit_price, line_total)
                    VALUES 
                        (:cid, :ri, :n, :q, :u, :p, :lt)
                ');
                foreach ($items as $it) {
                    $ins_item->execute([
                        ':cid' => $contract_id,
                        ':ri'  => $it['requisition_item_id'] ?? null,
                        ':n'   => $it['item_name'],
                        ':q'   => $it['quantity'] ?? $it['quoted_qty'],
                        ':u'   => $it['unit'] ?? 'pcs',
                        ':p'   => $it['unit_price'],
                        ':lt'  => $it['line_total'],
                    ]);
                }

                // Notify supplier
                $sup_stmt = $pdo->prepare('SELECT user_id, name FROM suppliers WHERE id = :id');
                $sup_stmt->execute([':id' => $sup_id]);
                $sup = $sup_stmt->fetch();

                if ($sup && !empty($sup['user_id'])) {
                    notify_user(
                        (int)$sup['user_id'],
                        'contract_ready',
                        'New Purchase Contract Awaiting Your Signature',
                        "Kofee Manila has sent Purchase Contract {$contract_ref} for {$title} (" . php_currency($total_amt) . "). Please review terms and countersign.",
                        'supplier_portal.php?tab=contracts&contract_id=' . $contract_id
                    );
                }

                audit_log('contract', $contract_id, 'created', "Purchase contract {$contract_ref} issued to {$sup['name']}");

                $pdo->commit();
                header('Location: purchase_contracts.php?id=' . $contract_id . '&toast=' . urlencode("Contract {$contract_ref} issued and sent to supplier for countersignature!"));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage();
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'issue_po') {
        require_permission('procurement.po.manage');
        $contract_id = (int)($_POST['contract_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $c_stmt = $pdo->prepare('
                SELECT c.*, s.name AS supplier_name, s.user_id AS supplier_user_id 
                FROM purchase_contracts c
                JOIN suppliers s ON s.id = c.supplier_id
                WHERE c.id = :id
            ');
            $c_stmt->execute([':id' => $contract_id]);
            $contract = $c_stmt->fetch();

            if (!$contract) throw new Exception('Purchase contract not found.');

            // Strictly gate PO creation on fully_signed status
            if ($contract['status'] !== 'fully_signed') {
                throw new Exception('A Purchase Order can only be created once the contract is fully signed by both Buyer and Supplier.');
            }

            // Generate PO number: KM-PO-YYYY-XXXX
            $po_number = sprintf('KM-PO-%s-%04d', date('Y'), $contract['requisition_id']);
            $suffix = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM purchase_orders WHERE po_number = :po');
                $chk->execute([':po' => $po_number]);
                if (!$chk->fetch()) break;
                $suffix++;
                $po_number = sprintf('KM-PO-%s-%04d-%d', date('Y'), $contract['requisition_id'], $suffix);
            }

            $pdo->prepare('
                INSERT INTO purchase_orders 
                    (po_number, contract_id, rfq_id, requisition_id, supplier_id, total_amount, status, created_by, expected_delivery_date)
                VALUES 
                    (:po, :cid, :rfq, :req, :sup, :amt, "sent", :u, DATE_ADD(CURDATE(), INTERVAL 7 DAY))
            ')->execute([
                ':po'  => $po_number,
                ':cid' => $contract_id,
                ':rfq' => $contract['rfq_id'],
                ':req' => $contract['requisition_id'],
                ':sup' => $contract['supplier_id'],
                ':amt' => $contract['total_amount'],
                ':u'   => $user['id'],
            ]);
            $po_id = (int)$pdo->lastInsertId();

            // Mark contract as po_created
            $pdo->prepare('UPDATE purchase_contracts SET status = "po_created" WHERE id = :id')->execute([':id' => $contract_id]);

            // Notify supplier that PO has been dispatched
            if (!empty($contract['supplier_user_id'])) {
                notify_user(
                    (int)$contract['supplier_user_id'],
                    'po_issued',
                    'Purchase Order Issued — ' . $po_number,
                    "Kofee Manila has issued PO {$po_number} under Contract {$contract['contract_ref']}. Please review and acknowledge in your portal.",
                    'supplier_portal.php?tab=pos&po_id=' . $po_id
                );
            }

            audit_log('purchase_order', $po_id, 'issued', "Issued PO {$po_number} under Contract {$contract['contract_ref']}");

            $pdo->commit();
            header('Location: purchase_orders.php?id=' . $po_id . '&toast=' . urlencode("Purchase Order {$po_number} created and dispatched to supplier (Awaiting Supplier Acknowledgment)!"));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $toast = $e->getMessage();
            $toast_type = 'error';
        }
    }
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$contract = null;
$contract_items = [];
$view_id = (int)($_GET['id'] ?? 0);
$create_mode = !empty($_GET['create']) || (!empty($_GET['bid_id']) && empty($view_id));

if ($view_id) {
    $stmt = $pdo->prepare('
        SELECT c.*, s.name AS supplier_name, s.contact_person, s.email, s.phone, s.address AS supplier_address,
               pr.title AS req_title, pr.department,
               rfq.rfq_ref,
               u_buyer.firstname AS buyer_fname, u_buyer.lastname AS buyer_lname
        FROM purchase_contracts c
        JOIN suppliers s ON s.id = c.supplier_id
        JOIN purchase_requisitions pr ON pr.id = c.requisition_id
        LEFT JOIN rfqs rfq ON rfq.id = c.rfq_id
        LEFT JOIN users u_buyer ON u_buyer.id = c.buyer_signed_by
        WHERE c.id = :id
    ');
    $stmt->execute([':id' => $view_id]);
    $contract = $stmt->fetch();

    if ($contract) {
        $it_stmt = $pdo->prepare('SELECT * FROM purchase_contract_items WHERE contract_id = :id ORDER BY id ASC');
        $it_stmt->execute([':id' => $view_id]);
        $contract_items = $it_stmt->fetchAll();
    }
}

// Data for Creation Mode
$prep_rfq = null;
$prep_bid = null;
$prep_req = null;
$prep_items = [];

if ($create_mode) {
    $rfq_id = (int)($_GET['rfq_id'] ?? 0);
    $bid_id = (int)($_GET['bid_id'] ?? 0);

    if (!$bid_id && $rfq_id) {
        $bid_id = (int)$pdo->query("SELECT id FROM bids WHERE rfq_id = $rfq_id AND status = 'selected' LIMIT 1")->fetchColumn();
    }

    if ($bid_id) {
        $b_stmt = $pdo->prepare('
            SELECT b.*, s.name AS supplier_name, s.contact_person, s.email, s.phone, s.id AS supplier_id,
                   rfq.rfq_ref, rfq.requisition_id, rfq.terms_and_conditions,
                   pr.title AS req_title, pr.department, pr.estimated_total
            FROM bids b
            JOIN suppliers s ON s.id = b.supplier_id
            JOIN rfqs rfq ON rfq.id = b.rfq_id
            JOIN purchase_requisitions pr ON pr.id = rfq.requisition_id
            WHERE b.id = :id
        ');
        $b_stmt->execute([':id' => $bid_id]);
        $prep_bid = $b_stmt->fetch();

        if ($prep_bid) {
            $bi_stmt = $pdo->prepare('SELECT * FROM bid_items WHERE bid_id = :id ORDER BY id ASC');
            $bi_stmt->execute([':id' => $bid_id]);
            $prep_items = $bi_stmt->fetchAll();
        }
    }
}

// All contracts for list view
$contracts_list = [];
if (!$contract && !$create_mode) {
    $contracts_list = $pdo->query('
        SELECT c.*, s.name AS supplier_name, pr.title AS req_title,
               (SELECT po_number FROM purchase_orders WHERE contract_id = c.id LIMIT 1) AS linked_po_number,
               (SELECT id FROM purchase_orders WHERE contract_id = c.id LIMIT 1) AS linked_po_id
        FROM purchase_contracts c
        JOIN suppliers s ON s.id = c.supplier_id
        JOIN purchase_requisitions pr ON pr.id = c.requisition_id
        ORDER BY c.created_at DESC
    ')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Purchase Contracts — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .contract-doc { background:#fff; border:1.5px solid var(--border); border-radius:12px; padding:32px 36px; max-width:860px; margin:0 auto; box-shadow:0 4px 18px rgba(0,0,0,0.04); }
    .contract-header { border-bottom:2px solid var(--caramel); padding-bottom:16px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; }
    .sig-box { border:1.5px dashed var(--border); border-radius:10px; padding:16px; background:#FAF5EE; min-height:140px; display:flex; flex-direction:column; justify-content:space-between; }
    @media print {
      body * { visibility:hidden; }
      #print-area, #print-area * { visibility:visible; }
      #print-area { position:absolute; left:0; top:0; width:100%; border:none; box-shadow:none; padding:0; }
      .no-print { display:none !important; }
    }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-contracts" class="page active">
  <div class="page-header no-print">
    <div>
      <h1>Purchase Contracts</h1>
      <p>Formal legal agreements governing supplier fulfillment, dual e-signatures, and Purchase Order issuance</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?> no-print" style="position:static;display:inline-flex;margin-bottom:14px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($create_mode && $prep_bid): ?>
      <!-- ── CONTRACT CREATION VIEW ── -->
      <div class="table-card" style="padding:22px 26px;max-width:860px;margin:0 auto">
        <h2 style="margin-bottom:4px">Generate Purchase Contract</h2>
        <p class="muted-cell" style="margin-bottom:18px">
          Governing RFQ: <strong><?= htmlspecialchars($prep_bid['rfq_ref']) ?></strong> · Requisition: <strong><?= htmlspecialchars($prep_bid['req_title']) ?></strong>
        </p>

        <form method="POST" id="form-create-contract">
          <input type="hidden" name="action" value="create_contract"/>
          <input type="hidden" name="requisition_id" value="<?= $prep_bid['requisition_id'] ?>"/>
          <input type="hidden" name="rfq_id" value="<?= $prep_bid['rfq_id'] ?>"/>
          <input type="hidden" name="bid_id" value="<?= $prep_bid['id'] ?>"/>
          <input type="hidden" name="supplier_id" value="<?= $prep_bid['supplier_id'] ?>"/>
          <input type="hidden" name="buyer_signature" id="cnt-buyer-sig" value=""/>

          <div class="field-group" style="margin-bottom:14px">
            <label class="field-label">Contract Official Title <span style="color:var(--red)">*</span></label>
            <input class="field-input" type="text" name="title" value="Supply Agreement: <?= htmlspecialchars($prep_bid['req_title']) ?> — <?= htmlspecialchars($prep_bid['supplier_name']) ?>" required/>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
            <div class="field-group" style="margin:0">
              <label class="field-label">Delivery Terms &amp; Timeline <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="text" name="delivery_terms" value="Delivery within <?= (int)$prep_bid['lead_time_days'] ?> day(s) upon receipt of Purchase Order to Kofee Manila Commissary" required/>
            </div>
            <div class="field-group" style="margin:0">
              <label class="field-label">Payment Terms <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="text" name="payment_terms" value="Net 30 Days upon complete goods receipt and three-way invoice matching" required/>
            </div>
          </div>

          <!-- Agreed Items Table -->
          <div style="margin-bottom:16px">
            <label class="field-label">Agreed Contract Items &amp; Pricing</label>
            <div class="table-scroll-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>Item Description</th>
                    <th>Agreed Qty</th>
                    <th>Agreed Unit Price</th>
                    <th>Total Value</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $calc_tot = 0;
                  if (!empty($prep_items)): 
                    foreach ($prep_items as $pi): 
                      $calc_tot += (float)$pi['line_total'];
                  ?>
                    <tr>
                      <td style="font-weight:700"><?= htmlspecialchars($pi['item_name']) ?></td>
                      <td><?= (float)$pi['quoted_qty'] ?> <?= htmlspecialchars($pi['unit']) ?></td>
                      <td>₱<?= number_format((float)$pi['unit_price'], 2) ?></td>
                      <td style="font-weight:700">₱<?= number_format((float)$pi['line_total'], 2) ?></td>
                    </tr>
                  <?php endforeach; else: $calc_tot = (float)$prep_bid['quoted_total']; ?>
                    <tr>
                      <td style="font-weight:700"><?= htmlspecialchars($prep_bid['req_title']) ?></td>
                      <td>1 lot</td>
                      <td>₱<?= number_format($calc_tot, 2) ?></td>
                      <td style="font-weight:700">₱<?= number_format($calc_tot, 2) ?></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
                <tfoot>
                  <tr style="background:#FAF5EE;font-weight:800">
                    <td colspan="3" style="text-align:right">Total Contract Obligation:</td>
                    <td style="font-size:15px;color:var(--espresso)">₱<?= number_format($calc_tot, 2) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <div class="field-group" style="margin-bottom:16px">
            <label class="field-label">Standard Legal &amp; Commercial Terms</label>
            <textarea class="field-input" name="contract_terms" rows="4">1. Quality Standards: All items delivered must strictly comply with agreed food safety and freshness standards. Defective or damaged items will be rejected upon delivery.
2. Advance Shipping Notice: The supplier must provide an ASN with tracking details prior to delivery arrival.
3. Three-Way Matching: Invoices will be matched against the Purchase Order and actual received Goods Receipt before payment scheduling.
4. Binding Agreement: This agreement becomes legally binding once countersigned by both Buyer and Supplier authorized representatives.
5. Contract Price and Taxes. The Total Contract Obligation is ₱10,000.00, VAT-inclusive. This amount includes all applicable taxes, duties, charges, delivery costs, and other costs necessary to complete delivery of the agreed items, unless otherwise stated in this Contract. The Supplier shall issue a valid invoice reflecting the applicable tax treatment.</textarea>
          </div>

          <!-- Buyer E-Signature Pad -->
          <div style="background:#FAF5EE;border:1.5px solid var(--border);border-radius:10px;padding:16px;margin-bottom:20px">
            <div style="font-size:12px;font-weight:800;color:var(--espresso);text-transform:uppercase;margin-bottom:8px">
              Buyer Authorized E-Signature <span style="color:var(--red)">*</span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px">
              <div class="field-group" style="margin:0">
                <label class="field-label">Signer Full Name</label>
                <input class="field-input" type="text" name="buyer_signed_name" value="<?= htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '') ?: $user['username']) ?>" required/>
              </div>
              <div class="field-group" style="margin:0">
                <label class="field-label">Signer Designation</label>
                <input class="field-input" type="text" name="buyer_signed_title" value="<?= ($user['role'] ?? '') === 'admin' ? 'Procurement Director' : 'Purchasing Officer' ?>" required/>
              </div>
            </div>

            <div class="field-group" style="margin:0">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                <label class="field-label" style="margin:0">Draw Signature on Canvas</label>
                <button type="button" class="act-btn" style="color:var(--red);padding:2px 8px;font-size:11px" onclick="clearCntSig()">Clear</button>
              </div>
              <div style="background:#fff;border:1.5px solid var(--border);border-radius:8px;position:relative">
                <canvas id="cnt-sig-pad" width="500" height="110" style="display:block;width:100%;height:110px;cursor:crosshair;touch-action:none;background:#fff"></canvas>
                <div id="cnt-sig-ph" style="position:absolute;bottom:6px;left:10px;font-size:11.5px;color:#A99EA9;pointer-events:none">
                  Draw buyer electronic signature above
                </div>
              </div>
              <div id="cnt-sig-status" style="font-size:11.5px;color:var(--text-muted);margin-top:4px">
                Signature required to issue contract to supplier.
              </div>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:8px">
            <button type="button" class="btn-cancel" onclick="window.location.href='rfq.php?id=<?= $prep_bid['rfq_id'] ?>'">Cancel</button>
            <button type="submit" class="btn-save" id="btn-create-cnt"><?= icon('check', 14) ?> Authorize &amp; Dispatch Contract to Supplier</button>
          </div>
        </form>
      </div>

    <?php elseif ($contract): ?>
      <!-- ── SINGLE CONTRACT VIEW / PRINTABLE DOCUMENT ── -->
      <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center" class="no-print">
        <a href="purchase_contracts.php" class="act-btn" style="text-decoration:none">
          <?= icon('arrow-left', 13) ?> Back to Contracts List
        </a>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn-cancel" onclick="window.print()"><?= icon('printer', 14) ?> Print Contract</button>
          <?php if ($contract['status'] === 'fully_signed'): ?>
            <form method="POST">
              <input type="hidden" name="action" value="issue_po"/>
              <input type="hidden" name="contract_id" value="<?= $contract['id'] ?>"/>
              <button type="submit" class="btn-save" style="background:var(--green)">
                <?= icon('send', 14) ?> Issue Purchase Order
              </button>
            </form>
          <?php elseif ($contract['status'] === 'sent_to_supplier'): ?>
            <span class="status-badge status-pending" style="padding:8px 12px;font-size:12.5px">
              <?= icon('clock', 12) ?> Awaiting Supplier Countersignature
            </span>
          <?php elseif ($contract['status'] === 'po_created'): ?>
            <span class="status-badge status-approved" style="padding:8px 12px;font-size:12.5px">
              <?= icon('check', 12) ?> Purchase Order Issued
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="contract-doc" id="print-area">
        <div class="contract-header">
          <div>
            <div style="font-weight:900;font-size:22px;color:var(--espresso);letter-spacing:0.5px">KOFEE MANILA</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Official Procurement Contract &amp; Supply Agreement</div>
            <div style="font-size:13px;font-weight:700;color:var(--caramel);margin-top:6px"><?= htmlspecialchars($contract['contract_ref']) ?></div>
          </div>
          <div style="text-align:right">
            <span class="status-badge status-<?= $contract['status'] === 'fully_signed' || $contract['status'] === 'po_created' ? 'approved' : 'pending' ?>">
              <?= strtoupper(str_replace('_', ' ', $contract['status'])) ?>
            </span>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:6px">Date: <?= date('F d, Y', strtotime($contract['created_at'])) ?></div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-muted);text-transform:uppercase">BUYER</div>
            <div style="font-weight:700;font-size:14px;color:var(--espresso)">Kofee Manila Commissary</div>
            <div style="font-size:12px;color:var(--text-muted)">Department: <?= htmlspecialchars(ucfirst($contract['department'])) ?></div>
            <div style="font-size:12px;color:var(--text-muted)">Procurement Officer: <?= htmlspecialchars($contract['buyer_signed_name']) ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-muted);text-transform:uppercase">SUPPLIER</div>
            <div style="font-weight:700;font-size:14px;color:var(--espresso)"><?= htmlspecialchars($contract['supplier_name']) ?></div>
            <div style="font-size:12px;color:var(--text-muted)">Contact: <?= htmlspecialchars($contract['contact_person'] ?: 'Sales Dept') ?></div>
            <div style="font-size:12px;color:var(--text-muted)">Email: <?= htmlspecialchars($contract['email']) ?> · Phone: <?= htmlspecialchars($contract['phone']) ?></div>
          </div>
        </div>

        <div style="margin-bottom:22px">
          <div style="font-weight:700;font-size:13px;color:var(--espresso);margin-bottom:6px">AGREED SCHEDULE OF ITEMS &amp; VALUE</div>
          <div class="table-scroll-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Item Name</th>
                  <th>Quantity</th>
                  <th>Unit Price</th>
                  <th>Total Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($contract_items as $ci): ?>
                  <tr>
                    <td style="font-weight:700"><?= htmlspecialchars($ci['item_name']) ?></td>
                    <td><?= (float)$ci['quantity'] ?> <?= htmlspecialchars($ci['unit']) ?></td>
                    <td>₱<?= number_format((float)$ci['unit_price'], 2) ?></td>
                    <td style="font-weight:700">₱<?= number_format((float)$ci['line_total'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#FAF5EE;font-weight:800">
                  <td colspan="3" style="text-align:right">Total Contract Value:</td>
                  <td style="font-size:15px;color:var(--espresso)">₱<?= number_format((float)$contract['total_amount'], 2) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
          <div style="background:#FBF6EF;padding:12px;border-radius:8px">
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">DELIVERY TERMS</div>
            <div style="font-size:12.5px;color:var(--espresso);margin-top:4px"><?= htmlspecialchars($contract['delivery_terms']) ?></div>
          </div>
          <div style="background:#FBF6EF;padding:12px;border-radius:8px">
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">PAYMENT TERMS</div>
            <div style="font-size:12.5px;color:var(--espresso);margin-top:4px"><?= htmlspecialchars($contract['payment_terms']) ?></div>
          </div>
        </div>

        <div style="margin-bottom:28px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">CONTRACT CONDITIONS &amp; TERMS</div>
          <div style="font-size:12px;color:var(--text-muted);line-height:1.6;white-space:pre-line;background:#fcfcfc;border:1px solid var(--border);border-radius:8px;padding:12px">
            <?= htmlspecialchars($contract['contract_terms']) ?>
          </div>
        </div>

        <!-- Dual E-Signatures -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
          <!-- Buyer Signature -->
          <div class="sig-box">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--caramel);text-transform:uppercase">BUYER SIGNATURE (KOFEE MANILA)</div>
              <div style="margin-top:8px">
                <?php if (!empty($contract['buyer_signature'])): ?>
                  <img src="<?= $contract['buyer_signature'] ?>" alt="Buyer Signature" style="max-height:60px;display:block"/>
                <?php else: ?>
                  <div style="font-size:12px;color:var(--text-muted)">Unsigned</div>
                <?php endif; ?>
              </div>
            </div>
            <div style="border-top:1px solid var(--border);padding-top:6px;font-size:11.5px;color:var(--text-muted)">
              <div><strong><?= htmlspecialchars($contract['buyer_signed_name']) ?></strong></div>
              <div><?= htmlspecialchars($contract['buyer_signed_title']) ?></div>
              <div>Signed: <?= date('M d, Y h:i A', strtotime($contract['buyer_signed_at'])) ?></div>
            </div>
          </div>

          <!-- Supplier Signature -->
          <div class="sig-box">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--caramel);text-transform:uppercase">SUPPLIER COUNTERSIGNATURE</div>
              <div style="margin-top:8px">
                <?php if (!empty($contract['supplier_signature'])): ?>
                  <img src="<?= $contract['supplier_signature'] ?>" alt="Supplier Signature" style="max-height:60px;display:block"/>
                <?php else: ?>
                  <div style="font-size:12px;color:var(--red);font-style:italic">Pending countersignature in Supplier Portal</div>
                <?php endif; ?>
              </div>
            </div>
            <div style="border-top:1px solid var(--border);padding-top:6px;font-size:11.5px;color:var(--text-muted)">
              <div><strong><?= htmlspecialchars($contract['supplier_signed_name'] ?: 'Awaiting Signer') ?></strong></div>
              <div>Authorized Representative</div>
              <div><?= !empty($contract['supplier_signed_at']) ? ('Signed: ' . date('M d, Y h:i A', strtotime($contract['supplier_signed_at']))) : 'Pending' ?></div>
            </div>
          </div>
        </div>
      </div>

    <?php else: ?>
      <!-- ── ALL CONTRACTS REGISTRY VIEW ── -->
      <div class="table-scroll-wrapper">
        <table>
          <thead>
            <tr>
              <th>Contract Ref</th>
              <th>Contract Title</th>
              <th>Supplier</th>
              <th>Total Amount</th>
              <th>Buyer Signed</th>
              <th>Supplier Signed</th>
              <th>Status</th>
              <th style="text-align:center;width:110px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($contracts_list)): ?>
              <tr class="empty-row"><td colspan="8"><?= icon('inbox', 18) ?> No purchase contracts found. Complete an RFQ selection to generate a contract.</td></tr>
            <?php else: foreach ($contracts_list as $c): ?>
              <tr>
                <td style="font-weight:700"><?= htmlspecialchars($c['contract_ref']) ?></td>
                <td>
                  <div style="font-weight:700"><?= htmlspecialchars($c['title']) ?></div>
                  <div class="muted-cell" style="font-size:11px">Req: <?= htmlspecialchars($c['req_title']) ?></div>
                </td>
                <td><?= htmlspecialchars($c['supplier_name']) ?></td>
                <td style="font-weight:800">₱<?= number_format((float)$c['total_amount'], 2) ?></td>
                <td class="muted-cell"><?= date('M d, Y', strtotime($c['buyer_signed_at'])) ?></td>
                <td>
                  <?php if (!empty($c['supplier_signed_at'])): ?>
                    <span style="color:var(--green);font-weight:700;font-size:12px"><?= date('M d, Y', strtotime($c['supplier_signed_at'])) ?></span>
                  <?php else: ?>
                    <span style="color:var(--red);font-size:12px;font-style:italic">Pending</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($c['status'] === 'fully_signed'): ?>
                    <span class="status-badge status-approved">Fully Signed</span>
                  <?php elseif ($c['status'] === 'po_created'): ?>
                    <span class="status-badge status-approved" style="background:#e8f4fd;color:#0b5394">PO Issued</span>
                  <?php else: ?>
                    <span class="status-badge status-pending">Sent to Supplier</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <a href="purchase_contracts.php?id=<?= $c['id'] ?>" class="act-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                    <?= icon('eye', 13) ?> View
                  </a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
let cntCanvas = null, cntCtx = null, cntDrawing = false, cntHasSig = false;

function initCntSig() {
  cntCanvas = document.getElementById('cnt-sig-pad');
  if (!cntCanvas) return;
  cntCtx = cntCanvas.getContext('2d');
  cntCtx.lineWidth = 2.5;
  cntCtx.lineCap = 'round';
  cntCtx.lineJoin = 'round';
  cntCtx.strokeStyle = '#241A2E';

  function getPos(e) {
    const rect = cntCanvas.getBoundingClientRect();
    const scaleX = cntCanvas.width / rect.width;
    const scaleY = cntCanvas.height / rect.height;
    if (e.touches && e.touches.length > 0) {
      return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
    }
    return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
  }

  function startDraw(e) {
    cntDrawing = true;
    cntHasSig = true;
    const ph = document.getElementById('cnt-sig-ph');
    if (ph) ph.style.display = 'none';
    const pos = getPos(e);
    cntCtx.beginPath();
    cntCtx.moveTo(pos.x, pos.y);
    if (e.type.startsWith('touch')) e.preventDefault();
  }

  function drawMove(e) {
    if (!cntDrawing) return;
    const pos = getPos(e);
    cntCtx.lineTo(pos.x, pos.y);
    cntCtx.stroke();
    if (e.type.startsWith('touch')) e.preventDefault();
  }

  function stopDraw() {
    if (!cntDrawing) return;
    cntDrawing = false;
    cntCtx.closePath();
    const st = document.getElementById('cnt-sig-status');
    st.innerHTML = '<span style="color:var(--green);font-weight:700">✓ Signature Captured</span>';
    document.getElementById('cnt-buyer-sig').value = cntCanvas.toDataURL('image/png');
  }

  cntCanvas.onmousedown = startDraw;
  cntCanvas.onmousemove = drawMove;
  cntCanvas.onmouseup   = stopDraw;
  cntCanvas.onmouseleave= stopDraw;
  cntCanvas.ontouchstart = startDraw;
  cntCanvas.ontouchmove  = drawMove;
  cntCanvas.ontouchend   = stopDraw;
}

function clearCntSig() {
  if (!cntCanvas || !cntCtx) return;
  cntCtx.clearRect(0, 0, cntCanvas.width, cntCanvas.height);
  cntHasSig = false;
  const ph = document.getElementById('cnt-sig-ph');
  if (ph) ph.style.display = 'block';
  document.getElementById('cnt-buyer-sig').value = '';
  document.getElementById('cnt-sig-status').textContent = 'Signature required to issue contract to supplier.';
}

document.addEventListener('DOMContentLoaded', () => {
  initCntSig();
});

document.getElementById('form-create-contract')?.addEventListener('submit', (e) => {
  if (!cntHasSig) {
    e.preventDefault();
    alert('Please draw your authorized electronic signature before issuing the contract.');
    return;
  }
  document.getElementById('cnt-buyer-sig').value = cntCanvas.toDataURL('image/png');
});
</script>
</body>
</html>
