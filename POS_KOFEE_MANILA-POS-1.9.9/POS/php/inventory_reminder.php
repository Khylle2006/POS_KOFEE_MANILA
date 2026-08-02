  <?php
  // This file now just outputs an empty container + polling script.
  // The actual show/hide logic runs client-side via check_reminder.php
  ?>
  <div class="inv-reminder-banner" id="inv-reminder" style="display:none">
    <div class="inv-reminder-inner">
      <div class="inv-reminder-icon">📦</div>
      <div class="inv-reminder-text">
        <strong>Inventory Check Reminder</strong>
        <span id="inv-reminder-count">You've reached <strong>0 orders</strong> — time to update your inventory stocks!</span>
        <small id="inv-reminder-lastupdate"></small>
      </div>
      <div class="inv-reminder-actions">
        <a href="inventory.php" class="inv-btn-go">📋 Update Now</a>
        <button class="inv-btn-dismiss" onclick="dismissReminder(currentReminderCount)">✕ Dismiss</button>
      </div>
    </div>
  </div>

  <script>
  let currentReminderCount = 0;

  function checkReminder() {
    fetch('check_reminder.php')
      .then(r => r.json())
      .then(data => {
        const banner = document.getElementById('inv-reminder');
        if (!banner) return;

        currentReminderCount = data.total_orders;

        if (data.show) {
          document.getElementById('inv-reminder-count').innerHTML =
            `You've reached <strong>${data.total_orders} orders</strong> — time to update your inventory stocks!`;
          document.getElementById('inv-reminder-lastupdate').textContent =
            data.last_update ? `Last inventory update: ${data.last_update}` : '';
          banner.style.display = '';
        } else {
          banner.style.display = 'none';
        }
      })
      .catch(err => console.error('Reminder check failed:', err));
  }

  function dismissReminder(orderCount) {
    fetch('dismiss_reminder.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_count: orderCount })
    }).then(() => {
      const banner = document.getElementById('inv-reminder');
      if (banner) banner.style.display = 'none';
    }).catch(err => console.error('Dismiss failed:', err));
  }

  // Check immediately on page load, then every 30 seconds
  checkReminder();
  setInterval(checkReminder, 100);
  </script>