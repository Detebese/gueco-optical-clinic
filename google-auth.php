<?php
// ============================================================
// GOOGLE OAUTH 2.0 REDIRECT HANDLER
// Gueco Optical Clinic — Patient Portal
// ============================================================

define('BASE_URL', '');
require_once __DIR__ . '/config/functions.php';
startSession();

// If already logged in, redirect to dashboard
if (isPatientLoggedIn()) {
    header('Location: patient/dashboard.php');
    exit;
}

// Check if credentials are configured
if (!isGoogleOAuthConfigured()) {
    $currentTheme = ($_COOKIE['gueco_theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="<?= $currentTheme ?>">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Google Sign-In Setup — Gueco Optical Clinic</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
      <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
          --bg-body: #0F172A;
          --bg-card: #1E293B;
          --text-primary: #F8FAFC;
          --text-muted: #94A3B8;
          --border: rgba(255,255,255,0.12);
          --clr-primary: #00ADEF;
        }
        [data-theme="light"] {
          --bg-body: #F8FAFC;
          --bg-card: #FFFFFF;
          --text-primary: #0F172A;
          --text-muted: #64748B;
          --border: #E2E8F0;
          --clr-primary: #0284C7;
        }
        body {
          font-family: 'Plus Jakarta Sans', sans-serif;
          background: var(--bg-body);
          color: var(--text-primary);
          min-height: 100vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 24px;
        }
        .setup-card {
          background: var(--bg-card);
          border: 1.5px solid var(--border);
          border-radius: 24px;
          max-width: 580px;
          width: 100%;
          padding: 36px 32px;
          box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35);
        }
        .google-icon-header {
          width: 64px; height: 64px; border-radius: 18px;
          background: rgba(0,173,239,0.12); border: 1.5px solid rgba(0,173,239,0.25);
          display: flex; align-items: center; justify-content: center;
          margin-bottom: 20px; font-size: 1.8rem;
        }
        h1 { font-size: 1.45rem; font-weight: 900; margin-bottom: 10px; }
        p { font-size: .92rem; color: var(--text-muted); line-height: 1.6; margin-bottom: 22px; }
        .steps {
          background: rgba(0,0,0,0.15); border: 1px solid var(--border);
          border-radius: 16px; padding: 20px; margin-bottom: 24px;
          display: flex; flex-direction: column; gap: 14px;
          font-size: .88rem;
        }
        .step-item { display: flex; gap: 12px; align-items: flex-start; }
        .step-num {
          width: 24px; height: 24px; border-radius: 50%;
          background: var(--clr-primary); color: #fff;
          display: flex; align-items: center; justify-content: center;
          font-weight: 800; font-size: .75rem; flex-shrink: 0;
        }
        code {
          background: rgba(0,173,239,0.12); color: var(--clr-primary);
          padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: .84rem;
        }
        .btn-back {
          display: inline-flex; align-items: center; gap: 8px;
          padding: 12px 24px; border-radius: 12px; font-weight: 800;
          font-size: .92rem; background: var(--clr-primary); color: #fff;
          text-decoration: none; transition: transform .15s ease;
        }
        .btn-back:hover { transform: translateY(-2px); color: #fff; }
      </style>
    </head>
    <body>
      <div class="setup-card">
        <div class="google-icon-header">
          <i class="fab fa-google" style="color:#EA4335;"></i>
        </div>
        <h1>Google Sign-In Configuration Required</h1>
        <p>To enable real Google authentication, you need to add your <strong>Google OAuth Credentials</strong> in your project configuration file.</p>
        
        <div class="steps">
          <div class="step-item">
            <span class="step-num">1</span>
            <div>Go to the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--clr-primary);font-weight:700;">Google Cloud Console Credentials page</a>.</div>
          </div>
          <div class="step-item">
            <span class="step-num">2</span>
            <div>Create an <strong>OAuth 2.0 Client ID</strong> with application type <strong>Web application</strong>.</div>
          </div>
          <div class="step-item">
            <span class="step-num">3</span>
            <div>Add this Authorized redirect URI:<br><code style="word-break:break-all;display:inline-block;margin-top:4px;"><?= htmlspecialchars(GOOGLE_REDIRECT_URI) ?></code></div>
          </div>
          <div class="step-item">
            <span class="step-num">4</span>
            <div>Open <code>config/google_oauth.php</code> and paste your <code>GOOGLE_CLIENT_ID</code> and <code>GOOGLE_CLIENT_SECRET</code>.</div>
          </div>
        </div>

        <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Return to Clinic Home</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Generate CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

// Redirect to Google Consent Screen
$authUrl = getGoogleAuthUrl($state);
header('Location: ' . $authUrl);
exit;
