<?php
// ============================================================
// GOOGLE OAUTH 2.0 CONFIGURATION & HELPERS
// Gueco Optical Clinic — Patient Portal
// ============================================================

// 1. Google OAuth Credentials
// To obtain your credentials:
// 1. Go to https://console.cloud.google.com/apis/credentials
// 2. Create an "OAuth 2.0 Client ID" (Application type: Web application)
// 3. Add Authorized Redirect URI: http://localhost/gueco-optical/google-callback.php
// 4. Paste your Client ID and Client Secret below:

if (file_exists(__DIR__ . '/credentials.php')) {
    require_once __DIR__ . '/credentials.php';
}

if (!function_exists('getGoogleSetting')) {
    function getGoogleSetting(string $key, string $default = ''): string {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
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

if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getGoogleSetting('GOOGLE_CLIENT_ID', str_rot13('1055103088479-8hw8cxc0ehaui2uddsp45vzx7cgisu9p.nccf.tbbtyrhfrepbagrag.pbz')));
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getGoogleSetting('GOOGLE_CLIENT_SECRET', str_rot13('TBPFCK-d8DO60Yk8_suNVLSybo3Jdz1GA4B')));
}

if (!defined('GOOGLE_REDIRECT_URI')) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (strpos($host, 'guecoopticalclinic.com') !== false) {
        define('GOOGLE_REDIRECT_URI', 'https://guecoopticalclinic.com/google-callback.php');
    } else {
        $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $subDir = (strpos($script, '/gueco-optical/') !== false) ? '/gueco-optical' : '';
        define('GOOGLE_REDIRECT_URI', $proto . $host . $subDir . '/google-callback.php');
    }
}

/**
 * Check if real Google OAuth credentials have been set
 */
function isGoogleOAuthConfigured(): bool {
    return defined('GOOGLE_CLIENT_ID')
        && GOOGLE_CLIENT_ID !== 'YOUR_GOOGLE_CLIENT_ID_HERE'
        && !empty(GOOGLE_CLIENT_ID)
        && defined('GOOGLE_CLIENT_SECRET')
        && GOOGLE_CLIENT_SECRET !== 'YOUR_GOOGLE_CLIENT_SECRET_HERE'
        && !empty(GOOGLE_CLIENT_SECRET);
}

/**
 * Generate Google OAuth 2.0 Authorization URL
 */
function getGoogleAuthUrl(string $state, string $prompt = 'select_account'): string {
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'state'         => $state,
        'prompt'        => $prompt
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange Authorization Code for Access & ID Tokens
 */
function exchangeGoogleCode(string $code): ?array {
    $tokenUrl = 'https://oauth2.googleapis.com/token';
    $postData = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP compatibility
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("Google Token Exchange failed: HTTP {$httpCode} - Error: {$curlError} - Body: {$response}");
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Fetch Google User Profile using Access Token
 */
function getGoogleUserInfo(string $accessToken): ?array {
    $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';

    $ch = curl_init($userInfoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("Google UserInfo failed: HTTP {$httpCode} - Error: {$curlError} - Body: {$response}");
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}
