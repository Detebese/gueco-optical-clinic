<?php
// ============================================================
// ESSENTIAL PATIENT PROFILE SETUP
// Gueco Optical Clinic Management System
// ============================================================

define('BASE_URL', '');
require_once __DIR__ . '/config/functions.php';
startSession();

// 1. Must have active patient session
if (empty($_SESSION['patient_id'])) {
    header('Location: index.php');
    exit;
}

// 2. Must be 2FA OTP verified
if (!isPatient2FAVerified()) {
    header('Location: verify-otp.php');
    exit;
}

$patientId = (int)$_SESSION['patient_id'];
$db = getDB();

// 3. If profile is already complete, redirect to dashboard
if (isPatientProfileComplete($patientId)) {
    header('Location: patient/dashboard.php');
    exit;
}

// Fetch current patient record
$stmt = $db->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: index.php');
    exit;
}

$error = '';
$errorField = '';

// Handle POST Save Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_essential_profile') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $lastName   = sanitize(trim($_POST['last_name'] ?? ''));
        $firstName  = sanitize(trim($_POST['first_name'] ?? ''));
        $middleName = sanitize(trim($_POST['middle_name'] ?? ''));

        // Format names with proper capitalization
        $lastName   = ucwords(strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $lastName)));
        $firstName  = ucwords(strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $firstName)));
        $middleName = ucwords(strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $middleName)));

        // Synchronize full_name: First [Middle] Last
        $nameParts = array_filter([$firstName, $middleName, $lastName]);
        $fullName  = implode(' ', $nameParts);
        
        $phone     = sanitize(trim($_POST['phone'] ?? ''));
        $gender    = sanitize(trim($_POST['gender'] ?? ''));
        $address   = sanitize(trim($_POST['address'] ?? ''));
        $birthdate = sanitize(trim($_POST['birthdate'] ?? ''));

        if (empty($lastName)) {
            $error = 'Please enter your last name.';
            $errorField = 'last_name';
        } elseif (empty($firstName)) {
            $error = 'Please enter your first name.';
            $errorField = 'first_name';
        } elseif (empty($phone)) {
            $error = 'Please provide your 11-digit mobile contact number.';
            $errorField = 'phone';
        } elseif (strlen(preg_replace('/[^0-9]/', '', $phone)) !== 11) {
            $error = 'Contact number must be exactly 11 digits (e.g., 09123456789).';
            $errorField = 'phone';
        } elseif (empty($gender) || !in_array($gender, ['male', 'female', 'other'])) {
            $error = 'Please select your biological sex / gender.';
            $errorField = 'gender';
        } elseif (empty($address)) {
            $error = 'Please enter your residential address.';
            $errorField = 'address';
        } else {
            try {
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

                // Standardize birthdate into ISO-8601 (YYYY-MM-DD) for MySQL 8 strict mode compatibility
                $birthdateFormatted = null;
                if (!empty($birthdate)) {
                    $ts = strtotime($birthdate);
                    if ($ts !== false) {
                        $birthdateFormatted = date('Y-m-d', $ts);
                    }
                }

                // Check table columns to be completely resilient across database versions
                $existingCols = [];
                try {
                    $colStmt = $db->query("SHOW COLUMNS FROM patients");
                    if ($colStmt) {
                        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } catch (Exception $e) {}

                if (!empty($existingCols)) {
                    if (!in_array('first_name', $existingCols)) {
                        try { $db->exec("ALTER TABLE patients ADD COLUMN first_name VARCHAR(100) NULL AFTER id"); } catch (Exception $e) {}
                    }
                    if (!in_array('middle_name', $existingCols)) {
                        try { $db->exec("ALTER TABLE patients ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name"); } catch (Exception $e) {}
                    }
                    if (!in_array('last_name', $existingCols)) {
                        try { $db->exec("ALTER TABLE patients ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name"); } catch (Exception $e) {}
                    }
                }

                // Re-verify existing columns after migration attempt
                try {
                    $colStmt = $db->query("SHOW COLUMNS FROM patients");
                    if ($colStmt) {
                        $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } catch (Exception $e) {}

                $hasFirstName  = in_array('first_name', $existingCols);
                $hasMiddleName = in_array('middle_name', $existingCols);
                $hasLastName   = in_array('last_name', $existingCols);

                $setClauses = [];
                $params = [];

                if ($hasFirstName) {
                    $setClauses[] = "first_name = ?";
                    $params[] = $firstName;
                }
                if ($hasMiddleName) {
                    $setClauses[] = "middle_name = ?";
                    $params[] = (!empty($middleName) ? $middleName : null);
                }
                if ($hasLastName) {
                    $setClauses[] = "last_name = ?";
                    $params[] = $lastName;
                }

                $setClauses[] = "full_name = ?";
                $params[] = $fullName;

                $setClauses[] = "phone = ?";
                $params[] = $cleanPhone;

                $setClauses[] = "gender = ?";
                $params[] = $gender;

                $setClauses[] = "address = ?";
                $params[] = $address;

                $setClauses[] = "birthdate = COALESCE(?, birthdate)";
                $params[] = $birthdateFormatted;

                $setClauses[] = "updated_at = NOW()";

                $params[] = $patientId;
                $sql = "UPDATE patients SET " . implode(", ", $setClauses) . " WHERE id = ?";
                $update = $db->prepare($sql);
                $update->execute($params);

                // Update session state
                $_SESSION['patient_name']       = $fullName;
                $_SESSION['patient_first_name'] = $firstName;
                $_SESSION['patient_last_name']  = $lastName;
                $_SESSION['patient_phone']      = $cleanPhone;
                $_SESSION['patient_gender']     = $gender;
                $_SESSION['patient_address']    = $address;
                $_SESSION['patient_birthdate']  = $birthdateFormatted;
                $_SESSION['flash_msg']    = 'Profile successfully setup! Welcome to your patient portal, ' . htmlspecialchars($firstName) . '.';
                $_SESSION['flash_type']   = 'success';
                $_SESSION['flash_title']  = 'Welcome!';

                header('Location: patient/dashboard.php');
                exit;

            } catch (Exception $e) {
                error_log("Failed updating essential profile for patient {$patientId}: " . $e->getMessage());
                $error = 'Failed to save details: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

$userTheme = $_COOKIE['gueco_theme'] ?? ($_COOKIE['theme'] ?? 'dark');
$currentTheme = ($userTheme === 'light') ? 'light' : 'dark';

// Default values
$valLastName   = htmlspecialchars($_POST['last_name'] ?? $patient['last_name'] ?? '');
$valFirstName  = htmlspecialchars($_POST['first_name'] ?? $patient['first_name'] ?? '');
$valMiddleName = htmlspecialchars($_POST['middle_name'] ?? $patient['middle_name'] ?? '');

// Pre-fill from full_name if first_name/last_name were not set yet
if (empty($valFirstName) && empty($valLastName) && !empty($patient['full_name'])) {
    $parts = preg_split('/\s+/', trim($patient['full_name']));
    if (count($parts) === 1) {
        $valFirstName = htmlspecialchars($parts[0]);
    } elseif (count($parts) === 2) {
        $valFirstName = htmlspecialchars($parts[0]);
        $valLastName  = htmlspecialchars($parts[1]);
    } elseif (count($parts) >= 3) {
        $valLastName   = htmlspecialchars(array_pop($parts));
        $valMiddleName = htmlspecialchars(array_pop($parts));
        $valFirstName  = htmlspecialchars(implode(' ', $parts));
    }
}
$valPhone   = htmlspecialchars($_POST['phone'] ?? $patient['phone'] ?? '');
$valGender  = htmlspecialchars($_POST['gender'] ?? $patient['gender'] ?? '');
$valAddress = htmlspecialchars($_POST['address'] ?? $patient['address'] ?? '');

$rawBirth = $_POST['birthdate'] ?? $patient['birthdate'] ?? '';
$valBirth = '';
if (!empty($rawBirth)) {
    $ts = strtotime($rawBirth);
    $valBirth = $ts !== false ? date('Y-m-d', $ts) : htmlspecialchars($rawBirth);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $currentTheme ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Complete Your Profile — Gueco Optical Clinic</title>
  
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
      --bg-card:       rgba(20, 19, 23, 0.86);
      --bg-input:      rgba(15, 14, 18, 0.9);
      --text-primary:  #F9FAFB;
      --text-muted:    #9CA3AF;
      --text-subtle:   #6B7280;
      --border-color:  rgba(255, 255, 255, 0.09);
      --border-glow:   rgba(0, 173, 239, 0.35);
      --pill-bg:       rgba(255, 255, 255, 0.04);
      --pill-border:   rgba(255, 255, 255, 0.08);
      --card-shadow:   0 32px 80px -16px rgba(0,0,0,0.92), 0 0 0 1px rgba(255,255,255,0.08) inset;
    }

    [data-theme="light"] {
      --bg-body:       #F0F4F9;
      --bg-card:       rgba(255, 255, 255, 0.94);
      --bg-input:      #FFFFFF;
      --text-primary:  #18181B;
      --text-muted:    #71717A;
      --text-subtle:   #A1A1AA;
      --border-color:  rgba(0, 0, 0, 0.1);
      --border-glow:   rgba(35, 94, 174, 0.3);
      --pill-bg:       rgba(255, 255, 255, 0.9);
      --pill-border:   rgba(0, 0, 0, 0.08);
      --card-shadow:   0 24px 60px -12px rgba(35, 94, 174, 0.15), 0 0 0 1px rgba(255,255,255,0.9) inset;
    }

    body {
      font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
      background: var(--bg-body);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 20px;
      position: relative;
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

    .setup-card {
      width: 100%;
      max-width: 540px;
      background: var(--bg-card);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid var(--border-color);
      border-radius: 26px;
      padding: 38px 36px;
      box-shadow: var(--card-shadow);
      position: relative;
      animation: setupIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes setupIn {
      from { opacity: 0; transform: translateY(18px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .header-icon {
      width: 64px;
      height: 64px;
      border-radius: 20px;
      background: linear-gradient(135deg, rgba(35,94,174,0.18), rgba(0,173,239,0.22));
      border: 1px solid var(--border-glow);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      color: var(--clr-gold);
      font-size: 26px;
      box-shadow: 0 10px 25px -5px rgba(0,173,239,0.3);
    }

    .setup-title {
      font-size: 1.65rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      margin-bottom: 6px;
      color: var(--text-primary);
    }

    .setup-subtitle {
      font-size: 0.88rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 24px;
    }

    .form-group {
      margin-bottom: 18px;
      text-align: left;
    }

    .form-label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--text-muted);
      margin-bottom: 8px;
    }

    .form-control, .form-select {
      width: 100%;
      height: 48px;
      padding: 10px 16px;
      font-size: 0.92rem;
      font-family: inherit;
      border-radius: 12px;
      background: var(--bg-input);
      border: 1.5px solid var(--border-color);
      color: var(--text-primary);
      transition: all 0.2s ease;
    }
    textarea.form-control {
      height: auto;
      min-height: 80px;
      resize: vertical;
    }
    .form-control:focus, .form-select:focus {
      outline: none;
      border-color: var(--clr-accent);
      box-shadow: 0 0 0 3px rgba(0,173,239,0.25);
    }
    .form-control.is-invalid, .form-select.is-invalid {
      border-color: var(--clr-danger) !important;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2) !important;
    }

    .btn-submit {
      width: 100%;
      height: 52px;
      background: linear-gradient(135deg, #235EAE 0%, #1c4b8b 100%);
      color: #FFFFFF;
      font-size: 0.98rem;
      font-weight: 800;
      border: none;
      border-radius: 14px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 8px 24px -4px rgba(35,94,174,0.45);
      transition: all 0.25s ease;
      margin-top: 10px;
    }
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px -4px rgba(35,94,174,0.6);
      background: linear-gradient(135deg, #276ac5 0%, #20559e 100%);
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
      background: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #F87171;
    }

    .privacy-notice {
      font-size: 0.76rem;
      color: var(--text-subtle);
      margin-top: 18px;
      line-height: 1.5;
      text-align: center;
    }
  </style>
</head>
<body>

  <!-- Theme Toggle -->
  <button class="theme-toggle-btn" id="themeBtn" title="Toggle Theme">
    <i class="fas fa-sun" id="themeIcon"></i>
  </button>

  <div class="setup-card">
    <div class="text-center">
      <div class="header-icon">
        <i class="fas fa-id-card"></i>
      </div>
      <h1 class="setup-title">Complete Your Profile</h1>
      <p class="setup-subtitle">
        Please fill in your essential medical record details before proceeding to your Patient Dashboard.
      </p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert-custom">
        <i class="fas fa-circle-exclamation mt-1"></i>
        <div><?= htmlspecialchars($error) ?></div>
      </div>
    <?php endif; ?>

    <form method="POST" id="profileForm">
      <input type="hidden" name="action" value="save_essential_profile">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

      <!-- Name Fields (Last Name, First Name, Middle Name) -->
      <div class="row g-3">
        <div class="col-sm-6 form-group">
          <label class="form-label"><i class="fas fa-user me-1"></i>Last Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="last_name" class="form-control <?= $errorField === 'last_name' ? 'is-invalid' : '' ?>" placeholder="e.g. Dela Cruz" value="<?= $valLastName ?>" required onblur="formatNameField(this)">
        </div>
        <div class="col-sm-6 form-group">
          <label class="form-label"><i class="fas fa-user me-1"></i>First Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="first_name" class="form-control <?= $errorField === 'first_name' ? 'is-invalid' : '' ?>" placeholder="e.g. Juan" value="<?= $valFirstName ?>" required onblur="formatNameField(this)">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label"><i class="fas fa-user me-1"></i>Middle Name <span style="color:var(--text-subtle);font-weight:400;text-transform:none;">(optional)</span></label>
        <input type="text" name="middle_name" class="form-control <?= $errorField === 'middle_name' ? 'is-invalid' : '' ?>" placeholder="e.g. Santos (leave blank if none)" value="<?= $valMiddleName ?>" onblur="formatNameField(this)">
      </div>

      <!-- Contact Number & Sex -->
      <div class="row g-3">
        <div class="col-sm-7 form-group">
          <label class="form-label"><i class="fas fa-phone me-1"></i>Contact Number <span style="color:var(--clr-danger)">*</span></label>
          <input type="tel" name="phone" maxlength="11" minlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="form-control" placeholder="09XXXXXXXXX" value="<?= $valPhone ?>" required>
        </div>
        <div class="col-sm-5 form-group">
          <label class="form-label"><i class="fas fa-venus-mars me-1"></i>Sex <span style="color:var(--clr-danger)">*</span></label>
          <select name="gender" class="form-select" required>
            <option value="">Select</option>
            <option value="male" <?= $valGender === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= $valGender === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= $valGender === 'other' ? 'selected' : '' ?>>Other</option>
          </select>
        </div>
      </div>

      <!-- Address -->
      <div class="form-group">
        <label class="form-label"><i class="fas fa-map-marker-alt me-1"></i>Residential Address <span style="color:var(--clr-danger)">*</span></label>
        <textarea name="address" class="form-control" placeholder="Barangay, Municipality, Province" rows="2" required><?= $valAddress ?></textarea>
      </div>

      <!-- Birthdate (Optional / Recommended) -->
      <div class="form-group">
        <label class="form-label"><i class="fas fa-birthday-cake me-1"></i>Birthdate <span style="color:var(--text-subtle);font-weight:400;text-transform:none;">(optional)</span></label>
        <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>" class="form-control" value="<?= $valBirth ?>">
      </div>

      <button type="submit" class="btn-submit">
        <i class="fas fa-check-circle"></i> Save Profile &amp; Go to Dashboard
      </button>

      <p class="privacy-notice">
        <i class="fas fa-shield-alt me-1"></i> Your personal details are protected under Republic Act 10173 (Data Privacy Act of 2012) and used exclusively for your optical clinic records.
      </p>
    </form>
  </div>

  <script>
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

    function formatNameField(el) {
      if (!el || !el.value) return;
      el.value = el.value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, c => c.toUpperCase());
    }
  </script>
</body>
</html>
