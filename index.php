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

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $showModal = true;
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
                $_SESSION['patient_id']    = $patient['id'];
                $_SESSION['patient_name']  = $patient['full_name'];
                $_SESSION['patient_email'] = $patient['email'];

                header('Location: patient/dashboard.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (Exception $e) {
            $error = 'System error. Please try again.';
        }
    }
}

// ── FORGOT PASSWORD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $showModal = true;
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
                $otp = sprintf("%06d", mt_rand(1, 999999));
                $otpHash = password_hash($otp, PASSWORD_DEFAULT);
                $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                $update = $db->prepare("UPDATE patients SET reset_otp_hash = ?, reset_expires = ? WHERE id = ?");
                $update->execute([$otpHash, $expires, $patient['id']]);
                
                sendEmailOTP($email, $otp); // Helper from config/functions.php
                
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

// ── VERIFY OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'otp') {
    $showModal = true;
    $otp = trim($_POST['otp'] ?? '');
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($otp)) {
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
                    $_SESSION['reset_verified'] = true;
                    $tab = 'new-password';
                } else {
                    $error = 'Invalid OTP.';
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gueco Optical Clinic</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    :root { 
      --clr-primary:#2563EB; --clr-secondary:#7C3AED; --clr-danger:#DC2626;
      --bg-body:#0F172A; --bg-card:rgba(30,41,59,.8); 
      --text-primary:#F1F5F9; --text-secondary:#CBD5E1; --text-muted:#64748B;
      --border-color:rgba(255,255,255,.08);
      --bg-topbar:rgba(15,23,42,.85);
      --bg-input:rgba(0,0,0,.2);
      --bg-input-focus:rgba(0,0,0,.4);
    }
    [data-theme="light"] {
      --bg-body:#F0F4FF; --bg-card:rgba(255,255,255,.9); 
      --text-primary:#0F172A; --text-secondary:#334155; --text-muted:#94A3B8;
      --border-color:rgba(0,0,0,.07);
      --bg-topbar:rgba(255,255,255,.85);
      --bg-input:rgba(255,255,255,.8);
      --bg-input-focus:#fff;
    }

    body { 
      font-family:'Poppins',sans-serif; background:var(--bg-body); 
      color:var(--text-primary); min-height:100vh; font-size:16px;
      overflow-x:hidden;
    }

    .bg-mesh {
      position:fixed; inset:0; z-index:-1; pointer-events:none;
      background:
        radial-gradient(ellipse 70% 60% at 0% 0%, rgba(37,99,235,.2) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 100% 100%, rgba(124,58,237,.15) 0%, transparent 50%);
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
      width:52px; height:52px; object-fit:contain; border-radius:10px;
    }
    .topbar-name { font-weight:800; font-size:1.3rem; color:var(--text-primary); line-height:1.2; }
    .topbar-sub  { font-size:.85rem; color:var(--text-muted); font-weight:500; }
    
    .nav-links a {
      color:var(--text-secondary); text-decoration:none; font-weight:600; font-size:.95rem; margin-left:24px; transition:color .2s;
    }
    .nav-links a:hover { color:var(--clr-primary); }
    
    .theme-btn {
      width:36px; height:36px; border-radius:50%; border:1px solid var(--border-color);
      background:rgba(255,255,255,.05); color:var(--text-muted); cursor:pointer;
      display:inline-flex; align-items:center; justify-content:center; font-size:.85rem;
      transition:all .2s; margin-left:24px; vertical-align:middle;
    }
    [data-theme="light"] .theme-btn { background:rgba(0,0,0,.03); }
    .theme-btn:hover { border-color:var(--clr-primary); color:var(--clr-primary); }

    /* HERO */
    .hero {
      padding:160px 24px 100px; max-width:1200px; margin:0 auto; display:flex; align-items:center; gap:60px;
    }
    .hero-text { flex:1; }
    .badge {
      display:inline-block; padding:8px 16px; background:rgba(37,99,235,.15);
      color:#60A5FA; border-radius:100px; font-size:.85rem; font-weight:700;
      letter-spacing:.05em; text-transform:uppercase; margin-bottom:24px;
      border:1px solid rgba(37,99,235,.3);
    }
    .hero h1 { font-size:3.8rem; font-weight:900; line-height:1.15; margin-bottom:24px; color:var(--text-primary); }
    .hero h1 span {
      background:linear-gradient(135deg,#60A5FA,#A78BFA);
      -webkit-background-clip:text; -webkit-text-fill-color:transparent;
    }
    .hero p { font-size:1.15rem; color:var(--text-secondary); line-height:1.7; margin-bottom:40px; max-width:540px; }
    
    .stats { display:flex; gap:40px; }
    .stat-item h3 { font-size:2.2rem; font-weight:800; margin:0; color:var(--text-primary); }
    .stat-item p { font-size:.9rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; margin:0; }

    .hero-image { flex:1; position:relative; }
    .hero-image img { width:100%; border-radius:32px; box-shadow:0 30px 60px rgba(0,0,0,.4); position:relative; z-index:2; border:1px solid var(--border-color); }
    .hero-image::after {
      content:''; position:absolute; inset:-20px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      filter:blur(60px); opacity:.3; z-index:1; border-radius:50%;
    }

    /* DETAILS */
    .details { max-width:1200px; margin:0 auto 100px; padding:0 24px; display:grid; grid-template-columns:repeat(3, 1fr); gap:30px; }
    .detail-card {
      background:var(--bg-card); border:1px solid var(--border-color);
      padding:40px; border-radius:24px; backdrop-filter:blur(16px);
      transition:transform .3s, box-shadow .3s;
    }
    .detail-card:hover { transform:translateY(-8px); box-shadow:0 20px 40px rgba(0,0,0,.3); border-color:rgba(37,99,235,.3); }
    .detail-icon {
      width:64px; height:64px; border-radius:18px; display:flex; align-items:center; justify-content:center;
      font-size:1.8rem; margin-bottom:24px; color:#fff;
    }
    .icon-blue { background:linear-gradient(135deg,#2563EB,#60A5FA); box-shadow:0 12px 24px rgba(37,99,235,.3); }
    .icon-purple { background:linear-gradient(135deg,#7C3AED,#A78BFA); box-shadow:0 12px 24px rgba(124,58,237,.3); }
    .icon-orange { background:linear-gradient(135deg,#D97706,#FBBF24); box-shadow:0 12px 24px rgba(217,119,6,.3); }
    
    .detail-card h3 { font-size:1.3rem; font-weight:700; margin-bottom:12px; }
    .detail-card p { font-size:1rem; color:var(--text-secondary); line-height:1.6; margin:0; }

    /* FLOATING ACTION BUTTON */
    .fab {
      position:fixed; bottom:40px; right:40px; z-index:999;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; padding:18px 32px; border-radius:100px; font-size:1.1rem; font-weight:700;
      display:flex; align-items:center; gap:12px; border:none; cursor:pointer;
      box-shadow:0 16px 40px rgba(37,99,235,.4);
      transition:all .3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .fab i { font-size:1.3rem; }
    .fab:hover {
      transform:scale(1.05) translateY(-4px); box-shadow:0 24px 50px rgba(37,99,235,.5); color:#fff;
    }

    /* MODAL STYLES */
    .modal-overlay { 
      display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(8px);
      z-index:9999; align-items:center; justify-content:center; padding:20px;
    }
    .modal-overlay.open { display:flex !important; }
    .auth-card {
      background:var(--bg-card); border:1px solid var(--border-color); border-radius:24px;
      width:100%; max-width:480px; box-shadow:0 24px 60px rgba(0,0,0,.4); overflow:hidden;
      animation:modalIn .3s cubic-bezier(0.16,1,0.3,1); max-height:90vh; overflow-y:auto;
    }
    @keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);} }
    .auth-header {
      padding:24px; display:flex; align-items:center; justify-content:space-between;
      border-bottom:1px solid var(--border-color); background:rgba(255,255,255,.02);
    }
    .auth-title { font-size:1.25rem; font-weight:700; margin:0; }
    .close-btn {
      background:rgba(255,255,255,.1); border:none; color:var(--text-secondary);
      width:32px; height:32px; border-radius:50%; cursor:pointer; transition:all .2s;
    }
    .close-btn:hover { background:var(--clr-danger); color:#fff; }
    
    .auth-tabs { display:flex; border-bottom:1px solid var(--border-color); }
    .auth-tab {
      flex:1; padding:16px; text-align:center; cursor:pointer; font-size:.9rem; font-weight:600;
      color:var(--text-muted); background:none; border:none; font-family:inherit; transition:all .2s;
    }
    .auth-tab.active { color:var(--clr-primary); border-bottom:2px solid var(--clr-primary); background:rgba(37,99,235,.04); }
    
    .auth-panel { padding:28px; display:none; }
    .auth-panel.active { display:block; }
    
    .form-group { margin-bottom:16px; }
    .form-label { display:block; font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:7px; }
    .form-control, .form-select {
      width:100%; padding:14px 16px; border-radius:12px; border:1px solid var(--border-color);
      background:var(--bg-input) !important; color:var(--text-primary) !important; font-family:inherit; font-size:.95rem; transition:all .2s;
    }
    .form-control:focus, .form-select:focus { border-color:var(--clr-primary); outline:none; background:var(--bg-input-focus) !important; color:var(--text-primary) !important; box-shadow:0 0 0 4px rgba(37,99,235,.1); }
    .is-invalid { border-color: var(--clr-danger) !important; box-shadow: 0 0 0 4px rgba(220,38,38,.1) !important; }

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
      color:#fff; font-family:'Poppins',sans-serif; font-size:.95rem; font-weight:700;
      cursor:pointer; transition:all .2s; box-shadow:0 8px 20px rgba(37,99,235,.3);
    }
    .btn-primary:hover { transform:translateY(-2px); box-shadow:0 12px 24px rgba(37,99,235,.4); }
    
    .form-pass-wrap { position:relative; }
    .pass-toggle {
      position:absolute; right:14px; top:50%; transform:translateY(-50%);
      background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:.9rem;
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
    <img src="assets/images/logo.png?v=2" alt="Logo" class="topbar-logo" style="border-radius:50%; box-shadow:0 2px 8px rgba(37,99,235,0.3); padding:2px; background:rgba(255,255,255,0.9);">
    <div>
      <div class="topbar-name">Gueco Optical</div>
      <div class="topbar-sub">Capas, Tarlac</div>
    </div>
  </a>
  <div class="nav-links d-none d-md-block">
    <a href="#about">About Us</a>
    <a href="#services">Services</a>
    <?php if($isPatient): ?>
      <a href="patient/dashboard.php" style="color:var(--clr-primary);">My Dashboard</a>
    <?php endif; ?>
    <button class="theme-btn" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
  </div>
</nav>

<!-- HERO SECTION -->
<section class="hero" id="about">
  <div class="hero-text">
    <div class="badge">Established in 1986</div>
    <h1>See the World <span>Clearly</span> & <span>Beautifully</span></h1>
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
    <!-- Using a placeholder pattern that matches the theme since we don't have an exact clinic photo -->
    <div style="width:100%;aspect-ratio:4/3;background:linear-gradient(135deg,rgba(30,41,59,.9),rgba(15,23,42,.9));border-radius:32px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border-color);position:relative;z-index:2;box-shadow:0 30px 60px rgba(0,0,0,.4);overflow:hidden;">
      <i class="fas fa-glasses" style="font-size:8rem;color:rgba(255,255,255,.05);position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);"></i>
      <div style="text-align:center;position:relative;z-index:3;">
        <img src="assets/images/logo.png?v=2" alt="Logo" style="width:120px; height:120px; object-fit:contain; border-radius:50%; margin-bottom:16px; background:rgba(255,255,255,0.9); box-shadow:0 8px 24px rgba(37,99,235,0.3); padding:4px;">
        <h2 style="font-size:2rem;font-weight:800;margin:0;letter-spacing:-1px;">Gueco Optical</h2>
        <p style="color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.2em;margin-top:8px;">Vision Care Center</p>
      </div>
    </div>
  </div>
</section>

<!-- DETAILS -->
<section class="details" id="services">
  <div class="detail-card">
    <div class="detail-icon icon-blue"><i class="fas fa-user-md"></i></div>
    <h3>Expert Optometrists</h3>
    <p>Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.</p>
  </div>
  
  <div class="detail-card">
    <div class="detail-icon icon-purple"><i class="fas fa-glasses"></i></div>
    <h3>Premium Eyewear</h3>
    <p>Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.</p>
  </div>
  
  <div class="detail-card">
    <div class="detail-icon icon-orange"><i class="fas fa-map-marker-alt"></i></div>
    <h3>Convenient Location</h3>
    <p>Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.</p>
  </div>
</section>

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
        <button type="submit" class="btn-primary">
          <i class="fas fa-user-plus"></i> Create Account
        </button>
      </form>
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
const savedTheme = localStorage.getItem('guecoTheme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
const themeIcon = document.getElementById('themeIcon');
if(themeIcon) {
  themeIcon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}

document.getElementById('themeToggle').addEventListener('click', () => {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('guecoTheme', next);
  const icon = document.getElementById('themeIcon');
  if(icon) {
    icon.className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
  }
});
</script>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
