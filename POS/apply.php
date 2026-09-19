<?php
// ─────────────────────────────────────────────────────────────
//  Kofee Manila — Job Application Form
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/db.php';

$pdo = get_db();
$jobSlug = trim($_GET['job'] ?? 'barista');
$jobId = (int)($_GET['id'] ?? 0);

// Lookup job posting
$job = null;
if ($jobId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM job_postings WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();
}

if (!$job && !empty($jobSlug)) {
    $stmt = $pdo->prepare('SELECT * FROM job_postings WHERE slug = :slug AND is_active = 1 LIMIT 1');
    $stmt->execute([':slug' => $jobSlug]);
    $job = $stmt->fetch();
}

// Fallback to first active position if not found
if (!$job) {
    $stmt = $pdo->query('SELECT * FROM job_postings WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $job = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Apply for <?= htmlspecialchars($job ? $job['title'] : 'Position') ?> — Kofee Manila Careers</title>
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

        <!-- Links -->
        <nav class="km-nav-links">
          <a href="index.html" class="km-nav-link">Home</a>
          <a href="careers.php" class="km-nav-link active">Careers</a>
          <a href="index.html#about" class="km-nav-link">About Us</a>
        </nav>

        <!-- Track Application Button -->
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

  <!-- APPLICATION FORM MAIN SECTION -->
  <main class="km-apply-page">
    <div class="container" style="position:relative;">

      <!-- Top-right Coffee Stain Watermark -->
      <div class="km-art-watermark" aria-hidden="true">
        <svg class="km-coffee-rings" viewBox="0 0 200 200" fill="none">
          <circle cx="105" cy="95" r="70" stroke="#9A6B46" stroke-width="6.5" stroke-dasharray="14 4 40 8 20 6" opacity="0.4" />
          <circle cx="105" cy="95" r="76" stroke="#C4936B" stroke-width="2" opacity="0.25" />
          <circle cx="103" cy="93" r="64" stroke="#7A4D2A" stroke-width="3" stroke-dasharray="8 6 24 5" opacity="0.3" />
          <circle cx="130" cy="115" r="48" stroke="#8E5D38" stroke-width="5" stroke-dasharray="12 4 30 6" opacity="0.35" />
          <circle cx="130" cy="115" r="54" stroke="#B8855F" stroke-width="1.8" opacity="0.2" />
          <circle cx="48" cy="72" r="3.5" fill="#A3724C" opacity="0.45" />
          <circle cx="56" cy="62" r="2" fill="#A3724C" opacity="0.35" />
          <circle cx="68" cy="96" r="2.5" fill="#8E5D38" opacity="0.4" />
        </svg>
        <div class="km-script-text">
          Good People
          <span>Better Coffee</span>
        </div>
      </div>

      <!-- Breadcrumb Link -->
      <a href="careers.php" class="km-back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
        Back to openings
      </a>

      <!-- Apply Hero -->
      <div class="km-apply-hero">
        <h1 class="km-apply-title">Apply for <?= htmlspecialchars($job['title']) ?></h1>
        <p class="km-apply-subtitle">Tell us about yourself. We're glad you're here.</p>
      </div>

      <!-- Application Layout -->
      <div class="km-apply-layout">

        <!-- LEFT COLUMN: APPLICATION FORM -->
        <div class="km-form-card">
          <form id="applicationForm" action="api/submit_application.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="job_slug" value="<?= htmlspecialchars($job['slug']) ?>">

            <!-- Section 1: Personal information -->
            <span class="km-form-section-title">Personal information</span>
            
            <div class="km-form-row">
              <div class="km-form-group">
                <label class="km-form-label" for="firstName">First name <span class="req">*</span></label>
                <input type="text" id="firstName" name="first_name" class="km-form-input" required autocomplete="given-name">
              </div>
              <div class="km-form-group">
                <label class="km-form-label" for="lastName">Last name <span class="req">*</span></label>
                <input type="text" id="lastName" name="last_name" class="km-form-input" required autocomplete="family-name">
              </div>
            </div>

            <div class="km-form-row">
              <div class="km-form-group">
                <label class="km-form-label" for="emailAddr">Email address <span class="req">*</span></label>
                <input type="email" id="emailAddr" name="email" class="km-form-input" required autocomplete="email">
              </div>
              <div class="km-form-group">
                <label class="km-form-label" for="mobileNum">Mobile number <span class="req">*</span></label>
                <input type="tel" id="mobileNum" name="phone" class="km-form-input" placeholder="09XXXXXXXXX" required autocomplete="tel">
              </div>
            </div>

            <div class="km-form-row single">
              <div class="km-form-group">
                <label class="km-form-label" for="cityName">City / Municipality <span class="req">*</span></label>
                <input type="text" id="cityName" name="city" class="km-form-input" placeholder="e.g. Manila, Quezon City, Makati" required autocomplete="address-level2">
              </div>
            </div>

            <div class="km-form-divider"></div>

            <!-- Section 2: Experience & availability -->
            <span class="km-form-section-title">Experience &amp; availability</span>

            <div class="km-form-row">
              <div class="km-form-group">
                <label class="km-form-label" for="expSelect">Relevant experience</label>
                <select id="expSelect" name="experience" class="km-form-select" required>
                  <option value="" disabled selected>Select experience</option>
                  <option value="No prior experience (Fresh grad / Student)">No prior experience (Fresh grad / Student)</option>
                  <option value="Less than 1 year">Less than 1 year</option>
                  <option value="1 - 2 years">1 - 2 years</option>
                  <option value="3 - 5 years">3 - 5 years</option>
                  <option value="5+ years">5+ years</option>
                </select>
              </div>

              <div class="km-form-group">
                <label class="km-form-label" for="startDate">Available start date</label>
                <input type="date" id="startDate" name="start_date" class="km-form-input" required min="<?= date('Y-m-d') ?>">
              </div>
            </div>

            <div class="km-form-divider"></div>

            <!-- Section 3: Resume -->
            <span class="km-form-section-title">Resume</span>

            <div class="km-dropzone" id="resumeDropzone" onclick="document.getElementById('resumeFileInput').click()">
              <input type="file" id="resumeFileInput" name="resume" accept=".pdf,.doc,.docx" style="display:none" required>
              <svg class="km-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                <polyline points="12 13 12 9 10 11"/>
                <line x1="12" y1="9" x2="14" y2="11"/>
              </svg>
              <div class="km-dropzone-title">Drag and drop your resume here</div>
              <button type="button" class="km-choose-btn" onclick="event.stopPropagation(); document.getElementById('resumeFileInput').click()">
                Choose file
              </button>
              <div class="km-dropzone-hint">PDF or DOCX · Maximum 5 MB</div>
            </div>

            <!-- Selected File Badge -->
            <div class="km-selected-file-badge" id="selectedFileBadge">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;color:var(--km-caramel);flex-shrink:0;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              <span id="selectedFileName" style="font-weight:600;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;max-width:260px;">resume.pdf</span>
              <span id="selectedFileSize" style="color:var(--km-text-muted);font-size:11.5px;">(1.2 MB)</span>
              <button type="button" class="km-file-remove" onclick="removeSelectedFile()">Remove</button>
            </div>

            <!-- Section 4: Additional message (optional) -->
            <div style="margin-top: 24px;">
              <span class="km-form-section-title">Additional message <span style="font-weight:400;font-size:14px;color:var(--km-text-muted);">(optional)</span></span>
              <textarea id="additionalMessage" name="message" class="km-form-textarea" rows="3" placeholder="Tell us why you'd like to join Kofee Manila"></textarea>
            </div>

            <!-- Consent Checkbox -->
            <div class="km-consent-row">
              <input type="checkbox" id="privacyConsent" name="privacy_consent" value="1" class="km-consent-checkbox" required>
              <label for="privacyConsent">
                I have read the <a href="javascript:void(0)" onclick="openPrivacyModal()">Privacy Notice</a> and consent to the processing of my information for recruitment.
              </label>
            </div>

            <!-- Submission Errors / Feedback Banner -->
            <div id="formErrorBanner" style="display:none;margin-bottom:16px;background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:12px 14px;color:#C53030;font-size:13px;"></div>

            <!-- Actions Row -->
            <div class="km-form-actions">
              <div class="km-action-buttons">
                <button type="submit" class="km-submit-btn" id="submitBtn">
                  Submit application
                </button>
                <a href="careers.php" class="km-cancel-btn">Cancel</a>
              </div>
              <span class="km-review-hint">Review your information before submitting.</span>
            </div>

          </form>
        </div>

        <!-- RIGHT COLUMN: CONTEXT CARDS -->
        <aside class="km-sidebar-area">
          
          <!-- Card 1: You're applying for -->
          <div class="km-context-card">
            <h3 class="km-context-card-title">You're applying for</h3>
            
            <div class="km-role-preview">
              <div class="km-briefcase-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                  <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
              </div>
              <div>
                <h4 class="km-role-details-title"><?= htmlspecialchars($job['title']) ?></h4>
                <div class="km-role-meta-line">
                  <span style="display:inline-flex;align-items:center;gap:4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= htmlspecialchars($job['location']) ?>
                  </span>
                  <span class="sep">|</span>
                  <span style="display:inline-flex;align-items:center;gap:4px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?= htmlspecialchars($job['job_type']) ?>
                  </span>
                </div>
              </div>
            </div>

            <div style="border-top: 1px solid var(--km-border-light); padding-top: 14px;">
              <div class="km-role-about-title">About the role</div>
              <p class="km-role-about-text"><?= htmlspecialchars($job['about_role'] ?: $job['description']) ?></p>
            </div>
          </div>

          <!-- Card 2: What happens next? -->
          <div class="km-context-card">
            <h3 class="km-context-card-title">What happens next?</h3>

            <div class="km-stepper">
              <!-- Step 1 -->
              <div class="km-step-item">
                <div class="km-step-indicator">
                  <div class="km-step-circle">1</div>
                  <div class="km-step-line"></div>
                </div>
                <div class="km-step-body">
                  <div class="km-step-title">Application review</div>
                  <div class="km-step-desc">We'll review your application to see how your experience and skills fit the role.</div>
                </div>
              </div>

              <!-- Step 2 -->
              <div class="km-step-item">
                <div class="km-step-indicator">
                  <div class="km-step-circle">2</div>
                  <div class="km-step-line"></div>
                </div>
                <div class="km-step-body">
                  <div class="km-step-title">Interview</div>
                  <div class="km-step-desc">Shortlisted candidates will be invited for an interview.</div>
                </div>
              </div>

              <!-- Step 3 -->
              <div class="km-step-item">
                <div class="km-step-indicator">
                  <div class="km-step-circle">3</div>
                  <div class="km-step-line"></div>
                </div>
                <div class="km-step-body">
                  <div class="km-step-title">Hiring decision</div>
                  <div class="km-step-desc">We'll be in touch once a decision has been made.</div>
                </div>
              </div>
            </div>

            <div class="km-stepper-foot">
              Our team will contact shortlisted applicants.
            </div>
          </div>

        </aside>
      </div>

    </div>
  </main>

  <!-- BOTTOM VALUE PROPS BANNER -->
  <section class="km-why-section" style="padding-top: 20px;">
    <div class="container">
      <div class="km-why-card" style="border-top: 1px solid var(--km-border);">
        <div class="km-why-intro">
          <h3 class="km-why-title">Why join Kofee Manila?</h3>
          <p class="km-why-subtitle">More than a job. A place to belong.</p>
        </div>

        <div class="km-why-pillars">
          <!-- Pillar 1 -->
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

          <!-- Pillar 2 -->
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

          <!-- Pillar 3 -->
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

  <!-- ── MODAL: APPLICATION SUCCESS ──────────────────────── -->
  <div class="km-modal-backdrop" id="successModalBackdrop">
    <div class="km-modal" style="max-width: 480px; text-align: center; padding: 36px 30px;">
      <div style="width: 58px; height: 58px; border-radius: 50%; background: #F0FDF4; border: 2px solid #86EFAC; color: #16A34A; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 28px; height: 28px;"><polyline points="20 6 9 17 4 12"/></svg>
      </div>

      <h3 class="km-modal-title" style="font-size: 24px; margin-bottom: 8px;">Application Submitted!</h3>
      <p style="font-size: 14px; color: var(--km-text-muted); line-height: 1.6; margin-bottom: 22px;">
        Thank you for applying to join the Kofee Manila team. We have received your information and resume.
      </p>

      <div style="background: #FAF7F2; border: 1.5px dashed var(--km-caramel); border-radius: 12px; padding: 18px; margin-bottom: 24px;">
        <span style="font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--km-text-muted); display: block; margin-bottom: 6px;">Your Application Tracking Code</span>
        <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
          <span id="submittedCodeText" style="font-family: monospace; font-size: 20px; font-weight: 800; color: var(--km-text-dark); letter-spacing: 0.05em;">KM-2026-BAR-0000</span>
          <button type="button" onclick="copyTrackingCode()" style="background: #FFFFFF; border: 1px solid #D6CBC1; border-radius: 6px; padding: 5px 9px; cursor: pointer; font-size: 11.5px; font-weight: 600; color: var(--km-text-dark);" id="copyCodeBtn">
            Copy
          </button>
        </div>
        <p style="font-size: 11.5px; color: var(--km-text-faint); margin-top: 8px;">Save this code or check your email to track your application stage at any time.</p>
      </div>

      <div style="display: flex; gap: 12px; justify-content: center;">
        <button type="button" class="km-btn-outline" onclick="goToTrackingFromSuccess()">Track status now</button>
        <a href="careers.php" class="km-btn-filled" style="padding: 10px 22px;">Return to Careers</a>
      </div>
    </div>
  </div>

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
      <div id="trackResultArea" style="display: none; border-top: 1px solid var(--km-border-light); padding-top: 18px; margin-top: 18px;"></div>
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
    // Resume Dropzone & File Handling
    const dropzone = document.getElementById('resumeDropzone');
    const fileInput = document.getElementById('resumeFileInput');
    const fileBadge = document.getElementById('selectedFileBadge');
    const fileNameEl = document.getElementById('selectedFileName');
    const fileSizeEl = document.getElementById('selectedFileSize');

    ['dragenter', 'dragover'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('dragover');
      });
    });

    ['dragleave', 'drop'].forEach(eventName => {
      dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('dragover');
      });
    });

    dropzone.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      const files = dt.files;
      if (files.length > 0) {
        fileInput.files = files;
        handleFileSelected(files[0]);
      }
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files.length > 0) {
        handleFileSelected(fileInput.files[0]);
      }
    });

    function handleFileSelected(file) {
      const allowedExts = ['pdf', 'doc', 'docx'];
      const ext = file.name.split('.').pop().toLowerCase();
      if (!allowedExts.includes(ext)) {
        alert('Invalid file format. Please upload a PDF or DOCX file.');
        removeSelectedFile();
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        alert('File size exceeds the 5 MB limit. Please select a smaller file.');
        removeSelectedFile();
        return;
      }
      fileNameEl.textContent = file.name;
      const mb = (file.size / (1024 * 1024)).toFixed(2);
      fileSizeEl.textContent = `(${mb} MB)`;
      fileBadge.classList.add('show');
    }

    function removeSelectedFile() {
      fileInput.value = '';
      fileBadge.classList.remove('show');
    }

    // AJAX Form Submission
    const appForm = document.getElementById('applicationForm');
    const submitBtn = document.getElementById('submitBtn');
    const errorBanner = document.getElementById('formErrorBanner');
    let lastTrackingCode = '';

    appForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBanner.style.display = 'none';

      if (!fileInput.files.length) {
        errorBanner.innerText = 'Please attach your resume file (PDF or DOCX).';
        errorBanner.style.display = 'block';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = 'Submitting application…';

      try {
        const formData = new FormData(appForm);
        const resp = await fetch('api/submit_application.php', {
          method: 'POST',
          body: formData
        });
        const result = await resp.json();

        if (!result.success) {
          errorBanner.innerText = result.error || 'Failed to submit application. Please check your fields.';
          errorBanner.style.display = 'block';
          submitBtn.disabled = false;
          submitBtn.innerText = 'Submit application';
          return;
        }

        // Show Success Modal
        lastTrackingCode = result.tracking_code;
        document.getElementById('submittedCodeText').innerText = result.tracking_code;
        document.getElementById('successModalBackdrop').classList.add('open');
        appForm.reset();
        removeSelectedFile();

      } catch (err) {
        errorBanner.innerText = 'Network error while submitting application. Please try again.';
        errorBanner.style.display = 'block';
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = 'Submit application';
      }
    });

    function copyTrackingCode() {
      if (!lastTrackingCode) return;
      navigator.clipboard.writeText(lastTrackingCode).then(() => {
        const btn = document.getElementById('copyCodeBtn');
        btn.innerText = 'Copied!';
        setTimeout(() => { btn.innerText = 'Copy'; }, 2000);
      });
    }

    function goToTrackingFromSuccess() {
      document.getElementById('successModalBackdrop').classList.remove('open');
      openTrackModal();
      document.getElementById('trackQueryInput').value = lastTrackingCode;
      queryTrackStatus();
    }

    // Modals
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

    // Application Tracking Lookup
    async function queryTrackStatus() {
      const q = document.getElementById('trackQueryInput').value.trim();
      const resArea = document.getElementById('trackResultArea');
      const trackBtn = document.getElementById('trackSubmitBtn');

      if (!q) {
        alert('Please enter your Application Code or Email address.');
        return;
      }

      trackBtn.disabled = true;
      trackBtn.innerText = 'Checking…';
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
        trackBtn.disabled = false;
        trackBtn.innerText = 'Check';
      }
    }
  </script>

</body>
</html>
