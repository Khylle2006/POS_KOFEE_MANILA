const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const careersPath = path.join(__dirname, '..', 'careers.php');
const supplierPath = path.join(__dirname, '..', 'supplier_partnership.php');
const indexPath = path.join(__dirname, '..', 'index.html');
const publicCssPath = path.join(__dirname, '..', 'css', 'brewvanti-public.css');

test('css/brewvanti-public.css defines luxury 2.5D design tokens matching index.html', () => {
    const css = fs.readFileSync(publicCssPath, 'utf8');

    // Color tokens
    assert.match(css, /--color-cream:\s*#FAF7F2/);
    assert.match(css, /--color-gold:\s*#D9BA85/);
    assert.match(css, /--color-mocha:\s*#68584B/);
    assert.match(css, /--color-espresso-pure:\s*#120C0D/);

    // Key components
    assert.match(css, /header\.brewvanti-header/);
    assert.match(css, /\.brand-logo-frame/);
    assert.match(css, /@keyframes logoAuraPulse/);
    assert.match(css, /\.cart-indicator-pill/);
    assert.match(css, /\.btn-login-cta/);
    assert.match(css, /@keyframes btnShimmer/);
    assert.match(css, /\.hero-section/);
    assert.match(css, /\.ambient-sparkle/);
    assert.match(css, /\.luxury-card/);
    assert.match(css, /footer\.brewvanti-footer/);
    assert.match(css, /\.km-modal-backdrop/);
});

test('careers.php matches index.html header, hero, cards, footer, and modals', () => {
    const html = fs.readFileSync(careersPath, 'utf8');

    // Stylesheet link
    assert.match(html, /href="css\/brewvanti-public\.css/);

    // Brand and header
    assert.match(html, /<header class="brewvanti-header">/);
    assert.match(html, /class="brand-logo-frame"/);
    assert.match(html, /assets\/brewvanti_logo\.png/);
    assert.match(html, /<h1>BrewVanti<\/h1>/);
    assert.match(html, /<span>Artisan Coffee &amp; Brew<\/span>/);

    // Nav Links (symmetric with index.html)
    assert.match(html, /href="index\.html#menu">Artisan Menu<\/a>/);
    assert.match(html, /href="index\.html#about">Story<\/a>/);
    assert.match(html, /href="careers\.php" class="active">Hiring<\/a>/);
    assert.match(html, /href="supplier_partnership\.php">Suppliers<\/a>/);
    assert.match(html, /href="index\.html#contact">Visit Us<\/a>/);

    // Header actions
    assert.match(html, /onclick="openTrackModal\(\)"/);
    assert.match(html, /class="btn-login-cta"/);
    assert.match(html, /href="php\/login\.php"/);

    // 2.5D Hero Section
    assert.match(html, /class="hero-section"/);
    assert.match(html, /ambient-sparkle sp-1/);
    assert.match(html, /CAREERS AT BREWVANTI/);
    assert.match(html, /Craft your next/);
    assert.match(html, /id="jobFilterForm"/);
    assert.match(html, /id="searchInput"/);
    assert.match(html, /id="locationSelect"/);
    assert.match(html, /id="deptSelect"/);

    // Positions Section & luxury cards
    assert.match(html, /class="positions-section"/);
    assert.match(html, /id="jobCounter"/);
    assert.match(html, /class="luxury-card job-card km-job-card"/);

    // Why Join Section
    assert.match(html, /class="why-section"/);
    assert.match(html, /Why join BrewVanti\?/);
    assert.match(html, /Grow Your Craft/);
    assert.match(html, /Artisan Community/);

    // Footer
    assert.match(html, /<footer class="brewvanti-footer"/);
    assert.match(html, /102 Artisan Ave, Manila/);
    assert.match(html, /\+63 \(2\) 8920-BREW/);

    // On-page Application Status Tracker section (Above footer)
    assert.match(html, /id="secure-application-tracking"/);
    assert.match(html, /id="onpageCareerTrackForm"/);
    assert.match(html, /id="onpageCareerEmail"/);
    assert.match(html, /id="onpageCareerResultArea"/);

    // Modals
    assert.match(html, /id="trackModalBackdrop"/);
    assert.match(html, /id="detailsModalBackdrop"/);
    assert.match(html, /id="privacyModalBackdrop"/);
    assert.match(html, /id="contactModalBackdrop"/);
});

test('supplier_partnership.php matches index.html header, hero, pillars, wizard, and status tracking', () => {
    const html = fs.readFileSync(supplierPath, 'utf8');

    // Stylesheet link
    assert.match(html, /href="css\/brewvanti-public\.css/);

    // Brand and header
    assert.match(html, /<header class="brewvanti-header">/);
    assert.match(html, /class="brand-logo-frame"/);
    assert.match(html, /assets\/brewvanti_logo\.png/);
    assert.match(html, /<h1>BrewVanti<\/h1>/);
    assert.match(html, /<span>Artisan Coffee &amp; Brew<\/span>/);

    // Nav Links (symmetric with index.html)
    assert.match(html, /href="index\.html#menu">Artisan Menu<\/a>/);
    assert.match(html, /href="index\.html#about">Story<\/a>/);
    assert.match(html, /href="careers\.php">Hiring<\/a>/);
    assert.match(html, /href="supplier_partnership\.php" class="active">Suppliers<\/a>/);
    assert.match(html, /href="index\.html#contact">Visit Us<\/a>/);

    // Header actions
    assert.match(html, /onclick="openTrackModal\(\)"/);
    assert.match(html, /class="btn-login-cta"/);
    assert.match(html, /href="php\/login\.php"/);

    // 2.5D Hero Section & Fast Facts
    assert.match(html, /class="hero-section"/);
    assert.match(html, /ambient-sparkle sp-1/);
    assert.match(html, /SUPPLIER PARTNERSHIP &amp; ACCREDITATION/);
    assert.match(html, /Grow your business with/);
    assert.match(html, /class="hero-card"/);
    assert.match(html, /Accredited Procurement Network/);
    assert.match(html, /onclick="openSupplierWizard\(\)"/);

    // Pillars Section
    assert.match(html, /class="pillars-section"/);
    assert.match(html, /Digital RFQs &amp; Direct Bidding/);
    assert.match(html, /PayMongo Direct Disbursements/);
    assert.match(html, /Transparent Quality Standards/);

    // Sourcing Directory & Categories
    assert.match(html, /class="sourcing-section"/);
    assert.match(html, /What we currently procure/);
    assert.match(html, /class="ingredient-tag"/);

    // Step-by-Step Wizard Modal
    assert.match(html, /id="supplierWizardModal"/);
    assert.match(html, /class="wizard-stepper"/);
    assert.match(html, /id="wizardPane1"/);
    assert.match(html, /id="wizardPane2"/);
    assert.match(html, /id="wizardPane3"/);
    assert.match(html, /id="wizardPane4"/);

    // File Uploads (Multiple files allowed)
    assert.match(html, /id="input_business_permit"/);
    assert.match(html, /id="input_authenticity_cert"/);
    assert.match(html, /id="input_multi_additional" name="additional_documents\[\]" multiple/);
    assert.match(html, /handleMultiFiles\(this\)/);

    // On-page Application Status Tracker section (Above footer)
    assert.match(html, /id="secure-application-tracking"/);
    assert.match(html, /id="onpageTrackForm"/);
    assert.match(html, /id="onpageTrackEmail"/);
    assert.match(html, /id="onpageTrackCode"/);
    assert.match(html, /id="onpageTrackResultArea"/);
    assert.match(html, /handleOnpageTrackSubmit/);
    assert.match(html, /handleOnpageRecoverLink/);

    // Status Tracking Modal & Overhauled Stepper
    assert.match(html, /id="trackModalBackdrop"/);
    assert.match(html, /class="status-stepper"/);
    assert.match(html, /id="trackQueryCode"/);
    assert.match(html, /id="trackQueryEmail"/);
    assert.match(html, /id="trackResultCard"/);

    // Success Modal
    assert.match(html, /id="successModalBackdrop"/);
    assert.match(html, /id="newTrackingCodeText"/);

    // Footer
    assert.match(html, /<footer class="brewvanti-footer"/);
    assert.match(html, /102 Artisan Ave, Manila/);
    assert.match(html, /\+63 \(2\) 8920-BREW/);
});

test('js/application-tracking.js respects existing page status tracker and styles fallback gracefully', () => {
    const js = fs.readFileSync(path.join(__dirname, '..', 'js', 'application-tracking.js'), 'utf8');

    assert.match(js, /document\.getElementById\('secure-application-tracking'\)/);
    assert.match(js, /if \(existing\) \{/);
    assert.match(js, /return;/);
    assert.match(js, /Playfair Display/);
    assert.match(js, /#D9BA85/);
});

test('php/profile.php renders polished Two-Factor Authentication (Email MFA) security card', () => {
    const profilePath = path.join(__dirname, '..', 'php', 'profile.php');
    const php = fs.readFileSync(profilePath, 'utf8');

    assert.match(php, /id="email-mfa"/);
    assert.match(php, /Two-Factor Authentication \(Email MFA\)/);
    assert.match(php, /Status: Active &amp; Protected/);
    assert.match(php, /Status: Disabled \(Off\)/);
    assert.match(php, /toggleMfaPassword/);
    assert.match(php, /name="current_password"/);
    assert.match(php, /value="mfa_start"/);
    assert.match(php, /value="mfa_verify"/);
    assert.match(php, /value="mfa_disable"/);
});

test('php/profile.php and profile_helpers.php optimize oversized avatar images and handle security faults gracefully', () => {
    const profilePath = path.join(__dirname, '..', 'php', 'profile.php');
    const helpersPath = path.join(__dirname, '..', 'includes', 'profile_helpers.php');
    const profilePhp = fs.readFileSync(profilePath, 'utf8');
    const helpersPhp = fs.readFileSync(helpersPath, 'utf8');

    // Profile page includes client-side canvas auto-downsampling and loading feedback
    assert.match(profilePhp, /async function optimizeImageForAvatar/);
    assert.match(profilePhp, /setFileInputFiles/);
    assert.match(profilePhp, /submitQuickAvatar/);
    assert.match(profilePhp, /previewSelectedAvatar/);
    assert.match(profilePhp, /id="avatar-quick-loading"/);
    assert.match(profilePhp, /handleProfileFormSubmit/);

    // Profile helpers catch SecurityFault during private storage moves and report clean error
    assert.match(helpersPhp, /catch\s*\(\s*SecurityFault\s*\$sf\s*\)/);
    assert.match(helpersPhp, /max 4096×4096 px/);
});
