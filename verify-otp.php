<?php
// ============================================================
// PATIENT PORTAL TWO-FACTOR AUTHENTICATION (OTP VERIFICATION)
// Gueco Optical Clinic Management System
// ============================================================

define('BASE_URL', '');
require_once __DIR__ . '/config/functions.php';
startSession();

// Must have a pending patient login
if (empty($_SESSION['patient_id'])) {
    header('Location: index.php');
    exit;
}

// If already verified, jump straight to dashboard
if (isPatient2FAVerified()) {
    header('Location: patient/dashboard.php');
    exit;
}

$patientId   = (int)$_SESSION['patient_id'];
$patientName = !empty($_SESSION['patient_name']) ? $_SESSION['patient_name'] : 'Valued Patient';
$email       = $_SESSION['patient_email'] ?? '';
$avatar      = $_SESSION['patient_avatar'] ?? '';

// Ensure an OTP is generated if missing or expired
if (empty($_SESSION['patient_otp_code']) || (isset($_SESSION['patient_otp_expires']) && $_SESSION['patient_otp_expires'] < time())) {
    issuePatientLoginOTP([
        'id'        => $patientId,
        'full_name' => $patientName,
        'email'     => $email,
        'avatar'    => $avatar
    ]);
}

$error   = '';
$success = '';

// Handle Cancel Action
if (isset($_GET['cancel'])) {
    unset(
        $_SESSION['patient_id'],
        $_SESSION['patient_name'],
        $_SESSION['patient_email'],
        $_SESSION['patient_avatar'],
        $_SESSION['patient_2fa_verified'],
        $_SESSION['patient_otp_code'],
        $_SESSION['patient_otp_hash'],
        $_SESSION['patient_otp_expires'],
        $_SESSION['patient_otp_attempts'],
        $_SESSION['patient_id_pending'],
        $_SESSION['patient_name_pending'],
        $_SESSION['patient_email_pending'],
        $_SESSION['patient_avatar_pending']
    );
    $_SESSION['flash_msg'] = 'Sign-in cancelled. Please log in when ready.';
    $_SESSION['flash_type'] = 'info';
    header('Location: index.php');
    exit;
}

// Handle Resend Action
if (isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $lastResend = $_SESSION['patient_otp_last_resend'] ?? 0;
        if (time() - $lastResend < 60) {
            $remainingSec = 60 - (time() - $lastResend);
            $error = "Please wait {$remainingSec} seconds before requesting a new code.";
        } else {
            $_SESSION['patient_otp_last_resend'] = time();
            issuePatientLoginOTP([
                'id'        => $patientId,
                'full_name' => $patientName,
                'email'     => $email,
                'avatar'    => $avatar
            ]);
            $success = 'A fresh 6-digit verification code has been generated and sent!';
        }
    }
}

// Handle OTP Verification
if (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please try again.';
    } else {
        $enteredOtp = trim($_POST['otp'] ?? '');
        
        // Assemble 6-digit inputs if submitted individually
        if (empty($enteredOtp) && isset($_POST['digit']) && is_array($_POST['digit'])) {
            $enteredOtp = implode('', array_map('trim', $_POST['digit']));
        }
        
        // Clean numeric
        $enteredOtp = preg_replace('/[^0-9]/', '', $enteredOtp);
        
        $attempts = (int)($_SESSION['patient_otp_attempts'] ?? 0);
        $expires  = (int)($_SESSION['patient_otp_expires'] ?? 0);
        $expected = (string)($_SESSION['patient_otp_code'] ?? '');
        
        if (empty($enteredOtp)) {
            $error = 'Please enter the 6-digit verification code.';
        } elseif (strlen($enteredOtp) !== 6) {
            $error = 'Verification code must be exactly 6 digits.';
        } elseif ($expires < time()) {
            $error = 'The verification code has expired. Please click "Resend Code" for a new one.';
        } elseif ($attempts >= 5) {
            $error = 'Too many failed attempts. For your security, this code was invalidated. Please request a new one.';
            $_SESSION['patient_otp_code'] = null;
        } else {
            // Verify code
            if ($enteredOtp === $expected) {
                // Verification successful!
                session_regenerate_id(true);
                $_SESSION['patient_2fa_verified'] = true;
                
                // Cleanup temporary OTP session tokens
                unset(
                    $_SESSION['patient_otp_code'],
                    $_SESSION['patient_otp_hash'],
                    $_SESSION['patient_otp_expires'],
                    $_SESSION['patient_otp_attempts'],
                    $_SESSION['patient_otp_last_resend']
                );
                
                // Check if profile has all essential fields (name, phone, address, sex)
                if (!isPatientProfileComplete($patientId)) {
                    $_SESSION['flash_msg']   = 'Verification complete! Please fill in your essential details to set up your profile.';
                    $_SESSION['flash_type']  = 'info';
                    $_SESSION['flash_title'] = 'Profile Setup';
                    header('Location: complete-profile.php');
                    exit;
                }

                $patientLoginCount = (int)($db->query("SELECT login_count FROM patients WHERE id = " . (int)$patientId)->fetchColumn() ?: 1);
                $isFirst = ($patientLoginCount <= 1);
                $_SESSION['flash_msg']   = 'Security verification passed! ' . ($isFirst ? 'Welcome, ' : 'Welcome back, ') . htmlspecialchars($patientName) . '.';
                $_SESSION['flash_type']  = 'success';
                $_SESSION['flash_title'] = $isFirst ? 'Welcome!' : 'Welcome Back!';
                
                header('Location: patient/dashboard.php');
                exit;
            } else {
                $_SESSION['patient_otp_attempts'] = $attempts + 1;
                $left = 5 - ($attempts + 1);
                if ($left > 0) {
                    $error = "Incorrect verification code. {$left} attempt" . ($left === 1 ? '' : 's') . " remaining.";
                } else {
                    $error = 'Too many failed attempts. The code has been invalidated. Please click "Resend Code".';
                    $_SESSION['patient_otp_code'] = null;
                }
            }
        }
    }
}

