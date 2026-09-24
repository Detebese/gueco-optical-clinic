<?php
// ============================================================
// GOOGLE OAUTH 2.0 CALLBACK HANDLER
// Gueco Optical Clinic — Patient Portal
// ============================================================

define('BASE_URL', '');
require_once __DIR__ . '/config/functions.php';
startSession();

// Handle user cancellation or error from Google
if (!empty($_GET['error'])) {
    $errorDesc = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    $_SESSION['flash_msg'] = 'Google Sign-In was cancelled or failed: ' . $errorDesc;
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// Verify CSRF state token
$savedState = $_SESSION['google_oauth_state'] ?? '';
$receivedState = $_GET['state'] ?? '';
unset($_SESSION['google_oauth_state']);

if (empty($savedState) || empty($receivedState) || !hash_equals($savedState, $receivedState)) {
    $_SESSION['flash_msg'] = 'Security verification failed (invalid state token). Please try signing in again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// Get authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $_SESSION['flash_msg'] = 'No authorization code received from Google.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// Exchange code for token
$tokenData = exchangeGoogleCode($code);
if (!$tokenData || empty($tokenData['access_token'])) {
    $_SESSION['flash_msg'] = 'Failed to authenticate with Google servers. Please verify your internet connection or Google credentials.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// Retrieve Google User Profile
$userInfo = getGoogleUserInfo($tokenData['access_token']);
if (!$userInfo || empty($userInfo['sub']) || empty($userInfo['email'])) {
    $_SESSION['flash_msg'] = 'Unable to retrieve your Google profile details. Please try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

$googleId = trim((string)$userInfo['sub']);
$email    = strtolower(trim((string)$userInfo['email']));
$fullName = trim((string)($userInfo['name'] ?? 'Google User'));
$picture  = trim((string)($userInfo['picture'] ?? ''));

// Clean up name format
$fullName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fullName);
$fullName = ucwords(strtolower($fullName));

$db = getDB();

try {
    // Ensure google_id, avatar, and auth_provider columns exist in patients table
    try {
        $colGid = $db->query("SHOW COLUMNS FROM patients LIKE 'google_id'")->fetch();
        if (!$colGid) {
            $db->exec("ALTER TABLE patients ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER email");
        }
        $colAv = $db->query("SHOW COLUMNS FROM patients LIKE 'avatar'")->fetch();
        if (!$colAv) {
            $db->exec("ALTER TABLE patients ADD COLUMN avatar VARCHAR(500) NULL AFTER gender");
        }
        $colProv = $db->query("SHOW COLUMNS FROM patients LIKE 'auth_provider'")->fetch();
        if (!$colProv) {
            $db->exec("ALTER TABLE patients ADD COLUMN auth_provider VARCHAR(20) DEFAULT 'email' AFTER status");
            $db->exec("UPDATE patients SET auth_provider = 'email' WHERE auth_provider IS NULL OR auth_provider = ''");
        }
        $colLoginCount = $db->query("SHOW COLUMNS FROM patients LIKE 'login_count'")->fetch();
        if (!$colLoginCount) {
            $db->exec("ALTER TABLE patients ADD COLUMN login_count INT NOT NULL DEFAULT 1");
        }
        $colLastLogin = $db->query("SHOW COLUMNS FROM patients LIKE 'last_login_at'")->fetch();
        if (!$colLastLogin) {
            $db->exec("ALTER TABLE patients ADD COLUMN last_login_at DATETIME NULL");
        }
    } catch (Exception $eCol) {
        // Table column checks failed or already exist
    }

    // Check if a patient already exists with this email (case-insensitive)
    $stmtEmail = $db->prepare("SELECT * FROM patients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtEmail->execute([$email]);
    $patient = $stmtEmail->fetch(PDO::FETCH_ASSOC);

    if ($patient) {
        // Check if account was registered with email & password (or auth_provider is 'email')
        $isEmailRegistered = empty($patient['auth_provider']) 
                          || $patient['auth_provider'] === 'email';

        if ($isEmailRegistered) {
            // Patient registered with email & password: require email and password login
            $_SESSION['flash_msg']     = 'This account (' . htmlspecialchars($email) . ') is already registered. Please log in using your email and password.';
            $_SESSION['flash_type']    = 'warning';
            $_SESSION['flash_title']   = 'Account Already Registered';
            $_SESSION['prefill_email'] = $email;

            header('Location: index.php?tab=login&email=' . urlencode($email) . '&existing=1');
            exit;
        }

        // Account was created via Google — update avatar if provided
        if (!empty($picture) && empty($patient['avatar'])) {
            $db->prepare("UPDATE patients SET avatar = ? WHERE id = ?")->execute([$picture, $patient['id']]);
        }
    } else {
        // New patient registering with Google
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $firstName = trim((string)($userInfo['given_name'] ?? ''));
        $lastName  = trim((string)($userInfo['family_name'] ?? ''));
        if (empty($firstName) && empty($lastName)) {
            $nameParts = preg_split('/\s+/', $fullName);
            $firstName = $nameParts[0] ?? '';
            $lastName  = count($nameParts) > 1 ? end($nameParts) : '';
        }
        $stmtInsert = $db->prepare(
            "INSERT INTO patients (first_name, last_name, full_name, email, password, google_id, avatar, auth_provider, status, login_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'google', 'active', 1)"
        );
        $stmtInsert->execute([$firstName, $lastName, $fullName, $email, $randomPassword, $googleId, $picture ?: null]);
        $newId = (int)$db->lastInsertId();

        $stmtNew = $db->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
        $stmtNew->execute([$newId]);
        $patient = $stmtNew->fetch(PDO::FETCH_ASSOC);
    }

    if (!$patient || ($patient['status'] ?? 'active') === 'inactive') {
        $_SESSION['flash_msg'] = 'Your patient account is inactive. Please contact clinic staff.';
        $_SESSION['flash_type'] = 'error';
        header('Location: index.php');
        exit;
    }

    // Successfully authenticated with Google - establish patient session
    session_regenerate_id(true);
    $_SESSION['patient_id']     = (int)$patient['id'];
    $_SESSION['patient_name']   = $patient['full_name'];
    $_SESSION['patient_email']  = $patient['email'];
    $_SESSION['patient_avatar'] = $patient['avatar'] ?: $picture;
    $_SESSION['patient_2fa_verified'] = true;

    $isComplete = isPatientProfileComplete((int)$patient['id']);
    $currentLogins = (int)($patient['login_count'] ?? 0);
    $newLogins = $isComplete ? max(2, $currentLogins + 1) : max(1, $currentLogins);

    $db->prepare("UPDATE patients SET login_count = ?, last_login_at = NOW() WHERE id = ?")
       ->execute([$newLogins, $patient['id']]);

    logActivity('Patient Login (Google)', 'Auth', (int)$patient['id'], 'patient');

    // Check if essential profile setup is complete
    if (!$isComplete) {
        header('Location: complete-profile.php');
        exit;
    }

    $isFirstLogin = ($newLogins <= 1);
    $displayName = !empty($patient['first_name']) 
        ? $patient['first_name'] 
        : (!empty($patient['full_name']) ? explode(' ', trim($patient['full_name']))[0] : 'Patient');

    if ($isFirstLogin) {
        $_SESSION['flash_msg']   = 'Signed in successfully! Welcome, ' . htmlspecialchars($displayName) . '.';
        $_SESSION['flash_type']  = 'success';
        $_SESSION['flash_title'] = 'Welcome!';
    } else {
        $_SESSION['flash_msg']   = 'Welcome back, ' . htmlspecialchars($displayName) . '!';
        $_SESSION['flash_type']  = 'success';
        $_SESSION['flash_title'] = 'Welcome Back!';
    }

    // Redirect directly to dashboard
    header('Location: patient/dashboard.php');
    exit;

} catch (Exception $e) {
    error_log("Google OAuth database error: " . $e->getMessage());
    $_SESSION['flash_msg'] = 'System error while logging you in with Google. Please try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}
