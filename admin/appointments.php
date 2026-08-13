<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Appointments';
$breadcrumb = ['Admin', 'Appointments'];
$db = getDB();
$today = date('Y-m-d');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'];

    if ($action === 'confirm') {
        $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
        $_SESSION['flash_msg'] = 'Appointment confirmed.'; $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'complete') {
        $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Appointment marked as completed.'; $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Appointment cancelled.'; $_SESSION['flash_type'] = 'success';
    } elseif ($action === 'no_show') {
        $db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Marked as no-show.'; $_SESSION['flash_type'] = 'success';
    }
    header('Location: appointments.php'); exit;
}

// Filters
$filterDate   = $_GET['date']    ?? $today;
$filterStatus = $_GET['status']  ?? '';
$search       = $_GET['search']  ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

$where = ['1=1'];
$params = [];

if ($filterDate) { $where[] = 'a.appointment_date = ?'; $params[] = $filterDate; }
if ($filterStatus) { $where[] = 'a.status = ?'; $params[] = $filterStatus; }
if ($search) { $where[] = 'p.full_name LIKE ?'; $params[] = "%$search%"; }

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE $whereStr");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];
$pagination = paginate($total, $perPage, $page);

$params[] = $perPage; $params[] = $pagination['offset'];
$appts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone, p.email as patient_email
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE $whereStr
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT ? OFFSET ?
");
$appts->execute($params);
$appts = $appts->fetchAll();

// Stats for today
$todayStats = [];
foreach (['pending','confirmed','completed','cancelled','no_show'] as $s) {
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=? AND status=?");
    $stmt->execute([$today, $s]); $todayStats[$s] = $stmt->fetch()['c'];
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Today quick stats -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
  <?php
  $statCfg = ['pending'=>['warning','clock','Pending'],'confirmed'=>['info','check-circle','Confirmed'],'completed'=>['success','check-double','Completed'],'cancelled'=>['danger','times-circle','Cancelled'],'no_show'=>['secondary','user-times','No Show']];
  foreach ($statCfg as $s => $cfg): ?>
  <a href="?date=<?= $today ?>&status=<?= $s ?>" style="text-decoration:none;flex:1;min-width:100px;">
    <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px;text-align:center;transition:all .2s ease;" onmouseover="this.style.boxShadow='var(--shadow-md)'" onmouseout="this.style.boxShadow='none'">
      <div style="font-size:1.4rem;font-weight:800;color:var(--text-primary)"><?= $todayStats[$s] ?></div>
      <div style="font-size:.7rem;color:var(--text-muted);font-weight:500"><?= $cfg[2] ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
      <div style="flex:1;min-width:160px;">
        <label class="form-label" style="margin-bottom:6px;">Date</label>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>">
      </div>
      <div style="flex:1;min-width:160px;">
        <label class="form-label" style="margin-bottom:6px;">Status</label>
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['pending','confirmed','completed','cancelled','no_show'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:2;min-width:200px;">
        <label class="form-label" style="margin-bottom:6px;">Search Patient</label>
        <input type="text" name="search" class="form-control" placeholder="Patient name..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
        <a href="appointments.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
        <a href="appointments.php?date=<?= $today ?>" class="btn btn-outline-primary"><i class="fas fa-calendar-day"></i> Today</a>
      </div>
    </form>
  </div>
</div>

<!-- Appointments Table -->
<div class="table-wrapper">
  <div style="padding:16px 20px;border-bottom:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between;">
    <span style="font-size:.85rem;font-weight:600;color:var(--text-primary)">
      <i class="fas fa-calendar-check me-2" style="color:var(--clr-primary)"></i>
      <?= $total ?> Appointment<?= $total !== 1 ? 's' : '' ?> Found
    </span>
    <span style="font-size:.78rem;color:var(--text-muted)">
      <?= $filterDate ? 'Date: ' . formatDate($filterDate) : 'All dates' ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Patient</th>
          <th>Contact</th>
          <th>Date</th>
          <th>Time</th>
          <th>Purpose</th>
          <th>Notes</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($appts)): ?>
        <tr><td colspan="9">
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-calendar"></i></div>
            <h6>No appointments found</h6>
            <p>Try adjusting the filters above</p>
          </div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($appts as $i => $a): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $pagination['offset'] + $i + 1 ?></td>
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= sanitize($a['patient_name']) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($a['patient_email']) ?></div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($a['phone'] ?? '—') ?></td>
          <td style="font-size:.83rem;font-weight:500"><?= formatDate($a['appointment_date']) ?></td>
          <td style="font-size:.83rem;font-weight:600;color:var(--clr-primary)"><?= formatTime($a['appointment_time']) ?></td>
          <td style="font-size:.78rem"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></td>
          <td style="font-size:.75rem;color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= sanitize($a['notes'] ?? '—') ?></td>
          <td><?= statusBadge($a['status']) ?></td>
          <td>
            <div style="display:flex;gap:4px;">
              <?php if ($a['status'] === 'pending'): ?>
              <form method="POST" style="margin:0">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="confirm">
                <button class="btn btn-sm btn-success btn-icon" title="Confirm"><i class="fas fa-check"></i></button>
              </form>
              <?php endif; ?>
              <?php if (in_array($a['status'],['pending','confirmed'])): ?>
              <form method="POST" style="margin:0">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="complete">
                <button class="btn btn-sm btn-primary btn-icon" title="Mark Complete"><i class="fas fa-check-double"></i></button>
              </form>
              <form method="POST" style="margin:0">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="no_show">
                <button class="btn btn-sm btn-secondary btn-icon" title="No Show"><i class="fas fa-user-times"></i></button>
              </form>
              <form method="POST" style="margin:0">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-sm btn-danger btn-icon" title="Cancel" data-confirm="Cancel this appointment?"><?php echo '<i class="fas fa-times"></i>'; ?></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pagination['total_pages'] > 1): ?>
  <div style="padding:16px 20px;border-top:1px solid var(--border-light);">
    <div class="pagination">
      <?php if ($pagination['has_prev']): ?>
      <a href="?date=<?= $filterDate ?>&status=<?= $filterStatus ?>&search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
      <a href="?date=<?= $filterDate ?>&status=<?= $filterStatus ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($pagination['has_next']): ?>
      <a href="?date=<?= $filterDate ?>&status=<?= $filterStatus ?>&search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
