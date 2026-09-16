<?php
// index.php — landing page
define('BASE_URL', '');
require_once 'config/functions.php';
startSession();

$isPatient = isPatientLoggedIn();
$isStaff = isLoggedIn();

$error = '';
$errorField = '';
$tab   = $_GET['tab'] ?? 'login'; // 'login' or 'register'
$showModal = false;

if (isset($_GET['registered'])) {
    $showModal = true;
    $tab = 'login';
}

// ── REGISTER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $showModal = true;
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
        $tab   = 'register';
    } else {
        $fullName  = sanitize(trim($_POST['full_name'] ?? ''));
        // Fix camelCase names: "JuanDelaCruz" -> "Juan Dela Cruz"
        $fullName  = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fullName);
        $fullName  = ucwords(strtolower($fullName)); // ensure proper casing
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';
        $phone     = sanitize(trim($_POST['phone'] ?? ''));
        $address   = sanitize(trim($_POST['address'] ?? ''));
        $birthdate = $_POST['birthdate'] ?? '';
        $gender    = $_POST['gender'] ?? '';
        $consent   = !empty($_POST['privacy_consent']);

        if (empty($fullName)) {
            $error = 'Please enter your full name.';
            $errorField = 'full_name';
            $tab   = 'register';
        } elseif (empty($email)) {
            $error = 'Please enter your email.';
            $errorField = 'email';
            $tab   = 'register';
        } elseif (empty($password)) {
            $error = 'Please enter a password.';
            $errorField = 'password';
            $tab   = 'register';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            $errorField = 'email';
            $tab   = 'register';
        } elseif (!empty($phone) && strlen($phone) !== 11) {
            $error = 'Phone number must be exactly 11 digits.';
            $errorField = 'phone';
            $tab   = 'register';
        } elseif (!empty($birthdate) && strtotime($birthdate) > time()) {
            $error = 'Birthdate cannot be in the future.';
            $errorField = 'birthdate';
            $tab   = 'register';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
            $errorField = 'password';
            $tab   = 'register';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
            $errorField = 'confirm_password';
            $tab   = 'register';
        } elseif (!$consent) {
            $error = 'You must agree to the Data Privacy Notice (RA 10173) to create an account.';
            $errorField = 'privacy_consent';
            $tab   = 'register';
        } else {
            try {
                $db   = getDB();
                $check = $db->prepare("SELECT id FROM patients WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $error = 'This email is already registered. Please login instead.';
                    $errorField = 'email';
                    $tab   = 'register';
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO patients (full_name, email, password, phone, address, birthdate, gender)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $fullName,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $phone,
                        $address,
                        $birthdate ?: null,
                        $gender ?: null,
                    ]);
                    $patientId = $db->lastInsertId();

                    $_SESSION['flash_msg']  = 'Account successfully created! You can now log in.';
                    $_SESSION['flash_type'] = 'success';
                    header('Location: index.php?registered=1');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Registration failed. Please try again.';
                $tab   = 'register';
            }
        }
    }
}

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $showModal = true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlKey = 'patient_login_' . $ip;

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
    } elseif (!checkRateLimit($rlKey, 5, 900)) {
        $remaining = ceil(getRateLimitRemainingSeconds($rlKey) / 60);
        $error = "Too many failed attempts. Please wait {$remaining} minute(s) before trying again.";
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            try {
                $db   = getDB();
                $stmt = $db->prepare("SELECT * FROM patients WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();

                if ($patient && password_verify($password, $patient['password'])) {
                    clearRateLimit($rlKey);
                    session_regenerate_id(true);

                    $_SESSION['patient_id']    = $patient['id'];
                    $_SESSION['patient_name']  = $patient['full_name'];
                    $_SESSION['patient_email'] = $patient['email'];

                    header('Location: patient/dashboard.php');
                    exit;
                } else {
                    recordFailedAttempt($rlKey, 900);
                    $error = 'Invalid email or password.';
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again.';
            }
        }
    }
}

// ── FORGOT PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $showModal = true;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlKey = 'patient_otp_req_' . $ip;

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'forgot';
    } elseif (!checkRateLimit($rlKey, 3, 900)) {
        $remaining = ceil(getRateLimitRemainingSeconds($rlKey) / 60);
        $error = "Too many OTP requests. Please wait {$remaining} minute(s) before requesting again.";
        $tab = 'forgot';
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email.';
            $tab = 'forgot';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT id FROM patients WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();
                
                if ($patient) {
                    recordFailedAttempt($rlKey, 900);
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    
                    $update = $db->prepare("UPDATE patients SET reset_otp_hash = ?, reset_expires = ? WHERE id = ?");
                    $update->execute([$otpHash, $expires, $patient['id']]);
                    
                    sendEmailOTP($email, $otp);
                    
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['flash_msg'] = 'OTP sent to your email.';
                    $_SESSION['flash_type'] = 'success';
                    
                    $tab = 'otp';
                } else {
                    $error = 'Email not found or inactive.';
                    $tab = 'forgot';
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again.';
                $tab = 'forgot';
            }
        }
    }
}

// ── VERIFY OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'otp') {
    $showModal = true;
    $otp = trim($_POST['otp'] ?? '');
    $email = $_SESSION['reset_email'] ?? '';
    $otpKey = 'otp_verify_' . md5($email ?: 'guest');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'otp';
    } elseif (!checkRateLimit($otpKey, 4, 900)) {
        if ($email) {
            $db = getDB();
            $db->prepare("UPDATE patients SET reset_otp_hash = NULL, reset_expires = NULL WHERE email = ?")->execute([$email]);
        }
        $error = 'Too many failed OTP attempts. For your security, this OTP has been invalidated. Please request a new one.';
        $tab = 'forgot';
    } elseif (empty($otp)) {
        $error = 'Please enter the OTP.';
        $tab = 'otp';
    } elseif (empty($email)) {
        $error = 'Session expired. Please try again.';
        $tab = 'forgot';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, reset_otp_hash, reset_expires FROM patients WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $patient = $stmt->fetch();
            
            if ($patient && $patient['reset_expires'] > date('Y-m-d H:i:s')) {
                if (password_verify($otp, $patient['reset_otp_hash'])) {
                    // OTP is valid
                    clearRateLimit($otpKey);
                    $_SESSION['reset_verified'] = true;
                    $tab = 'new-password';
                } else {
                    recordFailedAttempt($otpKey, 900);
                    $error = 'Invalid OTP. Please try again.';
                    $tab = 'otp';
                }
            } else {
                $error = 'OTP expired or invalid.';
                $tab = 'forgot';
            }
        } catch (Exception $e) {
            $error = 'System error. Please try again.';
            $tab = 'otp';
        }
    }
}

