<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();

$pdo  = get_db();
$user = current_user();

$letter_id = (int)($_GET['id'] ?? 0);
$req_id    = (int)($_GET['requisition_id'] ?? 0);

if (!$letter_id && !$req_id) {
    header('Location: requisitions.php');
    exit;
}

$letter = $letter_id
    ? get_procurement_letter($letter_id, false)
    : get_procurement_letter($req_id, true);

if (!$letter) {
    die('Procurement Letter not found.');
}

// Check authorization: Must have procurement view permission OR be the linked supplier
$is_staff = has_permission('procurement.view') || has_permission('procurement.requisitions') || ($user['role'] === 'admin');

$sup_stmt = $pdo->prepare('SELECT * FROM suppliers WHERE user_id = :u');
$sup_stmt->execute([':u' => $user['id']]);
$supplier = $sup_stmt->fetch();
$is_supplier_recipient = $supplier && ((int)$supplier['id'] === (int)$letter['supplier_id']);

if (!$is_staff && !$is_supplier_recipient) {
    header('Location: no_access.php?reason=forbidden');
    exit;
}

$toast = '';
$toast_type = 'success';

// Handle supplier acknowledgement directly on this letter page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_supplier_recipient) {
    $action = $_POST['action'] ?? '';
    if ($action === 'acknowledge') {
        $notes = trim($_POST['notes'] ?? '');
        $res = acknowledge_procurement_letter((int)$letter['id'], (int)$supplier['id'], $notes);
        if ($res['ok']) {
            $toast = $res['message'] ?? 'Letter acknowledged successfully!';
            // Refresh letter data
            $letter = get_procurement_letter((int)$letter['id'], false);
        } else {
            $toast = $res['error'] ?? 'Could not acknowledge letter.';
            $toast_type = 'error';
        }
    }
}

