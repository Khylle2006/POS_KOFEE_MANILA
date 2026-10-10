<?php
require_once __DIR__ . '/includes/request_security.php';
secure_session_start();
send_security_headers();
start_browser_security_output();
// ─────────────────────────────────────────────────────────────
//  BrewVanti — Careers & Open Positions Portal
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/db.php';

$pdo = get_db();
$search = trim($_GET['search'] ?? '');
$location = trim($_GET['location'] ?? '');
$department = trim($_GET['department'] ?? '');

$sql = 'SELECT * FROM job_postings WHERE is_active = 1';
$params = [];

if (!empty($search)) {
    $sql .= ' AND (title LIKE :s OR description LIKE :s OR tagline LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
if (!empty($location) && strtolower($location) !== 'all locations') {
    $sql .= ' AND location = :loc';
    $params[':loc'] = $location;
}
if (!empty($department) && strtolower($department) !== 'all departments') {
    $sql .= ' AND department = :dept';
    $params[':dept'] = $department;
}
$sql .= ' ORDER BY id ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Get unique locations and departments for filter dropdowns
$locStmt = $pdo->query('SELECT DISTINCT location FROM job_postings WHERE is_active = 1 ORDER BY location ASC');
$locations = $locStmt->fetchAll(PDO::FETCH_COLUMN);

$deptStmt = $pdo->query('SELECT DISTINCT department FROM job_postings WHERE is_active = 1 ORDER BY department ASC');
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Careers at BrewVanti — Craft Your Next Chapter</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,400;1,600;1,700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/brewvanti-public.css?v=<?= filemtime(__DIR__ . '/css/brewvanti-public.css') ?>">
  <style>
    /* Careers Specific 2.5D Styling */
    .hero-search-wrap {
      background: rgba(36, 26, 28, 0.72);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1.5px solid rgba(217, 186, 133, 0.32);
      border-radius: 999px;
      padding: 8px 12px;
      display: flex;
      align-items: center;
      gap: 12px;
      max-width: 900px;
      box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35), 0 0 24px rgba(217, 186, 133, 0.12);
      margin-top: 10px;
    }

    .hero-search-field {
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1.4;
      padding-left: 12px;
    }

    .hero-search-field svg,
    .hero-filter-field svg {
      color: var(--color-gold);
      flex-shrink: 0;
      width: 18px;
      height: 18px;
    }

    .hero-search-input {
      width: 100%;
      background: transparent;
      border: none;
      color: var(--color-cream);
      font-size: 14px;
      font-weight: 500;
      outline: none;
    }

    .hero-search-input::placeholder {
      color: rgba(250, 247, 242, 0.55);
    }

    .hero-filter-divider {
      width: 1px;
      height: 28px;
      background: rgba(217, 186, 133, 0.22);
      flex-shrink: 0;
    }

    .hero-filter-field {
      display: flex;
      align-items: center;
      gap: 8px;
      flex: 1;
      position: relative;
    }

    .hero-filter-select {
      width: 100%;
      background: transparent;
      border: none;
      color: var(--color-cream);
      font-size: 13.5px;
      font-weight: 500;
      outline: none;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      padding-right: 22px;
    }

    .hero-filter-select option {
      background: #1E1517;
      color: #FAF7F2;
    }

    .select-chevron {
      position: absolute;
      right: 4px;
      pointer-events: none;
      color: var(--color-gold);
      width: 14px;
      height: 14px;
    }

    /* Positions Section */
    .positions-section {
      padding: 90px 0 100px;
      background: var(--color-cream);
    }

    .positions-header-row {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px;
      margin-bottom: 40px;
    }

    .openings-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(217, 186, 133, 0.16);
      border: 1px solid rgba(217, 186, 133, 0.35);
      color: #7A531E;
      font-size: 12px;
      font-weight: 700;
      padding: 6px 14px;
      border-radius: 999px;
      letter-spacing: 0.04em;
    }

    .jobs-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 28px;
    }

    .job-card {
      padding: 30px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .job-card-top {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 16px;
    }

    .job-icon-frame {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      background: rgba(217, 186, 133, 0.14);
      border: 1.5px solid rgba(217, 186, 133, 0.32);
      color: #9E7438;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .job-title {
      font-family: var(--font-brand);
      font-size: 19px;
      font-weight: 700;
      color: var(--color-espresso);
      line-height: 1.25;
      margin-bottom: 6px;
    }

    .job-badges-row {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
    }

    .badge-pill {
      font-size: 11.5px;
      font-weight: 600;
      padding: 3px 10px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .badge-location {
      background: rgba(104, 88, 75, 0.08);
      color: var(--color-mocha);
      border: 1px solid rgba(104, 88, 75, 0.14);
    }

    .badge-type {
      background: rgba(217, 186, 133, 0.18);
      color: #7A531E;
      border: 1px solid rgba(217, 186, 133, 0.35);
      font-weight: 700;
    }

    .job-desc {
      font-size: 13.5px;
      color: var(--color-mocha);
      line-height: 1.6;
      margin-bottom: 24px;
    }

    .job-actions-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding-top: 18px;
      border-top: 1px solid rgba(104, 88, 75, 0.1);
    }

    /* Why Join Section */
    .why-section {
      padding: 0 0 100px;
    }

    .why-card {
      background: #241A1C;
      border: 1.5px solid rgba(217, 186, 133, 0.22);
      border-radius: 26px;
      padding: 56px 48px;
      color: var(--color-cream);
      box-shadow: 0 24px 64px rgba(18, 12, 13, 0.38);
    }

    .why-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 36px;
      margin-top: 40px;
    }

    .why-pillar {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .why-pillar-icon {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: rgba(217, 186, 133, 0.14);
      border: 1.5px solid rgba(217, 186, 133, 0.32);
      color: var(--color-gold-bright);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .why-pillar h4 {
      font-family: var(--font-brand);
      font-size: 20px;
      font-weight: 700;
      color: var(--color-cream);
    }

    .why-pillar p {
      font-size: 14px;
      line-height: 1.65;
      color: rgba(250, 247, 242, 0.72);
    }

    @media (max-width: 900px) {
      .hero-search-wrap {
        border-radius: 20px;
        flex-direction: column;
        align-items: stretch;
        padding: 16px;
      }
      .hero-filter-divider { display: none; }
      .why-grid { grid-template-columns: 1fr; }
      .jobs-grid { grid-template-columns: 1fr; }
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
        <li><a href="careers.php" class="active">Hiring</a></li>
        <li><a href="supplier_partnership.php">Suppliers</a></li>
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
      <span class="hero-eyebrow">✦ CAREERS AT BREWVANTI</span>
      <h1 class="hero-heading">Craft your next <em style="font-style: italic; color: var(--color-gold);">chapter.</em></h1>
      <p class="hero-subtext">Find your place in an artisan team that celebrates precision coffee, craftsmanship, and warm hospitality.</p>

      <!-- 2.5D Search & Filter Bar -->
      <form action="careers.php" method="GET" class="hero-search-wrap" id="jobFilterForm">
        <!-- Search Keyword -->
        <div class="hero-search-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" name="search" id="searchInput" class="hero-search-input" 
                 placeholder="Search job title or keyword" 
                 value="<?= htmlspecialchars($search) ?>" 
                 autocomplete="off">
        </div>

        <div class="hero-filter-divider"></div>

        <!-- Location Filter -->
        <div class="hero-filter-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </svg>
          <select name="location" id="locationSelect" class="hero-filter-select">
            <option value="">All locations</option>
            <?php foreach ($locations as $loc): ?>
              <option value="<?= htmlspecialchars($loc) ?>" <?= $location === $loc ? 'selected' : '' ?>>
                <?= htmlspecialchars($loc) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </div>

        <div class="hero-filter-divider"></div>

        <!-- Department Filter -->
        <div class="hero-filter-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="14" width="7" height="7" rx="1.5"/>
            <rect x="3" y="14" width="7" height="7" rx="1.5"/>
          </svg>
          <select name="department" id="deptSelect" class="hero-filter-select">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?= htmlspecialchars($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>>
                <?= htmlspecialchars($dept) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </div>

        <!-- Search Button -->
        <button type="submit" class="btn-login-cta">Search Jobs</button>
      </form>
    </div>
  </section>

  <!-- ─── OPEN POSITIONS SECTION ─── -->
  <section class="positions-section">
    <div class="container">
      <div class="positions-header-row">
        <div>
          <span class="section-eyebrow">OPPORTUNITIES</span>
          <h2 class="section-title">Open Positions</h2>
          <p class="section-subtitle">Find a craft role that suits your talent and ambition.</p>
        </div>
        <div>
          <span class="openings-badge" id="jobCounter">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <strong><?= count($jobs) ?> <?= count($jobs) === 1 ? 'opening' : 'openings' ?></strong>
          </span>
        </div>
      </div>

      <!-- Jobs Grid -->
      <div class="jobs-grid" id="jobsGrid">
        <?php if (empty($jobs)): ?>
          <div style="grid-column: 1 / -1; padding: 56px 24px; text-align: center; background: #FFF; border: 1.5px solid rgba(217, 186, 133, 0.25); border-radius: 20px;">
            <p style="font-size: 15px; color: var(--color-mocha); margin-bottom: 16px;">No matching open positions found for your criteria.</p>
            <a href="careers.php" class="btn-outline-gold">Clear all filters</a>
          </div>
        <?php else: ?>
          <?php foreach ($jobs as $job): ?>
            <div class="luxury-card job-card km-job-card" data-id="<?= (int)$job['id'] ?>" data-slug="<?= htmlspecialchars($job['slug']) ?>">
              <div>
                <!-- Card Top -->
                <div class="job-card-top">
                  <div class="job-icon-frame">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                      <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                    </svg>
                  </div>
                  <div>
                    <h3 class="job-title"><?= htmlspecialchars($job['title']) ?></h3>
                    <div class="job-badges-row">
                      <span class="badge-pill badge-location">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                          <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <?= htmlspecialchars($job['location']) ?>
                      </span>
                      <span class="badge-pill badge-type"><?= htmlspecialchars($job['job_type']) ?></span>
                    </div>
                  </div>
                </div>

                <!-- Description -->
                <p class="job-desc"><?= htmlspecialchars($job['tagline'] ?: $job['description']) ?></p>
              </div>

              <!-- Actions -->
              <div class="job-actions-row">
                <button type="button" class="btn-outline-gold" onclick="openJobDetails(<?= htmlspecialchars(json_encode($job)) ?>)">
                  View Details
                </button>
                <a href="apply.php?job=<?= urlencode($job['slug']) ?>" class="btn-login-cta">
                  Apply Now &rarr;
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ─── WHY JOIN BREWVANTI SECTION ─── -->
  <section class="why-section">
    <div class="container">
      <div class="why-card">
        <div>
          <span class="section-eyebrow" style="color:var(--color-gold-bright);">CRAFT CULTURE</span>
          <h2 style="font-family:var(--font-brand);font-size:32px;font-weight:700;margin-bottom:8px;">Why join BrewVanti?</h2>
          <p style="color:rgba(250, 247, 242, 0.72);font-size:15px;">More than a job. A specialty craft to master.</p>
        </div>

        <div class="why-grid">
          <!-- Pillar 1 -->
          <div class="why-pillar">
            <div class="why-pillar-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 20V10M12 20V4M6 20v-6"/>
              </svg>
            </div>
            <h4>Grow Your Craft</h4>
            <p>Master signature extraction profiles, sensory tasting, and precision barista techniques guided by SCA-certified mentors.</p>
          </div>

          <!-- Pillar 2 -->
          <div class="why-pillar">
            <div class="why-pillar-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <h4>Artisan Community</h4>
            <p>Collaborate in a supportive, high-standard environment built on respect, mutual growth, and transparent career advancement.</p>
          </div>

          <!-- Pillar 3 -->
          <div class="why-pillar">
            <div class="why-pillar-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
                <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                <line x1="6" y1="1" x2="6" y2="4"/>
                <line x1="10" y1="1" x2="10" y2="4"/>
                <line x1="14" y1="1" x2="14" y2="4"/>
              </svg>
            </div>
            <h4>Make Every Sip Count</h4>
            <p>Transform daily morning rituals into memorable, intentional moments for thousands of coffee lovers across Manila.</p>
          </div>
        </div>
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
          <span class="gold-eyebrow" style="display:block;margin-bottom:6px;">✦ REAL-TIME STATUS &amp; CANDIDATE TRACKER</span>
          <h2 style="font-family:var(--font-brand);font-size:30px;color:var(--color-espresso);margin:0 0 10px;font-weight:700;">
            Application Status
          </h2>
          <p style="font-size:13.5px;color:var(--color-mocha);line-height:1.6;margin:0;">
            Use your emailed private link to view status, or enter your registered email below to check real-time candidate review progress or request a new private link.
          </p>
        </div>

        <!-- Form Card -->
        <div style="background:#FAF7F2;border:1.5px solid rgba(217,186,133,0.28);border-radius:18px;padding:24px 28px;margin-bottom:24px;">
          <form id="onpageCareerTrackForm" onsubmit="handleOnpageCareerSubmit(event)">
            <div class="form-group" style="margin-bottom:16px;">
              <label class="form-label" for="onpageCareerEmail" style="display:flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Registered Email <span style="color:#DC2626;">*</span>
              </label>
              <input type="email" id="onpageCareerEmail" name="email" class="form-input" placeholder="candidate@example.com" required style="background:#FFFFFF;" />
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
              <span style="font-size:12px;color:var(--color-mocha);display:flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                Talent acquisition screening takes 3–5 business days.
              </span>
              <div style="display:flex;gap:10px;margin-left:auto;flex-wrap:wrap;">
                <button type="submit" class="btn-login-cta" id="onpageCareerSubmitBtn" style="padding:10px 22px;font-size:13px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                  Email Private Status Link &rarr;
                </button>
              </div>
            </div>
          </form>
        </div>

        <div id="onpageCareerResultArea" style="display:none;"></div>
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

  <!-- ── MODAL: TRACK APPLICATION ────────────────────────── -->
  <div class="km-modal-backdrop" id="trackModalBackdrop" onclick="handleBackdropClick(event, 'trackModalBackdrop')">
    <div class="km-modal-card" style="max-width: 520px;">
      <div class="km-modal-header">
        <div>
          <span style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-gold);">STATUS VERIFICATION</span>
          <h3 class="km-modal-title">Track Your Application</h3>
          <p class="km-modal-subtitle">Enter your registered email address to check candidate review progress.</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closeTrackModal()" aria-label="Close modal">&times;</button>
      </div>

      <div class="km-modal-body">
        <div style="margin-bottom: 18px;">
          <label style="font-size:12px;font-weight:700;color:var(--color-mocha);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;display:block;" for="trackQueryInput">Registered Email</label>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <input type="email" id="trackQueryInput" class="hero-search-input" 
                   style="flex:1;min-width:220px;padding:12px 16px;border:1.5px solid rgba(217,186,133,0.4);border-radius:12px;color:var(--color-espresso);background:#FAF7F2;outline:none;" 
                   placeholder="you@example.com" autocomplete="off">
            <button type="button" class="btn-login-cta" id="trackSubmitBtn" onclick="queryTrackStatus()">Email Link</button>
          </div>
        </div>

        <!-- Result Container -->
        <div id="trackResultArea" style="display: none; border-top: 1px solid rgba(104, 88, 75, 0.12); padding-top: 18px; margin-top: 18px;">
          <!-- Filled via JS -->
        </div>
      </div>
      <div class="km-modal-footer">
        <button type="button" class="btn-outline-gold" onclick="closeTrackModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- ── MODAL: JOB DETAILS ──────────────────────────────── -->
  <div class="km-modal-backdrop" id="detailsModalBackdrop" onclick="handleBackdropClick(event, 'detailsModalBackdrop')">
    <div class="km-modal-card" style="max-width: 620px;">
      <div class="km-modal-header">
        <div>
          <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
            <span class="badge-pill badge-type" id="modalJobDepartment">Coffee &amp; Barista</span>
            <span class="badge-pill badge-location" id="modalJobLocation" style="color:var(--color-cream);background:rgba(250,247,242,0.12);">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              <span id="modalJobLocationText">Manila</span>
            </span>
            <span class="badge-pill badge-type" id="modalJobType">Full-time</span>
          </div>
          <h3 class="km-modal-title" id="modalJobTitle">Barista</h3>
          <p class="km-modal-subtitle" id="modalJobTagline">Craft quality beverages and create welcoming experiences for every guest.</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closeJobDetails()" aria-label="Close modal">&times;</button>
      </div>

      <div class="km-modal-body" style="display:flex;flex-direction:column;gap:20px;font-size:13.5px;line-height:1.65;color:var(--color-espresso);">
        <div>
          <h4 style="font-family:var(--font-brand);font-size:16px;font-weight:700;color:var(--color-espresso);margin-bottom:6px;">About the Role</h4>
          <p id="modalAboutRole" style="color:var(--color-mocha);"></p>
        </div>

        <div id="modalRespSection">
          <h4 style="font-family:var(--font-brand);font-size:16px;font-weight:700;color:var(--color-espresso);margin-bottom:6px;">Key Responsibilities</h4>
          <ul id="modalResponsibilities" style="padding-left: 20px; color: var(--color-mocha);"></ul>
        </div>

        <div id="modalReqSection">
          <h4 style="font-family:var(--font-brand);font-size:16px;font-weight:700;color:var(--color-espresso);margin-bottom:6px;">Qualifications &amp; Requirements</h4>
          <ul id="modalRequirements" style="padding-left: 20px; color: var(--color-mocha);"></ul>
        </div>

        <div id="modalBenSection">
          <h4 style="font-family:var(--font-brand);font-size:16px;font-weight:700;color:var(--color-espresso);margin-bottom:6px;">Perks &amp; Benefits</h4>
          <ul id="modalBenefits" style="padding-left: 20px; color: var(--color-mocha);"></ul>
        </div>
      </div>

      <div class="km-modal-footer">
        <button type="button" class="btn-outline-gold" onclick="closeJobDetails()">Close</button>
        <a href="#" id="modalApplyBtn" class="btn-login-cta">Apply for this Position &rarr;</a>
      </div>
    </div>
  </div>

  <!-- ── MODAL: PRIVACY POLICY ────────────────────────────── -->
  <div class="km-modal-backdrop" id="privacyModalBackdrop" onclick="handleBackdropClick(event, 'privacyModalBackdrop')">
    <div class="km-modal-card" style="max-width: 520px;">
      <div class="km-modal-header">
        <div>
          <h3 class="km-modal-title">Privacy Notice for Applicants</h3>
          <p class="km-modal-subtitle">How BrewVanti handles candidate data</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closePrivacyModal()" aria-label="Close modal">&times;</button>
      </div>
      <div class="km-modal-body" style="font-size: 13.5px; line-height: 1.65; color: var(--color-mocha); display: flex; flex-direction: column; gap: 14px;">
        <p>At <strong>BrewVanti</strong>, we respect your personal data and comply with the Philippine Data Privacy Act of 2012 (RA 10173).</p>
        <p>By submitting your job application, you consent to our recruitment team collecting, evaluating, and storing your submitted contact information, employment history, and resume file strictly for recruitment and workforce evaluation purposes.</p>
        <p>Your resume and application details will never be sold, rented, or shared with unauthorized third parties.</p>
      </div>
      <div class="km-modal-footer">
        <button type="button" class="btn-login-cta" onclick="closePrivacyModal()">Understood</button>
      </div>
    </div>
  </div>

  <!-- ── MODAL: CONTACT US ────────────────────────────────── -->
  <div class="km-modal-backdrop" id="contactModalBackdrop" onclick="handleBackdropClick(event, 'contactModalBackdrop')">
    <div class="km-modal-card" style="max-width: 480px;">
      <div class="km-modal-header">
        <div>
          <h3 class="km-modal-title">Contact Talent Team</h3>
          <p class="km-modal-subtitle">We are always happy to connect with great talent.</p>
        </div>
        <button type="button" class="km-modal-close" onclick="closeContactModal()" aria-label="Close modal">&times;</button>
      </div>
      <div class="km-modal-body" style="font-size: 14px; line-height: 1.7; color: var(--color-espresso); display: flex; flex-direction: column; gap: 12px;">
        <div><strong>Recruitment Email:</strong> <a href="mailto:careers@kofeemanila.com" style="color:#A85A1E;text-decoration:underline;">careers@kofeemanila.com</a></div>
        <div><strong>Roastery &amp; Store:</strong> 102 Artisan Ave, Manila</div>
        <div><strong>Hours:</strong> Monday to Friday, 9:00 AM – 6:00 PM PHT</div>
      </div>
      <div class="km-modal-footer">
        <button type="button" class="btn-outline-gold" onclick="closeContactModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
    // Mobile navigation toggle
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

    // Live client-side instant filter enhancement
    const searchInput = document.getElementById('searchInput');
    const locSelect = document.getElementById('locationSelect');
    const deptSelect = document.getElementById('deptSelect');
    const jobCards = document.querySelectorAll('.km-job-card');
    const counterEl = document.getElementById('jobCounter');

    function filterJobsLive() {
      const q = searchInput.value.toLowerCase().trim();
      const loc = locSelect.value.toLowerCase().trim();
      const dept = deptSelect.value.toLowerCase().trim();

      let visible = 0;
      jobCards.forEach(card => {
        const text = card.innerText.toLowerCase();
        const matchesQuery = !q || text.includes(q);
        const matchesLoc = !loc || text.includes(loc);
        const matchesDept = !dept || text.includes(dept);

        if (matchesQuery && matchesLoc && matchesDept) {
          card.style.display = 'flex';
          visible++;
        } else {
          card.style.display = 'none';
        }
      });

      if (counterEl) {
        counterEl.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><strong>${visible} ${visible === 1 ? 'opening' : 'openings'}</strong>`;
      }
    }

    if (searchInput) searchInput.addEventListener('input', filterJobsLive);
    if (locSelect) locSelect.addEventListener('change', filterJobsLive);
    if (deptSelect) deptSelect.addEventListener('change', filterJobsLive);

    // Modals handler
    function openTrackModal() {
      document.getElementById('trackModalBackdrop').classList.add('open');
      document.getElementById('trackQueryInput').focus();
    }
    function closeTrackModal() {
      document.getElementById('trackModalBackdrop').classList.remove('open');
    }

    function openPrivacyModal() {
      document.getElementById('privacyModalBackdrop').classList.add('open');
    }
    function closePrivacyModal() {
      document.getElementById('privacyModalBackdrop').classList.remove('open');
    }

    function openContactModal() {
      document.getElementById('contactModalBackdrop').classList.add('open');
    }
    function closeContactModal() {
      document.getElementById('contactModalBackdrop').classList.remove('open');
    }

    function handleBackdropClick(e, id) {
      if (e.target.id === id) {
        document.getElementById(id).classList.remove('open');
      }
    }

    // Job Details Modal
    function openJobDetails(job) {
      document.getElementById('modalJobTitle').innerText = job.title;
      document.getElementById('modalJobTagline').innerText = job.tagline || job.description;
      document.getElementById('modalJobLocationText').innerText = job.location;
      document.getElementById('modalJobType').innerText = job.job_type;
      document.getElementById('modalJobDepartment').innerText = job.department || 'Operations';
      document.getElementById('modalAboutRole').innerText = job.about_role || job.description;
      document.getElementById('modalApplyBtn').href = 'apply.php?job=' + encodeURIComponent(job.slug);

      function renderList(targetId, sectionId, text) {
        const ul = document.getElementById(targetId);
        const sec = document.getElementById(sectionId);
        ul.innerHTML = '';
        if (!text) {
          sec.style.display = 'none';
          return;
        }
        sec.style.display = 'block';
        text.split('\n').forEach(line => {
          if (line.trim()) {
            const li = document.createElement('li');
            li.textContent = line.trim();
            ul.appendChild(li);
          }
        });
      }

      renderList('modalResponsibilities', 'modalRespSection', job.responsibilities);
      renderList('modalRequirements', 'modalReqSection', job.requirements);
      renderList('modalBenefits', 'modalBenSection', job.benefits);

      document.getElementById('detailsModalBackdrop').classList.add('open');
    }

    function closeJobDetails() {
      document.getElementById('detailsModalBackdrop').classList.remove('open');
    }

    // Application Tracking Lookup
    async function queryTrackStatus() {
      const email = document.getElementById('trackQueryInput').value.trim();
      const area = document.getElementById('trackResultArea');
      area.style.display = 'block';
      area.innerHTML = '<div style="text-align:center;padding:16px;color:var(--color-mocha);font-size:13px;">Checking application status…</div>';
      try {
        const response = await fetch('api/track_application.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'recover', email }) });
        const data = await response.json();
        if (data.recovery || data.message) {
          area.innerHTML = `
            <div style="background:#ECFDF5;border:1.5px solid #A7F3D0;border-radius:14px;padding:16px;color:#065F46;">
              <div style="font-weight:700;font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Private Link Dispatched
              </div>
              <p style="font-size:12.5px;margin:0;line-height:1.5;">${data.message || 'If an application matches this email, a private status link has been sent to your inbox.'}</p>
            </div>
          `;
        } else if (data.status) {
          renderJobStatus(data);
        } else {
          area.innerHTML = `<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px;border-radius:10px;font-size:13px;">${data.error || 'No active application found.'}</div>`;
        }
      } catch (error) { area.innerHTML = '<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px;border-radius:10px;font-size:13px;">The status service is temporarily unavailable.</div>'; }
    }

    function renderJobStatus(data, targetId = 'trackResultArea') {
      const area = document.getElementById(targetId);
      if (!area) return;
      area.style.display = 'block';
      const st = data.status || 'review';
      const isApproved = (st === 'approved' || st === 'hired');
      const isRejected = (st === 'rejected');
      let badgeBg = '#FEF3C7', badgeColor = '#92400E', badgeText = 'Under Review';
      if (isApproved) { badgeBg = '#DCFCE7'; badgeColor = '#166534'; badgeText = 'Offer Extended'; }
      if (isRejected) { badgeBg = '#FEE2E2'; badgeColor = '#991B1B'; badgeText = 'Decision Sent'; }

      area.innerHTML = `
        <div style="background:#FFFFFF;border:1.5px solid rgba(217,186,133,0.3);border-radius:16px;padding:20px;box-shadow:0 8px 24px rgba(30,21,23,0.06);">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div>
              <span style="font-size:11px;font-weight:700;color:var(--color-gold);text-transform:uppercase;">JOB APPLICATION</span>
              <h4 style="margin:2px 0 0;font-size:16px;color:var(--color-espresso);font-family:var(--font-brand);">${data.position_title || 'Position Application'}</h4>
            </div>
            <span style="background:${badgeBg};color:${badgeColor};font-size:11.5px;font-weight:700;padding:4px 12px;border-radius:999px;text-transform:uppercase;">
              ${badgeText}
            </span>
          </div>
          <div style="font-size:12.5px;color:var(--color-mocha);margin-bottom:12px;">
            Submitted on: <strong>${data.submitted_at || 'Recently'}</strong>
          </div>
          <div style="background:#FAF7F2;border:1px solid rgba(104,88,75,0.12);border-radius:12px;padding:14px;font-size:12.5px;color:var(--color-espresso);line-height:1.55;">
            ${isApproved ?
              'Congratulations! Your job application has been approved by the HR talent team. Check your email for onboarding details.' :
              (isRejected ? 'Thank you for your interest in BrewVanti. At this time, another candidate was selected for this role.' :
              'Your resume and application profile are currently being screened by our talent acquisition team. We typically review candidates within 3-5 business days.')
            }
          </div>
        </div>
      `;
    }

    // ── On-Page Career Tracker Handler ─────────────────────
    async function handleOnpageCareerSubmit(e) {
      e.preventDefault();
      const email = document.getElementById('onpageCareerEmail').value.trim();
      const area = document.getElementById('onpageCareerResultArea');
      if (!email) return;

      area.style.display = 'block';
      area.innerHTML = '<div style="text-align:center;padding:20px;color:var(--color-mocha);font-size:13px;">Checking application status…</div>';

      try {
        const response = await fetch('api/track_application.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'recover', email })
        });
        const data = await response.json();
        if (data.recovery || data.message) {
          area.innerHTML = `
            <div style="background:#ECFDF5;border:1.5px solid #A7F3D0;border-radius:14px;padding:18px;color:#065F46;">
              <div style="font-weight:700;font-size:14px;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Private Link Dispatched
              </div>
              <p style="font-size:12.5px;margin:0;line-height:1.5;">${data.message || 'If an application matches this email, a private status link has been sent to your inbox.'}</p>
            </div>
          `;
        } else if (data.status) {
          renderJobStatus(data, 'onpageCareerResultArea');
        } else {
          area.innerHTML = `<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px;border-radius:10px;font-size:13px;">${data.error || 'No active application found.'}</div>`;
        }
      } catch (error) {
        area.innerHTML = '<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px;border-radius:10px;font-size:13px;">The status service is temporarily unavailable.</div>';
      }
    }

    const hashToken = new URLSearchParams(window.location.hash.slice(1)).get('track');
    if (hashToken) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
      const onpageCareerArea = document.getElementById('onpageCareerResultArea');
      if (onpageCareerArea) {
        onpageCareerArea.style.display = 'block';
        onpageCareerArea.innerHTML = '<div style="text-align:center;padding:16px;color:var(--color-mocha);font-size:13px;">Validating status link…</div>';
      }
      openTrackModal();
      document.getElementById('trackResultArea').style.display = 'block';
      document.getElementById('trackResultArea').innerHTML = '<div style="text-align:center;padding:16px;color:var(--color-mocha);font-size:13px;">Validating status link…</div>';
      fetch('api/track_application.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: hashToken }) })
        .then(r => r.json())
        .then(d => {
          renderJobStatus(d, 'trackResultArea');
          if (onpageCareerArea) renderJobStatus(d, 'onpageCareerResultArea');
        })
        .catch(() => {
          const errBox = '<div style="background:#FFF5F5;border:1px solid #FED7D7;color:#C53030;padding:12px;border-radius:10px;font-size:13px;">Invalid or expired tracking link.</div>';
          document.getElementById('trackResultArea').innerHTML = errBox;
          if (onpageCareerArea) onpageCareerArea.innerHTML = errBox;
        });
    }
  </script>

  <script src="js/application-tracking.js"></script>
</body>
</html>
