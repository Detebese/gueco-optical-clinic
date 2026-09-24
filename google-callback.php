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
    // Ensure google_id and avatar columns exist in patients table
    try {
        $colGid = $db->query("SHOW COLUMNS FROM patients LIKE 'google_id'")->fetch();
        if (!$colGid) {
            $db->exec("ALTER TABLE patients ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER email");
        }
        $colAv = $db->query("SHOW COLUMNS FROM patients LIKE 'avatar'")->fetch();
        if (!$colAv) {
            $db->exec("ALTER TABLE patients ADD COLUMN avatar VARCHAR(500) NULL AFTER gender");
        }
    } catch (Exception $eCol) {
        // Table column checks failed or already exist
    }

    // 1. Check if patient exists with this Google ID
    $stmt = $db->prepare("SELECT * FROM patients WHERE google_id = ? LIMIT 1");
    $stmt->execute([$googleId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($patient) {
        // Existing linked account - update avatar if available
        if (!empty($picture) && empty($patient['avatar'])) {
            $db->prepare("UPDATE patients SET avatar = ? WHERE id = ?")->execute([$picture, $patient['id']]);
        }
    } else {
        // 2. Check if a patient exists with the same email (case-insensitive)
        $stmtEmail = $db->prepare("SELECT * FROM patients WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1");
        $stmtEmail->execute([$email]);
        $patient = $stmtEmail->fetch(PDO::FETCH_ASSOC);

        if ($patient) {
            // Existing email account - link Google ID
            $db->prepare("UPDATE patients SET google_id = ?, avatar = COALESCE(avatar, ?) WHERE id = ?")
               ->execute([$googleId, $picture ?: null, $patient['id']]);
        } else {
            // 3. New patient - auto-register
            $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $firstName = trim((string)($userInfo['given_name'] ?? ''));
            $lastName  = trim((string)($userInfo['family_name'] ?? ''));
            if (empty($firstName) && empty($lastName)) {
                $nameParts = preg_split('/\s+/', $fullName);
                $firstName = $nameParts[0] ?? '';
                $lastName  = count($nameParts) > 1 ? end($nameParts) : '';
            }
            $stmtInsert = $db->prepare(
                "INSERT INTO patients (first_name, last_name, full_name, email, password, google_id, avatar, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
            );
            $stmtInsert->execute([$firstName, $lastName, $fullName, $email, $randomPassword, $googleId, $picture ?: null]);
            $newId = (int)$db->lastInsertId();

            $stmtNew = $db->prepare("SELECT * FROM patients WHERE id = ? LIMIT 1");
            $stmtNew->execute([$newId]);
            $patient = $stmtNew->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$patient || ($patient['status'] ?? 'active') === 'inactive') {
        $_SESSION['flash_msg'] = 'Your patient account is inactive. Please contact clinic staff.';
        $_SESSION['flash_type'] = 'error';
        header('Location: index.php');
        exit;
    }

    // Successfully authenticated - establish pending patient session and issue OTP
    session_regenerate_id(true);
    $_SESSION['patient_id']     = (int)$patient['id'];
    $_SESSION['patient_name']   = $patient['full_name'];
    $_SESSION['patient_email']  = $patient['email'];
    $_SESSION['patient_avatar'] = $patient['avatar'] ?: $picture;
    $_SESSION['patient_2fa_verified'] = false;

    // Issue 6-digit verification OTP
    issuePatientLoginOTP($patient);

    // Redirect to verification screen
    header('Location: verify-otp.php');
    exit;

} catch (Exception $e) {
    error_log("Google OAuth database error: " . $e->getMessage());
    $_SESSION['flash_msg'] = 'System error while logging you in with Google. Please try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}
