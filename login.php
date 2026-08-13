<?php
define('BASE_URL', '');
require_once 'config/functions.php';
startSession();

if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl($_SESSION['user_role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['user_email'] = $user['email'];

                logActivity('Login', 'Auth', $user['id']);
                header('Location: ' . getDashboardUrl($user['role']));
                exit;
            } else {
                $error = 'Invalid email or password. Please try again.';
            }
        } catch (Exception $e) {
            $error = 'System error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Login — Gueco Optical Clinic</title>
  <meta name="description" content="Gueco Optical Clinic Staff Management Portal">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --clr-primary:   #2563EB;
      --clr-secondary: #7C3AED;
      --clr-accent:    #0EA5E9;
      --clr-success:   #059669;
      --clr-danger:    #DC2626;
      --clr-warning:   #D97706;
    }

    [data-theme="dark"] {
      --bg-body:   #0F172A;
      --bg-card:   rgba(30,41,59,.7);
      --bg-input:  rgba(15,23,42,.6);
      --text-primary: #F1F5F9;
      --text-muted:   #94A3B8;
      --border-color: rgba(255,255,255,.08);
    }
    [data-theme="light"] {
      --bg-body:   #EFF6FF;
      --bg-card:   rgba(255,255,255,.75);
      --bg-input:  rgba(255,255,255,.9);
      --text-primary: #0F172A;
      --text-muted:   #64748B;
      --border-color: rgba(0,0,0,.08);
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: var(--bg-body);
      min-height: 100vh;
      overflow: hidden;
      position: relative;
    }

    /* Animated mesh gradient background */
    .bg-mesh {
      position: fixed;
      inset: 0;
      z-index: 0;
      background:
        radial-gradient(ellipse 80% 80% at 20% 20%, rgba(37,99,235,.35) 0%, transparent 60%),
        radial-gradient(ellipse 60% 60% at 80% 80%, rgba(124,58,237,.3) 0%, transparent 60%),
        radial-gradient(ellipse 50% 50% at 50% 100%, rgba(14,165,233,.2) 0%, transparent 60%);
      animation: meshShift 12s ease-in-out infinite alternate;
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 80% 80% at 20% 20%, rgba(37,99,235,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 60% at 80% 80%, rgba(124,58,237,.15) 0%, transparent 60%),
        radial-gradient(ellipse 50% 50% at 50% 100%, rgba(14,165,233,.1) 0%, transparent 60%);
    }
    @keyframes meshShift {
      0%   { filter: hue-rotate(0deg) brightness(1); }
      100% { filter: hue-rotate(20deg) brightness(1.1); }
    }

    /* Floating orbs */
    .orb {
      position: fixed;
      border-radius: 50%;
      filter: blur(80px);
      opacity: .25;
      animation: orbFloat linear infinite;
      z-index: 0;
      pointer-events: none;
    }
    .orb-1 { width: 400px; height: 400px; background: var(--clr-primary);   top: -100px; left: -100px;  animation-duration: 18s; }
    .orb-2 { width: 300px; height: 300px; background: var(--clr-secondary); bottom: -80px; right: -80px; animation-duration: 14s; animation-delay: -5s; }
    .orb-3 { width: 200px; height: 200px; background: var(--clr-accent);    top: 40%;  left: 60%;       animation-duration: 22s; animation-delay: -9s; }
    @keyframes orbFloat {
      0%,100% { transform: translateY(0) scale(1);   }
      33%      { transform: translateY(-30px) scale(1.05); }
      66%      { transform: translateY(20px) scale(.95);  }
    }

    /* Grid overlay */
    .bg-grid {
      position: fixed;
      inset: 0;
      z-index: 0;
      background-image:
        linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
      background-size: 48px 48px;
    }
    [data-theme="light"] .bg-grid {
      background-image:
        linear-gradient(rgba(0,0,0,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,.04) 1px, transparent 1px);
    }

    /* Layout */
    .page-wrap {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: grid;
      grid-template-columns: 1fr 1fr;
    }
    @media (max-width: 768px) { .page-wrap { grid-template-columns: 1fr; } .left-panel { display: none; } }

    /* Left panel */
    .left-panel {
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 60px 56px;
    }

    .clinic-badge {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: rgba(37,99,235,.15);
      border: 1px solid rgba(37,99,235,.3);
      border-radius: 100px;
      padding: 8px 20px 8px 8px;
      margin-bottom: 40px;
      width: fit-content;
      backdrop-filter: blur(10px);
    }
    .clinic-badge-dot {
      width: 32px; height: 32px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: .8rem;
    }
    .clinic-badge span {
      font-size: .78rem;
      font-weight: 600;
      color: var(--text-primary);
      letter-spacing: .02em;
    }

    .left-title {
      font-size: 3rem;
      font-weight: 900;
      line-height: 1.1;
      margin-bottom: 16px;
      background: linear-gradient(135deg, #fff 0%, rgba(255,255,255,.6) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    [data-theme="light"] .left-title {
      background: linear-gradient(135deg, var(--clr-primary) 0%, var(--clr-secondary) 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .left-subtitle {
      font-size: .95rem;
      color: var(--text-muted);
      margin-bottom: 48px;
      line-height: 1.6;
      max-width: 380px;
    }

    /* Feature pills */
    .feature-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .feature-pill {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      backdrop-filter: blur(16px);
      border-radius: 14px;
      padding: 14px 16px;
      transition: transform .2s, border-color .2s;
    }
    .feature-pill:hover { transform: translateY(-2px); border-color: rgba(37,99,235,.3); }
    .fp-icon {
      width: 38px; height: 38px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: .85rem; flex-shrink: 0;
    }
    .fp-icon.blue   { background: rgba(37,99,235,.15);  color: #60A5FA; }
    .fp-icon.purple { background: rgba(124,58,237,.15); color: #A78BFA; }
    .fp-icon.green  { background: rgba(5,150,105,.15);  color: #34D399; }
    .fp-icon.orange { background: rgba(217,119,6,.15);  color: #FCD34D; }
    .fp-label { font-size: .78rem; font-weight: 600; color: var(--text-primary); }
    .fp-desc  { font-size: .68rem; color: var(--text-muted); margin-top: 2px; }

    /* Right panel / card */
    .right-panel {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 48px;
    }

    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--bg-card);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid var(--border-color);
      border-radius: 24px;
      padding: 40px;
      box-shadow: 0 32px 80px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.05) inset;
    }
    [data-theme="light"] .login-card {
      box-shadow: 0 20px 60px rgba(37,99,235,.12), 0 0 0 1px rgba(255,255,255,.7) inset;
    }

    /* Card header */
    .card-logo {
      width: 56px; height: 56px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 1.3rem;
      margin-bottom: 20px;
      box-shadow: 0 8px 24px rgba(37,99,235,.4);
    }
    .card-title {
      font-size: 1.55rem;
      font-weight: 800;
      color: var(--text-primary);
      margin-bottom: 4px;
    }
    .card-subtitle {
      font-size: .82rem;
      color: var(--text-muted);
      margin-bottom: 28px;
    }



    /* Inputs */
    .field-wrap { margin-bottom: 18px; }
    .field-label {
      display: block;
      font-size: .75rem;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: 8px;
    }
    .field-input-wrap { position: relative; }
    .field-icon {
      position: absolute;
      left: 14px; top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      font-size: .82rem;
      pointer-events: none;
      transition: color .2s;
    }
    .field-control {
      width: 100%;
      background: var(--bg-input);
      border: 1.5px solid var(--border-color);
      border-radius: 12px;
      padding: 13px 44px;
      font-family: 'Poppins', sans-serif;
      font-size: .88rem;
      color: var(--text-primary);
      transition: border-color .2s, box-shadow .2s;
      outline: none;
    }
    .field-control::placeholder { color: var(--text-muted); }
    .field-control:focus {
      border-color: var(--clr-primary);
      box-shadow: 0 0 0 3px rgba(37,99,235,.12);
    }
    .field-control:focus ~ .field-icon,
    .field-input-wrap:has(.field-control:focus) .field-icon { color: var(--clr-primary); }
    .pass-eye {
      position: absolute;
      right: 14px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      color: var(--text-muted); cursor: pointer;
      font-size: .82rem; padding: 4px;
      transition: color .2s;
    }
    .pass-eye:hover { color: var(--clr-primary); }

    /* Hide native Edge password reveal */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
      display: none;
    }

    /* Error */
    .err-box {
      display: flex;
      align-items: center;
      gap: 10px;
      background: rgba(220,38,38,.1);
      border: 1px solid rgba(220,38,38,.25);
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 18px;
      font-size: .8rem;
      color: #F87171;
      animation: fadeIn .3s ease;
    }

    /* Submit button */
    .btn-login {
      width: 100%;
      padding: 14px;
      background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary));
      color: #fff;
      font-family: 'Poppins', sans-serif;
      font-size: .9rem;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      transition: all .2s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      position: relative;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(37,99,235,.35);
      margin-top: 8px;
    }
    .btn-login::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,.15), transparent);
      opacity: 0;
      transition: opacity .2s;
    }
    .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(37,99,235,.5); }
    .btn-login:hover::before { opacity: 1; }
    .btn-login:active { transform: translateY(0); }

    /* Divider */
    .divider {
      display: flex; align-items: center; gap: 12px;
      margin: 20px 0;
      color: var(--text-muted); font-size: .72rem;
    }
    .divider::before, .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border-color);
    }

    /* Patient link */
    .btn-patient {
      width: 100%;
      padding: 13px;
      background: transparent;
      border: 1.5px solid var(--border-color);
      border-radius: 12px;
      color: var(--text-primary);
      font-family: 'Poppins', sans-serif;
      font-size: .85rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: all .2s;
    }
    .btn-patient:hover {
      border-color: var(--clr-primary);
      color: #60A5FA;
      background: rgba(37,99,235,.06);
    }

    /* Theme toggle */
    .theme-btn {
      position: fixed;
      top: 20px; right: 20px;
      z-index: 100;
      width: 40px; height: 40px;
      border-radius: 50%;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      backdrop-filter: blur(12px);
      color: var(--text-primary);
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem;
      transition: all .2s;
    }
    .theme-btn:hover { transform: scale(1.1) rotate(15deg); border-color: var(--clr-primary); }

    /* Footer */
    .card-footer-txt {
      text-align: center;
      margin-top: 22px;
      font-size: .7rem;
      color: var(--text-muted);
    }

    /* Loading shimmer on button */
    @keyframes shimmer {
      0%   { background-position: -200% center; }
      100% { background-position: 200% center; }
    }
    .btn-login.loading {
      background: linear-gradient(90deg, var(--clr-primary) 25%, var(--clr-secondary) 50%, var(--clr-primary) 75%);
      background-size: 200% auto;
      animation: shimmer 1.2s linear infinite;
    }
  </style>
