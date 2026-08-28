<?php
// Shared header config — included at the top of every staff page
if (!defined('BASE_URL')) {
    define('BASE_URL', str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/') - 2));
}
require_once __DIR__ . '/../config/functions.php';
startSession();

// Each page sets $pageTitle, $breadcrumb[], $activeNav before including this
$pageTitle   = $pageTitle   ?? 'Dashboard';
$breadcrumb  = $breadcrumb  ?? [];
$activeNav   = $activeNav   ?? '';
$user        = getCurrentUser();

$roleBgMap = [
  'admin'     => '#2563EB',
  'doctor'    => '#0891B2',
  'saleslady' => '#7C3AED',
];
$roleColor = $roleBgMap[$user['role'] ?? ''] ?? '#64748B';
$initials  = strtoupper(substr($user['full_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize($pageTitle) ?> — Gueco Optical</title>
  <meta name="description" content="Gueco Optical Clinic Management System">

  <!-- Immediate Theme Initialization (Prevents Theme Flash / FOUC) -->
  <script>
    (function() {
      try {
        var theme = localStorage.getItem('gueco_theme') || localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', theme);
      } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">

  <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>

<?php if (isset($_SESSION['flash_msg'])): ?>
<div id="flashMsg"
     data-msg="<?= htmlspecialchars($_SESSION['flash_msg']) ?>"
     data-type="<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?>"
     style="display:none"></div>
<?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); endif; ?>

<div class="app-wrapper">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <!-- Logo -->
    <div class="sidebar-logo">
      <div class="logo-icon" style="background:rgba(255,255,255,0.9); box-shadow:0 4px 12px rgba(37,99,235,0.2); border-radius:50%; padding:2px;"><img src="<?= BASE_URL ?>assets/images/logo.png?v=2" alt="Logo" style="width:100%; height:100%; object-fit:contain; border-radius:50%;"></div>
      <div class="logo-text">
        <h6>Gueco Optical</h6>
        <span>Clinic Management</span>
      </div>
    </div>

    <!-- User Info -->
    <div class="sidebar-user" style="margin-top:12px;">
      <div class="user-avatar" style="background:linear-gradient(135deg,<?= $roleColor ?>,#7C3AED)">
        <span style="color:#fff;font-weight:700;font-size:.9rem"><?= $initials ?></span>
      </div>
      <div class="user-info">
        <div class="user-name"><?= sanitize($user['full_name'] ?? 'User') ?></div>
        <div class="user-role"><?= getRoleLabel($user['role'] ?? '') ?></div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
      <?php include __DIR__ . '/sidebar.php'; ?>
    </nav>

    <!-- Logout -->
    <div class="sidebar-bottom">
      <a href="<?= BASE_URL ?>logout.php" class="nav-link">
        <div class="nav-icon" style="color:var(--clr-danger)"><i class="fas fa-sign-out-alt"></i></div>
        <span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <!-- HEADER -->
    <header class="app-header">
      <div class="header-left">
        <!-- Mobile menu toggle -->
        <button id="sidebarToggle" class="theme-toggle d-lg-none" style="display:none!important">
          <i class="fas fa-bars"></i>
        </button>
        <div class="page-title">
          <h5><?= sanitize($pageTitle) ?></h5>
          <?php if ($breadcrumb): ?>
          <nav class="breadcrumb">
            <span class="breadcrumb-item">Home</span>
            <?php foreach ($breadcrumb as $i => $b): ?>
              <span class="breadcrumb-item <?= ($i === count($breadcrumb)-1) ? 'active' : '' ?>">
                <?= sanitize($b) ?>
              </span>
            <?php endforeach; ?>
          </nav>
          <?php endif; ?>
        </div>
      </div>
      <div class="header-right">
        <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
          <i class="fas fa-moon" id="themeIcon"></i>
        </button>
        <div class="dropdown" id="notifDropdownWrap">
          <button class="notif-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
            <i class="fas fa-bell"></i>
            <?php
            // Count pending appointments and fetch latest 5
            try {
              $db = getDB();
              $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending' AND appointment_date >= CURDATE()");
              $stmtCount->execute();
              $notifCount = $stmtCount->fetch()['c'];
              
              $stmtRecent = $db->prepare("SELECT p.full_name as patient_name, a.appointment_date, a.appointment_time FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.status = 'pending' AND a.appointment_date >= CURDATE() ORDER BY a.created_at DESC LIMIT 5");
              $stmtRecent->execute();
              $recentAppts = $stmtRecent->fetchAll();
            } catch(Exception $e) { 
              $notifCount = 0; 
              $recentAppts = []; 
            }
            ?>
            <?php if ($notifCount > 0): ?>
            <span class="notif-badge" id="notifBadgeEl"><?= min($notifCount, 99) ?></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width: 300px;">
            <li><h6 class="dropdown-header">Notifications</h6></li>
            <?php if (empty($recentAppts)): ?>
              <li><span class="dropdown-item text-muted">No new notifications</span></li>
            <?php else: ?>
              <?php foreach($recentAppts as $appt): ?>
                <li>
                  <a class="dropdown-item py-2" href="appointments.php">
                    <div class="fw-bold text-truncate" style="max-width: 260px;">
                      <?= sanitize($appt['patient_name']) ?>
                    </div>
                    <small class="text-muted">
                      Requested for <?= date('M d, Y', strtotime($appt['appointment_date'])) ?> 
                      at <?= date('h:i A', strtotime($appt['appointment_time'])) ?>
                    </small>
                  </a>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-center text-primary fw-semibold" href="appointments.php">View All Appointments</a></li>
          </ul>
        </div>
        <div class="header-avatar" title="<?= sanitize($user['full_name'] ?? '') ?>">
          <?= $initials ?>
        </div>
      </div>
    </header>

    <!-- PAGE CONTENT starts here (footer.php closes it) -->
    <div class="page-content">
