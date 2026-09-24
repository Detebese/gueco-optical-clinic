<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requirePatientLogin();

$db        = getDB();
$patientId = $_SESSION['patient_id'];

// Flash message
$flashMsg = $flashType = '';
if (isset($_SESSION['flash_msg'])) {
    $flashMsg  = $_SESSION['flash_msg'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // Change Password
    if ($action === 'change_password') {
        $currPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';
        
        if (empty($currPass) || empty($newPass) || empty($confPass)) {
            $_SESSION['flash_msg'] = 'All fields are required.';
            $_SESSION['flash_type'] = 'danger';
        } elseif ($passErr = validatePasswordStrength($newPass)) {
            $_SESSION['flash_msg'] = $passErr;
            $_SESSION['flash_type'] = 'danger';
        } elseif ($newPass !== $confPass) {
            $_SESSION['flash_msg'] = 'New passwords do not match.';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $chkPass = $db->prepare("SELECT password FROM patients WHERE id=?");
            $chkPass->execute([$patientId]);
            $hash = $chkPass->fetchColumn();
            
            if (password_verify($currPass, $hash)) {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare("UPDATE patients SET password=? WHERE id=?")->execute([$newHash, $patientId]);
                $_SESSION['flash_msg'] = 'Password changed successfully!';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_msg'] = 'Incorrect current password.';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        header('Location: change_password.php');
        exit;
    }
}

// Patient info
$patient = $db->prepare("SELECT * FROM patients WHERE id=?"); 
$patient->execute([$patientId]); 
$patient = $patient->fetch();
$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Password — Gueco Optical Clinic</title>
  
  <!-- Immediate Theme Initialization & Caret Browsing Prevention -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem("gueco_theme") || localStorage.getItem("gueco-theme") || localStorage.getItem("theme") || localStorage.getItem("guecoTheme");
        if (!theme) {
          var m = document.cookie.match(/(?:^|;\s*)gueco_theme=([^;]+)/);
          theme = m ? m[1] : "<?= $currentTheme ?>";
        }
        if (theme !== "light" && theme !== "dark") theme = "dark";
        document.documentElement.setAttribute("data-theme", theme);
      } catch (e) {
        document.documentElement.setAttribute("data-theme", "<?= $currentTheme ?>");
      }
    })();

    // Prevent accidental browser Caret Browsing (F7) activation
    window.addEventListener('keydown', function(e) {
      if (e.key === 'F7' || e.keyCode === 118) {
        e.preventDefault();
      }
    });
  </script>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <!-- SweetAlert2 (Modal Popups) -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

    /* ─── Universal Caret & Text-Selection Prevention ─────── */
    *, *::before, *::after {
      caret-color: transparent;
    }

    body, h1, h2, h3, h4, h5, h6, p, span, div, a, label, li, ul, ol, section, main, header, footer, nav, table, tr, th, td,
    button,
    [type="button"],
    [type="reset"],
    [type="submit"],
    .btn,
    .nav-link,
    .tab-btn,
    .badge,
    .theme-btn,
    .user-chip,
    .card {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }

    h1, h2, h3, h4, h5, h6, p, label, .card, table {
      cursor: default;
    }

    button,
    [type="button"],
    [type="reset"],
    [type="submit"],
    .btn,
    a,
    .nav-link,
    .tab-btn,
    .theme-btn,
    .user-chip {
      cursor: pointer;
    }

    input,
    textarea,
    [contenteditable="true"],
    .allow-select {
      -webkit-user-select: text !important;
      -moz-user-select: text !important;
      -ms-user-select: text !important;
      user-select: text !important;
      caret-color: auto !important;
      cursor: text !important;
    }

    select {
      -webkit-user-select: auto !important;
      -moz-user-select: auto !important;
      -ms-user-select: auto !important;
      user-select: auto !important;
      cursor: pointer !important;
    }

    :root {
      --clr-primary:       #235EAE;
      --clr-primary-light: #00ADEF;
      --clr-secondary:     #272264;
      --clr-bronze:        #E09A67;
      --clr-gold:          #F59E0B;
      --clr-success:       #10B981;
      --clr-danger:        #EF4444;
      --clr-warning:       #F59E0B;
      
      --bg-body:           #F8FAFC;
      --bg-card:           #FFFFFF;
      --bg-hover:          #F1F5F9;
      --border-color:      #E2E8F0;
      --border-light:      #EDF2F7;
      
      --text-primary:      #0F172A;
      --text-secondary:    #475569;
      --text-muted:        #94A3B8;
      
      --shadow-sm:         0 2px 8px rgba(0,0,0,.04);
      --shadow-md:         0 8px 24px rgba(35, 94, 174, 0.08);
      --shadow-lg:         0 16px 36px rgba(35, 94, 174, 0.12);
    }

    [data-theme="dark"] {
      --clr-primary:       #00ADEF;
      --clr-primary-light: #38BDF8;
      --clr-secondary:     #235EAE;
      --clr-bronze:        #F6AD55;
      --clr-gold:          #FBBF24;
      --clr-success:       #34D399;
      --clr-danger:        #F87171;
      --clr-warning:       #FBBF24;
      
      --bg-body:           #0B132B;
      --bg-card:           #1C2541;
      --bg-hover:          #243056;
      --border-color:      rgba(255, 255, 255, 0.08);
      --border-light:      rgba(255, 255, 255, 0.04);
      
      --text-primary:      #F8FAFC;
      --text-secondary:    #CBD5E1;
      --text-muted:        #64748B;
      
      --shadow-sm:         0 2px 8px rgba(0,0,0,.25);
      --shadow-md:         0 8px 24px rgba(0,0,0,.35);
      --shadow-lg:         0 16px 36px rgba(0,0,0,.45);
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background-color: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      transition: background-color .3s ease, color .3s ease;
      position: relative;
    }

    .bg-mesh {
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at 10% 20%, rgba(35, 94, 174, 0.04) 0%, transparent 40%),
                  radial-gradient(circle at 90% 80%, rgba(0, 173, 239, 0.04) 0%, transparent 40%);
      pointer-events: none;
      z-index: 0;
    }

    /* TOPBAR */
    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: var(--bg-card);
      border-bottom: 1px solid var(--border-color);
      padding: 0 24px;
      height: 70px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: var(--shadow-sm);
      backdrop-filter: blur(12px);
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
    }

    .topbar-logo {
      width: 40px;
      height: 40px;
      object-fit: contain;
    }

    .topbar-name {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-primary);
      line-height: 1.2;
    }

    .topbar-sub {
      font-size: .72rem;
      color: var(--text-muted);
      font-weight: 600;
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .theme-btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      color: var(--text-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .95rem;
      cursor: pointer;
      transition: all .2s;
    }

    .theme-btn:hover {
      background: var(--border-color);
      color: var(--clr-primary);
      transform: scale(1.05);
    }

    .user-chip {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 14px 6px 6px;
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      border-radius: 40px;
      cursor: pointer;
      transition: all .2s;
    }

    .user-chip:hover {
      border-color: var(--clr-primary);
    }

    .user-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: .8rem;
    }

    .user-name {
      font-size: .88rem;
      font-weight: 700;
      color: var(--text-primary);
    }

    .user-dropdown { position: relative; }
    .user-dropdown-menu {
      position: absolute;
      top: calc(100% + 10px);
      right: 0;
      background: var(--bg-card);
      border: 1.5px solid var(--border-color);
      border-radius: 16px;
      box-shadow: var(--shadow-md);
      width: 230px;
      padding: 8px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      opacity: 0;
      visibility: hidden;
      transform: translateY(-8px);
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 100;
    }
    .user-dropdown.open .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-item {
      padding: 10px 14px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--text-primary);
      text-decoration: none;
      font-size: .88rem;
      font-weight: 700;
      background: none;
      border: none;
      width: 100%;
      text-align: left;
      cursor: pointer;
      transition: all .15s;
    }
    .dropdown-item i { font-size: 1.05rem; color: var(--clr-primary); width: 20px; text-align: center; }
    [data-theme="dark"] .dropdown-item i { color: var(--clr-primary-light); }
    .dropdown-item:hover { background: var(--bg-hover); color: var(--clr-primary); transform: translateX(3px); }
    [data-theme="dark"] .dropdown-item:hover { color: var(--clr-primary-light); }
    .dropdown-item.danger { color: var(--clr-danger); }
    .dropdown-item.danger i { color: var(--clr-danger); }
    .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.12); color: var(--clr-danger); }

    /* PAGE WRAPPER */
    .page-wrap {
      max-width: 680px;
      margin: 0 auto;
      padding: 34px 20px 80px;
      position: relative;
      z-index: 1;
    }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--text-secondary);
      font-size: .88rem;
      font-weight: 700;
      text-decoration: none;
      margin-bottom: 24px;
      transition: color .2s, transform .2s;
    }

    .back-link:hover {
      color: var(--clr-primary);
      transform: translateX(-4px);
    }

    /* CARD DESIGN */
    .form-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 20px;
      padding: 30px;
      box-shadow: var(--shadow-sm);
      margin-bottom: 24px;
    }

    .card-header-flex {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border-light);
    }

    .card-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }

    .card-icon.orange {
      background: rgba(245, 158, 11, 0.12);
      color: var(--clr-warning);
    }

    .card-title {
      font-size: 1.18rem;
      font-weight: 800;
      color: var(--text-primary);
      margin: 0;
    }

    .card-subtitle {
      font-size: .78rem;
      color: var(--text-muted);
      margin: 2px 0 0;
    }

    .field-control {
      width: 100%;
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 12px 16px;
      font-size: .92rem;
      color: var(--text-primary);
      font-family: inherit;
      outline: none;
      transition: all .2s ease;
    }

    .field-control:focus {
      border-color: var(--clr-primary);
      background: var(--bg-card);
      box-shadow: 0 0 0 3px rgba(35, 94, 174, 0.15);
    }

    .field-control:disabled {
      opacity: 0.65;
      cursor: not-allowed;
      background: var(--bg-body);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--clr-secondary), var(--clr-primary));
      color: #fff;
      border: none;
      padding: 12px 28px;
      border-radius: 12px;
      font-size: .92rem;
      font-weight: 700;
      cursor: pointer;
      transition: all .25s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(39, 34, 100, 0.25);
    }

    /* Password Requirements Checklist */
    .pass-req-box {
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 20px;
    }
    .pass-req-header {
      font-size: .78rem;
      font-weight: 800;
      color: var(--text-secondary);
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .pass-req-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    @media (max-width: 576px) {
      .pass-req-grid { grid-template-columns: 1fr; }
    }
    .pass-req-item {
      font-size: .74rem;
      font-weight: 600;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
      transition: all .2s;
    }
    .pass-req-item i { font-size: .8rem; }
    .pass-req-item.valid { color: var(--clr-success); }
    .pass-req-item.valid i { color: var(--clr-success); }

    @media (max-width: 640px) {
      .topbar {
        height: 60px;
        padding: 0 14px;
      }
      .topbar-name { font-size: .92rem; }
      .topbar-sub { display: none; }
      .page-wrap { padding: 20px 14px 60px; }
      .form-card { padding: 20px 16px; border-radius: 16px; }
      .btn-warning { width: 100%; justify-content: center; }
      .field-control { padding: 11px 14px; font-size: .88rem; }
    }
  </style>
</head>
<body>
<div class="bg-mesh"></div>

<!-- TOPBAR -->
<nav class="topbar">
  <a href="dashboard.php" class="topbar-brand">
    <img src="../assets/images/logo.png?v=2" alt="Logo" class="topbar-logo">
    <div>
      <div class="topbar-name">Gueco Optical Clinic</div>
      <div class="topbar-sub">Patient Portal · Capas, Tarlac</div>
    </div>
  </a>
  <div class="topbar-right">
    <button class="theme-btn" id="themeToggle" title="Toggle Light/Dark Theme">
      <i class="<?= $currentTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon' ?>" id="themeIcon"></i>
    </button>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-chip" onclick="toggleDropdown()">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['patient_name'] ?? 'P', 0, 1)) ?></div>
        <span class="user-name"><?= sanitize(explode(' ', $_SESSION['patient_name'] ?? 'Patient')[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:.7rem;color:var(--text-muted);margin-left:4px;"></i>
      </div>
      <div class="user-dropdown-menu">
        <a href="settings.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Profile Settings</a>
        <a href="change_password.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
        <div style="height:1px;background:var(--border-color);margin:4px 0;"></div>
        <a href="logout.php" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

  <!-- Notifications Popup Carrier (SweetAlert2 Modal Popup) -->
  <?php if ($flashMsg): ?>
  <div id="patientFlashMsg"
       data-msg="<?= htmlspecialchars((string)$flashMsg, ENT_QUOTES) ?>"
       data-type="<?= htmlspecialchars((string)$flashType, ENT_QUOTES) ?>"
       data-title="<?= htmlspecialchars((string)($flashType === 'success' ? 'Success!' : 'Notice'), ENT_QUOTES) ?>"
       style="display:none"></div>
  <?php endif; ?>

  <!-- Change Password Card -->
  <div class="form-card" id="password">
    <div class="card-header-flex">
      <div class="card-icon orange"><i class="fas fa-key"></i></div>
      <div>
        <h2 class="card-title">Change Password</h2>
        <p class="card-subtitle">Ensure your account remains secure</p>
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <input type="hidden" name="action" value="change_password">
      
      <div class="mb-3">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-lock me-1"></i>Current Password</label>
        <input type="password" name="current_password" class="field-control" required placeholder="••••••••">
      </div>

      <div class="mb-3">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-key me-1"></i>New Password</label>
        <input type="password" id="settingNewPass" name="new_password" class="field-control" required minlength="8" placeholder="At least 8 chars, 1 capital, 1 special">
      </div>

      <div class="mb-3">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-check-double me-1"></i>Confirm Password</label>
        <input type="password" id="settingConfirmPass" name="confirm_password" class="field-control" required minlength="8" placeholder="Re-enter new password">
      </div>

      <!-- Real-time Password Security Requirements -->
      <div class="pass-req-box" id="settingPassRules">
        <div class="pass-req-header"><i class="fas fa-shield-halved"></i> Password Security Requirements:</div>
        <div class="pass-req-grid">
          <div class="pass-req-item" id="setReqLength"><i class="fas fa-circle-xmark"></i> At least 8 characters</div>
          <div class="pass-req-item" id="setReqUpper"><i class="fas fa-circle-xmark"></i> At least 1 capital letter (A–Z)</div>
          <div class="pass-req-item" id="setReqSpecial"><i class="fas fa-circle-xmark"></i> At least 1 special char (!@#$...)</div>
          <div class="pass-req-item" id="setReqMatch"><i class="fas fa-circle-xmark"></i> Passwords match</div>
        </div>
      </div>

      <button type="submit" class="btn-warning"><i class="fas fa-lock me-2"></i> Update Password</button>
    </form>
  </div>

</div>

<script>
// Theme Management
const html = document.documentElement;
const themeBtn  = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');

function applyPatientTheme(theme) {
  if (theme !== 'light' && theme !== 'dark') theme = 'dark';
  html.setAttribute('data-theme', theme);
  try {
    localStorage.setItem('gueco_theme', theme);
    localStorage.setItem('gueco-theme', theme);
    localStorage.setItem('guecoTheme', theme);
    localStorage.setItem('theme', theme);
    document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
    document.cookie = "theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
  } catch(e) {}
  if (themeIcon) {
    themeIcon.className = (theme === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
  }
}

const savedTheme = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('theme') || localStorage.getItem('guecoTheme') || '<?= $currentTheme ?>';
applyPatientTheme(savedTheme);

if (themeBtn) {
  themeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    const current = html.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    applyPatientTheme(next);
  });
}

// Dropdown
function toggleDropdown() {
  document.getElementById('userDropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.user-dropdown')) {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) dropdown.classList.remove('open');
  }
});