</head>
<body>
  <!-- Backgrounds -->
  <div class="bg-mesh"></div>
  <div class="bg-grid"></div>
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>

  <!-- Theme Toggle -->
  <button class="theme-btn" id="themeToggle" title="Toggle Theme">
    <i class="fas fa-moon" id="themeIcon"></i>
  </button>

  <div class="page-wrap">

    <!-- ======= LEFT PANEL ======= -->
    <div class="left-panel">

      <div class="clinic-badge">
        <div class="clinic-badge-dot" style="background:rgba(255,255,255,0.9); box-shadow:0 4px 12px rgba(37,99,235,0.2); border-radius:50%; padding:2px;"><img src="assets/images/logo.png?v=2" alt="Logo" style="width:100%; height:100%; object-fit:contain; border-radius:50%;"></div>
        <span>Gueco Optical Clinic — Capas, Tarlac</span>
      </div>

      <h1 class="left-title">Modern Clinic<br>Management<br>System</h1>
      <p class="left-subtitle">
        A complete digital solution for Gueco Optical Clinic — streamlining appointments, prescriptions, inventory, and sales in one powerful platform.
      </p>

      <div class="feature-grid">
        <div class="feature-pill">
          <div class="fp-icon blue"><i class="fas fa-calendar-check"></i></div>
          <div>
            <div class="fp-label">Appointments</div>
            <div class="fp-desc">Smart scheduling</div>
          </div>
        </div>
        <div class="feature-pill">
          <div class="fp-icon purple"><i class="fas fa-glasses"></i></div>
          <div>
            <div class="fp-label">Prescriptions</div>
            <div class="fp-desc">Digital Rx records</div>
          </div>
        </div>
        <div class="feature-pill">
          <div class="fp-icon green"><i class="fas fa-boxes"></i></div>
          <div>
            <div class="fp-label">Inventory</div>
            <div class="fp-desc">Real-time tracking</div>
          </div>
        </div>
        <div class="feature-pill">
          <div class="fp-icon orange"><i class="fas fa-cash-register"></i></div>
          <div>
            <div class="fp-label">Point of Sale</div>
            <div class="fp-desc">Fast transactions</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ======= RIGHT PANEL ======= -->
    <div class="right-panel">
      <div class="login-card">

        <img src="assets/images/logo.png?v=2" alt="Gueco Optical Logo" class="card-logo" style="background:rgba(255,255,255,0.9); box-shadow:0 8px 24px rgba(37,99,235,0.3); border-radius:50%; padding:4px;">
        <div class="card-title">Welcome back 👋</div>
        <div class="card-subtitle">Enter your credentials to continue</div>



        <!-- Error -->
        <?php if ($error): ?>
        <div class="err-box">
          <i class="fas fa-exclamation-circle"></i>
          <?= sanitize($error) ?>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="login.php" id="loginForm" autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

          <div class="field-wrap">
            <label class="field-label" for="loginEmail">Email Address</label>
            <div class="field-input-wrap">
              <i class="fas fa-envelope field-icon"></i>
              <input
                type="email"
                id="loginEmail"
                name="email"
                class="field-control"
                placeholder="your@email.com"
                value="<?= sanitize($_POST['email'] ?? '') ?>"
                required autocomplete="email">
            </div>
          </div>

          <div class="field-wrap">
            <label class="field-label" for="loginPassword">Password</label>
            <div class="field-input-wrap">
              <i class="fas fa-lock field-icon"></i>
              <input
                type="password"
                id="loginPassword"
                name="password"
                class="field-control"
                placeholder="••••••••"
                required autocomplete="current-password"
                style="padding-right:44px">
              <button type="button" class="pass-eye" onclick="togglePass()">
                <i class="fas fa-eye" id="passEyeIcon"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-login" id="loginBtn">
            <i class="fas fa-sign-in-alt"></i> Sign In
          </button>
        </form>

        <p class="card-footer-txt" style="margin-top:20px;">
          &copy; <?= date('Y') ?> Gueco Optical Clinic &mdash; Capas, Tarlac
        </p>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Theme toggle
    const html = document.documentElement;
    const themeBtn  = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const saved = localStorage.getItem('gueco-theme') || 'dark';
    html.setAttribute('data-theme', saved);
    themeIcon.className = saved === 'dark' ? 'fas fa-moon' : 'fas fa-sun';

    themeBtn.addEventListener('click', () => {
      const cur = html.getAttribute('data-theme');
      const next = cur === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      localStorage.setItem('gueco-theme', next);
      themeIcon.className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    });

    // Password toggle
    function togglePass() {
      const inp  = document.getElementById('loginPassword');
      const icon = document.getElementById('passEyeIcon');
      if (inp.type === 'password') { inp.type = 'text';     icon.className = 'fas fa-eye-slash'; }
      else                         { inp.type = 'password'; icon.className = 'fas fa-eye'; }
    }



    // Loading state on submit
    document.getElementById('loginForm').addEventListener('submit', () => {
      const btn = document.getElementById('loginBtn');
      btn.classList.add('loading');
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
    });
  </script>
</body>
</html>
