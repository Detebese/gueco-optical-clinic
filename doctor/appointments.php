<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Appointments';
$breadcrumb = ['Doctor', 'Appointments'];
$db = getDB();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['appt_id'];
    $action = $_POST['action'] ?? '';
    if ($action === 'complete')  $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$id]);
    if ($action === 'no_show')   $db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$id]);
    header('Location: appointments.php?date='.(isset($_GET['date'])?$_GET['date']:$today)); exit;
}

$filterDate = $_GET['date'] ?? $today;
$appts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone, p.gender,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=a.patient_id) as rx_count
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.appointment_date=?
    ORDER BY FIELD(a.status,'confirmed','pending','completed','no_show','cancelled'), a.appointment_time ASC
");
$appts->execute([$filterDate]); $appts = $appts->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i class="fas fa-calendar-day me-2" style="color:var(--clr-primary)"></i>My Appointment Queue — <?= formatDate($filterDate) ?></h5>
  <div style="display:flex;gap:8px;align-items:center;">
    <a href="?date=<?= date('Y-m-d',strtotime($filterDate.'-1 day')) ?>" class="btn btn-outline-primary btn-icon"><i class="fas fa-chevron-left"></i></a>
    <input type="date" class="form-control" value="<?= $filterDate ?>" style="width:170px;" onchange="location='?date='+this.value">
    <a href="?date=<?= date('Y-m-d',strtotime($filterDate.'+1 day')) ?>" class="btn btn-outline-primary btn-icon"><i class="fas fa-chevron-right"></i></a>
    <a href="?date=<?= $today ?>" class="btn btn-primary">Today</a>
  </div>
</div>

<?php if (empty($appts)): ?>
<div class="empty-state" style="padding:60px"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h6>No appointments on this date</h6><p>Check another date using the navigation above</p></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
  <?php foreach ($appts as $i => $a): ?>
  <?php $isDone = in_array($a['status'],['completed','no_show','cancelled']); ?>
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:20px;transition:all .2s;<?= $isDone?'opacity:.65':'' ?>"
       onmouseover="this.style.boxShadow='var(--shadow-md)'" onmouseout="this.style.boxShadow='none'">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:40px;height:40px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.95rem;flex-shrink:0;">
          <?= $i+1 ?>
        </div>
        <div>
          <div style="font-weight:700;font-size:.92rem"><?= sanitize($a['patient_name']) ?></div>
          <div style="font-size:.72rem;color:var(--text-muted)"><?= sanitize($a['phone']??'') ?> &middot; <?= $a['gender']?ucfirst($a['gender']):'—' ?></div>
        </div>
      </div>
      <?= statusBadge($a['status']) ?>
    </div>

    <div style="display:flex;gap:12px;margin-bottom:14px;">
      <div style="background:var(--bg-hover);border-radius:8px;padding:8px 12px;flex:1;">
        <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Time</div>
        <div style="font-weight:700;font-size:.88rem;color:var(--clr-primary)"><?= formatTime($a['appointment_time']) ?></div>
      </div>
      <div style="background:var(--bg-hover);border-radius:8px;padding:8px 12px;flex:1;">
        <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Purpose</div>
        <div style="font-weight:600;font-size:.8rem"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></div>
      </div>
      <div style="background:var(--bg-hover);border-radius:8px;padding:8px 12px;text-align:center;">
        <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Rx</div>
        <div style="font-weight:700;font-size:.88rem;color:var(--clr-secondary)"><?= $a['rx_count'] ?></div>
      </div>
    </div>

    <?php if ($a['notes']): ?>
    <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:12px;font-style:italic;padding:8px;background:var(--bg-hover);border-radius:8px;">
      <i class="fas fa-sticky-note me-1"></i><?= sanitize($a['notes']) ?>
    </div>
    <?php endif; ?>

    <?php if (!$isDone): ?>
    <div style="display:flex;gap:8px;">
      <a href="patients.php?view=<?= $a['patient_id'] ?>" class="btn btn-outline-primary btn-sm" style="flex:1;text-align:center;"><i class="fas fa-user me-1"></i>Record</a>
      <a href="prescriptions.php?patient_id=<?= $a['patient_id'] ?>" class="btn btn-secondary btn-sm" style="flex:1;text-align:center;"><i class="fas fa-glasses me-1"></i>Write Rx</a>
      <form method="POST" style="flex:1">
        <input type="hidden" name="appt_id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="complete">
        <button class="btn btn-success btn-sm w-100"><i class="fas fa-check me-1"></i>Done</button>
      </form>
      <form method="POST" style="">
        <input type="hidden" name="appt_id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="no_show">
        <button class="btn btn-secondary btn-sm btn-icon" title="No Show"><i class="fas fa-user-times"></i></button>
      </form>
    </div>
    <?php else: ?>
    <a href="patients.php?view=<?= $a['patient_id'] ?>" class="btn btn-outline-primary btn-sm w-100"><i class="fas fa-eye me-1"></i>View Patient</a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
