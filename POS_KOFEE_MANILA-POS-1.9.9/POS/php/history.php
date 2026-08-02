<?php
require_once '../includes/auth_check.php';
require_once '../includes/permissions.php';
require_role();
require_permission('orders.history');


include("../includes/sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Order History — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/receipt_modal.css"/>
  <link rel="stylesheet" href="../css/history.css"/>
</head>
<body>

<div id="page-history" class="page active">
  <div class="page-header">
    <div>
      <h1>Order History</h1>
      <p>All past transactions</p>
    </div>
    <button class="btn btn-outline" onclick="exportHistory()">⬇ Export</button>
  </div>

  <div class="page-body">

    <div class="filter-bar" style="align-items:center">
      <input class="filter-input" type="text" id="history-search"
             placeholder="🔍 Search by order # or item…"
             oninput="filterHistory(this.value)"
             style="flex:1;min-width:220px"/>

      <select class="filter-select" id="history-type" onchange="filterType(this.value)" style="width:auto">
        <option value="">All Types</option>
        <option value="Dine In">Dine In</option>
        <option value="Take Out">Take Out</option>
        <option value="Delivery">Delivery</option>
      </select>

      <select class="filter-select" id="history-status" onchange="filterStatus(this.value)" style="width:auto">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="completed">Done</option>
        <option value="cancelled">Cancelled</option>
      </select>

      <input class="filter-input" type="date" id="history-date"
             onchange="filterDate(this.value)" style="width:auto"/>

      <span id="record-count" style="font-size:12px;color:var(--text-muted);white-space:nowrap;margin-left:auto"></span>
    </div>

    <div class="table-scroll-wrapper">
    <table>
      <thead>
        <tr>
          <th>Order #</th>
          <th>Date</th>
          <th>Items</th>
          <th>Type</th>
          <th>Total</th>
          <th>Receipt</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="history-tbody">
        <tr class="empty-row">
          <td colspan="7">Loading…</td>
        </tr>
      </tbody>
    </table>
</div>

  </div>
</div>

<!-- Receipt Modal (same component as New Order's post-checkout receipt) -->
<div class="modal-overlay" id="receipt-overlay">
  <div class="receipt-modal">

    <div class="receipt-head">
      <div class="receipt-check">🧾</div>
      <h2>Kofee Manila</h2>
      <p>Official Order Receipt</p>
      <span class="receipt-order-num" id="r-order-num">#0001</span>
    </div>

    <div class="receipt-body">
      <div class="receipt-meta">
        <span id="r-type">🍽️ Dine In</span>
        <span id="r-date"></span>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <span class="status-badge" id="r-status">Pending</span>
      </div>

      <div class="receipt-items" id="r-items"></div>

      <div class="receipt-totals">
        <div class="rt-row"><span>Subtotal</span><span id="r-subtotal">₱0.00</span></div>
        <div class="rt-row"><span>VAT (12%)</span><span id="r-tax">₱0.00</span></div>
        <div class="rt-row grand"><span>Total</span><span id="r-total">₱0.00</span></div>
      </div>
    </div>

    <div class="receipt-footer">
      <button class="btn-print" onclick="printReceipt()">🖨️ Print</button>
      <button class="btn-new-order" onclick="closeReceipt()">✕ Close</button>
    </div>

  </div>
</div>

<script src="../js/history.js"></script>

</body>
</html>