// ── NEW PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new-password') {
    $showModal = true;
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
        $tab = 'new-password';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $email = $_SESSION['reset_email'] ?? '';
        
        if (empty($_SESSION['reset_verified'])) {
            $error = 'Please verify OTP first.';
            $tab = 'forgot';
        } elseif (empty($password)) {
            $error = 'Please enter a new password.';
            $tab = 'new-password';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
            $tab = 'new-password';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
            $tab = 'new-password';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT id FROM patients WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $patient = $stmt->fetch();
                
                if ($patient) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $update = $db->prepare("UPDATE patients SET password = ?, reset_otp_hash = NULL, reset_expires = NULL WHERE id = ?");
                    $update->execute([$hash, $patient['id']]);
                    
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_verified']);
                    
                    $_SESSION['flash_msg'] = 'Password reset successful. Please login.';
                    $_SESSION['flash_type'] = 'success';
                    
                    $tab = 'login';
                } else {
                    $error = 'Account not found.';
                    $tab = 'forgot';
                }
            } catch (Exception $e) {
                $error = 'System error. Please try again.';
                $tab = 'new-password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gueco Optical Clinic — Vision Care Center</title>
  <meta name="description" content="Book appointments and access professional eye care at Gueco Optical Clinic, Capas, Tarlac.">
  
  <!-- Immediate Theme Initialization -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem("gueco_theme") || localStorage.getItem("gueco-theme") || localStorage.getItem("theme") || "dark";
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
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    :root { 
      /* Luxury Brand Color Palette */
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

      /* Dark Theme */
      --bg-body:          #0A0A0D;
      --bg-card:          #17161D;
      --bg-card-glass:    rgba(23, 22, 29, 0.88);
      --bg-topbar:        rgba(10, 10, 13, 0.85);
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

      --shadow-sm:        0 2px 8px rgba(0, 0, 0, 0.45);
      --shadow-md:        0 8px 24px rgba(0, 0, 0, 0.55);
      --shadow-lg:        0 16px 36px rgba(0, 0, 0, 0.65);
      --shadow-xl:        0 28px 60px rgba(0, 0, 0, 0.8);
    }
    [data-theme="light"] {
      /* Warm Alabaster Canvas */
      --bg-body:          #F3F1EC;
      --bg-card:          #FFFFFF;
      --bg-card-glass:    rgba(255, 255, 255, 0.95);
      --bg-topbar:        rgba(255, 255, 255, 0.9);
      --bg-hover:         #E8E4DC;
      --bg-input:         #EBE7E0;
      --bg-input-focus:   #FFFFFF;

      --text-primary:     #18181B;
      --text-secondary:   #52525B;
      --text-muted:       #71717A;
      --text-subtle:      #A1A1AA;

      --border-color:     rgba(0, 0, 0, 0.08);
      --border-light:     rgba(0, 0, 0, 0.04);
      --border-glow:      rgba(224, 154, 103, 0.35);

      --shadow-sm:        0 2px 6px rgba(0, 0, 0, 0.03);
      --shadow-md:        0 8px 24px rgba(0, 0, 0, 0.06);
      --shadow-lg:        0 16px 36px rgba(0, 0, 0, 0.08);
      --shadow-xl:        0 24px 48px rgba(0, 0, 0, 0.12);
    }

    body { 
      font-family:'Plus Jakarta Sans','Poppins',sans-serif; background:var(--bg-body); 
      color:var(--text-primary); min-height:100vh; font-size:16px;
      overflow-x:hidden; transition: background 0.25s ease, color 0.25s ease;
    }

    .bg-mesh {
      position:fixed; inset:0; z-index:-1; pointer-events:none;
      background:
        radial-gradient(ellipse 70% 60% at 0% 0%, rgba(224,154,103,.14) 0%, transparent 60%),
        radial-gradient(ellipse 50% 50% at 100% 100%, rgba(194,99,37,.1) 0%, transparent 60%);
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 70% 60% at 0% 0%, rgba(224,154,103,.1) 0%, transparent 60%),
        radial-gradient(ellipse 50% 50% at 100% 100%, rgba(194,99,37,.06) 0%, transparent 60%);
    }

    /* TOPBAR */
    .topbar {
      position:fixed; top:0; width:100%; z-index:200;
      background:var(--bg-topbar); backdrop-filter:blur(20px);
      border-bottom:1px solid var(--border-color);
      display:flex; align-items:center; justify-content:space-between;
      padding:0 28px; height:80px;
    }
    .topbar-brand { display:flex; align-items:center; gap:16px; text-decoration:none; }
    .topbar-logo {
      width:52px; height:52px; object-fit:contain; border-radius:50%;
      background:rgba(255,255,255,0.95); padding:2px; box-shadow:0 2px 10px rgba(224,154,103,0.35);
    }
    .topbar-name { font-weight:800; font-size:1.3rem; color:var(--text-primary); line-height:1.2; letter-spacing:-0.3px; }
    .topbar-sub  { font-size:.85rem; color:var(--text-muted); font-weight:500; }
    
    .nav-links a {
      color:var(--text-secondary); text-decoration:none; font-weight:600; font-size:.95rem; margin-left:24px; transition:color .2s;
    }
    .nav-links a:hover { color:var(--clr-primary); }
    
    .theme-btn {
      width:38px; height:38px; border-radius:50%; border:1px solid var(--border-color);
      background:rgba(255,255,255,.05); color:var(--text-muted); cursor:pointer;
      display:inline-flex; align-items:center; justify-content:center; font-size:.88rem;
      transition:all .2s; margin-left:24px; vertical-align:middle;
    }
    [data-theme="light"] .theme-btn { background:rgba(0,0,0,.04); }
    .theme-btn:hover { border-color:var(--clr-primary); color:var(--clr-primary); transform:scale(1.05); }

    /* HERO */
    .hero {
      padding:160px 24px 100px; max-width:1200px; margin:0 auto; display:flex; align-items:center; gap:60px;
    }
    .hero-text { flex:1; }
    .badge-est {
      display:inline-flex; align-items:center; gap:8px; padding:8px 18px;
      background:rgba(224,154,103,.14); color:#E09A67; border-radius:100px;
      font-size:.85rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
      margin-bottom:24px; border:1px solid rgba(224,154,103,.35); box-shadow:0 2px 10px rgba(224,154,103,.12);
    }
    .hero h1 { font-size:3.8rem; font-weight:900; line-height:1.15; margin-bottom:24px; color:var(--text-primary); letter-spacing:-1px; }
    .hero h1 span {
      background:linear-gradient(135deg,#FDBA74 0%,#E09A67 50%,#C26325 100%);
      -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    }
    .hero p { font-size:1.15rem; color:var(--text-secondary); line-height:1.7; margin-bottom:40px; max-width:540px; }
    
    .stats { display:flex; gap:40px; }
    .stat-item h3 { font-size:2.2rem; font-weight:800; margin:0; color:var(--clr-primary); }
    .stat-item p { font-size:.9rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; margin:0; }

    .hero-image { flex:1; position:relative; }
    .hero-image img { width:100%; border-radius:32px; box-shadow:0 30px 60px rgba(0,0,0,.4); position:relative; z-index:2; border:1px solid var(--border-color); }
    .hero-image::after {
      content:''; position:absolute; inset:-20px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      filter:blur(60px); opacity:.25; z-index:1; border-radius:50%;
    }

    /* DETAILS */
    .details { max-width:1200px; margin:0 auto 100px; padding:0 24px; display:grid; grid-template-columns:repeat(3, 1fr); gap:30px; }
    .detail-card {
      background:var(--bg-card); border:1px solid var(--border-color);
      padding:40px; border-radius:24px; backdrop-filter:blur(16px);
      transition:transform .3s cubic-bezier(0.16,1,0.3,1), box-shadow .3s cubic-bezier(0.16,1,0.3,1), border-color .3s;
    }
    .detail-card:hover { transform:translateY(-8px); box-shadow:0 20px 40px rgba(0,0,0,.3); border-color:rgba(224,154,103,.4); }
    .detail-icon {
      width:64px; height:64px; border-radius:18px; display:flex; align-items:center; justify-content:center;
      font-size:1.8rem; margin-bottom:24px; color:#fff;
    }
    .icon-bronze { background:linear-gradient(135deg,#E09A67,#FDBA74); box-shadow:0 12px 24px rgba(224,154,103,.35); }
    .icon-gold   { background:linear-gradient(135deg,#D97706,#FBBF24); box-shadow:0 12px 24px rgba(217,119,6,.35); }
    .icon-emerald{ background:linear-gradient(135deg,#059669,#34D399); box-shadow:0 12px 24px rgba(5,150,105,.35); }
    
    .detail-card h3 { font-size:1.3rem; font-weight:700; margin-bottom:12px; }
    .detail-card p { font-size:1rem; color:var(--text-secondary); line-height:1.6; margin:0; }

    /* FLOATING ACTION BUTTON */
    .fab {
      position:fixed; bottom:40px; right:40px; z-index:999;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; padding:18px 32px; border-radius:100px; font-size:1.05rem; font-weight:700;
      display:flex; align-items:center; gap:12px; border:none; cursor:pointer;
      box-shadow:0 16px 36px rgba(224,154,103,.4);
      transition:all .3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      text-decoration:none !important;
    }
    .fab i { font-size:1.3rem; }
    .fab:hover {
      transform:scale(1.05) translateY(-4px); box-shadow:0 24px 50px rgba(224,154,103,.55); color:#fff;
    }

    /* MODAL STYLES */
    .modal-overlay { 
      display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); backdrop-filter:blur(10px);
      z-index:9999; align-items:center; justify-content:center; padding:20px;
    }
    .modal-overlay.open { display:flex !important; }
    .auth-card {
      background:var(--bg-card); border:1px solid rgba(224,154,103,.35); border-radius:24px;
      width:100%; max-width:480px; box-shadow:0 24px 60px rgba(0,0,0,.55), 0 0 25px rgba(224,154,103,.12); overflow:hidden;
      animation:modalIn .3s cubic-bezier(0.16,1,0.3,1); max-height:90vh; overflow-y:auto;
    }
    @keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);} }
    .auth-header {
      padding:22px 26px; display:flex; align-items:center; justify-content:space-between;
      border-bottom:1px solid var(--border-color); background:rgba(224,154,103,.06);
    }
    .auth-title { font-size:1.25rem; font-weight:700; margin:0; color:var(--text-primary); }
    .close-btn {
      background:rgba(255,255,255,.08); border:none; color:var(--text-secondary);
      width:32px; height:32px; border-radius:50%; cursor:pointer; transition:all .2s;
      display:flex; align-items:center; justify-content:center;
    }
    .close-btn:hover { background:var(--clr-danger); color:#fff; transform:rotate(90deg); }
    
    .auth-tabs { display:flex; border-bottom:1px solid var(--border-color); }
    .auth-tab {
      flex:1; padding:16px; text-align:center; cursor:pointer; font-size:.9rem; font-weight:600;
      color:var(--text-muted); background:none; border:none; font-family:inherit; transition:all .2s;
    }
    .auth-tab.active { color:var(--clr-primary); border-bottom:2px solid var(--clr-primary); background:rgba(224,154,103,.06); }
    
    .auth-panel { padding:28px; display:none; }
    .auth-panel.active { display:block; }
    
    .form-group { margin-bottom:16px; }
    .form-label { display:block; font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:7px; }
    .form-control, .form-select {
      width:100%; padding:14px 16px; border-radius:12px; border:1px solid var(--border-color);
      background:var(--bg-input) !important; color:var(--text-primary) !important; font-family:inherit; font-size:.95rem; transition:all .2s;
    }
    .form-control:focus, .form-select:focus { border-color:var(--clr-primary); outline:none; background:var(--bg-input-focus) !important; color:var(--text-primary) !important; box-shadow:0 0 0 4px rgba(224,154,103,.15); }
    .is-invalid { border-color: var(--clr-danger) !important; box-shadow: 0 0 0 4px rgba(239,68,68,.15) !important; }

    /* TOAST NOTIFICATION */
    .toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:10px; }
    .toast {
      background:#fff; color:#333; padding:16px 20px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.15);
      display:flex; align-items:center; gap:12px; min-width:300px; transform:translateX(120%); transition:transform .4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
      border-left:4px solid var(--clr-primary); font-weight:500; font-size:.95rem;
    }
    .toast.show { transform:translateX(0); }
    .toast.danger { border-left-color:var(--clr-danger); }
    .toast.danger i { color:var(--clr-danger); }
    .toast i { font-size:1.2rem; color:var(--clr-primary); }
    [data-theme="dark"] .toast { background:var(--bg-card); color:var(--text-primary); border:1px solid var(--border-color); border-left:4px solid var(--clr-primary); }
    [data-theme="dark"] .toast.danger { border-left-color:var(--clr-danger); }
    
    .btn-primary {
      width:100%; padding:14px; border-radius:12px; border:none;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; font-family:'Plus Jakarta Sans','Poppins',sans-serif; font-size:.95rem; font-weight:700;
      cursor:pointer; transition:all .2s; box-shadow:0 8px 20px rgba(224,154,103,.3);
    }
    .btn-primary:hover { transform:translateY(-2px); box-shadow:0 12px 24px rgba(224,154,103,.45); }
    
    .form-pass-wrap { position:relative; }
    .pass-toggle {
      position:absolute; right:14px; top:50%; transform:translateY(-50%);
      background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:.9rem;
    }

    /* MODERN DATA PRIVACY MODAL */
    .privacy-card {
      background: var(--bg-card);
      border: 1px solid rgba(224,154,103,0.3);
      border-radius: 28px;
      width: 100%;
      max-width: 680px;
      box-shadow: 0 30px 80px rgba(0, 0, 0, 0.55), 0 0 25px rgba(224,154,103,0.12);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      max-height: 88vh;
      backdrop-filter: blur(24px);
      animation: modalIn .3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .privacy-header {
      padding: 24px 28px;
      border-bottom: 1px solid var(--border-color);
      background: linear-gradient(135deg, rgba(224, 154, 103, 0.1), rgba(194, 99, 37, 0.05));
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }
    .privacy-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 1.3rem;
      box-shadow: 0 8px 20px rgba(224, 154, 103, 0.35);
      flex-shrink: 0;
    }
    .privacy-body {
      padding: 24px 28px;
      overflow-y: auto;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 16px;
      scrollbar-width: thin;
      scrollbar-color: rgba(224, 154, 103, 0.5) transparent;
    }
    .privacy-body::-webkit-scrollbar {
      width: 6px;
    }
    .privacy-body::-webkit-scrollbar-thumb {
      background: rgba(224, 154, 103, 0.4);
      border-radius: 10px;
    }
    .privacy-body::-webkit-scrollbar-track {
      background: transparent;
    }
    .privacy-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 100px;
      background: rgba(16, 185, 129, 0.12);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #10B981;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .privacy-section-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 18px 20px;
      transition: all 0.2s ease;
    }
    .privacy-section-card:hover {
      border-color: rgba(224, 154, 103, 0.35);
      background: rgba(224, 154, 103, 0.04);
    }
    .privacy-sec-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 10px;
    }
    .privacy-sec-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      flex-shrink: 0;
    }
    .sec-blue   { background: rgba(224, 154, 103, 0.16); color: #FDBA74; }
    .sec-purple { background: rgba(217, 119, 6, 0.16);   color: #FBBF24; }
    .sec-teal   { background: rgba(14, 165, 233, 0.16);  color: #38BDF8; }
    .sec-amber  { background: rgba(16, 185, 129, 0.16);  color: #34D399; }
    .privacy-sec-title {
      font-size: 0.96rem;
      font-weight: 700;
      color: var(--text-primary);
      margin: 0;
    }
    .privacy-sec-desc {
      font-size: 0.85rem;
      color: var(--text-secondary);
      line-height: 1.6;
      margin: 0 0 10px 0;
    }
    .privacy-pill-group {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .privacy-pill {
      font-size: 0.72rem;
      padding: 4px 10px;
      border-radius: 6px;
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      color: var(--text-muted);
      font-weight: 500;
    }
    .privacy-rights-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 8px;
    }
    @media (max-width: 576px) {
      .privacy-rights-grid {
        grid-template-columns: 1fr;
      }
    }
    .privacy-right-item {
      background: var(--bg-hover);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 0.8rem;
    }
    .privacy-right-title {
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .privacy-right-desc {
      color: var(--text-muted);
      font-size: 0.74rem;
      line-height: 1.4;
      margin: 0;
    }
    .privacy-footer {
      padding: 18px 28px;
      border-top: 1px solid var(--border-color);
      background: rgba(255, 255, 255, 0.02);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-shrink: 0;
    }

    @media(max-width:992px){
      .hero { flex-direction:column; padding-top:120px; text-align:center; }
      .hero p { margin:0 auto 40px; }
      .stats { justify-content:center; }
      .details { grid-template-columns:1fr; }
    }
  </style>
</head>
<body>
<div class="bg-mesh"></div>

<!-- TOPBAR -->
<nav class="topbar">
  <a href="index.php" class="topbar-brand">
    <img src="assets/images/logo.png?v=2" alt="Logo" class="topbar-logo">
    <div>
      <div class="topbar-name">Gueco Optical</div>
      <div class="topbar-sub">Capas, Tarlac</div>
    </div>
  </a>
  <div class="nav-links d-none d-md-flex align-items-center">
    <a href="#about">About Us</a>
    <a href="#services">Services</a>
    <a href="javascript:void(0)" onclick="openPrivacyModal()"><i class="fas fa-shield-alt me-1" style="color:var(--clr-primary)"></i>Data Privacy</a>
    <?php if($isPatient): ?>
      <a href="patient/dashboard.php" style="color:var(--clr-primary); font-weight:700;">My Dashboard</a>
    <?php endif; ?>
    <button class="theme-btn" id="themeToggle" title="Toggle Theme"><i class="fas fa-moon" id="themeIcon"></i></button>
  </div>
  <!-- Mobile quick actions -->
  <div class="d-flex d-md-none align-items-center gap-2">
    <button type="button" onclick="openPrivacyModal()" class="btn btn-sm btn-outline-primary" style="font-size:0.75rem; padding:4px 10px; border-radius:8px; font-weight:600; border-color:var(--clr-primary); color:var(--clr-primary);">
      <i class="fas fa-shield-alt"></i> Privacy
    </button>
    <button class="theme-btn" id="themeToggleMobile" style="margin-left:0;" title="Toggle Theme"><i class="fas fa-moon" id="themeIconMobile"></i></button>
  </div>
</nav>

<!-- HERO SECTION -->
<section class="hero" id="about">
  <div class="hero-text">
    <div class="badge-est"><i class="fas fa-certificate text-warning me-1"></i>Established in 1986</div>
    <h1>See the World <span>Clearly</span> &amp; <span>Beautifully</span></h1>
    <p>Providing exceptional, comprehensive eye care services to the Capas community. We combine state-of-the-art technology with compassionate care to help you achieve your best vision.</p>
    
    <div class="stats">
      <div class="stat-item">
        <h3>40+</h3>
        <p>Years of Service</p>
      </div>
      <div class="stat-item">
        <h3>10k+</h3>
        <p>Happy Patients</p>
      </div>
      <div class="stat-item">
        <h3>100%</h3>
        <p>Commitment</p>
      </div>
    </div>
  </div>
  <div class="hero-image">
    <!-- Luxury Vision Care Card -->
    <div style="width:100%;aspect-ratio:4/3;background:linear-gradient(135deg,var(--bg-card),rgba(10,10,13,0.95));border-radius:32px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(224,154,103,0.3);position:relative;z-index:2;box-shadow:0 30px 60px rgba(0,0,0,.5), 0 0 30px rgba(224,154,103,0.12);overflow:hidden;">
      <i class="fas fa-glasses" style="font-size:8rem;color:rgba(224,154,103,.05);position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);"></i>
      <div style="text-align:center;position:relative;z-index:3;">
        <img src="assets/images/logo.png?v=2" alt="Logo" style="width:120px; height:120px; object-fit:contain; border-radius:50%; margin-bottom:16px; background:rgba(255,255,255,0.95); box-shadow:0 8px 24px rgba(224,154,103,0.35); padding:4px;">
        <h2 style="font-size:2rem;font-weight:800;margin:0;letter-spacing:-0.5px;color:var(--text-primary);">Gueco Optical</h2>
        <p style="color:var(--clr-primary);font-weight:700;text-transform:uppercase;letter-spacing:.2em;margin-top:8px;font-size:0.85rem;">Vision Care Center</p>
      </div>
    </div>
  </div>
</section>

<!-- DETAILS -->
<section class="details" id="services">
  <div class="detail-card">
    <div class="detail-icon icon-bronze"><i class="fas fa-user-md"></i></div>
    <h3>Expert Optometrists</h3>
    <p>Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.</p>
  </div>
  
  <div class="detail-card">
    <div class="detail-icon icon-gold"><i class="fas fa-glasses"></i></div>
    <h3>Premium Eyewear</h3>
    <p>Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.</p>
  </div>
  
  <div class="detail-card">
    <div class="detail-icon icon-emerald"><i class="fas fa-map-marker-alt"></i></div>
    <h3>Convenient Location</h3>
    <p>Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.</p>
  </div>
</section>

<!-- TRUST & DATA PRIVACY BANNER -->
<section style="max-width:1200px; margin:0 auto 80px; padding:0 24px;">
  <div style="background:linear-gradient(135deg,rgba(224,154,103,0.12),rgba(194,99,37,0.06)); border:1px solid rgba(224,154,103,0.3); border-radius:24px; padding:32px 36px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:20px; backdrop-filter:blur(16px); box-shadow:0 12px 30px rgba(0,0,0,0.25);">
    <div style="display:flex; align-items:center; gap:20px; max-width:720px;">
      <div style="width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.6rem; flex-shrink:0; box-shadow:0 8px 20px rgba(224,154,103,0.35);">
        <i class="fas fa-shield-alt"></i>
      </div>
      <div>
        <h4 style="margin:0 0 6px 0; font-weight:800; font-size:1.2rem; color:var(--text-primary);">Data Privacy &amp; Patient Confidentiality</h4>
        <p style="margin:0; font-size:0.92rem; color:var(--text-secondary); line-height:1.5;">
          Gueco Optical Clinic strictly complies with the <strong>Philippine Data Privacy Act of 2012 (Republic Act No. 10173)</strong>. Your medical records, optical prescriptions, and personal info are safe, encrypted, and kept confidential.
        </p>
      </div>
    </div>
    <button type="button" onclick="openPrivacyModal()" class="btn btn-outline-primary" style="padding:12px 24px; border-radius:12px; font-weight:700; font-size:0.92rem; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; border-width:1.5px; border-color:var(--clr-primary); color:var(--clr-primary);">
      <i class="fas fa-file-shield"></i> Read Data Privacy Notice
    </button>
  </div>
</section>

<!-- FOOTER -->
<footer style="border-top:1px solid var(--border-color); background:var(--bg-card); padding:50px 24px 30px; margin-top:60px;">
  <div style="max-width:1200px; margin:0 auto; display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:40px; margin-bottom:40px;">
    <div>
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
        <img src="assets/images/logo.png?v=2" alt="Logo" style="width:44px; height:44px; border-radius:50%; background:#fff; padding:2px; box-shadow:0 2px 10px rgba(224,154,103,0.35);">
        <div>
          <h5 style="margin:0; font-weight:800; font-size:1.15rem; color:var(--text-primary);">Gueco Optical Clinic</h5>
          <small style="color:var(--text-muted); font-size:0.8rem;">Professional Eye Care &amp; Optical Services</small>
        </div>
      </div>
      <p style="font-size:0.88rem; color:var(--text-secondary); line-height:1.6; max-width:400px; margin-bottom:16px;">
        Dedicated to delivering comprehensive, high-quality eye examinations and premium optical eyewear to the Capas, Tarlac community since 1986.
      </p>
      <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(224,154,103,0.12); border:1px solid rgba(224,154,103,0.3); border-radius:8px; padding:6px 12px; font-size:0.8rem; color:var(--clr-primary); font-weight:600;">
        <i class="fas fa-shield-alt"></i> RA 10173 Data Privacy Compliant
      </div>
    </div>
    
    <div>
      <h6 style="font-size:0.88rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:18px;">Quick Links</h6>
      <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px; font-size:0.9rem;">
        <li><a href="#about" style="color:var(--text-secondary); text-decoration:none; transition:color 0.2s;">About Us</a></li>
        <li><a href="#services" style="color:var(--text-secondary); text-decoration:none; transition:color 0.2s;">Services &amp; Eyewear</a></li>
        <li><a href="javascript:void(0)" onclick="openPrivacyModal()" style="color:var(--clr-primary); text-decoration:none; font-weight:600;"><i class="fas fa-shield-alt me-1"></i>Data Privacy Notice (RA 10173)</a></li>
        <li><a href="javascript:void(0)" onclick="openAuthModal()" style="color:var(--text-secondary); text-decoration:none;">Patient Login / Register</a></li>
      </ul>
    </div>
    
    <div>
      <h6 style="font-size:0.88rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:18px;">Clinic Information</h6>
      <div style="display:flex; flex-direction:column; gap:10px; font-size:0.88rem; color:var(--text-secondary);">
        <div><i class="fas fa-map-marker-alt me-2" style="color:var(--clr-primary);"></i>Capas, Tarlac, Philippines</div>
        <div><i class="fas fa-clock me-2" style="color:var(--clr-primary);"></i>Mon – Fri: 9:00 AM – 5:00 PM</div>
        <div><i class="fas fa-calendar-times me-2" style="color:var(--clr-danger);"></i>Sat &amp; Sun: Closed</div>
      </div>
    </div>
  </div>
  
  <div style="max-width:1200px; margin:0 auto; padding-top:20px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; font-size:0.8rem; color:var(--text-muted);">
    <div>&copy; <?= date('Y') ?> Gueco Optical Clinic. All rights reserved.</div>
    <div>
      <a href="javascript:void(0)" onclick="openPrivacyModal()" style="color:var(--text-muted); text-decoration:underline;">Data Privacy Policy &amp; Consent Notice (RA 10173)</a>
    </div>
  </div>
</footer>

<!-- FLOATING ACTION BUTTON -->
<?php if($isPatient): ?>
  <a href="patient/dashboard.php" class="fab">
    <i class="fas fa-calendar-check"></i> 
    Book an Appointment
  </a>
<?php else: ?>
  <button onclick="openAuthModal()" class="fab">
    <i class="fas fa-calendar-check"></i> 
    Book an Appointment
  </button>
<?php endif; ?>

<!-- AUTHENTICATION MODAL -->
<div class="modal-overlay <?= $showModal ? 'open' : '' ?>" id="authModal">
  <div class="auth-card">
    <div class="auth-header">
      <h2 class="auth-title">Patient Portal</h2>
      <button class="close-btn" onclick="closeAuthModal()"><i class="fas fa-times"></i></button>
    </div>
    
    <div class="auth-tabs">
      <button class="auth-tab <?= $tab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">
        <i class="fas fa-sign-in-alt me-1"></i> Login
      </button>
      <button class="auth-tab <?= $tab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">
        <i class="fas fa-user-plus me-1"></i> Register
      </button>
    </div>

    <!-- LOGIN PANEL -->
    <div class="auth-panel <?= $tab === 'login' ? 'active' : '' ?>" id="panel-login">
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px;">
        Sign in to your patient account to book and manage appointments.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-envelope me-1"></i>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter your email" required>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock me-1"></i>Password</label>
          <div class="form-pass-wrap">
            <input type="password" id="loginPass" name="password" class="form-control" placeholder="Enter password" required style="padding-right:44px">
            <button type="button" class="pass-toggle" data-toggle-pass="loginPass">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div style="text-align: right; margin-top: 8px;">
            <a href="#" onclick="switchTab('forgot')" style="font-size: .85rem; color: var(--clr-primary); text-decoration: none;">Forgot Password?</a>
          </div>
        </div>
        <button type="submit" class="btn-primary">
          <i class="fas fa-sign-in-alt"></i> Sign In
        </button>
      </form>
    </div>

    <!-- FORGOT PASSWORD PANEL -->
    <div class="auth-panel <?= $tab === 'forgot' ? 'active' : '' ?>" id="panel-forgot">
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px;">
        Enter your email address and we'll send you an OTP to reset your password.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="forgot">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-envelope me-1"></i>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter your email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn-primary">
          <i class="fas fa-paper-plane"></i> Send OTP
        </button>
        <div style="text-align: center; margin-top: 15px;">
          <a href="#" onclick="switchTab('login')" style="font-size: .85rem; color: var(--text-muted); text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
      </form>
    </div>

    <!-- OTP PANEL -->
    <div class="auth-panel <?= $tab === 'otp' ? 'active' : '' ?>" id="panel-otp">
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px;">
        Enter the 6-digit OTP sent to your email.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="otp">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-key me-1"></i>OTP</label>
          <input type="text" name="otp" class="form-control" placeholder="Enter OTP" required maxlength="6">
        </div>
        <button type="submit" class="btn-primary">
          <i class="fas fa-check"></i> Verify OTP
        </button>
      </form>
    </div>

    <!-- NEW PASSWORD PANEL -->
    <div class="auth-panel <?= $tab === 'new-password' ? 'active' : '' ?>" id="panel-new-password">
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px;">
        Create a new password for your account.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="new-password">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock me-1"></i>New Password <span style="color:var(--clr-danger)">*</span></label>
          <div class="form-pass-wrap">
            <input type="password" id="newPass" name="password" class="form-control" placeholder="At least 8 characters" required style="padding-right:44px">
            <button type="button" class="pass-toggle" data-toggle-pass="newPass">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock me-1"></i>Confirm Password <span style="color:var(--clr-danger)">*</span></label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn-primary">
          <i class="fas fa-save"></i> Reset Password
        </button>
      </form>
    </div>

    <!-- REGISTER PANEL -->
    <div class="auth-panel <?= $tab === 'register' ? 'active' : '' ?>" id="panel-register">
      <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px;">
        Create a free account to schedule appointments online and manage your visit history.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label"><i class="fas fa-user me-1"></i>Full Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="full_name" class="form-control <?= $errorField === 'full_name' ? 'is-invalid' : '' ?>" placeholder="e.g. Juan Dela Cruz" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required onblur="this.value = this.value.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\s+/g, ' ').trim().replace(/\b\w/g, c => c.toUpperCase());">
        </div>
        <div style="display:flex;gap:12px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label"><i class="fas fa-envelope me-1"></i>Email <span style="color:var(--clr-danger)">*</span></label>
            <input type="email" name="email" class="form-control <?= $errorField === 'email' ? 'is-invalid' : '' ?>" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label"><i class="fas fa-phone me-1"></i>Phone</label>
            <input type="tel" pattern="[0-9]*" maxlength="11" minlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" name="phone" class="form-control <?= $errorField === 'phone' ? 'is-invalid' : '' ?>" placeholder="09XX-XXX-XXXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>
        </div>
        <div style="display:flex;gap:12px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label"><i class="fas fa-birthday-cake me-1"></i>Birthdate</label>
            <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>" class="form-control <?= $errorField === 'birthdate' ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label"><i class="fas fa-venus-mars me-1"></i>Gender</label>
            <select name="gender" class="form-select <?= $errorField === 'gender' ? 'is-invalid' : '' ?>">
              <option value="">Select</option>
              <option value="male" <?= ($_POST['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
              <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
              <option value="other" <?= ($_POST['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-map-marker-alt me-1"></i>Address</label>
          <input type="text" name="address" class="form-control <?= $errorField === 'address' ? 'is-invalid' : '' ?>" placeholder="Barangay, Municipality, Province" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock me-1"></i>Password <span style="color:var(--clr-danger)">*</span></label>
          <div class="form-pass-wrap">
            <input type="password" id="regPass" name="password" class="form-control <?= $errorField === 'password' ? 'is-invalid' : '' ?>" placeholder="At least 8 characters" required style="padding-right:44px">
            <button type="button" class="pass-toggle" data-toggle-pass="regPass">
              <i class="fas fa-eye"></i>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fas fa-lock me-1"></i>Confirm Password <span style="color:var(--clr-danger)">*</span></label>
          <input type="password" name="confirm_password" class="form-control <?= $errorField === 'confirm_password' ? 'is-invalid' : '' ?>" placeholder="Repeat password" required>
        </div>
        
        <!-- Data Privacy Consent (RA 10173) -->
        <div class="form-group" style="margin-top: 14px; margin-bottom: 18px;">
          <div style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.81rem; color: var(--text-muted); line-height: 1.45;">
            <input type="checkbox" name="privacy_consent" id="privacyConsent" class="<?= $errorField === 'privacy_consent' ? 'is-invalid' : '' ?>" value="1" required style="margin-top: 3px; cursor: pointer; accent-color: var(--clr-primary);">
            <label for="privacyConsent" style="cursor: pointer;">
              I agree to the <a href="javascript:void(0)" onclick="openPrivacyModal()" style="color: var(--clr-primary); font-weight: 600; text-decoration: underline;">Data Privacy Policy</a> in accordance with the <strong>Philippine Data Privacy Act of 2012 (RA 10173)</strong> for the processing and storage of my personal and optical health records. <span style="color:var(--clr-danger)">*</span>
            </label>
          </div>
        </div>

        <button type="submit" class="btn-primary">
          <i class="fas fa-user-plus"></i> Create Account
        </button>
      </form>
    </div>

  </div>
</div>

<!-- DATA PRIVACY NOTICE MODAL (RA 10173 MODERN) -->
<div class="modal-overlay" id="privacyModal" style="z-index: 100000;">
  <div class="privacy-card">
    <!-- Header -->
    <div class="privacy-header">
      <div style="display:flex; align-items:center; gap:16px;">
        <div class="privacy-icon-box">
          <i class="fas fa-shield-halved"></i>
        </div>
        <div>
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
            <h3 class="privacy-sec-title" style="font-size:1.25rem; font-weight:800; color:var(--text-primary);">Data Privacy Notice</h3>
            <span class="privacy-badge"><i class="fas fa-check-circle"></i> RA 10173 Compliant</span>
          </div>
          <div style="font-size:0.8rem; color:var(--text-muted); font-weight:500;">
            Gueco Optical Clinic &middot; Patient Rights &amp; Optical Health Records Protection
          </div>
        </div>
      </div>
      <button class="close-btn" onclick="closePrivacyModal()" title="Close"><i class="fas fa-times"></i></button>
    </div>

    <!-- Body -->
    <div class="privacy-body">
      <!-- Callout Notice -->
      <div style="background:linear-gradient(135deg,rgba(37,99,235,0.08),rgba(124,58,237,0.05)); border:1px solid rgba(37,99,235,0.2); border-radius:14px; padding:14px 16px; font-size:0.85rem; color:var(--text-secondary); line-height:1.5; display:flex; gap:12px; align-items:flex-start;">
        <i class="fas fa-certificate" style="color:var(--clr-primary); font-size:1.1rem; margin-top:2px; flex-shrink:0;"></i>
        <div>
          Gueco Optical Clinic is dedicated to protecting the privacy, confidentiality, and integrity of your optical and personal data in strict adherence to <strong>Republic Act No. 10173 (Philippine Data Privacy Act of 2012)</strong>.
        </div>
      </div>

      <!-- Section 1 -->
      <div class="privacy-section-card">
        <div class="privacy-sec-head">
          <div class="privacy-sec-icon sec-blue"><i class="fas fa-id-card-clip"></i></div>
          <h5 class="privacy-sec-title">1. Information We Collect</h5>
        </div>
        <p class="privacy-sec-desc">
          To provide accurate clinical examinations and tailored optical care, we collect essential patient demographics and clinical records during appointment bookings and eye consultations:
        </p>
        <div class="privacy-pill-group">
          <span class="privacy-pill"><i class="fas fa-user me-1 text-primary"></i>Full Name &amp; Demographics</span>
          <span class="privacy-pill"><i class="fas fa-phone me-1 text-primary"></i>Contact Phone &amp; Email</span>
          <span class="privacy-pill"><i class="fas fa-location-dot me-1 text-primary"></i>Residential Address</span>
          <span class="privacy-pill"><i class="fas fa-glasses me-1 text-primary"></i>Refraction Data (OD/OS SPH, CYL, AXIS, PD)</span>
          <span class="privacy-pill"><i class="fas fa-notes-medical me-1 text-primary"></i>Doctor Clinical Notes</span>
        </div>
      </div>

      <!-- Section 2 -->
      <div class="privacy-section-card">
        <div class="privacy-sec-head">
          <div class="privacy-sec-icon sec-purple"><i class="fas fa-stethoscope"></i></div>
          <h5 class="privacy-sec-title">2. Purpose &amp; Use of Information</h5>
        </div>
        <p class="privacy-sec-desc">
          Your personal and medical information is processed solely for legitimate medical and optical services, including:
        </p>
        <ul style="font-size:0.83rem; color:var(--text-secondary); padding-left:20px; margin:0 0 10px 0; line-height:1.6;">
          <li>Scheduling, confirming, and managing clinical optometric appointments.</li>
          <li>Accurate prescription lens fitting, frame customization, and optical dispensing.</li>
          <li>Maintaining continuous, lifetime ophthalmic patient history.</li>
        </ul>
        <div style="background:rgba(5,150,105,0.08); border:1px solid rgba(5,150,105,0.2); border-radius:8px; padding:8px 12px; font-size:0.78rem; color:#34D399; display:flex; align-items:center; gap:8px;">
          <i class="fas fa-lock"></i>
          <span><strong>Zero Marketing Policy:</strong> We never sell, lease, or distribute your personal data to external advertisers.</span>
        </div>
      </div>

      <!-- Section 3 -->
      <div class="privacy-section-card">
        <div class="privacy-sec-head">
          <div class="privacy-sec-icon sec-teal"><i class="fas fa-user-shield"></i></div>
          <h5 class="privacy-sec-title">3. Confidentiality &amp; Security Measures</h5>
        </div>
        <p class="privacy-sec-desc">
          Access to medical files is strictly confined to licensed optometrists and authenticated clinic personnel on a strict need-to-know basis. Our systems enforce database session encryption, brute-force rate limiting, and technical access controls to protect your data against unauthorized access.
        </p>
      </div>

      <!-- Section 4 -->
      <div class="privacy-section-card">
        <div class="privacy-sec-head">
          <div class="privacy-sec-icon sec-amber"><i class="fas fa-scale-balanced"></i></div>
          <h5 class="privacy-sec-title">4. Your Patient Rights (RA 10173)</h5>
        </div>
        <p class="privacy-sec-desc">
          As a registered patient and data subject under Philippine Law, you are entitled to the following statutory rights:
        </p>
        <div class="privacy-rights-grid">
          <div class="privacy-right-item">
            <div class="privacy-right-title"><i class="fas fa-eye text-primary"></i> Right to Access</div>
            <p class="privacy-right-desc">View your prescription history and recorded visits anytime in your patient portal.</p>
          </div>
          <div class="privacy-right-item">
            <div class="privacy-right-title"><i class="fas fa-pen-to-square text-success"></i> Right to Rectify</div>
            <p class="privacy-right-desc">Update contact details or request corrections to inaccurate medical records.</p>
          </div>
          <div class="privacy-right-item">
            <div class="privacy-right-title"><i class="fas fa-hand text-warning"></i> Right to Object</div>
            <p class="privacy-right-desc">Withdraw processing consent or request record deactivation subject to medical retention rules.</p>
          </div>
          <div class="privacy-right-item">
            <div class="privacy-right-title"><i class="fas fa-shield-heart text-info"></i> Right to Security</div>
            <p class="privacy-right-desc">Be protected against unlawful processing and security breaches.</p>
          </div>
        </div>
      </div>

      <!-- Contact Note -->
      <div style="font-size:0.78rem; color:var(--text-muted); text-align:center; padding:6px 0;">
        For privacy questions or data requests, visit <strong>Gueco Optical Clinic</strong> in Capas, Tarlac or contact our clinic staff.
      </div>
    </div>

    <!-- Footer -->
    <div class="privacy-footer">
      <button type="button" class="btn btn-outline-secondary" onclick="closePrivacyModal()" style="padding:10px 20px; font-size:0.88rem; font-weight:600; border-radius:12px;">
        Close
      </button>
      <button type="button" class="btn-primary" onclick="acceptPrivacyAndClose()" style="padding:10px 28px; font-size:0.92rem; width:auto; border-radius:12px; display:inline-flex; align-items:center; gap:8px;">
        <i class="fas fa-check-circle"></i> I Understand &amp; Agree
      </button>
    </div>
  </div>
</div>

<script>
// Toast logic
function showToast(msg, isError = false) {
  const container = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = 'toast' + (isError ? ' danger' : '');
  const icon = isError ? 'fa-exclamation-circle' : 'fa-check-circle';
  toast.innerHTML = `<i class="fas ${icon}"></i> <div>${msg}</div>`;
  container.appendChild(toast);
  
  setTimeout(() => toast.classList.add('show'), 100);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 400);
  }, 4000);
}

<?php if ($error): ?>
  setTimeout(() => showToast(<?= json_encode($error) ?>, true), 300);
<?php endif; ?>

<?php if (isset($_SESSION['flash_msg'])): ?>
  setTimeout(() => showToast(<?= json_encode($_SESSION['flash_msg']) ?>, <?= $_SESSION['flash_type'] === 'error' ? 'true' : 'false' ?>), 300);
  <?php 
    unset($_SESSION['flash_msg']);
    unset($_SESSION['flash_type']);
  ?>
<?php endif; ?>

// Privacy Modal logic
function openPrivacyModal() {
  document.getElementById('privacyModal').classList.add('open');
}
function closePrivacyModal() {
  document.getElementById('privacyModal').classList.remove('open');
}
function acceptPrivacyAndClose() {
  const chk = document.getElementById('privacyConsent');
  if (chk) {
    chk.checked = true;
    chk.classList.remove('is-invalid');
  }
  closePrivacyModal();
}
document.getElementById('privacyModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    closePrivacyModal();
  }
});

// Modal logic
function openAuthModal() {
  document.getElementById('authModal').classList.add('open');
  switchTab('login'); // Always default to login tab when opening
}
function closeAuthModal() {
  const regPanel = document.getElementById('panel-register');
  if (regPanel && regPanel.classList.contains('active')) {
    const inputs = regPanel.querySelectorAll('input:not([type="hidden"]), select');
    let hasData = false;
    inputs.forEach(input => {
      if (input.value.trim() !== '') hasData = true;
    });

    if (hasData) {
      if (!confirm('Are you sure you want to close? All inputted data will be cleared.')) {
        return; // Stop closing if they click cancel
      }
      // Clear data if they click OK
      inputs.forEach(input => {
        input.value = '';
        input.classList.remove('is-invalid');
      });
    }
  }
  
  // Also clear login panel if needed (optional, but requested for register)
  document.getElementById('authModal').classList.remove('open');
}
// Close on outside click
document.getElementById('authModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeAuthModal();
  }
});

// Tab logic
function switchTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
  
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('active');
  
  if (tab === 'login' || tab === 'register') {
    const tabBtn = document.querySelector('.auth-tab:' + (tab === 'login' ? 'first-child' : 'last-child'));
    if (tabBtn) tabBtn.classList.add('active');
  }
}

// Password toggles
document.querySelectorAll('.pass-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    const input = document.getElementById(this.dataset.togglePass);
    if (input.type === 'password') {
      input.type = 'text';
      this.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
      input.type = 'password';
      this.innerHTML = '<i class="fas fa-eye"></i>';
    }
  });
});

// Theme logic
function updateThemeIcons(theme) {
  const iconClass = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = iconClass;
  const iconMobile = document.getElementById('themeIconMobile');
  if (iconMobile) iconMobile.className = iconClass;
}

const savedTheme = localStorage.getItem('gueco_theme') || localStorage.getItem('gueco-theme') || localStorage.getItem('guecoTheme') || localStorage.getItem('theme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
updateThemeIcons(savedTheme);

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('gueco_theme', next);
  localStorage.setItem('guecoTheme', next);
  updateThemeIcons(next);
}

document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
document.getElementById('themeToggleMobile')?.addEventListener('click', toggleTheme);
</script>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
