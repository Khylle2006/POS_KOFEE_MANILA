<?php
// ─────────────────────────────────────────────────────────────
//  Kofee Manila — Supplier Partnership & Accreditation Portal
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
  <title>Supplier Partnership &amp; Accreditation — Kofee Manila</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/careers.css?v=<?= filemtime(__DIR__ . '/css/careers.css') ?>">
  <style>
    .km-procure-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 20px;
      margin: 32px 0 48px;
    }
    .km-procure-card {
      background: #FFFFFF;
      border: 1.5px solid var(--km-border);
      border-radius: 16px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .km-procure-card:hover {
      transform: translateY(-4px);
      border-color: var(--km-caramel);
      box-shadow: 0 12px 28px -8px rgba(36,26,46,0.12);
    }
    .km-card-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: #FAF7F2;
      border: 1px solid var(--km-border);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--km-caramel);
      margin-bottom: 16px;
      font-size: 20px;
    }
    .km-tag-pill {
      display: inline-block;
      padding: 4px 10px;
      background: #EFE8DF;
      color: var(--km-caramel);
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      margin-bottom: 8px;
    }
    .km-form-container {
      background: #FFFFFF;
      border: 1.5px solid var(--km-border);
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 12px 36px -12px rgba(36,26,46,0.08);
      max-width: 860px;
      margin: 0 auto 60px;
    }
    .km-section-divider {
      margin: 32px 0 24px;
      padding-top: 24px;
      border-top: 1px dashed var(--km-border);
    }
    .km-req-badge {
      color: #DC2626;
      font-weight: 700;
      margin-left: 2px;
    }
    .km-file-upload-box {
      border: 2px dashed #D6C7B7;
      background: #FAF7F2;
      border-radius: 12px;
      padding: 22px 18px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
      display: block;
    }
    .km-file-upload-box:hover {
      border-color: var(--km-caramel);
      background: #FDFBF8;
      box-shadow: 0 4px 12px rgba(36,26,46,0.04);
    }
    .km-file-upload-box.has-file {
      border-color: #16A34A;
      border-style: solid;
      background: #F0FDF4;
    }
    .km-file-upload-box input[type="file"] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
      width: 100%;
      height: 100%;
      z-index: 2;
    }
    .km-uploaded-filename {
      font-size: 12px;
      font-weight: 700;
      color: #166534;
      margin-top: 8px;
      display: none;
      padding: 4px 10px;
      background: #DCFCE7;
      border-radius: 6px;
      word-break: break-all;
    }
  </style>
