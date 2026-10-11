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
  <title>Analytics & Reports — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/analytics.css?v=<?= time() ?>"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <!-- Inlined critical layout styles to guarantee flawless rendering independent of browser CSS caching -->
  <style>
    .analytics-financial-row {
      display: grid !important;
      grid-template-columns: repeat(3, 1fr) !important;
      gap: 16px !important;
      margin-bottom: 16px !important;
    }
    @media (max-width: 960px) {
      .analytics-financial-row { grid-template-columns: 1fr !important; }
    }
    .analytics-operational-row {
      display: grid !important;
      grid-template-columns: repeat(4, 1fr) !important;
      gap: 16px !important;
      margin-bottom: 20px !important;
    }
    @media (max-width: 1080px) {
      .analytics-operational-row { grid-template-columns: repeat(2, 1fr) !important; }
    }
    @media (max-width: 560px) {
      .analytics-operational-row { grid-template-columns: 1fr !important; }
    }
    .stat-card-header {
      display: flex !important;
      align-items: center !important;
      justify-content: space-between !important;
      margin-bottom: 6px !important;
    }
    .stat-tag {
      font-size: 11px !important;
      font-weight: 700 !important;
      text-transform: uppercase !important;
      letter-spacing: .04em !important;
      padding: 4px 10px !important;
      border-radius: 999px !important;
      border: 1px solid transparent !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 4px !important;
    }
    .tag-caramel {
      background: #FFF8F1 !important;
      color: #B45309 !important;
      border-color: rgba(201, 123, 61, 0.25) !important;
    }
    .tag-red {
      background: #FFEBEE !important;
      color: #C62828 !important;
      border-color: rgba(198, 40, 40, 0.25) !important;
      cursor: pointer !important;
      transition: background 0.15s ease !important;
    }
    .tag-red:hover { background: #FFCDD2 !important; }
    .tag-green {
      background: #E8F5E9 !important;
      color: #2E7D32 !important;
      border-color: rgba(46, 125, 50, 0.25) !important;
    }
    .tag-green.is-negative {
      background: #FFEBEE !important;
      color: #C62828 !important;
      border-color: rgba(198, 40, 40, 0.25) !important;
    }
    .stat-profit .stat-value { color: #2E7D32 !important; }
    .stat-profit.is-negative .stat-value { color: #C62828 !important; }
    .period-toggle {
      display: inline-flex !important;
      background: #F4EBE1 !important;
      border: 1px solid rgba(36, 26, 46, 0.12) !important;
      padding: 3px !important;
      border-radius: 999px !important;
      gap: 3px !important;
    }
    .period-btn {
      border: none !important;
      background: transparent !important;
      color: #7A6B62 !important;
      font-family: inherit !important;
      font-size: 12px !important;
      font-weight: 600 !important;
      padding: 6px 14px !important;
      border-radius: 999px !important;
      cursor: pointer !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 6px !important;
      transition: all 0.15s ease !important;
    }
    .period-btn:hover { color: #241A2E !important; }
    .period-btn.active {
      background: #FFFFFF !important;
      color: #241A2E !important;
      font-weight: 700 !important;
      box-shadow: 0 2px 6px rgba(36, 26, 46, 0.1) !important;
    }
    .dot-indicator {
      width: 6px; height: 6px; border-radius: 50%; display: inline-block; background: #2E7D32; margin-right: 5px;
    }
    .breakdown-modal-backdrop {
      position: fixed; inset: 0; background: rgba(25, 18, 32, 0.6);
      backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
      z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px;
    }
    .breakdown-modal-backdrop.is-open { display: flex !important; }
  </style>
</head>
<body>

<?php include("../includes/sidebar.php"); ?>

<div id="page-analytics" class="page active">
  <div class="page-header">
    <div>
      <h1>Analytics & Reports</h1>
      <p id="page-subtitle">Executive overview of sales revenue, expenses, and net profit</p>
    </div>
    <div class="analytics-header-actions">
      <div class="period-toggle" role="tablist" aria-label="Select report time period">
        <button type="button" class="period-btn active" data-period="all" role="tab" aria-selected="true" id="tab-all">
          <?= icon('calendar', 14) ?> All Time
        </button>
        <button type="button" class="period-btn" data-period="month" role="tab" aria-selected="false" id="tab-month">
          This Month
        </button>
        <button type="button" class="period-btn" data-period="week" role="tab" aria-selected="false" id="tab-week">
          Last 7 Days
        </button>
      </div>
    </div>
  </div>

  <div class="page-body">

    <!-- ── Row 1: Executive Financial Cards (Total Sales, Total Expenses, Total Profit) ── -->
    <div class="analytics-financial-row">
      <!-- 1. Total Sales -->
      <div class="stat-card stat-sales">
        <div class="stat-card-header">
          <div class="stat-icon" style="background:#fff3e0;color:#c47d3e" aria-hidden="true">
            <?= icon('coin', 22) ?>
          </div>
          <span class="stat-tag tag-caramel" id="tag-sales">All Time</span>
        </div>
        <div class="stat-label" id="label-sales">Total Sales</div>
        <div class="stat-value" id="s-total-sales">₱—</div>
        <div class="stat-sub" id="sub-sales"><span class="dot-indicator"></span><span id="sales-orders-text">— orders recorded</span></div>
      </div>

      <!-- 2. Total Expenses -->
      <div class="stat-card stat-expenses">
        <div class="stat-card-header">
          <div class="stat-icon" style="background:#ffebee;color:#c62828" aria-hidden="true">
            <?= icon('credit-card', 22) ?>
          </div>
          <button type="button" class="stat-tag tag-red" id="btn-expense-breakdown" title="View cost breakdown" aria-haspopup="dialog">
            <?= icon('invoice', 12) ?> Breakdown
          </button>
        </div>
        <div class="stat-label" id="label-expenses">Total Expenses</div>
        <div class="stat-value" id="s-total-expenses">₱—</div>
        <div class="stat-sub" id="sub-expenses">Paid procurement, payroll & operating costs</div>
      </div>

      <!-- 3. Total Profit -->
      <div class="stat-card stat-profit" id="card-profit">
        <div class="stat-card-header">
          <div class="stat-icon" id="icon-profit" style="background:#e8f5e9;color:#2e7d32" aria-hidden="true">
            <?= icon('sparkles', 22) ?>
          </div>
          <span class="stat-tag tag-green" id="s-profit-margin">+0.0% Margin</span>
        </div>
        <div class="stat-label" id="label-profit">Total Profit</div>
        <div class="stat-value" id="s-total-profit">₱—</div>
        <div class="stat-sub" id="sub-profit"><span id="s-profit-status" style="font-weight:700">Profitable</span> · Net earnings after expenses</div>
      </div>
    </div>

    <!-- ── Row 2: Secondary Operational KPIs ────────────────────── -->
    <div class="analytics-operational-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;color:#2e7d32" aria-hidden="true">
          <?= icon('package', 22) ?>
        </div>
        <div class="stat-label">Orders</div>
        <div class="stat-value" id="s-weekly-orders">—</div>
        <div class="stat-sub" id="s-orders-sub">7-day order count</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;color:#1976d2" aria-hidden="true">
          <?= icon('cup-tea', 22) ?>
        </div>
        <div class="stat-label">Cups Sold</div>
        <div class="stat-value" id="s-cups">—</div>
        <div class="stat-sub">This week volume</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;color:#c2185b" aria-hidden="true">
          <?= icon('star', 22) ?>
        </div>
        <div class="stat-label">Best Category</div>
        <div class="stat-value" id="s-best-cat" style="font-size:18px">—</div>
        <div class="stat-sub">Top category sales</div>
      </div>

      <div class="stat-card">
        <div class="stat-icon" style="background:#f3e8ff;color:#7e22ce" aria-hidden="true">
          <?= icon('bar-chart', 22) ?>
        </div>
        <div class="stat-label">Avg. Order Value</div>
        <div class="stat-value" id="s-avg-order">—</div>
        <div class="stat-sub" id="s-avg-sub">Average ticket size</div>
      </div>
    </div>

    <!-- Hidden compatibility span for legacy weekly reference -->
    <span id="s-weekly-sales" style="display:none">—</span>

    <!-- ── Charts Section ────────────────────────────────────────── -->
    <div class="chart-section">
      <div class="chart-card">
        <h3>Daily Sales This Week (₱)</h3>
        <div class="bar-chart" id="bar-chart"><div class="loading">Loading daily sales…</div></div>
      </div>
      <div class="chart-card">
        <h3>Sales by Category</h3>
        <div class="donut-wrap">
          <svg class="donut-svg" viewBox="0 0 120 120" id="donut-svg"></svg>
          <div class="legend" id="donut-legend"><div class="loading">Loading categories…</div></div>
        </div>
      </div>
    </div>

    <!-- ── Top Selling Items ─────────────────────────────────────── -->
    <div class="top-items-card">
      <h3>Top Selling Items</h3>
      <div id="top-items-list"><div class="loading">Loading top items…</div></div>
    </div>

  </div>
</div>

<!-- ── Expense Breakdown Modal ─────────────────────────────────── -->
<div class="breakdown-modal-backdrop" id="expense-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-breakdown-title">
  <div class="breakdown-modal">
    <div class="breakdown-modal-head">
      <div>
        <h3 id="modal-breakdown-title">Expense Breakdown</h3>
        <p id="modal-breakdown-subtitle">Itemized operational costs for the selected period</p>
      </div>
      <button type="button" class="modal-close-btn" id="modal-close-btn" aria-label="Close modal">
        <?= icon('x', 18) ?>
      </button>
    </div>

    <div class="breakdown-list">
      <div class="breakdown-item">
        <div class="breakdown-item-info">
          <div class="breakdown-item-icon" style="background:#eff6ff;color:#2563eb">
            <?= icon('truck', 18) ?>
          </div>
          <div>
            <div class="breakdown-item-title">Procurement & Suppliers</div>
            <div class="breakdown-item-desc">Completed supplier payments & paid legacy invoices</div>
          </div>
        </div>
        <div class="breakdown-item-amount" id="breakdown-procurement">₱0.00</div>
      </div>

      <div class="breakdown-item">
        <div class="breakdown-item-info">
          <div class="breakdown-item-icon" style="background:#ecfdf5;color:#059669">
            <?= icon('employees', 18) ?>
          </div>
          <div>
            <div class="breakdown-item-title">Payroll & Wages</div>
            <div class="breakdown-item-desc">Released employee net salaries</div>
          </div>
        </div>
        <div class="breakdown-item-amount" id="breakdown-payroll">₱0.00</div>
      </div>

      <div class="breakdown-item">
        <div class="breakdown-item-info">
          <div class="breakdown-item-icon" style="background:#fffbeb;color:#d97706">
            <?= icon('inventory', 18) ?>
          </div>
          <div>
            <div class="breakdown-item-title">Inventory Restocks</div>
            <div class="breakdown-item-desc">Purchases counted under procurement when paid</div>
          </div>
        </div>
        <div class="breakdown-item-amount" id="breakdown-inventory">₱0.00</div>
      </div>

      <div class="breakdown-item" id="breakdown-other-row">
        <div class="breakdown-item-info">
          <div class="breakdown-item-icon" style="background:#fdf2f8;color:#db2777">
            <?= icon('clipboard', 18) ?>
          </div>
          <div>
            <div class="breakdown-item-title">Other Operating Expenses</div>
            <div class="breakdown-item-desc">General store overhead & miscellaneous</div>
          </div>
        </div>
        <div class="breakdown-item-amount" id="breakdown-other">₱0.00</div>
      </div>
    </div>

    <div class="breakdown-total-bar">
      <span class="breakdown-total-label">Total Expenses</span>
      <span class="breakdown-total-value" id="breakdown-total">₱0.00</span>
    </div>
  </div>
</div>

<script>
(function() {
  let analyticsData = null;
  let currentPeriod = 'all';

  function formatCurrency(val) {
    const num = Number(val) || 0;
    return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function updateFinancialCards() {
    if (!analyticsData) return;

    const data = analyticsData;
    let sales = 0;
    let expenses = 0;
    let profit = 0;
    let margin = 0;
    let orders = 0;
    let avgOrder = 0;
    let tag = 'All Time';
    let periodName = 'All Time';

    if (currentPeriod === 'week') {
      sales    = data.weekly_sales || 0;
      expenses = data.weekly_expenses || 0;
      profit   = data.weekly_profit || 0;
      margin   = data.weekly_profit_margin || 0;
      orders   = data.weekly_orders || 0;
      avgOrder = data.weekly_avg_order || 0;
      tag      = 'Last 7 Days';
      periodName = 'Last 7 Days';
    } else if (currentPeriod === 'month') {
      sales    = data.monthly_sales || 0;
      expenses = data.monthly_expenses || 0;
      profit   = data.monthly_profit || 0;
      margin   = data.monthly_profit_margin || 0;
      orders   = data.monthly_orders || 0;
      avgOrder = data.monthly_avg_order || 0;
      tag      = 'This Month';
      periodName = 'This Month';
    } else {
      sales    = data.total_sales || 0;
      expenses = data.total_expenses || 0;
      profit   = data.total_profit || 0;
      margin   = data.profit_margin || 0;
      orders   = data.total_orders || 0;
      avgOrder = data.avg_order_value || 0;
      tag      = 'All Time';
      periodName = 'All Time';
    }

    // ── Update Tags & Labels ──
    document.getElementById('tag-sales').textContent = tag;
    document.getElementById('label-sales').textContent = currentPeriod === 'all' ? 'Total Sales' : (tag + ' Sales');
    document.getElementById('label-expenses').textContent = currentPeriod === 'all' ? 'Total Expenses' : (tag + ' Expenses');
    document.getElementById('label-profit').textContent = currentPeriod === 'all' ? 'Total Profit' : (tag + ' Net Profit');

    // ── Update Values ──
    document.getElementById('s-total-sales').textContent    = formatCurrency(sales);
    document.getElementById('s-total-expenses').textContent = formatCurrency(expenses);
    document.getElementById('sales-orders-text').textContent = orders.toLocaleString() + ' orders (' + tag + ')';
    document.getElementById('s-avg-order').textContent      = formatCurrency(avgOrder);
    document.getElementById('s-avg-sub').textContent        = 'Avg ticket (' + tag + ')';

    // ── Update Legacy weekly element ──
    const legacyWeeklySalesEl = document.getElementById('s-weekly-sales');
    if (legacyWeeklySalesEl) {
      legacyWeeklySalesEl.textContent = formatCurrency(data.weekly_sales || 0);
    }

    // ── Update Profit Card & Margin Status ──
    const profitEl     = document.getElementById('s-total-profit');
    const profitMargin = document.getElementById('s-profit-margin');
    const profitCard   = document.getElementById('card-profit');
    const profitStatus = document.getElementById('s-profit-status');
    const profitIcon   = document.getElementById('icon-profit');

    if (profit < 0) {
      profitEl.textContent = '-' + formatCurrency(Math.abs(profit));
      profitMargin.className = 'stat-tag tag-green is-negative';
      profitMargin.textContent = margin.toFixed(1) + '% Margin';
      profitCard.classList.add('is-negative');
      profitStatus.textContent = 'Net Loss';
      if (profitIcon) {
        profitIcon.style.background = '#ffebee';
        profitIcon.style.color = '#c62828';
      }
    } else {
      profitEl.textContent = formatCurrency(profit);
      profitMargin.className = 'stat-tag tag-green';
      profitMargin.textContent = '+' + margin.toFixed(1) + '% Margin';
      profitCard.classList.remove('is-negative');
      profitStatus.textContent = 'Profitable';
      if (profitIcon) {
        profitIcon.style.background = '#e8f5e9';
        profitIcon.style.color = '#2e7d32';
      }
    }

    // ── Update Breakdown Modal Values ──
    const breakdownPeriod = { all: 'all', month: 'monthly', week: 'weekly' }[currentPeriod];
    const bd = data.expense_breakdown ? data.expense_breakdown[breakdownPeriod] : null;
    if (bd) {
      document.getElementById('breakdown-procurement').textContent = formatCurrency(bd.procurement || 0);
      document.getElementById('breakdown-payroll').textContent     = formatCurrency(bd.payroll || 0);
      document.getElementById('breakdown-inventory').textContent   = bd.inventory ? formatCurrency(bd.inventory) : 'Included above';
      document.getElementById('breakdown-other').textContent       = formatCurrency(bd.other || 0);
      document.getElementById('breakdown-total').textContent       = formatCurrency(expenses);
      document.getElementById('modal-breakdown-subtitle').textContent = 'Itemized operational costs for ' + periodName;
    }
  }

  // ── Period Toggle Buttons ──
  document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      document.querySelectorAll('.period-btn').forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
      });
      this.classList.add('active');
      this.setAttribute('aria-selected', 'true');
      currentPeriod = this.getAttribute('data-period');
      updateFinancialCards();
    });
  });

  // ── Modal Handlers ──
  const modalBackdrop = document.getElementById('expense-modal-backdrop');
  const openModalBtn  = document.getElementById('btn-expense-breakdown');
  const closeModalBtn = document.getElementById('modal-close-btn');

  function openModal() {
    modalBackdrop.classList.add('is-open');
    closeModalBtn.focus();
  }

  function closeModal() {
    modalBackdrop.classList.remove('is-open');
    openModalBtn.focus();
  }

  if (openModalBtn) openModalBtn.addEventListener('click', openModal);
  if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
  if (modalBackdrop) {
    modalBackdrop.addEventListener('click', function(e) {
      if (e.target === modalBackdrop) closeModal();
    });
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modalBackdrop.classList.contains('is-open')) {
      closeModal();
    }
  });

  // ── Fetch Analytics Data ──
  let analyticsLoading = false;
  function loadAnalytics() {
    if (analyticsLoading) return;
    analyticsLoading = true;
    return fetch('../api/get_analytics.php', { cache: 'no-store' })
    .then(r => {
      if (!r.ok) throw new Error('Analytics request failed');
      return r.json();
    })
    .then(data => {
      analyticsData = data;
      document.getElementById('sub-expenses').textContent = 'Paid procurement, payroll & operating costs';

      // ── Populate Top Financial Cards ──
      updateFinancialCards();

      // ── Operational Stats ──
      document.getElementById('s-weekly-orders').textContent = data.weekly_orders || 0;
      document.getElementById('s-cups').textContent          = data.cups || 0;
      document.getElementById('s-best-cat').textContent      = data.best_category || 'N/A';

      // ── Bar Chart: Daily Sales ──
      const barChart = document.getElementById('bar-chart');
      const vals     = data.daily_sales.map(d => d.total);
      const max      = Math.max(...vals, 1);

      if (vals.every(v => v === 0)) {
        barChart.innerHTML = '<div class="bar-empty">No sales recorded this week</div>';
      } else {
        barChart.innerHTML = data.daily_sales.map(d => `
          <div class="bar-col">
            <div class="bar-val">${d.total > 0 ? '₱'+d.total.toLocaleString() : ''}</div>
            <div class="bar" style="height:${Math.max((d.total/max)*120, d.total>0?6:2)}px;
                 opacity:${d.total>0?1:.2}"></div>
            <div class="bar-label">${d.date}</div>
          </div>`).join('');
      }

      // ── Donut Chart: Sales by Category ──
      const cats   = data.categories;
      const r = 40, cx = 60, cy = 60;
      let offset   = -Math.PI / 2;
      let paths    = '';
      const colors = ['#8B5E3C','#C9A96E','#e07b5a','#d4b896','#c47d3e'];

      const hasData = cats && cats.some(c => c.total_sales > 0);

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

      // ── Top Selling Items ──
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

      if (!data.top_items || !data.top_items.length) {
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
      console.error('Analytics load error:', err);
      const barChart = document.getElementById('bar-chart');
      if (barChart) barChart.innerHTML = '<div class="bar-empty">Failed to load analytics data</div>';
      document.getElementById('sub-expenses').textContent = 'Unable to refresh expenses. Please try again.';
    })
    .finally(() => { analyticsLoading = false; });
  }
  loadAnalytics();
  window.addEventListener('focus', loadAnalytics);
  setInterval(() => {
    if (!document.hidden) loadAnalytics();
  }, 30000);
})();
</script>

</body>
</html>
