<?php
// ─────────────────────────────────────────────────────────────
//  Kofee Manila — Careers & Open Positions Portal
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
  <title>Careers at Kofee Manila — Brew Your Next Chapter</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/careers.css?v=<?= filemtime(__DIR__ . '/css/careers.css') ?>">
</head>
<body>

  <!-- TOP NAVIGATION HEADER -->
  <header class="km-header">
    <div class="container">
      <div class="km-header-inner">
        <!-- Logo -->
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

        <!-- Center Links -->
        <nav class="km-nav-links">
          <a href="index.html" class="km-nav-link">Home</a>
          <a href="careers.php" class="km-nav-link active">Careers</a>
          <a href="index.html#about" class="km-nav-link">About Us</a>
        </nav>

        <!-- Right Action Button -->
        <div>
          <button type="button" class="km-track-btn" onclick="openTrackModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
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

      <!-- Watermark Art: Coffee rings + Script -->
      <div class="km-art-watermark" aria-hidden="true">
        <svg class="km-coffee-rings" viewBox="0 0 200 200" fill="none">
          <!-- Outer primary ring -->
          <circle cx="105" cy="95" r="70" stroke="#9A6B46" stroke-width="6.5" stroke-dasharray="14 4 40 8 20 6" opacity="0.4" />
          <circle cx="105" cy="95" r="76" stroke="#C4936B" stroke-width="2" opacity="0.25" />
          <circle cx="103" cy="93" r="64" stroke="#7A4D2A" stroke-width="3" stroke-dasharray="8 6 24 5" opacity="0.3" />
          <!-- Inner secondary interlocking ring -->
          <circle cx="130" cy="115" r="48" stroke="#8E5D38" stroke-width="5" stroke-dasharray="12 4 30 6" opacity="0.35" />
          <circle cx="130" cy="115" r="54" stroke="#B8855F" stroke-width="1.8" opacity="0.2" />
          <!-- Watercolor droplets -->
          <circle cx="48" cy="72" r="3.5" fill="#A3724C" opacity="0.45" />
          <circle cx="56" cy="62" r="2" fill="#A3724C" opacity="0.35" />
          <circle cx="68" cy="96" r="2.5" fill="#8E5D38" opacity="0.4" />
        </svg>
        <div class="km-script-text">
          Good People
          <span>Better Coffee</span>
        </div>
      </div>

      <!-- Hero Titles -->
      <span class="km-hero-eyebrow">CAREERS AT KOFEE MANILA</span>
      <h1 class="km-hero-title">Brew your next chapter.</h1>
      <p class="km-hero-subtitle">Find your place in a team that brings great coffee and warm service to every community.</p>

      <!-- Search & Filter Bar -->
      <form action="careers.php" method="GET" class="km-search-box" id="jobFilterForm">
        <!-- Search Keyword -->
        <div class="km-search-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" name="search" id="searchInput" class="km-search-input" 
                 placeholder="Search job title or keyword" 
                 value="<?= htmlspecialchars($search) ?>" 
                 autocomplete="off">
        </div>

        <div class="km-search-divider"></div>

        <!-- Location Filter -->
        <div class="km-filter-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </svg>
          <select name="location" id="locationSelect" class="km-filter-select">
            <option value="">All locations</option>
            <?php foreach ($locations as $loc): ?>
              <option value="<?= htmlspecialchars($loc) ?>" <?= $location === $loc ? 'selected' : '' ?>>
                <?= htmlspecialchars($loc) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <svg class="km-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </div>

        <div class="km-search-divider"></div>

        <!-- Department Filter -->
        <div class="km-filter-field">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="14" width="7" height="7" rx="1.5"/>
            <rect x="3" y="14" width="7" height="7" rx="1.5"/>
          </svg>
          <select name="department" id="deptSelect" class="km-filter-select">
            <option value="">All departments</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?= htmlspecialchars($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>>
                <?= htmlspecialchars($dept) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <svg class="km-select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </div>

        <!-- Search Button -->
        <button type="submit" class="km-search-btn">Search jobs</button>
      </form>

    </div>
  </section>

  <!-- OPEN POSITIONS SECTION -->
  <section class="km-positions-section">
    <div class="container">
      <h2 class="km-section-title">Open positions</h2>
      <p class="km-section-subtitle">Find a role that fits you.</p>
      <div class="km-positions-meta">
        <span id="jobCounter"><strong><?= count($jobs) ?> <?= count($jobs) === 1 ? 'opening' : 'openings' ?></strong></span>
        <span> &nbsp;|&nbsp; Sample openings</span>
      </div>

      <!-- Jobs Grid -->
      <div class="km-jobs-grid" id="jobsGrid">
        <?php if (empty($jobs)): ?>
          <div style="grid-column: 1 / -1; padding: 48px 24px; text-align: center; background: #FFF; border: 1px solid var(--km-border); border-radius: 14px;">
            <p style="font-size: 15px; color: var(--km-text-muted); margin-bottom: 12px;">No matching open positions found for your criteria.</p>
            <a href="careers.php" class="km-btn-outline">Clear all filters</a>
          </div>
        <?php else: ?>
          <?php foreach ($jobs as $job): ?>
            <div class="km-job-card" data-id="<?= (int)$job['id'] ?>" data-slug="<?= htmlspecialchars($job['slug']) ?>">
              <!-- Card Top -->
              <div class="km-job-header">
                <div class="km-briefcase-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                  </svg>
                </div>
                <div class="km-job-title-area">
                  <h3 class="km-job-title"><?= htmlspecialchars($job['title']) ?></h3>
                  <div class="km-job-badges">
                    <span class="km-job-location">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                      </svg>
                      <?= htmlspecialchars($job['location']) ?>
                    </span>
                    <span class="km-job-tag"><?= htmlspecialchars($job['job_type']) ?></span>
                  </div>
                </div>
              </div>

              <!-- Description -->
              <p class="km-job-desc"><?= htmlspecialchars($job['tagline'] ?: $job['description']) ?></p>

              <!-- Actions -->
              <div class="km-job-actions">
                <button type="button" class="km-btn-outline" onclick="openJobDetails(<?= htmlspecialchars(json_encode($job)) ?>)">
                  View details
                </button>
                <a href="apply.php?job=<?= urlencode($job['slug']) ?>" class="km-btn-filled">
                  Apply now
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- WHY JOIN KOFEE MANILA SECTION -->
  <section class="km-why-section">
    <div class="container">
      <div class="km-why-card">
        <div class="km-why-intro">
          <h3 class="km-why-title">Why join Kofee Manila?</h3>
          <p class="km-why-subtitle">More than a job. A place to belong.</p>
        </div>

        <div class="km-why-pillars">
          <!-- Pillar 1: Grow your skills -->
          <div class="km-pillar">
            <div class="km-pillar-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 20V10M12 20V4M6 20v-6"/>
              </svg>
            </div>
            <div>
              <h4>Grow your skills</h4>
              <p>Learn, improve, and build a brighter future.</p>
            </div>
          </div>

          <!-- Pillar 2: Work with a team -->
          <div class="km-pillar">
            <div class="km-pillar-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div>
              <h4>Work with a team</h4>
              <p>Be part of a supportive and passionate community.</p>
            </div>
          </div>

          <!-- Pillar 3: Make every cup count -->
          <div class="km-pillar">
            <div class="km-pillar-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
                <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                <line x1="6" y1="1" x2="6" y2="4"/>
                <line x1="10" y1="1" x2="10" y2="4"/>
                <line x1="14" y1="1" x2="14" y2="4"/>
              </svg>
            </div>
            <div>
              <h4>Make every cup count</h4>
              <p>Bring good coffee and positive moments to people's days.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer class="km-footer">
    <div class="container">
      <div class="km-footer-inner">
        <div class="km-footer-brand">
          Kofee Manila &nbsp;·&nbsp; Careers
        </div>
        <div class="km-footer-links">
          <a href="javascript:void(0)" onclick="openPrivacyModal()">Privacy policy</a>
          <span>|</span>
          <a href="javascript:void(0)" onclick="openContactModal()">Contact us</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- ── MODAL: TRACK APPLICATION ────────────────────────── -->
  <div class="km-modal-backdrop" id="trackModalBackdrop" onclick="handleBackdropClick(event, 'trackModalBackdrop')">
    <div class="km-modal" style="max-width: 500px;">
      <button type="button" class="km-modal-close" onclick="closeTrackModal()">✕</button>
      <div class="km-modal-header">
        <h3 class="km-modal-title">Track your application</h3>
        <p class="km-modal-subtitle">Enter your Application Code (e.g. KM-2026-BAR-XXXX) or registered email address.</p>
      </div>

      <div style="margin-bottom: 18px;">
        <label class="km-form-label" for="trackQueryInput" style="margin-bottom: 6px; display: block;">Application Code or Email</label>
        <div style="display: flex; gap: 8px;">
          <input type="text" id="trackQueryInput" class="km-form-input" placeholder="e.g. KM-2026-BAR-1234" autocomplete="off">
          <button type="button" class="km-submit-btn" id="trackSubmitBtn" onclick="queryTrackStatus()">Check</button>
        </div>
      </div>

      <!-- Result Container -->
      <div id="trackResultArea" style="display: none; border-top: 1px solid var(--km-border-light); padding-top: 18px; margin-top: 18px;">
        <!-- Filled via JS -->
      </div>
    </div>
  </div>

  <!-- ── MODAL: JOB DETAILS ──────────────────────────────── -->
  <div class="km-modal-backdrop" id="detailsModalBackdrop" onclick="handleBackdropClick(event, 'detailsModalBackdrop')">
    <div class="km-modal" style="max-width: 580px;">
      <button type="button" class="km-modal-close" onclick="closeJobDetails()">✕</button>
      <div class="km-modal-header">
        <div class="km-job-badges" style="margin-bottom: 8px;">
          <span class="km-job-tag" id="modalJobDepartment">Coffee & Barista</span>
          <span class="km-job-location" id="modalJobLocation">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span id="modalJobLocationText">Manila</span>
          </span>
          <span class="km-job-tag" id="modalJobType">Full-time</span>
        </div>
        <h3 class="km-modal-title" id="modalJobTitle">Barista</h3>
        <p class="km-modal-subtitle" id="modalJobTagline">Craft quality beverages and create welcoming experiences for every guest.</p>
      </div>

      <div style="display: flex; flex-direction: column; gap: 18px; font-size: 13.5px; line-height: 1.6; color: var(--km-text-body);" id="modalJobContent">
        <div>
          <h4 style="font-size: 14px; font-weight: 700; color: var(--km-text-dark); margin-bottom: 6px;">About the Role</h4>
          <p id="modalAboutRole" style="color: var(--km-text-muted);"></p>
        </div>

        <div id="modalRespSection">
          <h4 style="font-size: 14px; font-weight: 700; color: var(--km-text-dark); margin-bottom: 6px;">Key Responsibilities</h4>
          <ul id="modalResponsibilities" style="padding-left: 18px; color: var(--km-text-muted);"></ul>
        </div>

        <div id="modalReqSection">
          <h4 style="font-size: 14px; font-weight: 700; color: var(--km-text-dark); margin-bottom: 6px;">Qualifications & Requirements</h4>
          <ul id="modalRequirements" style="padding-left: 18px; color: var(--km-text-muted);"></ul>
        </div>

        <div id="modalBenSection">
          <h4 style="font-size: 14px; font-weight: 700; color: var(--km-text-dark); margin-bottom: 6px;">Perks & Benefits</h4>
          <ul id="modalBenefits" style="padding-left: 18px; color: var(--km-text-muted);"></ul>
        </div>
      </div>

      <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--km-border-light); display: flex; justify-content: flex-end; gap: 12px;">
        <button type="button" class="km-btn-outline" onclick="closeJobDetails()">Close</button>
        <a href="#" id="modalApplyBtn" class="km-btn-filled" style="padding: 10px 24px;">Apply for this position</a>
      </div>
    </div>
  </div>

  <!-- ── MODAL: PRIVACY POLICY ────────────────────────────── -->
  <div class="km-modal-backdrop" id="privacyModalBackdrop" onclick="handleBackdropClick(event, 'privacyModalBackdrop')">
    <div class="km-modal" style="max-width: 520px;">
      <button type="button" class="km-modal-close" onclick="closePrivacyModal()">✕</button>
      <div class="km-modal-header">
        <h3 class="km-modal-title">Privacy Notice for Applicants</h3>
        <p class="km-modal-subtitle">How Kofee Manila handles your candidate data</p>
      </div>
      <div style="font-size: 13px; line-height: 1.65; color: var(--km-text-muted); display: flex; flex-direction: column; gap: 12px;">
        <p>At <strong>Kofee Manila</strong>, we respect your personal data and comply with the Philippine Data Privacy Act of 2012 (RA 10173).</p>
        <p>By submitting your job application, you consent to our recruitment team collecting, evaluating, and storing your submitted contact information, employment history, and resume file strictly for recruitment and workforce evaluation purposes.</p>
        <p>Your resume and application details will never be sold, rented, or shared with unauthorized third parties. You may request deletion or updates of your candidate records by contacting <a href="mailto:careers@kofeemanila.com" style="color:var(--km-caramel);text-decoration:underline;">careers@kofeemanila.com</a>.</p>
      </div>
      <div style="margin-top: 20px; text-align: right;">
        <button type="button" class="km-btn-outline" onclick="closePrivacyModal()">Understood</button>
      </div>
    </div>
  </div>

  <!-- ── MODAL: CONTACT US ────────────────────────────────── -->
  <div class="km-modal-backdrop" id="contactModalBackdrop" onclick="handleBackdropClick(event, 'contactModalBackdrop')">
    <div class="km-modal" style="max-width: 460px;">
      <button type="button" class="km-modal-close" onclick="closeContactModal()">✕</button>
      <div class="km-modal-header">
        <h3 class="km-modal-title">Contact Our Talent Team</h3>
        <p class="km-modal-subtitle">We are always happy to connect with great talent.</p>
      </div>
      <div style="font-size: 13.5px; line-height: 1.7; color: var(--km-text-body); display: flex; flex-direction: column; gap: 10px;">
        <div><strong>Recruitment Email:</strong> <a href="mailto:careers@kofeemanila.com" style="color:var(--km-caramel);">careers@kofeemanila.com</a></div>
        <div><strong>Store Operations:</strong> Manila, Quezon City, and Makati branches</div>
        <div><strong>Hours:</strong> Monday to Friday, 9:00 AM – 6:00 PM PHT</div>
      </div>
      <div style="margin-top: 20px; text-align: right;">
        <button type="button" class="km-btn-outline" onclick="closeContactModal()">Close</button>
      </div>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
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
        counterEl.innerHTML = `<strong>${visible} ${visible === 1 ? 'opening' : 'openings'}</strong>`;
      }
    }

    searchInput.addEventListener('input', filterJobsLive);
    locSelect.addEventListener('change', filterJobsLive);
    deptSelect.addEventListener('change', filterJobsLive);

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
      resArea.innerHTML = '<p style="color:var(--km-text-muted);font-size:13px;text-align:center;">Looking up application records…</p>';

      try {
        const resp = await fetch('api/track_application.php?query=' + encodeURIComponent(q));
        const data = await resp.json();

        if (!data.success) {
          resArea.innerHTML = `
            <div style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:12px 14px;color:#C53030;font-size:13px;">
              ${data.error || 'No matching application record found.'}
            </div>
          `;
          return;
        }

        const step = data.current_step;
        resArea.innerHTML = `
          <div style="background:#FAF7F2;border:1px solid var(--km-border);border-radius:10px;padding:16px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
              <div>
                <span style="font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:var(--km-caramel);">${data.application_code}</span>
                <h4 style="font-family:var(--km-font-serif);font-size:17px;font-weight:700;color:var(--km-text-dark);">${data.job_title}</h4>
                <div style="font-size:12px;color:var(--km-text-muted);margin-top:2px;">
                  Applicant: <strong>${data.candidate_name}</strong> &bull; Submitted on ${data.submitted_at}
                </div>
              </div>
              <span class="km-job-tag" style="background:#EFE8DF;">${data.job_location}</span>
            </div>

            <!-- Pipeline Status Stepper -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px dashed var(--km-border);">
              <div style="display:flex;justify-content:space-between;position:relative;margin-bottom:14px;">
                <div style="position:absolute;top:14px;left:20px;right:20px;height:2px;background:#E2D9CE;z-index:1;"></div>
                <div style="position:absolute;top:14px;left:20px;width:${step === 1 ? '0%' : step === 2 ? '50%' : '100%'};height:2px;background:var(--km-caramel);z-index:2;transition:width 0.3s ease;"></div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:80px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 1 ? 'var(--km-caramel)' : '#FFF'};border:2px solid ${step >= 1 ? 'var(--km-caramel)' : '#C49E7C'};color:${step >= 1 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">1</div>
                  <span style="font-size:11px;font-weight:${step === 1 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Review</span>
                </div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:80px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 2 ? 'var(--km-caramel)' : '#FFF'};border:2px solid ${step >= 2 ? 'var(--km-caramel)' : '#C49E7C'};color:${step >= 2 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">2</div>
                  <span style="font-size:11px;font-weight:${step === 2 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Interview</span>
                </div>

                <div style="display:flex;flex-direction:column;align-items:center;z-index:3;width:80px;text-align:center;">
                  <div style="width:28px;height:28px;border-radius:50%;background:${step >= 3 ? 'var(--km-caramel)' : '#FFF'};border:2px solid ${step >= 3 ? 'var(--km-caramel)' : '#C49E7C'};color:${step >= 3 ? '#FFF' : '#7D4924'};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">3</div>
                  <span style="font-size:11px;font-weight:${step === 3 ? '700' : '500'};color:var(--km-text-dark);margin-top:4px;">Decision</span>
                </div>
              </div>

              <div style="background:#FFF;border-radius:8px;border:1px solid var(--km-border-light);padding:12px;font-size:12.5px;color:var(--km-text-body);line-height:1.5;">
                <strong style="color:var(--km-text-dark);display:block;margin-bottom:2px;">Current Status: ${data.stage_label}</strong>
                ${data.status_message}
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
  </script>

</body>
</html>