</head>
<body>

  <!-- TOP NAVIGATION HEADER -->
  <header class="km-header">
    <div class="container">
      <div class="km-header-inner">
        <!-- Brand -->
        <a href="index.html" class="km-brand">
          <div class="km-brand-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
              <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
              <line x1="6" y1="1" x2="6" y2="4"/>
              <line x1="10" y1="1" x2="10" y2="4"/>
              <line x1="14" y1="1" x2="14" y2="4"/>
            </svg>
          </div>
          <span class="km-brand-name">Kofee Manila</span>
        </a>

        <!-- Navigation Links -->
        <nav class="km-nav-links">
          <a href="index.html" class="km-nav-link">Home</a>
          <a href="supplier_partnership.php" class="km-nav-link active">Suppliers</a>
          <a href="careers.php" class="km-nav-link">Careers</a>
          <a href="index.html#about" class="km-nav-link">About Us</a>
          <a href="auth/login.php" class="km-nav-link">Portal Login</a>
        </nav>

        <!-- Right Action Button -->
        <div>
          <button type="button" class="km-track-btn" onclick="openTrackModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
              <polyline points="15 3 21 3 21 9"/>
              <line x1="10" y1="14" x2="21" y2="3"/>
            </svg>
            Track Application
          </button>
        </div>
      </div>
    </div>
  </header>

  <!-- HERO SECTION -->
  <section class="km-careers-hero">
    <div class="container" style="position:relative;">

      <!-- Watermark Art -->
      <div class="km-art-watermark" aria-hidden="true">
        <svg class="km-coffee-rings" viewBox="0 0 200 200" fill="none">
          <circle cx="105" cy="95" r="70" stroke="#9A6B46" stroke-width="6.5" stroke-dasharray="14 4 40 8 20 6" opacity="0.4" />
          <circle cx="105" cy="95" r="76" stroke="#C4936B" stroke-width="2" opacity="0.25" />
          <circle cx="103" cy="93" r="64" stroke="#7A4D2A" stroke-width="3" stroke-dasharray="8 6 24 5" opacity="0.3" />
          <circle cx="130" cy="115" r="48" stroke="#8E5D38" stroke-width="5" stroke-dasharray="12 4 30 6" opacity="0.35" />
          <circle cx="130" cy="115" r="54" stroke="#B8855F" stroke-width="1.8" opacity="0.2" />
        </svg>
        <div class="km-script-text">
          Ethical Trade
          <span>Quality First</span>
        </div>
      </div>

      <span class="km-hero-eyebrow">SUPPLIER PARTNERSHIP &amp; ACCREDITATION</span>
      <h1 class="km-hero-title">Grow your business with Kofee Manila.</h1>
      <p class="km-hero-desc">
        We partner with origin growers, roasters, certified dairies, and packaging artisans who share our dedication to craftsmanship, ethical sourcing, and consistent standards. Apply to supply your products and join our digital procurement network.
      </p>

      <div style="display:flex;gap:12px;margin-top:28px;flex-wrap:wrap">
        <a href="#apply-section" class="km-hero-search-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
          <span>Become a Supplier Partner</span> &rarr;
        </a>
        <button type="button" class="km-track-btn" onclick="openTrackModal()" style="font-size:14px;padding:12px 24px;">
          Check Application Status
        </button>
      </div>

    </div>
  </section>

  <!-- VALUE PILLARS -->
  <section class="container" style="padding-top:20px;padding-bottom:20px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:48px;">
      <div style="background:#FFFFFF;border:1.5px solid var(--km-border);border-radius:18px;padding:26px;">
        <div style="width:42px;height:42px;border-radius:12px;background:#FAF7F2;display:flex;align-items:center;justify-content:center;color:var(--km-caramel);margin-bottom:14px;font-size:20px;">⚡</div>
        <h3 style="font-size:16.5px;color:var(--km-text-dark);margin-bottom:6px;font-weight:700;">Digital RFQs &amp; Direct Bidding</h3>
        <p style="font-size:13px;color:var(--km-text-muted);line-height:1.6;">Access electronic Requests for Quotations directly in the Supplier Portal and submit competitive bids seamlessly.</p>
      </div>
      <div style="background:#FFFFFF;border:1.5px solid var(--km-border);border-radius:18px;padding:26px;">
        <div style="width:42px;height:42px;border-radius:12px;background:#FAF7F2;display:flex;align-items:center;justify-content:center;color:var(--km-caramel);margin-bottom:14px;font-size:20px;">💳</div>
        <h3 style="font-size:16.5px;color:var(--km-text-dark);margin-bottom:6px;font-weight:700;">PayMongo Direct Disbursements</h3>
        <p style="font-size:13px;color:var(--km-text-muted);line-height:1.6;">Receive invoice settlements directly to your registered corporate bank account or e-wallet without payment delays.</p>
      </div>
      <div style="background:#FFFFFF;border:1.5px solid var(--km-border);border-radius:18px;padding:26px;">
        <div style="width:42px;height:42px;border-radius:12px;background:#FAF7F2;display:flex;align-items:center;justify-content:center;color:var(--km-caramel);margin-bottom:14px;font-size:20px;">🤝</div>
        <h3 style="font-size:16.5px;color:var(--km-text-dark);margin-bottom:6px;font-weight:700;">Transparent Partnership</h3>
        <p style="font-size:13px;color:var(--km-text-muted);line-height:1.6;">Fair contract terms, scheduled delivery notices, 3-way invoice matching, and objective supplier performance ratings.</p>
      </div>
    </div>
  </section>

  <!-- WHAT WE SOURCE / PROCUREMENT CATEGORIES -->
  <section class="container">
    <div style="text-align:center;max-width:620px;margin:0 auto 24px;">
      <span class="km-hero-eyebrow">ACTIVE SOURCING CATEGORIES</span>
      <h2 style="font-family:var(--km-font-serif);font-size:32px;color:var(--km-text-dark);margin:8px 0 12px;">What We Look For</h2>
      <p style="font-size:14.5px;color:var(--km-text-muted);line-height:1.65;">
        Explore the primary supplies and ingredients we currently source for our cafés. Click on any item to prefill your partnership application.
      </p>
    </div>

    <div class="km-procure-grid">
      <?php foreach ($all_ingredients as $ing): ?>
        <div class="km-procure-card">
          <div>
            <span class="km-tag-pill"><?= htmlspecialchars($ing['category_name'] ?: 'Supplies') ?></span>
            <h3 style="font-size:17px;font-weight:700;color:var(--km-text-dark);margin-bottom:6px;">
              <?= htmlspecialchars($ing['name']) ?>
            </h3>
            <p style="font-size:12.5px;color:var(--km-text-muted);line-height:1.5;margin-bottom:14px;">
              Standard Measure: <strong><?= htmlspecialchars($ing['unit'] ?: 'units') ?></strong> &bull; Requires valid product authenticity &amp; food safety compliance.
            </p>
          </div>
          <button type="button" class="km-track-btn" style="width:100%;text-align:center;justify-content:center;"
                  onclick="selectIngredient(<?= (int)$ing['id'] ?>, '<?= htmlspecialchars(addslashes($ing['name'])) ?>', '<?= htmlspecialchars(addslashes($ing['category_name'] ?: '')) ?>', '<?= htmlspecialchars(addslashes($ing['unit'] ?: 'kg')) ?>')">
            Apply to Supply This &rarr;
          </button>
        </div>
      <?php endforeach; ?>

      <!-- Custom Product Option Card -->
      <div class="km-procure-card" style="border-style:dashed;background:#FAF7F2;">
        <div>
          <span class="km-tag-pill" style="background:#E2D9CE;">Artisanal / New Item</span>
          <h3 style="font-size:17px;font-weight:700;color:var(--km-text-dark);margin-bottom:6px;">
            Other / Custom Product
          </h3>
          <p style="font-size:12.5px;color:var(--km-text-muted);line-height:1.5;margin-bottom:14px;">
            Offer a specialized coffee bean roast, alternative milk, signature syrup, baked pastry, or packaging not listed above.
          </p>
        </div>
        <button type="button" class="km-track-btn" style="width:100%;text-align:center;justify-content:center;"
                onclick="selectIngredient(0, '', 'Other', 'per unit')">
          Propose Custom Item &rarr;
        </button>
      </div>
    </div>
  </section>

  <!-- APPLICATION FORM SECTION -->
  <section class="container" id="apply-section">
    <div class="km-form-container">
      <div style="text-align:center;margin-bottom:32px;">
        <span class="km-hero-eyebrow">OFFICIAL ACCREDITATION FORM</span>
        <h2 style="font-family:var(--km-font-serif);font-size:28px;color:var(--km-text-dark);margin:6px 0 8px;">
          Supplier Partnership Application
        </h2>
        <p style="font-size:14px;color:var(--km-text-muted);max-width:580px;margin:0 auto;line-height:1.6;">
          Please complete the form below and attach verified copies of your business registration and product authenticity certificates.
        </p>
      </div>

      <form id="supplierAppForm" onsubmit="handleSupplierSubmit(event)">

        <!-- STEP 1: BUSINESS PROFILE -->
        <h3 style="font-size:17px;color:var(--km-text-dark);font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
          <span style="width:26px;height:26px;border-radius:50%;background:var(--km-caramel);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;font-weight:700;">1</span>
          Business &amp; Contact Information
        </h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Company / Business Name <span class="km-req-badge">*</span>
            </label>
            <input type="text" name="company_name" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., Benguet Mountain Roasters Inc." required />
          </div>
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Contact Person <span class="km-req-badge">*</span>
            </label>
            <input type="text" name="contact_person" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., Maria Santos (Key Accounts)" required />
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Business Email Address <span class="km-req-badge">*</span>
            </label>
            <input type="email" name="email" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="sales@yourcompany.com" required />
            <small style="font-size:11.5px;color:var(--km-text-muted);">Tracking updates &amp; portal login credentials will be emailed here.</small>
          </div>
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Mobile / Phone Number <span class="km-req-badge">*</span>
            </label>
            <input type="text" name="phone" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., +63 917 123 4567" required />
          </div>
        </div>

        <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;">
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Registered Business Address <span class="km-req-badge">*</span>
            </label>
            <input type="text" name="address" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="Building, Street, Barangay, City, Province" required />
          </div>
          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Tax ID / TIN / DTI No.
            </label>
            <input type="text" name="tax_id" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="000-000-000-000" />
          </div>
        </div>

        <!-- STEP 2: SELECTED PRODUCT & SPECIFICATIONS -->
        <div class="km-section-divider">
          <h3 style="font-size:17px;color:var(--km-text-dark);font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
            <span style="width:26px;height:26px;border-radius:50%;background:var(--km-caramel);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;font-weight:700;">2</span>
            Product Selection &amp; Supply Proposal
          </h3>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Select Sourced Item <span class="km-req-badge">*</span>
              </label>
              <select name="ingredient_id" id="ingredientSelect" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;background:#fff;" onchange="handleIngredientChange()">
                <option value="0">-- Other / Propose New Custom Item --</option>
                <?php foreach ($all_ingredients as $ing): ?>
                  <option value="<?= (int)$ing['id'] ?>"
                          data-name="<?= htmlspecialchars($ing['name']) ?>"
                          data-cat="<?= htmlspecialchars($ing['category_name'] ?: 'Supplies') ?>"
                          data-unit="<?= htmlspecialchars($ing['unit'] ?: 'kg') ?>"
                          <?= ($preselected_id === (int)$ing['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ing['name']) ?> (<?= htmlspecialchars($ing['category_name'] ?: 'Supplies') ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Specific Product / Trade Name <span class="km-req-badge">*</span>
              </label>
              <input type="text" name="product_name" id="productNameInput" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., Mount Atok Premium Single Origin Arabica" required />
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Category
              </label>
              <input type="text" name="product_category" id="productCatInput" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., Coffee Beans" />
            </div>
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Proposed Unit Price (₱)
              </label>
              <input type="number" step="0.01" min="0" name="proposed_price" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="0.00" />
            </div>
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Pricing Unit
              </label>
              <input type="text" name="price_unit" id="priceUnitInput" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="per kg / per pack" value="per kg" />
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Estimated Monthly Supply Capacity
              </label>
              <input type="text" name="supply_capacity" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., 500 kg / month, 1,200 liters / month" />
            </div>
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
                Origin, Grade &amp; Specifications
              </label>
              <input type="text" name="product_description" class="km-form-input" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:14px;" placeholder="e.g., Benguet, 1,400 MASL, Washed Process, Medium Roast" />
            </div>
          </div>
        </div>

        <!-- STEP 3: COMPLIANCE DOCUMENTS UPLOAD (REQUIRED) -->
        <div class="km-section-divider">
          <h3 style="font-size:17px;color:var(--km-text-dark);font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
            <span style="width:26px;height:26px;border-radius:50%;background:var(--km-caramel);color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center;font-weight:700;">3</span>
            Permits &amp; Authenticity Verification <span class="km-req-badge">*</span>
          </h3>
          <p style="font-size:13px;color:var(--km-text-muted);margin-bottom:18px;line-height:1.5;">
            To ensure guest safety and food quality compliance, all accredited suppliers must provide valid business permits and authenticity credentials (PDF, DOCX, JPG, or PNG, max 10 MB per file).
          </p>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
            <!-- Business Permit Upload Box -->
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:700;margin-bottom:8px;color:var(--km-text-dark);">
                1. Business Permit / Registration <span class="km-req-badge">*</span>
              </label>
              <label class="km-file-upload-box" for="input_business_permit">
                <div style="font-size:28px;margin-bottom:6px;">📄</div>
                <div style="font-size:13.5px;font-weight:700;color:var(--km-text-dark);">Upload Business Permit</div>
                <div style="font-size:11.5px;color:var(--km-text-muted);margin-top:2px;">Mayor's Permit, DTI, SEC, or BIR 2303</div>
                <input type="file" id="input_business_permit" name="business_permit" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required onchange="handleFileSelected(this, 'permitFilename')" />
                <div id="permitFilename" class="km-uploaded-filename"></div>
              </label>
            </div>

            <!-- Authenticity Certificate Upload Box -->
            <div>
              <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:700;margin-bottom:8px;color:var(--km-text-dark);">
                2. Product Authenticity / Quality Certificate <span class="km-req-badge">*</span>
              </label>
              <label class="km-file-upload-box" for="input_authenticity_cert">
                <div style="font-size:28px;margin-bottom:6px;">🏅</div>
                <div style="font-size:13.5px;font-weight:700;color:var(--km-text-dark);">Upload Authenticity Certificate</div>
                <div style="font-size:11.5px;color:var(--km-text-muted);margin-top:2px;">FDA CPR, Certificate of Analysis (COA), or Halal/Sanitary</div>
                <input type="file" id="input_authenticity_cert" name="authenticity_cert" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required onchange="handleFileSelected(this, 'authFilename')" />
                <div id="authFilename" class="km-uploaded-filename"></div>
              </label>
            </div>
          </div>

          <!-- Optional Additional Documents -->
          <div style="margin-bottom:20px;">
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:8px;color:var(--km-text-dark);">
              3. Additional Documents / Product Catalog (Optional)
            </label>
            <label class="km-file-upload-box" for="input_additional_docs" style="padding:16px;">
              <div style="font-size:13px;font-weight:600;color:var(--km-text-dark);">Click to upload company profile, laboratory test report, or catalog (optional)</div>
              <input type="file" id="input_additional_docs" name="additional_documents" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" onchange="handleFileSelected(this, 'addlFilename')" />
              <div id="addlFilename" class="km-uploaded-filename"></div>
            </label>
          </div>

          <div>
            <label class="km-form-label" style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--km-text-dark);">
              Company Overview &amp; Message to Procurement Management
            </label>
            <textarea name="company_profile_notes" class="km-form-input" rows="3" style="width:100%;padding:11px 14px;border:1px solid var(--km-border);border-radius:9px;font-size:13.5px;" placeholder="Tell us about your production facilities, harvest cycles, existing café clients, or logistic lead times…"></textarea>
          </div>
        </div>

        <!-- STEP 4: CONSENT & SUBMIT -->
        <div class="km-section-divider">
          <div style="margin-bottom:24px;background:#FAF7F2;border:1px solid var(--km-border);border-radius:10px;padding:16px;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:13px;color:var(--km-text-dark);line-height:1.5;">
              <input type="checkbox" name="privacy_consent" value="1" required style="margin-top:3px;" />
              <span>
                I certify that all information and uploaded permits/authenticity documents are true, genuine, and legally valid. I agree to Kofee Manila's Supplier Code of Conduct and authorize the procurement team to verify credentials for supplier accreditation.
              </span>
            </label>
          </div>

          <div id="submitErrorMsg" style="display:none;background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px 16px;border-radius:8px;font-size:13.5px;margin-bottom:16px;"></div>

          <div style="text-align:center;">
            <button type="submit" id="submitAppBtn" class="km-hero-search-btn" style="padding:14px 44px;font-size:15px;cursor:pointer;border:none;">
              Submit Supplier Accreditation Application
            </button>
          </div>
        </div>

      </form>
    </div>
  </section>

  <!-- FOOTER -->
  <footer style="padding:40px 24px 30px;border-top:1px solid var(--km-border);background:#fff;">
    <div class="container" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
      <p style="font-size:13px;color:var(--km-text-muted);margin:0;">&copy; <?= date('Y') ?> Kofee Manila. Quality &bull; Ethical Trade &bull; Honest Coffee.</p>
      <div style="display:flex;gap:20px;">
        <a href="index.html" style="font-size:13px;color:var(--km-text-muted);text-decoration:none;font-weight:600;">Home</a>
        <a href="careers.php" style="font-size:13px;color:var(--km-text-muted);text-decoration:none;font-weight:600;">Careers</a>
        <a href="supplier_partnership.php" style="font-size:13px;color:var(--km-caramel);text-decoration:none;font-weight:600;">Supplier Portal</a>
        <a href="auth/login.php" style="font-size:13px;color:var(--km-text-muted);text-decoration:none;font-weight:600;">Login</a>
      </div>
    </div>
  </footer>

  <!-- ── MODAL 1: APPLICATION TRACKING MODAL ── -->
  <div class="km-modal-backdrop" id="trackModalBackdrop" onclick="handleBackdropClick(event, 'trackModalBackdrop')">
    <div class="km-modal" style="max-width:540px;">
      <div class="km-modal-header">
        <div>
          <span style="font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--km-caramel);">PARTNERSHIP TRACKING</span>
          <h3 class="km-modal-title" style="margin-top:2px;">Track Supplier Application</h3>
        </div>
        <button type="button" class="km-modal-close" onclick="closeTrackModal()">&times;</button>
      </div>

      <div class="km-modal-body">
        <p style="font-size:13px;color:var(--km-text-muted);margin-bottom:14px;line-height:1.5;">
          Enter your Application Code (e.g. <code>KM-SUP-2026-1024</code>) or your registered business email address to check your accreditation review status.
        </p>

        <div style="display:flex;gap:8px;margin-bottom:18px;">
          <input type="text" id="trackQueryInput" class="km-form-input" style="flex:1;padding:10px 14px;border:1px solid var(--km-border);border-radius:8px;font-size:13.5px;" placeholder="KM-SUP-2026-XXXX or email@domain.com" />
          <button type="button" id="trackSubmitBtn" class="km-hero-search-btn" style="padding:10px 20px;font-size:13.5px;cursor:pointer;border:none;" onclick="queryTrackStatus()">
            Check
          </button>
        </div>

        <div id="trackResultArea" style="display:none;"></div>
      </div>
    </div>
  </div>

  <!-- ── MODAL 2: SUBMISSION SUCCESS MODAL ── -->
  <div class="km-modal-backdrop" id="successModalBackdrop">
    <div class="km-modal" style="max-width:520px;text-align:center;">
      <div style="width:60px;height:60px;border-radius:50%;background:#DCFCE7;color:#166534;font-size:30px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
        &#10003;
      </div>
      <h3 style="font-family:var(--km-font-serif);font-size:24px;color:var(--km-text-dark);margin-bottom:6px;">Application Received!</h3>
      <p style="font-size:13.5px;color:var(--km-text-muted);line-height:1.6;margin-bottom:20px;">
        Your business accreditation documents and product details have been transmitted to the Kofee Manila procurement committee.
      </p>

      <div style="background:#FAF7F2;border:1.5px dashed var(--km-caramel);border-radius:10px;padding:16px;margin-bottom:20px;">
        <span style="font-size:11px;font-weight:700;color:var(--km-text-muted);text-transform:uppercase;letter-spacing:1px;display:block;">Your Application Tracking Code</span>
        <div id="newTrackingCodeText" style="font-family:monospace;font-size:24px;font-weight:bold;color:var(--km-text-dark);margin-top:4px;"></div>
      </div>

      <p style="font-size:12.5px;color:var(--km-text-muted);margin-bottom:24px;">
        A confirmation email has been dispatched. Please retain your tracking code to view progress.
      </p>

      <div style="display:flex;gap:12px;justify-content:center;">
        <button type="button" class="km-track-btn" onclick="closeSuccessModal()">Done</button>
        <button type="button" class="km-hero-search-btn" onclick="openTrackWithCode()">Track Now &rarr;</button>
      </div>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
    // Prefill helper when clicking "Apply for this item"
    function selectIngredient(id, name, cat, unit) {
      const select = document.getElementById('ingredientSelect');
      select.value = String(id);
      handleIngredientChange();

      if (id === 0) {
        document.getElementById('productNameInput').value = '';
        document.getElementById('productCatInput').value = cat || '';
      }

      const applySec = document.getElementById('apply-section');
      applySec.scrollIntoView({ behavior: 'smooth' });
    }

    function handleIngredientChange() {
      const select = document.getElementById('ingredientSelect');
      const opt = select.options[select.selectedIndex];
      const prodInput = document.getElementById('productNameInput');
      const catInput = document.getElementById('productCatInput');
      const unitInput = document.getElementById('priceUnitInput');

      if (select.value === '0') {
        prodInput.value = '';
        prodInput.placeholder = 'e.g., Signature Cold Brew Concentrate';
        catInput.value = '';
        catInput.placeholder = 'e.g., Beverages / Packaging';
        unitInput.value = 'per unit';
      } else {
        prodInput.value = opt.getAttribute('data-name') || '';
        catInput.value = opt.getAttribute('data-cat') || '';
        unitInput.value = 'per ' + (opt.getAttribute('data-unit') || 'kg');
      }
    }

    function handleFileSelected(input, targetDisplayId) {
      const display = document.getElementById(targetDisplayId);
      const box = input.closest('.km-file-upload-box');
      if (input.files && input.files[0]) {
        const file = input.files[0];
        display.style.display = 'inline-block';
        display.textContent = '✓ Ready: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        if (box) box.classList.add('has-file');
      } else {
        display.style.display = 'none';
        display.textContent = '';
        if (box) box.classList.remove('has-file');
      }
    }

    // Modal Handlers
    function openTrackModal() {
      document.getElementById('trackModalBackdrop').classList.add('open');
      document.getElementById('trackQueryInput').focus();
    }
    function closeTrackModal() {
      document.getElementById('trackModalBackdrop').classList.remove('open');
    }

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
      document.getElementById('trackQueryInput').value = code;
      openTrackModal();
      queryTrackStatus();
    }

    function handleBackdropClick(e, id) {
      if (e.target.id === id) {
        document.getElementById(id).classList.remove('open');
      }
    }

    // Async Application Submission
    async function handleSupplierSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('supplierAppForm');
      const btn = document.getElementById('submitAppBtn');
      const errBox = document.getElementById('submitErrorMsg');

      errBox.style.display = 'none';
      errBox.innerText = '';
      btn.disabled = true;
      btn.innerText = 'Uploading documents and submitting…';

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
          btn.disabled = false;
          btn.innerText = 'Submit Supplier Accreditation Application';
          errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }

        form.reset();
        document.querySelectorAll('.km-uploaded-filename').forEach(el => {
          el.style.display = 'none';
          el.innerText = '';
        });
        document.querySelectorAll('.km-file-upload-box').forEach(el => {
          el.classList.remove('has-file');
        });

        openSuccessModal(data.tracking_code);

      } catch (err) {
        errBox.style.display = 'block';
        errBox.innerText = 'A network error occurred while communicating with the server. Please check your connection and try again.';
      } finally {
        btn.disabled = false;
        btn.innerText = 'Submit Supplier Accreditation Application';
      }
    }

    // Status Tracking Query
    async function queryTrackStatus() {
      const q = document.getElementById('trackQueryInput').value.trim();
      const resArea = document.getElementById('trackResultArea');
      const submitBtn = document.getElementById('trackSubmitBtn');

      if (!q) {
        alert('Please enter your Application Code or Email address.');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = 'Checking…';
      resArea.style.display = 'block';
      resArea.innerHTML = '<p style="color:var(--km-text-muted);font-size:13px;text-align:center;">Looking up accreditation records…</p>';

      try {
        const resp = await fetch('api/track_supplier_application.php?query=' + encodeURIComponent(q));
        const data = await resp.json();

        if (!data.success) {
          resArea.innerHTML = `
            <div style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:12px 14px;color:#C53030;font-size:13px;">
              ${data.error || 'No matching supplier application record found.'}
            </div>
          `;
          return;
        }

        const step = data.current_step;
        const isApproved = data.status === 'approved';
        const isRejected = data.status === 'rejected';

        resArea.innerHTML = `
          <div style="background:#FAF7F2;border:1px solid var(--km-border);border-radius:12px;padding:18px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
              <div>
                <span style="font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--km-caramel);">${data.application_code}</span>
                <h4 style="font-family:var(--km-font-serif);font-size:18px;font-weight:700;color:var(--km-text-dark);margin:2px 0;">${data.company_name}</h4>
                <div style="font-size:12.5px;color:var(--km-text-muted);">
                  Applied Item: <strong>${data.product_name}</strong> (${data.product_category})
                </div>
                <div style="font-size:11.5px;color:var(--km-text-muted);margin-top:2px;">
                  Submitted on ${data.submitted_at}
                </div>
              </div>
              <span class="km-job-tag" style="background:#EFE8DF;font-size:11px;">${data.stage_label}</span>
            </div>

            <!-- Pipeline Stepper -->
            <div style="margin-top:18px;padding-top:16px;border-top:1px dashed var(--km-border);">
              <div style="display:flex;justify-content:space-between;position:relative;margin-bottom:16px;">
                <div style="position:absolute;top:14px;left:20px;right:20px;height:2px;background:#E2D9CE;z-index:1;"></div>
                <div style="position:absolute;top:14px;left:20px;width:${step === 1 ? '0%' : step === 2 ? '50%' : '100%'};height:2px;background:var(--km-caramel);z-index:2;transition:width 0.3s ease;"></div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:90px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 1 ? 'var(--km-caramel)' : '#FFF'};border:2px solid ${step >= 1 ? 'var(--km-caramel)' : '#C49E7C'};color:${step >= 1 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">1</div>
                  <span style="font-size:11px;font-weight:${step === 1 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Received</span>
                </div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:90px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 2 ? 'var(--km-caramel)' : '#FFF'};border:2px solid ${step >= 2 ? 'var(--km-caramel)' : '#C49E7C'};color:${step >= 2 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">2</div>
                  <span style="font-size:11px;font-weight:${step === 2 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Compliance</span>
                </div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:90px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 3 ? (isApproved ? '#16A34A' : isRejected ? '#DC2626' : 'var(--km-caramel)') : '#FFF'};border:2px solid ${step >= 3 ? (isApproved ? '#16A34A' : isRejected ? '#DC2626' : 'var(--km-caramel)') : '#C49E7C'};color:${step >= 3 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">3</div>
                  <span style="font-size:11px;font-weight:${step === 3 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Decision</span>
                </div>
              </div>

              <div style="background:#FFF;border-radius:8px;border:1px solid ${isApproved ? '#BBF7D0' : isRejected ? '#FECACA' : 'var(--km-border-light)'};padding:14px;font-size:13px;line-height:1.5;">
                <strong style="color:${isApproved ? '#166534' : isRejected ? '#991B1B' : 'var(--km-text-dark)'};display:block;margin-bottom:3px;">
                  Status: ${data.stage_label}
                </strong>
                ${data.status_message}
                ${isApproved ? '<div style="margin-top:10px;"><a href="auth/login.php" class="km-track-btn" style="display:inline-block;padding:6px 14px;font-size:12px;">Sign in to Supplier Portal &rarr;</a></div>' : ''}
              </div>
            </div>
          </div>
        `;
      } catch (err) {
        resArea.innerHTML = '<p style="color:#C53030;font-size:13px;text-align:center;">Failed to connect to application tracking service.</p>';
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Check';
      }
    }

    // Auto-trigger if tracking code provided in URL (?track=KM-SUP-...)
    const emailedTrackingCode = new URLSearchParams(window.location.search).get('track');
    if (emailedTrackingCode) {
      document.getElementById('trackQueryInput').value = emailedTrackingCode;
      openTrackModal();
      queryTrackStatus();
    }
  </script>

</body>
</html>