// SweetAlert2 Modal Popup Notification
function showPopupModal(msg, type = 'info', title = null) {
  if (!msg) return;
  if (typeof Swal === 'undefined') {
    alert(msg);
    return;
  }
  const isError = (type === 'danger' || type === 'error');
  const isSuccess = (type === 'success');
  const iconType = isSuccess ? 'success' : (isError ? 'error' : 'info');
  const titleText = title || (isSuccess ? 'Success!' : (isError ? 'Notice' : 'Information'));

  Swal.fire({
    title: titleText,
    text: msg,
    icon: iconType,
    confirmButtonText: 'OK',
    confirmButtonColor: 'var(--clr-primary)',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)',
    customClass: {
      popup: 'patient-swal-popup'
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  const pFlash = document.getElementById('patientFlashMsg');
  if (pFlash) {
    const msg = pFlash.dataset.msg;
    const type = pFlash.dataset.type || 'info';
    const title = pFlash.dataset.title;
    if (msg) {
      showPopupModal(msg, type, title);
    }
  }
});

// Real-time Password Security Requirements Validator (Change Password)
(function() {
  const passEl = document.getElementById('settingNewPass');
  const confirmEl = document.getElementById('settingConfirmPass');
  const reqLen = document.getElementById('setReqLength');
  const reqUpp = document.getElementById('setReqUpper');
  const reqSpe = document.getElementById('setReqSpecial');
  const reqMat = document.getElementById('setReqMatch');

  if (!passEl) return;

  function setReq(el, ok) {
    if (!el) return;
    const icon = el.querySelector('i');
    if (ok) {
      el.classList.add('valid');
      if (icon) icon.className = 'fas fa-circle-check';
    } else {
      el.classList.remove('valid');
      if (icon) icon.className = 'fas fa-circle-xmark';
    }
  }

  function validate() {
    const val = passEl.value || '';
    const conf = confirmEl ? confirmEl.value : '';

    const hasLen = val.length >= 8;
    const hasUpp = /[A-Z]/.test(val);
    const hasSpe = /[^a-zA-Z0-9]/.test(val);
    const hasMat = val.length > 0 && conf.length > 0 && val === conf;

    setReq(reqLen, hasLen);
    setReq(reqUpp, hasUpp);
    setReq(reqSpe, hasSpe);
    if (reqMat) setReq(reqMat, hasMat);

    return hasLen && hasUpp && hasSpe && (confirmEl ? hasMat : true);
  }

  passEl.addEventListener('input', validate);
  if (confirmEl) confirmEl.addEventListener('input', validate);

  const form = passEl.closest('form');
  if (form) {
    form.addEventListener('submit', function(e) {
      const val = passEl.value || '';
      const conf = confirmEl ? confirmEl.value : '';
      if (val.length < 8) {
        e.preventDefault();
        passEl.focus();
        showPopupModal('New password must be at least 8 characters long.', 'danger', 'Security Requirement');
        return false;
      }
      if (!/[A-Z]/.test(val)) {
        e.preventDefault();
        passEl.focus();
        showPopupModal('New password must contain at least one capital letter (A–Z).', 'danger', 'Security Requirement');
        return false;
      }
      if (!/[^a-zA-Z0-9]/.test(val)) {
        e.preventDefault();
        passEl.focus();
        showPopupModal('New password must contain at least one special character (e.g. !@#$%^&*).', 'danger', 'Security Requirement');
        return false;
      }
      if (confirmEl && val !== conf) {
        e.preventDefault();
        confirmEl.focus();
        showPopupModal('New passwords do not match. Please verify both fields.', 'danger', 'Notice');
        return false;
      }
    });
  }
})();
</script>
</body>
</html>