$items = $letter['items'] ?? [];
$subtotal = 0.0;
foreach ($items as $it) {
    $subtotal += ((float)$it['quantity'] * (float)$it['est_unit_price']);
}
if ($subtotal <= 0 && (float)$letter['req_total'] > 0) {
    $subtotal = (float)$letter['req_total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Procurement Letter — <?= htmlspecialchars($letter['letter_ref']) ?> — Kofee Manila</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <style>
    :root {
      --km-espresso: #241A2E;
      --km-caramel: #C97B3D;
      --km-latte: #EFE0CC;
      --km-paper: #FFFFFF;
      --km-ink: #2B2130;
      --km-muted: #6B5E70;
      --km-border: #E5D5C5;
    }

    body {
      background: #F4EBE1;
      color: var(--km-ink);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      margin: 0;
      padding: 0;
    }

    /* Screen Top Toolbar */
    .letter-toolbar {
      position: sticky;
      top: 0;
      z-index: 100;
      background: var(--km-espresso);
      color: #fff;
      padding: 12px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 14px rgba(36,26,46,0.25);
    }
    .letter-toolbar .brand-tag {
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: var(--km-caramel);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .letter-toolbar .actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Document Sheet Container */
    .sheet-wrapper {
      max-width: 860px;
      margin: 32px auto 60px;
      padding: 0 16px;
    }

    .doc-sheet {
      background: var(--km-paper);
      border-radius: 12px;
      border: 1px solid var(--km-border);
      box-shadow: 0 10px 32px rgba(36,26,46,0.08);
      padding: 56px 64px;
      box-sizing: border-box;
      position: relative;
    }

    /* Official Letterhead Header */
    .lh-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 2.5px solid var(--km-espresso);
      padding-bottom: 24px;
      margin-bottom: 28px;
    }
    .lh-logo-area {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .lh-monogram {
      width: 54px;
      height: 54px;
      border-radius: 14px;
      background: linear-gradient(135deg, var(--km-espresso) 0%, #3D2644 100%);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: Georgia, serif;
      font-weight: 800;
      font-size: 26px;
      box-shadow: 0 4px 10px rgba(36,26,46,0.25);
    }
    .lh-brand-name {
      font-family: Georgia, serif;
      font-size: 24px;
      font-weight: 800;
      color: var(--km-espresso);
      line-height: 1.1;
      margin: 0;
    }
    .lh-brand-sub {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      color: var(--km-caramel);
      margin-top: 4px;
    }
    .lh-company-meta {
      text-align: right;
      font-size: 11.5px;
      line-height: 1.5;
      color: var(--km-muted);
    }

    /* Meta Details Grid */
    .doc-meta-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      background: #FAF5EE;
      border: 1px solid var(--km-latte);
      border-radius: 10px;
      padding: 16px 20px;
      margin-bottom: 28px;
    }
    .doc-meta-col strong {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--km-caramel);
      margin-bottom: 3px;
    }
    .doc-meta-col p {
      margin: 0 0 8px;
      font-size: 13.5px;
      color: var(--km-ink);
    }
    .doc-meta-col p:last-child {
      margin-bottom: 0;
    }

    /* Formal Subject & Salutation */
    .doc-subject-bar {
      border-left: 4px solid var(--km-caramel);
      padding: 6px 14px;
      margin-bottom: 22px;
      background: #FCF8F2;
    }
    .doc-subject-bar h2 {
      font-size: 15px;
      margin: 0;
      color: var(--km-espresso);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .doc-body-p {
      font-size: 14px;
      line-height: 1.65;
      color: var(--km-ink);
      margin-bottom: 16px;
    }

    /* Itemized Table */
    .doc-table {
      width: 100%;
      border-collapse: collapse;
      margin: 20px 0 24px;
      font-size: 13px;
    }
    .doc-table th {
      background: var(--km-espresso);
      color: #fff;
      font-weight: 700;
      text-align: left;
      padding: 10px 12px;
      font-size: 11.5px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .doc-table th:last-child,
    .doc-table td:last-child {
      text-align: right;
    }
    .doc-table td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--km-latte);
      color: var(--km-ink);
    }
    .doc-table tr:last-child td {
      border-bottom: 2px solid var(--km-espresso);
    }
    .doc-table tr.total-row td {
      font-size: 15px;
      font-weight: 800;
      background: #FAF5EE;
      color: var(--km-espresso);
      border-top: 2px solid var(--km-espresso);
      border-bottom: 2px solid var(--km-espresso);
      padding: 12px;
    }

    /* Terms Block */
    .terms-card {
      background: #FCF8F2;
      border: 1px solid var(--km-border);
      border-radius: 8px;
      padding: 16px 20px;
      margin-bottom: 30px;
      font-size: 12.5px;
      line-height: 1.6;
    }
    .terms-card h4 {
      margin: 0 0 8px;
      font-size: 12.5px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--km-espresso);
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .terms-list {
      margin: 0;
      padding-left: 18px;
    }
    .terms-list li {
      margin-bottom: 4px;
    }

    /* Signatures Section */
    .sig-section {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 32px;
      margin-top: 36px;
      padding-top: 24px;
      border-top: 1px dashed var(--km-border);
      page-break-inside: avoid;
    }
    .sig-box {
      border: 1px solid var(--km-latte);
      border-radius: 10px;
      padding: 18px;
      background: #FAF6F0;
      position: relative;
    }
    .sig-label {
      font-size: 10.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--km-caramel);
      margin-bottom: 8px;
    }
    .sig-preview {
      height: 80px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      margin-bottom: 8px;
    }
    .sig-preview img {
      max-height: 80px;
      max-width: 100%;
      object-fit: contain;
      filter: drop-shadow(0 1px 2px rgba(0,0,0,0.1));
    }
    .sig-signer-name {
      font-size: 14px;
      font-weight: 800;
      color: var(--km-espresso);
      border-top: 1.5px solid var(--km-espresso);
      padding-top: 6px;
      margin-top: 4px;
    }
    .sig-signer-title {
      font-size: 11.5px;
      color: var(--km-muted);
    }
    .sig-stamp {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 10.5px;
      font-weight: 700;
      color: #27AE60;
      background: #E8F8F0;
      border: 1px solid #A3E4D7;
      padding: 3px 8px;
      border-radius: 6px;
      margin-top: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Supplier Acknowledgement Form on Letter */
    .ack-prompt-box {
      background: #EBF5FB;
      border: 1.5px solid #A9CCE3;
      border-radius: 10px;
      padding: 16px 20px;
      margin-top: 24px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    /* Print Stylesheet */
    @media print {
      body {
        background: #FFFFFF !important;
        color: #000000 !important;
        font-size: 11pt;
      }
      .letter-toolbar, .no-print {
        display: none !important;
      }
      .sheet-wrapper {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
      }
      .doc-sheet {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        border-radius: 0 !important;
      }
      .doc-meta-grid, .terms-card, .sig-box {
        background: #FFFFFF !important;
        border-color: #CCCCCC !important;
      }
      .doc-table th {
        background: #333333 !important;
        color: #FFFFFF !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      .sig-preview img {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>

<!-- Top Toolbar (Screen View Only) -->
<div class="letter-toolbar no-print">
  <div class="brand-tag">
    <?= icon('coffee', 16, '', 'vertical-align:middle;margin-right:4px') ?>
    Kofee Manila Procurement Office
  </div>
  <div class="actions">
    <?php if ($is_supplier_recipient): ?>
      <a href="supplier_portal.php" class="act-btn" style="background:#4A3854;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
        <?= icon('arrow-left', 13) ?> Back to Supplier Portal
      </a>
    <?php else: ?>
      <a href="requisitions.php" class="act-btn" style="background:#4A3854;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
        <?= icon('arrow-left', 13) ?> Back to Requisitions
      </a>
    <?php endif; ?>

    <button type="button" class="act-btn act-activate" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px">
      <?= icon('printer', 13) ?> Print / Save as PDF
    </button>
  </div>
</div>

<?php if ($toast): ?>
<div class="no-print" style="max-width:860px;margin:16px auto -16px;padding:0 16px">
  <div class="toast toast-<?= $toast_type ?>" style="position:static;display:block;width:100%;box-sizing:border-box">
    <?= $toast ?>
  </div>
</div>
<?php endif; ?>

<div class="sheet-wrapper">
  <div class="doc-sheet">

    <!-- Letterhead -->
    <div class="lh-header">
      <div class="lh-logo-area">
        <div class="lh-monogram">KM</div>
        <div>
          <h1 class="lh-brand-name">Kofee Manila</h1>
          <div class="lh-brand-sub">Artisan Coffee &amp; Bakery Procurement</div>
        </div>
      </div>
      <div class="lh-company-meta">
        <strong>Kofee Manila Corp.</strong><br/>
        108 Maginhawa St., Teacher's Village, Quezon City<br/>
        Metro Manila, Philippines · Tel: +63 (02) 8920-1234<br/>
        TIN: 412-889-021-000 · VAT Registered
      </div>
    </div>

    <!-- Letter Meta Block -->
    <div class="doc-meta-grid">
      <div class="doc-meta-col">
        <strong>Awarded Supplier:</strong>
        <p style="font-weight:800;font-size:15px;color:var(--km-espresso)"><?= htmlspecialchars($letter['supplier_name']) ?></p>
        <p>
          <strong>Attention:</strong> <?= htmlspecialchars($letter['supplier_contact'] ?: 'Management Team') ?><br/>
          <?= htmlspecialchars($letter['supplier_address'] ?: 'Metro Manila, Philippines') ?><br/>
          <?= htmlspecialchars($letter['supplier_phone'] ?: '—') ?>
          <?= $letter['supplier_email'] ? ' · ' . htmlspecialchars($letter['supplier_email']) : '' ?>
        </p>
      </div>
      <div class="doc-meta-col" style="text-align:right">
        <strong>Official Reference Number:</strong>
        <p style="font-family:monospace;font-size:16px;font-weight:800;color:var(--km-caramel)"><?= htmlspecialchars($letter['letter_ref']) ?></p>
        <p>
          <strong>Date Issued:</strong> <?= date('F d, Y', strtotime($letter['sent_at'])) ?><br/>
          <strong>Requisition Reference:</strong> PR #<?= str_pad($letter['requisition_id'], 4, '0', STR_PAD_LEFT) ?> (<?= htmlspecialchars($letter['req_department']) ?>)<br/>
          <strong>Delivery Channel:</strong> In-System Supplier Portal
        </p>
      </div>
    </div>

    <!-- Subject & Narrative Body -->
    <div class="doc-subject-bar">
      <h2>SUBJECT: NOTICE OF PROCUREMENT AWARD &amp; PURCHASE AUTHORIZATION</h2>
    </div>

    <p class="doc-body-p">
      Dear <strong><?= htmlspecialchars($letter['supplier_contact'] ?: $letter['supplier_name']) ?></strong> and Management Team,
    </p>

    <p class="doc-body-p">
      We are pleased to inform you that your quotation and offer have been officially approved and awarded by Kofee Manila Central Procurement for <strong><?= htmlspecialchars($letter['req_title']) ?></strong>.
      Please consider this official letter as our formal purchase authorization to proceed with fulfillment and preparation of the items listed below under the authorized quantities and terms.
    </p>

    <!-- Itemized Table -->
    <table class="doc-table">
      <thead>
        <tr>
          <th style="width:40px">#</th>
          <th>Item Description / Specification</th>
          <th style="width:90px;text-align:center">Qty</th>
          <th style="width:80px;text-align:center">Unit</th>
          <th style="width:130px;text-align:right">Unit Price (₱)</th>
          <th style="width:140px;text-align:right">Amount (₱)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td>1</td>
            <td><strong><?= htmlspecialchars($letter['req_title']) ?></strong></td>
            <td style="text-align:center">1.00</td>
            <td style="text-align:center">lot</td>
            <td style="text-align:right">₱<?= number_format((float)$letter['req_total'], 2) ?></td>
            <td style="text-align:right">₱<?= number_format((float)$letter['req_total'], 2) ?></td>
          </tr>
        <?php else: foreach ($items as $idx => $it):
          $line_total = (float)$it['quantity'] * (float)$it['est_unit_price'];
        ?>
          <tr>
            <td><?= $idx + 1 ?></td>
            <td><strong><?= htmlspecialchars($it['item_name']) ?></strong></td>
            <td style="text-align:center"><?= number_format((float)$it['quantity'], 2) ?></td>
            <td style="text-align:center"><?= htmlspecialchars($it['unit']) ?></td>
            <td style="text-align:right">₱<?= number_format((float)$it['est_unit_price'], 2) ?></td>
            <td style="text-align:right">₱<?= number_format($line_total, 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        <tr class="total-row">
          <td colspan="5" style="text-align:right">TOTAL AUTHORIZED PROCUREMENT AMOUNT:</td>
          <td>₱<?= number_format($subtotal, 2) ?></td>
        </tr>
      </tbody>
    </table>

    <!-- Delivery Terms & Conditions -->
    <div class="terms-card">
      <h4><?= icon('truck', 14) ?> Fulfillment &amp; Payment Conditions</h4>
      <ul class="terms-list">
        <li><strong>Delivery Timeline:</strong> <?= htmlspecialchars($letter['delivery_terms'] ?: 'Within 5-7 business days upon receipt of order.') ?></li>
        <li><strong>Delivery Destination:</strong> Kofee Manila Central Commissary &amp; Main Branch Receiving Dock, Quezon City.</li>
        <li><strong>Goods Inspection:</strong> All goods received will be inspected for count, batch integrity, and freshness upon arrival and receipted via our Goods Receipt Note (GRN) workflow.</li>
        <li><strong>Payment Terms:</strong> <?= htmlspecialchars($letter['payment_terms'] ?: 'Net 30 Days upon delivery and three-way invoice matching.') ?></li>
        <?php if (!empty($letter['special_instructions'])): ?>
          <li><strong>Special Instructions:</strong> <?= htmlspecialchars($letter['special_instructions']) ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Signatures & Authorization Section -->
    <div class="sig-section">
      <!-- Approver E-Signature Block -->
      <div class="sig-box">
        <div class="sig-label">Authorized Approver (Kofee Manila)</div>
        <div class="sig-preview">
          <?php if (!empty($letter['approver_signature'])): ?>
            <img src="<?= htmlspecialchars($letter['approver_signature']) ?>" alt="Approver E-Signature"/>
          <?php else: ?>
            <div style="font-family:Georgia,serif;font-style:italic;color:var(--km-espresso);font-size:18px">[Signed Electronically]</div>
          <?php endif; ?>
        </div>
        <div class="sig-signer-name"><?= htmlspecialchars($letter['approver_name']) ?></div>
        <div class="sig-signer-title"><?= htmlspecialchars($letter['approver_title']) ?> · Kofee Manila</div>
        <div class="sig-stamp">
          <?= icon('check', 11) ?> Digitally Authorized on <?= date('M d, Y H:i', strtotime($letter['sent_at'])) ?>
        </div>
      </div>

      <!-- Supplier Acknowledgment Block -->
      <div class="sig-box">
        <div class="sig-label">Supplier Formal Acknowledgment</div>
        <?php if ($letter['status'] === 'acknowledged'): ?>
          <div class="sig-preview" style="align-items:center">
            <div style="font-family:Georgia,serif;font-style:italic;font-size:17px;color:#1E8449;font-weight:700">
              <?= htmlspecialchars($letter['supplier_contact'] ?: $letter['supplier_name']) ?>
            </div>
          </div>
          <div class="sig-signer-name"><?= htmlspecialchars($letter['supplier_name']) ?></div>
          <div class="sig-signer-title">Authorized Representative</div>
          <div class="sig-stamp" style="background:#EAF2F8;color:#2980B9;border-color:#A9CCE3">
            <?= icon('check', 11) ?> Acknowledged on <?= date('M d, Y H:i', strtotime($letter['acknowledged_at'])) ?>
          </div>
          <?php if (!empty($letter['acknowledgement_notes'])): ?>
            <div style="font-size:11.5px;color:var(--km-muted);margin-top:6px;font-style:italic">
              Note: "<?= htmlspecialchars($letter['acknowledgement_notes']) ?>"
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="sig-preview" style="align-items:center;color:var(--km-muted);font-size:12.5px;font-style:italic">
            Awaiting supplier receipt acknowledgment via Supplier Portal.
          </div>
          <div class="sig-signer-name"><?= htmlspecialchars($letter['supplier_name']) ?></div>
          <div class="sig-signer-title">Recipient Supplier</div>
          <div class="sig-stamp" style="background:#FDF3EA;color:var(--km-caramel);border-color:#F5CBA7">
            <?= icon('clock', 11) ?> Dispatched to Portal — Pending Acknowledgment
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- If viewed by recipient supplier and not yet acknowledged, offer instant acknowledgment form -->
    <?php if ($is_supplier_recipient && $letter['status'] !== 'acknowledged'): ?>
      <div class="ack-prompt-box no-print">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div>
            <strong style="color:#1B4F72;font-size:14px"><?= icon('check', 14) ?> Formal Supplier Acknowledgment</strong>
            <p style="font-size:12.5px;color:#2C3E50;margin:3px 0 0">
              Please acknowledge receipt of this official purchase authorization letter. This informs Kofee Manila procurement that order fulfillment is confirmed.
            </p>
          </div>
        </div>
        <form method="POST" style="margin-top:6px">
          <input type="hidden" name="action" value="acknowledge"/>
          <div class="field-group" style="margin-bottom:8px">
            <input class="field-input" type="text" name="notes" placeholder="Optional delivery commitment note (e.g. 'Order received, delivery scheduled for Friday Oct 2')"/>
          </div>
          <button type="submit" class="btn-save" style="background:#27AE60">
            <?= icon('check', 14) ?> Formally Acknowledge Receipt of Order
          </button>
        </form>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>

