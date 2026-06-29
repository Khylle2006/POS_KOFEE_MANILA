<!DOCTYPE html>
<html>
<head>
    <title>POS System</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/sidebar.css">
    <link rel="stylesheet" href="../css/analytics.css">
</head>


<body>
    <?php include('../includes/admin_sidebar.php'); ?>

      <div id="page-analytics" class="page active">
    <div class="page-header">
      <div>
        <h1>Analytics</h1>
        <p>Sales performance overview</p>
      </div>
    </div>
    <div class="page-body">
      <div class="analytics-top">
        <div class="stat-card">
          <div class="stat-icon" style="background:#fff3e0">💰</div>
          <div class="stat-label">Weekly Sales</div>
          <div class="stat-value">₱0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e8f5e9">📦</div>
          <div class="stat-label">Weekly Orders</div>
          <div class="stat-value">0</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#e3f2fd">🥤</div>
          <div class="stat-label">Cups Sold</div>
          <div class="stat-value">0</div>
          <div class="stat-sub">Across all categories</div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fce4ec">⭐</div>
          <div class="stat-label">Best Category</div>
          <div class="stat-value" style="font-size:16px">Milk Tea</div>
        </div>
      </div>

      <div class="chart-section">
        <div class="chart-card">
          <h3>Daily Sales This Week (₱)</h3>
          <div class="bar-chart" id="bar-chart"></div>
        </div>
        <div class="chart-card">
          <h3>Sales by Category</h3>
          <div class="donut-wrap">
            <svg class="donut-svg" viewBox="0 0 120 120" id="donut-svg"></svg>
            <div class="legend" id="donut-legend"></div>
          </div>
        </div>
      </div>

      <div class="top-items-card">
        <h3>Top Selling Items</h3>
        <div id="top-items-list"></div>
      </div>
    </div>
  </div>

  <script>

        function renderAnalytics() {
        // Bar chart
        const days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        const vals = [1820, 2100, 1650, 2480, 2200, 2800, 1230];
        const max = Math.max(...vals);
        const barChart = document.getElementById('bar-chart');
        barChart.innerHTML = days.map((d, i) => `
            <div class="bar-col">
            <div class="bar-val">₱${vals[i]}</div>
            <div class="bar" style="height:${(vals[i]/max)*120}px"></div>
            <div class="bar-label">${d}</div>
            </div>
        `).join('');

        // MENU IMNIDA
        const cats = [
            { label:'Milk Tea', pct:42, color:'#8B5E3C' },
            { label:'Ice Coffee', pct:28, color:'#C9A96E' },
            { label:'Fruit Tea', pct:18, color:'#e07b5a' },
            { label:'Hot Coffee', pct:12, color:'#d4b896' },
        ];
        const r = 40, cx = 60, cy = 60;
        let offset = -Math.PI / 2;
        let paths = '';
        cats.forEach(c => {
            const angle = (c.pct / 100) * Math.PI * 2;
            const x1 = cx + r * Math.cos(offset);
            const y1 = cy + r * Math.sin(offset);
            offset += angle;
            const x2 = cx + r * Math.cos(offset);
            const y2 = cy + r * Math.sin(offset);
            const large = angle > Math.PI ? 1 : 0;
            paths += `<path d="M${cx},${cy} L${x1},${y1} A${r},${r} 0 ${large},1 ${x2},${y2} Z" fill="${c.color}" stroke="#fff" stroke-width="2"/>`;
        });
        document.getElementById('donut-svg').innerHTML = paths +
            `<circle cx="${cx}" cy="${cy}" r="24" fill="white"/>`+
            `<text x="${cx}" y="${cy+5}" text-anchor="middle" font-size="11" font-weight="800" fill="#2d2417">Sales</text>`;

        document.getElementById('donut-legend').innerHTML = cats.map(c =>
            `<div class="legend-item"><div class="legend-dot" style="background:${c.color}"></div>${c.label}<span class="legend-pct">${c.pct}%</span></div>`
        ).join('');

        // Top items
        const topItems = [
            { icon:'🧋', name:'Taro Milk Tea', count: 84 },
            { icon:'🍵', name:'Brown Sugar Boba', count: 67 },
            { icon:'🧊', name:'Iced Americano', count: 52 },
            { icon:'🍹', name:'Mango Fruit Tea', count: 41 },
            { icon:'☕', name:'Caramel Latte', count: 38 },
        ];
        document.getElementById('top-items-list').innerHTML = topItems.map((t, i) => `
            <div class="top-item-row">
            <div class="ti-rank">${i+1}</div>
            <div class="ti-icon">${t.icon}</div>
            <div class="ti-info">
                <div class="ti-name">${t.name}</div>
                <div class="ti-count">${t.count} cups sold</div>
            </div>
            <div class="ti-bar-wrap"><div class="ti-bar-fill" style="width:${(t.count/84)*100}%"></div></div>
            </div>
        `).join('');
        }

  </script>

<script>
fetch('get_analytics.php')
.then(r => r.json())
.then(data => {

    document.querySelectorAll('.stat-value')[0].innerHTML = '₱' + data.weekly_sales;
    document.querySelectorAll('.stat-value')[1].innerHTML = data.weekly_orders;
    document.querySelectorAll('.stat-value')[2].innerHTML = data.cups;
    document.querySelectorAll('.stat-value')[3].innerHTML = data.best_category;

    // REAL BAR CHART
    const barChart = document.getElementById('bar-chart');

    const days = data.daily_sales.map(d => d.date);
    const vals = data.daily_sales.map(d => d.total);

    const max = Math.max(...vals, 1);

    barChart.innerHTML = days.map((d, i) => `
        <div class="bar-col">
            <div class="bar-val">₱${vals[i]}</div>
            <div class="bar" style="height:${(vals[i]/max)*120}px"></div>
            <div class="bar-label">${d}</div>
        </div>
    `).join('');
});
</script>

</body>
</html>