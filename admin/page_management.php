<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Page Management';
$breadcrumb = ['Admin', 'Page Management'];
$activeNav  = 'page_management.php';
$db = getDB();
$msg = ''; $msgType = 'success';

// ── Auto-create tables if they don't exist ──────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS clinic_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    purpose_category VARCHAR(50) NOT NULL,
    badge VARCHAR(50),
    description TEXT,
    duration VARCHAR(30),
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS clinic_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-circle-question',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Seed default services if empty ─────────────────────────────────────────
$svcCount = (int)$db->query("SELECT COUNT(*) FROM clinic_services")->fetchColumn();
if ($svcCount === 0) {
    $defaults = [
        ['Comprehensive Eye Examination','consultation','Examination','Full eye health check, visual acuity test, and digital refraction test.','30–45 mins',1],
        ['Prescription & Visual Acuity Test','consultation','Examination','Precise sphere, cylinder & axis measurement for reading or distance glasses.','20–30 mins',2],
        ['Pediatric & Student Vision Screening','consultation','Specialized','Gentle eye exam designed for children, students, and early myopia detection.','25–35 mins',3],
        ['Senior Vision & Cataract Screening','consultation','Specialized','Assessment for presbyopia, cataracts, and age-related visual changes.','30–45 mins',4],
        ['Eyeglass Frame Selection & Styling','eyeglass_claim','Eyewear','Bridge sizing, facial ergonomics, and personalized frame styling assistance.','20–30 mins',5],
        ['Lens Upgrade (Blue Light / Transitions)','eyeglass_claim','Lenses','Anti-radiation computer lenses, photochromic transitions, or progressive lenses.','15–20 mins',6],
        ['Eyeglass Pick-up & Final Alignment','eyeglass_claim','Eyewear','Claim completed prescription glasses with custom temple & nosepad fitting.','15 mins',7],
        ['Frame Repair & Ultrasonic Cleaning','other','Care','Nosepad replacement, frame realignment, screw tightening, and deep ultrasonic bath.','15–20 mins',8],
        ['Contact Lens Fitting & Insertion Training','contact_lens_fitting','Contacts','Corneal measurement, comfort trial fitting, and contact lens handling training.','30–40 mins',9],
        ['Contact Lens Replenishment / Pick-up','contact_lens_fitting','Contacts','Claim monthly, bi-weekly, or daily disposable contact lens supplies.','10–15 mins',10],
        ['Post-Consultation Prescription Check','follow_up','Follow-up','Re-evaluating vision adaptation and visual comfort with newly acquired glasses.','15–20 mins',11],
        ['General Optical Inquiries & Consultation','other','General','Discuss specific vision concerns, eye symptoms, referrals, or clinic services.','15–20 mins',12],
    ];
    $ins = $db->prepare("INSERT INTO clinic_services (name,purpose_category,badge,description,duration,sort_order) VALUES (?,?,?,?,?,?)");
    foreach ($defaults as $d) $ins->execute($d);
}

// ── Seed default FAQs if empty ──────────────────────────────────────────────
$faqCount = (int)$db->query("SELECT COUNT(*) FROM clinic_faqs")->fetchColumn();
if ($faqCount === 0) {
    $defaultFaqs = [
        ['How often should I have a comprehensive eye examination?', 'Both adults and children are recommended to undergo a professional eye examination at least once every 12 months. Routine checkups ensure your optical prescription remains accurate and help detect subtle vision changes early. Patients who wear contact lenses, spend long hours on digital screens, or have pre-existing health conditions such as diabetes or hypertension may benefit from semi-annual checkups.', 'fa-eye', 1],
        ['How do I schedule an appointment through the patient portal?', 'Booking an appointment is seamless! Simply click the "Book an Appointment" button anywhere on this page. You can log in or register in seconds using your email address or Google Account. Once inside, select your preferred clinic date, convenient time slot, and reason for visit. You will receive immediate booking confirmation and appointment reminders.', 'fa-calendar-check', 2],
        ['What should I bring to my optical appointment?', 'To help our optometrists provide the most accurate assessment, please bring: your current eyeglasses or contact lens prescription details (if any), a valid photo ID for patient identification, a list of any current medications, eye drops, or chronic conditions (e.g., allergies, diabetes), and your sunglasses in case your eyes feel sensitive to bright light following ophthalmic screening.', 'fa-clipboard-list', 3],
        ['How long does it take to prepare my new prescription eyewear?', 'Standard single-vision prescription lenses and in-stock frames are typically crafted and ready for dispensing within 1 to 2 business days. Custom specialty orders including progressive multifocal lenses, ultra-thin high-index materials, blue-light blocking filters, and photochromic transition lenses typically require 3 to 5 business days for optical surfacing and quality inspection.', 'fa-glasses', 4],
        ['Do you offer warranties and aftercare on eyeglasses?', 'Yes! All authentic designer frames and premium prescription lens coatings purchased at Gueco Optical Clinic include manufacturer warranty coverage against verified factory defects. In addition, every patient receives Free Lifetime Maintenance including complimentary ultrasonic cleaning, screw tightening, nose pad replacements, and custom frame adjustments whenever you visit our clinic in Capas, Tarlac.', 'fa-shield-halved', 5],
        ['Is my personal and medical health information kept private?', 'Your health privacy is our utmost priority. All patient records, clinical charts, refraction results, and contact information are strictly protected under the Philippine Data Privacy Act of 2012 (RA 10173). We adhere to strict medical confidentiality. We never sell, rent, or distribute your personal details to outside advertisers or third parties.', 'fa-user-shield', 6],
    ];
    $faqIns = $db->prepare("INSERT INTO clinic_faqs (question,answer,icon,sort_order) VALUES (?,?,?,?)");
    foreach ($defaultFaqs as $f) $faqIns->execute($f);
}

