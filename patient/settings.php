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
        $fullName = sanitize($_POST['full_name'] ?? '');
        $fullName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fullName);
        $fullName = ucwords(strtolower($fullName));
        
        $phone    = sanitize($_POST['phone'] ?? '');
        $gender   = sanitize($_POST['gender'] ?? '');
        $address  = sanitize($_POST['address'] ?? '');
        $bdate    = sanitize($_POST['birthdate'] ?? '');
        $removeAvatar = isset($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1';
        
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (!empty($phone)) {
            if (strlen($cleanPhone) !== 11 || !str_starts_with($cleanPhone, '09')) {
                $_SESSION['flash_msg'] = 'Contact number must be exactly 11 digits starting with 09 (e.g., 09123456789).';
                $_SESSION['flash_type'] = 'danger';
                header('Location: settings.php');
                exit;
            }
        }

        $bdateFormatted = null;
        if (!empty($bdate)) {
            $ts = strtotime($bdate);
            if ($ts !== false) {
                $bdateFormatted = date('Y-m-d', $ts);
            }
        }

        // Defensive check: ensure avatar column exists
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM patients LIKE 'avatar'")->fetch();
            if (!$colCheck) {
                $db->exec("ALTER TABLE patients ADD COLUMN avatar VARCHAR(500) NULL AFTER gender");
            }
        } catch (Exception $e) {}

        // Fetch current avatar
        $currStmt = $db->prepare("SELECT avatar FROM patients WHERE id = ?");
        $currStmt->execute([$patientId]);
        $currentAvatar = $currStmt->fetchColumn() ?: '';

        $avatarUpdated = false;
        $newAvatarVal = $currentAvatar;

        // Handle Avatar Removal
        if ($removeAvatar) {
            if (!empty($currentAvatar) && !str_starts_with($currentAvatar, 'http')) {
                $oldFile = __DIR__ . '/../' . ltrim($currentAvatar, '/');
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $newAvatarVal = null;
            $avatarUpdated = true;
        }

        // Handle Avatar File Upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $fileTmp = $_FILES['avatar']['tmp_name'];
            $fileExt = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $fileSize = $_FILES['avatar']['size'];
            
            $imgInfo = @getimagesize($fileTmp);
            $fileMime = $imgInfo['mime'] ?? '';

            if (!in_array($fileExt, $allowedExts) || !in_array($fileMime, $allowedMimes)) {
                $_SESSION['flash_msg'] = 'Invalid image format. Please upload a JPG, PNG, WEBP, or GIF image.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: settings.php');
                exit;
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $_SESSION['flash_msg'] = 'The selected image is too large. Maximum allowed size is 5MB.';
                $_SESSION['flash_type'] = 'danger';
                header('Location: settings.php');
                exit;
            } else {
                $uploadDir = __DIR__ . '/../assets/images/avatars/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $avatarFilename = 'avatar_pt' . $patientId . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                $destPath = $uploadDir . $avatarFilename;

                if (move_uploaded_file($fileTmp, $destPath)) {
                    // Remove previous local avatar if it exists
                    if (!empty($currentAvatar) && !str_starts_with($currentAvatar, 'http')) {
                        $oldFile = __DIR__ . '/../' . ltrim($currentAvatar, '/');
                        if (file_exists($oldFile) && $oldFile !== $destPath) {
                            @unlink($oldFile);
                        }
                    }
                    $newAvatarVal = 'assets/images/avatars/' . $avatarFilename;
                    $avatarUpdated = true;
                } else {
                    $_SESSION['flash_msg'] = 'Failed to save uploaded picture. Please try again.';
                    $_SESSION['flash_type'] = 'danger';
                    header('Location: settings.php');
                    exit;
                }
            }
        }
        
        try {
            if ($avatarUpdated) {
                $db->prepare("UPDATE patients SET full_name=COALESCE(NULLIF(?,''), full_name), phone=?, gender=?, address=?, birthdate=COALESCE(?, birthdate), avatar=?, updated_at=NOW() WHERE id=?")
                   ->execute([$fullName, $cleanPhone, $gender, $address, $bdateFormatted, $newAvatarVal, $patientId]);
                $_SESSION['patient_avatar'] = $newAvatarVal ?: '';
            } else {
                $db->prepare("UPDATE patients SET full_name=COALESCE(NULLIF(?,''), full_name), phone=?, gender=?, address=?, birthdate=COALESCE(?, birthdate), updated_at=NOW() WHERE id=?")
                   ->execute([$fullName, $cleanPhone, $gender, $address, $bdateFormatted, $patientId]);
            }
            
            if (!empty($fullName)) {
                $_SESSION['patient_name'] = $fullName;
            }
            
            $_SESSION['flash_msg'] = $avatarUpdated ? 'Profile and photo updated successfully!' : 'Profile updated successfully!';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            error_log("Failed updating patient profile in settings: " . $e->getMessage());
            $_SESSION['flash_msg'] = 'Failed to update profile. Please check your details.';
            $_SESSION['flash_type'] = 'danger';
        }
        header('Location: settings.php');
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
  <title>Profile Settings — Gueco Optical Clinic</title>
  
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
      cursor: text !important;
      caret-color: auto !important;
    }

    select {
      -webkit-user-select: auto !important;
      -moz-user-select: auto !important;
      -ms-user-select: auto !important;
      user-select: auto !important;
      cursor: pointer !important;
    }

    /* Password Security Requirements Indicator Box */
    .pass-req-box {
      margin-top: 8px;
      margin-bottom: 16px;
      padding: 10px 14px;
      border-radius: 14px;
      background: rgba(35, 94, 174, 0.1);
      border: 1px solid rgba(0, 173, 239, 0.25);
      font-size: 0.82rem;
      transition: all 0.25s ease;
    }
    [data-theme="light"] .pass-req-box {
      background: #F8FAFC;
      border: 1px solid #CBD5E1;
    }
    .pass-req-header {
      font-weight: 800;
      color: #38BDF8;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.82rem;
    }
    [data-theme="light"] .pass-req-header {
      color: #0284C7;
    }
    .pass-req-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 14px;
    }
    @media (max-width: 480px) {
      .pass-req-grid {
        grid-template-columns: 1fr;
      }
    }
    .pass-req-item {
      display: flex;
      align-items: center;
      gap: 7px;
      font-weight: 600;
      color: #94A3B8;
      font-size: 0.8rem;
      transition: all 0.2s ease;
    }
    [data-theme="light"] .pass-req-item {
      color: #64748B;
    }
    .pass-req-item i {
      font-size: 0.85rem;
      color: #94A3B8;
      transition: all 0.2s ease;
    }
    [data-theme="light"] .pass-req-item i {
      color: #94A3B8;
    }
    .pass-req-item.valid {
      color: #10B981 !important;
    }
    .pass-req-item.valid i {
      color: #10B981 !important;
      transform: scale(1.08);
    }

    /* SweetAlert2 Overrides */
    .swal2-container { z-index: 200000 !important; backdrop-filter: blur(8px) !important; -webkit-backdrop-filter: blur(8px) !important; }
    .swal2-popup.patient-swal-popup {
      border-radius: 22px !important; font-family: 'Plus Jakarta Sans', sans-serif !important;
      padding: 28px 24px 24px !important; border: 1.5px solid var(--border-color) !important;
      background: var(--bg-card) !important; color: var(--text-primary) !important;
      box-shadow: 0 25px 60px -8px rgba(0, 0, 0, 0.4) !important;
    }
    [data-theme="dark"] .swal2-popup.patient-swal-popup {
      background: #162238 !important; border: 1.5px solid rgba(56, 189, 248, 0.3) !important;
      box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.9), 0 0 35px rgba(56, 189, 248, 0.15) !important;
    }
    .patient-swal-popup .swal2-title { font-size: 1.35rem !important; font-weight: 900 !important; color: var(--text-primary) !important; }
    [data-theme="dark"] .patient-swal-popup .swal2-title { color: #FFFFFF !important; }
    .patient-swal-popup .swal2-html-container { font-size: .92rem !important; color: var(--text-secondary) !important; margin: 6px 0 18px !important; }
    [data-theme="dark"] .patient-swal-popup .swal2-html-container { color: #CBD5E1 !important; }
    .patient-swal-popup .swal2-confirm {
      border-radius: 12px !important; padding: 12px 28px !important; font-size: .88rem !important;
      font-weight: 800 !important; border: none !important;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)) !important;
      color: #FFFFFF !important; box-shadow: 0 4px 0 #0369A1 !important;
    }

    :root { 
      /* Blue Luxury Brand Color Tokens */
      --clr-bronze-light: #27AAE2;
      --clr-bronze:       #235EAE;
      --clr-bronze-dark:  #272264;
      --clr-gold:         #00ADEF;
      --clr-amber:        #1E74BD;

      --clr-primary:      #235EAE;
      --clr-primary-light:#00ADEF;
      --clr-primary-dark: #272264;
      --clr-secondary:    #00ADEF;
      
      --clr-success:      #10B981;
      --clr-danger:       #EF4444;
      --clr-warning:      #268FC8;
      --clr-info:         #00ADEF;
    }

    [data-theme="dark"] {
      --bg-body:          #0A0A0D;
      --bg-card:          #13162B;
      --bg-card-glass:    rgba(19, 22, 43, 0.85);
      --bg-topbar:        rgba(10, 10, 13, 0.88);
      --bg-hover:         rgba(0, 173, 239, 0.08);
      --bg-input:         #1A1D36;
      --bg-input-focus:   #23274A;

      --text-primary:     #F9FAFB;
      --text-secondary:   #E5E7EB;
      --text-muted:       #9CA3AF;
      --text-subtle:      #6B7280;

      --border-color:     rgba(255, 255, 255, 0.09);
      --border-light:     rgba(255, 255, 255, 0.05);
      --border-glow:      rgba(0, 173, 239, 0.35);

      --shadow-md:        0 12px 36px rgba(0, 0, 0, 0.45);
      --shadow-card:      0 8px 32px rgba(0, 0, 0, 0.35);
    }

    [data-theme="light"] {
      --bg-body:          #F0F4F9;
      --bg-card:          #FFFFFF;
      --bg-card-glass:    rgba(255, 255, 255, 0.92);
      --bg-topbar:        rgba(240, 244, 249, 0.90);
      --bg-hover:         #E0EBF7;
      --bg-input:         #E5EEF8;
      --bg-input-focus:   #FFFFFF;

      --text-primary:     #17161D;
      --text-secondary:   #3B3944;
      --text-muted:       #6B7280;
      --text-subtle:      #9CA3AF;

      --border-color:     rgba(0, 0, 0, 0.08);
      --border-light:     rgba(0, 0, 0, 0.04);
      --border-glow:      rgba(35, 94, 174, 0.25);

      --shadow-md:        0 12px 36px rgba(35, 94, 174, 0.08);
      --shadow-card:      0 8px 30px rgba(0, 0, 0, 0.05);
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      line-height: 1.6;
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* Luxury Background Mesh */
    .bg-mesh {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(0, 173, 239, 0.12) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(35, 94, 174, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(39, 170, 226, 0.03) 0%, transparent 60%);
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(0, 173, 239, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(35, 94, 174, 0.05) 0%, transparent 50%);
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
      box-shadow: 0 2px 10px rgba(0, 173, 239, 0.2);
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
      transform: scale(1.05); box-shadow: 0 0 15px rgba(0, 173, 239, 0.25);
    }

    .user-chip {
      display: flex; align-items: center; gap: 8px;
      background: var(--bg-card); border: 1px solid var(--border-color);
      border-radius: 100px; padding: 5px 14px 5px 5px; cursor: pointer;
      transition: all .2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .user-chip:hover { border-color: var(--clr-primary); box-shadow: 0 0 12px rgba(0, 173, 239, 0.2); }
    .user-avatar {
      width: 30px; height: 30px; border-radius: 50%;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-weight: 800; font-size: .76rem;
      box-shadow: 0 2px 8px rgba(35, 94, 174, 0.35);
      overflow: hidden;
      flex-shrink: 0;
    }
    .user-avatar img {
      width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 50%;
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
    .card-icon.bronze { background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)); box-shadow: 0 6px 18px rgba(35, 94, 174, 0.35); }
    .card-icon.orange { background: linear-gradient(135deg, #1E74BD, #272264); box-shadow: 0 6px 18px rgba(30, 116, 189, 0.3); }
    .card-title { font-size: 1.25rem; font-weight: 800; color: var(--text-primary); margin: 0; letter-spacing: -0.01em; }
    .card-subtitle { font-size: .84rem; color: var(--text-muted); margin: 3px 0 0; font-weight: 500; }

    /* PROFILE AVATAR SECTION */
    .profile-avatar-section {
      display: flex;
      align-items: center;
      gap: 24px;
      padding: 22px 24px;
      background: var(--bg-hover);
      border: 1.5px solid var(--border-color);
      border-radius: 18px;
      margin-bottom: 26px;
      transition: all 0.25s ease;
    }
    .profile-avatar-preview-box {
      position: relative;
      width: 96px;
      height: 96px;
      border-radius: 50%;
      flex-shrink: 0;
      box-shadow: 0 8px 24px rgba(35, 94, 174, 0.25);
      border: 3px solid var(--bg-card);
    }
    .profile-avatar-img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
      display: block;
    }
    .profile-avatar-initials {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #FFFFFF;
      font-size: 2.2rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      text-transform: uppercase;
      user-select: none;
    }
    .avatar-camera-btn {
      position: absolute;
      bottom: -2px;
      right: -2px;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: linear-gradient(135deg, #00ADEF, #235EAE);
      color: #FFFFFF !important;
      border: 2.5px solid var(--bg-card);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.82rem;
      cursor: pointer !important;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s;
    }
    .avatar-camera-btn:hover {
      transform: scale(1.12);
      background: linear-gradient(135deg, #0284C7, #1D4ED8);
      color: #FFFFFF !important;
    }
    .profile-avatar-meta {
      flex: 1;
      min-width: 0;
    }
    .profile-avatar-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-primary);
      margin-bottom: 8px;
    }
    .profile-avatar-actions {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .btn-avatar-upload {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 9px 18px;
      border-radius: 100px;
      font-size: 0.82rem;
      font-weight: 800;
      color: #FFFFFF !important;
      background: linear-gradient(135deg, #00ADEF, #235EAE);
      border: none;
      cursor: pointer !important;
      box-shadow: 0 4px 14px rgba(0, 173, 239, 0.3);
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .btn-avatar-upload:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(0, 173, 239, 0.45);
      color: #FFFFFF !important;
    }
    .btn-avatar-remove {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 100px;
      font-size: 0.82rem;
      font-weight: 700;
      color: #EF4444;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.25);
      cursor: pointer !important;
      transition: all 0.2s ease;
    }
    .btn-avatar-remove:hover {
      background: #EF4444;
      color: #FFFFFF !important;
      border-color: #EF4444;
      transform: translateY(-1px);
    }
    .avatar-file-feedback {
      margin-top: 10px;
      font-size: 0.78rem;
      font-weight: 700;
      padding: 6px 12px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .avatar-file-feedback.ready {
      background: rgba(16, 185, 129, 0.12);
      color: #10B981;
      border: 1px solid rgba(16, 185, 129, 0.25);
    }
    .avatar-file-feedback.remove {
      background: rgba(245, 158, 11, 0.12);
      color: #F59E0B;
      border: 1px solid rgba(245, 158, 11, 0.25);
    }

    /* FORM FIELDS */
    .field-control {
      width: 100%; padding: 13px 16px; border-radius: 12px; border: 1.5px solid var(--border-color);
      background: var(--bg-input) !important; color: var(--text-primary) !important; font-family: inherit;
      font-size: .92rem; transition: all .2s; outline: none;
    }
    .field-control:focus {
      border-color: var(--clr-primary);
      box-shadow: 0 0 0 3px rgba(0, 173, 239, 0.2);
    }
    .field-control:disabled { opacity: .6; cursor: not-allowed; }
    
    .btn-primary {
      width: 100%; padding: 14px 24px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #fff; font-family: inherit; font-size: .95rem; font-weight: 800;
      cursor: pointer; transition: all .25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 6px 20px rgba(35, 94, 174, 0.35);
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(0, 173, 239, 0.5);
    }
    
    .btn-warning {
      width: 100%; padding: 14px 24px; border-radius: 12px; border: none;
      background: linear-gradient(135deg, #1E74BD, #272264);
      color: #fff; font-family: inherit; font-size: .95rem; font-weight: 800;
      cursor: pointer; transition: all .25s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 6px 20px rgba(30, 116, 189, 0.3);
    }
    .btn-warning:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(30, 116, 189, 0.45);
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

    @media (max-width: 640px) {
      .page-wrap { padding: 16px 12px 60px; }
      .topbar { padding: 0 14px; height: 60px; }
      .topbar-brand { gap: 10px; }
      .topbar-logo { width: 36px; height: 36px; border-radius: 8px; }
      .topbar-name { font-size: .92rem; }
      .topbar-sub { display: none; }
      .user-chip { padding: 3px 10px 3px 3px; }
      .user-name { font-size: .82rem; }
      .form-card { padding: 20px 16px; border-radius: 18px; margin-bottom: 20px; }
      .card-header-flex { gap: 12px; margin-bottom: 20px; }
      .card-icon { width: 42px; height: 42px; font-size: 1.05rem; border-radius: 12px; }
      .card-title { font-size: 1.15rem; }
      .card-subtitle { font-size: .8rem; }
      .field-control { padding: 11px 14px; font-size: .88rem; border-radius: 12px; }
      .btn-primary, .btn-warning { width: 100%; justify-content: center; padding: 12px 18px; }
      .pass-req-box { padding: 10px 12px; border-radius: 12px; }
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
        <div class="user-avatar">
          <?php 
            $navAvatar = $patient['avatar'] ?? ($_SESSION['patient_avatar'] ?? '');
            if (!empty($navAvatar)): 
              $navSrc = str_starts_with($navAvatar, 'http') ? $navAvatar : (BASE_URL . ltrim($navAvatar, '/'));
          ?>
            <img src="<?= htmlspecialchars($navSrc) ?>" alt="Avatar">
          <?php else: ?>
            <?= strtoupper(substr($patient['full_name'] ?? ($_SESSION['patient_name'] ?? 'P'), 0, 1)) ?>
          <?php endif; ?>
        </div>
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

  <!-- Profile Settings Card -->
  <div class="form-card" id="profile">
    <div class="card-header-flex">
      <div class="card-icon bronze"><i class="fas fa-user"></i></div>
      <div>
        <h2 class="card-title">Profile Settings</h2>
        <p class="card-subtitle">View and update your contact details and profile picture</p>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <input type="hidden" name="action" value="update_profile">
      <input type="hidden" name="remove_avatar" id="removeAvatarInput" value="0">
      
      <!-- Profile Picture Upload Section -->
      <div class="profile-avatar-section">
        <div class="profile-avatar-preview-box">
          <?php 
            $hasAvatar = !empty($patient['avatar']);
            $avatarSrc = '';
            if ($hasAvatar) {
                $avatarSrc = str_starts_with($patient['avatar'], 'http') ? $patient['avatar'] : (BASE_URL . ltrim($patient['avatar'], '/'));
            }
          ?>
          <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Profile Picture" id="avatarImgPreview" class="profile-avatar-img <?= $hasAvatar ? '' : 'd-none' ?>">
          <div id="avatarInitialsFallback" class="profile-avatar-initials <?= $hasAvatar ? 'd-none' : '' ?>">
            <?= strtoupper(substr($patient['full_name'] ?? ($_SESSION['patient_name'] ?? 'P'), 0, 1)) ?>
          </div>
          
          <button type="button" class="avatar-camera-btn" onclick="document.getElementById('avatarInput').click()" title="Choose new profile photo">
            <i class="fas fa-camera"></i>
          </button>
        </div>

        <div class="profile-avatar-meta">
          <h4 class="profile-avatar-title" style="margin-bottom:12px;">Profile Photo</h4>
          <div class="profile-avatar-actions">
            <button type="button" class="btn-avatar-upload" onclick="document.getElementById('avatarInput').click()">
              <i class="fas fa-upload me-1"></i> Choose New Photo
            </button>
            <input type="file" name="avatar" id="avatarInput" accept="image/png, image/jpeg, image/jpg, image/webp, image/gif" style="display:none;" onchange="previewAvatar(event)">
            
            <button type="button" class="btn-avatar-remove <?= $hasAvatar ? '' : 'd-none' ?>" id="btnRemoveAvatar" onclick="handleRemoveAvatar()">
              <i class="fas fa-trash-alt me-1"></i> Remove Photo
            </button>
          </div>
          <div id="avatarFileFeedback" class="avatar-file-feedback d-none"></div>
        </div>
      </div>
      
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
        <div class="col-md-6">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-phone me-1"></i>Contact Number</label>
          <input type="tel" name="phone" inputmode="numeric" maxlength="11" minlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)" pattern="^09[0-9]{9}$" class="field-control" value="<?= htmlspecialchars($patient['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
        </div>
        <div class="col-md-6">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-venus-mars me-1"></i>Biological Sex / Gender</label>
          <select name="gender" class="field-control">
            <option value="">Select</option>
            <option value="male" <?= ($patient['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($patient['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($patient['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
          </select>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-12">
          <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-birthday-cake me-1"></i>Birthdate</label>
          <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>" class="field-control" value="<?= htmlspecialchars($patient['birthdate'] ?? '') ?>">
        </div>
      </div>

      <div class="mb-4">
        <label style="display:block;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-map-marker-alt me-1"></i>Address</label>
        <textarea name="address" class="field-control" rows="2" placeholder="Your residential address..."><?= htmlspecialchars($patient['address'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn-primary"><i class="fas fa-save me-2"></i> Save Profile</button>
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

// Avatar Preview & Removal Handler
function previewAvatar(event) {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  // Validate type
  const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  if (!validTypes.includes(file.type)) {
    showPopupModal('Please choose a valid image file (JPG, PNG, WEBP, or GIF).', 'danger', 'Invalid Format');
    event.target.value = '';
    return;
  }

  // Validate size (5MB)
  if (file.size > 5 * 1024 * 1024) {
    showPopupModal('The selected image is larger than 5MB. Please choose a smaller photo.', 'danger', 'File Too Large');
    event.target.value = '';
    return;
  }

  const reader = new FileReader();
  reader.onload = function(e) {
    const previewImg = document.getElementById('avatarImgPreview');
    const initials = document.getElementById('avatarInitialsFallback');
    const removeBtn = document.getElementById('btnRemoveAvatar');
    const feedback = document.getElementById('avatarFileFeedback');
    const removeInput = document.getElementById('removeAvatarInput');

    if (previewImg) {
      previewImg.src = e.target.result;
      previewImg.classList.remove('d-none');
    }
    if (initials) {
      initials.classList.add('d-none');
    }
    if (removeBtn) {
      removeBtn.classList.remove('d-none');
    }
    if (removeInput) {
      removeInput.value = '0';
    }
    if (feedback) {
      const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
      feedback.className = 'avatar-file-feedback ready';
      feedback.innerHTML = '<i class="fas fa-check-circle"></i> ' + file.name + ' (' + sizeMB + ' MB) — Ready to save!';
      feedback.classList.remove('d-none');
    }
  };
  reader.readAsDataURL(file);
}

function handleRemoveAvatar() {
  const fileInput = document.getElementById('avatarInput');
  const previewImg = document.getElementById('avatarImgPreview');
  const initials = document.getElementById('avatarInitialsFallback');
  const removeBtn = document.getElementById('btnRemoveAvatar');
  const feedback = document.getElementById('avatarFileFeedback');
  const removeInput = document.getElementById('removeAvatarInput');

  if (fileInput) fileInput.value = '';
  if (previewImg) {
    previewImg.src = '';
    previewImg.classList.add('d-none');
  }
  if (initials) {
    initials.classList.remove('d-none');
  }
  if (removeBtn) {
    removeBtn.classList.add('d-none');
  }
  if (removeInput) {
    removeInput.value = '1';
  }
  if (feedback) {
    feedback.className = 'avatar-file-feedback remove';
    feedback.innerHTML = '<i class="fas fa-info-circle"></i> Photo marked for removal. Click "Save Profile" to apply.';
    feedback.classList.remove('d-none');
  }
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
</script>
</body>
</html>
