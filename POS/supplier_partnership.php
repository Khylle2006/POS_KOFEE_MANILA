<?php
require_once __DIR__ . '/includes/request_security.php';
secure_session_start();
send_security_headers();
start_browser_security_output();
// ─────────────────────────────────────────────────────────────
//  BrewVanti — Supplier Partnership & Accreditation Portal
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/procurement_helpers.php';

$pdo = get_db();
ensure_procurement_tables($pdo);

// Fetch active ingredients grouped by category for quick selection
$catStmt = $pdo->query('SELECT * FROM ingredient_categories ORDER BY id ASC');
$categories = $catStmt->fetchAll();

$ingStmt = $pdo->query('
    SELECT i.id, i.name, i.unit, i.cat_id, c.name AS category_name
    FROM ingredients i
    LEFT JOIN ingredient_categories c ON c.id = i.cat_id
    WHERE i.archived_at IS NULL
    ORDER BY c.id ASC, i.name ASC
');
$all_ingredients = $ingStmt->fetchAll();

$preselected_id = (int)($_GET['item_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Supplier Partnership &amp; Accreditation — BrewVanti</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,400;1,600;1,700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/brewvanti-public.css?v=<?= filemtime(__DIR__ . '/css/brewvanti-public.css') ?>">
  <style>
    /* Supplier Partnership Specific Styling */
    .hero-grid {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 48px;
      align-items: center;
    }

    .hero-ctas-row {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }

    .hero-card {
      background: rgba(36, 26, 28, 0.72);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1.5px solid rgba(217, 186, 133, 0.32);
      border-radius: 24px;
      padding: 36px 32px;
      color: var(--color-cream);
      box-shadow: 0 20px 48px rgba(0, 0, 0, 0.4), 0 0 24px rgba(217, 186, 133, 0.12);
    }

    .hero-card h3 {
      font-family: var(--font-brand);
      font-size: 22px;
      font-weight: 700;
      color: var(--color-cream);
      margin-bottom: 8px;
    }

    .hero-card p {
      font-size: 13.5px;
      color: rgba(250, 247, 242, 0.75);
      line-height: 1.6;
      margin-bottom: 20px;
    }

    .hero-card ul {
      list-style: none;
      padding: 0;
      margin: 0 0 24px 0;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .hero-card ul li {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 13px;
      color: rgba(250, 247, 242, 0.88);
    }

    .hero-card ul li svg {
      width: 18px;
      height: 18px;
      color: var(--color-gold);
      flex-shrink: 0;
    }

    /* Pillars Section */
    .pillars-section {
      padding: 90px 0;
      background: #FAF7F2;
      border-bottom: 1px solid rgba(104, 88, 75, 0.12);
    }

    .pillars-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
    }

    .pillar-card {
      padding: 34px 28px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .pillar-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: rgba(217, 186, 133, 0.15);
      border: 1.5px solid rgba(217, 186, 133, 0.32);
      color: #9E7438;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .pillar-card h3 {
      font-family: var(--font-brand);
      font-size: 20px;
      font-weight: 700;
      color: var(--color-espresso);
      line-height: 1.3;
    }

    .pillar-card p {
      font-size: 14px;
      line-height: 1.65;
      color: var(--color-mocha);
    }

    /* Sourcing Directory Section */
    .sourcing-section {
      padding: 90px 0 100px;
      background: var(--color-cream);
    }

    .categories-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 28px;
    }

    .category-card {
      padding: 28px;
    }

    .category-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 18px;
      padding-bottom: 14px;
      border-bottom: 1px solid rgba(104, 88, 75, 0.1);
    }

    .category-head h4 {
      font-family: var(--font-brand);
      font-size: 18px;
      font-weight: 700;
      color: var(--color-espresso);
      margin: 0;
    }

    .ingredient-pills-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .ingredient-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 999px;
      background: rgba(217, 186, 133, 0.12);
      border: 1px solid rgba(217, 186, 133, 0.28);
      color: var(--color-espresso);
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .ingredient-tag:hover {
      background: var(--color-gold);
      color: var(--color-espresso-pure);
      border-color: var(--color-gold);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(217, 186, 133, 0.3);
    }

    /* Wizard Modal Stepper */
    .wizard-stepper {
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
      padding: 20px 32px;
      background: #FAF7F2;
      border-bottom: 1px solid rgba(104, 88, 75, 0.12);
    }

    .stepper-track-bar {
      position: absolute;
      top: 50%;
      left: 54px;
      right: 54px;
      height: 3px;
      background: rgba(104, 88, 75, 0.15);
      transform: translateY(-50%);
      z-index: 1;
    }

    .stepper-progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #D9BA85, #ECC98F);
      width: 0%;
      transition: width 0.35s ease;
    }

    .step-indicator-node {
      position: relative;
      z-index: 2;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
    }

    .step-circle {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #FFFFFF;
      border: 2px solid rgba(104, 88, 75, 0.25);
      color: var(--color-mocha);
      font-weight: 800;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.25s ease;
    }

    .step-indicator-node.active .step-circle {
      border-color: var(--color-gold);
      background: var(--color-espresso-pure);
      color: var(--color-gold);
      box-shadow: 0 0 0 4px rgba(217, 186, 133, 0.25);
    }

    .step-indicator-node.completed .step-circle {
      border-color: #16A34A;
      background: #16A34A;
      color: #FFFFFF;
    }

    .step-title-text {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--color-mocha);
    }

    .step-indicator-node.active .step-title-text {
      color: var(--color-espresso);
      font-weight: 800;
    }

    .wizard-pane {
      display: none;
    }
    .wizard-pane.active {
      display: block;
    }

    /* Form Fields */
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 16px;
    }

    .form-label {
      font-size: 11.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--color-mocha);
    }

    .form-input, .form-select, .form-textarea {
      width: 100%;
      padding: 11px 14px;
      border-radius: 12px;
      border: 1.5px solid rgba(104, 88, 75, 0.25);
      background: #FFFFFF;
      font-size: 13.5px;
      color: var(--color-espresso);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
      border-color: var(--color-gold);
      box-shadow: 0 0 0 3px rgba(217, 186, 133, 0.2);
    }

    /* Dropzone Upload Boxes */
    .dropzone-box {
      display: block;
      border: 2px dashed rgba(217, 186, 133, 0.45);
      border-radius: 16px;
      background: #FAF7F2;
      padding: 22px;
      text-align: center;
      cursor: pointer;
      transition: all 0.25s ease;
      margin-bottom: 16px;
    }

    .dropzone-box:hover {
      border-color: var(--color-gold);
      background: #F5EFE6;
    }

    .dropzone-box.has-file {
      border-color: #16A34A;
      background: #F0FDF4;
    }

    .dropzone-box input[type="file"] {
      display: none;
    }

    .dropzone-icon {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(217, 186, 133, 0.16);
      color: var(--color-gold);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }

    .dropzone-title {
      font-weight: 700;
      font-size: 13.5px;
      color: var(--color-espresso);
      margin-bottom: 4px;
    }

    .dropzone-hint {
      font-size: 12px;
      color: var(--color-mocha);
    }

    .file-badge-list {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 10px;
      justify-content: center;
    }

    .file-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      border-radius: 999px;
      background: #DCFCE7;
      color: #166534;
      font-size: 11.5px;
      font-weight: 700;
      border: 1px solid #BBF7D0;
    }

    /* Status Stepper Timeline */
    .status-stepper {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin: 20px 0;
      position: relative;
    }

    .status-step-node {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 6px;
    }

    .status-step-circle {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #F3EDE6;
      border: 2px solid rgba(104, 88, 75, 0.2);
      color: var(--color-mocha);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 13px;
    }

    .status-step-node.completed .status-step-circle {
      background: #DCFCE7;
      border-color: #16A34A;
      color: #16A34A;
    }

    .status-step-node.active .status-step-circle {
      background: var(--color-espresso-pure);
      border-color: var(--color-gold);
      color: var(--color-gold);
      box-shadow: 0 0 0 4px rgba(217, 186, 133, 0.25);
    }

    .status-step-node.declined .status-step-circle {
      background: #FEE2E2;
      border-color: #DC2626;
      color: #DC2626;
    }

    .status-step-title {
      font-size: 12.5px;
      font-weight: 700;
      color: var(--color-espresso);
    }

    .status-step-sub {
      font-size: 11px;
      color: var(--color-mocha);
    }

    @media (max-width: 900px) {
      .hero-grid { grid-template-columns: 1fr; }
      .pillars-grid { grid-template-columns: 1fr; }
      .categories-grid { grid-template-columns: 1fr; }
      .wizard-stepper { padding: 16px 20px; }
      .stepper-track-bar { left: 40px; right: 40px; }
    }
  </style>
