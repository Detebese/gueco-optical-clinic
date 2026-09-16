<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Staff Activity Logs';
$breadcrumb = ['Admin', 'Activity Logs'];
$activeNav  = 'activity_logs.php';
$db = getDB();

// Filters
$search       = sanitize(trim($_GET['search'] ?? ''));
$roleFilter   = sanitize($_GET['role'] ?? '');
$userFilter   = (int)($_GET['user_id'] ?? 0);
$moduleFilter = sanitize($_GET['module'] ?? '');
$dateFilter   = sanitize($_GET['date_range'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

// Build WHERE Clause
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(l.action LIKE ? OR l.module LIKE ? OR l.ip_address LIKE ? OR u.full_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($roleFilter !== '') {
    if ($roleFilter === 'patient') {
        $where[] = "l.user_type = 'patient'";
    } else {
        $where[] = "u.role = ?";
        $params[] = $roleFilter;
    }
}

if ($userFilter > 0) {
    $where[] = "l.user_id = ? AND l.user_type = 'staff'";
    $params[] = $userFilter;
}

if ($moduleFilter !== '') {
    $where[] = "l.module = ?";
    $params[] = $moduleFilter;
}

if ($dateFilter === 'today') {
    $where[] = "DATE(l.created_at) = CURDATE()";
} elseif ($dateFilter === 'yesterday') {
    $where[] = "DATE(l.created_at) = SUBDATE(CURDATE(), 1)";
} elseif ($dateFilter === '7days') {
    $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($dateFilter === 'month') {
    $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

$whereStr = implode(' AND ', $where);

// Total Count
$countSql = "
    SELECT COUNT(*) as c
    FROM activity_logs l
    LEFT JOIN users u ON u.id = l.user_id AND l.user_type = 'staff'
    LEFT JOIN patients p ON p.id = l.user_id AND l.user_type = 'patient'
    WHERE $whereStr
";
$cntStmt = $db->prepare($countSql);
$cntStmt->execute($params);
$totalLogs = (int)($cntStmt->fetch()['c'] ?? 0);

$pg = paginate($totalLogs, $perPage, $page);

// Fetch Logs
$fetchSql = "
    SELECT l.*, 
           u.full_name as staff_name, 
           u.role as staff_role, 
           u.email as staff_email,
           p.full_name as patient_name
    FROM activity_logs l
    LEFT JOIN users u ON u.id = l.user_id AND l.user_type = 'staff'
    LEFT JOIN patients p ON p.id = l.user_id AND l.user_type = 'patient'
    WHERE $whereStr
    ORDER BY l.created_at DESC
    LIMIT ? OFFSET ?
";
$fetchParams = array_merge($params, [$perPage, $pg['offset']]);
$logsStmt = $db->prepare($fetchSql);
$logsStmt->execute($fetchParams);
$logs = $logsStmt->fetchAll();

// Summary Stats
$statsToday = $db->query("SELECT COUNT(*) as c FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetch()['c'] ?? 0;
$statsDoctor = $db->query("SELECT COUNT(*) as c FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE u.role = 'doctor'")->fetch()['c'] ?? 0;
$statsSaleslady = $db->query("SELECT COUNT(*) as c FROM activity_logs l JOIN users u ON u.id = l.user_id WHERE u.role = 'saleslady'")->fetch()['c'] ?? 0;
$statsTotalAll = $db->query("SELECT COUNT(*) as c FROM activity_logs")->fetch()['c'] ?? 0;

// Staff list for dropdown
$allStaff = $db->query("SELECT id, full_name, role FROM users ORDER BY role ASC, full_name ASC")->fetchAll();

// Modules list
$distinctModules = $db->query("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC")->fetchAll(PDO::FETCH_COLUMN);

// Helper for module badge class & icon
function getModuleBadge(string $module): array {
    $m = strtolower($module);
    if (strpos($m, 'pos') !== false || strpos($m, 'sale') !== false) {
        return ['class' => 'mod-sales', 'icon' => 'fa-cash-register'];
    } elseif (strpos($m, 'prescription') !== false) {
        return ['class' => 'mod-prescriptions', 'icon' => 'fa-glasses'];
    } elseif (strpos($m, 'appointment') !== false) {
        return ['class' => 'mod-appointments', 'icon' => 'fa-calendar-check'];
    } elseif (strpos($m, 'inventory') !== false || strpos($m, 'stock') !== false) {
        return ['class' => 'mod-inventory', 'icon' => 'fa-boxes'];
    } elseif (strpos($m, 'staff') !== false) {
        return ['class' => 'mod-staff', 'icon' => 'fa-user-tie'];
    } elseif (strpos($m, 'cat') !== false) {
        return ['class' => 'mod-categories', 'icon' => 'fa-th-large'];
    } elseif (strpos($m, 'sup') !== false) {
        return ['class' => 'mod-suppliers', 'icon' => 'fa-truck'];
    } elseif (strpos($m, 'auth') !== false || strpos($m, 'login') !== false || strpos($m, 'logout') !== false) {
        return ['class' => 'mod-auth', 'icon' => 'fa-key'];
    }
    return ['class' => 'mod-default', 'icon' => 'fa-shield-halved'];
}

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/pages/activity_logs.css?v=' . time() . '">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Header -->
<div class="log-header-wrap">
  <div class="log-header-left">
    <div class="log-header-icon-box">
      <i class="fas fa-history"></i>
    </div>
    <div class="log-header-titles">
      <h4>Staff Activity Audit Logs</h4>
      <p>Real-time audit trail and operational tracking across Optometrists, Salesladies, and Clinic Admin</p>
    </div>
  </div>
  <div>
    <button type="button" class="btn btn-secondary" onclick="window.location.href='activity_logs.php'" title="Refresh Logs">
      <i class="fas fa-sync-alt"></i> Refresh
    </button>
  </div>
</div>

<!-- Bento Stat Grid -->
<div class="log-stats-grid">
  <div class="log-stat-card">
    <div class="log-stat-info">
      <div class="log-stat-label">Total Logs</div>
      <div class="log-stat-val"><?= number_format($statsTotalAll) ?></div>
    </div>
    <div class="log-stat-icon-wrap icon-blue">
      <i class="fas fa-list-check"></i>
    </div>
  </div>

  <div class="log-stat-card">
    <div class="log-stat-info">
      <div class="log-stat-label">Today's Activity</div>
      <div class="log-stat-val"><?= number_format($statsToday) ?></div>
    </div>
    <div class="log-stat-icon-wrap icon-emerald">
      <i class="fas fa-calendar-day"></i>
    </div>
  </div>

  <div class="log-stat-card">
    <div class="log-stat-info">
      <div class="log-stat-label">Doctor Actions</div>
      <div class="log-stat-val"><?= number_format($statsDoctor) ?></div>
    </div>
    <div class="log-stat-icon-wrap icon-purple">
      <i class="fas fa-user-md"></i>
    </div>
  </div>

  <div class="log-stat-card">
    <div class="log-stat-info">
      <div class="log-stat-label">Saleslady Actions</div>
      <div class="log-stat-val"><?= number_format($statsSaleslady) ?></div>
    </div>
    <div class="log-stat-icon-wrap icon-amber">
      <i class="fas fa-cash-register"></i>
    </div>
  </div>
</div>

<!-- Filters Toolbar -->
<div class="log-toolbar-card">
  <form method="GET" action="activity_logs.php">
    <div class="log-toolbar-grid">
      <!-- Search Input -->
      <div class="log-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" name="search" class="form-control" placeholder="Search action, user, or IP..." value="<?= htmlspecialchars($search) ?>">
      </div>

      <!-- Role Filter -->
      <div>
        <select name="role" class="form-select" onchange="this.form.submit()">
          <option value="">All Roles</option>
          <option value="doctor" <?= $roleFilter === 'doctor' ? 'selected' : '' ?>>Optometrists / Doctors</option>
          <option value="saleslady" <?= $roleFilter === 'saleslady' ? 'selected' : '' ?>>Salesladies</option>
          <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrators</option>
          <option value="patient" <?= $roleFilter === 'patient' ? 'selected' : '' ?>>Patients</option>
        </select>
      </div>

      <!-- Specific Staff Member -->
      <div>
        <select name="user_id" class="form-select" onchange="this.form.submit()">
          <option value="">All Staff Members</option>
          <?php foreach ($allStaff as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $userFilter === (int)$st['id'] ? 'selected' : '' ?>>
            <?= sanitize($st['full_name']) ?> (<?= ucfirst($st['role']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Module Filter -->
      <div>
        <select name="module" class="form-select" onchange="this.form.submit()">
          <option value="">All Modules</option>
          <?php foreach ($distinctModules as $mod): ?>
          <option value="<?= htmlspecialchars($mod) ?>" <?= $moduleFilter === $mod ? 'selected' : '' ?>><?= sanitize($mod) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Date Range -->
      <div>
        <select name="date_range" class="form-select" onchange="this.form.submit()">
          <option value="">All Time</option>
          <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="yesterday" <?= $dateFilter === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
          <option value="7days" <?= $dateFilter === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
          <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>Last 30 Days</option>
        </select>
      </div>
    </div>

    <?php if ($search !== '' || $roleFilter !== '' || $userFilter > 0 || $moduleFilter !== '' || $dateFilter !== ''): ?>
    <div style="margin-top: 12px; display: flex; align-items: center; justify-content: space-between;">
      <span style="font-size: 0.78rem; color: var(--text-muted);">
        Filtered results: <strong><?= number_format($totalLogs) ?></strong> records found
      </span>
      <a href="activity_logs.php" class="btn btn-sm btn-secondary" style="font-size:0.75rem;">
        <i class="fas fa-times me-1"></i> Clear Filters
      </a>
    </div>
    <?php endif; ?>
  </form>
</div>

<!-- Activity Logs Table -->
<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th style="width: 60px;">#</th>
          <th style="width: 200px;">Timestamp</th>
          <th style="width: 220px;">Staff / User</th>
          <th style="width: 160px;">Module</th>
          <th>Action Summary</th>
          <th style="width: 140px; text-align: right;">IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr>
          <td colspan="6">
            <div class="empty-state" style="text-align:center; padding:48px 20px;">
              <div class="empty-icon" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:12px;">
                <i class="fas fa-history"></i>
              </div>
              <h6 style="font-weight:700; color:var(--text-primary); margin-bottom:4px;">No activity logs recorded</h6>
              <p style="font-size:0.84rem; color:var(--text-muted);">Staff actions will automatically appear here as clinic operations occur.</p>
            </div>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($logs as $i => $log): ?>
        <?php
          $isPatient = ($log['user_type'] === 'patient');
          $displayName = $isPatient ? ($log['patient_name'] ?? 'Patient #'.$log['user_id']) : ($log['staff_name'] ?? 'System / Former Staff');
          $roleName = $isPatient ? 'Patient' : ($log['staff_role'] ?? 'Staff');
          $avatarClass = 'avatar-' . ($isPatient ? 'patient' : ($log['staff_role'] ?? 'admin'));
          $modBadge = getModuleBadge($log['module'] ?? '');
        ?>
        <tr>
          <td style="color:var(--text-muted); font-size:0.78rem; font-weight:600;">
            <?= $pg['offset'] + $i + 1 ?>
          </td>
          <td>
            <div style="font-weight: 600; font-size: 0.84rem; color: var(--text-primary);">
              <?= date('M d, Y h:i A', strtotime($log['created_at'])) ?>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-muted);">
              <?= timeAgo($log['created_at']) ?>
            </div>
          </td>
          <td>
            <div class="log-user-chip">
              <div class="log-user-avatar <?= $avatarClass ?>">
                <?= strtoupper(substr($displayName, 0, 1)) ?>
              </div>
              <div>
                <div class="log-user-name"><?= sanitize($displayName) ?></div>
                <div class="log-user-role">
                  <?php if ($isPatient): ?>
                    <span class="badge bg-secondary" style="font-size:0.64rem; padding:2px 6px;">Patient</span>
                  <?php else: ?>
                    <?= roleBadge($log['staff_role'] ?? 'admin') ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td>
            <span class="module-badge <?= $modBadge['class'] ?>">
              <i class="fas <?= $modBadge['icon'] ?>"></i>
              <?= sanitize($log['module'] ?: 'General') ?>
            </span>
          </td>
          <td>
            <div class="log-action-text">
              <?= sanitize($log['action']) ?>
            </div>
          </td>
          <td style="text-align: right;">
            <span class="log-ip-badge">
              <i class="fas fa-network-wired"></i> <?= sanitize($log['ip_address'] ?: '—') ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pg['total_pages'] > 1): ?>
  <div class="log-pagination-wrap">
    <div style="font-size: 0.8rem; color: var(--text-muted);">
      Showing page <strong><?= $page ?></strong> of <strong><?= $pg['total_pages'] ?></strong> (<?= number_format($totalLogs) ?> total entries)
    </div>
    <div style="display: flex; gap: 6px;">
      <?php
        $queryParams = $_GET;
        unset($queryParams['page']);
        $queryString = http_build_query($queryParams);
        $urlPrefix = 'activity_logs.php?' . ($queryString ? $queryString . '&' : '') . 'page=';
      ?>
      <?php if ($page > 1): ?>
        <a href="<?= $urlPrefix . ($page - 1) ?>" class="btn btn-sm btn-secondary">
          <i class="fas fa-chevron-left"></i> Previous
        </a>
      <?php endif; ?>

      <?php for ($p = max(1, $page - 2); $p <= min($pg['total_pages'], $page + 2); $p++): ?>
        <a href="<?= $urlPrefix . $p ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>" style="min-width: 32px;">
          <?= $p ?>
        </a>
      <?php endfor; ?>

      <?php if ($page < $pg['total_pages']): ?>
        <a href="<?= $urlPrefix . ($page + 1) ?>" class="btn btn-sm btn-secondary">
          Next <i class="fas fa-chevron-right"></i>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>