// Mask Email: e.g. j***n@gmail.com
$maskedEmail = $email;
if (!empty($email) && str_contains($email, '@')) {
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) {
        $maskedEmail = substr($local, 0, 1) . '***@' . $domain;
    } else {
        $maskedEmail = substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 2)) . substr($local, -1) . '@' . $domain;
    }
}

$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Security Verification — Gueco Optical Clinic</title>
  
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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ─── Universal Caret & Text-Selection Prevention ─────── */
    *, *::before, *::after {
      caret-color: transparent;
    }

    body, h1, h2, h3, h4, h5, h6, p, span, div, a, label, li, ul, ol, section, main, header, footer, nav,
    button, [type="button"], [type="reset"], [type="submit"], .btn, .theme-btn, .card {
      -webkit-user-select: none;
      -moz-user-select: none;
      -ms-user-select: none;
      user-select: none;
    }

    h1, h2, h3, h4, h5, h6, p, label, .card {
      cursor: default;
    }

    button, [type="button"], [type="reset"], [type="submit"], .btn, a, .theme-btn {
      cursor: pointer;
    }

    input, textarea, [contenteditable="true"], .allow-select {
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

    :root {
      --clr-bronze-light: #27AAE2;
      --clr-bronze:       #235EAE;
      --clr-bronze-dark:  #272264;
      --clr-gold:         #00ADEF;
      --clr-primary:      #235EAE;
      --clr-accent:       #00ADEF;
      --clr-danger:       #EF4444;
      --clr-success:      #10B981;
    }

    [data-theme="dark"] {
      --bg-body:       #0A0A0C;
      --bg-card:       rgba(20, 19, 23, 0.82);
      --bg-card-hover: rgba(28, 26, 32, 0.95);
      --bg-input:      rgba(15, 14, 18, 0.85);
      --text-primary:  #F9FAFB;
      --text-muted:    #9CA3AF;
      --text-subtle:   #6B7280;
      --border-color:  rgba(255, 255, 255, 0.08);
      --border-glow:   rgba(0, 173, 239, 0.35);
      --pill-bg:       rgba(255, 255, 255, 0.04);
      --pill-border:   rgba(255, 255, 255, 0.08);
      --card-shadow:   0 32px 80px -16px rgba(0,0,0,0.9), 0 0 0 1px rgba(255,255,255,0.08) inset;
      --box-bg:        rgba(255, 255, 255, 0.03);
    }

    [data-theme="light"] {
      --bg-body:       #F0F4F9;
      --bg-card:       rgba(255, 255, 255, 0.92);
      --bg-card-hover: rgba(255, 255, 255, 0.98);
      --bg-input:      #FFFFFF;
      --text-primary:  #18181B;
      --text-muted:    #71717A;
      --text-subtle:   #A1A1AA;
      --border-color:  rgba(0, 0, 0, 0.1);
      --border-glow:   rgba(35, 94, 174, 0.3);
      --pill-bg:       rgba(255, 255, 255, 0.9);
      --pill-border:   rgba(0, 0, 0, 0.08);
      --card-shadow:   0 24px 60px -12px rgba(35, 94, 174, 0.15), 0 0 0 1px rgba(255,255,255,0.9) inset;
      --box-bg:        rgba(35, 94, 174, 0.03);
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      position: relative;
      overflow-x: hidden;
      transition: background-color 0.3s ease, color 0.3s ease;
    }

    .theme-toggle-btn {
      position: fixed;
      top: 24px;
      right: 24px;
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      color: var(--text-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      backdrop-filter: blur(12px);
      z-index: 100;
      transition: all 0.2s ease;
    }
    .theme-toggle-btn:hover {
      border-color: var(--clr-accent);
      transform: scale(1.05);
    }

    .otp-card {
      width: 100%;
      max-width: 460px;
      background: var(--bg-card);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--border-color);
      border-radius: 24px;
      padding: 38px 32px;
      box-shadow: var(--card-shadow);
      text-align: center;
      position: relative;
      animation: cardAppear 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes cardAppear {
      from { opacity: 0; transform: translateY(16px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .shield-icon {
      width: 68px;
      height: 68px;
      border-radius: 20px;
      background: linear-gradient(135deg, rgba(35,94,174,0.18), rgba(0,173,239,0.22));
      border: 1px solid var(--border-glow);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      color: var(--clr-gold);
      font-size: 28px;
      box-shadow: 0 10px 25px -5px rgba(0,173,239,0.3);
    }

    .otp-title {
      font-size: 1.55rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      margin-bottom: 8px;
      color: var(--text-primary);
    }

    .otp-subtitle {
      font-size: 0.88rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 24px;
    }

    .email-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      background: var(--pill-bg);
      border: 1px solid var(--pill-border);
      border-radius: 999px;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--clr-gold);
      margin-top: 6px;
    }

    /* 6-Digit input boxes */
    .otp-digits-wrap {
      display: flex;
      justify-content: center;
      gap: 10px;
      margin: 28px 0;
    }

    .otp-digit {
      width: 52px;
      height: 60px;
      font-size: 28px;
      font-weight: 800;
      text-align: center;
      background: var(--bg-input);
      border: 1.5px solid var(--border-color);
      border-radius: 14px;
      color: var(--text-primary);
      transition: all 0.2s ease;
      box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }

    .otp-digit:focus {
      outline: none;
      border-color: var(--clr-accent);
      box-shadow: 0 0 0 3px rgba(0,173,239,0.25);
      transform: translateY(-2px);
    }

    .btn-verify {
      width: 100%;
      height: 50px;
      background: linear-gradient(135deg, #235EAE 0%, #1c4b8b 100%);
      color: #FFFFFF;
      font-size: 0.96rem;
      font-weight: 700;
      border: none;
      border-radius: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 8px 24px -4px rgba(35,94,174,0.45);
      transition: all 0.25s ease;
    }
    .btn-verify:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px -4px rgba(35,94,174,0.6);
      background: linear-gradient(135deg, #276ac5 0%, #20559e 100%);
    }
    .btn-verify:active {
      transform: translateY(0);
    }

    .resend-box {
      margin-top: 22px;
      padding-top: 20px;
      border-top: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .btn-link-resend {
      background: none;
      border: none;
      color: var(--clr-accent);
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: opacity 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-link-resend:hover {
      text-decoration: underline;
      opacity: 0.85;
    }
    .btn-link-resend:disabled {
      color: var(--text-subtle);
      cursor: not-allowed;
      text-decoration: none;
      opacity: 0.6;
    }

    .btn-cancel {
      font-size: 0.82rem;
      color: var(--text-muted);
      text-decoration: none;
      transition: color 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .btn-cancel:hover {
      color: var(--clr-danger);
    }

    .alert-custom {
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 0.86rem;
      margin-bottom: 20px;
      text-align: left;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }
    .alert-danger-custom {
      background: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #F87171;
    }
    .alert-success-custom {
      background: rgba(16, 185, 129, 0.12);
      border: 1px solid rgba(16, 185, 129, 0.3);
      color: #34D399;
    }
  </style>
</head>
<body>

  <!-- Theme Toggle -->
  <button class="theme-toggle-btn" id="themeBtn" title="Toggle Theme">
    <i class="fas fa-sun" id="themeIcon"></i>
  </button>

  <div class="otp-card">
    <div class="shield-icon">
      <i class="fas fa-shield-halved"></i>
    </div>

    <h1 class="otp-title">Two-Factor Authentication</h1>
    <p class="otp-subtitle">
      Hi <b><?= htmlspecialchars($patientName) ?></b>, we've sent a 6-digit verification code to your email account:
      <br>
      <span class="email-badge">
        <i class="fas fa-envelope"></i> <?= htmlspecialchars($maskedEmail) ?>
      </span>
    </p>

    <!-- Alert Messages -->

    <?php if (!empty($error)): ?>
      <div class="alert-custom alert-danger-custom">
        <i class="fas fa-circle-exclamation mt-1"></i>
        <div><?= htmlspecialchars($error) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="alert-custom alert-success-custom">
        <i class="fas fa-circle-check mt-1"></i>
        <div><?= htmlspecialchars($success) ?></div>
      </div>
    <?php endif; ?>

    <form method="POST" id="otpForm">
      <input type="hidden" name="action" value="verify_otp">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <input type="hidden" name="otp" id="hiddenOtp" value="">

      <div class="otp-digits-wrap">
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus autocomplete="off" required>
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
        <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="off" required>
      </div>

      <button type="submit" class="btn-verify" id="btnVerify">
        <i class="fas fa-arrow-right-to-bracket"></i> Verify &amp; Continue to Dashboard
      </button>
    </form>

    <div class="resend-box">
      <form method="POST" style="margin: 0;">
        <input type="hidden" name="action" value="resend_otp">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <button type="submit" class="btn-link-resend" id="resendBtn">
          <i class="fas fa-rotate-right"></i> Resend Verification Code
        </button>
      </form>

      <a href="verify-otp.php?cancel=1" class="btn-cancel" onclick="return confirm('Cancel sign-in and return to the main page?');">
        <i class="fas fa-arrow-left"></i> Cancel and sign in with another account
      </a>
    </div>
  </div>

  <script>
    // Theme Management
    const themeBtn = document.getElementById('themeBtn');
    const themeIcon = document.getElementById('themeIcon');

    function syncTheme(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      if (themeIcon) {
        themeIcon.className = (theme === 'dark') ? 'fas fa-sun' : 'fas fa-moon';
      }
      try {
        localStorage.setItem('gueco_theme', theme);
        localStorage.setItem('gueco-theme', theme);
        localStorage.setItem('theme', theme);
        document.cookie = "gueco_theme=" + theme + "; path=/; max-age=31536000; SameSite=Lax";
      } catch(e) {}
    }

    const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
    syncTheme(currentTheme);

    themeBtn?.addEventListener('click', () => {
      const active = document.documentElement.getAttribute('data-theme') || 'dark';
      syncTheme(active === 'dark' ? 'light' : 'dark');
    });

    // 6-Digit Auto-Focus and Paste Handling
    const digits = document.querySelectorAll('.otp-digit');
    const hiddenOtp = document.getElementById('hiddenOtp');
    const otpForm = document.getElementById('otpForm');

    digits.forEach((digit, index) => {
      // Auto-advance
      digit.addEventListener('input', (e) => {
        const val = e.target.value.replace(/[^0-9]/g, '');
        e.target.value = val ? val.slice(-1) : '';
        
        if (val && index < digits.length - 1) {
          digits[index + 1].focus();
        }
        collectOtp();
      });

      // Backspace backward navigation
      digit.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
          digits[index - 1].focus();
        }
      });

      // Paste full 6-digit code into any box
      digit.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
        if (text) {
          text.split('').forEach((char, i) => {
            if (digits[i]) digits[i].value = char;
          });
          const nextFocus = Math.min(text.length, digits.length - 1);
          digits[nextFocus].focus();
          collectOtp();
          if (text.length === 6) {
            otpForm.submit();
          }
        }
      });
    });

    function collectOtp() {
      let code = '';
      digits.forEach(d => code += d.value);
      hiddenOtp.value = code;
    }

    otpForm.addEventListener('submit', (e) => {
      collectOtp();
      if (hiddenOtp.value.length !== 6) {
        e.preventDefault();
        alert('Please enter all 6 digits of the verification code.');
        digits[0].focus();
      }
    });

    // Auto-focus first digit
    window.addEventListener('DOMContentLoaded', () => {
      digits[0]?.focus();
    });
  </script>
</body>
</html>
