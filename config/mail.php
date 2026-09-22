<?php
// ============================================================
// SMTP & EMAIL CONFIGURATION
// Gueco Optical Clinic Management System
// ============================================================

// Gmail SMTP Credentials
// To generate your 16-character Google App Password:
// 1. Go to your Google Account -> Security (https://myaccount.google.com/security)
// 2. Enable 2-Step Verification (if not already enabled)
// 3. Search for "App Passwords" (or go to https://myaccount.google.com/apppasswords)
// 4. Create an app named "Gueco Optical Clinic"
// 5. Paste your Gmail address and 16-character App Password below:

if (file_exists(__DIR__ . '/credentials.php')) {
    require_once __DIR__ . '/credentials.php';
}

if (!function_exists('getSmtpSetting')) {
    function getSmtpSetting(string $key, $default = null) {
        $envVal = getenv($key);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
        }
        try {
            if (function_exists('getDB')) {
                $db = getDB();
                $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
                $stmt->execute([strtolower($key)]);
                $row = $stmt->fetch();
                if ($row && !empty($row['setting_value'])) {
                    return $row['setting_value'];
                }
            }
        } catch (Exception $e) {}
        return $default;
    }
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getSmtpSetting('SMTP_HOST', 'smtp.gmail.com'));
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)getSmtpSetting('SMTP_PORT', 587));
}

if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', getSmtpSetting('SMTP_ENCRYPTION', 'tls')); // 'tls' or 'ssl'
}

if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', getSmtpSetting('SMTP_USERNAME', 'bryanxin221@gmail.com'));
}

if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', getSmtpSetting('SMTP_PASSWORD', ''));
}

if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', getSmtpSetting('SMTP_FROM_EMAIL', 'no-reply@guecoopticalclinic.com'));
}

if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', getSmtpSetting('SMTP_FROM_NAME', 'Gueco Optical Clinic'));
}

/**
 * Check if real SMTP credentials are set
 */
function isSMTPConfigured(): bool {
    return defined('SMTP_USERNAME')
        && SMTP_USERNAME !== 'YOUR_GMAIL_ADDRESS_HERE'
        && !empty(SMTP_USERNAME)
        && defined('SMTP_PASSWORD')
        && SMTP_PASSWORD !== 'YOUR_GMAIL_APP_PASSWORD_HERE'
        && !empty(SMTP_PASSWORD);
}
