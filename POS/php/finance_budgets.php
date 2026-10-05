<?php
// ==============================================================================
// php/finance_budgets.php
// Finance Budget Allocations, Top-ups, Reallocations & Spending Audits
// ==============================================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';
require_once __DIR__ . '/../includes/icons.php';

require_login();

$user  = current_user();
$roles = $user['roles'] ?? (isset($user['role']) ? [$user['role']] : []);

// Access check: Admin, Finance, or users with procurement.budget.manage or finance.view
$can_manage_budgets = has_permission('procurement.budget.manage') 
    || has_permission('finance.view') 
    || in_array('admin', $roles, true) 
    || in_array('finance', $roles, true);

if (!$can_manage_budgets) {
    header('Location: no_access.php?reason=forbidden');
    exit;
}

$pdo = get_db();
$toast = '';
$toast_type = 'success';

if (isset($_GET['toast'])) {
    $toast = htmlspecialchars($_GET['toast']);
    $toast_type = $_GET['type'] ?? 'success';
}

// Current period detection
$current_period = procurement_current_period();
$selected_period = trim($_GET['period'] ?? $current_period);

// Department display mapping
$dept_labels = [
    'manager'     => 'Store Operations & Mgmt',
    'crew'        => 'Counter Crew & Baristas',
    'inventory'   => 'Inventory & Raw Ingredients',
    'procurement' => 'Procurement & Direct Sourcing',
    'warehouse'   => 'Warehouse & Logistics',
    'cashier'     => 'Front Cashier & POS',
    'hr'          => 'HR & Workforce Training',
    'admin'       => 'Executive Administration',
    'finance'     => 'Finance & Treasury'
];

