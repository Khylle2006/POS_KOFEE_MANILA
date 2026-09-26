<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_once '../includes/icons.php';
require_login();
require_permission('procurement.supplier.portal');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';

// Ensure tables exist
ensure_procurement_tables($pdo);

// ── Resolve this login to a supplier profile ──────────────────
$sup_stmt = $pdo->prepare('SELECT * FROM suppliers WHERE user_id = :u');
$sup_stmt->execute([':u' => $user['id']]);
$supplier = $sup_stmt->fetch();

// ── POST actions (only meaningful once a supplier profile is linked) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supplier) {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_bid') {
        $rfq_id = (int)($_POST['rfq_id'] ?? 0);
        $total  = (float)($_POST['quoted_total'] ?? 0);
        $lead   = (int)($_POST['lead_time_days'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');

        // Confirm this supplier was actually invited to an open RFQ before accepting a quote.
        $chk = $pdo->prepare("
            SELECT rfqs.* FROM rfqs
            JOIN rfq_invites ri ON ri.rfq_id = rfqs.id
            WHERE rfqs.id = :r AND ri.supplier_id = :s AND rfqs.status = 'open'
        ");
        $chk->execute([':r' => $rfq_id, ':s' => $supplier['id']]);
        $rfq_row = $chk->fetch();

        if (!$rfq_row) {
            $toast = 'This RFQ is no longer open for quotes.'; $toast_type = 'error';
        } elseif ($total <= 0) {
            $toast = 'Enter a valid quoted total.'; $toast_type = 'error';
        } else {
            // Check deadline enforcement
            $due_check = can_supplier_bid($rfq_row);
            if (!$due_check['can_bid']) {
                $toast = $due_check['reason'];
                $toast_type = 'error';
            } else {
                $pdo->prepare('
                    INSERT INTO bids (rfq_id, supplier_id, quoted_total, lead_time_days, notes, status)
                    VALUES (:r,:s,:t,:l,:n, "submitted")
                    ON DUPLICATE KEY UPDATE quoted_total=:t2, lead_time_days=:l2, notes=:n2, status="submitted"
                ')->execute([
                    ':r'=>$rfq_id, ':s'=>$supplier['id'], ':t'=>$total, ':l'=>$lead, ':n'=>$notes,
                    ':t2'=>$total, ':l2'=>$lead, ':n2'=>$notes,
                ]);
                audit_log('bid', $rfq_id, 'quoted', $supplier['name'] . ' quoted ' . php_currency($total));
                notify_role_by_permission('procurement.bidding.review', 'bid_submitted', 'New quote submitted', $supplier['name'] . ' quoted ' . php_currency($total) . ' on RFQ #' . $rfq_id, 'rfq.php?id=' . $rfq_id);
                $toast = 'Quote submitted successfully.';
            }
        }
    }

    if ($action === 'withdraw_bid') {
        $bid_id = (int)($_POST['bid_id'] ?? 0);
        $res = withdraw_supplier_bid($bid_id, (int)$supplier['id']);
        if ($res['ok']) {
            $toast = $res['message'];
        } else {
            $toast = $res['error'];
            $toast_type = 'error';
        }
        header('Location: supplier_portal.php?tab=rfqs&toast=' . urlencode($toast) . '&type=' . $toast_type);
        exit;
    }

    if ($action === 'sign_contract') {
        $contract_id  = (int)($_POST['contract_id'] ?? 0);
        $signer_name  = trim($_POST['supplier_signed_name'] ?? '');
        $supplier_sig = trim($_POST['supplier_signature'] ?? '');

        if (!$contract_id || !$signer_name || !$supplier_sig) {
            $toast = 'Please provide your full legal name and draw your electronic countersignature.';
            $toast_type = 'error';
        } else {
            $c_chk = $pdo->prepare('SELECT * FROM purchase_contracts WHERE id = :id AND supplier_id = :s');
            $c_chk->execute([':id' => $contract_id, ':s' => $supplier['id']]);
            $c_row = $c_chk->fetch();

            if (!$c_row) {
                $toast = 'Contract not found or not assigned to your account.';
                $toast_type = 'error';
            } elseif ($c_row['status'] !== 'sent_to_supplier') {
                $toast = 'This contract is not awaiting signature (status: ' . $c_row['status'] . ').';
                $toast_type = 'error';
            } else {
                try {
                    $pdo->prepare("
                        UPDATE purchase_contracts
                        SET supplier_signed_by = :u,
                            supplier_signed_name = :name,
                            supplier_signature = :sig,
                            supplier_signed_at = NOW(),
                            status = 'fully_signed'
                        WHERE id = :id
                    ")->execute([
                        ':u'    => $user['id'],
                        ':name' => $signer_name,
                        ':sig'  => $supplier_sig,
                        ':id'   => $contract_id,
                    ]);

                    audit_log(
                        'contract',
                        $contract_id,
                        'fully_signed',
                        "Supplier {$supplier['name']} countersigned contract {$c_row['contract_ref']}"
                    );

                    // Notify buyer and procurement officers
                    if (!empty($c_row['buyer_signed_by'])) {
                        notify_user(
                            (int)$c_row['buyer_signed_by'],
                            'contract_signed',
                            'Contract Countersigned by Supplier',
                            "Supplier {$supplier['name']} has countersigned Contract {$c_row['contract_ref']}. You can now issue the Purchase Order.",
                            'purchase_contracts.php?id=' . $contract_id
                        );
                    }
                    notify_role_by_permission(
                        'procurement.po.manage',
                        'contract_signed',
                        'Contract Countersigned by Supplier',
                        "Contract {$c_row['contract_ref']} for {$supplier['name']} is now fully signed. Ready to issue Purchase Order.",
                        'purchase_contracts.php?id=' . $contract_id
                    );

                    $toast = "Contract {$c_row['contract_ref']} successfully countersigned! Kofee Manila procurement has been notified to issue the Purchase Order.";
                    header('Location: supplier_portal.php?tab=contracts&toast=' . urlencode($toast));
                    exit;
                } catch (Exception $e) {
                    $toast = 'Error signing contract: ' . $e->getMessage();
                    $toast_type = 'error';
                }
            }
        }
    }

    if ($action === 'acknowledge_letter') {
        $letter_id = (int)($_POST['letter_id'] ?? 0);
        $notes     = trim($_POST['acknowledgement_notes'] ?? '');
        $res       = acknowledge_procurement_letter($letter_id, (int)$supplier['id'], $notes);
        if ($res['ok']) {
            $toast = $res['message'] ?? 'Letter acknowledged.';
        } else {
            $toast = $res['error'] ?? 'Could not acknowledge letter.';
            $toast_type = 'error';
        }
    }

    if ($action === 'acknowledge_po') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $po_stmt = $pdo->prepare('
            SELECT po.*, c.contract_ref 
            FROM purchase_orders po 
            LEFT JOIN purchase_contracts c ON c.id = po.contract_id 
            WHERE po.id = :id AND po.supplier_id = :sid AND po.status = "sent"
        ');
        $po_stmt->execute([':id' => $po_id, ':sid' => $supplier['id']]);
        $po_row = $po_stmt->fetch();

        if (!$po_row) {
            $toast = 'Could not acknowledge — order may already be acknowledged or not found.';
            $toast_type = 'error';
        } else {
            $pdo->prepare("
                UPDATE purchase_orders 
                SET status = 'acknowledged', acknowledged_at = NOW() 
                WHERE id = :id
            ")->execute([':id' => $po_id]);

            $po_num = $po_row['po_number'] ?: ('KM-PO-' . str_pad($po_id, 5, '0', STR_PAD_LEFT));
            audit_log('po', $po_id, 'acknowledged', "{$supplier['name']} acknowledged order {$po_num}");

            $title = "PO Acknowledged — {$po_num}";
            $msg   = "Supplier {$supplier['name']} has formally acknowledged Purchase Order {$po_num}.";
            $url   = "purchase_orders.php?id={$po_id}";

            if (!empty($po_row['created_by'])) {
                notify_user((int)$po_row['created_by'], 'po_acknowledged', $title, $msg, $url);
            }
            notify_role_by_permission('procurement.po.manage', 'po_acknowledged', $title, $msg, $url, !empty($po_row['created_by']) ? (int)$po_row['created_by'] : null);

            $toast = "Purchase Order {$po_num} acknowledged successfully.";
            header('Location: supplier_portal.php?tab=orders&toast=' . urlencode($toast));
            exit;
        }
    }

    if ($action === 'raise_po_issue') {
        $po_id  = (int)($_POST['po_id'] ?? 0);
        $reason = trim($_POST['issue_reason'] ?? '');
        $notes  = trim($_POST['issue_notes'] ?? '');

        if (!$po_id || !$notes) {
            $toast = 'Please provide details explaining the issue.';
            $toast_type = 'error';
        } else {
            $full_notes = $reason ? "[{$reason}] {$notes}" : $notes;
            $po_stmt = $pdo->prepare('
                SELECT po.*, c.contract_ref 
                FROM purchase_orders po 
                LEFT JOIN purchase_contracts c ON c.id = po.contract_id 
                WHERE po.id = :id AND po.supplier_id = :sid
            ');
            $po_stmt->execute([':id' => $po_id, ':sid' => $supplier['id']]);
            $po_row = $po_stmt->fetch();

            if (!$po_row) {
                $toast = 'Purchase order not found.';
                $toast_type = 'error';
            } else {
                $pdo->prepare("
                    UPDATE purchase_orders
                    SET issue_status = 'open',
                        issue_notes = :notes,
                        issue_raised_at = NOW()
                    WHERE id = :id
                ")->execute([':notes' => $full_notes, ':id' => $po_id]);

                $po_num = $po_row['po_number'] ?: ('KM-PO-' . str_pad($po_id, 5, '0', STR_PAD_LEFT));
                audit_log('po', $po_id, 'issue_raised', "Issue raised by {$supplier['name']}: {$full_notes}");

                $title = "Supplier Issue Raised — {$po_num}";
                $msg   = "Supplier {$supplier['name']} reported an issue on PO {$po_num}: {$full_notes}";
                $url   = "purchase_orders.php?id={$po_id}";

                if (!empty($po_row['created_by'])) {
                    notify_user((int)$po_row['created_by'], 'po_issue', $title, $msg, $url);
                }
                notify_role_by_permission('procurement.po.manage', 'po_issue', $title, $msg, $url, !empty($po_row['created_by']) ? (int)$po_row['created_by'] : null);

                $toast = "Your issue has been reported to management for review. PO {$po_num} marked under review.";
                header('Location: supplier_portal.php?tab=orders&toast=' . urlencode($toast));
                exit;
            }
        }
    }

    if ($action === 'submit_asn' || $action === 'submit_delivery_notice') {
        $po_id      = (int)($_POST['po_id'] ?? 0);
        $carrier    = trim($_POST['carrier_name'] ?? '');
        $tracking   = trim($_POST['tracking_number'] ?? '');
        $ship_date  = !empty($_POST['shipped_date']) ? $_POST['shipped_date'] : date('Y-m-d');
        $arr_date   = !empty($_POST['expected_arrival_date']) ? $_POST['expected_arrival_date'] : null;
        $f_status   = in_array($_POST['fulfillment_status'] ?? '', ['preparing','partially_shipped','shipped','delivered'], true) ? $_POST['fulfillment_status'] : 'shipped';
        $asn_notes  = trim($_POST['notes'] ?? '');
        $lines      = $_POST['items'] ?? []; // [req_item_id => ['shipped_qty' => ...]]

        $chk = $pdo->prepare('
            SELECT po.*, c.contract_ref 
            FROM purchase_orders po 
            LEFT JOIN purchase_contracts c ON c.id = po.contract_id 
            WHERE po.id = :id AND po.supplier_id = :sid AND po.status IN ("sent","acknowledged")
        ');
        $chk->execute([':id' => $po_id, ':sid' => $supplier['id']]);
        $po_row = $chk->fetch();

        if (!$po_row) {
            $toast = 'Purchase order not found or not eligible for shipment dispatch.';
            $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Notice Ref: KM-ASN-YYYY-XXXX
                $notice_ref = sprintf('KM-ASN-%s-%04d', date('Y'), $po_id);
                $suffix = 1;
                while (true) {
                    $c_chk = $pdo->prepare('SELECT id FROM delivery_notices WHERE notice_ref = :ref');
                    $c_chk->execute([':ref' => $notice_ref]);
                    if (!$c_chk->fetch()) break;
                    $suffix++;
                    $notice_ref = sprintf('KM-ASN-%s-%04d-%d', date('Y'), $po_id, $suffix);
                }

                $pdo->prepare('
                    INSERT INTO delivery_notices 
                        (notice_ref, po_id, supplier_id, carrier_name, tracking_number, shipped_date, expected_arrival_date, fulfillment_status, notes)
                    VALUES 
                        (:ref, :po, :sid, :c, :trk, :sdate, :adate, :fst, :n)
                ')->execute([
                    ':ref'   => $notice_ref,
                    ':po'    => $po_id,
                    ':sid'   => $supplier['id'],
                    ':c'     => $carrier ?: null,
                    ':trk'   => $tracking ?: null,
                    ':sdate' => $ship_date,
                    ':adate' => $arr_date,
                    ':fst'   => $f_status,
                    ':n'     => $asn_notes ?: null,
                ]);
                $dn_id = (int)$pdo->lastInsertId();

                // Insert items
                $ins_item = $pdo->prepare('
                    INSERT INTO delivery_notice_items 
                        (delivery_notice_id, requisition_item_id, item_name, shipped_qty, unit)
                    VALUES 
                        (:dn, :ri, :name, :qty, :unit)
                ');

                $total_shipped_units = 0;
                foreach ($lines as $ri_id => $l) {
                    $sqty = (float)($l['shipped_qty'] ?? 0);
                    $name = trim($l['item_name'] ?? 'Item');
                    $unit = trim($l['unit'] ?? 'pcs');
                    if ($sqty > 0) {
                        $ins_item->execute([
                            ':dn'   => $dn_id,
                            ':ri'   => (int)$ri_id,
                            ':name' => $name,
                            ':qty'  => $sqty,
                            ':unit' => $unit,
                        ]);
                        $total_shipped_units += $sqty;
                    }
                }

                // Update PO: fulfillment_status, shipped_at, shipping_notes
                $ship_summary = trim(($carrier ? "Carrier: $carrier" : '') . ($tracking ? " (Trk: $tracking)" : '') . ($asn_notes ? " — $asn_notes" : ''));
                $pdo->prepare("
                    UPDATE purchase_orders 
                    SET fulfillment_status = :fst,
                        shipped_at = COALESCE(shipped_at, NOW()),
                        shipping_notes = :sn
                    WHERE id = :id
                ")->execute([
                    ':fst' => $f_status,
                    ':sn'  => $ship_summary ?: null,
                    ':id'  => $po_id,
                ]);

                $po_num = $po_row['po_number'] ?: ('KM-PO-' . str_pad($po_id, 5, '0', STR_PAD_LEFT));
                audit_log('delivery_notice', $dn_id, 'submitted', "ASN {$notice_ref} submitted by {$supplier['name']} via {$carrier} (Status: {$f_status}, Units: {$total_shipped_units})");
                audit_log('po', $po_id, 'shipped', "ASN {$notice_ref} dispatched via {$carrier} (Tracking: {$tracking})");

                // Notify warehouse / receiving
                notify_role_by_permission(
                    'procurement.receiving',
                    'asn_submitted',
                    "Incoming Delivery Notice / ASN — {$notice_ref}",
                    "Supplier {$supplier['name']} dispatched shipment for PO {$po_num} via {$carrier}" . ($tracking ? " (Trk: {$tracking})" : '') . ". {$total_shipped_units} total unit(s) expected.",
                    "goods_receipts.php?po_id={$po_id}&asn_id={$dn_id}"
                );

                if (!empty($po_row['created_by'])) {
                    notify_user(
                        (int)$po_row['created_by'],
                        'asn_submitted',
                        "Shipment Dispatched on PO {$po_num} ({$notice_ref})",
                        "Supplier {$supplier['name']} submitted ASN {$notice_ref} with status \"{$f_status}\".",
                        "purchase_orders.php?id={$po_id}"
                    );
                }

                $pdo->commit();
                $toast = "Advance Shipping Notice {$notice_ref} submitted successfully. Receiving team has been notified.";
                header('Location: supplier_portal.php?tab=orders&toast=' . urlencode($toast));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = 'Error submitting Delivery Notice: ' . $e->getMessage();
                $toast_type = 'error';
            }
        }
    }

    if ($action === 'update_fulfillment_status') {
        $po_id    = (int)($_POST['po_id'] ?? 0);
        $f_status = in_array($_POST['fulfillment_status'] ?? '', ['preparing','partially_shipped','shipped','delivered'], true) ? $_POST['fulfillment_status'] : 'preparing';
        $notes    = trim($_POST['status_notes'] ?? '');

        $pdo->prepare("
            UPDATE purchase_orders 
            SET fulfillment_status = :st,
                shipped_at = CASE WHEN :st = 'shipped' AND shipped_at IS NULL THEN NOW() ELSE shipped_at END
            WHERE id = :id AND supplier_id = :sid
        ")->execute([':st' => $f_status, ':id' => $po_id, ':sid' => $supplier['id']]);

        audit_log('po', $po_id, 'fulfillment_updated', "Fulfillment status updated to {$f_status}" . ($notes ? ": {$notes}" : ''));
        $toast = "Fulfillment status updated to " . ucfirst(str_replace('_', ' ', $f_status)) . ".";
        header('Location: supplier_portal.php?tab=orders&toast=' . urlencode($toast));
        exit;
    }

    if ($action === 'mark_shipped') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $note  = trim($_POST['shipping_notes'] ?? '');
        $upd = $pdo->prepare("
            UPDATE purchase_orders
            SET shipped_at = NOW(), shipping_notes = :n, fulfillment_status = 'shipped'
            WHERE id = :id AND supplier_id = :sid
              AND status IN ('sent','acknowledged') AND shipped_at IS NULL
        ");
        $upd->execute([':n' => $note ?: null, ':id' => $po_id, ':sid' => $supplier['id']]);
        if ($upd->rowCount()) {
            audit_log('po', $po_id, 'shipped', $supplier['name'] . ' marked the order shipped' . ($note ? " — {$note}" : ''));
            notify_role_by_permission('procurement.receiving', 'po_shipped', 'Order shipped', $supplier['name'] . ' shipped PO #' . $po_id, 'goods_receipts.php?po_id=' . $po_id);
            $toast = 'Marked as shipped.';
        } else {
            $toast = 'Could not mark shipped — order may already be past that step.'; $toast_type = 'error';
        }
        header('Location: supplier_portal.php?tab=orders&toast=' . urlencode($toast));
        exit;
    }

    if ($action === 'submit_invoice') {
        $po_id      = (int)($_POST['po_id'] ?? 0);
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $inv_num    = trim($_POST['invoice_number'] ?? '');
        $inv_date   = $_POST['invoice_date'] ?: date('Y-m-d');
        $due_date   = $_POST['due_date'] ?: null;
        $lines      = $_POST['lines'] ?? [];
        $tax        = (float)($_POST['tax_amount'] ?? 0);

        if (!$inv_num) {
            $toast = 'Invoice number is required.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                // Attachment handling
                $attachment_path = null;
                if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../uploads/invoices';
                    if (!is_dir($upload_dir)) {
                        @mkdir($upload_dir, 0755, true);
                    }
                    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                        $fname = 'inv_sup_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . '/' . $fname)) {
                            $attachment_path = 'uploads/invoices/' . $fname;
                        }
                    }
                }

                if ($invoice_id > 0) {
                    // Resubmitting existing invoice
                    $chk = $pdo->prepare('SELECT * FROM invoices WHERE id = :id AND supplier_id = :s');
                    $chk->execute([':id' => $invoice_id, ':s' => $supplier['id']]);
                    $existing_inv = $chk->fetch();
                    if (!$existing_inv) {
                        throw new Exception('Invoice not found or does not belong to your account.');
                    }
                    $po_id = (int)$existing_inv['po_id'];

                    $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id AND supplier_id = :s');
                    $po_stmt->execute([':id' => $po_id, ':s' => $supplier['id']]);
                    $po = $po_stmt->fetch();
                    if (!$po) throw new Exception('Purchase order not found.');

                    $subtotal = 0.0;
                    $line_data = [];
                    foreach ($lines as $ri_id => $l) {
                        $qty   = (float)($l['qty'] ?? 0);
                        $price = (float)($l['unit_price'] ?? 0);
                        if ($qty > 0) {
                            $ri_stmt = $pdo->prepare('SELECT item_name FROM requisition_items WHERE id = :id');
                            $ri_stmt->execute([':id' => (int)$ri_id]);
                            $item_name = $ri_stmt->fetchColumn() ?: 'Item';
                            $lt = round($qty * $price, 2);
                            $subtotal += $lt;
                            $line_data[] = [
                                'requisition_item_id' => (int)$ri_id,
                                'item_name'           => $item_name,
                                'qty'                 => $qty,
                                'unit_price'          => $price,
                                'line_total'          => $lt,
                            ];
                        }
                    }
                    if (empty($line_data)) {
                        throw new Exception('Enter a quantity for at least one line item.');
                    }
                    $total = round($subtotal + $tax, 2);
                    $new_version = ((int)($existing_inv['version'] ?: 1)) + 1;
                    $final_attachment = $attachment_path ?: $existing_inv['attachment_path'];

                    $upd_stmt = $pdo->prepare("
                        UPDATE invoices
                        SET invoice_number = :num,
                            invoice_date = :idate,
                            due_date = :ddate,
                            subtotal = :sub,
                            tax_amount = :tax,
                            total_amount = :tot,
                            attachment_path = :att,
                            version = :ver,
                            status = 'submitted',
                            correction_notes = NULL
                        WHERE id = :id
                    ");
                    $upd_stmt->execute([
                        ':num'   => $inv_num,
                        ':idate' => $inv_date,
                        ':ddate' => $due_date,
                        ':sub'   => $subtotal,
                        ':tax'   => $tax,
                        ':tot'   => $total,
                        ':att'   => $final_attachment,
                        ':ver'   => $new_version,
                        ':id'    => $invoice_id,
                    ]);

                    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = :id')->execute([':id' => $invoice_id]);
                    $li_stmt = $pdo->prepare('
                        INSERT INTO invoice_items (invoice_id, requisition_item_id, item_name, qty, unit_price, line_total)
                        VALUES (:inv, :ri, :n, :q, :p, :lt)
                    ');
                    foreach ($line_data as $l) {
                        $li_stmt->execute([
                            ':inv' => $invoice_id, ':ri' => $l['requisition_item_id'], ':n' => $l['item_name'],
                            ':q' => $l['qty'], ':p' => $l['unit_price'], ':lt' => $l['line_total'],
                        ]);
                    }

                    $pdo->commit();
                    audit_log('invoice', $invoice_id, 'resubmitted', "Supplier {$supplier['name']} resubmitted invoice {$inv_num} (v{$new_version}) after corrections — " . php_currency($total));
                    notify_role_by_permission(
                        'procurement.invoice.match', 'invoice_resubmitted',
                        "Invoice {$inv_num} (v{$new_version}) Resubmitted",
                        $supplier['name'] . " resubmitted invoice {$inv_num} (v{$new_version}) for PO #{$po_id}. Ready for 3-way match.",
                        'three_way_match.php?invoice_id=' . $invoice_id
                    );
                    $toast = "Invoice {$inv_num} (v{$new_version}) resubmitted successfully — awaiting 3-way match.";
                    header('Location: supplier_portal.php?tab=invoices&toast=' . urlencode($toast));
                    exit;

                } else {
                    // New Invoice creation
                    $po_stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id AND supplier_id = :s');
                    $po_stmt->execute([':id' => $po_id, ':s' => $supplier['id']]);
                    $po = $po_stmt->fetch();
                    if (!$po) throw new Exception('Purchase order not found.');

                    // Calculate subtotal from submitted lines
                    $subtotal = 0.0;
                    $line_data = [];
                    foreach ($lines as $ri_id => $l) {
                        $qty   = (float)($l['qty'] ?? 0);
                        $price = (float)($l['unit_price'] ?? 0);
                        if ($qty > 0) {
                            $ri_stmt = $pdo->prepare('SELECT item_name FROM requisition_items WHERE id = :id');
                            $ri_stmt->execute([':id' => (int)$ri_id]);
                            $item_name = $ri_stmt->fetchColumn() ?: 'Item';
                            $lt = round($qty * $price, 2);
                            $subtotal += $lt;
                            $line_data[] = [
                                'requisition_item_id' => (int)$ri_id,
                                'item_name'           => $item_name,
                                'qty'                 => $qty,
                                'unit_price'          => $price,
                                'line_total'          => $lt,
                            ];
                        }
                    }

                    if (empty($line_data)) {
                        throw new Exception('Enter a quantity for at least one line item.');
                    }

                    $total = round($subtotal + $tax, 2);

                    $inv_stmt = $pdo->prepare(
                        'INSERT INTO invoices (po_id, supplier_id, invoice_number, invoice_date, due_date, subtotal, tax_amount, total_amount, attachment_path, version, status, uploaded_by)
                         VALUES (:po, :sup, :num, :idate, :ddate, :sub, :tax, :tot, :att, 1, \'submitted\', :u)'
                    );
                    $inv_stmt->execute([
                        ':po' => $po_id, ':sup' => $supplier['id'], ':num' => $inv_num, ':idate' => $inv_date, ':ddate' => $due_date,
                        ':sub' => $subtotal, ':tax' => $tax, ':tot' => $total, ':att' => $attachment_path, ':u' => $user['id'],
                    ]);
                    $invoice_id = (int)$pdo->lastInsertId();

                    $li_stmt = $pdo->prepare(
                        'INSERT INTO invoice_items (invoice_id, requisition_item_id, item_name, qty, unit_price, line_total)
                         VALUES (:inv, :ri, :n, :q, :p, :lt)'
                    );
                    foreach ($line_data as $l) {
                        $li_stmt->execute([
                            ':inv' => $invoice_id, ':ri' => $l['requisition_item_id'], ':n' => $l['item_name'],
                            ':q' => $l['qty'], ':p' => $l['unit_price'], ':lt' => $l['line_total'],
                        ]);
                    }

                    $pdo->commit();
                    audit_log('invoice', $invoice_id, 'created', $supplier['name'] . " submitted invoice {$inv_num} for PO #$po_id — " . php_currency($total));
                    notify_role_by_permission(
                        'procurement.invoice.match', 'invoice_created',
                        "Invoice {$inv_num} submitted for PO #$po_id",
                        $supplier['name'] . ' submitted an invoice — ready for 3-way match.',
                        'three_way_match.php?invoice_id=' . $invoice_id
                    );
                    $toast = 'Invoice submitted — awaiting match and approval.';
                    header('Location: supplier_portal.php?tab=invoices&toast=' . urlencode($toast));
                    exit;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    $ret_tab = $_POST['tab'] ?? ($_GET['tab'] ?? 'rfqs');
    header('Location: supplier_portal.php?tab=' . urlencode($ret_tab) . ($toast ? '&toast=' . urlencode($toast) . '&type=' . $toast_type : ''));
    exit;
}

if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

$active_tab = $_GET['tab'] ?? 'rfqs';

// ── Data for the views below (only if this login has a supplier profile) ──
$open_invites = [];
$my_bids = [];
$my_contracts = [];
$contract_items_map = [];
$my_pos = [];
$my_letters = [];
$my_invoices = [];
$my_invoice_items = [];
$breakdown = null;
$ratings = [];

if ($supplier) {
    // 1. Contracts assigned to this supplier
    $cnt_stmt = $pdo->prepare("
        SELECT c.*, pr.title AS req_title, pr.department,
               rfq.rfq_ref,
               u_buyer.firstname AS buyer_fname, u_buyer.lastname AS buyer_lname
        FROM purchase_contracts c
        JOIN purchase_requisitions pr ON pr.id = c.requisition_id
        LEFT JOIN rfqs rfq ON rfq.id = c.rfq_id
        LEFT JOIN users u_buyer ON u_buyer.id = c.buyer_signed_by
        WHERE c.supplier_id = :s
        ORDER BY FIELD(c.status, 'sent_to_supplier', 'fully_signed', 'po_created'), c.created_at DESC
    ");
    $cnt_stmt->execute([':s' => $supplier['id']]);
    $my_contracts = $cnt_stmt->fetchAll();

    if (!empty($my_contracts)) {
        $c_ids = array_column($my_contracts, 'id');
        $in_c = implode(',', array_map('intval', $c_ids));
        $ci_stmt = $pdo->query("SELECT * FROM purchase_contract_items WHERE contract_id IN ($in_c) ORDER BY id ASC");
        while ($ci = $ci_stmt->fetch(PDO::FETCH_ASSOC)) {
            $contract_items_map[$ci['contract_id']][] = $ci;
        }
    }

    // 2. Official procurement letters
    $let_stmt = $pdo->prepare("
        SELECT pl.*, pr.title AS req_title, pr.department, pr.estimated_total
        FROM procurement_letters pl
        JOIN purchase_requisitions pr ON pr.id = pl.requisition_id
        WHERE pl.supplier_id = :s
        ORDER BY FIELD(pl.status, 'sent', 'acknowledged'), pl.sent_at DESC
    ");
    $let_stmt->execute([':s' => $supplier['id']]);
    $my_letters = $let_stmt->fetchAll();

    // 3. RFQs invited to
    $inv_stmt = $pdo->prepare("
        SELECT rfqs.*,
               pr.title AS req_title, pr.department, pr.estimated_total,
               b.id AS bid_id, b.quoted_total, b.lead_time_days, b.notes AS bid_notes, b.status AS bid_status
        FROM rfq_invites ri
        JOIN rfqs ON rfqs.id = ri.rfq_id
        JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        LEFT JOIN bids b ON b.rfq_id = rfqs.id AND b.supplier_id = ri.supplier_id
        WHERE ri.supplier_id = :s AND rfqs.status = 'open'
        ORDER BY rfqs.due_date IS NULL, rfqs.due_date ASC
    ");
    $inv_stmt->execute([':s' => $supplier['id']]);
    $open_invites = $inv_stmt->fetchAll();

    // 4. Bid history
    $bid_stmt = $pdo->prepare("
        SELECT b.*, rfqs.status AS rfq_status, rfqs.rfq_ref, rfqs.due_date, pr.title AS req_title
        FROM bids b
        JOIN rfqs ON rfqs.id = b.rfq_id
        JOIN purchase_requisitions pr ON pr.id = rfqs.requisition_id
        WHERE b.supplier_id = :s
        ORDER BY b.submitted_at DESC
    ");
    $bid_stmt->execute([':s' => $supplier['id']]);
    $my_bids = $bid_stmt->fetchAll();

    // 5. Purchase Orders
    $po_stmt = $pdo->prepare("
        SELECT po.*, pr.title AS req_title, pr.department,
               c.contract_ref, c.title AS contract_title, c.status AS contract_status,
               (SELECT status FROM goods_receipts g WHERE g.po_id = po.id ORDER BY g.received_at DESC LIMIT 1) AS grn_status,
               (SELECT status FROM invoices i WHERE i.po_id = po.id AND i.status != 'cancelled' ORDER BY i.created_at DESC LIMIT 1) AS invoice_status,
               (SELECT id FROM invoices i WHERE i.po_id = po.id AND i.status != 'cancelled' ORDER BY i.created_at DESC LIMIT 1) AS invoice_id
        FROM purchase_orders po
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        LEFT JOIN purchase_contracts c ON c.id = po.contract_id
        WHERE po.supplier_id = :s
        ORDER BY FIELD(po.status,'sent','acknowledged','delivered','draft','closed','cancelled'), po.created_at DESC
    ");
    $po_stmt->execute([':s' => $supplier['id']]);
    $my_pos = $po_stmt->fetchAll();

    // PO line items & Invoicing line items
    $po_items_map = [];
    $invoiceable_items = [];
    foreach ($my_pos as $p) {
        $items = [];
        if (!empty($p['contract_id'])) {
            $c_items = $pdo->prepare('SELECT * FROM purchase_contract_items WHERE contract_id = :cid');
            $c_items->execute([':cid' => $p['contract_id']]);
            $items = $c_items->fetchAll();
        }
        if (empty($items)) {
            $ri_stmt = $pdo->prepare('SELECT *, est_unit_price AS unit_price, (quantity * est_unit_price) AS line_total FROM requisition_items WHERE requisition_id = :rid');
            $ri_stmt->execute([':rid' => $p['requisition_id']]);
            $items = $ri_stmt->fetchAll();
        }
        $po_items_map[$p['id']] = $items;

        if ($p['status'] === 'delivered' && (!$p['invoice_id'] || in_array($p['invoice_status'], ['needs_correction', 'cancelled'], true))) {
            $invoiceable_items[$p['id']] = $items;
        }
    }

    // Delivery notices / ASNs submitted by this supplier
    $dn_stmt = $pdo->prepare("
        SELECT dn.*, 
               (SELECT COUNT(*) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS item_count,
               (SELECT COALESCE(SUM(dni.shipped_qty), 0) FROM delivery_notice_items dni WHERE dni.delivery_notice_id = dn.id) AS total_shipped_qty
        FROM delivery_notices dn
        WHERE dn.supplier_id = :s
        ORDER BY dn.shipped_date DESC, dn.created_at DESC
    ");
    $dn_stmt->execute([':s' => $supplier['id']]);
    $all_dns = $dn_stmt->fetchAll();
    $po_dns_map = [];
    foreach ($all_dns as $dn) {
        $po_dns_map[$dn['po_id']][] = $dn;
    }

    // 6. Own performance scorecard
    $b = $pdo->prepare('
        SELECT AVG(quality_score) AS quality, AVG(timeliness_score) AS timeliness,
               AVG(price_score) AS price, AVG(communication_score) AS communication
        FROM supplier_performance_ratings WHERE supplier_id = :s
    ');
    $b->execute([':s' => $supplier['id']]);
    $breakdown = $b->fetch();

    $r = $pdo->prepare('
        SELECT spr.*, po.id AS po_id
        FROM supplier_performance_ratings spr
        JOIN purchase_orders po ON po.id = spr.po_id
        WHERE spr.supplier_id = :s ORDER BY spr.created_at DESC LIMIT 10
    ');
    $r->execute([':s' => $supplier['id']]);
    $ratings = $r->fetchAll();

    // 7. Invoices & Payments for this supplier
    $inv_stmt = $pdo->prepare("
        SELECT i.*, po.requisition_id, pr.title AS req_title,
               (SELECT COUNT(*) FROM payments py WHERE py.invoice_id = i.id AND py.status = 'completed') AS paid_count,
               (SELECT COALESCE(SUM(py.amount), 0) FROM payments py WHERE py.invoice_id = i.id AND py.status = 'completed') AS amount_paid
        FROM invoices i
        JOIN purchase_orders po ON po.id = i.po_id
        JOIN purchase_requisitions pr ON pr.id = po.requisition_id
        WHERE i.supplier_id = :s
        ORDER BY i.created_at DESC
    ");
    $inv_stmt->execute([':s' => $supplier['id']]);
    $my_invoices = $inv_stmt->fetchAll();

    $my_invoice_items = [];
    foreach ($my_invoices as $inv) {
        $iis = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id ASC");
        $iis->execute([':id' => $inv['id']]);
        $my_invoice_items[$inv['id']] = $iis->fetchAll();
    }
}

$pending_cnt_count = count(array_filter($my_contracts, fn($c) => $c['status'] === 'sent_to_supplier'));
$pending_ack_count = count(array_filter($my_letters, fn($l) => $l['status'] === 'sent'));
$needs_corr_count  = count(array_filter($my_invoices, fn($i) => $i['status'] === 'needs_correction'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Supplier Portal — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .invite-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-bottom:12px; }
    .invite-card.quoted { border-color:var(--green); background:var(--green-lt); }
    .po-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:14px 18px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; }
    .score-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px dashed var(--border); }
    .score-row:last-child { border-bottom:none; }
    .breakdown-bar-wrap { background:#f2e6d6; border-radius:999px; height:8px; overflow:hidden; flex:1; margin:0 10px; }
    .breakdown-bar-fill { height:100%; background:var(--caramel, #c47d3e); }
    .portal-section-title { font-size:14px; font-weight:800; margin:20px 0 10px; }
    .portal-section-title:first-child { margin-top:0; }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }
    .sig-box-preview { border:1.5px dashed var(--border); border-radius:8px; padding:12px; background:#FAF5EE; min-height:100px; display:flex; flex-direction:column; justify-content:space-between; }
    .item-schedule-table { width:100%; border-collapse:collapse; margin-top:6px; font-size:12px; }
    .item-schedule-table th { background:#f5efe6; color:var(--espresso); padding:6px 10px; font-weight:700; text-align:left; border-bottom:1.5px solid var(--border); }
    .item-schedule-table td { padding:7px 10px; border-bottom:1px solid #f0ebe4; }
  </style>
</head>
<body>
<?php include("../includes/sidebar.php"); ?>

<div id="page-supplier-portal" class="page active">
  <div class="page-header">
    <div>
      <h1>Supplier Portal</h1>
      <p><?= $supplier ? 'Welcome, ' . htmlspecialchars($supplier['name']) : 'Quote on RFQs, countersign contracts, acknowledge orders, and track your standing' ?></p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:12px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if (!$supplier): ?>
      <div class="table-card" style="padding:22px">
        <p>Your account isn't linked to a supplier profile yet. Ask a Procurement Officer to connect your login on the <strong>Suppliers</strong> page before you can quote or track orders here.</p>
      </div>

    <?php else: ?>

      <!-- ── Top Tab Switcher ── -->
      <div class="filter-bar" style="margin-bottom:18px;display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="filter-pill <?= $active_tab === 'rfqs' ? 'active' : '' ?>" onclick="switchTab('rfqs')">
          <?= icon('rfq', 14) ?> RFQ Invitations &amp; Bids (<?= count($open_invites) ?>)
        </button>
        <button type="button" class="filter-pill <?= $active_tab === 'contracts' ? 'active' : '' ?>" onclick="switchTab('contracts')">
          <?= icon('file-text', 14) ?> Contracts
          <?php if ($pending_cnt_count > 0): ?>
            <span style="background:var(--red);color:#fff;padding:1px 7px;border-radius:10px;font-size:10.5px;font-weight:700;margin-left:4px"><?= $pending_cnt_count ?> Sign</span>
          <?php else: ?>
            (<?= count($my_contracts) ?>)
          <?php endif; ?>
        </button>
        <button type="button" class="filter-pill <?= $active_tab === 'orders' ? 'active' : '' ?>" onclick="switchTab('orders')">
          <?= icon('truck', 14) ?> Purchase Orders (<?= count($my_pos) ?>)
        </button>
        <button type="button" class="filter-pill <?= $active_tab === 'letters' ? 'active' : '' ?>" onclick="switchTab('letters')">
          <?= icon('clipboard', 14) ?> Award Letters
          <?php if ($pending_ack_count > 0): ?>
            <span style="background:var(--caramel);color:#fff;padding:1px 7px;border-radius:10px;font-size:10.5px;font-weight:700;margin-left:4px"><?= $pending_ack_count ?> New</span>
          <?php else: ?>
            (<?= count($my_letters) ?>)
          <?php endif; ?>
        </button>
        <button type="button" class="filter-pill <?= $active_tab === 'invoices' ? 'active' : '' ?>" onclick="switchTab('invoices')">
          <?= icon('invoice', 14) ?> Invoices &amp; Payments
          <?php if ($needs_corr_count > 0): ?>
            <span style="background:var(--red);color:#fff;padding:1px 7px;border-radius:10px;font-size:10.5px;font-weight:700;margin-left:4px"><?= $needs_corr_count ?> Action Needed</span>
          <?php else: ?>
            (<?= count($my_invoices) ?>)
          <?php endif; ?>
        </button>
        <button type="button" class="filter-pill <?= $active_tab === 'performance' ? 'active' : '' ?>" onclick="switchTab('performance')">
          <?= icon('star', 14) ?> My Scorecard &amp; Ratings
        </button>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 1: RFQS & BIDS                             -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-rfqs" class="tab-pane <?= $active_tab === 'rfqs' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px">
          <?= icon('message', 16, '', 'color:var(--caramel)') ?> Open RFQ Invitations <?= count($open_invites) ? '(' . count($open_invites) . ')' : '' ?>
        </h3>
        <?php if (empty($open_invites)): ?>
          <p class="muted-cell" style="margin-bottom:14px">No open RFQs waiting on a quote from you right now.</p>
        <?php else: foreach ($open_invites as $inv): 
          $can_quote = can_supplier_bid($inv);
        ?>
          <div class="invite-card <?= $inv['bid_id'] && $inv['bid_status'] !== 'withdrawn' ? 'quoted' : '' ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;gap:8px">
              <div>
                <strong style="font-size:15px;color:var(--espresso)"><?= htmlspecialchars($inv['req_title']) ?></strong>
                <p class="muted-cell">
                  <?= htmlspecialchars($inv['department']) ?> · Est. <?= php_currency($inv['estimated_total']) ?> ·
                  <?php if ($inv['due_date']): ?>
                    <?php if ($can_quote['is_expired']): ?>
                      <span class="status-badge status-rejected" style="font-size:11px"><?= icon('x', 11) ?> Expired on <?= date('M d, Y', strtotime($inv['due_date'])) ?></span>
                    <?php else: ?>
                      <span class="status-badge" style="background:#e6f4ea;color:#137333;font-size:11px"><?= icon('clock', 11) ?> Due <?= date('M d, Y', strtotime($inv['due_date'])) ?> (<?= $can_quote['days_left'] ?>d left)</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span>No deadline set</span>
                  <?php endif; ?>
                </p>
              </div>
              <div style="display:flex;gap:6px;align-items:center">
                <?php if ($inv['bid_id']): ?>
                  <span class="status-badge status-<?= $inv['bid_status']==='withdrawn'?'rejected':($inv['bid_status']==='shortlisted'?'pending':'approved') ?>">
                    <?= status_badge($inv['bid_status']) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($can_quote['can_bid']): ?>
              <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <input type="hidden" name="action" value="submit_bid"/>
                <input type="hidden" name="tab" value="rfqs"/>
                <input type="hidden" name="rfq_id" value="<?= $inv['id'] ?>"/>
                <div class="field-group" style="margin:0;max-width:150px">
                  <label class="field-label">Quoted Total (₱) <span style="color:var(--red)">*</span></label>
                  <input class="field-input" type="number" step="0.01" min="0.01" name="quoted_total" value="<?= $inv['quoted_total'] ?: '' ?>" required/>
                </div>
                <div class="field-group" style="margin:0;max-width:120px">
                  <label class="field-label">Lead Time (days)</label>
                  <input class="field-input" type="number" min="0" name="lead_time_days" value="<?= $inv['lead_time_days'] ?: 0 ?>"/>
                </div>
                <div class="field-group" style="margin:0;flex:1;min-width:160px">
                  <label class="field-label">Notes</label>
                  <input class="field-input" type="text" name="notes" value="<?= htmlspecialchars($inv['bid_notes'] ?? '') ?>" placeholder="Optional delivery notes"/>
                </div>
                <button type="submit" class="btn-save">
                  <?= $inv['bid_id'] && $inv['bid_status'] !== 'withdrawn' ? icon('edit', 14) . ' Update Quote' : icon('send', 14) . ' Submit Quote' ?>
                </button>
              </form>

              <?php if ($inv['bid_id'] && $inv['bid_status'] !== 'withdrawn'): ?>
                <div style="margin-top:8px;text-align:right">
                  <form method="POST" onsubmit="return confirm('Withdraw this submitted quotation?');" style="display:inline-block">
                    <input type="hidden" name="action" value="withdraw_bid"/>
                    <input type="hidden" name="bid_id" value="<?= $inv['bid_id'] ?>"/>
                    <button type="submit" class="act-btn" style="color:var(--red);font-size:11.5px"><?= icon('x', 11) ?> Withdraw Quote</button>
                  </form>
                </div>
              <?php endif; ?>

            <?php else: ?>
              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;color:#991b1b;font-size:12px">
                <?= icon('alert-circle', 13) ?> <?= htmlspecialchars($can_quote['reason']) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>

        <!-- Bid History Table -->
        <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px;margin-top:24px">
          <?= icon('clipboard', 16, '', 'color:var(--caramel)') ?> My Quotation History
        </h3>
        <div class="table-scroll-wrapper" style="margin-bottom:8px">
          <table>
            <thead><tr><th>Requisition</th><th>RFQ Ref</th><th>Quoted Amount</th><th>Lead Time</th><th>Status</th><th>Submitted</th><th style="text-align:center">Action</th></tr></thead>
            <tbody>
            <?php if (empty($my_bids)): ?>
              <tr class="empty-row"><td colspan="7"><?= icon('inbox', 18) ?> No quotes submitted yet.</td></tr>
            <?php else: foreach ($my_bids as $b): ?>
              <tr>
                <td style="font-weight:700"><?= htmlspecialchars($b['req_title']) ?></td>
                <td><?= htmlspecialchars($b['rfq_ref']) ?></td>
                <td style="font-weight:700;color:var(--espresso)">₱<?= number_format((float)$b['quoted_total'], 2) ?></td>
                <td><?= (int)$b['lead_time_days'] ?> day(s)</td>
                <td><span class="status-badge status-<?= $b['status']==='selected'?'approved':($b['status']==='withdrawn'||$b['status']==='rejected'?'rejected':'pending') ?>"><?= status_badge($b['status']) ?></span></td>
                <td class="muted-cell"><?= date('M d, Y', strtotime($b['submitted_at'])) ?></td>
                <td style="text-align:center">
                  <?php if (!in_array($b['status'], ['withdrawn','selected','rejected'], true) && (strtotime($b['due_date']) >= strtotime(date('Y-m-d')))): ?>
                    <form method="POST" onsubmit="return confirm('Withdraw this quotation?');">
                      <input type="hidden" name="action" value="withdraw_bid"/>
                      <input type="hidden" name="bid_id" value="<?= $b['id'] ?>"/>
                      <button type="submit" class="act-btn" style="color:var(--red);font-size:11px"><?= icon('x', 11) ?> Withdraw</button>
                    </form>
                  <?php else: ?>
                    <span class="muted-cell" style="font-size:11px">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 2: PURCHASE CONTRACTS & COUNTERSIGNATURES   -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-contracts" class="tab-pane <?= $active_tab === 'contracts' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;justify-content:space-between">
          <span style="display:inline-flex;align-items:center;gap:6px">
            <?= icon('file-text', 16, '', 'color:var(--caramel)') ?>
            Purchase Contracts &amp; Supply Agreements <?= count($my_contracts) ? '(' . count($my_contracts) . ')' : '' ?>
          </span>
          <?php if ($pending_cnt_count > 0): ?>
            <span class="status-badge status-pending" style="font-size:11px">
              <?= $pending_cnt_count ?> Awaiting Your Countersignature
            </span>
          <?php endif; ?>
        </h3>

        <?php if (empty($my_contracts)): ?>
          <p class="muted-cell" style="margin-bottom:14px">No purchase contracts have been issued to your profile yet.</p>
        <?php else: foreach ($my_contracts as $cnt): 
          $c_items = $contract_items_map[$cnt['id']] ?? [];
        ?>
          <div class="po-card" style="border-color:<?= $cnt['status']==='sent_to_supplier' ? '#E67E22' : 'var(--border)' ?>;background:<?= $cnt['status']==='sent_to_supplier' ? '#FDF8F2' : '#FFFFFF' ?>;flex-direction:column;align-items:stretch;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
              <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <strong style="font-size:15px;color:var(--espresso)"><?= htmlspecialchars($cnt['title']) ?></strong>
                  <span style="font-family:monospace;font-size:12px;font-weight:700;background:#EFE0CC;padding:2px 6px;border-radius:4px;color:var(--espresso)">
                    <?= htmlspecialchars($cnt['contract_ref']) ?>
                  </span>
                  <?php if ($cnt['status'] === 'sent_to_supplier'): ?>
                    <span class="status-badge status-pending" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba">
                      <?= icon('clock', 11) ?> Awaiting Countersignature
                    </span>
                  <?php elseif ($cnt['status'] === 'fully_signed'): ?>
                    <span class="status-badge status-approved">
                      <?= icon('check', 11) ?> Fully Signed &amp; Legally Binding
                    </span>
                  <?php elseif ($cnt['status'] === 'po_created'): ?>
                    <span class="status-badge status-approved" style="background:#e8f4fd;color:#0b5394">
                      <?= icon('check', 11) ?> Purchase Order Issued
                    </span>
                  <?php endif; ?>
                </div>

                <p class="muted-cell" style="margin:4px 0 0">
                  Total Contract Obligation: <strong style="color:var(--espresso)">₱<?= number_format((float)$cnt['total_amount'], 2) ?></strong> ·
                  Department: <?= htmlspecialchars(ucfirst($cnt['department'])) ?> ·
                  Buyer Officer: <?= htmlspecialchars($cnt['buyer_fname'] . ' ' . $cnt['buyer_lname']) ?>
                </p>

                <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">
                  <strong>Delivery:</strong> <?= htmlspecialchars($cnt['delivery_terms']) ?> ·
                  <strong>Payment:</strong> <?= htmlspecialchars($cnt['payment_terms']) ?>
                </p>

                <?php if ($cnt['status'] === 'fully_signed' || $cnt['status'] === 'po_created'): ?>
                  <p style="font-size:12px;color:#27AE60;margin:6px 0 0;font-weight:600">
                    <?= icon('check', 12) ?> Countersigned by <?= htmlspecialchars($cnt['supplier_signed_name']) ?> on <?= date('M d, Y h:i A', strtotime($cnt['supplier_signed_at'])) ?>
                  </p>
                <?php endif; ?>
              </div>

              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button type="button" class="btn-save" style="padding:7px 14px;font-size:12.5px;<?= $cnt['status']==='sent_to_supplier'?'background:var(--caramel)':'' ?>" onclick='openContractModal(<?= htmlspecialchars(json_encode($cnt), ENT_QUOTES) ?>)'>
                  <?= $cnt['status'] === 'sent_to_supplier' ? icon('edit', 14) . ' Review &amp; Countersign' : icon('eye', 14) . ' View Signed Agreement' ?>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 3: PURCHASE ORDERS                         -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-orders" class="tab-pane <?= $active_tab === 'orders' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px">
          <?= icon('package', 16, '', 'color:var(--caramel)') ?> Purchase Orders &amp; Delivery Tracking
        </h3>
        <?php if (empty($my_pos)): ?>
          <p class="muted-cell" style="margin-bottom:8px">No purchase orders yet.</p>
        <?php else: foreach ($my_pos as $p): ?>
          <div class="po-card" id="po-card-<?= $p['id'] ?>" style="flex-direction:column;align-items:stretch">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
              <div>
                <strong><?= htmlspecialchars($p['po_number'] ?: ('# ' . str_pad($p['id'],5,'0',STR_PAD_LEFT))) ?> — <?= htmlspecialchars($p['req_title']) ?></strong>
                <?php if (!empty($p['contract_ref'])): ?>
                  <span class="muted-cell" style="margin-left:8px;font-size:12px;color:var(--espresso)">Contract: <strong><?= htmlspecialchars($p['contract_ref']) ?></strong></span>
                <?php endif; ?>
                <p class="muted-cell"><?= htmlspecialchars($p['department']) ?> · <?= php_currency($p['total_amount']) ?><?= $p['expected_delivery_date'] ? ' · Expected ' . date('M d, Y', strtotime($p['expected_delivery_date'])) : '' ?></p>
                <p style="margin-top:6px;font-size:12px;display:flex;align-items:center;flex-wrap:wrap;gap:6px">
                  <span class="status-badge status-<?= in_array($p['status'],['closed','delivered'])?'approved':($p['status']==='cancelled'?'rejected':'pending') ?>">
                    <?= $p['status'] === 'sent' ? 'Awaiting Acknowledgment' : status_badge($p['status']) ?>
                  </span>
                  <?php if ($p['acknowledged_at'] && $p['status'] !== 'sent'): ?>
                    <span class="status-badge status-approved" style="display:inline-flex;align-items:center;gap:4px"><?= icon('check', 11) ?> Ack <?= date('M d', strtotime($p['acknowledged_at'])) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($p['fulfillment_status'])): ?>
                    <?php
                      $f_bg = '#EDF2F7'; $f_color = '#4A5568';
                      if ($p['fulfillment_status'] === 'delivered') {
                          $f_bg = '#DEF7EC'; $f_color = '#03543F';
                      } elseif ($p['fulfillment_status'] === 'shipped') {
                          $f_bg = '#EBF8FF'; $f_color = '#2B6CB0';
                      } elseif ($p['fulfillment_status'] === 'partially_shipped') {
                          $f_bg = '#FEFCBF'; $f_color = '#744210';
                      }
                    ?>
                    <span class="status-badge" style="background:<?= $f_bg ?>;color:<?= $f_color ?>;border:1px solid <?= $f_color ?>44;font-weight:600;display:inline-flex;align-items:center;gap:4px">
                      <?= icon('truck', 11) ?> Fulfillment: <?= ucwords(str_replace('_', ' ', $p['fulfillment_status'])) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($p['issue_status'] === 'open'): ?>
                    <span class="status-badge" style="background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5;font-weight:700;display:inline-flex;align-items:center;gap:4px"><?= icon('alert-triangle', 11) ?> Issue Under Review</span>
                  <?php elseif ($p['issue_status'] === 'resolved'): ?>
                    <span class="status-badge" style="background:#DEF7EC;color:#03543F;border:1px solid #BCF0DA;font-weight:700;display:inline-flex;align-items:center;gap:4px"><?= icon('check', 11) ?> Issue Resolved</span>
                  <?php endif; ?>
                  <?php if ($p['shipped_at']): ?><span class="status-badge status-pending" style="display:inline-flex;align-items:center;gap:4px"><?= icon('truck', 12) ?> Shipped <?= date('M d, Y', strtotime($p['shipped_at'])) ?></span><?php endif; ?>
                  <?php if ($p['grn_status']): ?><span class="status-badge status-pending">GRN: <?= status_badge($p['grn_status']) ?></span><?php endif; ?>
                  <?php if ($p['invoice_status']): ?><span class="status-badge status-<?= in_array($p['invoice_status'],['approved','matched','paid'])?'approved':(in_array($p['invoice_status'],['disputed','needs_correction','cancelled'])?'rejected':'pending') ?>">Invoice: <?= status_badge($p['invoice_status']) ?></span><?php endif; ?>
                  <?php if ($p['paid_at']): ?><span class="status-badge status-approved" style="display:inline-flex;align-items:center;gap:4px"><?= icon('dollar', 12) ?> Paid <?= date('M d, Y', strtotime($p['paid_at'])) ?></span><?php endif; ?>
                </p>
                <?php if ($p['shipping_notes']): ?><p class="muted-cell" style="margin-top:4px;font-style:italic;display:flex;align-items:center;gap:4px"><?= icon('truck', 12) ?> "<?= htmlspecialchars($p['shipping_notes']) ?>"</p><?php endif; ?>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button type="button" class="act-btn" onclick="togglePoItems(<?= $p['id'] ?>)"><?= icon('package', 13) ?> Items (<?= count($po_items_map[$p['id']] ?? []) ?>)</button>
                <?php if ($p['status'] === 'sent'): ?>
                  <form method="POST"><input type="hidden" name="action" value="acknowledge_po"/><input type="hidden" name="tab" value="orders"/><input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
                    <button type="submit" class="act-btn act-activate"><?= icon('check', 13) ?> Acknowledge Order</button></form>
                  <?php if ($p['issue_status'] !== 'open'): ?>
                    <button type="button" class="act-btn act-suspend" style="color:var(--red);border-color:#FCA5A5" onclick="openIssueModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['po_number'] ?: ('KM-PO-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT))) ?>', '<?= htmlspecialchars($p['contract_ref'] ?? '') ?>')"><?= icon('alert-triangle', 13) ?> Raise an Issue</button>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if (in_array($p['status'],['sent','acknowledged'],true) && $p['fulfillment_status'] !== 'delivered'): ?>
                  <?php if ($p['status'] === 'acknowledged' && $p['issue_status'] !== 'open'): ?>
                    <button type="button" class="act-btn act-suspend" style="color:var(--red);border-color:#FCA5A5" onclick="openIssueModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['po_number'] ?: ('KM-PO-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT))) ?>', '<?= htmlspecialchars($p['contract_ref'] ?? '') ?>')"><?= icon('alert-triangle', 13) ?> Raise an Issue</button>
                  <?php endif; ?>
                  <button type="button" class="act-btn act-activate" style="background:var(--caramel);color:#fff;border-color:var(--caramel)" onclick='openAsnModal(<?= $p['id'] ?>, <?= json_encode($p['po_number'] ?: ('KM-PO-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT))) ?>, <?= htmlspecialchars(json_encode($po_items_map[$p['id']] ?? []), ENT_QUOTES) ?>)'><?= icon('truck', 13) ?> Dispatch Notice (ASN)</button>
                  <button type="button" class="act-btn" onclick="toggleShipForm(<?= $p['id'] ?>)"><?= icon('check', 13) ?> Quick Shipped</button>
                <?php endif; ?>
                <?php if ($p['status'] === 'delivered' && (!$p['invoice_id'] || $p['invoice_status'] === 'cancelled')): ?>
                  <button type="button" class="act-btn act-activate" onclick="toggleInvoiceForm(<?= $p['id'] ?>)"><?= icon('invoice', 13) ?> Submit Invoice</button>
                <?php elseif ($p['invoice_status'] === 'needs_correction'): ?>
                  <button type="button" class="act-btn" style="color:var(--red);border-color:#FCA5A5;background:#FFF5F5" onclick="switchTab('invoices')"><?= icon('alert-triangle', 13) ?> Correction Needed</button>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($p['issue_status'] === 'open'): ?>
            <div style="background:#FFF5F5;border:1.5px solid #FEB2B2;border-left:4px solid var(--red);padding:10px 14px;border-radius:8px;margin-top:10px;font-size:12.5px">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
                <strong style="color:#C53030;display:flex;align-items:center;gap:4px"><?= icon('alert-triangle', 13) ?> Issue Raised to Management (Under Review)</strong>
                <span style="font-size:11px;color:var(--text-muted)"><?= date('M d, Y g:i A', strtotime($p['issue_raised_at'])) ?></span>
              </div>
              <p style="margin:6px 0 0;color:var(--text);background:#FFF;padding:8px 12px;border-radius:6px;border:1px solid #FED7D7;font-size:12px;line-height:1.4"><?= nl2br(htmlspecialchars($p['issue_notes'])) ?></p>
              <span style="font-size:11px;color:var(--text-muted);display:block;margin-top:4px">Management has been alerted. Once reviewed, resolution notes will appear here.</span>
            </div>
            <?php elseif ($p['issue_status'] === 'resolved'): ?>
            <div style="background:#F0FFF4;border:1px solid #C6F6D5;border-left:4px solid var(--green);padding:10px 14px;border-radius:8px;margin-top:10px;font-size:12.5px">
              <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
                <strong style="color:#22543D;display:flex;align-items:center;gap:4px"><?= icon('check', 13) ?> Issue Resolved by Management</strong>
                <?php if (!empty($p['issue_resolved_at'])): ?>
                  <span style="font-size:11px;color:var(--text-muted)"><?= date('M d, Y g:i A', strtotime($p['issue_resolved_at'])) ?></span>
                <?php endif; ?>
              </div>
              <p style="margin:4px 0 0;color:var(--text);font-size:12px;line-height:1.4"><?= nl2br(htmlspecialchars($p['issue_resolution_notes'] ?: 'Management resolved the reported issue.')) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($po_dns_map[$p['id']])): ?>
            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-left:4px solid var(--caramel);padding:10px 14px;border-radius:8px;margin-top:10px;font-size:12px">
              <div style="font-weight:700;color:var(--espresso);margin-bottom:6px;display:flex;align-items:center;justify-content:space-between">
                <span style="display:flex;align-items:center;gap:5px"><?= icon('truck', 13) ?> Advance Shipping Notices (<?= count($po_dns_map[$p['id']]) ?>)</span>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px">
                <?php foreach ($po_dns_map[$p['id']] as $dn): ?>
                  <div style="background:#FFF;border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                    <div>
                      <strong style="color:var(--caramel)"><?= htmlspecialchars($dn['notice_ref']) ?></strong>
                      <span style="margin-left:8px;color:var(--text-muted)">Carrier: <strong><?= htmlspecialchars($dn['carrier_name'] ?: 'N/A') ?></strong></span>
                      <?php if (!empty($dn['tracking_number'])): ?>
                        <span style="margin-left:8px;color:var(--text-muted)">Tracking: <code><?= htmlspecialchars($dn['tracking_number']) ?></code></span>
                      <?php endif; ?>
                      <span style="margin-left:8px;color:var(--text-muted)">Shipped: <?= date('M d, Y', strtotime($dn['shipped_date'])) ?></span>
                      <?php if (!empty($dn['expected_arrival_date'])): ?>
                        <span style="margin-left:8px;color:var(--text-muted)">ETA: <?= date('M d, Y', strtotime($dn['expected_arrival_date'])) ?></span>
                      <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                      <span class="status-badge" style="font-size:11px;background:#EDF2F7;color:#4A5568"><?= number_format((float)$dn['total_shipped_qty'], 2) ?> total unit(s)</span>
                      <span class="status-badge status-<?= $dn['fulfillment_status'] === 'delivered' ? 'approved' : 'pending' ?>" style="font-size:11px"><?= ucwords(str_replace('_', ' ', $dn['fulfillment_status'])) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Collapsible PO Line Items Schedule -->
            <div id="po-items-<?= $p['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
              <h4 style="font-size:12.5px;color:var(--espresso);margin-bottom:8px;display:flex;align-items:center;gap:6px">
                <?= icon('clipboard', 13) ?> Order Line Items &amp; Agreed Specifications
              </h4>
              <div class="table-scroll-wrapper" style="margin:0">
                <table style="width:100%;font-size:12px">
                  <thead>
                    <tr style="background:#FAF8F5">
                      <th style="text-align:left;padding:6px 10px">Item Name</th>
                      <th style="text-align:right;padding:6px 10px">Quantity</th>
                      <th style="text-align:left;padding:6px 10px">Unit</th>
                      <th style="text-align:right;padding:6px 10px">Unit Price</th>
                      <th style="text-align:right;padding:6px 10px">Line Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php 
                    $sub_tot = 0;
                    foreach (($po_items_map[$p['id']] ?? []) as $it): 
                      $lt = (float)($it['line_total'] ?? ((float)$it['quantity'] * (float)$it['unit_price']));
                      $sub_tot += $lt;
                    ?>
                    <tr>
                      <td style="padding:6px 10px;font-weight:600"><?= htmlspecialchars($it['item_name']) ?></td>
                      <td style="padding:6px 10px;text-align:right"><?= number_format((float)$it['quantity'], 2) ?></td>
                      <td style="padding:6px 10px"><?= htmlspecialchars($it['unit'] ?? 'pcs') ?></td>
                      <td style="padding:6px 10px;text-align:right">₱<?= number_format((float)$it['unit_price'], 2) ?></td>
                      <td style="padding:6px 10px;text-align:right;font-weight:700">₱<?= number_format($lt, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr style="font-weight:700;border-top:1.5px solid var(--border)">
                      <td colspan="4" style="text-align:right;padding:6px 10px">Total Agreed Amount:</td>
                      <td style="text-align:right;padding:6px 10px;color:var(--espresso)">₱<?= number_format($p['total_amount'], 2) ?></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>

            <?php if (in_array($p['status'],['sent','acknowledged'],true) && !$p['shipped_at']): ?>
            <form method="POST" id="ship-form-<?= $p['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);gap:8px;flex-wrap:wrap;align-items:flex-end">
              <input type="hidden" name="action" value="mark_shipped"/>
              <input type="hidden" name="tab" value="orders"/>
              <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
              <div class="field-group" style="margin:0;flex:1;min-width:200px">
                <label class="field-label">Tracking / Carrier Note (optional)</label>
                <input class="field-input" type="text" name="shipping_notes" placeholder="e.g. LBC, tracking #1234"/>
              </div>
              <button type="submit" class="btn-save"><?= icon('truck', 14) ?> Confirm Shipped</button>
            </form>
            <?php endif; ?>

            <?php if ($p['status'] === 'delivered' && (!$p['invoice_id'] || $p['invoice_status'] === 'cancelled')): ?>
            <form method="POST" id="invoice-form-<?= $p['id'] ?>" enctype="multipart/form-data" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
              <input type="hidden" name="action" value="submit_invoice"/>
              <input type="hidden" name="tab" value="orders"/>
              <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
              <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
                <div class="field-group" style="margin:0;max-width:160px">
                  <label class="field-label">Invoice Number <span style="color:var(--red)">*</span></label>
                  <input class="field-input" type="text" name="invoice_number" required/>
                </div>
                <div class="field-group" style="margin:0;max-width:150px">
                  <label class="field-label">Invoice Date</label>
                  <input class="field-input" type="date" name="invoice_date"/>
                </div>
                <div class="field-group" style="margin:0;max-width:150px">
                  <label class="field-label">Due Date</label>
                  <input class="field-input" type="date" name="due_date"/>
                </div>
                <div class="field-group" style="margin:0;max-width:130px">
                  <label class="field-label">Tax Amount (₱)</label>
                  <input class="field-input" type="number" step="0.01" min="0" name="tax_amount" value="0"/>
                </div>
                <div class="field-group" style="margin:0;min-width:200px">
                  <label class="field-label">Invoice File (PDF, JPG, PNG)</label>
                  <input class="field-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/>
                </div>
              </div>
              <div class="table-scroll-wrapper" style="margin-bottom:10px">
                <table style="width:100%;min-width:480px">
                  <thead><tr><th style="text-align:left;font-size:11.5px">Item</th><th style="text-align:left;font-size:11.5px">Ordered</th><th style="text-align:left;font-size:11.5px">Qty Invoiced</th><th style="text-align:left;font-size:11.5px">Unit Price (₱)</th></tr></thead>
                  <tbody>
                    <?php foreach (($invoiceable_items[$p['id']] ?? []) as $ri): ?>
                      <tr>
                        <td style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($ri['item_name']) ?></td>
                        <td style="font-size:12.5px" class="muted-cell"><?= number_format((float)$ri['quantity'],2) ?> <?= htmlspecialchars($ri['unit']) ?></td>
                        <td><input class="field-input" type="number" step="0.01" min="0" style="width:90px;padding:6px 8px" name="lines[<?= $ri['id'] ?>][qty]" value="<?= number_format((float)$ri['quantity'],2) ?>"/></td>
                        <td><input class="field-input" type="number" step="0.01" min="0" style="width:100px;padding:6px 8px" name="lines[<?= $ri['id'] ?>][unit_price]" value="<?= number_format((float)$ri['est_unit_price'],2) ?>"/></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div style="text-align:right"><button type="submit" class="btn-save"><?= icon('invoice', 14) ?> Submit Invoice</button></div>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 4: AWARD LETTERS                           -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-letters" class="tab-pane <?= $active_tab === 'letters' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;justify-content:space-between">
          <span style="display:inline-flex;align-items:center;gap:6px">
            <?= icon('file-text', 16, '', 'color:var(--caramel)') ?>
            Official Procurement Letters &amp; Order Authorizations <?= count($my_letters) ? '(' . count($my_letters) . ')' : '' ?>
          </span>
          <?php if ($pending_ack_count > 0): ?>
            <span class="status-badge status-pending" style="font-size:11px">
              <?= $pending_ack_count ?> Awaiting Acknowledgment
            </span>
          <?php endif; ?>
        </h3>

        <?php if (empty($my_letters)): ?>
          <p class="muted-cell" style="margin-bottom:18px">No procurement letters issued to your account yet.</p>
        <?php else: foreach ($my_letters as $let): ?>
          <div class="po-card" style="border-color:<?= $let['status']==='sent' ? '#E67E22' : 'var(--border)' ?>;background:<?= $let['status']==='sent' ? '#FDF8F2' : '#FFFFFF' ?>;flex-direction:column;align-items:stretch;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
              <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <strong style="font-size:15px;color:var(--espresso)"><?= htmlspecialchars($let['req_title']) ?></strong>
                  <span style="font-family:monospace;font-size:12px;font-weight:700;background:#EFE0CC;padding:2px 6px;border-radius:4px;color:var(--espresso)">
                    <?= htmlspecialchars($let['letter_ref']) ?>
                  </span>
                  <span class="status-badge <?= $let['status']==='acknowledged' ? 'status-approved' : 'status-pending' ?>">
                    <?= $let['status']==='acknowledged' ? 'Acknowledged' : 'Pending Acknowledgment' ?>
                  </span>
                </div>

                <p class="muted-cell" style="margin:4px 0 0">
                  Department: <?= htmlspecialchars($let['department']) ?> ·
                  Authorized Amount: <strong style="color:var(--espresso)"><?= php_currency((float)$let['estimated_total']) ?></strong> ·
                  Issued <?= date('M d, Y', strtotime($let['sent_at'])) ?> by <?= htmlspecialchars($let['approver_name']) ?> (<?= htmlspecialchars($let['approver_title']) ?>)
                </p>

                <?php if (!empty($let['delivery_terms'])): ?>
                  <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0">
                    <strong>Delivery Terms:</strong> <?= htmlspecialchars($let['delivery_terms']) ?>
                  </p>
                <?php endif; ?>

                <?php if ($let['status'] === 'acknowledged'): ?>
                  <p style="font-size:12px;color:#27AE60;margin:6px 0 0;font-weight:600">
                    <?= icon('check', 12) ?> Formally acknowledged on <?= date('M d, Y H:i', strtotime($let['acknowledged_at'])) ?>
                    <?= !empty($let['acknowledgement_notes']) ? ' — "' . htmlspecialchars($let['acknowledgement_notes']) . '"' : '' ?>
                  </p>
                <?php endif; ?>
              </div>

              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <a href="procurement_letter.php?id=<?= $let['id'] ?>" target="_blank" class="act-btn act-activate" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px">
                  <?= icon('eye', 13) ?> View Official Letter
                </a>
                <?php if ($let['status'] === 'sent'): ?>
                  <button type="button" class="btn-save" style="padding:6px 12px;font-size:12px;background:#27AE60" onclick="toggleAckForm(<?= $let['id'] ?>)">
                    <?= icon('check', 13) ?> Acknowledge Letter
                  </button>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($let['status'] === 'sent'): ?>
              <form method="POST" id="ack-form-<?= $let['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
                <input type="hidden" name="action" value="acknowledge_letter"/>
                <input type="hidden" name="tab" value="letters"/>
                <input type="hidden" name="letter_id" value="<?= $let['id'] ?>"/>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                  <div class="field-group" style="margin:0;flex:1;min-width:240px">
                    <label class="field-label">Confirmation / Estimated Delivery Note (optional)</label>
                    <input class="field-input" type="text" name="acknowledgement_notes" placeholder="e.g. Order received and scheduled for dispatch on Friday"/>
                  </div>
                  <button type="submit" class="btn-save" style="background:#27AE60">
                    <?= icon('check', 13) ?> Confirm Acknowledgment
                  </button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 5: INVOICES & PAYMENTS                      -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-invoices" class="tab-pane <?= $active_tab === 'invoices' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px">
          <?= icon('invoice', 16, '', 'color:var(--caramel)') ?> Invoices &amp; Payments (<?= count($my_invoices) ?>)
        </h3>

        <?php if ($needs_corr_count > 0): ?>
          <div style="background:#FFF3CD;border:1.5px solid #FFEBAA;border-radius:10px;padding:14px 18px;margin-bottom:18px;color:#856404;display:flex;align-items:center;gap:12px">
            <?= icon('alert-triangle', 22, '', 'color:#856404;flex-shrink:0') ?>
            <div>
              <div style="font-weight:700;font-size:13.5px">Action Needed on <?= $needs_corr_count ?> Invoice<?= $needs_corr_count > 1 ? 's' : '' ?></div>
              <div style="font-size:12px;margin-top:2px">Finance has requested corrections. Review the notes on each flagged invoice below, update line items or upload revised documents, and resubmit.</div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (empty($my_invoices)): ?>
          <div class="table-card" style="padding:32px;text-align:center;color:var(--text-muted)">
            <?= icon('inbox', 28) ?>
            <p style="margin:8px 0 0 0;font-size:13.5px">No invoices submitted yet.</p>
            <p style="font-size:12px;margin-top:4px">When your Purchase Orders are delivered, you can submit invoices from the <strong>Purchase Orders</strong> tab.</p>
          </div>
        <?php else: foreach ($my_invoices as $inv): ?>
          <div class="invite-card <?= $inv['status'] === 'needs_correction' ? 'quoted' : '' ?>" style="<?= $inv['status'] === 'needs_correction' ? 'border-color:#F59E0B;background:#FFFDF8' : '' ?>" id="inv-card-<?= $inv['id'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
              <div>
                <div style="display:flex;align-items:center;gap:8px">
                  <strong style="font-size:15px;color:var(--espresso)"><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                  <?php if (!empty($inv['version']) && $inv['version'] > 1): ?>
                    <span style="background:#e8f4fd;color:#0b72b9;font-weight:700;font-size:10.5px;padding:1px 6px;border-radius:4px">v<?= (int)$inv['version'] ?></span>
                  <?php endif; ?>
                </div>
                <p class="muted-cell" style="margin-top:3px">
                  PO #<?= str_pad($inv['po_id'], 5, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($inv['req_title']) ?> · Submitted <?= date('M d, Y', strtotime($inv['created_at'])) ?>
                </p>
              </div>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <?php if (!empty($inv['attachment_path'])): ?>
                  <a href="../<?= htmlspecialchars($inv['attachment_path']) ?>" target="_blank" class="act-btn" style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px">
                    <?= icon('file-text', 12) ?> View Invoice File
                  </a>
                <?php endif; ?>
                <span class="status-badge status-<?= in_array($inv['status'],['approved','matched','paid'])?'approved':(in_array($inv['status'],['disputed','needs_correction','cancelled'])?'rejected':'pending') ?>">
                  <?= status_badge($inv['status']) ?>
                </span>
              </div>
            </div>

            <!-- Totals strip -->
            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;padding:8px 12px;background:#F8F4EE;border-radius:8px;font-size:12.5px">
              <div>Subtotal: <strong><?= php_currency($inv['subtotal']) ?></strong></div>
              <div>Tax: <strong><?= php_currency($inv['tax_amount']) ?></strong></div>
              <div>Total: <strong style="color:var(--espresso)"><?= php_currency($inv['total_amount']) ?></strong></div>
              <?php if ($inv['paid_count'] > 0): ?>
                <div style="color:#27AE60">Paid: <strong><?= php_currency($inv['amount_paid']) ?></strong></div>
              <?php endif; ?>
            </div>

            <!-- Items list toggle & Resubmit Action -->
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <button type="button" class="act-btn" onclick="toggleInvoiceItems(<?= $inv['id'] ?>)" style="font-size:11.5px">
                <?= icon('package', 12) ?> Line Items (<?= count($my_invoice_items[$inv['id']] ?? []) ?>)
              </button>
              <?php if ($inv['status'] === 'needs_correction'): ?>
                <button type="button" class="act-btn act-activate" onclick="toggleResubmitForm(<?= $inv['id'] ?>)" style="background:var(--red);border-color:var(--red);color:#fff">
                  <?= icon('edit', 12) ?> Edit &amp; Resubmit Invoice
                </button>
              <?php endif; ?>
            </div>

            <!-- Items breakdown -->
            <div id="inv-items-<?= $inv['id'] ?>" style="display:none;margin-top:10px">
              <table class="item-schedule-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
                <tbody>
                  <?php foreach (($my_invoice_items[$inv['id']] ?? []) as $li): ?>
                    <tr>
                      <td><?= htmlspecialchars($li['item_name']) ?></td>
                      <td><?= number_format((float)$li['qty'], 2) ?></td>
                      <td><?= php_currency($li['unit_price']) ?></td>
                      <td style="font-weight:600"><?= php_currency($li['line_total']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if (!empty($inv['correction_notes'])): ?>
              <div style="margin-top:12px;padding:12px 14px;border-radius:8px;background:#FFF3CD;border:1px solid #FFEBAA;color:#856404;font-size:12.5px">
                <strong style="display:flex;align-items:center;gap:5px"><?= icon('alert-triangle', 13) ?> Correction Requested by Finance:</strong>
                <p style="margin:4px 0 0 0;line-height:1.4"><?= nl2br(htmlspecialchars($inv['correction_notes'])) ?></p>
              </div>
            <?php endif; ?>

            <?php if ($inv['status'] === 'needs_correction'): ?>
              <!-- Inline resubmission form -->
              <form method="POST" id="resubmit-form-<?= $inv['id'] ?>" enctype="multipart/form-data" style="display:none;margin-top:14px;padding:16px;background:#FEFAF4;border:1.5px dashed var(--caramel);border-radius:10px">
                <input type="hidden" name="action" value="submit_invoice"/>
                <input type="hidden" name="tab" value="invoices"/>
                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>"/>
                <input type="hidden" name="po_id" value="<?= $inv['po_id'] ?>"/>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                  <h4 style="margin:0;font-size:13.5px;color:var(--espresso)">
                    Resubmit Invoice #<?= htmlspecialchars($inv['invoice_number']) ?> (v<?= ((int)$inv['version'] ?: 1) + 1 ?>)
                  </h4>
                  <button type="button" class="btn-cancel" onclick="toggleResubmitForm(<?= $inv['id'] ?>)" style="padding:2px 8px;font-size:11px">Close</button>
                </div>

                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
                  <div class="field-group" style="margin:0;max-width:160px">
                    <label class="field-label">Invoice Number <span style="color:var(--red)">*</span></label>
                    <input class="field-input" type="text" name="invoice_number" value="<?= htmlspecialchars($inv['invoice_number']) ?>" required/>
                  </div>
                  <div class="field-group" style="margin:0;max-width:150px">
                    <label class="field-label">Invoice Date</label>
                    <input class="field-input" type="date" name="invoice_date" value="<?= htmlspecialchars($inv['invoice_date']) ?>"/>
                  </div>
                  <div class="field-group" style="margin:0;max-width:150px">
                    <label class="field-label">Due Date</label>
                    <input class="field-input" type="date" name="due_date" value="<?= htmlspecialchars($inv['due_date'] ?? '') ?>"/>
                  </div>
                  <div class="field-group" style="margin:0;max-width:130px">
                    <label class="field-label">Tax Amount (₱)</label>
                    <input class="field-input" type="number" step="0.01" min="0" name="tax_amount" value="<?= htmlspecialchars($inv['tax_amount']) ?>"/>
                  </div>
                  <div class="field-group" style="margin:0;flex:1;min-width:220px">
                    <label class="field-label">Revised Invoice Document (PDF, JPG, PNG)</label>
                    <input class="field-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"/>
                    <?php if (!empty($inv['attachment_path'])): ?>
                      <small style="color:var(--text-muted);font-size:11px;display:block;margin-top:2px">Current: <?= basename($inv['attachment_path']) ?></small>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="table-scroll-wrapper" style="margin-bottom:12px">
                  <table style="width:100%;min-width:480px">
                    <thead><tr><th style="text-align:left;font-size:11.5px">Item</th><th style="text-align:left;font-size:11.5px">Qty Invoiced</th><th style="text-align:left;font-size:11.5px">Unit Price (₱)</th></tr></thead>
                    <tbody>
                      <?php 
                      $curr_items = $my_invoice_items[$inv['id']] ?? [];
                      if (empty($curr_items)) {
                          $curr_items = $po_items_map[$inv['po_id']] ?? [];
                      }
                      foreach ($curr_items as $li): 
                        $ri_id = $li['requisition_item_id'] ?? $li['id'];
                      ?>
                        <tr>
                          <td style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($li['item_name']) ?></td>
                          <td><input class="field-input" type="number" step="0.01" min="0" style="width:100px;padding:6px 8px" name="lines[<?= $ri_id ?>][qty]" value="<?= (float)$li['qty'] ?>"/></td>
                          <td><input class="field-input" type="number" step="0.01" min="0" style="width:110px;padding:6px 8px" name="lines[<?= $ri_id ?>][unit_price]" value="<?= (float)$li['unit_price'] ?>"/></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:8px">
                  <button type="button" class="btn-cancel" onclick="toggleResubmitForm(<?= $inv['id'] ?>)">Cancel</button>
                  <button type="submit" class="btn-save"><?= icon('send', 13) ?> Resubmit Corrected Invoice</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════ -->
      <!-- TAB 6: MY PERFORMANCE                          -->
      <!-- ═══════════════════════════════════════════════ -->
      <div id="tab-performance" class="tab-pane <?= $active_tab === 'performance' ? 'active' : '' ?>">
        <h3 class="portal-section-title" style="display:flex;align-items:center;gap:6px">
          <?= icon('star', 16, '', 'fill:currentColor;color:var(--amber,#b45309)') ?> My Performance Scorecard
        </h3>
        <div class="table-card" style="padding:18px 20px;margin-bottom:8px">
          <p class="muted-cell" style="margin-bottom:12px"><?= $supplier['rating_count'] ?> rating(s) · Overall <?= $supplier['rating_avg'] ? number_format($supplier['rating_avg'],2) . '/5' : 'Not yet rated' ?></p>
          <?php if ($breakdown && $supplier['rating_count'] > 0): ?>
            <?php foreach (['quality'=>'Quality of Goods','timeliness'=>'On-Time Delivery','price'=>'Price Competitiveness','communication'=>'Responsiveness'] as $key => $label): $val = (float)$breakdown[$key]; ?>
              <div class="score-row">
                <span style="width:160px;font-size:12.5px;font-weight:600"><?= $label ?></span>
                <div class="breakdown-bar-wrap"><div class="breakdown-bar-fill" style="width:<?= $val/5*100 ?>%"></div></div>
                <span style="font-size:12.5px;font-weight:700"><?= number_format($val,1) ?> / 5.0</span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="muted-cell">No performance ratings on file yet — ratings are awarded upon Purchase Order delivery &amp; closure.</p>
          <?php endif; ?>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<!-- ═══════════════════════════════════════════════ -->
<!-- RAISE PO ISSUE MODAL                            -->
<!-- ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-raise-issue">
  <div class="modal" style="max-width:540px">
    <div class="modal-header">
      <h3 style="display:flex;align-items:center;gap:6px;color:#C53030">
        <?= icon('alert-triangle', 16) ?> Raise an Issue / Commercial Concern
      </h3>
      <button class="modal-close" onclick="closeIssueModal()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="form-raise-issue">
      <input type="hidden" name="action" value="raise_po_issue"/>
      <input type="hidden" name="tab" value="orders"/>
      <input type="hidden" name="po_id" id="m-issue-po-id" value=""/>

      <div class="modal-body" style="padding:16px 20px">
        <div style="background:#FFF5F5;border:1px solid #FEB2B2;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;color:#742A2A">
          <div><strong>Purchase Order:</strong> <span id="m-issue-po-num" style="font-weight:700"></span></div>
          <div id="m-issue-cnt-ref" style="margin-top:2px;font-size:11.5px;color:#9B2C2C"></div>
          <p style="margin:6px 0 0;font-size:11.5px;color:#4A5568">
            Raising an issue places this order under review and alerts purchasing management to resolve pricing, schedule, or item discrepancies with you.
          </p>
        </div>

        <div class="field-group" style="margin-bottom:12px">
          <label class="field-label">Issue Category <span style="color:var(--red)">*</span></label>
          <select name="issue_reason" class="field-input" required>
            <option value="Price / Cost Discrepancy">Price / Cost Discrepancy (does not match contract)</option>
            <option value="Lead Time / Schedule Conflict">Lead Time / Delivery Schedule Conflict</option>
            <option value="Specification / Line Item Query">Specification / Line Item Query</option>
            <option value="Stock / Quantity Shortage">Stock / Quantity Shortage</option>
            <option value="Delivery Address / Logistics">Delivery Address / Logistics Question</option>
            <option value="Other Commercial Concern">Other Commercial Concern</option>
          </select>
        </div>

        <div class="field-group" style="margin-bottom:6px">
          <label class="field-label">Issue Details &amp; Proposed Adjustment <span style="color:var(--red)">*</span></label>
          <textarea name="issue_notes" class="field-input" rows="4" placeholder="Detail the discrepancy, lead time issue, or question for management review..." required style="resize:vertical"></textarea>
        </div>
      </div>

      <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="btn-cancel" onclick="closeIssueModal()">Cancel</button>
        <button type="submit" class="btn-save" style="background:var(--red);border-color:var(--red)">
          <?= icon('alert-triangle', 14) ?> Submit Issue to Management
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════ -->
<!-- ADVANCE SHIPPING NOTICE (ASN) MODAL             -->
<!-- ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-submit-asn">
  <div class="modal" style="max-width:650px">
    <div class="modal-header">
      <h3 style="display:flex;align-items:center;gap:6px"><?= icon('truck', 16) ?> Submit Advance Shipping Notice (ASN)</h3>
      <button class="modal-close" onclick="closeAsnModal()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="form-submit-asn">
      <input type="hidden" name="action" value="submit_asn"/>
      <input type="hidden" name="tab" value="orders"/>
      <input type="hidden" name="po_id" id="m-asn-po-id" value=""/>

      <div class="modal-body" style="padding:16px 20px;max-height:75vh;overflow-y:auto">
        <div style="background:#FAF5EE;border:1px solid #E5D5C5;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px">
          <div><strong>Purchase Order:</strong> <span id="m-asn-po-num" style="font-weight:700"></span></div>
          <p style="margin:4px 0 0;font-size:11.5px;color:var(--text-muted)">
            Notify Kofee Manila warehouse of incoming dispatch, logistics tracking, and item quantities.
          </p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div class="field-group" style="margin:0">
            <label class="field-label">Carrier / Logistics Provider <span style="color:var(--red)">*</span></label>
            <input class="field-input" type="text" name="carrier_name" placeholder="e.g. LBC Express, J&amp;T, Own Fleet" required/>
          </div>
          <div class="field-group" style="margin:0">
            <label class="field-label">Tracking / Airway Bill #</label>
            <input class="field-input" type="text" name="tracking_number" placeholder="e.g. TRK-12345678"/>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div class="field-group" style="margin:0">
            <label class="field-label">Shipped Date <span style="color:var(--red)">*</span></label>
            <input class="field-input" type="date" name="shipped_date" value="<?= date('Y-m-d') ?>" required/>
          </div>
          <div class="field-group" style="margin:0">
            <label class="field-label">Expected Arrival Date (ETA)</label>
            <input class="field-input" type="date" name="expected_arrival_date"/>
          </div>
        </div>

        <div class="field-group" style="margin-bottom:14px">
          <label class="field-label">Fulfillment Status <span style="color:var(--red)">*</span></label>
          <select name="fulfillment_status" class="field-input" required>
            <option value="shipped">Complete Shipment (All items dispatched)</option>
            <option value="partially_shipped">Partial Shipment (Remaining items will follow)</option>
          </select>
        </div>

        <div class="field-group" style="margin-bottom:14px">
          <label class="field-label">Dispatched Item Quantities <span style="color:var(--red)">*</span></label>
          <div class="table-scroll-wrapper" style="margin:0">
            <table style="width:100%;font-size:12px">
              <thead>
                <tr style="background:#FAF8F5">
                  <th style="text-align:left;padding:6px 10px">Item Name</th>
                  <th style="text-align:right;padding:6px 10px">Ordered Qty</th>
                  <th style="text-align:right;padding:6px 10px;width:120px">Shipped Qty</th>
                </tr>
              </thead>
              <tbody id="m-asn-items-tbody"></tbody>
            </table>
          </div>
        </div>

        <div class="field-group" style="margin-bottom:6px">
          <label class="field-label">Logistics / Packing Notes (optional)</label>
          <textarea name="notes" class="field-input" rows="2" placeholder="e.g. 2 boxes packed with dry ice, fragile handling instructions" style="resize:vertical"></textarea>
        </div>
      </div>

      <div class="modal-footer" style="padding:12px 20px;display:flex;justify-content:flex-end;gap:8px">
        <button type="button" class="btn-cancel" onclick="closeAsnModal()">Cancel</button>
        <button type="submit" class="btn-save" style="background:var(--caramel);border-color:var(--caramel)">
          <?= icon('truck', 14) ?> Dispatch &amp; Notify Warehouse
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════ -->
<!-- CONTRACT COUNTERSIGNATURE MODAL                 -->
<!-- ═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-contract">
  <div class="modal" style="max-width:740px">
    <div class="modal-header">
      <h3 id="mc-modal-title"><?= icon('file-text', 16) ?> Contract Countersignature</h3>
      <button class="modal-close" onclick="closeContractModal()"><?= icon('x', 14) ?></button>
    </div>
    <form method="POST" id="form-sign-contract">
      <input type="hidden" name="action" value="sign_contract"/>
      <input type="hidden" name="tab" value="contracts"/>
      <input type="hidden" name="contract_id" id="mc-contract-id" value=""/>
      <input type="hidden" name="supplier_signature" id="mc-supplier-sig" value=""/>

      <div class="modal-body" style="max-height:75vh;overflow-y:auto">
        <div style="background:#FAF5EE;border:1px solid #E5D5C5;border-radius:10px;padding:16px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <div id="mc-ref" style="font-family:monospace;font-weight:700;font-size:12px;color:var(--caramel)"></div>
              <div id="mc-title" style="font-weight:800;font-size:16px;color:var(--espresso);margin-top:2px"></div>
              <div id="mc-meta" style="font-size:12px;color:var(--text-muted);margin-top:4px"></div>
            </div>
            <div style="text-align:right">
              <div id="mc-amount" style="font-size:19px;font-weight:800;color:var(--espresso)"></div>
              <div id="mc-status-badge" style="margin-top:4px"></div>
            </div>
          </div>
        </div>

        <!-- Line items table -->
        <div class="field-group" style="margin-bottom:14px">
          <label class="field-label">Scheduled Items &amp; Pricing</label>
          <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">
            <table class="item-schedule-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Quantity</th>
                  <th>Unit Price</th>
                  <th style="text-align:right">Line Total</th>
                </tr>
              </thead>
              <tbody id="mc-items-tbody"></tbody>
            </table>
          </div>
        </div>

        <!-- Terms -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
          <div style="background:#fcfcfc;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">DELIVERY TERMS</div>
            <div id="mc-deliv" style="font-size:12.5px;color:var(--espresso);margin-top:3px"></div>
          </div>
          <div style="background:#fcfcfc;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">PAYMENT TERMS</div>
            <div id="mc-pay" style="font-size:12.5px;color:var(--espresso);margin-top:3px"></div>
          </div>
        </div>

        <div class="field-group" style="margin-bottom:16px">
          <label class="field-label">Contract Conditions &amp; Terms</label>
          <div id="mc-terms" style="background:#fcfcfc;border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:11.5px;color:var(--text-muted);white-space:pre-line;max-height:90px;overflow-y:auto"></div>
        </div>

        <!-- Buyer Signature info -->
        <div style="background:#FBF6EF;border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-size:11px;font-weight:700;color:var(--caramel);text-transform:uppercase">BUYER SIGNATURE (KOFEE MANILA)</div>
            <div id="mc-buyer-name" style="font-weight:700;font-size:13px;color:var(--espresso);margin-top:2px"></div>
            <div id="mc-buyer-time" style="font-size:11px;color:var(--text-muted)"></div>
          </div>
          <div id="mc-buyer-sig-img"></div>
        </div>

        <!-- Supplier Signature Section -->
        <div id="mc-sign-section">
          <div style="background:#FAF5EE;border:1.5px solid var(--border);border-radius:10px;padding:16px">
            <div style="font-size:12px;font-weight:800;color:var(--espresso);text-transform:uppercase;margin-bottom:8px">
              Supplier Authorized Countersignature <span style="color:var(--red)">*</span>
            </div>

            <div class="field-group" style="margin-bottom:10px">
              <label class="field-label">Authorized Signer Full Legal Name <span style="color:var(--red)">*</span></label>
              <input class="field-input" type="text" name="supplier_signed_name" id="mc-sig-name" value="<?= htmlspecialchars($user['firstname'] ? ($user['firstname'] . ' ' . $user['lastname']) : ($supplier['contact_person'] ?: $supplier['name'])) ?>" required/>
            </div>

            <div class="field-group" style="margin:0">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                <label class="field-label" style="margin:0">Draw Your Signature</label>
                <button type="button" class="act-btn" style="color:var(--red);padding:2px 8px;font-size:11px" onclick="clearSupSig()">Clear</button>
              </div>
              <div style="background:#fff;border:1.5px solid var(--border);border-radius:8px;position:relative">
                <canvas id="sup-sig-pad" width="550" height="110" style="display:block;width:100%;height:110px;cursor:crosshair;touch-action:none;background:#fff"></canvas>
                <div id="sup-sig-ph" style="position:absolute;bottom:6px;left:10px;font-size:11.5px;color:#A99EA9;pointer-events:none">
                  Draw supplier representative electronic signature above
                </div>
              </div>
              <div id="sup-sig-status" style="font-size:11.5px;color:var(--text-muted);margin-top:4px">
                Signature required to complete contract execution.
              </div>
            </div>

            <div style="margin-top:12px;font-size:11px;color:var(--text-muted)">
              By signing above, you confirm your legal authority to bind <?= htmlspecialchars($supplier['name']) ?> to the schedule, pricing, delivery, and payment terms of this agreement.
            </div>
          </div>
        </div>

        <!-- Supplier Signature View (when already signed) -->
        <div id="mc-signed-view" style="display:none;background:#E8F5E9;border:1px solid #A5D6A7;border-radius:8px;padding:14px;margin-top:10px">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:11px;font-weight:700;color:#2E7D32;text-transform:uppercase">SUPPLIER COUNTERSIGNATURE</div>
              <div id="mc-view-sup-name" style="font-weight:700;font-size:13px;color:#1B5E20;margin-top:2px"></div>
              <div id="mc-view-sup-time" style="font-size:11px;color:#2E7D32"></div>
            </div>
            <div id="mc-view-sup-img"></div>
          </div>
        </div>

      </div>

      <div class="modal-actions" id="mc-modal-actions">
        <button type="button" class="btn-cancel" onclick="closeContractModal()">Cancel</button>
        <button type="submit" class="btn-save" id="btn-submit-sign" style="background:#27AE60">
          <?= icon('check', 14) ?> Confirm Countersignature
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const CONTRACT_ITEMS = <?= json_encode($contract_items_map) ?>;

function switchTab(tab) {
  document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.filter-pill').forEach(el => el.classList.remove('active'));
  const target = document.getElementById('tab-' + tab);
  if (target) target.classList.add('active');
  const btn = document.querySelector(`[onclick="switchTab('${tab}')"]`);
  if (btn) btn.classList.add('active');

  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  window.history.replaceState({}, '', url);
}

// ── Contract Modal & Signature Pad ──
let supCanvas = null, supCtx = null, supDrawing = false, supHasSig = false;

function initSupSig() {
  supCanvas = document.getElementById('sup-sig-pad');
  if (!supCanvas) return;
  supCtx = supCanvas.getContext('2d');
  supCtx.lineWidth = 2.5;
  supCtx.lineCap = 'round';
  supCtx.lineJoin = 'round';
  supCtx.strokeStyle = '#241A2E';

  function getPos(e) {
    const rect = supCanvas.getBoundingClientRect();
    const scaleX = supCanvas.width / rect.width;
    const scaleY = supCanvas.height / rect.height;
    if (e.touches && e.touches.length > 0) {
      return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
    }
    return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
  }

  function startDraw(e) {
    supDrawing = true;
    supHasSig = true;
    const ph = document.getElementById('sup-sig-ph');
    if (ph) ph.style.display = 'none';
    const pos = getPos(e);
    supCtx.beginPath();
    supCtx.moveTo(pos.x, pos.y);
    if (e.type.startsWith('touch')) e.preventDefault();
  }

  function drawMove(e) {
    if (!supDrawing) return;
    const pos = getPos(e);
    supCtx.lineTo(pos.x, pos.y);
    supCtx.stroke();
    if (e.type.startsWith('touch')) e.preventDefault();
  }

  function stopDraw() {
    if (!supDrawing) return;
    supDrawing = false;
    supCtx.closePath();
    const st = document.getElementById('sup-sig-status');
    st.innerHTML = '<span style="color:var(--green);font-weight:700">✓ Signature Captured</span>';
    document.getElementById('mc-supplier-sig').value = supCanvas.toDataURL('image/png');
  }

  supCanvas.onmousedown = startDraw;
  supCanvas.onmousemove = drawMove;
  supCanvas.onmouseup   = stopDraw;
  supCanvas.onmouseleave= stopDraw;
  supCanvas.ontouchstart = startDraw;
  supCanvas.ontouchmove  = drawMove;
  supCanvas.ontouchend   = stopDraw;
}

function clearSupSig() {
  if (!supCanvas || !supCtx) return;
  supCtx.clearRect(0, 0, supCanvas.width, supCanvas.height);
  supHasSig = false;
  const ph = document.getElementById('sup-sig-ph');
  if (ph) ph.style.display = 'block';
  document.getElementById('mc-supplier-sig').value = '';
  document.getElementById('sup-sig-status').textContent = 'Signature required to complete contract execution.';
}

function openContractModal(c) {
  document.getElementById('mc-contract-id').value = c.id;
  document.getElementById('mc-ref').textContent = c.contract_ref;
  document.getElementById('mc-title').textContent = c.title;
  document.getElementById('mc-meta').textContent = 'Department: ' + c.department + ' · Date: ' + (c.created_at || '').substring(0, 10);
  document.getElementById('mc-amount').textContent = '₱' + parseFloat(c.total_amount).toFixed(2);
  document.getElementById('mc-deliv').textContent = c.delivery_terms || 'Standard delivery';
  document.getElementById('mc-pay').textContent = c.payment_terms || 'Net 30';
  document.getElementById('mc-terms').textContent = c.contract_terms || 'Standard Terms & Conditions';

  // Buyer Signature info
  document.getElementById('mc-buyer-name').textContent = c.buyer_signed_name || 'Buyer Purchasing Officer';
  document.getElementById('mc-buyer-time').textContent = 'Signed on: ' + (c.buyer_signed_at || '—');
  const bImgEl = document.getElementById('mc-buyer-sig-img');
  if (c.buyer_signature) {
    bImgEl.innerHTML = `<img src="${c.buyer_signature}" alt="Buyer Signature" style="max-height:50px;display:block"/>`;
  } else {
    bImgEl.innerHTML = '<span class="muted-cell">Unsigned</span>';
  }

  // Populate Items Table
  const tbody = document.getElementById('mc-items-tbody');
  tbody.innerHTML = '';
  const items = CONTRACT_ITEMS[c.id] || [];
  if (items.length > 0) {
    items.forEach(it => {
      const tr = document.createElement('tr');
      const uPrice = parseFloat(it.unit_price || 0);
      const lTot = parseFloat(it.line_total || 0);
      tr.innerHTML = `
        <td style="font-weight:600">${escapeHtml(it.item_name)}</td>
        <td>${parseFloat(it.quantity)} ${escapeHtml(it.unit || '')}</td>
        <td>₱${uPrice.toFixed(2)}</td>
        <td style="text-align:right;font-weight:700">₱${lTot.toFixed(2)}</td>
      `;
      tbody.appendChild(tr);
    });
  } else {
    tbody.innerHTML = '<tr><td colspan="4" class="muted-cell" style="text-align:center">1 Lot agreement</td></tr>';
  }

  // Mode: Sign vs View
  const signSec = document.getElementById('mc-sign-section');
  const viewSec = document.getElementById('mc-signed-view');
  const actionsEl = document.getElementById('mc-modal-actions');
  const badgeEl = document.getElementById('mc-status-badge');

  if (c.status === 'sent_to_supplier') {
    badgeEl.innerHTML = '<span class="status-badge status-pending">Awaiting Countersignature</span>';
    signSec.style.display = 'block';
    viewSec.style.display = 'none';
    actionsEl.innerHTML = `
      <button type="button" class="btn-cancel" onclick="closeContractModal()">Cancel</button>
      <button type="submit" class="btn-save" style="background:#27AE60"><?= icon('check', 14) ?> Confirm Countersignature</button>
    `;
    setTimeout(initSupSig, 100);
  } else {
    badgeEl.innerHTML = '<span class="status-badge status-approved">Fully Signed</span>';
    signSec.style.display = 'none';
    viewSec.style.display = 'block';
    document.getElementById('mc-view-sup-name').textContent = c.supplier_signed_name || 'Authorized Representative';
    document.getElementById('mc-view-sup-time').textContent = 'Signed on: ' + (c.supplier_signed_at || '—');
    const supImgEl = document.getElementById('mc-view-sup-img');
    if (c.supplier_signature) {
      supImgEl.innerHTML = `<img src="${c.supplier_signature}" alt="Supplier Signature" style="max-height:50px;display:block"/>`;
    } else {
      supImgEl.innerHTML = '<span class="muted-cell">Signed</span>';
    }
    actionsEl.innerHTML = `<button type="button" class="btn-cancel" onclick="closeContractModal()">Close</button>`;
  }

  document.getElementById('modal-contract').classList.add('open');
}
function closeContractModal() { document.getElementById('modal-contract').classList.remove('open'); }

function togglePoItems(id) {
  const el = document.getElementById('po-items-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function openIssueModal(poId, poNum, contractRef) {
  document.getElementById('m-issue-po-id').value = poId;
  document.getElementById('m-issue-po-num').textContent = poNum;
  const cntEl = document.getElementById('m-issue-cnt-ref');
  if (contractRef) {
    cntEl.textContent = 'Governing Contract: ' + contractRef;
    cntEl.style.display = 'block';
  } else {
    cntEl.style.display = 'none';
  }
  document.getElementById('modal-raise-issue').classList.add('open');
}
function closeIssueModal() {
  document.getElementById('modal-raise-issue').classList.remove('open');
}

function openAsnModal(poId, poNum, items) {
  document.getElementById('m-asn-po-id').value = poId;
  document.getElementById('m-asn-po-num').textContent = poNum;
  const tbody = document.getElementById('m-asn-items-tbody');
  tbody.innerHTML = '';
  if (items && items.length > 0) {
    items.forEach(it => {
      const tr = document.createElement('tr');
      const reqId = it.requisition_item_id || it.id;
      const qty = parseFloat(it.quantity || 0);
      const name = escapeHtml(it.item_name || 'Item');
      const unit = escapeHtml(it.unit || 'pcs');
      tr.innerHTML = `
        <td style="padding:6px 10px;font-weight:600">
          ${name}
          <input type="hidden" name="items[${reqId}][item_name]" value="${name}"/>
          <input type="hidden" name="items[${reqId}][unit]" value="${unit}"/>
        </td>
        <td style="padding:6px 10px;text-align:right;color:var(--text-muted)">${qty.toFixed(2)} ${unit}</td>
        <td style="padding:6px 10px;text-align:right">
          <input class="field-input" type="number" step="0.01" min="0" max="${qty}" name="items[${reqId}][shipped_qty]" value="${qty}" style="width:100px;text-align:right;padding:4px 8px"/>
        </td>
      `;
      tbody.appendChild(tr);
    });
  } else {
    tbody.innerHTML = '<tr><td colspan="3" class="muted-cell" style="text-align:center;padding:10px">No line items found. Enter tracking info below.</td></tr>';
  }
  document.getElementById('modal-submit-asn').classList.add('open');
}
function closeAsnModal() {
  document.getElementById('modal-submit-asn').classList.remove('open');
}

function toggleAckForm(id) {
  const f = document.getElementById('ack-form-' + id);
  if (f) f.style.display = (f.style.display === 'none' || !f.style.display) ? 'block' : 'none';
}
function toggleShipForm(id) {
  const f = document.getElementById('ship-form-' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'flex' : 'none';
}
function toggleInvoiceForm(id) {
  const f = document.getElementById('invoice-form-' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function toggleInvoiceItems(id) {
  const el = document.getElementById('inv-items-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleResubmitForm(id) {
  const f = document.getElementById('resubmit-form-' + id);
  if (f) f.style.display = f.style.display === 'none' ? 'block' : 'none';
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.getElementById('form-sign-contract')?.addEventListener('submit', (e) => {
  if (!supHasSig) {
    e.preventDefault();
    alert('Please draw your electronic countersignature on the canvas before confirming.');
    return;
  }
  document.getElementById('mc-supplier-sig').value = supCanvas.toDataURL('image/png');
});

document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const contractId = urlParams.get('contract_id');
  if (contractId) {
    switchTab('contracts');
    // Find contract by id in my_contracts
    <?php if (!empty($my_contracts)): ?>
      const allContracts = <?= json_encode($my_contracts) ?>;
      const target = allContracts.find(c => c.id == contractId);
      if (target) openContractModal(target);
    <?php endif; ?>
  }
  const poId = urlParams.get('po_id');
  if (poId) {
    switchTab('orders');
    const targetCard = document.getElementById('po-card-' + poId);
    if (targetCard) {
      targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      targetCard.style.boxShadow = '0 0 0 3px var(--caramel)';
      setTimeout(() => { targetCard.style.boxShadow = ''; }, 3500);
    }
  }
  const invId = urlParams.get('invoice_id');
  if (invId) {
    switchTab('invoices');
    const targetCard = document.getElementById('inv-card-' + invId);
    if (targetCard) {
      targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      targetCard.style.boxShadow = '0 0 0 3px var(--caramel)';
      setTimeout(() => { targetCard.style.boxShadow = ''; }, 3500);
    }
    const targetForm = document.getElementById('resubmit-form-' + invId);
    if (targetForm) {
      targetForm.style.display = 'block';
    }
  }
});

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { 
    if (e.target === el) {
      closeContractModal();
      closeIssueModal();
      closeAsnModal();
    }
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeContractModal();
    closeIssueModal();
    closeAsnModal();
  }
});
</script>

</body>
</html>