// ── Seed default site_settings if empty ─────────────────────────────────────
$settCount = (int)$db->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
if ($settCount === 0) {
    $settDefaults = [
        ['hero_badge',        'Established in 1986'],
        ['hero_headline',     'See the World'],
        ['hero_highlight',    'Clearly & Beautifully'],
        ['hero_description',  'Providing exceptional, comprehensive eye care services to the Capas community. We combine state-of-the-art technology with compassionate care to help you achieve your best vision.'],
        ['stat1_value',       '40+'],
        ['stat1_label',       'Years of Service'],
        ['stat2_value',       '10k+'],
        ['stat2_label',       'Happy Patients'],
        ['stat3_value',       '100%'],
        ['stat3_label',       'Commitment'],
        ['card1_icon',        'fa-user-md'],
        ['card1_title',       'Expert Optometrists'],
        ['card1_desc',        'Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.'],
        ['card2_icon',        'fa-glasses'],
        ['card2_title',       'Premium Eyewear'],
        ['card2_desc',        'Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.'],
        ['card3_icon',        'fa-map-marker-alt'],
        ['card3_title',       'Convenient Location'],
        ['card3_desc',        'Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.'],
    ];
    $sIns = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)");
    foreach ($settDefaults as $s) $sIns->execute($s);
}

// ── Auto-migrate legacy <span> from hero_headline if present ────────────────
try {
    $existingHeadline = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'hero_headline'")->fetchColumn();
    if ($existingHeadline && strpos($existingHeadline, '<span') !== false) {
        $db->prepare("UPDATE site_settings SET setting_value = 'See the World' WHERE setting_key = 'hero_headline'")->execute();
        $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('hero_highlight', 'Clearly & Beautifully') ON DUPLICATE KEY UPDATE setting_value = 'Clearly & Beautifully'")->execute();
    }
} catch (Throwable $e) {}

// ── Available Clinic Icons for Quick Picker ────────────────────────────────
$availableIcons = [
    'fa-user-md'              => ['Doctor / Optometrist', 'Professional eye care specialist'],
    'fa-glasses'              => ['Eyewear & Frames', 'Designer frames and lenses'],
    'fa-eye'                  => ['Eye Examination', 'Vision testing and checkup'],
    'fa-map-marker-alt'       => ['Clinic Location', 'Capas, Tarlac clinic branch'],
    'fa-stethoscope'          => ['Medical Care', 'Ophthalmic consultations'],
    'fa-award'                => ['Certified Quality', 'Licensed practice excellence'],
    'fa-shield-halved'        => ['Warranty & Protection', 'Lifetime maintenance warranty'],
    'fa-clock'                => ['Fast Service', 'Quick dispensing & turnaround'],
    'fa-heart'                => ['Patient Care', 'Gentle, compassionate service'],
    'fa-microscope'           => ['Modern Equipment', 'High-precision digital tools'],
    'fa-calendar-check'       => ['Appointment Booking', 'Flexible scheduling'],
    'fa-clipboard-list'       => ['Prescription Records', 'Accurate optical measurements'],
    'fa-hospital'             => ['Clinic Facility', 'Clean, comfortable clinic'],
    'fa-headset'              => ['Patient Support', 'Inquiries and assistance'],
    'fa-thumbs-up'            => ['Trusted Service', 'Over 40 years community trust'],
    'fa-hand-holding-medical' => ['Care & Comfort', 'Dedicated vision treatment'],
    'fa-gem'                  => ['Premium Eyewear', 'Luxury and designer brands'],
    'fa-circle-question'      => ['Help & FAQs', 'General inquiries & support'],
    'fa-user-shield'          => ['Privacy & Records', 'Secure patient health privacy'],
    'fa-sparkles'             => ['Specialty Lenses', 'Blue light & transition lenses']
];

