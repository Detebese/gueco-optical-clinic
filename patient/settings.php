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

    // Update Profile
    if ($action === 'update_profile') {
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $db->prepare("UPDATE patients SET phone=?, address=? WHERE id=?")->execute([$phone, $address, $patientId]);
        $_SESSION['flash_msg'] = 'Profile updated successfully!';
        $_SESSION['flash_type'] = 'success';
        header('Location: settings.php');
        exit;
    }

    // Change Password
    if ($action === 'change_password') {
        $currPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';
        
        if (empty($currPass) || empty($newPass) || empty($confPass)) {
            $_SESSION['flash_msg'] = 'All fields are required.';
            $_SESSION['flash_type'] = 'danger';
        } elseif (strlen($newPass) < 6) {
            $_SESSION['flash_msg'] = 'New password must be at least 6 characters.';
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
        header('Location: settings.php#password');
        exit;
    }
}

// Patient info
$patient = $db->prepare("SELECT * FROM patients WHERE id=?"); 
$patient->execute([$patientId]); 
$patient = $patient->fetch();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account Settings — Gueco Optical Clinic</title>
  
  <!-- Immediate Theme Initialization -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem("gueco_theme") || localStorage.getItem("gueco-theme") || localStorage.getItem("guecoTheme") || "dark";
        document.documentElement.setAttribute("data-theme", theme);
      } catch (e) {
        document.documentElement.setAttribute("data-theme", "dark");
      }
    })();
  </script>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  
  <style>
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }

    :root { 
      /* Luxury Brand Color Tokens */
      --clr-bronze-light: #FDBA74;
      --clr-bronze:       #E09A67;
      --clr-bronze-dark:  #B86B35;
      --clr-gold:         #F59E0B;
      --clr-amber:        #D97706;

      --clr-primary:      #E09A67;
      --clr-primary-light:#FDBA74;
      --clr-primary-dark: #B86B35;
      --clr-secondary:    #C26325;
      
      --clr-success:      #10B981;
      --clr-danger:       #EF4444;
      --clr-warning:      #F59E0B;
      --clr-info:         #0EA5E9;
    }

    [data-theme="dark"] {
      --bg-body:          #0A0A0D;
      --bg-card:          #17161D;
      --bg-card-glass:    rgba(23, 22, 29, 0.85);
      --bg-topbar:        rgba(10, 10, 13, 0.88);
      --bg-hover:         rgba(224, 154, 103, 0.08);
      --bg-input:         #1E1C24;
      --bg-input-focus:   #25232D;

      --text-primary:     #F9FAFB;
      --text-secondary:   #E5E7EB;
      --text-muted:       #9CA3AF;
      --text-subtle:      #6B7280;

      --border-color:     rgba(255, 255, 255, 0.09);
      --border-light:     rgba(255, 255, 255, 0.05);
      --border-glow:      rgba(224, 154, 103, 0.35);

      --shadow-md:        0 12px 36px rgba(0, 0, 0, 0.45);
      --shadow-card:      0 8px 32px rgba(0, 0, 0, 0.35);
    }

    [data-theme="light"] {
      --bg-body:          #F3F1EC;
      --bg-card:          #FFFFFF;
      --bg-card-glass:    rgba(255, 255, 255, 0.92);
      --bg-topbar:        rgba(243, 241, 236, 0.90);
      --bg-hover:         rgba(224, 154, 103, 0.06);
      --bg-input:         #EBE7E0;
      --bg-input-focus:   #E2DDD4;

      --text-primary:     #17161D;
      --text-secondary:   #3B3944;
      --text-muted:       #6B7280;
      --text-subtle:      #9CA3AF;

      --border-color:     rgba(0, 0, 0, 0.08);
      --border-light:     rgba(0, 0, 0, 0.04);
      --border-glow:      rgba(224, 154, 103, 0.25);

      --shadow-md:        0 12px 36px rgba(184, 107, 53, 0.08);
      --shadow-card:      0 8px 30px rgba(0, 0, 0, 0.05);
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      font-size: 15px;
      line-height: 1.6;
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Luxury Background Mesh */
    .bg-mesh {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(224, 154, 103, 0.12) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(194, 99, 37, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.03) 0%, transparent 60%);
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(224, 154, 103, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(194, 99, 37, 0.05) 0%, transparent 50%);
    }

    /* TOPBAR */
    .topbar {
      position: sticky; top: 0; z-index: 200;
      background: var(--bg-topbar); backdrop-filter: blur(20px);
      border-bottom: 1px solid var(--border-color);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 28px; height: 68px;
      transition: all 0.3s ease;
    }
    .topbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
    .topbar-logo {
      width: 42px; height: 42px; object-fit: contain; border-radius: 10px;
      border: 1px solid var(--border-glow);
      box-shadow: 0 2px 10px rgba(224, 154, 103, 0.2);
    }
    .topbar-name { font-weight: 800; font-size: 1.05rem; color: var(--text-primary); letter-spacing: -0.01em; }
    .topbar-sub  { font-size: .75rem; color: var(--text-muted); font-weight: 500; }
    .topbar-right { display: flex; align-items: center; gap: 10px; }

    .theme-btn {
      width: 38px; height: 38px; border-radius: 50%;
      border: 1px solid var(--border-color);
      background: var(--bg-card); color: var(--text-muted); cursor: pointer;
      display: flex; align-items: center; justify-content: center; font-size: .88rem;
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .theme-btn:hover {
      border-color: var(--clr-primary); color: var(--clr-primary);
      transform: scale(1.05); box-shadow: 0 0 15px rgba(224, 154, 103, 0.25);
    }

    .user-chip {
      display: flex; align-items: center; gap: 8px;
      background: var(--bg-card); border: 1px solid var(--border-color);
      border-radius: 100px; padding: 5px 14px 5px 5px; cursor: pointer;
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .user-chip:hover { border-color: var(--clr-primary); box-shadow: 0 0 12px rgba(224, 154, 103, 0.2); }
    .user-avatar {
      width: 30px; height: 30px; border-radius: 50%;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-weight: 800; font-size: .76rem;
      box-shadow: 0 2px 8px rgba(224, 154, 103, 0.35);
    }
    .user-name { font-size: .88rem; font-weight: 700; color: var(--text-primary); }

    .user-dropdown { position: relative; }
    .user-dropdown-menu {
      position: absolute; top: calc(100% + 10px); right: 0;
      background: var(--bg-card); border: 1px solid var(--border-color);
      border-radius: 14px; box-shadow: 0 16px 40px rgba(0,0,0,.35);
      width: 220px; padding: 8px;
      display: flex; flex-direction: column; gap: 4px;
      opacity: 0; visibility: hidden; transform: translateY(-10px);
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1); z-index: 100;
      backdrop-filter: blur(16px);
    }
    .user-dropdown.open .user-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-item {
      padding: 10px 14px; border-radius: 10px; display: flex; align-items: center; gap: 12px;
      color: var(--text-primary); text-decoration: none; font-size: .88rem; font-weight: 600;
      background: none; border: none; width: 100%; text-align: left; cursor: pointer;
      transition: all .2s;
    }
    .dropdown-item i { font-size: 1.05rem; color: var(--clr-primary); width: 20px; text-align: center; }
    .dropdown-item:hover { background: var(--bg-hover); color: var(--clr-primary); transform: translateX(3px); }
    .dropdown-item.danger { color: var(--clr-danger); }
    .dropdown-item.danger i { color: var(--clr-danger); }
    .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.1); color: #F87171; }

    /* LAYOUT */
    .page-wrap { position: relative; z-index: 1; max-width: 900px; margin: 36px auto; padding: 0 24px 60px; }
    
    /* CARDS */
    .form-card {
      background: var(--bg-card); border: 1px solid var(--border-color);
      border-radius: 22px; padding: 32px; box-shadow: var(--shadow-card);
      margin-bottom: 26px; transition: all .2s ease;
    }
    .form-card:hover { border-color: var(--border-glow); }
    .card-header-flex { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
    .card-icon {
      width: 48px; height: 48px; border-radius: 13px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.25rem; color: #fff; flex-shrink: 0;
    }
    .card-icon.bronze { background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)); box-shadow: 0 6px 18px rgba(224, 154, 103, 0.35); }
    .card-icon.orange { background: linear-gradient(135deg, #D97706, #B86B35); box-shadow: 0 6px 18px rgba(217, 119, 6, 0.3); }
    .card-title { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.01em; }
    .card-subtitle { font-size: .84rem; color: var(--text-muted); margin: 3px 0 0; font-weight: 500; }

    /* FORM FIELDS */
    .field-control {
      width: 100%; padding: 13px 16px; border-radius: 12px; border: 1.5px solid var(--border-color);
      background: var(--bg-input) !important; color: var(--text-primary) !important; font-family: inherit;
      font-size: .92rem; transition: all .2s; outline: none;
    }
    .field-control:focus {
      border-color: var(--clr-primary);
      box-shadow: 0 0 0 3px rgba(224, 154, 103, 0.2);
    }
    .field-control:disabled { opacity: .6; cursor: not-allowed; }
    
    .btn-primary {
      width: 100%; padding: 14px 24px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #fff; font-family: inherit; font-size: .95rem; font-weight: 800;
      cursor: pointer; transition: all .25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 6px 20px rgba(224, 154, 103, 0.35);
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(224, 154, 103, 0.5);
    }
    
    .btn-warning {
      width: 100%; padding: 14px 24px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, #D97706, #B86B35);
      color: #fff; font-family: inherit; font-size: .95rem; font-weight: 800;
      cursor: pointer; transition: all .25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 6px 20px rgba(217, 119, 6, 0.3);
    }
    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(217, 119, 6, 0.45);
    }

    .alert {
      padding: 14px 18px; border-radius: 12px; margin-bottom: 24px;
      font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: 10px;
      border: 1px solid transparent;
    }
    .alert-success { background: rgba(16, 185, 129, 0.12); color: #34D399; border-color: rgba(16, 185, 129, 0.3); }
    .alert-danger  { background: rgba(239, 68, 68, 0.12);  color: #F87171; border-color: rgba(239, 68, 68, 0.3); }
    [data-theme="light"] .alert-success { color: #059669; }
    [data-theme="light"] .alert-danger  { color: #DC2626; }
    
    .back-link {
      display: inline-flex; align-items: center; gap: 8px; color: var(--text-muted);
      text-decoration: none; font-weight: 700; font-size: .88rem; margin-bottom: 24px;
      transition: all .2s;
    }
    .back-link:hover { color: var(--clr-primary); transform: translateX(-3px); }
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
      <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-chip" onclick="toggleDropdown()">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['patient_name'] ?? 'P', 0, 1)) ?></div>
        <span class="user-name"><?= sanitize(explode(' ', $_SESSION['patient_name'] ?? 'Patient')[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:.7rem;color:var(--text-muted);margin-left:4px;"></i>
      </div>
      <div class="user-dropdown-menu">
        <a href="settings.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Profile Settings</a>
        <a href="settings.php#password" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
        <div style="height:1px;background:var(--border-color);margin:4px 0;"></div>
        <a href="logout.php" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

  <!-- Alerts -->
  <?php if ($flashMsg): ?>
  <div class="alert alert-<?= $flashType ?>"><i class="fas fa-info-circle"></i> <?= sanitize($flashMsg) ?></div>
  <?php endif; ?>

  <!-- Profile Settings Card -->
  <div class="form-card" id="profile">
    <div class="card-header-flex">
      <div class="card-icon bronze"><i class="fas fa-user"></i></div>
      <div>
        <h2 class="card-title">Profile Settings</h2>
        <p class="card-subtitle">View and update your contact details</p>
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <input type="hidden" name="action" value="update_profile">
      
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-user-circle me-1"></i>Full Name</label>
          <input type="text" class="field-control" value="<?= htmlspecialchars($patient['full_name'] ?? '') ?>" disabled title="Contact the clinic to change your name.">
        </div>
        <div class="col-md-6">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-envelope me-1"></i>Email Address</label>
          <input type="email" class="field-control" value="<?= htmlspecialchars($patient['email'] ?? '') ?>" disabled title="Contact the clinic to change your email.">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-12">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-phone me-1"></i>Phone Number</label>
          <input type="text" name="phone" class="field-control" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>" placeholder="09xxxxxxxxx">
        </div>
      </div>

      <div class="mb-4">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-map-marker-alt me-1"></i>Address</label>
        <textarea name="address" class="field-control" rows="2" placeholder="Your residential address..."><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn-primary"><i class="fas fa-save me-2"></i> Save Profile</button>
    </form>
  </div>

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
        <input type="password" name="new_password" class="field-control" required minlength="6" placeholder="Min. 6 characters">
      </div>

      <div class="mb-4">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-check-double me-1"></i>Confirm Password</label>
        <input type="password" name="confirm_password" class="field-control" required minlength="6" placeholder="Re-enter new password">
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
const savedTheme = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('guecoTheme') || 'dark';

html.setAttribute('data-theme', savedTheme);
if (themeIcon) {
  themeIcon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
}

if (themeBtn) {
  themeBtn.addEventListener('click', () => {
    const current = html.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('gueco_theme', next);
    localStorage.setItem('gueco-theme', next);
    localStorage.setItem('guecoTheme', next);
    if (themeIcon) {
      themeIcon.className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }
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
</script>
</body>
</html>
