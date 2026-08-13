<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Appointments';
$breadcrumb = ['Saleslady', 'Appointments'];
$db = getDB();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)$_POST['appt_id'];
    $action = $_POST['action'] ?? '';
    if ($action === 'confirm') $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'],$id]);
    if ($action === 'no_show') $db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$id]);
    if ($action === 'cancel')  $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$id]);
    header('Location: appointments.php?date='.(isset($_GET['date'])?$_GET['date']:$today));
    exit;
}

$filterDate = $_GET['date'] ?? $today;
$appts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone, p.email as patient_email
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.appointment_date=?
    ORDER BY a.appointment_time ASC
");
$appts->execute([$filterDate]); $appts = $appts->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i class="fas fa-calendar-check me-2" style="color:var(--clr-primary)"></i>Appointment Queue — <?= formatDate($filterDate) ?></h5>
  <div style="display:flex;gap:8px;align-items:center;">
    <input type="date" id="dateNav" class="form-control" value="<?= $filterDate ?>" style="width:180px;" onchange="location='?date='+this.value">
    <a href="?date=<?= $today ?>" class="btn btn-outline-primary">Today</a>
    <a href="?date=<?= date('Y-m-d',strtotime($filterDate.'+1 day')) ?>" class="btn btn-outline-primary"><i class="fas fa-chevron-right"></i></a>
  </div>
</div>

<!-- Status Summary -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
  <?php
  $statMap = ['pending'=>['warning','Pending'],'confirmed'=>['info','Confirmed'],'completed'=>['success','Done'],'cancelled'=>['danger','Cancelled'],'no_show'=>['secondary','No-Show']];
  foreach ($statMap as $s => [$color,$label]): $cnt = count(array_filter($appts, fn($a)=>$a['status']===$s)); ?>
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;padding:12px 16px;text-align:center;min-width:90px;">
    <div style="font-weight:800;font-size:1.2rem;color:var(--clr-<?= $color ?>)"><?= $cnt ?></div>
    <div style="font-size:.7rem;color:var(--text-muted)"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Time</th><th>Purpose</th><th>Notes</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($appts)): ?>
        <tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h6>No appointments on this date</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($appts as $i => $a): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted);font-weight:700"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:600;font-size:.88rem"><?= sanitize($a['patient_name']) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($a['phone']??'') ?></div>
          </td>
          <td style="font-weight:700;color:var(--clr-primary);font-size:.88rem"><?= formatTime($a['appointment_time']) ?></td>
          <td style="font-size:.82rem"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></td>
          <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($a['notes']??'—') ?></td>
          <td><?= statusBadge($a['status']) ?></td>
          <td>
            <?php if ($a['status']==='pending'): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="appt_id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="confirm">
              <button class="btn btn-sm btn-success btn-icon" title="Confirm"><i class="fas fa-check"></i></button></form>
            <?php endif; ?>
            <?php if (in_array($a['status'],['pending','confirmed'])): ?>
            <form method="POST" style="display:inline"><input type="hidden" name="appt_id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="no_show">
              <button class="btn btn-sm btn-secondary btn-icon" title="No Show"><i class="fas fa-user-times"></i></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="appt_id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="cancel">
              <button class="btn btn-sm btn-danger btn-icon" data-confirm="Cancel appointment?" title="Cancel"><i class="fas fa-times"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