</head>
<body class="kfs-public-page">

  <!-- ─── 2.5D Sticky Floating Header (Matches index.html) ─── -->
  <header class="brewvanti-header">
    <div class="container nav-wrap">
      <a href="index.html" class="brand-group" aria-label="BrewVanti Homepage">
        <div class="brand-logo-frame">
          <img class="brand-logo-img" src="assets/brewvanti_logo.png" alt="BrewVanti Logo" onerror="this.src='assets/brewvanti_pos_logo.svg'">
        </div>
        <div class="brand-meta">
          <h1>BrewVanti</h1>
          <span>Artisan Coffee &amp; Brew</span>
        </div>
      </a>

      <ul class="nav-links" id="publicNavLinks">
        <li><a href="index.html#menu">Artisan Menu</a></li>
        <li><a href="index.html#about">Story</a></li>
        <li><a href="careers.php">Hiring</a></li>
        <li><a href="supplier_partnership.php" class="active">Suppliers</a></li>
        <li><a href="index.html#contact">Visit Us</a></li>
      </ul>

      <div class="nav-right-actions">
        <button type="button" class="cart-indicator-pill" onclick="openTrackModal()" aria-label="Track Application Status">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
            <polyline points="15 3 21 3 21 9"/>
            <line x1="10" y1="14" x2="21" y2="3"/>
          </svg>
          Track Status
        </button>
        <a href="php/login.php" class="btn-login-cta">Staff Portal</a>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-expanded="false">☰</button>
      </div>
    </div>
  </header>

  <!-- ─── 2.5D HERO SECTION (Matches index.html) ─── -->
  <section class="hero-section">
    <div class="ambient-sparkle sp-1"></div>
    <div class="ambient-sparkle sp-2"></div>
    <div class="ambient-sparkle sp-3"></div>
    <div class="ambient-sparkle sp-4"></div>

    <div class="container">
      <div class="hero-grid">
        <div>
          <span class="hero-eyebrow">✦ SUPPLIER PARTNERSHIP &amp; ACCREDITATION</span>
          <h1 class="hero-heading">Grow your business with <em style="font-style: italic; color: var(--color-gold);">BrewVanti.</em></h1>
          <p class="hero-subtext">
            We partner with coffee origin estates, roasters, certified dairies, and sustainable packaging creators who share our dedication to ethical trade, pure ingredients, and consistent standards.
          </p>
          <div class="hero-ctas-row">
            <button type="button" class="btn-login-cta" onclick="openSupplierWizard()">
              Become a Supplier Partner &rarr;
            </button>
            <button type="button" class="cart-indicator-pill" onclick="openTrackModal()">
              Track Application Status
            </button>
          </div>
        </div>

        <!-- Hero Fast-Facts Card -->
        <div class="hero-card">
          <h3>Accredited Procurement Network</h3>
          <p>Submit your verified business permits and product certifications online to start bidding on automated digital RFQs.</p>
          <ul>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Automated purchase orders &amp; delivery schedules</span>
            </li>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Direct disbursements via PayMongo to your bank or e-wallet</span>
            </li>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"/></svg>
              <span>Secure supplier portal with real-time invoice matching</span>
            </li>
          </ul>
          <button type="button" class="btn-login-cta" style="width:100%;" onclick="openSupplierWizard()">
            Start Accreditation Wizard &rarr;
          </button>
        </div>
      </div>
    </div>
  </section>

  <!-- ─── VALUE PILLARS SECTION ─── -->
  <section class="pillars-section">
    <div class="container">
      <div class="section-head">
        <span class="section-eyebrow">PARTNERSHIP ADVANTAGES</span>
        <h2 class="section-title">Built for transparent, reliable commerce</h2>
        <p class="section-subtitle">Experience enterprise-grade digital procurement designed to keep our cafés well supplied and our suppliers paid on time.</p>
      </div>

      <div class="pillars-grid">
        <div class="luxury-card pillar-card">
          <div class="pillar-icon-box">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
            </svg>
          </div>
          <h3>Digital RFQs &amp; Direct Bidding</h3>
          <p>Access active electronic Requests for Quotations directly in the portal. Submit competitive unit bids and receive purchase order allocations without red tape.</p>
        </div>

        <div class="luxury-card pillar-card">
          <div class="pillar-icon-box">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
              <line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
          </div>
          <h3>PayMongo Direct Disbursements</h3>
          <p>Receive scheduled invoice settlements credited directly to your registered corporate bank account or verified e-wallet without payment delays or check pickups.</p>
        </div>

        <div class="luxury-card pillar-card">
          <div class="pillar-icon-box">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              <polyline points="9 12 11 14 15 10"/>
            </svg>
          </div>
          <h3>Transparent Quality Standards</h3>
          <p>Scheduled delivery notices, 3-way invoice matching against Goods Receipt Notes (GRN), and objective feedback to ensure long-term, mutually prosperous growth.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ─── ACTIVE SOURCING CATEGORIES SECTION ─── -->
  <section class="sourcing-section">
    <div class="container">
      <div class="section-head">
        <span class="section-eyebrow">SOURCING DIRECTORY</span>
        <h2 class="section-title">What we currently procure</h2>
        <p class="section-subtitle">
          Select any required material below to prefill your partnership application, or propose custom artisanal creations.
        </p>
      </div>

      <div class="categories-grid">
        <?php foreach ($categories as $cat): ?>
          <?php
            $cat_items = array_filter($all_ingredients, fn($i) => (int)$i['cat_id'] === (int)$cat['id']);
          ?>
          <div class="luxury-card category-card">
            <div class="category-head">
              <div class="pillar-icon-box" style="width:40px;height:40px;border-radius:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                  <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                  <line x1="12" y1="22.08" x2="12" y2="12"/>
                </svg>
              </div>
              <h4><?= htmlspecialchars($cat['name']) ?></h4>
            </div>

            <div class="ingredient-pills-wrap">
              <?php if (empty($cat_items)): ?>
                <span style="font-size:12.5px;color:var(--color-mocha);">General sourcing in progress.</span>
              <?php else: ?>
                <?php foreach ($cat_items as $item): ?>
                  <button type="button" class="ingredient-tag" onclick="openSupplierWizardWithItem(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>', '<?= htmlspecialchars(addslashes($cat['name'])) ?>', '<?= htmlspecialchars(addslashes($item['unit'])) ?>')">
                    <?= htmlspecialchars($item['name']) ?> &rarr;
                  </button>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ─── ON-PAGE APPLICATION STATUS TRACKER (Luxury 2.5D UI) ─── -->
  <section class="section" id="secure-application-tracking" style="padding:40px 0 80px;background:linear-gradient(180deg, #FFFFFF 0%, #FAF7F2 100%);border-top:1px solid rgba(104,88,75,0.12);">
    <div class="container" style="max-width:860px;">
      <div class="luxury-card" style="padding:44px 36px;border-radius:24px;border:1.5px solid rgba(217,186,133,0.35);background:#FFFFFF;box-shadow:0 18px 48px rgba(30,21,23,0.07);">
        
        <div style="text-align:center;max-width:620px;margin:0 auto 30px;">
          <div style="width:48px;height:48px;border-radius:14px;background:#FAF7F2;border:1.5px solid var(--color-gold);display:inline-flex;align-items:center;justify-content:center;color:var(--color-gold);margin-bottom:14px;box-shadow:0 4px 16px rgba(217,186,133,0.2);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </div>
          <span class="gold-eyebrow" style="display:block;margin-bottom:6px;">✦ REAL-TIME STATUS &amp; ACCREDITATION TRACKER</span>
          <h2 style="font-family:var(--font-brand);font-size:30px;color:var(--color-espresso);margin:0 0 10px;font-weight:700;">
            Application Status
          </h2>
          <p style="font-size:13.5px;color:var(--color-mocha);line-height:1.6;margin:0;">
            Use your emailed private link to view status, or enter your registered business email below to check real-time procurement review or request a new private link.
          </p>
        </div>

        <!-- Form Card -->
        <div style="background:#FAF7F2;border:1.5px solid rgba(217,186,133,0.28);border-radius:18px;padding:24px 28px;margin-bottom:24px;">
          <form id="onpageTrackForm" onsubmit="handleOnpageTrackSubmit(event)">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
              <div class="form-group" style="margin:0;">
                <label class="form-label" for="onpageTrackEmail" style="display:flex;align-items:center;gap:6px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                  Registered Business Email <span style="color:#DC2626;">*</span>
                </label>
                <input type="email" id="onpageTrackEmail" name="email" class="form-input" placeholder="supplier@yourcompany.com" required style="background:#FFFFFF;" />
              </div>

              <div class="form-group" style="margin:0;">
                <label class="form-label" for="onpageTrackCode" style="display:flex;align-items:center;gap:6px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="17" y2="12"/></svg>
                  Application Code (Optional)
                </label>
                <input type="text" id="onpageTrackCode" name="code" class="form-input" placeholder="e.g. KM-SUP-2026-1024" style="background:#FFFFFF;" />
              </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
              <span style="font-size:12px;color:var(--color-mocha);display:flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                Evaluation turnaround is 2–3 business days.
              </span>
              <div style="display:flex;gap:10px;margin-left:auto;flex-wrap:wrap;">
                <button type="button" class="btn-outline-gold" id="onpageRecoverBtn" onclick="handleOnpageRecoverLink()" style="padding:10px 18px;font-size:13px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                  Email Private Status Link
                </button>
                <button type="submit" class="btn-login-cta" id="onpageCheckBtn" style="padding:10px 22px;font-size:13px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  Check Status &rarr;
                </button>
              </div>
            </div>
          </form>
        </div>

        <!-- Real-Time Status Result Display -->
        <div id="onpageTrackResultArea" style="display:none;"></div>
      </div>
    </div>
  </section>

  <!-- ─── LUXURY DARK FOOTER (Matches index.html) ─── -->
  <footer class="brewvanti-footer" id="contact">
    <div class="container">
      <div class="footer-top-grid">
        <div class="footer-brand">
          <div class="brand-group">
            <div class="brand-logo-frame">
              <img class="brand-logo-img" src="assets/brewvanti_logo.png" alt="BrewVanti Logo" onerror="this.src='assets/brewvanti_pos_logo.svg'">
            </div>
            <div class="brand-meta">
              <h1>BrewVanti</h1>
              <span>Artisan Coffee &amp; Brew</span>
            </div>
          </div>
          <p>
            Crafting third-wave coffee experiences through ethically sourced beans, meticulous roasting profiles, and genuine hospitality.
          </p>
        </div>

        <div class="footer-col">
          <h5>Quick Links</h5>
          <ul>
            <li><a href="index.html">Home</a></li>
            <li><a href="index.html#featured">Featured Menu</a></li>
            <li><a href="index.html#menu">Full Drinks List</a></li>
            <li><a href="index.html#craft">The Craft &amp; Roastery</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h5>Visit Our Store</h5>
          <ul>
            <li><a href="index.html#contact">102 Artisan Ave, Manila</a></li>
            <li><a href="index.html#contact">Mon - Fri: 7:00 AM - 10:00 PM</a></li>
            <li><a href="index.html#contact">Sat - Sun: 8:00 AM - 11:00 PM</a></li>
            <li><a href="tel:+63289202739">+63 (2) 8920-BREW</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h5>Experience</h5>
          <ul>
            <li><a href="php/login.php">POS Order Terminal</a></li>
            <li><a href="index.html#contact">Table Reservation</a></li>
            <li><a href="careers.php">Careers &amp; Hiring</a></li>
            <li><a href="supplier_partnership.php">Supplier Partnership</a></li>
          </ul>
        </div>
      </div>

      <div class="footer-bottom-bar">
        <p>&copy; 2026 BrewVanti Artisan Coffee &amp; Brew. All rights reserved.</p>
        <p>Crafted with Passion &bull; Manila, Philippines</p>
      </div>
    </div>
  </footer>

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- MODAL: STEP-BY-STEP SUPPLIER APPLICATION WIZARD            -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="km-modal-backdrop" id="supplierWizardModal" onclick="handleBackdropClick(event, 'supplierWizardModal')">
    <div class="km-modal-card" style="max-width:760px;">

      <!-- Header -->
      <div class="km-modal-header">
        <div>
          <span style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-gold);">BREWVANTI PROCUREMENT ONBOARDING</span>
          <h3 class="km-modal-title">Supplier Partner Application</h3>
          <p class="km-modal-subtitle">Submit company details, product offerings, and accreditation documents for committee review.</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closeSupplierWizard()" aria-label="Close modal">&times;</button>
      </div>

      <!-- Stepper Progress Bar -->
      <div class="wizard-stepper">
        <div class="stepper-track-bar">
          <div class="stepper-progress-fill" id="stepperProgressFill"></div>
        </div>

        <div class="step-indicator-node active" id="stepIndicator1">
          <div class="step-circle">1</div>
          <span class="step-title-text">Profile</span>
        </div>
        <div class="step-indicator-node" id="stepIndicator2">
          <div class="step-circle">2</div>
          <span class="step-title-text">Offering</span>
        </div>
        <div class="step-indicator-node" id="stepIndicator3">
          <div class="step-circle">3</div>
          <span class="step-title-text">Documents</span>
        </div>
        <div class="step-indicator-node" id="stepIndicator4">
          <div class="step-circle">4</div>
          <span class="step-title-text">Review</span>
        </div>
      </div>

      <form id="supplierAppForm" onsubmit="handleWizardSubmit(event)" enctype="multipart/form-data">
        <div class="km-modal-body">
          <div id="wizardErrorMsg" style="display:none;background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px 16px;border-radius:12px;margin-bottom:18px;font-size:13px;"></div>

          <!-- STEP 1: BUSINESS PROFILE -->
          <div class="wizard-pane active" id="wizardPane1">
            <h4 style="font-family:var(--font-brand);font-size:17px;color:var(--color-espresso);margin-bottom:14px;font-weight:700;">1. Business &amp; Contact Information</h4>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_company_name">Company / Business Name <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_company_name" name="company_name" class="form-input" placeholder="e.g. Cordillera Mountain Beans Corp." required />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_biz_reg">Business Reg. / DTI / SEC No. <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_biz_reg" name="business_reg_number" class="form-input" placeholder="e.g. SEC-CS202100412" required />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_contact_person">Authorized Contact Person <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_contact_person" name="contact_person" class="form-input" placeholder="e.g. Maria Santos" required />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_email">Official Business Email <span style="color:#DC2626;">*</span></label>
                <input type="email" id="w_email" name="email" class="form-input" placeholder="procurement@yourcompany.com" required />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_phone">Business Phone / Mobile <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_phone" name="phone" class="form-input" placeholder="e.g. +63 917 123 4567" required />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_address">Head Office / Facility Address <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_address" name="address" class="form-input" placeholder="Building, Street, City, Province" required />
              </div>
            </div>
          </div>

          <!-- STEP 2: SOURCING CATEGORY & OFFERING -->
          <div class="wizard-pane" id="wizardPane2">
            <h4 style="font-family:var(--font-brand);font-size:17px;color:var(--color-espresso);margin-bottom:14px;font-weight:700;">2. Sourcing Category &amp; Product Offering</h4>

            <div class="form-group">
              <label class="form-label" for="w_ingredient_select">Select Material Category / Required Item</label>
              <select id="w_ingredient_select" name="ingredient_id" class="form-select" onchange="handleIngredientChange()">
                <option value="0">-- Custom / Other Specialty Product Proposal --</option>
                <?php foreach ($all_ingredients as $ing): ?>
                  <option value="<?= (int)$ing['id'] ?>"
                          data-name="<?= htmlspecialchars($ing['name']) ?>"
                          data-cat="<?= htmlspecialchars($ing['category_name']) ?>"
                          data-unit="<?= htmlspecialchars($ing['unit']) ?>"
                          <?= $preselected_id === (int)$ing['id'] ? 'selected' : '' ?>>
                    [<?= htmlspecialchars($ing['category_name']) ?>] <?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['unit']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_product_name">Product / Trade Name <span style="color:#DC2626;">*</span></label>
                <input type="text" id="w_product_name" name="product_name" class="form-input" placeholder="e.g. Single-Origin Benguet Arabica Beans" required />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_product_category">Product Classification</label>
                <input type="text" id="w_product_category" name="product_category" class="form-input" placeholder="e.g. Specialty Coffee" />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_proposed_price">Proposed Unit Price (PHP)</label>
                <input type="number" step="0.01" min="0" id="w_proposed_price" name="proposed_price" class="form-input" placeholder="0.00" />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_price_unit">Pricing Unit</label>
                <input type="text" id="w_price_unit" name="price_unit" class="form-input" placeholder="e.g. per kg, per pack, per liter" value="per kg" />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <div class="form-group">
                <label class="form-label" for="w_capacity">Monthly Supply Capacity</label>
                <input type="text" id="w_capacity" name="monthly_capacity" class="form-input" placeholder="e.g. 1,000 kg / month" />
              </div>
              <div class="form-group">
                <label class="form-label" for="w_moq">Minimum Order Quantity (MOQ)</label>
                <input type="text" id="w_moq" name="moq" class="form-input" placeholder="e.g. 50 kg per delivery" />
              </div>
            </div>
          </div>

          <!-- STEP 3: ACCREDITATION DOCUMENT UPLOADS -->
          <div class="wizard-pane" id="wizardPane3">
            <h4 style="font-family:var(--font-brand);font-size:17px;color:var(--color-espresso);margin-bottom:14px;font-weight:700;">3. Upload Accreditation Credentials</h4>
            <p style="font-size:12.5px;color:var(--color-mocha);margin-bottom:16px;">Accepted formats: PDF, DOC, DOCX, JPG, PNG, WEBP (Max 10 MB per file).</p>

            <!-- Box 1: Business Permit -->
            <label class="dropzone-box" id="dropzonePermit" for="input_business_permit">
              <div class="dropzone-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                </svg>
              </div>
              <div class="dropzone-title">1. Mayor's / Business Permit or SEC / DTI Certificate <span style="color:#DC2626;">*</span></div>
              <div class="dropzone-hint">Click or drag &amp; drop your valid local government permit or SEC registration certificate</div>
              <input type="file" id="input_business_permit" name="business_permit" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required onchange="handleSingleFile(this, 'badgePermit', 'dropzonePermit')" />
              <div id="badgePermit" class="file-badge-list"></div>
            </label>

            <!-- Box 2: Product Authenticity / Quality Certificate -->
            <label class="dropzone-box" id="dropzoneAuth" for="input_authenticity_cert">
              <div class="dropzone-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
              </div>
              <div class="dropzone-title">2. Product Authenticity / Quality Certificate <span style="color:#DC2626;">*</span></div>
              <div class="dropzone-hint">FDA CPR, Certificate of Analysis (COA), Halal Certificate, or Food Safety License</div>
              <input type="file" id="input_authenticity_cert" name="authenticity_cert" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required onchange="handleSingleFile(this, 'badgeAuth', 'dropzoneAuth')" />
              <div id="badgeAuth" class="file-badge-list"></div>
            </label>

            <!-- Box 3: Multi-File Supplementary Documents -->
            <label class="dropzone-box" id="dropzoneMulti" for="input_multi_additional">
              <div class="dropzone-icon">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
              </div>
              <div class="dropzone-title">3. Supplementary Documents &amp; Price Quotation (Multiple Files Allowed)</div>
              <div class="dropzone-hint">Click or drag &amp; drop official quotation sheets, catalogs, lab tests, farm photos</div>
              <input type="file" id="input_multi_additional" name="additional_documents[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" onchange="handleMultiFiles(this)" />
              <div id="badgeMultiList" class="file-badge-list"></div>
            </label>

            <div class="form-group" style="margin-top:16px;">
              <label class="form-label" for="w_notes">Company Profile Notes &amp; Message to Procurement Management</label>
              <textarea id="w_notes" name="company_profile_notes" class="form-textarea" rows="2" placeholder="Tell us about your production facilities, harvest cycles, existing café partners, or logistics lead times..."></textarea>
            </div>
          </div>

          <!-- STEP 4: REVIEW & CONFIRM -->
          <div class="wizard-pane" id="wizardPane4">
            <h4 style="font-family:var(--font-brand);font-size:17px;color:var(--color-espresso);margin-bottom:14px;font-weight:700;">4. Review Application Summary</h4>

            <div style="background:#FAF7F2;border:1.5px solid rgba(217,186,133,0.3);border-radius:16px;padding:22px;margin-bottom:20px;font-size:13px;line-height:1.6;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;padding-bottom:14px;border-bottom:1px dashed rgba(104,88,75,0.2);">
                <div>
                  <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Company Name</span>
                  <div id="revCompName" style="font-weight:700;color:var(--color-espresso);font-size:14px;"></div>
                </div>
                <div>
                  <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Contact Person</span>
                  <div id="revContact" style="font-weight:700;color:var(--color-espresso);"></div>
                </div>
                <div>
                  <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Email</span>
                  <div id="revEmail"></div>
                </div>
                <div>
                  <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Phone</span>
                  <div id="revPhone"></div>
                </div>
              </div>

              <div style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px dashed rgba(104,88,75,0.2);">
                <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Product &amp; Pricing</span>
                <div id="revProduct" style="font-weight:700;color:var(--color-espresso);margin-top:2px;font-size:14px;"></div>
                <div id="revPricing" style="color:#16A34A;font-weight:700;margin-top:2px;"></div>
              </div>

              <div>
                <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;">Documents Ready for Upload</span>
                <div id="revDocsSummary" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;"></div>
              </div>
            </div>

            <!-- Consent Checkbox -->
            <div style="background:#FFFFFF;border:1px solid rgba(104,88,75,0.18);border-radius:14px;padding:16px;margin-bottom:16px;">
              <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-size:12.5px;color:var(--color-espresso);line-height:1.5;">
                <input type="checkbox" id="w_privacy_consent" name="privacy_consent" value="1" required style="margin-top:3px;accent-color:var(--color-gold);" />
                <span>
                  I certify that all information and uploaded permits/authenticity documents are true, genuine, and legally valid. I agree to BrewVanti's Supplier Code of Conduct and authorize the procurement team to verify credentials for supplier accreditation.
                </span>
              </label>
            </div>
          </div>
        </div>

        <!-- Wizard Navigation Footer -->
        <div class="km-modal-footer">
          <button type="button" class="btn-outline-gold" id="wizardBackBtn" onclick="prevWizardStep()" style="display:none;">
            &larr; Back
          </button>
          <div style="margin-left:auto;display:flex;gap:10px;">
            <button type="button" class="btn-outline-gold" onclick="closeSupplierWizard()">
              Cancel
            </button>
            <button type="button" class="btn-login-cta" id="wizardNextBtn" onclick="nextWizardStep()">
              Next Step &rarr;
            </button>
            <button type="submit" class="btn-login-cta" id="wizardSubmitBtn" style="display:none;">
              Submit Application &rarr;
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- MODAL: APPLICATION STATUS TRACKER (OVERHAULED UI)          -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="km-modal-backdrop" id="trackModalBackdrop" onclick="handleBackdropClick(event, 'trackModalBackdrop')">
    <div class="km-modal-card" style="max-width:640px;">
      <div class="km-modal-header">
        <div>
          <span style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-gold);">ACCREDITATION REVIEW TRACKING</span>
          <h3 class="km-modal-title">Track Application Status</h3>
          <p class="km-modal-subtitle">Check real-time procurement evaluation progress or recover your tracking link.</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closeTrackModal()" aria-label="Close modal">&times;</button>
      </div>

      <div class="km-modal-body">
        <!-- Search Query Box -->
        <div style="background:#FAF7F2;border:1.5px solid rgba(217,186,133,0.3);border-radius:16px;padding:20px;margin-bottom:20px;">
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label" for="trackQueryCode">Application Code or Tracking Token</label>
            <input type="text" id="trackQueryCode" class="form-input" placeholder="e.g. KM-SUP-2026-1024 (Optional if recovering by email)" />
          </div>
          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label" for="trackQueryEmail">Registered Business Email <span style="color:#DC2626;">*</span></label>
            <input type="email" id="trackQueryEmail" class="form-input" placeholder="sales@yourdomain.com" required />
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" id="trackCheckBtn" class="btn-login-cta" onclick="handleCheckStatus()">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Check Status
            </button>
            <button type="button" id="trackRecoverBtn" class="btn-outline-gold" onclick="handleRecoverEmailLink()">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
              Email Private Status Link
            </button>
          </div>
        </div>

        <!-- Result Card Area -->
        <div id="trackResultCard" style="display:none;"></div>
      </div>

      <div class="km-modal-footer">
        <button type="button" class="btn-outline-gold" onclick="closeTrackModal()">Close</button>
        <button type="button" class="btn-login-cta" onclick="openSupplierWizard()">Submit New Application &rarr;</button>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════ -->
  <!-- MODAL: APPLICATION SUBMISSION SUCCESS                      -->
  <!-- ══════════════════════════════════════════════════════════ -->
  <div class="km-modal-backdrop" id="successModalBackdrop">
    <div class="km-modal-card" style="max-width:520px;text-align:center;">
      <div style="padding:40px 32px;">
        <div style="width:68px;height:68px;border-radius:50%;background:#DCFCE7;border:2px solid #86EFAC;color:#166534;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
          <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>

        <h3 style="font-family:var(--font-brand);font-size:24px;color:var(--color-espresso);margin-bottom:8px;font-weight:700;">
          Application Received!
        </h3>
        <p style="font-size:13.5px;color:var(--color-mocha);line-height:1.6;margin-bottom:20px;">
          Your accreditation documents and product details have been registered and transmitted to the BrewVanti procurement committee.
        </p>

        <div style="background:#FAF7F2;border:1.5px dashed var(--color-gold);border-radius:14px;padding:18px;margin-bottom:20px;">
          <span style="font-size:11px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;letter-spacing:1px;display:block;">Your Application Tracking Code</span>
          <div id="newTrackingCodeText" style="font-family:monospace;font-size:24px;font-weight:800;color:var(--color-espresso);margin-top:6px;letter-spacing:0.04em;"></div>
        </div>

        <p style="font-size:12px;color:var(--color-mocha);margin-bottom:24px;">
          A confirmation email has been dispatched. Please save your code to monitor review progress anytime.
        </p>

        <div style="display:flex;gap:12px;justify-content:center;">
          <button type="button" class="btn-outline-gold" onclick="closeSuccessModal()">Done</button>
          <button type="button" class="btn-login-cta" onclick="openTrackWithCode()">Track Now &rarr;</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── JAVASCRIPT LOGIC ───────────────────────────────────── -->
  <script>
    let currentStep = 1;

    // Mobile nav toggle
    const navToggle = document.getElementById('navToggle');
    const publicNav = document.getElementById('publicNavLinks');
    if (navToggle && publicNav) {
      navToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = publicNav.classList.toggle('open');
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', (e) => {
        if (!publicNav.contains(e.target) && !navToggle.contains(e.target)) {
          publicNav.classList.remove('open');
          navToggle.setAttribute('aria-expanded', 'false');
        }
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && publicNav.classList.contains('open')) {
          publicNav.classList.remove('open');
          navToggle.setAttribute('aria-expanded', 'false');
          navToggle.focus();
        }
      });
    }

    // Modal helpers
    function handleBackdropClick(e, id) {
      if (e.target.id === id) {
        document.getElementById(id).classList.remove('open');
      }
    }

    function openSupplierWizard() {
      currentStep = 1;
      updateWizardUI();
      document.getElementById('supplierWizardModal').classList.add('open');
      document.getElementById('w_company_name').focus();
    }

    function openSupplierWizardWithItem(id, name, cat, unit) {
      openSupplierWizard();
      const select = document.getElementById('w_ingredient_select');
      select.value = String(id);
      handleIngredientChange();

      if (id === 0) {
        document.getElementById('w_product_name').value = '';
        document.getElementById('w_product_category').value = cat || 'Custom';
        document.getElementById('w_price_unit').value = 'per unit';
      }
    }

    function closeSupplierWizard() {
      document.getElementById('supplierWizardModal').classList.remove('open');
    }

    function handleIngredientChange() {
      const select = document.getElementById('w_ingredient_select');
      const opt = select.options[select.selectedIndex];
      const prodInput = document.getElementById('w_product_name');
      const catInput = document.getElementById('w_product_category');
      const unitInput = document.getElementById('w_price_unit');

      if (select.value === '0') {
        prodInput.value = '';
        prodInput.placeholder = 'e.g. Signature Cold Brew Concentrate';
        catInput.value = 'Custom / Other';
        unitInput.value = 'per unit';
      } else {
        prodInput.value = opt.getAttribute('data-name') || '';
        catInput.value = opt.getAttribute('data-cat') || 'Supplies';
        unitInput.value = 'per ' + (opt.getAttribute('data-unit') || 'kg');
      }
    }

    // Multi-step Navigation
    function validateStep(step) {
      const errBox = document.getElementById('wizardErrorMsg');
      errBox.style.display = 'none';
      errBox.innerText = '';

      if (step === 1) {
        const comp = document.getElementById('w_company_name').value.trim();
        const contact = document.getElementById('w_contact_person').value.trim();
        const email = document.getElementById('w_email').value.trim();
        const phone = document.getElementById('w_phone').value.trim();
        const addr = document.getElementById('w_address').value.trim();

        if (!comp || !contact || !email || !phone || !addr) {
          errBox.style.display = 'block';
          errBox.innerText = 'Please complete all required fields in Business & Contact Information.';
          return false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          errBox.style.display = 'block';
          errBox.innerText = 'Please enter a valid business email address.';
          return false;
        }
      } else if (step === 2) {
        const prod = document.getElementById('w_product_name').value.trim();
        if (!prod) {
          errBox.style.display = 'block';
          errBox.innerText = 'Please enter your product / material name.';
          return false;
        }
      } else if (step === 3) {
        const permit = document.getElementById('input_business_permit').files[0];
        const auth = document.getElementById('input_authenticity_cert').files[0];
        if (!permit) {
          errBox.style.display = 'block';
          errBox.innerText = 'Please upload your Business Permit / Registration document.';
          return false;
        }
        if (!auth) {
          errBox.style.display = 'block';
          errBox.innerText = 'Please upload your Product Authenticity / Quality Certificate.';
          return false;
        }
      }
      return true;
    }

    function nextWizardStep() {
      if (!validateStep(currentStep)) return;
      if (currentStep < 4) {
        currentStep++;
        if (currentStep === 4) populateReviewSummary();
        updateWizardUI();
      }
    }

    function prevWizardStep() {
      if (currentStep > 1) {
        currentStep--;
        updateWizardUI();
      }
    }

    function updateWizardUI() {
      // Panes
      for (let i = 1; i <= 4; i++) {
        const pane = document.getElementById('wizardPane' + i);
        const indicator = document.getElementById('stepIndicator' + i);
        if (pane) pane.classList.toggle('active', i === currentStep);
        if (indicator) {
          indicator.classList.toggle('active', i === currentStep);
          indicator.classList.toggle('completed', i < currentStep);
        }
      }

      // Progress bar fill
      const fillEl = document.getElementById('stepperProgressFill');
      if (fillEl) {
        fillEl.style.width = ((currentStep - 1) / 3 * 100) + '%';
      }

      // Buttons
      document.getElementById('wizardBackBtn').style.display = currentStep > 1 ? 'inline-flex' : 'none';
      document.getElementById('wizardNextBtn').style.display = currentStep < 4 ? 'inline-flex' : 'none';
      document.getElementById('wizardSubmitBtn').style.display = currentStep === 4 ? 'inline-flex' : 'none';
    }

    // Single File badge update
    function handleSingleFile(input, badgeId, boxId) {
      const box = document.getElementById(boxId);
      const badgeArea = document.getElementById(badgeId);
      badgeArea.innerHTML = '';

      if (input.files && input.files[0]) {
        const file = input.files[0];
        box.classList.add('has-file');
        const chip = document.createElement('span');
        chip.className = 'file-chip';
        chip.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> ' +
          escapeHtml(file.name) + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        badgeArea.appendChild(chip);
      } else {
        box.classList.remove('has-file');
      }
    }

    // Multi-File attachments handling
    function handleMultiFiles(input) {
      const badgeList = document.getElementById('badgeMultiList');
      const box = document.getElementById('dropzoneMulti');
      badgeList.innerHTML = '';

      if (input.files && input.files.length > 0) {
        box.classList.add('has-file');
        Array.from(input.files).forEach((f) => {
          const chip = document.createElement('span');
          chip.className = 'file-chip';
          chip.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg> ' +
            escapeHtml(f.name) + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
          badgeList.appendChild(chip);
        });
      } else {
        box.classList.remove('has-file');
      }
    }

    function populateReviewSummary() {
      document.getElementById('revCompName').innerText = document.getElementById('w_company_name').value;
      document.getElementById('revContact').innerText = document.getElementById('w_contact_person').value;
      document.getElementById('revEmail').innerText = document.getElementById('w_email').value;
      document.getElementById('revPhone').innerText = document.getElementById('w_phone').value;
      document.getElementById('revProduct').innerText = document.getElementById('w_product_name').value + ' (' + document.getElementById('w_product_category').value + ')';

      const price = document.getElementById('w_proposed_price').value;
      const unit = document.getElementById('w_price_unit').value || 'unit';
      document.getElementById('revPricing').innerText = price ? '₱' + Number(price).toFixed(2) + ' ' + unit : 'Quoted on RFQ';

      const docsArea = document.getElementById('revDocsSummary');
      docsArea.innerHTML = '';

      const permit = document.getElementById('input_business_permit').files[0];
      const auth = document.getElementById('input_authenticity_cert').files[0];
      const addl = document.getElementById('input_multi_additional').files;

      if (permit) {
        docsArea.innerHTML += '<span class="file-chip">Permit: ' + escapeHtml(permit.name) + '</span>';
      }
      if (auth) {
        docsArea.innerHTML += '<span class="file-chip">Certificate: ' + escapeHtml(auth.name) + '</span>';
      }
      if (addl && addl.length > 0) {
        docsArea.innerHTML += '<span class="file-chip">+' + addl.length + ' additional attachment(s)</span>';
      }
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Submit Handler
    async function handleWizardSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('supplierAppForm');
      const submitBtn = document.getElementById('wizardSubmitBtn');
      const errBox = document.getElementById('wizardErrorMsg');

      errBox.style.display = 'none';
      submitBtn.disabled = true;
      submitBtn.innerText = 'Submitting & uploading…';

      const formData = new FormData(form);

      try {
        const resp = await fetch('api/submit_supplier_application.php', {
          method: 'POST',
          body: formData
        });
        const data = await resp.json();

        if (!data.success) {
          errBox.style.display = 'block';
          errBox.innerText = data.error || 'Submission failed. Please check your form and attachments.';
          submitBtn.disabled = false;
          submitBtn.innerText = 'Submit Application';
          return;
        }

        form.reset();
        closeSupplierWizard();
        openSuccessModal(data.tracking_code);

      } catch (err) {
        errBox.style.display = 'block';
        errBox.innerText = 'A network error occurred while uploading. Please check your connection and try again.';
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Submit Application';
      }
    }

    // Success Modal
    function openSuccessModal(code) {
      document.getElementById('newTrackingCodeText').innerText = code;
      document.getElementById('successModalBackdrop').classList.add('open');
    }
    function closeSuccessModal() {
      document.getElementById('successModalBackdrop').classList.remove('open');
    }
    function openTrackWithCode() {
      const code = document.getElementById('newTrackingCodeText').innerText;
      closeSuccessModal();
      document.getElementById('trackQueryCode').value = code;
      openTrackModal();
      handleCheckStatus();
    }

    // ── APPLICATION STATUS TRACKER UI ────────────────────────
    function openTrackModal() {
      document.getElementById('trackModalBackdrop').classList.add('open');
      document.getElementById('trackQueryEmail').focus();
    }
    function closeTrackModal() {
      document.getElementById('trackModalBackdrop').classList.remove('open');
    }

    async function handleCheckStatus() {
      const code = document.getElementById('trackQueryCode').value.trim();
      const email = document.getElementById('trackQueryEmail').value.trim();
      const card = document.getElementById('trackResultCard');

      if (!email) {
        card.style.display = 'block';
        card.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px;border-radius:10px;font-size:13px;">Please enter your registered email address.</div>';
        return;
      }

      card.style.display = 'block';
      card.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-mocha);font-size:13px;">Verifying credentials and fetching review status…</div>';

      try {
        const resp = await fetch('api/track_supplier_application.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'recover', email: email, code: code })
        });
        const data = await resp.json();

        renderStatusResult(data, code, email);
      } catch (err) {
        card.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px;border-radius:10px;font-size:13px;">The status service is temporarily unavailable. Please try again later.</div>';
      }
    }

    async function handleRecoverEmailLink() {
      const email = document.getElementById('trackQueryEmail').value.trim();
      const card = document.getElementById('trackResultCard');

      if (!email) {
        card.style.display = 'block';
        card.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px;border-radius:10px;font-size:13px;">Please enter your registered email address.</div>';
        return;
      }

      card.style.display = 'block';
      card.innerHTML = '<div style="text-align:center;padding:20px;color:var(--color-mocha);font-size:13px;">Sending private link to your email…</div>';

      try {
        const resp = await fetch('api/track_supplier_application.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'recover', email: email })
        });
        const data = await resp.json();

        card.innerHTML = `
          <div style="background:#ECFDF5;border:1.5px solid #A7F3D0;border-radius:14px;padding:20px;color:#065F46;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
              <div style="width:36px;height:36px;border-radius:50%;background:#D1FAE5;display:flex;align-items:center;justify-content:center;color:#059669;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              </div>
              <h4 style="margin:0;font-size:15px;color:#065F46;font-weight:700;">Private Link Dispatched</h4>
            </div>
            <p style="font-size:13px;line-height:1.6;margin:0;">
              ${escapeHtml(data.message || 'If an application matches this email, a private status link has been sent.')}
              Please check your inbox and click the private link to review your real-time accreditation status.
            </p>
          </div>
        `;
      } catch (err) {
        card.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px;border-radius:10px;font-size:13px;">Unable to reach status link service.</div>';
      }
    }

    function renderStatusResult(data, code, email, targetId = 'trackResultCard') {
      const card = document.getElementById(targetId);
      if (!card) return;
      card.style.display = 'block';

      if (!data.success && data.error) {
        card.innerHTML = `<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:14px;border-radius:12px;font-size:13px;">${escapeHtml(data.error)}</div>`;
        return;
      }

      const status = data.status || 'review';
      const submitted = data.submitted_at || 'Recently submitted';
      const comp = data.company_name || 'Registered Applicant';
      const prod = data.product_name || 'Supplies';
      const appCode = data.application_code || code || 'KM-SUP-PENDING';

      const isApproved = (status === 'approved');
      const isDeclined = (status === 'rejected');

      let badgeBg = '#FEF3C7';
      let badgeColor = '#92400E';
      let badgeLabel = 'Under Review';
      if (isApproved) { badgeBg = '#DCFCE7'; badgeColor = '#166534'; badgeLabel = 'Accredited Partner'; }
      if (isDeclined) { badgeBg = '#FEE2E2'; badgeColor = '#991B1B'; badgeLabel = 'Declined'; }

      card.innerHTML = `
        <div style="background:#FFFFFF;border:1.5px solid rgba(217,186,133,0.3);border-radius:18px;padding:22px;box-shadow:0 8px 24px rgba(30,21,23,0.06);">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
            <div>
              <span style="font-family:monospace;font-size:12px;color:var(--color-gold);font-weight:700;display:block;">${escapeHtml(appCode)}</span>
              <h4 style="margin:2px 0 0;font-size:17px;color:var(--color-espresso);font-family:var(--font-brand);">${escapeHtml(comp)}</h4>
              <span style="font-size:12.5px;color:var(--color-mocha);">${escapeHtml(prod)}</span>
            </div>
            <span style="background:${badgeBg};color:${badgeColor};font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:0.04em;">
              ${badgeLabel}
            </span>
          </div>

          <!-- Visual Progress Stepper -->
          <div class="status-stepper">
            <div class="status-step-node completed">
              <div class="status-step-circle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <div class="status-step-title">Submitted</div>
              <div class="status-step-sub">${escapeHtml(submitted)}</div>
            </div>

            <div class="status-step-node ${isApproved || isDeclined ? 'completed' : 'active'}">
              <div class="status-step-circle">
                ${isApproved || isDeclined ?
                  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' :
                  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="9"/><polyline points="12 6 12 12 16 14"/></svg>'
                }
              </div>
              <div class="status-step-title">Committee Review</div>
              <div class="status-step-sub">${isApproved || isDeclined ? 'Completed' : 'In Progress'}</div>
            </div>

            <div class="status-step-node ${isApproved ? 'completed' : (isDeclined ? 'declined' : '')}">
              <div class="status-step-circle">
                ${isApproved ?
                  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>' :
                  (isDeclined ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' : '<span style="font-size:12px;font-weight:700;">3</span>')
                }
              </div>
              <div class="status-step-title">${isApproved ? 'Accredited' : (isDeclined ? 'Decision Sent' : 'Accreditation')}</div>
              <div class="status-step-sub">${isApproved ? 'Portal Active' : (isDeclined ? 'Notice Emailed' : 'Pending')}</div>
            </div>
          </div>

          <!-- Status Guidance Banner -->
          <div style="background:#FAF7F2;border:1px solid rgba(104,88,75,0.14);border-radius:12px;padding:16px;font-size:13px;line-height:1.6;">
            ${isApproved ? `
              <div style="color:#166534;font-weight:700;margin-bottom:6px;">✓ Accreditation Confirmed</div>
              <p style="color:var(--color-espresso);margin:0 0 12px;">Your application has been approved by BrewVanti Procurement Management. Your official login credentials have been dispatched to your email.</p>
              <a href="php/login.php" class="btn-login-cta">Sign In to Supplier Portal &rarr;</a>
            ` : (isDeclined ? `
              <div style="color:#991B1B;font-weight:700;margin-bottom:4px;">Application Not Accredited at this Time</div>
              <p style="color:var(--color-mocha);margin:0;">Official evaluation feedback was transmitted to ${escapeHtml(email)}. You may submit an updated dossier once revised credentials are available.</p>
            ` : `
              <div style="color:#92400E;font-weight:700;margin-bottom:4px;">Dossier Under Verification</div>
              <p style="color:var(--color-mocha);margin:0;">Your submitted business registration and authenticity documents are undergoing inspection by our procurement evaluation committee. Standard turnaround is 2–3 business days.</p>
            `)}
          </div>
        </div>
      `;
    }

    // ── On-Page Tracker Form Handlers ─────────────────────
    async function handleOnpageTrackSubmit(e) {
      e.preventDefault();
      const code = document.getElementById('onpageTrackCode').value.trim();
      const email = document.getElementById('onpageTrackEmail').value.trim();
      const area = document.getElementById('onpageTrackResultArea');

      if (!email) {
        area.style.display = 'block';
        area.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:14px;border-radius:12px;font-size:13px;">Please enter your registered business email address.</div>';
        return;
      }

      area.style.display = 'block';
      area.innerHTML = '<div style="text-align:center;padding:28px;color:var(--color-mocha);font-size:13px;">Verifying credentials and fetching review status…</div>';

      try {
        const resp = await fetch('api/track_supplier_application.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'recover', email: email, code: code })
        });
        const data = await resp.json();
        renderStatusResult(data, code, email, 'onpageTrackResultArea');
      } catch (err) {
        area.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:14px;border-radius:12px;font-size:13px;">The status service is temporarily unavailable. Please try again later.</div>';
      }
    }

    async function handleOnpageRecoverLink() {
      const email = document.getElementById('onpageTrackEmail').value.trim();
      const area = document.getElementById('onpageTrackResultArea');

      if (!email) {
        area.style.display = 'block';
        area.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:14px;border-radius:12px;font-size:13px;">Please enter your registered business email address first.</div>';
        return;
      }

      area.style.display = 'block';
      area.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-mocha);font-size:13px;">Sending private link to your email…</div>';

      try {
        const resp = await fetch('api/track_supplier_application.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'recover', email: email })
        });
        const data = await resp.json();

        area.innerHTML = `
          <div style="background:#ECFDF5;border:1.5px solid #A7F3D0;border-radius:14px;padding:22px;color:#065F46;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
              <div style="width:36px;height:36px;border-radius:50%;background:#D1FAE5;display:flex;align-items:center;justify-content:center;color:#059669;flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              </div>
              <h4 style="margin:0;font-size:15px;color:#065F46;font-weight:700;">Private Link Dispatched</h4>
            </div>
            <p style="font-size:13px;line-height:1.6;margin:0;">
              ${escapeHtml(data.message || 'If an application matches this email, a private status link has been sent.')}
              Please check your inbox and click the private link to review your real-time accreditation status.
            </p>
          </div>
        `;
      } catch (err) {
        area.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:14px;border-radius:12px;font-size:13px;">Unable to reach status link service.</div>';
      }
    }

    // Auto-trigger if URL has hash with token #track=...
    const urlHash = new URLSearchParams(window.location.hash.slice(1));
    const tokenFromUrl = urlHash.get('track');
    if (tokenFromUrl) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
      const onpageArea = document.getElementById('onpageTrackResultArea');
      if (onpageArea) {
        onpageArea.style.display = 'block';
        onpageArea.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-mocha);font-size:13px;">Validating private tracking link…</div>';
      }
      openTrackModal();
      document.getElementById('trackResultCard').style.display = 'block';
      document.getElementById('trackResultCard').innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-mocha);font-size:13px;">Validating private tracking link…</div>';
      fetch('api/track_supplier_application.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: tokenFromUrl })
      })
      .then(r => r.json())
      .then(d => {
        renderStatusResult(d, '', '', 'trackResultCard');
        if (onpageArea) renderStatusResult(d, '', '', 'onpageTrackResultArea');
      })
      .catch(() => {
        const errHtml = '<div style="background:#FEE2E2;border:1px solid #FCA5A5;color:#991B1B;padding:12px;border-radius:10px;font-size:13px;">Invalid or expired tracking link.</div>';
        document.getElementById('trackResultCard').innerHTML = errHtml;
        if (onpageArea) onpageArea.innerHTML = errHtml;
      });
    }
  </script>
</body>
</html>
