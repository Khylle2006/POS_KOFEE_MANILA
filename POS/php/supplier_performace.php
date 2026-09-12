<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/procurement_helpers.php';
require_login();
require_permission('procurement.view');

$pdo   = get_db();
$user  = current_user();
$toast = '';
$toast_type = 'success';
$closed_successfully = false;

// ── POST: rate + close ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'rate_and_close') {
        require_permission('procurement.performance.rate');

        $po_id     = (int)($_POST['po_id'] ?? 0);
        $quality   = max(1, min(5, (int)($_POST['quality_score'] ?? 0)));
        $timely    = max(1, min(5, (int)($_POST['timeliness_score'] ?? 0)));
        $price     = max(1, min(5, (int)($_POST['price_score'] ?? 0)));
        $comm      = max(1, min(5, (int)($_POST['communication_score'] ?? 0)));
        $comments  = trim($_POST['comments'] ?? '');

        $po = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id'); $po->execute([':id' => $po_id]); $po = $po->fetch();

        if (!$po) {
            $toast = '⚠️ Purchase Order not found.'; $toast_type = 'error';
        } elseif ($po['status'] === 'closed') {
            $toast = '⚠️ This order is already closed.'; $toast_type = 'error';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare(
                    'INSERT INTO supplier_performance_ratings
                        (po_id, supplier_id, rated_by, quality_score, timeliness_score, price_score, communication_score, comments)
                     VALUES (:po, :sup, :u, :q, :t, :p, :c, :cm)'
                )->execute([
                    ':po' => $po_id, ':sup' => $po['supplier_id'], ':u' => $user['id'],
                    ':q' => $quality, ':t' => $timely, ':p' => $price, ':c' => $comm, ':cm' => $comments ?: null,
                ]);
                $rating_id = (int)$pdo->lastInsertId();

                $overall = round(($quality + $timely + $price + $comm) / 4, 2);

                $pdo->prepare(
                    'UPDATE suppliers
                     SET rating_avg = ROUND(((COALESCE(rating_avg,0) * rating_count) + :o) / (rating_count + 1), 2),
                         rating_count = rating_count + 1
                     WHERE id = :sid'
                )->execute([':o' => $overall, ':sid' => $po['supplier_id']]);

                $pdo->prepare("UPDATE purchase_orders SET status='closed', closed_at=NOW(), supplier_rating=:r WHERE id=:id")
                    ->execute([':r' => round($overall), ':id' => $po_id]);

                // Move the requisition to closed too — the loop is complete.
                $pdo->prepare("UPDATE purchase_requisitions SET status='closed' WHERE id=:id")
                    ->execute([':id' => $po['requisition_id']]);

                $pdo->commit();

                audit_log('supplier', $po['supplier_id'], 'performance_rated', "PO #$po_id — overall {$overall}/5");
                audit_log('po', $po_id, 'closed', "Procurement cycle complete, overall rating {$overall}/5");

                $toast = "✅ Order closed — supplier rated {$overall}/5.";
                $closed_successfully = true;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $toast = '⚠️ ' . $e->getMessage(); $toast_type = 'error';
            }
        }
    }

    $q = $closed_successfully
      ? '?closed=1'
      : ($toast ? '?toast=' . urlencode($toast) . '&type=' . $toast_type : '');
    header('Location: supplier_performace.php' . $q);
    exit;
}

  if (($_GET['closed'] ?? '') === '1') {
    $closed_successfully = true;
  }