// ── POST Handler ─────────────────────────────────────────────────────────────
$reopenData = null;
$activeTab  = $_POST['active_tab'] ?? $_GET['tab'] ?? 'homepage';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $activeTab = $_POST['active_tab'] ?? 'homepage';

    // ── HOMEPAGE SETTINGS ──
    if ($action === 'save_homepage') {
        $keys = ['hero_badge','hero_headline','hero_highlight','hero_description',
                 'stat1_value','stat1_label','stat2_value','stat2_label','stat3_value','stat3_label',
                 'card1_icon','card1_title','card1_desc',
                 'card2_icon','card2_title','card2_desc',
                 'card3_icon','card3_title','card3_desc'];
        $upsert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $upsert->execute([$k, $val]);
        }
        $msg = 'Homepage content updated successfully! Changes are now live on the patient website.';
        $msgType = 'success';
        logActivity('Updated homepage content via Page Management', 'Page Management', $_SESSION['user_id'], 'staff');
    }

    // ── SERVICES: ADD ──
    elseif ($action === 'add_service') {
        $name    = sanitize(trim($_POST['svc_name'] ?? ''));
        $cat     = sanitize(trim($_POST['svc_category'] ?? ''));
        $badge   = sanitize(trim($_POST['svc_badge'] ?? ''));
        $desc    = sanitize(trim($_POST['svc_desc'] ?? ''));
        $dur     = sanitize(trim($_POST['svc_duration'] ?? ''));
        if ($name && $cat) {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM clinic_services")->fetchColumn();
            $db->prepare("INSERT INTO clinic_services (name,purpose_category,badge,description,duration,sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$name,$cat,$badge,$desc,$dur,$maxOrder+1]);
            $msg = "Service \"$name\" added successfully.";
            $msgType = 'success';
            logActivity("Added service \"$name\"", 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Service name and category are required.'; $msgType = 'danger'; }
    }

    // ── SERVICES: EDIT ──
    elseif ($action === 'edit_service') {
        $id   = (int)($_POST['svc_id'] ?? 0);
        $name = sanitize(trim($_POST['svc_name'] ?? ''));
        $cat  = sanitize(trim($_POST['svc_category'] ?? ''));
        $badge= sanitize(trim($_POST['svc_badge'] ?? ''));
        $desc = sanitize(trim($_POST['svc_desc'] ?? ''));
        $dur  = sanitize(trim($_POST['svc_duration'] ?? ''));
        $stat = (int)($_POST['svc_active'] ?? 1);
        if ($name && $cat && $id) {
            $db->prepare("UPDATE clinic_services SET name=?,purpose_category=?,badge=?,description=?,duration=?,is_active=? WHERE id=?")
               ->execute([$name,$cat,$badge,$desc,$dur,$stat,$id]);
            $msg = "Service \"$name\" updated successfully.";
            $msgType = 'success';
            logActivity("Updated service #$id \"$name\"", 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Service name and category are required.'; $msgType = 'danger'; }
    }

    // ── SERVICES: TOGGLE ──
    elseif ($action === 'toggle_service') {
        $id  = (int)($_POST['svc_id'] ?? 0);
        $cur = (int)($_POST['svc_current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE clinic_services SET is_active=? WHERE id=?")->execute([$new,$id]);
        $msg = 'Service ' . ($new ? 'activated and visible to patients' : 'hidden from patient booking') . ' successfully.';
        $msgType = 'success';
        logActivity(($new ? 'Activated' : 'Deactivated') . " service #$id", 'Page Management', $_SESSION['user_id'], 'staff');
    }

    // ── FAQS: ADD ──
    elseif ($action === 'add_faq') {
        $q    = sanitize(trim($_POST['faq_question'] ?? ''));
        $a    = sanitize(trim($_POST['faq_answer'] ?? ''));
        $icon = sanitize(trim($_POST['faq_icon'] ?? 'fa-circle-question'));
        if ($q && $a) {
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM clinic_faqs")->fetchColumn();
            $db->prepare("INSERT INTO clinic_faqs (question,answer,icon,sort_order) VALUES (?,?,?,?)")
               ->execute([$q,$a,$icon,$maxOrder+1]);
            $msg = 'FAQ added successfully.';
            $msgType = 'success';
            logActivity('Added new FAQ', 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Question and answer are required.'; $msgType = 'danger'; }
    }

    // ── FAQS: EDIT ──
    elseif ($action === 'edit_faq') {
        $id   = (int)($_POST['faq_id'] ?? 0);
        $q    = sanitize(trim($_POST['faq_question'] ?? ''));
        $a    = sanitize(trim($_POST['faq_answer'] ?? ''));
        $icon = sanitize(trim($_POST['faq_icon'] ?? 'fa-circle-question'));
        $stat = (int)($_POST['faq_active'] ?? 1);
        if ($q && $a && $id) {
            $db->prepare("UPDATE clinic_faqs SET question=?,answer=?,icon=?,is_active=? WHERE id=?")
               ->execute([$q,$a,$icon,$stat,$id]);
            $msg = 'FAQ updated successfully.';
            $msgType = 'success';
            logActivity("Updated FAQ #$id", 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Question and answer are required.'; $msgType = 'danger'; }
    }

    // ── FAQS: TOGGLE ──
    elseif ($action === 'toggle_faq') {
        $id  = (int)($_POST['faq_id'] ?? 0);
        $cur = (int)($_POST['faq_current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE clinic_faqs SET is_active=? WHERE id=?")->execute([$new,$id]);
        $msg = 'FAQ ' . ($new ? 'published to website' : 'hidden from website') . ' successfully.';
        $msgType = 'success';
        logActivity(($new ? 'Published' : 'Hidden') . " FAQ #$id", 'Page Management', $_SESSION['user_id'], 'staff');
    }
}

// ── Load Data ────────────────────────────────────────────────────────────────
$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
function gs(array $s, string $k, string $d = ''): string {
    return htmlspecialchars($s[$k] ?? $d, ENT_QUOTES);
}

$services = $db->query("SELECT * FROM clinic_services ORDER BY sort_order ASC, id ASC")->fetchAll();
$faqs     = $db->query("SELECT * FROM clinic_faqs ORDER BY sort_order ASC, id ASC")->fetchAll();

$totalSvc  = count($services);
$activeSvc = count(array_filter($services, fn($s) => $s['is_active']));
$totalFaq  = count($faqs);
$activeFaq = count(array_filter($faqs, fn($f) => $f['is_active']));

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/page_management.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        text: '<?= addslashes($msg) ?>',
        icon: '<?= $msgType ?>',
        confirmButtonColor: 'var(--clr-primary)',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        timer: 3500,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     TOP BANNER & HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-studio-header">
  <div class="pm-studio-left">
    <div class="pm-studio-emblem">
      <i class="fas fa-palette"></i>
    </div>
    <div>
      <div class="pm-studio-kicker"><i class="fas fa-circle text-success me-1" style="font-size:0.55rem;"></i> Live Page Customizer</div>
      <h3 class="pm-studio-title">Website Content Studio</h3>
      <p class="pm-studio-subtitle">Easily manage what your patients see on the landing page and booking system — no coding required!</p>
    </div>
  </div>
  <div class="pm-studio-right">
    <a href="<?= BASE_URL ?>index.php" target="_blank" class="pm-live-btn" title="Open patient website in a new tab">
      <i class="fas fa-external-link-alt"></i> Preview Live Website
    </a>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB NAVIGATION
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-nav-wrapper">
  <div class="pm-tab-pills">
    <button type="button" class="pm-tab-pill <?= $activeTab === 'homepage' ? 'active' : '' ?>" onclick="switchTab('homepage')">
      <div class="pm-tab-icon"><i class="fas fa-home"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">Landing Page</span>
        <span class="pm-tab-sub">Hero, stats &amp; highlights</span>
      </div>
    </button>
    <button type="button" class="pm-tab-pill <?= $activeTab === 'services' ? 'active' : '' ?>" onclick="switchTab('services')">
      <div class="pm-tab-icon"><i class="fas fa-stethoscope"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">Appointment Services</span>
        <span class="pm-tab-sub">Patient booking options</span>
      </div>
      <span class="pm-tab-counter"><?= $activeSvc ?>/<?= $totalSvc ?></span>
    </button>
    <button type="button" class="pm-tab-pill <?= $activeTab === 'faqs' ? 'active' : '' ?>" onclick="switchTab('faqs')">
      <div class="pm-tab-icon"><i class="fas fa-circle-question"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">FAQ Center</span>
        <span class="pm-tab-sub">Common patient inquiries</span>
      </div>
      <span class="pm-tab-counter"><?= $activeFaq ?>/<?= $totalFaq ?></span>
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 1: HOMEPAGE CONTENT (VISUAL STUDIO)
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'homepage' ? 'active' : '' ?>" id="tab-homepage">
  <form method="POST" id="homepageForm">
    <input type="hidden" name="action" value="save_homepage">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="active_tab" value="homepage">

    <!-- Section 1: Hero Banner Studio -->
    <div class="pm-card-box">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-flag"></i> SECTION 1</div>
        <h4 class="pm-card-box-title">Hero Banner Studio</h4>
        <p class="pm-card-box-desc">This is the main headline and introduction displayed at the very top of your landing page.</p>
      </div>

      <div class="pm-hero-studio-grid">
        <!-- Left: Form Controls -->
        <div class="pm-hero-controls">
          <div class="pm-field-block">
            <label class="pm-input-label">
              <i class="fas fa-certificate text-warning"></i>
              <span>Top Badge Text</span>
            </label>
            <input type="text" name="hero_badge" id="heroBadgeInput" class="form-control pm-styled-input" 
                   value="<?= gs($settings,'hero_badge','Established in 1986') ?>" 
                   placeholder="e.g. Established in 1986" oninput="updateHeroLivePreview()">
            <span class="pm-input-hint">The golden badge shown above your main headline.</span>
          </div>

          <div class="pm-field-row-2">
            <div class="pm-field-block">
              <label class="pm-input-label">
                <i class="fas fa-font text-primary"></i>
                <span>Headline (First Part)</span>
              </label>
              <input type="text" name="hero_headline" id="heroHeadlineInput" class="form-control pm-styled-input" 
                     value="<?= gs($settings,'hero_headline','See the World') ?>" 
                     placeholder="e.g. See the World" oninput="updateHeroLivePreview()">
              <span class="pm-input-hint">Standard primary title text.</span>
            </div>

            <div class="pm-field-block">
              <label class="pm-input-label">
                <i class="fas fa-wand-magic-sparkles text-info"></i>
                <span>Accent Highlight Words</span>
              </label>
              <input type="text" name="hero_highlight" id="heroHighlightInput" class="form-control pm-styled-input" 
                     value="<?= gs($settings,'hero_highlight','Clearly & Beautifully') ?>" 
                     placeholder="e.g. Clearly & Beautifully" oninput="updateHeroLivePreview()">
              <span class="pm-input-hint">These words shine with luxury blue gradient.</span>
            </div>
          </div>

          <div class="pm-field-block">
            <label class="pm-input-label">
              <i class="fas fa-align-left text-muted"></i>
              <span>Clinic Introduction Paragraph</span>
            </label>
            <textarea name="hero_description" id="heroDescInput" class="form-control pm-styled-textarea" rows="3" 
                      placeholder="Write a warm introduction for your clinic..." oninput="updateHeroLivePreview()"><?= gs($settings,'hero_description') ?></textarea>
            <span class="pm-input-hint">Brief clinic mission or welcome message.</span>
          </div>
        </div>

        <!-- Right: Interactive Live Mockup -->
        <div class="pm-hero-mockup-wrapper">
          <div class="pm-mockup-banner-top">
            <span class="pm-mockup-dot red"></span>
            <span class="pm-mockup-dot yellow"></span>
            <span class="pm-mockup-dot green"></span>
            <span class="pm-mockup-url"><i class="fas fa-lock"></i> guecoopticalclinic.com</span>
          </div>
          <div class="pm-hero-mockup-inner">
            <div class="pm-mockup-badge" id="prevHeroBadge">
              <i class="fas fa-certificate text-warning me-1"></i>
              <span><?= gs($settings,'hero_badge','Established in 1986') ?></span>
            </div>
            <h1 class="pm-mockup-h1">
              <span id="prevHeroHeadline"><?= gs($settings,'hero_headline','See the World') ?></span>
              <span class="pm-mockup-grad" id="prevHeroHighlight"><?= gs($settings,'hero_highlight','Clearly & Beautifully') ?></span>
            </h1>
            <p class="pm-mockup-desc" id="prevHeroDesc"><?= gs($settings,'hero_description') ?></p>
            <div class="pm-mockup-cta">
              <span class="pm-mockup-btn-primary"><i class="fas fa-calendar-check"></i> Book an Appointment</span>
              <span class="pm-mockup-btn-outline">Explore Services</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Milestones & Counters -->
    <div class="pm-card-box mt-4">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-chart-line"></i> SECTION 2</div>
        <h4 class="pm-card-box-title">Clinic Milestones &amp; Statistics</h4>
        <p class="pm-card-box-desc">The 3 quick proof counters displayed directly below the hero section.</p>
      </div>

      <div class="pm-stats-builder-grid">
        <?php 
        $statDefaults = [
            ['stat1', '40+',  'Years of Service', 'fa-award',       '#235EAE'],
            ['stat2', '10k+', 'Happy Patients',   'fa-smile-beam',  '#00ADEF'],
            ['stat3', '100%', 'Commitment',       'fa-hand-holding-heart', '#10B981']
        ];
        foreach ($statDefaults as [$k, $dv, $dl, $icon, $accent]): 
        ?>
        <div class="pm-stat-builder-card">
          <div class="pm-stat-icon-top" style="color:<?= $accent ?>;">
            <i class="fas <?= $icon ?>"></i>
          </div>
          <div class="pm-stat-inputs">
            <div class="pm-stat-input-group">
              <label class="pm-stat-label">Displayed Number</label>
              <input type="text" name="<?= $k ?>_value" class="form-control pm-stat-number-input" 
                     value="<?= gs($settings,"{$k}_value",$dv) ?>" placeholder="e.g. 40+">
            </div>
            <div class="pm-stat-input-group">
              <label class="pm-stat-label">Stat Label</label>
              <input type="text" name="<?= $k ?>_label" class="form-control pm-stat-text-input" 
                     value="<?= gs($settings,"{$k}_label",$dl) ?>" placeholder="e.g. Years of Service">
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Section 3: Clinic Highlights (3 Feature Cards) -->
    <div class="pm-card-box mt-4">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-layer-group"></i> SECTION 3</div>
        <h4 class="pm-card-box-title">Clinic Feature Highlights (3 Cards)</h4>
        <p class="pm-card-box-desc">The 3 luxury cards that describe your clinic's primary strengths. Click the icon to choose a different graphic!</p>
      </div>

      <div class="pm-feature-cards-grid">
        <?php
        $cardConfigs = [
          ['card1', 'fa-user-md',        'Expert Optometrists', 'Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.', 'Bronze Accent', 'pm-accent-bronze'],
          ['card2', 'fa-glasses',        'Premium Eyewear',     'Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.', 'Gold Accent',   'pm-accent-gold'],
          ['card3', 'fa-map-marker-alt', 'Convenient Location', 'Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.',     'Emerald Accent','pm-accent-emerald']
        ];

        foreach ($cardConfigs as [$k, $di, $dt, $dd, $accentName, $accentClass]):
          $savedIcon = gs($settings,"{$k}_icon",$di);
        ?>
        <div class="pm-feature-builder-card <?= $accentClass ?>">
          <!-- Hidden Icon Input -->
          <input type="hidden" name="<?= $k ?>_icon" id="<?= $k ?>_icon_input" value="<?= $savedIcon ?>">

          <!-- Visual Icon Button -->
          <div class="pm-feature-top-bar">
            <button type="button" class="pm-feature-icon-btn" id="<?= $k ?>_icon_box" 
                    onclick="openIconPicker('<?= $k ?>')" title="Click to choose a different icon">
              <i class="fas <?= $savedIcon ?>"></i>
            </button>
            <div class="pm-feature-badge-wrap">
              <span class="pm-badge-accent"><?= $accentName ?></span>
              <button type="button" class="pm-change-icon-chip" onclick="openIconPicker('<?= $k ?>')">
                <i class="fas fa-icons"></i> Change Icon
              </button>
            </div>
          </div>

          <div class="pm-feature-form-body">
            <div class="pm-field-block">
              <label class="pm-input-label">Card Title</label>
              <input type="text" name="<?= $k ?>_title" class="form-control pm-styled-input fw-bold" 
                     value="<?= gs($settings,"{$k}_title",$dt) ?>" placeholder="e.g. Expert Optometrists">
            </div>

            <div class="pm-field-block">
              <label class="pm-input-label">Card Description</label>
              <textarea name="<?= $k ?>_desc" class="form-control pm-styled-textarea" rows="3" 
                        placeholder="Explain this clinic benefit..."><?= gs($settings,"{$k}_desc",$dd) ?></textarea>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Floating / Sticky Save Toolbar -->
    <div class="pm-sticky-save-bar">
      <div class="pm-save-bar-left">
        <i class="fas fa-check-circle text-success"></i>
        <span>Ready to update? Changes will take effect immediately on your live website.</span>
      </div>
      <button type="submit" class="pm-save-action-btn">
        <i class="fas fa-save"></i> Save All Homepage Changes
      </button>
    </div>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 2: APPOINTMENT SERVICES (BOOKING WIZARD)
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'services' ? 'active' : '' ?>" id="tab-services">
  <!-- Info Banner -->
  <div class="pm-info-callout">
    <div class="pm-callout-icon"><i class="fas fa-info-circle"></i></div>
    <div class="pm-callout-content">
      <h6>Patient Booking Services Hub</h6>
      <p>These services appear in <strong>Step 2</strong> of the patient appointment booking wizard. You can add new clinic procedures, edit descriptions, adjust estimated durations, or toggle services active/hidden with a single click.</p>
    </div>
  </div>

  <!-- Filter & Action Toolbar -->
  <div class="pm-hub-toolbar">
    <div class="pm-hub-search">
      <i class="fas fa-search"></i>
      <input type="text" id="svcSearch" placeholder="Search services by name, badge, or category..." autocomplete="off">
    </div>

    <div class="pm-category-pills">
      <button type="button" class="pm-cat-filter active" data-filter="all">All (<?= $totalSvc ?>)</button>
      <button type="button" class="pm-cat-filter" data-filter="consultation">Consultations</button>
      <button type="button" class="pm-cat-filter" data-filter="eyeglass_claim">Eyewear &amp; Lenses</button>
      <button type="button" class="pm-cat-filter" data-filter="contact_lens_fitting">Contacts</button>
      <button type="button" class="pm-cat-filter" data-filter="follow_up">Follow-ups</button>
      <button type="button" class="pm-cat-filter" data-filter="other">General</button>
    </div>

    <button type="button" class="pm-add-btn" onclick="openModal('addSvcModal')">
      <i class="fas fa-plus"></i> Add New Service
    </button>
  </div>

  <!-- Services Grid -->
  <div class="pm-services-hub-grid" id="svcGrid">
    <?php if (empty($services)): ?>
    <div class="pm-empty-card">
      <i class="fas fa-stethoscope"></i>
      <h5>No Services Configured</h5>
      <p>Click the "Add New Service" button above to create your first appointment service.</p>
    </div>
    <?php else: foreach ($services as $svc): 
      $catKey = $svc['purpose_category'];
    ?>
    <div class="pm-hub-svc-card <?= $svc['is_active'] ? '' : 'pm-is-hidden' ?>"
         data-name="<?= strtolower(htmlspecialchars($svc['name'])) ?>"
         data-badge="<?= strtolower(htmlspecialchars($svc['badge'] ?? '')) ?>"
         data-cat="<?= $catKey ?>">
      
      <div class="pm-svc-topline">
        <span class="pm-badge-category pm-cat-<?= $catKey ?>">
          <?= htmlspecialchars($svc['badge'] ?: ucfirst(str_replace('_',' ',$catKey))) ?>
        </span>

        <!-- Clear Status Indicator -->
        <span class="pm-status-pill <?= $svc['is_active'] ? 'active' : 'hidden' ?>">
          <i class="fas fa-<?= $svc['is_active'] ? 'check-circle' : 'eye-slash' ?>"></i>
          <?= $svc['is_active'] ? 'Visible to Patients' : 'Hidden from Booking' ?>
        </span>
      </div>

      <h5 class="pm-svc-card-title"><?= htmlspecialchars($svc['name']) ?></h5>
      <p class="pm-svc-card-desc"><?= htmlspecialchars($svc['description'] ?? 'No description provided.') ?></p>

      <div class="pm-svc-card-meta">
        <span class="pm-duration-chip"><i class="fas fa-clock"></i> <?= htmlspecialchars($svc['duration'] ?: '15–30 mins') ?></span>
        <span class="pm-category-label"><?= ucfirst(str_replace('_',' ',$catKey)) ?></span>
      </div>

      <div class="pm-svc-card-actions">
        <!-- Edit Button -->
        <button type="button" class="pm-action-btn edit" title="Edit Service Details"
          onclick="openEditSvc(<?= $svc['id'] ?>, '<?= addslashes($svc['name']) ?>', '<?= $svc['purpose_category'] ?>', '<?= addslashes($svc['badge'] ?? '') ?>', '<?= addslashes($svc['description'] ?? '') ?>', '<?= addslashes($svc['duration'] ?? '') ?>', <?= $svc['is_active'] ?>)">
          <i class="fas fa-edit"></i> Edit Details
        </button>

        <!-- Toggle Visibility Button -->
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="action" value="toggle_service">
          <input type="hidden" name="active_tab" value="services">
          <input type="hidden" name="svc_id" value="<?= $svc['id'] ?>">
          <input type="hidden" name="svc_current" value="<?= $svc['is_active'] ?>">
          <button type="submit" class="pm-action-btn <?= $svc['is_active'] ? 'toggle-hide' : 'toggle-show' ?>"
            data-confirm="<?= $svc['is_active'] ? 'Hide this service from patients during appointment booking?' : 'Make this service visible to patients during appointment booking?' ?>">
            <i class="fas fa-<?= $svc['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
            <?= $svc['is_active'] ? 'Hide Service' : 'Show Service' ?>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 3: FAQ KNOWLEDGEBASE
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'faqs' ? 'active' : '' ?>" id="tab-faqs">
  <!-- Info Banner -->
  <div class="pm-info-callout">
    <div class="pm-callout-icon"><i class="fas fa-circle-question"></i></div>
    <div class="pm-callout-content">
      <h6>Landing Page FAQs Manager</h6>
      <p>These questions and answers appear in the accordion section at the bottom of your landing page. You can add new common patient inquiries, edit solutions, and hide questions at any time.</p>
    </div>
  </div>

  <!-- Filter & Action Toolbar -->
  <div class="pm-hub-toolbar">
    <div class="pm-hub-search">
      <i class="fas fa-search"></i>
      <input type="text" id="faqSearch" placeholder="Search FAQs by question text..." autocomplete="off">
    </div>

    <button type="button" class="pm-add-btn" onclick="openModal('addFaqModal')">
      <i class="fas fa-plus"></i> Add New Question
    </button>
  </div>

  <!-- FAQ Accordion List -->
  <div class="pm-faq-accordion-list" id="faqList">
    <?php if (empty($faqs)): ?>
    <div class="pm-empty-card">
      <i class="fas fa-circle-question"></i>
      <h5>No Questions Configured</h5>
      <p>Click the "Add New Question" button to create your first frequently asked question.</p>
    </div>
    <?php else: foreach ($faqs as $i => $faq): ?>
    <div class="pm-faq-accordion-card <?= $faq['is_active'] ? '' : 'pm-is-hidden' ?>" data-q="<?= strtolower(htmlspecialchars($faq['question'])) ?>">
      <div class="pm-faq-card-head" onclick="toggleFaqAccordion(this)">
        <div class="pm-faq-head-left">
          <div class="pm-faq-number-badge"><?= $i+1 ?></div>
          <div class="pm-faq-icon-avatar"><i class="fas <?= htmlspecialchars($faq['icon']) ?>"></i></div>
          <div class="pm-faq-question-title"><?= htmlspecialchars($faq['question']) ?></div>
        </div>
        <div class="pm-faq-head-right">
          <span class="pm-status-pill <?= $faq['is_active'] ? 'active' : 'hidden' ?>">
            <?= $faq['is_active'] ? 'Published' : 'Hidden' ?>
          </span>
          <div class="pm-faq-chevron"><i class="fas fa-chevron-down"></i></div>
        </div>
      </div>

      <div class="pm-faq-card-body">
        <div class="pm-faq-answer-text">
          <?= nl2br(htmlspecialchars($faq['answer'])) ?>
        </div>
        <div class="pm-faq-card-actions">
          <button type="button" class="pm-action-btn edit" title="Edit Question & Answer"
            onclick="openEditFaq(<?= $faq['id'] ?>, '<?= addslashes($faq['question']) ?>', '<?= addslashes($faq['answer']) ?>', '<?= addslashes($faq['icon']) ?>', <?= $faq['is_active'] ?>)">
            <i class="fas fa-edit"></i> Edit FAQ
          </button>
          <form method="POST" style="margin:0;display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_faq">
            <input type="hidden" name="active_tab" value="faqs">
            <input type="hidden" name="faq_id" value="<?= $faq['id'] ?>">
            <input type="hidden" name="faq_current" value="<?= $faq['is_active'] ?>">
            <button type="submit" class="pm-action-btn <?= $faq['is_active'] ? 'toggle-hide' : 'toggle-show' ?>"
              data-confirm="<?= $faq['is_active'] ? 'Hide this FAQ from the website?' : 'Publish this FAQ on the website?' ?>">
              <i class="fas fa-<?= $faq['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
              <?= $faq['is_active'] ? 'Hide from Patients' : 'Publish Question' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: VISUAL ICON PICKER (GRID)
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="iconPickerModal">
  <div class="modal-box pm-icon-picker-box">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-icons"></i></div>
        <div class="modal-header-titles">
          <h5>Choose an Icon</h5>
          <small>Click any icon below to apply it immediately</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('iconPickerModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body pm-icon-picker-body">
      <div class="pm-icon-grid">
        <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
        <button type="button" class="pm-icon-tile" onclick="selectIcon('<?= $iCls ?>')">
          <div class="pm-icon-tile-sym"><i class="fas <?= $iCls ?>"></i></div>
          <div class="pm-icon-tile-name"><?= $iTitle ?></div>
          <div class="pm-icon-tile-tag"><?= $iCls ?></div>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('iconPickerModal')">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: ADD SERVICE
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addSvcModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-stethoscope"></i></div>
        <div class="modal-header-titles">
          <h5>Add Clinic Service</h5>
          <small>Add an optical service for patient appointment booking</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Name <span class="text-danger">*</span></label>
          <input type="text" name="svc_name" class="form-control" placeholder="e.g. Comprehensive Eye Examination" required autofocus>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Booking Category <span class="text-danger">*</span></label>
            <select name="svc_category" class="form-select" required>
              <option value="">Select category...</option>
              <option value="consultation">Consultation / Check-up</option>
              <option value="eyeglass_claim">Eyewear &amp; Lenses</option>
              <option value="contact_lens_fitting">Contact Lenses</option>
              <option value="follow_up">Follow-up Consultation</option>
              <option value="other">Care &amp; Repairs</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Card Badge</label>
            <input type="text" name="svc_badge" class="form-control" placeholder="e.g. Examination, Lenses, Care">
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Description</label>
          <textarea name="svc_desc" class="form-control" rows="3" placeholder="Briefly describe what this service includes..."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Estimated Appointment Duration</label>
          <input type="text" name="svc_duration" class="form-control" placeholder="e.g. 20–30 mins">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save &amp; Add Service</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: EDIT SERVICE
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editSvcModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles">
          <h5>Edit Clinic Service</h5>
          <small>Modify service information and booking settings</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_id" id="editSvcId">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Name <span class="text-danger">*</span></label>
          <input type="text" name="svc_name" id="editSvcName" class="form-control" required>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Booking Category <span class="text-danger">*</span></label>
            <select name="svc_category" id="editSvcCat" class="form-select" required>
              <option value="consultation">Consultation / Check-up</option>
              <option value="eyeglass_claim">Eyewear &amp; Lenses</option>
              <option value="contact_lens_fitting">Contact Lenses</option>
              <option value="follow_up">Follow-up Consultation</option>
              <option value="other">Care &amp; Repairs</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Card Badge</label>
            <input type="text" name="svc_badge" id="editSvcBadge" class="form-control">
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Description</label>
          <textarea name="svc_desc" id="editSvcDesc" class="form-control" rows="3"></textarea>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Estimated Duration</label>
            <input type="text" name="svc_duration" id="editSvcDur" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Patient Visibility</label>
            <select name="svc_active" id="editSvcActive" class="form-select">
              <option value="1">Visible to Patients</option>
              <option value="0">Hidden from Booking</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: ADD FAQ
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-circle-question"></i></div>
        <div class="modal-header-titles">
          <h5>Add Frequently Asked Question</h5>
          <small>Create a new helpful answer for patients</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Icon Category</label>
          <select name="faq_icon" class="form-select">
            <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
            <option value="<?= $iCls ?>" <?= $iCls === 'fa-circle-question' ? 'selected' : '' ?>>
              <?= $iTitle ?> (<?= $iCls ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Question Text <span class="text-danger">*</span></label>
          <input type="text" name="faq_question" class="form-control" placeholder="e.g. How often should I have an eye examination?" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Detailed Answer <span class="text-danger">*</span></label>
          <textarea name="faq_answer" class="form-control" rows="5" placeholder="Write a clear, helpful answer for your patients..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Publish Question</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: EDIT FAQ
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles">
          <h5>Edit Question &amp; Answer</h5>
          <small>Modify question text, answer, or visibility</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">
        <input type="hidden" name="faq_id" id="editFaqId">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Icon Category</label>
          <select name="faq_icon" id="editFaqIcon" class="form-select">
            <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
            <option value="<?= $iCls ?>">
              <?= $iTitle ?> (<?= $iCls ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Question Text <span class="text-danger">*</span></label>
          <input type="text" name="faq_question" id="editFaqQ" class="form-control" required>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Detailed Answer <span class="text-danger">*</span></label>
          <textarea name="faq_answer" id="editFaqA" class="form-control" rows="5" required></textarea>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Visibility on Landing Page</label>
          <select name="faq_active" id="editFaqActive" class="form-select">
            <option value="1">Published (Visible on landing page)</option>
            <option value="0">Hidden (Not shown to patients)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE JAVASCRIPT
     ═══════════════════════════════════════════════════════════════ -->
<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.pm-tab-pill').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pm-tab-panel').forEach(p => p.classList.remove('active'));
    const btn = document.querySelector(`.pm-tab-pill[onclick="switchTab('${tab}')"]`);
    if (btn) btn.classList.add('active');
    const pnl = document.getElementById('tab-' + tab);
    if (pnl) pnl.classList.add('active');
}

// ── Hero live preview ────────────────────────────────────────────────────────
function updateHeroLivePreview() {
    const badge     = document.getElementById('heroBadgeInput') ? document.getElementById('heroBadgeInput').value : '';
    const headline  = document.getElementById('heroHeadlineInput') ? document.getElementById('heroHeadlineInput').value : '';
    const highlight = document.getElementById('heroHighlightInput') ? document.getElementById('heroHighlightInput').value : '';
    const desc      = document.getElementById('heroDescInput') ? document.getElementById('heroDescInput').value : '';

    const prevBadge = document.getElementById('prevHeroBadge');
    if (prevBadge) {
        const badgeSpan = prevBadge.querySelector('span');
        if (badgeSpan) badgeSpan.textContent = badge;
    }

    const prevHl = document.getElementById('prevHeroHeadline');
    if (prevHl) prevHl.textContent = headline + (headline && highlight ? ' ' : '');

    const prevHg = document.getElementById('prevHeroHighlight');
    if (prevHg) prevHg.textContent = highlight;

    const prevDesc = document.getElementById('prevHeroDesc');
    if (prevDesc) prevDesc.textContent = desc;
}

// ── Visual Icon Picker System ────────────────────────────────────────────────
let activeIconTargetKey = null;
function openIconPicker(cardKey) {
    activeIconTargetKey = cardKey;
    openModal('iconPickerModal');
}

function selectIcon(iconClass) {
    if (activeIconTargetKey) {
        const input = document.getElementById(activeIconTargetKey + '_icon_input');
        if (input) input.value = iconClass;
        const box = document.getElementById(activeIconTargetKey + '_icon_box');
        if (box) box.innerHTML = '<i class="fas ' + iconClass + '"></i>';
    }
    closeModal('iconPickerModal');
}

// ── Service edit modal ───────────────────────────────────────────────────────
function openEditSvc(id, name, cat, badge, desc, dur, active) {
    document.getElementById('editSvcId').value     = id;
    document.getElementById('editSvcName').value   = name;
    document.getElementById('editSvcCat').value    = cat;
    document.getElementById('editSvcBadge').value  = badge;
    document.getElementById('editSvcDesc').value   = desc;
    document.getElementById('editSvcDur').value    = dur;
    document.getElementById('editSvcActive').value = active ? '1' : '0';
    openModal('editSvcModal');
}

// ── FAQ edit modal ───────────────────────────────────────────────────────────
function openEditFaq(id, q, a, icon, active) {
    document.getElementById('editFaqId').value     = id;
    document.getElementById('editFaqQ').value      = q;
    document.getElementById('editFaqA').value      = a;
    const sel = document.getElementById('editFaqIcon');
    if (sel) sel.value = icon;
    document.getElementById('editFaqActive').value = active ? '1' : '0';
    openModal('editFaqModal');
}

// ── FAQ Accordion Toggle ─────────────────────────────────────────────────────
function toggleFaqAccordion(headerEl) {
    const card = headerEl.closest('.pm-faq-accordion-card');
    if (card) {
        card.classList.toggle('open');
    }
}

// ── Search & Filter Logic ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Confirmation buttons
    document.querySelectorAll('button[data-confirm]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            const msg  = this.dataset.confirm;
            Swal.fire({
                title: 'Confirm Action',
                text: msg,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--clr-primary)',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });
    });

    // Service category filter tabs
    const catFilters = document.querySelectorAll('.pm-cat-filter');
    const svcCards   = document.querySelectorAll('.pm-hub-svc-card');
    const svcSearch  = document.getElementById('svcSearch');

    function applySvcFilter() {
        const activeCat = document.querySelector('.pm-cat-filter.active')?.dataset.filter || 'all';
        const q = svcSearch ? svcSearch.value.toLowerCase().trim() : '';

        svcCards.forEach(card => {
            const name  = card.dataset.name  || '';
            const badge = card.dataset.badge || '';
            const cat   = card.dataset.cat   || '';

            const matchCat  = (activeCat === 'all' || cat === activeCat);
            const matchText = (!q || name.includes(q) || badge.includes(q) || cat.includes(q));

            card.style.display = (matchCat && matchText) ? 'flex' : 'none';
        });
    }

    catFilters.forEach(btn => {
        btn.addEventListener('click', function() {
            catFilters.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            applySvcFilter();
        });
    });

    if (svcSearch) {
        svcSearch.addEventListener('input', applySvcFilter);
    }

    // FAQ live search
    const faqSearch = document.getElementById('faqSearch');
    if (faqSearch) {
        faqSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.pm-faq-accordion-card').forEach(c => {
                const text = c.dataset.q || '';
                c.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