// Presets for "Budget for Things"
$thing_presets = [
    'Raw Coffee Beans & Tea Bases'            => 'inventory',
    'Fresh Dairy, Milks & Flavor Syrups'       => 'inventory',
    'Cups, Lids, Straws & Packaging'          => 'crew',
    'Espresso Machine & Equipment Maintenance' => 'manager',
    'Store Supplies, Cleaning & Sanitation'    => 'manager',
    'Seasonal Marketing, Banners & Promos'     => 'admin',
    'Delivery, Logistics & Warehouse Storage' => 'warehouse',
    'Staff Training, Uniforms & Welfare'       => 'hr',
    'Direct Supplier Bulk Sourcing'           => 'procurement',
    'General Administration & Store Utilities' => 'admin'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Finance Budget Allocations — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <style>
    .fb-wrap { max-width: 1380px; margin: 0 auto; width: 100%; }
    
    /* Metrics Row */
    .fb-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .fb-kpi-card {
      background: #FFFFFF;
      border: 1.5px solid var(--border);
      border-radius: 18px;
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: 0 2px 8px rgba(36,26,46,0.03);
      position: relative;
      overflow: hidden;
    }
    .fb-kpi-card::after {
      content: '';
      position: absolute;
      top: 0; left: 0; bottom: 0;
      width: 5px;
    }
    .fb-kpi-card.c-pool::after { background: #8B4513; }
    .fb-kpi-card.c-used::after { background: #D97706; }
    .fb-kpi-card.c-avail::after { background: #16A34A; }
    .fb-kpi-card.c-rate::after { background: #6366F1; }

    .fb-kpi-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #6B7280;
      font-size: 13px;
      font-weight: 600;
    }
    .fb-kpi-icon {
      width: 36px; height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .fb-kpi-val {
      font-size: 26px;
      font-weight: 800;
      color: var(--espresso);
      font-family: 'Playfair Display', Georgia, serif;
      line-height: 1.15;
    }
    .fb-kpi-sub {
      font-size: 12px;
      color: #9CA3AF;
      font-weight: 500;
    }

    /* Controls Bar */
    .fb-controls {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      background: #FFFFFF;
      border: 1.5px solid var(--border);
      border-radius: 16px;
      padding: 14px 18px;
      margin-bottom: 22px;
    }
    .fb-filter-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .fb-select {
      height: 38px;
      padding: 0 14px;
      border-radius: 10px;
      border: 1.5px solid var(--border);
      background: #FFF;
      font-size: 13px;
      font-weight: 600;
      color: var(--espresso);
      outline: none;
      cursor: pointer;
    }
    .fb-select:focus { border-color: var(--caramel); }
    .fb-search {
      height: 38px;
      padding: 0 14px 0 34px;
      border-radius: 10px;
      border: 1.5px solid var(--border);
      background: #FFF url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239CA3AF' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E") no-repeat 10px center;
      font-size: 13px;
      color: var(--espresso);
      outline: none;
      width: 220px;
    }
    .fb-search:focus { border-color: var(--caramel); }

    /* Action Buttons */
    .btn-allocate-new {
      background: #8B4513;
      color: #FFFFFF;
      border: none;
      border-radius: 10px;
      height: 38px;
      padding: 0 16px;
      font-size: 13px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: background 0.15s ease, transform 0.1s ease;
    }
    .btn-allocate-new:hover { background: #70360F; transform: translateY(-1px); }

    .btn-sec-action {
      background: #FAF5EE;
      color: var(--espresso);
      border: 1.5px solid var(--border);
      border-radius: 10px;
      height: 38px;
      padding: 0 14px;
      font-size: 13px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-sec-action:hover { background: #F4E8D9; border-color: #D2BA9F; }

    /* Grid of Budget Cards */
    .fb-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
      gap: 18px;
      margin-bottom: 30px;
    }
    .fb-card {
      background: #FFFFFF;
      border: 1.5px solid var(--border);
      border-radius: 18px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 16px;
      box-shadow: 0 2px 10px rgba(36,26,46,0.04);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .fb-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(36,26,46,0.08);
    }
    .fb-card-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 10px;
    }
    .fb-badge-dept {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 11.5px;
      font-weight: 700;
      background: #FAF5EE;
      color: #8B4513;
      border: 1px solid #DFCBB5;
    }
    .fb-period-pill {
      font-size: 11.5px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 999px;
      background: #F3F4F6;
      color: #4B5563;
    }
    .fb-thing-title {
      font-size: 17px;
      font-weight: 700;
      color: var(--espresso);
      margin-top: 8px;
      line-height: 1.3;
    }
    .fb-notes {
      font-size: 12.5px;
      color: #6B7280;
      margin-top: 4px;
      line-height: 1.4;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* Financial Breakdown inside Card */
    .fb-stats-box {
      background: #FAF5EE;
      border-radius: 12px;
      padding: 12px 14px;
      border: 1px solid #EBE4D8;
    }
    .fb-stat-line {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12.5px;
      margin-bottom: 6px;
    }
    .fb-stat-line:last-child { margin-bottom: 0; }
    .fb-stat-lbl { color: #6B7280; font-weight: 600; }
    .fb-stat-num { font-weight: 700; color: var(--espresso); }
    .fb-stat-num.spent { color: #D97706; }
    .fb-stat-num.avail { color: #16A34A; }
    .fb-stat-num.over { color: #DC2626; }

    /* Progress bar */
    .fb-progress-wrap {
      margin-top: 10px;
    }
    .fb-progress-header {
      display: flex;
      justify-content: space-between;
      font-size: 11.5px;
      font-weight: 700;
      color: #4B5563;
      margin-bottom: 5px;
    }
    .fb-progress-track {
      height: 8px;
      border-radius: 999px;
      background: #E5E7EB;
      overflow: hidden;
      position: relative;
    }
    .fb-progress-bar {
      height: 100%;
      border-radius: 999px;
      transition: width 0.4s ease;
    }
    .fb-progress-bar.p-safe { background: #16A34A; }
    .fb-progress-bar.p-warn { background: #D97706; }
    .fb-progress-bar.p-dang { background: #DC2626; }

    /* Card Actions */
    .fb-card-actions {
      display: flex;
      gap: 8px;
      padding-top: 12px;
      border-top: 1px solid #F3F4F6;
    }
    .fb-btn-sm {
      flex: 1;
      height: 32px;
      font-size: 12px;
      font-weight: 700;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: #FFF;
      color: var(--espresso);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
      transition: all 0.15s ease;
    }
    .fb-btn-sm:hover { background: #FAF5EE; border-color: #8B4513; color: #8B4513; }
    .fb-btn-sm.primary {
      background: #8B4513;
      color: #FFF;
      border-color: #8B4513;
    }
    .fb-btn-sm.primary:hover { background: #70360F; }

    /* Modals */
    .fb-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(36,26,46,0.55);
      backdrop-filter: blur(2px);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .fb-modal-overlay.open { display: flex; }
    .fb-modal {
      background: #FFFFFF;
      border-radius: 20px;
      width: 100%;
      max-width: 540px;
      box-shadow: 0 16px 40px rgba(0,0,0,0.22);
      border: 1.5px solid var(--border);
      overflow: hidden;
      animation: fbModalSlide 0.22s ease-out;
    }
    @keyframes fbModalSlide {
      from { opacity: 0; transform: translateY(14px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .fb-modal-head {
      padding: 20px 24px;
      border-bottom: 1.5px solid #F3F4F6;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #FAF5EE;
    }
    .fb-modal-head h2 {
      margin: 0;
      font-size: 18px;
      color: var(--espresso);
      font-family: 'Playfair Display', Georgia, serif;
    }
    .fb-modal-close {
      background: none;
      border: none;
      font-size: 22px;
      line-height: 1;
      color: #9CA3AF;
      cursor: pointer;
    }
    .fb-modal-close:hover { color: var(--espresso); }
    .fb-modal-body {
      padding: 22px 24px;
      max-height: 75vh;
      overflow-y: auto;
    }
    .fb-modal-footer {
      padding: 16px 24px;
      background: #FAFAFA;
      border-top: 1.5px solid #F3F4F6;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .fb-form-row {
      margin-bottom: 16px;
    }
    .fb-form-row label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: #374151;
      margin-bottom: 6px;
    }
    .fb-form-input, .fb-form-select, .fb-form-textarea {
      width: 100%;
      box-sizing: border-box;
      height: 40px;
      padding: 0 12px;
      border-radius: 10px;
      border: 1.5px solid var(--border);
      font-size: 13.5px;
      color: var(--espresso);
      outline: none;
      background: #FFFFFF;
      transition: border-color 0.15s ease;
    }
    .fb-form-textarea {
      height: 80px;
      padding: 10px 12px;
      resize: vertical;
    }
    .fb-form-input:focus, .fb-form-select:focus, .fb-form-textarea:focus {
      border-color: #8B4513;
    }
    .fb-form-hint {
      font-size: 11.5px;
      color: #6B7280;
      margin-top: 4px;
    }

    /* Table in Modals (Breakdown & Logs) */
    .fb-log-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
    }
    .fb-log-table th {
      background: #FAF5EE;
      color: var(--espresso);
      padding: 10px 12px;
      text-align: left;
      font-weight: 700;
      border-bottom: 1.5px solid var(--border);
    }
    .fb-log-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #F3F4F6;
      color: #374151;
    }
  </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<div id="page-finance-budgets" class="page active">
  <div class="fb-wrap">

    <!-- Page Header -->
    <div class="page-header" style="margin-bottom: 20px;">
      <div>
        <div style="font-size: 12px; color: #8B4513; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
          Finance &bull; Financial Planning &amp; Treasury
        </div>
        <h1 style="margin: 0; font-size: 26px; color: var(--espresso);">Budget Allocations</h1>
        <p style="margin: 4px 0 0; color: #6B7280; font-size: 13.5px;">
          Allocate, top-up, and monitor department and procurement budgets for supplies, ingredients, and store operations.
        </p>
      </div>

      <div style="display: flex; gap: 10px; align-items: center;">
        <button type="button" class="btn-sec-action" onclick="openLogsModal()">
          <?= icon('clipboard', 14) ?> <span>Allocation Audit Trail</span>
        </button>
        <button type="button" class="btn-sec-action" onclick="openTransferModal()">
          <?= icon('scale', 14) ?> <span>Transfer Funds</span>
        </button>
        <button type="button" class="btn-allocate-new" onclick="openAllocateModal()">
          <?= icon('plus', 14) ?> <span>Allocate Budget for Things</span>
        </button>
      </div>
    </div>

    <?php if ($toast): ?>
    <div class="toast toast-<?= $toast_type ?>" style="display:inline-flex;margin-bottom:16px"><?= $toast ?></div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="fb-kpi-grid">
      <div class="fb-kpi-card c-pool">
        <div class="fb-kpi-head">
          <span>Total Allocated Pool</span>
          <div class="fb-kpi-icon" style="background:#FDF3EB;color:#8B4513;"><?= icon('coin', 18) ?></div>
        </div>
        <div class="fb-kpi-val" id="kpi-total-allocated">₱0.00</div>
        <div class="fb-kpi-sub" id="kpi-period-label">Period: <?= htmlspecialchars($selected_period) ?></div>
      </div>

      <div class="fb-kpi-card c-used">
        <div class="fb-kpi-head">
          <span>Committed / Spent</span>
          <div class="fb-kpi-icon" style="background:#FEF3C7;color:#D97706;"><?= icon('shopping-cart', 18) ?></div>
        </div>
        <div class="fb-kpi-val" id="kpi-total-used" style="color:#D97706;">₱0.00</div>
        <div class="fb-kpi-sub">Across Approved Requisitions &amp; POs</div>
      </div>

      <div class="fb-kpi-card c-avail">
        <div class="fb-kpi-head">
          <span>Available Remaining</span>
          <div class="fb-kpi-icon" style="background:#DCFCE7;color:#16A34A;"><?= icon('shield-check', 18) ?></div>
        </div>
        <div class="fb-kpi-val" id="kpi-total-remaining" style="color:#16A34A;">₱0.00</div>
        <div class="fb-kpi-sub">Uncommitted Funds Available to Spend</div>
      </div>

      <div class="fb-kpi-card c-rate">
        <div class="fb-kpi-head">
          <span>Overall Utilization</span>
          <div class="fb-kpi-icon" style="background:#EEF2FF;color:#6366F1;"><?= icon('bar-chart', 18) ?></div>
        </div>
        <div class="fb-kpi-val" id="kpi-utilization-pct">0.0%</div>
        <div class="fb-kpi-sub" id="kpi-budget-count">0 Budget Categories Configured</div>
      </div>
    </div>

    <!-- Controls Bar -->
    <div class="fb-controls">
      <div class="fb-filter-group">
        <label for="period-select" style="font-size:12.5px;font-weight:700;color:var(--espresso);">Fiscal Period:</label>
        <select id="period-select" class="fb-select" onchange="changePeriod(this.value)">
          <option value="<?= htmlspecialchars($selected_period) ?>" selected><?= htmlspecialchars($selected_period) ?> (Active)</option>
        </select>

        <label for="dept-filter" style="font-size:12.5px;font-weight:700;color:var(--espresso);margin-left:10px;">Area / Department:</label>
        <select id="dept-filter" class="fb-select" onchange="filterBudgets()">
          <option value="all">All Cost Centers &amp; Departments</option>
          <?php foreach ($dept_labels as $key => $lbl): ?>
          <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fb-filter-group">
        <input type="text" id="budget-search" class="fb-search" placeholder="Search budget for things…" oninput="filterBudgets()"/>
        <button type="button" class="btn-sec-action" onclick="fetchBudgets()" title="Refresh data">
          <?= icon('search', 13) ?> Refresh
        </button>
      </div>
    </div>

    <!-- Budgets Grid -->
    <div id="budgets-container" class="fb-grid">
      <!-- Injected via JavaScript -->
    </div>

    <!-- Empty State -->
    <div id="budgets-empty" style="display:none;text-align:center;padding:60px 20px;background:#FFF;border-radius:18px;border:1.5px dashed var(--border);">
      <div style="font-size:40px;margin-bottom:12px;">📊</div>
      <h3 style="color:var(--espresso);margin:0 0 8px;">No Budget Allocations Found</h3>
      <p style="color:#6B7280;font-size:13.5px;max-width:420px;margin:0 auto 18px;">
        There are no budgets allocated for the selected period yet. Click the button below to allocate funds for coffee, ingredients, packaging, or operations.
      </p>
      <button type="button" class="btn-allocate-new" onclick="openAllocateModal()">
        <?= icon('plus', 14) ?> Allocate Budget for Things Now
      </button>
    </div>

  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL 1: Allocate Budget for Things -->
<!-- ============================================================================== -->
<div id="modal-allocate" class="fb-modal-overlay">
  <div class="fb-modal">
    <div class="fb-modal-head">
      <h2>Allocate Budget for Things</h2>
      <button class="fb-modal-close" onclick="closeModal('modal-allocate')">&times;</button>
    </div>
    <form id="form-allocate" onsubmit="submitAllocate(event)">
      <div class="fb-modal-body">

        <div class="fb-form-row">
          <label for="alloc-preset">Quick Preset ("Budget for Things"):</label>
          <select id="alloc-preset" class="fb-form-select" onchange="applyPreset(this.value)">
            <option value="">-- Choose a Preset or Enter Custom Below --</option>
            <?php foreach ($thing_presets as $thing => $d): ?>
            <option value="<?= htmlspecialchars($thing) ?>" data-dept="<?= htmlspecialchars($d) ?>">
              <?= htmlspecialchars($thing) ?> (<?= htmlspecialchars($dept_labels[$d] ?? $d) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <div class="fb-form-hint">Selecting a preset auto-populates the category and department.</div>
        </div>

        <div class="fb-form-row">
          <label for="alloc-category">Budget Category / Thing to Fund *</label>
          <input type="text" id="alloc-category" class="fb-form-input" required placeholder="e.g. Raw Coffee Beans &amp; Tea Bases" />
        </div>

        <div class="fb-form-row">
          <label for="alloc-dept">Department / Cost Center *</label>
          <select id="alloc-dept" class="fb-form-select" required>
            <?php foreach ($dept_labels as $key => $lbl): ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($lbl) ?> (<?= htmlspecialchars($key) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="fb-form-row">
            <label for="alloc-period">Fiscal Period *</label>
            <input type="text" id="alloc-period" class="fb-form-input" required value="<?= htmlspecialchars($selected_period) ?>" placeholder="e.g. 2026-Q3" />
          </div>

          <div class="fb-form-row">
            <label for="alloc-amount">Allocation Amount (₱) *</label>
            <input type="number" id="alloc-amount" class="fb-form-input" required min="0" step="0.01" placeholder="50000.00" />
          </div>
        </div>

        <div class="fb-form-row">
          <label for="alloc-notes">Allocation Notes &amp; Purpose</label>
          <textarea id="alloc-notes" class="fb-form-textarea" placeholder="Describe the specific purpose or justification for this budget allocation..."></textarea>
        </div>

      </div>
      <div class="fb-modal-footer">
        <button type="button" class="btn-sec-action" onclick="closeModal('modal-allocate')">Cancel</button>
        <button type="submit" class="btn-allocate-new" id="btn-submit-allocate">
          <?= icon('check', 14) ?> Save Budget Allocation
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL 2: Top-Up / Adjust Allocation -->
<!-- ============================================================================== -->
<div id="modal-adjust" class="fb-modal-overlay">
  <div class="fb-modal">
    <div class="fb-modal-head">
      <h2>Adjust / Top-Up Budget</h2>
      <button class="fb-modal-close" onclick="closeModal('modal-adjust')">&times;</button>
    </div>
    <form id="form-adjust" onsubmit="submitAdjust(event)">
      <input type="hidden" id="adjust-budget-id" />
      <div class="fb-modal-body">
        
        <div style="background:#FAF5EE;border:1px solid #DFCBB5;border-radius:12px;padding:12px 14px;margin-bottom:16px;">
          <div style="font-size:11.5px;color:#8B4513;font-weight:700;text-transform:uppercase;" id="adjust-card-dept">DEPARTMENT</div>
          <div style="font-size:16px;font-weight:700;color:var(--espresso);margin-top:2px;" id="adjust-card-title">Category Title</div>
          <div style="display:flex;gap:16px;margin-top:8px;font-size:13px;">
            <div>Current: <b id="adjust-card-current">₱0.00</b></div>
            <div>Spent: <b id="adjust-card-spent" style="color:#D97706;">₱0.00</b></div>
            <div>Remaining: <b id="adjust-card-avail" style="color:#16A34A;">₱0.00</b></div>
          </div>
        </div>

        <div class="fb-form-row">
          <label for="adjust-type">Adjustment Action:</label>
          <select id="adjust-type" class="fb-form-select" onchange="calcNewAdjust()">
            <option value="topup">+ Top-Up Funds (Add to Budget)</option>
            <option value="reduce">- Reduce Allocation (Decrease Budget)</option>
          </select>
        </div>

        <div class="fb-form-row">
          <label for="adjust-amount">Amount (₱) *</label>
          <input type="number" id="adjust-amount" class="fb-form-input" required min="1" step="0.01" placeholder="10000.00" oninput="calcNewAdjust()" />
        </div>

        <div style="background:#F3F4F6;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#374151;">
          New Total Allocation will be: <b id="adjust-new-total" style="color:var(--espresso);">₱0.00</b>
        </div>

        <div class="fb-form-row">
          <label for="adjust-reason">Reason &amp; Audit Note *</label>
          <textarea id="adjust-reason" class="fb-form-textarea" required placeholder="State the reason for this budget adjustment (e.g. Approved seasonal menu top-up)..."></textarea>
        </div>

      </div>
      <div class="fb-modal-footer">
        <button type="button" class="btn-sec-action" onclick="closeModal('modal-adjust')">Cancel</button>
        <button type="submit" class="btn-allocate-new" id="btn-submit-adjust">
          <?= icon('check', 14) ?> Apply Adjustment
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL 3: Reallocate / Transfer Funds -->
<!-- ============================================================================== -->
<div id="modal-transfer" class="fb-modal-overlay">
  <div class="fb-modal">
    <div class="fb-modal-head">
      <h2>Transfer Funds Between Budgets</h2>
      <button class="fb-modal-close" onclick="closeModal('modal-transfer')">&times;</button>
    </div>
    <form id="form-transfer" onsubmit="submitTransfer(event)">
      <div class="fb-modal-body">
        
        <p style="font-size:13px;color:#6B7280;margin:0 0 16px;">
          Reallocate surplus uncommitted funds from one department or category to another.
        </p>

        <div class="fb-form-row">
          <label for="transfer-source">Source Budget (From) *</label>
          <select id="transfer-source" class="fb-form-select" required onchange="updateTransferMax()">
            <option value="">-- Select Source Budget --</option>
          </select>
          <div class="fb-form-hint" id="transfer-source-hint">Available balance will appear here.</div>
        </div>

        <div class="fb-form-row">
          <label for="transfer-target">Destination Budget (To) *</label>
          <select id="transfer-target" class="fb-form-select" required>
            <option value="">-- Select Destination Budget --</option>
          </select>
        </div>

        <div class="fb-form-row">
          <label for="transfer-amount">Transfer Amount (₱) *</label>
          <input type="number" id="transfer-amount" class="fb-form-input" required min="1" step="0.01" placeholder="5000.00" />
        </div>

        <div class="fb-form-row">
          <label for="transfer-reason">Justification / Reallocation Reason *</label>
          <textarea id="transfer-reason" class="fb-form-textarea" required placeholder="Explain why funds are being transferred between these budgets..."></textarea>
        </div>

      </div>
      <div class="fb-modal-footer">
        <button type="button" class="btn-sec-action" onclick="closeModal('modal-transfer')">Cancel</button>
        <button type="submit" class="btn-allocate-new" id="btn-submit-transfer">
          <?= icon('scale', 14) ?> Execute Transfer
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL 4: Spending Breakdown & Purchase Requisitions -->
<!-- ============================================================================== -->
<div id="modal-breakdown" class="fb-modal-overlay">
  <div class="fb-modal" style="max-width:720px;">
    <div class="fb-modal-head">
      <div>
        <h2 id="breakdown-title" style="margin:0;">Spending Breakdown</h2>
        <div style="font-size:12px;color:#6B7280;" id="breakdown-sub">Department Requisitions &amp; Expenses</div>
      </div>
      <button class="fb-modal-close" onclick="closeModal('modal-breakdown')">&times;</button>
    </div>
    <div class="fb-modal-body" style="padding:16px 20px;">
      
      <div id="breakdown-stats-strip" style="display:flex;gap:12px;background:#FAF5EE;border-radius:12px;padding:12px;margin-bottom:14px;border:1px solid #DFCBB5;">
        <!-- Injected via JS -->
      </div>

      <div style="overflow-x:auto;">
        <table class="fb-log-table">
          <thead>
            <tr>
              <th>Req #</th>
              <th>Title / Description</th>
              <th>Requested By</th>
              <th>Status</th>
              <th>Est. Total</th>
              <th>Actual Total</th>
            </tr>
          </thead>
          <tbody id="breakdown-tbody">
            <!-- Injected via JS -->
          </tbody>
        </table>
      </div>

    </div>
    <div class="fb-modal-footer">
      <button type="button" class="btn-sec-action" onclick="closeModal('modal-breakdown')">Close</button>
    </div>
  </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL 5: Audit Log & Allocation Trail -->
<!-- ============================================================================== -->
<div id="modal-logs" class="fb-modal-overlay">
  <div class="fb-modal" style="max-width:850px;">
    <div class="fb-modal-head">
      <h2>Budget Allocation Audit Trail</h2>
      <button class="fb-modal-close" onclick="closeModal('modal-logs')">&times;</button>
    </div>
    <div class="fb-modal-body" style="padding:16px 20px;">
      <p style="font-size:13px;color:#6B7280;margin:0 0 14px;">
        Chronological record of all budget allocations, top-ups, reductions, and transfers performed by Finance officers.
      </p>
      
      <div style="overflow-x:auto;max-height:480px;">
        <table class="fb-log-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Officer</th>
              <th>Department / Category</th>
              <th>Action</th>
              <th>Change</th>
              <th>New Allocation</th>
              <th>Justification</th>
            </tr>
          </thead>
          <tbody id="logs-tbody">
            <!-- Injected via JS -->
          </tbody>
        </table>
      </div>
    </div>
    <div class="fb-modal-footer">
      <button type="button" class="btn-sec-action" onclick="closeModal('modal-logs')">Close</button>
    </div>
  </div>
</div>

<script src="../assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script>
let allBudgets = [];
let currentPeriod = "<?= htmlspecialchars($selected_period) ?>";

document.addEventListener("DOMContentLoaded", () => {
  fetchBudgets();
});

async function fetchBudgets() {
  try {
    const res = await fetch(`../api/finance_budgets.php?action=list&period=${encodeURIComponent(currentPeriod)}`);
    const data = await res.json();
    if (!data.success) {
      console.error(data.error);
      return;
    }

    allBudgets = data.budgets || [];
    renderKPIs(data.summary, data.period);
    populatePeriodSelect(data.available_periods, data.period);
    filterBudgets();
  } catch (err) {
    console.error("Failed to load budgets:", err);
  }
}

function renderKPIs(summary, period) {
  document.getElementById('kpi-total-allocated').textContent = '₱' + Number(summary.total_allocated || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('kpi-total-used').textContent = '₱' + Number(summary.total_used || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('kpi-total-remaining').textContent = '₱' + Number(summary.total_remaining || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('kpi-utilization-pct').textContent = (summary.utilization_pct || 0).toFixed(1) + '%';
  document.getElementById('kpi-period-label').textContent = `Period: ${period}`;
  document.getElementById('kpi-budget-count').textContent = `${summary.budget_count || 0} Budget Categories Configured`;
}

function populatePeriodSelect(periods, active) {
  const sel = document.getElementById('period-select');
  if (!sel || !periods) return;
  sel.innerHTML = periods.map(p => `
    <option value="${escapeHtml(p)}" ${p === active ? 'selected' : ''}>
      ${escapeHtml(p)} ${p === active ? '(Current)' : ''}
    </option>
  `).join('');
}

function changePeriod(val) {
  currentPeriod = val;
  fetchBudgets();
}

function filterBudgets() {
  const deptVal = document.getElementById('dept-filter')?.value || 'all';
  const searchVal = (document.getElementById('budget-search')?.value || '').toLowerCase().trim();

  const filtered = allBudgets.filter(b => {
    const matchDept = (deptVal === 'all') || (b.department.toLowerCase() === deptVal.toLowerCase());
    const matchSearch = !searchVal || 
      (b.category_name && b.category_name.toLowerCase().includes(searchVal)) ||
      (b.department && b.department.toLowerCase().includes(searchVal)) ||
      (b.notes && b.notes.toLowerCase().includes(searchVal));
    return matchDept && matchSearch;
  });

  renderBudgetCards(filtered);
}

function renderBudgetCards(budgets) {
  const container = document.getElementById('budgets-container');
  const empty = document.getElementById('budgets-empty');
  if (!container) return;

  if (budgets.length === 0) {
    container.style.display = 'none';
    if (empty) empty.style.display = 'block';
    return;
  }

  container.style.display = 'grid';
  if (empty) empty.style.display = 'none';

  container.innerHTML = budgets.map(b => {
    const alloc = b.allocated_amount;
    const used  = b.used_amount;
    const rem   = b.remaining_amount;
    const pct   = Math.min(100, b.utilization_pct);
    const isOver = b.is_overbudget;

    const barClass = pct >= 90 ? 'p-dang' : (pct >= 70 ? 'p-warn' : 'p-safe');
    const statusTxt = isOver ? 'OVER BUDGET' : `${b.utilization_pct.toFixed(1)}% Used`;
    const statusColor = isOver ? '#DC2626' : (pct >= 90 ? '#DC2626' : (pct >= 70 ? '#D97706' : '#16A34A'));

    const deptName = <?= json_encode($dept_labels) ?>[b.department] || b.department;

    return `
      <div class="fb-card">
        <div>
          <div class="fb-card-top">
            <span class="fb-badge-dept">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/></svg>
              ${escapeHtml(deptName)}
            </span>
            <span class="fb-period-pill">${escapeHtml(b.period_label)}</span>
          </div>

          <div class="fb-thing-title">${escapeHtml(b.category_name || 'General Operations Budget')}</div>
          ${b.notes ? `<div class="fb-notes">${escapeHtml(b.notes)}</div>` : ''}
        </div>

        <div>
          <div class="fb-stats-box">
            <div class="fb-stat-line">
              <span class="fb-stat-lbl">Allocated Budget</span>
              <span class="fb-stat-num">₱${alloc.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
            </div>
            <div class="fb-stat-line">
              <span class="fb-stat-lbl">Spent / Committed</span>
              <span class="fb-stat-num spent">₱${used.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
            </div>
            <div class="fb-stat-line">
              <span class="fb-stat-lbl">Available Balance</span>
              <span class="fb-stat-num ${isOver ? 'over' : 'avail'}">₱${rem.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
            </div>

            <div class="fb-progress-wrap">
              <div class="fb-progress-header">
                <span>Utilization</span>
                <span style="color:${statusColor}">${statusTxt}</span>
              </div>
              <div class="fb-progress-track">
                <div class="fb-progress-bar ${barClass}" style="width: ${pct}%;"></div>
              </div>
            </div>
          </div>

          <div class="fb-card-actions">
            <button type="button" class="fb-btn-sm" onclick="openBreakdownModal(${b.id})" title="View purchase requests under this budget">
              👁 Breakdown
            </button>
            <button type="button" class="fb-btn-sm" onclick="openTransferWithSource(${b.id})" title="Transfer surplus funds to another budget">
              ⇄ Transfer
            </button>
            <button type="button" class="fb-btn-sm primary" onclick="openAdjustModal(${b.id})" title="Top-up or adjust allocation">
              + Top-Up / Edit
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// ── Modals Management ─────────────────────────
function openModal(id) {
  document.getElementById(id)?.classList.add('open');
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove('open');
}

function openAllocateModal() {
  document.getElementById('form-allocate').reset();
  document.getElementById('alloc-period').value = currentPeriod;
  openModal('modal-allocate');
}

function applyPreset(presetName) {
  if (!presetName) return;
  document.getElementById('alloc-category').value = presetName;
  const opt = document.querySelector(`#alloc-preset option[value="${presetName}"]`);
  if (opt) {
    const dept = opt.dataset.dept;
    if (dept) document.getElementById('alloc-dept').value = dept;
  }
}

async function submitAllocate(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-allocate');
  btn.disabled = true;

  const payload = {
    action: 'allocate',
    category_name: document.getElementById('alloc-category').value.trim(),
    department: document.getElementById('alloc-dept').value,
    period_label: document.getElementById('alloc-period').value.trim(),
    allocated_amount: parseFloat(document.getElementById('alloc-amount').value) || 0,
    notes: document.getElementById('alloc-notes').value.trim()
  };

  try {
    const res = await fetch('../api/finance_budgets.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      closeModal('modal-allocate');
      Swal.fire({
        title: "Budget Allocated!",
        text: data.message || "Budget allocation saved successfully.",
        icon: "success",
        confirmButtonColor: '#8B4513'
      });
      fetchBudgets();
    } else {
      Swal.fire({ title: "Error", text: data.error || "Failed to save allocation.", icon: "error" });
    }
  } catch (err) {
    Swal.fire({ title: "Network Error", text: err.message, icon: "error" });
  } finally {
    btn.disabled = false;
  }
}

let activeAdjustBudget = null;
function openAdjustModal(budgetId) {
  const b = allBudgets.find(item => item.id == budgetId);
  if (!b) return;
  activeAdjustBudget = b;

  document.getElementById('adjust-budget-id').value = b.id;
  document.getElementById('adjust-card-dept').textContent = b.department;
  document.getElementById('adjust-card-title').textContent = b.category_name || 'General Operations';
  document.getElementById('adjust-card-current').textContent = '₱' + b.allocated_amount.toLocaleString('en-PH', {minimumFractionDigits: 2});
  document.getElementById('adjust-card-spent').textContent = '₱' + b.used_amount.toLocaleString('en-PH', {minimumFractionDigits: 2});
  document.getElementById('adjust-card-avail').textContent = '₱' + b.remaining_amount.toLocaleString('en-PH', {minimumFractionDigits: 2});

  document.getElementById('adjust-type').value = 'topup';
  document.getElementById('adjust-amount').value = '';
  document.getElementById('adjust-reason').value = '';
  calcNewAdjust();

  openModal('modal-adjust');
}

function calcNewAdjust() {
  if (!activeAdjustBudget) return;
  const current = activeAdjustBudget.allocated_amount;
  const type = document.getElementById('adjust-type').value;
  const amt = parseFloat(document.getElementById('adjust-amount').value) || 0;

  const newTotal = type === 'topup' ? (current + amt) : (current - amt);
  const el = document.getElementById('adjust-new-total');
  el.textContent = '₱' + Math.max(0, newTotal).toLocaleString('en-PH', {minimumFractionDigits: 2});
  el.style.color = (type === 'reduce' && newTotal < activeAdjustBudget.used_amount) ? '#DC2626' : 'var(--espresso)';
}

async function submitAdjust(e) {
  e.preventDefault();
  if (!activeAdjustBudget) return;

  const btn = document.getElementById('btn-submit-adjust');
  btn.disabled = true;

  const type = document.getElementById('adjust-type').value;
  const rawAmt = parseFloat(document.getElementById('adjust-amount').value) || 0;
  const amtChange = type === 'topup' ? rawAmt : -rawAmt;
  const reason = document.getElementById('adjust-reason').value.trim();

  try {
    const res = await fetch('../api/finance_budgets.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'adjust',
        budget_id: activeAdjustBudget.id,
        amount_change: amtChange,
        reason: reason
      })
    });
    const data = await res.json();
    if (data.success) {
      closeModal('modal-adjust');
      Swal.fire({
        title: "Allocation Updated!",
        text: data.message || "Budget adjustment saved successfully.",
        icon: "success",
        confirmButtonColor: '#8B4513'
      });
      fetchBudgets();
    } else {
      Swal.fire({ title: "Error", text: data.error || "Failed to adjust budget.", icon: "error" });
    }
  } catch (err) {
    Swal.fire({ title: "Network Error", text: err.message, icon: "error" });
  } finally {
    btn.disabled = false;
  }
}

function openTransferModal() {
  populateTransferSelects();
  document.getElementById('form-transfer').reset();
  openModal('modal-transfer');
}

function openTransferWithSource(sourceId) {
  populateTransferSelects(sourceId);
  openModal('modal-transfer');
}

function populateTransferSelects(preselectSourceId = null) {
  const src = document.getElementById('transfer-source');
  const tgt = document.getElementById('transfer-target');
  if (!src || !tgt) return;

  src.innerHTML = '<option value="">-- Select Source Budget --</option>' + allBudgets.map(b => `
    <option value="${b.id}" data-avail="${b.remaining_amount}">
      ${escapeHtml(b.category_name || b.department)} (${b.department}) — Avail: ₱${b.remaining_amount.toLocaleString('en-PH', {minimumFractionDigits:2})}
    </option>
  `).join('');

  tgt.innerHTML = '<option value="">-- Select Destination Budget --</option>' + allBudgets.map(b => `
    <option value="${b.id}">
      ${escapeHtml(b.category_name || b.department)} (${b.department})
    </option>
  `).join('');

  if (preselectSourceId) {
    src.value = preselectSourceId;
    updateTransferMax();
  }
}

function updateTransferMax() {
  const sel = document.getElementById('transfer-source');
  const opt = sel.options[sel.selectedIndex];
  const hint = document.getElementById('transfer-source-hint');
  const avail = opt ? parseFloat(opt.dataset.avail) || 0 : 0;
  if (hint) {
    hint.textContent = `Maximum transferrable funds: ₱${avail.toLocaleString('en-PH', {minimumFractionDigits:2})}`;
  }
}

async function submitTransfer(e) {
  e.preventDefault();
  const btn = document.getElementById('btn-submit-transfer');
  btn.disabled = true;

  const payload = {
    action: 'transfer',
    source_budget_id: parseInt(document.getElementById('transfer-source').value),
    target_budget_id: parseInt(document.getElementById('transfer-target').value),
    transfer_amount: parseFloat(document.getElementById('transfer-amount').value) || 0,
    reason: document.getElementById('transfer-reason').value.trim()
  };

  try {
    const res = await fetch('../api/finance_budgets.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      closeModal('modal-transfer');
      Swal.fire({
        title: "Transfer Complete!",
        text: data.message || "Funds transferred successfully.",
        icon: "success",
        confirmButtonColor: '#8B4513'
      });
      fetchBudgets();
    } else {
      Swal.fire({ title: "Transfer Failed", text: data.error || "Could not transfer funds.", icon: "error" });
    }
  } catch (err) {
    Swal.fire({ title: "Network Error", text: err.message, icon: "error" });
  } finally {
    btn.disabled = false;
  }
}

async function openBreakdownModal(budgetId) {
  try {
    const res = await fetch(`../api/finance_budgets.php?action=breakdown&budget_id=${budgetId}`);
    const data = await res.json();
    if (!data.success) {
      Swal.fire({ title: "Error", text: data.error, icon: "error" });
      return;
    }

    const b = data.budget;
    document.getElementById('breakdown-title').textContent = b.category_name || b.department;
    document.getElementById('breakdown-sub').textContent = `Cost Center: ${b.department} · Fiscal Period: ${b.period_label}`;

    const strip = document.getElementById('breakdown-stats-strip');
    strip.innerHTML = `
      <div style="flex:1;"><b>Allocated:</b> ₱${Number(b.allocated_amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
      <div style="flex:1;color:#D97706;"><b>Spent:</b> ₱${Number(b.used_amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
      <div style="flex:1;color:#16A34A;"><b>Remaining:</b> ₱${Math.max(0, b.allocated_amount - b.used_amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
    `;

    const tbody = document.getElementById('breakdown-tbody');
    const reqs = data.requisitions || [];

    if (reqs.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:24px;color:#9CA3AF;">No purchase requisitions have charged this department yet.</td></tr>`;
    } else {
      tbody.innerHTML = reqs.map(r => {
        const itemsSummary = (r.items && r.items.length > 0)
          ? `<div style="font-size:11.5px;color:#6B7280;margin-top:4px;">Items: ${r.items.map(it => escapeHtml(it.item_name) + ' (' + Number(it.quantity) + ' ' + escapeHtml(it.unit) + ')').join(', ')}</div>`
          : '';
        const dateStr = r.created_at ? new Date(r.created_at).toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'}) : '';
        const poBadge = r.po_number ? `<span style="display:inline-block;padding:2px 6px;border-radius:4px;background:#E0E7FF;color:#3730A3;font-size:10.5px;font-weight:700;margin-left:6px;">PO: #${escapeHtml(r.po_number)}</span>` : '';

        return `
          <tr>
            <td style="white-space:nowrap;">
              <b>#${escapeHtml(r.pr_number || ('PR-' + r.id))}</b>
              ${poBadge}
              <div style="font-size:11px;color:#9CA3AF;">${dateStr}</div>
            </td>
            <td>
              <div style="font-weight:600;color:var(--espresso);">${escapeHtml(r.title)}</div>
              ${itemsSummary}
              ${r.notes ? `<div style="font-size:11px;color:#9CA3AF;font-style:italic;">"${escapeHtml(r.notes)}"</div>` : ''}
            </td>
            <td>${escapeHtml((r.requester_fname + ' ' + (r.requester_lname || '')).trim())}</td>
            <td><span style="font-weight:700;text-transform:capitalize;font-size:11.5px;color:${r.status === 'approved' ? '#16A34A' : (r.status === 'rejected' ? '#DC2626' : (r.status === 'closed' ? '#4B5563' : '#D97706'))}">${escapeHtml(r.status)}</span></td>
            <td>₱${Number(r.estimated_total || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
            <td><b>₱${Number(r.actual_total || r.estimated_total || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</b></td>
          </tr>
        `;
      }).join('');
    }

    openModal('modal-breakdown');
  } catch (err) {
    Swal.fire({ title: "Error", text: err.message, icon: "error" });
  }
}

async function openLogsModal() {
  try {
    const res = await fetch(`../api/finance_budgets.php?action=logs&period=${encodeURIComponent(currentPeriod)}`);
    const data = await res.json();
    if (!data.success) return;

    const tbody = document.getElementById('logs-tbody');
    const logs = data.logs || [];

    if (logs.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px;color:#9CA3AF;">No allocation logs recorded yet.</td></tr>`;
    } else {
      tbody.innerHTML = logs.map(l => {
        const officer = (l.firstname ? (l.firstname + ' ' + (l.lastname || '')) : (l.username || 'Finance Officer')).trim();
        const date = new Date(l.created_at).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        const chg = Number(l.amount_change || 0);
        const chgStr = (chg > 0 ? `+₱${chg.toLocaleString('en-PH', {minimumFractionDigits:2})}` : (chg < 0 ? `-₱${Math.abs(chg).toLocaleString('en-PH', {minimumFractionDigits:2})}` : '₱0.00'));
        const chgColor = chg > 0 ? '#16A34A' : (chg < 0 ? '#DC2626' : '#6B7280');

        return `
          <tr>
            <td style="white-space:nowrap;font-size:11.5px;color:#6B7280;">${date}</td>
            <td><b>${escapeHtml(officer)}</b></td>
            <td>${escapeHtml(l.category_name || l.department)} <small style="color:#9CA3AF;">(${escapeHtml(l.department)})</small></td>
            <td><span style="font-weight:700;text-transform:uppercase;font-size:11px;color:#8B4513;">${escapeHtml(l.action_type)}</span></td>
            <td style="font-weight:700;color:${chgColor}">${chgStr}</td>
            <td>₱${Number(l.new_allocated || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
            <td style="font-size:12px;color:#6B7280;">${escapeHtml(l.reason || '—')}</td>
          </tr>
        `;
      }).join('');
    }

    openModal('modal-logs');
  } catch (err) {
    Swal.fire({ title: "Error", text: err.message, icon: "error" });
  }
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

</body>
</html>
