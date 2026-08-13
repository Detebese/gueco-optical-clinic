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

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $db->prepare("UPDATE patients SET phone=?, address=? WHERE id=?")->execute([$phone, $address, $patientId]);
    $_SESSION['flash_msg'] = 'Profile updated successfully!';
    $_SESSION['flash_type'] = 'success';
    header('Location: settings.php');
    exit;
}

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confPass = $_POST['confirm_password'] ?? '';
    
    if (empty($currPass) || empty($newPass) || empty($confPass)) {
        $_SESSION['flash_msg'] = 'All fields are required.';
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

    :root { --clr-primary:#2563EB; --clr-secondary:#7C3AED; --clr-success:#059669; --clr-danger:#DC2626; --clr-warning:#D97706; --clr-info:#0EA5E9; }

    [data-theme="dark"]{
      --bg-body:#0F172A; --bg-card:rgba(30,41,59,.8); --bg-hover:rgba(255,255,255,.04);
      --text-primary:#F1F5F9; --text-secondary:#CBD5E1; --text-muted:#64748B;
      --border-color:rgba(255,255,255,.08); --border-light:rgba(255,255,255,.05);
      --shadow-md:0 8px 30px rgba(0,0,0,.35);
    }
    [data-theme="light"]{
      --bg-body:#F0F4FF; --bg-card:rgba(255,255,255,.9); --bg-hover:rgba(0,0,0,.03);
      --text-primary:#0F172A; --text-secondary:#334155; --text-muted:#94A3B8;
      --border-color:rgba(0,0,0,.07); --border-light:rgba(0,0,0,.04);
      --shadow-md:0 8px 30px rgba(37,99,235,.08);
    }

    body { font-family:'Poppins',sans-serif; background:var(--bg-body); min-height:100vh; font-size:16px; color:var(--text-primary); }

    /* BG */
    .bg-mesh {
      position:fixed; inset:0; z-index:0; pointer-events:none;
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(37,99,235,.2) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(124,58,237,.15) 0%, transparent 50%);
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(37,99,235,.1) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(124,58,237,.08) 0%, transparent 50%);
    }

    /* TOPBAR */
    .topbar {
      position:sticky; top:0; z-index:200;
      background:rgba(15,23,42,.85); backdrop-filter:blur(20px);
      border-bottom:1px solid var(--border-color);
      display:flex; align-items:center; justify-content:space-between;
      padding:0 28px; height:64px;
    }
    [data-theme="light"] .topbar { background:rgba(240,244,255,.9); }
    .topbar-brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
    .topbar-logo {
      width:42px; height:42px; object-fit:contain; border-radius:8px;
    }
    .topbar-name { font-weight:700; font-size:1.05rem; color:var(--text-primary); }
    .topbar-sub  { font-size:.75rem; color:var(--text-muted); }
    .topbar-right { display:flex; align-items:center; gap:10px; }
    .theme-btn {
      width:36px; height:36px; border-radius:50%; border:1px solid var(--border-color);
      background:var(--bg-hover); color:var(--text-muted); cursor:pointer;
      display:flex; align-items:center; justify-content:center; font-size:.82rem;
      transition:all .2s;
    }
    .theme-btn:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
    .user-chip {
      display:flex; align-items:center; gap:8px;
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:100px; padding:5px 14px 5px 5px; cursor:pointer; transition:all .2s;
    }
    .user-chip:hover { border-color:var(--clr-primary); }
    .user-avatar {
      width:28px; height:28px; border-radius:50%;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      display:flex; align-items:center; justify-content:center;
      color:#fff; font-weight:700; font-size:.72rem;
    }
    .user-name { font-size:.88rem; font-weight:600; color:var(--text-primary); }
    
    .user-dropdown { position:relative; }
    .user-dropdown-menu {
      position:absolute; top:calc(100% + 10px); right:0; background:var(--bg-card);
      border:1px solid var(--border-color); border-radius:12px;
      box-shadow:0 12px 30px rgba(0,0,0,.25); width:210px; padding:8px;
      display:flex; flex-direction:column; gap:4px;
      opacity:0; visibility:hidden; transform:translateY(-10px);
      transition:all .2s; z-index:100;
    }
    .user-dropdown.open .user-dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
    .dropdown-item {
      padding:10px 14px; border-radius:8px; display:flex; align-items:center; gap:12px;
      color:var(--text-primary); text-decoration:none; font-size:.88rem; font-weight:500;
      background:none; border:none; width:100%; text-align:left; cursor:pointer; transition:background .2s;
    }
    .dropdown-item i { font-size:1.1rem; opacity:.7; width:20px; text-align:center; }
    .dropdown-item:hover { background:var(--bg-hover); color:var(--clr-primary); }
    .dropdown-item.danger:hover { background:rgba(220,38,38,.1); color:#F87171; }

    /* LAYOUT */
    .page-wrap { position:relative; z-index:1; max-width:900px; margin:40px auto; padding:0 24px; }
    
    /* CARDS */
    .form-card {
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:24px; padding:32px; box-shadow:var(--shadow-md);
      backdrop-filter:blur(16px); margin-bottom: 24px;
    }
    .card-header-flex { display:flex; align-items:center; gap:16px; margin-bottom:24px; }
    .card-icon {
      width:48px; height:48px; border-radius:14px;
      display:flex; align-items:center; justify-content:center;
      font-size:1.4rem; color:#fff; flex-shrink:0;
    }
    .card-icon.blue { background:linear-gradient(135deg,var(--clr-primary),#60A5FA); box-shadow:0 8px 20px rgba(37,99,235,.25); }
    .card-icon.orange { background:linear-gradient(135deg,#D97706,#F59E0B); box-shadow:0 8px 20px rgba(217,119,6,.25); }
    .card-title { font-size:1.3rem; font-weight:700; color:var(--text-primary); margin:0; }
    .card-subtitle { font-size:.85rem; color:var(--text-muted); margin:4px 0 0; }

    /* FORM FIELDS */
    .field-control {
      width:100%; padding:14px 16px; border-radius:12px; border:1px solid var(--border-color);
      background:var(--bg-hover) !important; color:var(--text-primary) !important; font-family:inherit;
      font-size:.95rem; transition:all .2s; outline:none;
    }
    .field-control:focus { border-color:var(--clr-primary); background:transparent; box-shadow:0 0 0 4px rgba(37,99,235,.1); }
    .field-control:disabled { opacity:.6; cursor:not-allowed; }
    
    .btn-primary {
      width:100%; padding:14px 24px; border-radius:12px; border:none;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; font-family:'Poppins',sans-serif; font-size:.95rem; font-weight:700;
      cursor:pointer; transition:all .2s; box-shadow:0 8px 20px rgba(37,99,235,.3);
    }
    .btn-primary:hover { transform:translateY(-2px); box-shadow:0 12px 24px rgba(37,99,235,.4); }
    
    .btn-warning {
      width:100%; padding:14px 24px; border-radius:12px; border:none;
      background:linear-gradient(135deg,#D97706,#DC2626);
      color:#fff; font-family:'Poppins',sans-serif; font-size:.95rem; font-weight:700;
      cursor:pointer; transition:all .2s; box-shadow:0 8px 20px rgba(220,38,38,.3);
    }
    .btn-warning:hover { transform:translateY(-2px); box-shadow:0 12px 24px rgba(220,38,38,.4); }

    .alert { padding:14px 18px; border-radius:12px; margin-bottom:24px; font-size:.9rem; font-weight:500; display:flex; align-items:center; gap:10px; border:1px solid transparent; }
    .alert-success { background:rgba(5,150,105,.1); color:#34D399; border-color:rgba(5,150,105,.2); }
    .alert-danger { background:rgba(220,38,38,.1); color:#F87171; border-color:rgba(220,38,38,.2); }
    [data-theme="light"] .alert-success { color:#059669; }
    [data-theme="light"] .alert-danger { color:#DC2626; }
    
    .back-link {
        display:inline-flex; align-items:center; gap:8px; color:var(--text-muted);
        text-decoration:none; font-weight:600; font-size:.9rem; margin-bottom: 24px; transition:color .2s;
    }
    .back-link:hover { color:var(--clr-primary); }
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
    <button class="theme-btn" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-chip" onclick="toggleDropdown()">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['patient_name'],0,1)) ?></div>
        <span class="user-name"><?= sanitize(explode(' ',$_SESSION['patient_name'])[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:.75rem;color:var(--text-muted);margin-left:4px;"></i>
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
      <div class="card-icon blue"><i class="fas fa-user"></i></div>
      <div>
        <h2 class="card-title">Profile Settings</h2>
        <p class="card-subtitle">View and update your contact details</p>
      </div>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="update_profile">
      
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-user-circle me-1"></i>Full Name</label>
          <input type="text" class="field-control" value="<?= htmlspecialchars($patient['full_name']) ?>" disabled title="Contact the clinic to change your name.">
        </div>
        <div class="col-md-6">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-envelope me-1"></i>Email Address</label>
          <input type="email" class="field-control" value="<?= htmlspecialchars($patient['email']) ?>" disabled title="Contact the clinic to change your email.">
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-12">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-phone me-1"></i>Phone Number</label>
          <input type="text" name="phone" class="field-control" value="<?= htmlspecialchars($patient['phone']) ?>">
        </div>
      </div>

      <div class="mb-4">
        <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-map-marker-alt me-1"></i>Address</label>
        <textarea name="address" class="field-control" rows="2"><?= htmlspecialchars($patient['address']) ?></textarea>
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
      <input type="hidden" name="action" value="change_password">
      
      <div class="mb-3">
        <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-lock me-1"></i>Current Password</label>
        <input type="password" name="current_password" class="field-control" required>
      </div>

      <div class="mb-3">
        <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-key me-1"></i>New Password</label>
        <input type="password" name="new_password" class="field-control" required minlength="6">
      </div>

      <div class="mb-4">
        <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-check-double me-1"></i>Confirm Password</label>
        <input type="password" name="confirm_password" class="field-control" required minlength="6">
      </div>

      <button type="submit" class="btn-warning"><i class="fas fa-lock me-2"></i> Update Password</button>
    </form>
  </div>

</div>

<script>
// Theme logic
const savedTheme = localStorage.getItem('guecoTheme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
const themeIcon = document.getElementById('themeIcon');
themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

document.getElementById('themeToggle').addEventListener('click', () => {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('guecoTheme', next);
  themeIcon.className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
});

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
