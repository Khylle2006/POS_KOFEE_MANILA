<?php
// ─────────────────────────────────────────────
//  includes/inventory_reminder.php
//  Include this on every protected page.
//  Shows a reminder banner every 20 orders.
// ─────────────────────────────────────────────
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Total order count
$total_orders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

// Last time the reminder was dismissed (stored in DB as a simple key-value)
// We use a settings table — create it if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$last_dismissed = (int)($pdo->query("
    SELECT `value` FROM app_settings WHERE `key` = 'inventory_reminder_dismissed_at'
")->fetchColumn() ?: 0);

// Show reminder if:
// - total orders is a multiple of 20 (and > 0)
// - AND the user hasn't dismissed it at this order count yet
$should_show = (
    $total_orders > 0 &&
    ($total_orders % 20 === 0) &&
    $last_dismissed !== $total_orders
);

// Last inventory update time
$last_update = $pdo->query("
    SELECT MAX(updated_at) FROM ingredients
")->fetchColumn();
?>

<?php if ($should_show): ?>
<div class="inv-reminder-banner" id="inv-reminder">
  <div class="inv-reminder-inner">
    <div class="inv-reminder-icon">📦</div>
    <div class="inv-reminder-text">
      <strong>Inventory Check Reminder</strong>
      <span>You've reached <strong><?= $total_orders ?> orders</strong> — time to update your inventory stocks!</span>
      <?php if ($last_update): ?>
        <small>Last inventory update: <?= date('M d, Y g:i A', strtotime($last_update)) ?></small>
      <?php endif; ?>
    </div>
    <div class="inv-reminder-actions">
      <a href="/POS/php/inventory.php" class="inv-btn-go">📋 Update Now</a>
      <button class="inv-btn-dismiss" onclick="dismissReminder(<?= $total_orders ?>)">✕ Dismiss</button>
    </div>
  </div>
</div>

<style>
  .inv-reminder-banner {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99000;
    width: calc(100% - 48px);
    max-width: 700px;
    background: #fff;
    border: 2px solid #c47d3e;
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(196,125,62,.25);
    animation: slideUpBanner .35s cubic-bezier(.34,1.56,.64,1);
  }
  @keyframes slideUpBanner {
    from { opacity:0; transform: translateX(-50%) translateY(20px); }
    to   { opacity:1; transform: translateX(-50%) translateY(0); }
  }
  .inv-reminder-inner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
  }
  .inv-reminder-icon {
    font-size: 32px;
    flex-shrink: 0;
    width: 52px;
    height: 52px;
    background: #fdf3ea;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .inv-reminder-text {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .inv-reminder-text strong { font-size: 14px; font-weight: 700; color: #2c1a0e; }
  .inv-reminder-text span   { font-size: 13px; color: #9a7e65; }
  .inv-reminder-text small  { font-size: 11px; color: #b0956e; margin-top: 2px; }
  .inv-reminder-actions { display: flex; gap: 8px; flex-shrink: 0; align-items: center; }
  .inv-btn-go {
    padding: 9px 16px;
    background: #c47d3e;
    color: #fff;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: background .15s;
  }
  .inv-btn-go:hover { background: #7a4e2e; }
  .inv-btn-dismiss {
    padding: 9px 12px;
    background: none;
    border: 1.5px solid #ecddc8;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: #9a7e65;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
  }
  .inv-btn-dismiss:hover { border-color: #c47d3e; color: #c47d3e; }
</style>

<script>
function dismissReminder(orderCount) {
  fetch('/POS/php/dismiss_reminder.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_count: orderCount })
  }).then(() => {
    const banner = document.getElementById('inv-reminder');
    if (banner) {
      banner.style.transition = 'opacity .3s, transform .3s';
      banner.style.opacity    = '0';
      banner.style.transform  = 'translateX(-50%) translateY(10px)';
      setTimeout(() => banner.remove(), 300);
    }
  });
}
</script>
<?php endif; ?>