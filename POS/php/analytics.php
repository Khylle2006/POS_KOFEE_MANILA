<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/icons.php';
require_login();
require_permission('analytics.view');
require_clocked_in_for_pos();

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Analytics — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/analytics.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-analytics" class="page active">
  <div class="page-header">
    <div>
      <h1>Analytics</h1>
      <p>Sales performance — last 7 days</p>
    </div>
  </div>

  <div class="page-body">

    <div class="analytics-top">
      <div class="stat-card">
        <div class="stat-icon" style="background:#fff3e0;color:#c47d3e"><?= icon('coin', 22) ?></div>
        <div class="stat-label">Weekly Sales</div>
        <div class="stat-value" id="s-weekly-sales">—</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;color:#2e7d32"><?= icon('package', 22) ?></div>
        <div class="stat-label">Weekly Orders</div>
        <div class="stat-value" id="s-weekly-orders">—</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;color:#1976d2"><?= icon('cup-tea', 22) ?></div>
        <div class="stat-label">Cups Sold</div>
        <div class="stat-value" id="s-cups">—</div>
        <div class="stat-sub">This week</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;color:#c2185b"><?= icon('star', 22) ?></div>
        <div class="stat-label">Best Category</div>
        <div class="stat-value" id="s-best-cat" style="font-size:16px">—</div>
      </div>
    </div>

    <div class="chart-section">
      <div class="chart-card">
        <h3>Daily Sales This Week (₱)</h3>
        <div class="bar-chart" id="bar-chart"><div class="loading">Loading…</div></div>
      </div>
      <div class="chart-card">
        <h3>Sales by Category</h3>
        <div class="donut-wrap">
          <svg class="donut-svg" viewBox="0 0 120 120" id="donut-svg"></svg>
          <div class="legend" id="donut-legend"><div class="loading">Loading…</div></div>
        </div>
      </div>
    </div>

    <div class="top-items-card">
      <h3>Top Selling Items</h3>
      <div id="top-items-list"><div class="loading">Loading…</div></div>
    </div>

  </div>
</div>

<script>
fetch('../api/get_analytics.php')
  .then(r => r.json())
  .then(data => {

    // ── Stat cards ──────────────────────────────
    document.getElementById('s-weekly-sales').textContent   = '₱' + data.weekly_sales.toLocaleString();
    document.getElementById('s-weekly-orders').textContent  = data.weekly_orders;
    document.getElementById('s-cups').textContent           = data.cups;
    document.getElementById('s-best-cat').textContent       = data.best_category;

    // ── Bar chart ───────────────────────────────
    const barChart = document.getElementById('bar-chart');
    const vals     = data.daily_sales.map(d => d.total);
    const max      = Math.max(...vals, 1);

    if (vals.every(v => v === 0)) {
      barChart.innerHTML = '<div class="bar-empty">No sales data this week</div>';
    } else {
      barChart.innerHTML = data.daily_sales.map(d => `
        <div class="bar-col">
          <div class="bar-val">${d.total > 0 ? '₱'+d.total.toLocaleString() : ''}</div>
          <div class="bar" style="height:${Math.max((d.total/max)*120,d.total>0?6:2)}px;
               opacity:${d.total>0?1:.2}"></div>
          <div class="bar-label">${d.date}</div>
        </div>`).join('');
    }

    // ── Donut chart ─────────────────────────────
    const cats   = data.categories;
    const r = 40, cx = 60, cy = 60;
    let offset   = -Math.PI / 2;
    let paths    = '';
    const colors = ['#8B5E3C','#C9A96E','#e07b5a','#d4b896','#c47d3e'];

    const hasData = cats.some(c => c.total_sales > 0);

    if (!hasData) {
      document.getElementById('donut-svg').innerHTML =
        `<circle cx="60" cy="60" r="40" fill="#ecddc8"/>
         <text x="60" y="65" text-anchor="middle" font-size="10" fill="#9a7e65">No data</text>`;
      document.getElementById('donut-legend').innerHTML =
        '<div style="color:#9a7e65;font-size:12px">No sales yet</div>';
    } else {
      const total = cats.reduce((s,c) => s + +c.total_sales, 0) || 1;
      cats.forEach((c, i) => {
        const angle = (+c.total_sales / total) * Math.PI * 2;
        const x1 = cx + r * Math.cos(offset);
        const y1 = cy + r * Math.sin(offset);
        offset += angle;
        const x2 = cx + r * Math.cos(offset);
        const y2 = cy + r * Math.sin(offset);
        const large = angle > Math.PI ? 1 : 0;
        const color = colors[i % colors.length];
        paths += `<path d="M${cx},${cy} L${x1.toFixed(2)},${y1.toFixed(2)} A${r},${r} 0 ${large},1 ${x2.toFixed(2)},${y2.toFixed(2)} Z" fill="${color}" stroke="#fff" stroke-width="2"/>`;
      });
      document.getElementById('donut-svg').innerHTML = paths +
        `<circle cx="${cx}" cy="${cy}" r="24" fill="white"/>
         <text x="${cx}" y="${cy+4}" text-anchor="middle" font-size="10" font-weight="800" fill="#2c1a0e">Sales</text>`;

      document.getElementById('donut-legend').innerHTML = cats.map((c,i) => `
        <div class="legend-item">
          <div class="legend-dot" style="background:${colors[i%colors.length]}"></div>
          ${c.label}
          <span class="legend-pct">${c.pct}%</span>
        </div>`).join('');
    }

    // ── Top items ───────────────────────────────
    const topEl   = document.getElementById('top-items-list');
    const icons   = [
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 3h12l-1.5 16a2 2 0 0 1-2 1.8H9.5A2 2 0 0 1 7.5 19L6 3z"/><line x1="10" y1="7" x2="14" y2="7"/><circle cx="10" cy="14" r="1.2" fill="currentColor"/><circle cx="14" cy="14" r="1.2" fill="currentColor"/><circle cx="12" cy="17" r="1.2" fill="currentColor"/></svg>',
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>',
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 2h10l1 18a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L7 2z"/><line x1="5" y1="6" x2="19" y2="6"/><path d="M10 10h4"/><path d="M9 14h6"/><line x1="12" y1="12" x2="12" y2="4"/></svg>',
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="14" r="7"/><path d="M12 7V3"/><path d="M12 3c3 0 5 1.5 5 4"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/></svg>',
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>'
    ];
    const defaultIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/></svg>';
    const topMax  = data.top_items[0]?.total_sold || 1;

    if (!data.top_items.length) {
      topEl.innerHTML = '<div class="loading">No items sold yet</div>';
    } else {
      topEl.innerHTML = data.top_items.map((t, i) => `
        <div class="top-item-row">
          <div class="ti-rank">${i+1}</div>
          <div class="ti-icon">${icons[i] || defaultIcon}</div>
          <div class="ti-info">
            <div class="ti-name">${t.name}</div>
            <div class="ti-count">${t.total_sold} cups sold</div>
          </div>
          <div class="ti-bar-wrap">
            <div class="ti-bar-fill" style="width:${(t.total_sold/topMax)*100}%"></div>
          </div>
        </div>`).join('');
    }

  })
  .catch(err => {
    console.error('Analytics error:', err);
    document.getElementById('bar-chart').innerHTML = '<div class="bar-empty">Failed to load data</div>';
  });
</script>

</body>
</html>