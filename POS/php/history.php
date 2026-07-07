<?php
require_once '../includes/auth_check.php';
require_role();

$user = current_user();
$sidebar = $user['role'] === 'admin' ? 'admin_sidebar' : 'staff_sidebar';
include("../includes/$sidebar.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Order History — Kofee POS</title>
  <link rel="stylesheet" href="../css/style.css"/>
  <link rel="stylesheet" href="../css/sidebar.css"/>
  <link rel="stylesheet" href="../css/history.css"/>
  <style>
    /* ── Payment badges ── */
    .pm-badge {
      display: inline-flex; align-items: center;
      padding: 3px 10px; border-radius: 20px;
      font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .badge-dine     { background: #e3f2fd; color: #1565c0; }
    .badge-take     { background: #e8f5e9; color: #2e7d32; }
    .badge-delivery { background: #fff3e0; color: #e65100; }
    .badge-complete { background: #e8f5e9; color: #2e7d32; }
    .badge-pending  { background: #fff3e0; color: #e65100; }

    /* ── Receipt modal overlay ── */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0;
      width: 100vw; height: 100vh;
      background: rgba(44,26,14,.45);
      z-index: 99999;
      backdrop-filter: blur(3px);
    }
    .modal-overlay.open {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* ── Receipt card ── */
    .receipt-card {
      background: #fff;
      border-radius: 24px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 24px 64px rgba(44,26,14,.22);
      overflow: hidden;
      animation: popIn .22s cubic-bezier(.34,1.56,.64,1);
      margin: 20px;
    }
    @keyframes popIn {
      from { opacity:0; transform:scale(.9); }
      to   { opacity:1; transform:scale(1); }
    }

    /* receipt header */
    .receipt-head {
      background: linear-gradient(135deg,#fff3e0,#ffe0b2);
      padding: 28px 28px 20px;
      text-align: center;
      border-bottom: 1.5px solid #ecddc8;
    }
    .receipt-logo {
      width: 56px; height: 56px; border-radius: 16px;
      background: #fff; display: flex; align-items: center;
      justify-content: center; font-size: 28px;
      margin: 0 auto 12px;
      box-shadow: 0 2px 12px rgba(196,125,62,.2);
    }
    .receipt-head h2 { font-size: 17px; font-weight: 800; color: #2c1a0e; }
    .receipt-head p  { font-size: 12px; color: #9a7e65; margin-top: 3px; }
    .receipt-num {
      display: inline-block; margin-top: 10px;
      padding: 4px 16px; background: #c47d3e; color: #fff;
      border-radius: 20px; font-size: 13px; font-weight: 700;
    }

    /* receipt body */
    .receipt-body { padding: 20px 24px; }

    .receipt-meta {
      display: flex; justify-content: space-between;
      font-size: 11px; color: #9a7e65; font-weight: 600;
      text-transform: uppercase; letter-spacing: .05em;
      margin-bottom: 14px; align-items: center;
    }
    .r-status-badge {
      padding: 3px 10px; border-radius: 20px;
      font-size: 10px; font-weight: 700;
      text-transform: uppercase;
    }

    /* items table */
    .r-items-table {
      width: 100%; border-collapse: collapse;
      margin-bottom: 14px;
    }
    .r-items-table th {
      font-size: 10px; font-weight: 700; color: #9a7e65;
      text-transform: uppercase; letter-spacing: .05em;
      padding: 6px 0; border-bottom: 1.5px solid #ecddc8;
      text-align: left;
    }
    .r-items-table th:nth-child(2) { text-align: center; }
    .r-items-table th:last-child   { text-align: right; }
    .r-items-table td {
      padding: 8px 0; font-size: 13px;
      border-bottom: 1px solid #f5ede0;
    }
    .r-items-table td:nth-child(2) { text-align: center; color: #9a7e65; }
    .r-items-table td:last-child   { text-align: right; font-weight: 700; color: #c47d3e; }
    .r-items-table tr:last-child td { border-bottom: none; }

    /* totals */
    .r-totals {
      border-top: 1.5px dashed #ecddc8;
      padding-top: 12px;
      display: flex; flex-direction: column; gap: 5px;
    }
    .r-row { display: flex; justify-content: space-between; font-size: 12px; color: #9a7e65; }
    .r-row.grand {
      font-size: 17px; font-weight: 800; color: #2c1a0e;
      margin-top: 8px; padding-top: 10px;
      border-top: 1.5px solid #ecddc8;
    }
    .r-row.grand span:last-child { color: #c47d3e; }

    /* footer buttons */
    .receipt-footer {
      padding: 0 24px 22px;
      display: flex; gap: 10px;
    }
    .btn-print {
      flex: 1; padding: 11px;
      border: 1.5px solid #ecddc8; border-radius: 12px;
      background: none; font-family: 'Poppins',sans-serif;
      font-size: 13px; font-weight: 600; color: #9a7e65;
      cursor: pointer; transition: all .15s;
    }
    .btn-print:hover { border-color: #c47d3e; color: #c47d3e; }
    .btn-close-receipt {
      flex: 2; padding: 11px;
      background: #c47d3e; color: #fff; border: none;
      border-radius: 12px; font-family: 'Poppins',sans-serif;
      font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .btn-close-receipt:hover { background: #7a4e2e; }

    /* ── Print styles ── */
    @media print {
      body * { visibility: hidden; }
      .receipt-card, .receipt-card * { visibility: visible; }
      .receipt-card {
        position: fixed; top: 0; left: 0;
        width: 80mm; /* thermal receipt width */
        border-radius: 0; box-shadow: none;
        margin: 0; border: none;
      }
      .receipt-footer { display: none; }
      .modal-overlay  { background: none; backdrop-filter: none; }
    }
  </style>
</head>
<body>

<div id="page-history" class="page active">
  <div class="page-header">
    <div>
      <h1>Order History</h1>
      <p>All past transactions</p>
    </div>
  </div>

  <div class="page-body">

    <div class="filter-bar">
      <input class="filter-input" type="text"
             placeholder="🔍 Search by order # or item…"
             oninput="filterHistory(this.value)"/>

      <select class="filter-select" onchange="filterType(this.value)">
        <option value="">All Types</option>
        <option value="Dine In">Dine In</option>
        <option value="Take Out">Take Out</option>
        <option value="Delivery">Delivery</option>
      </select>
    </div>

    <table class="history-table">
      <thead>
        <tr>
          <th>Order #</th>
          <th>Date</th>
          <th>Items</th>
          <th>Type</th>
          <th>Total</th>
          <th>Receipt</th>
        </tr>
      </thead>
      <tbody id="history-tbody">
        <tr><td colspan="6" style="text-align:center;padding:40px;color:#9a7e65">Loading…</td></tr>
      </tbody>
    </table>

  </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receipt-overlay">
  <div class="receipt-card">

    <div class="receipt-head">
      <div class="receipt-logo">🧋</div>
      <h2>Kofee POS</h2>
      <p>Official Order Receipt</p>
      <span class="receipt-num" id="r-order-num">#0001</span>
    </div>

    <div class="receipt-body">

      <div class="receipt-meta">
        <span id="r-type">🍽️ Dine In</span>
        <span id="r-date"></span>
        <span class="r-status-badge badge-pending" id="r-status">pending</span>
      </div>

      <table class="r-items-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Price</th>
          </tr>
        </thead>
        <tbody id="r-items"></tbody>
      </table>

      <div class="r-totals">
        <div class="r-row"><span>Subtotal</span><span id="r-subtotal">₱0.00</span></div>
        <div class="r-row"><span>Tax (12%)</span><span id="r-tax">₱0.00</span></div>
        <div class="r-row grand"><span>Total</span><span id="r-total">₱0.00</span></div>
      </div>

    </div>

    <div class="receipt-footer">
      <button class="btn-print" onclick="printReceipt()">🖨️ Print</button>
      <button class="btn-close-receipt" onclick="closeReceipt()">✕ Close</button>
    </div>

  </div>
</div>

<script src="../js/history.js"></script>

</body>
</html>