if (isset($_GET['toast'])) {
    $toast      = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// ── Supplier scorecard view ──────────────────────────────
$view_supplier_id = (int)($_GET['supplier_id'] ?? 0);
$supplier = null; $ratings = []; $breakdown = null;
if ($view_supplier_id) {
    $s = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id'); $s->execute([':id' => $view_supplier_id]); $supplier = $s->fetch();
    if ($supplier) {
        $r = $pdo->prepare('
            SELECT spr.*, po.id AS po_id, u.firstname, u.lastname
            FROM supplier_performance_ratings spr
            JOIN purchase_orders po ON po.id = spr.po_id
            LEFT JOIN users u ON u.id = spr.rated_by
            WHERE spr.supplier_id = :sid ORDER BY spr.created_at DESC
        ');
        $r->execute([':sid' => $view_supplier_id]);
        $ratings = $r->fetchAll();

        $b = $pdo->prepare('
            SELECT AVG(quality_score) AS quality, AVG(timeliness_score) AS timeliness,
                   AVG(price_score) AS price, AVG(communication_score) AS communication
            FROM supplier_performance_ratings WHERE supplier_id = :sid
        ');
        $b->execute([':sid' => $view_supplier_id]);
        $breakdown = $b->fetch();
    }
}

// ── Orders ready to close (delivered + invoice paid, not yet closed) ──
$ready_stmt = $pdo->query("
    SELECT po.*, s.name AS supplier_name, pr.title AS req_title
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    JOIN purchase_requisitions pr ON pr.id = po.requisition_id
    WHERE po.status = 'delivered'
      AND EXISTS (SELECT 1 FROM invoices i WHERE i.po_id = po.id AND i.status = 'paid')
    ORDER BY po.delivered_at DESC
");
$ready_pos = $ready_stmt->fetchAll();

// ── Supplier leaderboard ──────────────────────────────
$suppliers_stmt = $pdo->query("SELECT * FROM suppliers ORDER BY rating_avg IS NULL, rating_avg DESC, name ASC");
$suppliers = $suppliers_stmt->fetchAll();

include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Supplier Performance — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .score-row { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px dashed var(--border); }
    .score-row:last-child { border-bottom:none; }
    .star-picker { display:flex; gap:4px; }
    .star-picker .star { font-size:22px; cursor:pointer; color:var(--border); }
    .close-card { border:1.5px solid var(--border); border-radius:var(--radius); padding:16px 18px; margin-bottom:14px; }
    .breakdown-bar-wrap { background:#f2e6d6; border-radius:999px; height:8px; overflow:hidden; flex:1; margin:0 10px; }
    .breakdown-bar-fill { height:100%; background:var(--caramel, #c47d3e); }
  </style>
</head>
<body>

<div id="page-supplier-perf" class="page active">
  <div class="page-header">
    <div>
      <h1>Supplier Performance</h1>
      <p>Score suppliers on quality, timeliness, price, and communication — and close completed orders</p>
    </div>
  </div>

  <div class="page-body">

    <?php if ($toast && !$closed_successfully): ?>
    <div class="toast toast-<?= $toast_type ?>" style="position:static;display:inline-flex;margin-bottom:4px"><?= $toast ?></div>
    <?php endif; ?>

    <?php if ($supplier): ?>
      <!-- ── Scorecard ── -->
      <div class="table-card" style="padding:20px 22px;margin-bottom:18px">
        <h2><?= htmlspecialchars($supplier['name']) ?></h2>
        <p class="muted-cell" style="margin-bottom:14px"><?= $supplier['rating_count'] ?> rating(s) · Overall <?= $supplier['rating_avg'] ? number_format($supplier['rating_avg'],2) . '/5' : 'Not yet rated' ?></p>

        <?php if ($breakdown && $supplier['rating_count'] > 0): ?>
          <?php foreach (['quality'=>'Quality','timeliness'=>'Timeliness','price'=>'Price','communication'=>'Communication'] as $key => $label): $val = (float)$breakdown[$key]; ?>
            <div class="score-row">
              <span style="width:120px;font-size:12.5px;font-weight:600"><?= $label ?></span>
              <div class="breakdown-bar-wrap"><div class="breakdown-bar-fill" style="width:<?= $val/5*100 ?>%"></div></div>
              <span style="font-size:12.5px;font-weight:700"><?= number_format($val,1) ?>/5</span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <h3 style="font-size:13.5px;margin-bottom:8px">Rating History</h3>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>PO #</th><th>Quality</th><th>Timeliness</th><th>Price</th><th>Comm.</th><th>Rated By</th><th>Date</th></tr></thead>
          <tbody>
          <?php if (empty($ratings)): ?>
            <tr class="empty-row"><td colspan="7">🫙 No ratings yet for this supplier.</td></tr>
          <?php else: foreach ($ratings as $r): ?>
            <tr>
              <td style="font-weight:700">#<?= str_pad($r['po_id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><?= $r['quality_score'] ?>/5</td>
              <td><?= $r['timeliness_score'] ?>/5</td>
              <td><?= $r['price_score'] ?>/5</td>
              <td><?= $r['communication_score'] ?>/5</td>
              <td><?= htmlspecialchars(trim(($r['firstname'] ?? '').' '.($r['lastname'] ?? ''))) ?: '—' ?></td>
              <td class="muted-cell"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p style="margin-top:14px"><a href="supplier_performace.php" style="font-size:12.5px;color:var(--caramel);font-weight:600">← Back</a></p>

    <?php else: ?>

      <?php if (has_permission('procurement.performance.rate')): ?>
      <h3 style="font-size:13.5px;margin-bottom:10px">🏁 Ready to Close &amp; Rate</h3>
      <?php if (empty($ready_pos)): ?>
        <p class="muted-cell" style="margin-bottom:22px">No delivered-and-paid orders awaiting closure right now.</p>
      <?php else: foreach ($ready_pos as $p): ?>
        <div class="close-card">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
            <div>
              <strong><?= htmlspecialchars($p['req_title']) ?></strong>
              <p class="muted-cell">PO #<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($p['supplier_name']) ?> · <?= php_currency($p['total_amount']) ?></p>
            </div>
          </div>
          <form method="POST" class="rate-form" data-po="<?= $p['id'] ?>">
            <input type="hidden" name="action" value="rate_and_close"/>
            <input type="hidden" name="po_id" value="<?= $p['id'] ?>"/>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin-bottom:10px">
              <?php foreach (['quality_score'=>'Quality','timeliness_score'=>'Timeliness','price_score'=>'Price Fairness','communication_score'=>'Communication'] as $field => $label): ?>
                <div>
                  <span style="font-size:12px;font-weight:600;display:block;margin-bottom:4px"><?= $label ?></span>
                  <div class="star-picker" data-field="<?= $field ?>">
                    <?php for ($i=1;$i<=5;$i++): ?><span class="star" data-val="<?= $i ?>">★</span><?php endfor; ?>
                  </div>
                  <input type="hidden" name="<?= $field ?>" value="5"/>
                </div>
              <?php endforeach; ?>
            </div>
            <textarea class="field-input" name="comments" placeholder="Comments (optional)" style="width:100%;min-height:50px;margin-bottom:10px"></textarea>
            <div style="text-align:right"><button type="submit" class="btn-save">✔ Close &amp; Submit Rating</button></div>
          </form>
        </div>
      <?php endforeach; endif; ?>
      <?php endif; ?>

      <h3 style="font-size:13.5px;margin:22px 0 10px">📊 Supplier Leaderboard</h3>
      <div class="table-scroll-wrapper">
        <table>
          <thead><tr><th>Supplier</th><th>Overall</th><th>Ratings</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($suppliers)): ?>
            <tr class="empty-row"><td colspan="5">🫙 No suppliers yet.</td></tr>
          <?php else: foreach ($suppliers as $s): ?>
            <tr>
              <td style="font-weight:700"><?= htmlspecialchars($s['name']) ?></td>
              <td><?= $s['rating_avg'] ? number_format($s['rating_avg'],2) . '/5' : '—' ?></td>
              <td><?= $s['rating_count'] ?></td>
              <td><span class="status-badge status-<?= $s['status']==='active'?'approved':'rejected' ?>"><?= ucfirst($s['status']) ?></span></td>
              <td><button class="act-btn" onclick="window.location.href='supplier_performace.php?supplier_id=<?= $s['id'] ?>'">📊 Scorecard</button></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.star-picker').forEach(picker => {
  const hidden = picker.parentElement.querySelector('input[type=hidden]');
  const stars = picker.querySelectorAll('.star');
  function paint(n) { stars.forEach(s => s.style.color = parseInt(s.dataset.val) <= n ? '#f5b301' : 'var(--border)'); hidden.value = n; }
  paint(5);
  stars.forEach(s => s.addEventListener('click', () => paint(parseInt(s.dataset.val))));
});

<?php if ($closed_successfully): ?>
Swal.fire({
  icon: 'success',
  title: 'Order Closed',
  text: 'The supplier was rated and the procurement cycle is complete.',
  confirmButtonText: 'Go to Inventory',
  confirmButtonColor: '#c47d3e',
  allowOutsideClick: false
}).then(() => {
  window.location.href = 'inventory.php';
});
<?php endif; ?>
</script>

</body>
</html>