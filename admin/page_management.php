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
        ['hero_headline',     'See the World <span>Clearly</span> &amp; <span>Beautifully</span>'],
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

// ── POST Handler ─────────────────────────────────────────────────────────────
$reopenData = null;
$activeTab  = $_POST['active_tab'] ?? $_GET['tab'] ?? 'homepage';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $activeTab = $_POST['active_tab'] ?? 'homepage';

    // ── HOMEPAGE SETTINGS ──
    if ($action === 'save_homepage') {
        $keys = ['hero_badge','hero_headline','hero_description',
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
        $msg = 'Service ' . ($new ? 'activated' : 'deactivated') . ' successfully.';
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
        $msg = 'FAQ ' . ($new ? 'published' : 'hidden') . ' successfully.';
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

<!-- Page Header -->
<div class="pm-page-header">
  <div class="pm-header-left">
    <div class="pm-header-icon">
      <i class="fas fa-globe"></i>
    </div>
    <div>
      <h4 class="pm-header-title">Page Management</h4>
      <p class="pm-header-sub">Control what patients see on the website — services, homepage content, and FAQs</p>
    </div>
  </div>
  <a href="<?= BASE_URL ?>index.php" target="_blank" class="btn btn-outline-primary pm-preview-btn">
    <i class="fas fa-external-link-alt"></i> Preview Website
  </a>
</div>

<!-- Tab Nav -->
<div class="pm-tab-nav">
  <button type="button" class="pm-tab <?= $activeTab === 'homepage' ? 'active' : '' ?>" onclick="switchTab('homepage')">
    <i class="fas fa-home"></i> Homepage Content
  </button>
  <button type="button" class="pm-tab <?= $activeTab === 'services' ? 'active' : '' ?>" onclick="switchTab('services')">
    <i class="fas fa-stethoscope"></i> Services
    <span class="pm-tab-badge"><?= $activeSvc ?>/<?= $totalSvc ?></span>
  </button>
  <button type="button" class="pm-tab <?= $activeTab === 'faqs' ? 'active' : '' ?>" onclick="switchTab('faqs')">
    <i class="fas fa-circle-question"></i> FAQs
    <span class="pm-tab-badge"><?= $activeFaq ?>/<?= $totalFaq ?></span>
  </button>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 1: HOMEPAGE CONTENT
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'homepage' ? 'active' : '' ?>" id="tab-homepage">
  <form method="POST">
    <input type="hidden" name="action" value="save_homepage">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="active_tab" value="homepage">

    <!-- Hero Section -->
    <div class="pm-section-card">
      <div class="pm-section-head">
        <div class="pm-section-icon" style="background:linear-gradient(135deg,#235EAE,#00ADEF);">
          <i class="fas fa-wand-magic-sparkles"></i>
        </div>
        <div>
          <h5 class="pm-section-title">Hero Section</h5>
          <p class="pm-section-sub">The first thing patients see when they visit the website</p>
        </div>
      </div>
      <div class="pm-form-grid">
        <div class="pm-form-group">
          <label class="pm-label"><i class="fas fa-certificate"></i> Badge Text</label>
          <input type="text" name="hero_badge" class="form-control" value="<?= gs($settings,'hero_badge','Established in 1986') ?>" placeholder="e.g. Established in 1986">
          <small class="pm-hint">The small badge pill shown above the headline</small>
        </div>
        <div class="pm-form-group pm-span-2">
          <label class="pm-label"><i class="fas fa-heading"></i> Main Headline</label>
          <input type="text" name="hero_headline" class="form-control" value="<?= gs($settings,'hero_headline','See the World Clearly &amp; Beautifully') ?>" placeholder="Main headline text">
          <small class="pm-hint">You can use &lt;span&gt; tags for colored words</small>
        </div>
        <div class="pm-form-group pm-span-3">
          <label class="pm-label"><i class="fas fa-align-left"></i> Description</label>
          <textarea name="hero_description" class="form-control" rows="3" placeholder="Hero description text..."><?= gs($settings,'hero_description') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Stats Section -->
    <div class="pm-section-card">
      <div class="pm-section-head">
        <div class="pm-section-icon" style="background:linear-gradient(135deg,#1E74BD,#27AAE2);">
          <i class="fas fa-chart-bar"></i>
        </div>
        <div>
          <h5 class="pm-section-title">Statistics</h5>
          <p class="pm-section-sub">The 3 stat numbers shown below the hero description</p>
        </div>
      </div>
      <div class="pm-stats-grid">
        <?php foreach ([['stat1','40+','Years of Service'],['stat2','10k+','Happy Patients'],['stat3','100%','Commitment']] as [$k,$dv,$dl]): ?>
        <div class="pm-stat-block">
          <div class="pm-stat-preview">
            <span class="pm-stat-num"><?= gs($settings,"{$k}_value",$dv) ?></span>
            <span class="pm-stat-lbl"><?= gs($settings,"{$k}_label",$dl) ?></span>
          </div>
          <div class="pm-form-group">
            <label class="pm-label">Value</label>
            <input type="text" name="<?= $k ?>_value" class="form-control" value="<?= gs($settings,"{$k}_value",$dv) ?>" placeholder="e.g. 40+">
          </div>
          <div class="pm-form-group">
            <label class="pm-label">Label</label>
            <input type="text" name="<?= $k ?>_label" class="form-control" value="<?= gs($settings,"{$k}_label",$dl) ?>" placeholder="e.g. Years of Service">
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Feature Cards -->
    <div class="pm-section-card">
      <div class="pm-section-head">
        <div class="pm-section-icon" style="background:linear-gradient(135deg,#2D3891,#272264);">
          <i class="fas fa-layer-group"></i>
        </div>
        <div>
          <h5 class="pm-section-title">Feature Cards</h5>
          <p class="pm-section-sub">The 3 highlight cards shown below the hero section</p>
        </div>
      </div>
      <?php
      $cardDefs = [
        ['card1','fa-user-md','Expert Optometrists','Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.'],
        ['card2','fa-glasses','Premium Eyewear','Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.'],
        ['card3','fa-map-marker-alt','Convenient Location','Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.'],
      ];
      foreach ($cardDefs as [$k,$di,$dt,$dd]):
      ?>
      <div class="pm-card-editor">
        <div class="pm-card-editor-preview">
          <div class="pm-card-icon-preview"><i class="fas <?= gs($settings,"{$k}_icon",$di) ?>"></i></div>
          <div>
            <div class="pm-card-title-preview"><?= gs($settings,"{$k}_title",$dt) ?></div>
            <div class="pm-card-desc-preview"><?= gs($settings,"{$k}_desc",$dd) ?></div>
          </div>
        </div>
        <div class="pm-form-grid">
          <div class="pm-form-group">
            <label class="pm-label">Icon Class</label>
            <input type="text" name="<?= $k ?>_icon" class="form-control" value="<?= gs($settings,"{$k}_icon",$di) ?>" placeholder="e.g. fa-user-md">
            <small class="pm-hint">FontAwesome 6 class (e.g. fa-eye)</small>
          </div>
          <div class="pm-form-group pm-span-2">
            <label class="pm-label">Title</label>
            <input type="text" name="<?= $k ?>_title" class="form-control" value="<?= gs($settings,"{$k}_title",$dt) ?>">
          </div>
          <div class="pm-form-group pm-span-3">
            <label class="pm-label">Description</label>
            <textarea name="<?= $k ?>_desc" class="form-control" rows="2"><?= gs($settings,"{$k}_desc",$dd) ?></textarea>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="pm-save-bar">
      <div class="pm-save-info"><i class="fas fa-info-circle"></i> Changes will appear immediately on the patient website after saving.</div>
      <button type="submit" class="btn btn-primary pm-save-btn"><i class="fas fa-save"></i> Save Homepage Content</button>
    </div>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 2: SERVICES
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'services' ? 'active' : '' ?>" id="tab-services">
  <div class="pm-toolbar">
    <div class="pm-toolbar-left">
      <div class="pm-search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="svcSearch" placeholder="Search services..." autocomplete="off">
      </div>
    </div>
    <button type="button" class="btn btn-primary" onclick="openModal('addSvcModal')">
      <i class="fas fa-plus"></i> Add Service
    </button>
  </div>

  <div class="pm-cards-grid" id="svcGrid">
    <?php if (empty($services)): ?>
    <div class="pm-empty-state">
      <i class="fas fa-stethoscope"></i>
      <h6>No services yet</h6>
      <p>Click "Add Service" to create your first clinic service.</p>
    </div>
    <?php else: foreach ($services as $svc): ?>
    <div class="pm-service-card <?= $svc['is_active'] ? '' : 'pm-inactive' ?>"
         data-name="<?= strtolower(htmlspecialchars($svc['name'])) ?>"
         data-badge="<?= strtolower(htmlspecialchars($svc['badge'] ?? '')) ?>">
      <div class="pm-svc-top">
        <span class="pm-svc-badge"><?= htmlspecialchars($svc['badge'] ?? '') ?></span>
        <span class="pm-svc-status <?= $svc['is_active'] ? 'pm-status-active' : 'pm-status-inactive' ?>">
          <?= $svc['is_active'] ? 'Active' : 'Hidden' ?>
        </span>
      </div>
      <div class="pm-svc-name"><?= htmlspecialchars($svc['name']) ?></div>
      <div class="pm-svc-desc"><?= htmlspecialchars($svc['description'] ?? '') ?></div>
      <div class="pm-svc-meta"><i class="fas fa-clock"></i> <?= htmlspecialchars($svc['duration'] ?? '') ?></div>
      <div class="pm-svc-footer">
        <span class="pm-cat-pill pm-cat-<?= $svc['purpose_category'] ?>">
          <?= htmlspecialchars($svc['purpose_category']) ?>
        </span>
        <div class="pm-svc-actions">
          <button type="button" class="pm-btn-icon pm-btn-edit" title="Edit"
            onclick="openEditSvc(<?= $svc['id'] ?>, '<?= addslashes($svc['name']) ?>', '<?= $svc['purpose_category'] ?>', '<?= addslashes($svc['badge'] ?? '') ?>', '<?= addslashes($svc['description'] ?? '') ?>', '<?= addslashes($svc['duration'] ?? '') ?>', <?= $svc['is_active'] ?>)">
            <i class="fas fa-edit"></i>
          </button>
          <form method="POST" style="margin:0;display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_service">
            <input type="hidden" name="active_tab" value="services">
            <input type="hidden" name="svc_id" value="<?= $svc['id'] ?>">
            <input type="hidden" name="svc_current" value="<?= $svc['is_active'] ?>">
            <button type="submit" class="pm-btn-icon <?= $svc['is_active'] ? 'pm-btn-warn' : 'pm-btn-success' ?>"
              title="<?= $svc['is_active'] ? 'Hide from patients' : 'Show to patients' ?>"
              data-confirm="<?= $svc['is_active'] ? 'Hide' : 'Activate' ?> \"<?= addslashes($svc['name']) ?>\"?">
              <i class="fas fa-<?= $svc['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 3: FAQS
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'faqs' ? 'active' : '' ?>" id="tab-faqs">
  <div class="pm-toolbar">
    <div class="pm-toolbar-left">
      <div class="pm-search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="faqSearch" placeholder="Search FAQs..." autocomplete="off">
      </div>
    </div>
    <button type="button" class="btn btn-primary" onclick="openModal('addFaqModal')">
      <i class="fas fa-plus"></i> Add FAQ
    </button>
  </div>

  <div class="pm-faq-list" id="faqList">
    <?php if (empty($faqs)): ?>
    <div class="pm-empty-state">
      <i class="fas fa-circle-question"></i>
      <h6>No FAQs yet</h6>
      <p>Click "Add FAQ" to create your first question.</p>
    </div>
    <?php else: foreach ($faqs as $i => $faq): ?>
    <div class="pm-faq-row <?= $faq['is_active'] ? '' : 'pm-inactive' ?>"
         data-q="<?= strtolower(htmlspecialchars($faq['question'])) ?>">
      <div class="pm-faq-num"><?= $i+1 ?></div>
      <div class="pm-faq-icon-wrap"><i class="fas <?= htmlspecialchars($faq['icon']) ?>"></i></div>
      <div class="pm-faq-content">
        <div class="pm-faq-q"><?= htmlspecialchars($faq['question']) ?></div>
        <div class="pm-faq-a"><?= htmlspecialchars($faq['answer']) ?></div>
      </div>
      <div class="pm-faq-right">
        <span class="pm-svc-status <?= $faq['is_active'] ? 'pm-status-active' : 'pm-status-inactive' ?>">
          <?= $faq['is_active'] ? 'Published' : 'Hidden' ?>
        </span>
        <div class="pm-svc-actions">
          <button type="button" class="pm-btn-icon pm-btn-edit" title="Edit"
            onclick="openEditFaq(<?= $faq['id'] ?>, '<?= addslashes($faq['question']) ?>', '<?= addslashes($faq['answer']) ?>', '<?= addslashes($faq['icon']) ?>', <?= $faq['is_active'] ?>)">
            <i class="fas fa-edit"></i>
          </button>
          <form method="POST" style="margin:0;display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_faq">
            <input type="hidden" name="active_tab" value="faqs">
            <input type="hidden" name="faq_id" value="<?= $faq['id'] ?>">
            <input type="hidden" name="faq_current" value="<?= $faq['is_active'] ?>">
            <button type="submit" class="pm-btn-icon <?= $faq['is_active'] ? 'pm-btn-warn' : 'pm-btn-success' ?>"
              title="<?= $faq['is_active'] ? 'Hide FAQ' : 'Publish FAQ' ?>"
              data-confirm="<?= $faq['is_active'] ? 'Hide' : 'Publish' ?> this FAQ?">
              <i class="fas fa-<?= $faq['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════════ -->

<!-- Add Service Modal -->
<div class="modal-overlay" id="addSvcModal">
  <div class="modal-box" style="max-width:560px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-stethoscope"></i></div>
        <div class="modal-header-titles"><h5>Add Service</h5><small>Create a new clinic service for patient booking</small></div>
      </div>
      <button class="modal-close" onclick="closeModal('addSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <div class="form-group">
          <label class="form-label">Service Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="svc_name" class="form-control" placeholder="e.g. Comprehensive Eye Examination" required autofocus>
        </div>
        <div class="pm-modal-row">
          <div class="form-group">
            <label class="form-label">Category <span style="color:var(--clr-danger)">*</span></label>
            <select name="svc_category" class="form-select" required>
              <option value="">Select category</option>
              <option value="consultation">Consultation</option>
              <option value="eyeglass_claim">Eyewear & Lenses</option>
              <option value="contact_lens_fitting">Contact Lenses</option>
              <option value="follow_up">Follow-up</option>
              <option value="other">Care & General</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Badge Label</label>
            <input type="text" name="svc_badge" class="form-control" placeholder="e.g. Examination">
            <small class="form-text text-muted">Short label on the card</small>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="svc_desc" class="form-control" rows="2" placeholder="Brief description of this service..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Estimated Duration</label>
          <input type="text" name="svc_duration" class="form-control" placeholder="e.g. 30–45 mins">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Service</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Service Modal -->
<div class="modal-overlay" id="editSvcModal">
  <div class="modal-box" style="max-width:560px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles"><h5>Edit Service</h5><small>Modify service details</small></div>
      </div>
      <button class="modal-close" onclick="closeModal('editSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_id" id="editSvcId">
        <div class="form-group">
          <label class="form-label">Service Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="svc_name" id="editSvcName" class="form-control" required>
        </div>
        <div class="pm-modal-row">
          <div class="form-group">
            <label class="form-label">Category <span style="color:var(--clr-danger)">*</span></label>
            <select name="svc_category" id="editSvcCat" class="form-select" required>
              <option value="consultation">Consultation</option>
              <option value="eyeglass_claim">Eyewear & Lenses</option>
              <option value="contact_lens_fitting">Contact Lenses</option>
              <option value="follow_up">Follow-up</option>
              <option value="other">Care & General</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Badge Label</label>
            <input type="text" name="svc_badge" id="editSvcBadge" class="form-control">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="svc_desc" id="editSvcDesc" class="form-control" rows="2"></textarea>
        </div>
        <div class="pm-modal-row">
          <div class="form-group">
            <label class="form-label">Estimated Duration</label>
            <input type="text" name="svc_duration" id="editSvcDur" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Visibility</label>
            <select name="svc_active" id="editSvcActive" class="form-select">
              <option value="1">Active (visible to patients)</option>
              <option value="0">Hidden (not shown)</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Service</button>
      </div>
    </form>
  </div>
</div>

<!-- Add FAQ Modal -->
<div class="modal-overlay" id="addFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-circle-question"></i></div>
        <div class="modal-header-titles"><h5>Add FAQ</h5><small>Create a new frequently asked question</small></div>
      </div>
      <button class="modal-close" onclick="closeModal('addFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">
        <div class="form-group">
          <label class="form-label">Icon Class</label>
          <input type="text" name="faq_icon" class="form-control" value="fa-circle-question" placeholder="e.g. fa-eye, fa-calendar-check">
          <small class="form-text text-muted">FontAwesome 6 icon class</small>
        </div>
        <div class="form-group">
          <label class="form-label">Question <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="faq_question" class="form-control" placeholder="e.g. How often should I have an eye exam?" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Answer <span style="color:var(--clr-danger)">*</span></label>
          <textarea name="faq_answer" class="form-control" rows="4" placeholder="Write the detailed answer here..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add FAQ</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit FAQ Modal -->
<div class="modal-overlay" id="editFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles"><h5>Edit FAQ</h5><small>Modify question and answer</small></div>
      </div>
      <button class="modal-close" onclick="closeModal('editFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">
        <input type="hidden" name="faq_id" id="editFaqId">
        <div class="form-group">
          <label class="form-label">Icon Class</label>
          <input type="text" name="faq_icon" id="editFaqIcon" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Question <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="faq_question" id="editFaqQ" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Answer <span style="color:var(--clr-danger)">*</span></label>
          <textarea name="faq_answer" id="editFaqA" class="form-control" rows="4" required></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Visibility</label>
          <select name="faq_active" id="editFaqActive" class="form-select">
            <option value="1">Published (visible on website)</option>
            <option value="0">Hidden (not shown)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update FAQ</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.pm-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pm-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector(`.pm-tab[onclick="switchTab('${tab}')"]`).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
}

// ── Service edit ─────────────────────────────────────────────────────────────
function openEditSvc(id, name, cat, badge, desc, dur, active) {
    document.getElementById('editSvcId').value    = id;
    document.getElementById('editSvcName').value  = name;
    document.getElementById('editSvcCat').value   = cat;
    document.getElementById('editSvcBadge').value = badge;
    document.getElementById('editSvcDesc').value  = desc;
    document.getElementById('editSvcDur').value   = dur;
    document.getElementById('editSvcActive').value = active ? '1' : '0';
    openModal('editSvcModal');
}

// ── FAQ edit ─────────────────────────────────────────────────────────────────
function openEditFaq(id, q, a, icon, active) {
    document.getElementById('editFaqId').value     = id;
    document.getElementById('editFaqQ').value      = q;
    document.getElementById('editFaqA').value      = a;
    document.getElementById('editFaqIcon').value   = icon;
    document.getElementById('editFaqActive').value = active ? '1' : '0';
    openModal('editFaqModal');
}

// ── Confirm toggle buttons ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('button[data-confirm]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            const msg  = this.dataset.confirm;
            Swal.fire({
                title: 'Are you sure?',
                text: msg,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--clr-primary)',
                cancelButtonColor: 'var(--clr-danger)',
                confirmButtonText: 'Yes, do it!',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });
    });

    // Service live search
    const svcSearch = document.getElementById('svcSearch');
    if (svcSearch) {
        svcSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.pm-service-card').forEach(c => {
                const name  = c.dataset.name  || '';
                const badge = c.dataset.badge || '';
                c.style.display = (!q || name.includes(q) || badge.includes(q)) ? '' : 'none';
            });
        });
    }

    // FAQ live search
    const faqSearch = document.getElementById('faqSearch');
    if (faqSearch) {
        faqSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.pm-faq-row').forEach(r => {
                const text = r.dataset.q || '';
                r.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
