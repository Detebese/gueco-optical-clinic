<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Patient Records';
$breadcrumb = ['Doctor', 'Patients'];
$db = getDB();
$msg = ''; $msgType = 'success';

$search = sanitize($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page']??1)); $perPage = 15;

$where = ["p.status='active'"]; $params = [];
if ($search) { $where[] = "(p.full_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr");
$total->execute($params); $total = $total->fetch()['c'];
$pg = paginate($total, $perPage, $page);

$params2 = array_merge($params, [$perPage, $pg['offset']]);
$patients = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=p.id) as rx_count,
           (SELECT MAX(appointment_date) FROM appointments a WHERE a.patient_id=p.id AND a.status='completed') as last_visit
    FROM patients p WHERE $whereStr ORDER BY p.full_name ASC LIMIT ? OFFSET ?
");
$patients->execute($params2); $patients = $patients->fetchAll();

// View single patient
$viewPatient = null; $patientRx = []; $patientAppts = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    $viewPatient = $db->prepare("SELECT * FROM patients WHERE id=?"); $viewPatient->execute([$vid]); $viewPatient = $viewPatient->fetch();
    if ($viewPatient) {
        $patientRx = $db->prepare("SELECT rx.*, u.full_name as doctor_name FROM prescriptions rx JOIN users u ON u.id=rx.doctor_id WHERE rx.patient_id=? ORDER BY rx.created_at DESC"); $patientRx->execute([$vid]); $patientRx = $patientRx->fetchAll();
        $patientAppts = $db->prepare("SELECT * FROM appointments WHERE patient_id=? ORDER BY appointment_date DESC LIMIT 10"); $patientAppts->execute([$vid]); $patientAppts = $patientAppts->fetchAll();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewPatient): ?>
<!-- PATIENT DETAIL VIEW -->
<div style="margin-bottom:16px;">
  <a href="patients.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Patients</a>
</div>

<div class="row" style="margin-bottom:20px;">
  <div class="col-4">
    <div class="card">
      <div class="card-body" style="text-align:center;padding:30px 20px;">
        <div style="width:80px;height:80px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1.8rem;margin:0 auto 16px;">
          <?= strtoupper(substr($viewPatient['full_name'],0,1)) ?>
        </div>
        <h5 style="font-size:1.05rem;font-weight:700;margin-bottom:4px"><?= sanitize($viewPatient['full_name']) ?></h5>
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:20px"><?= sanitize($viewPatient['email']) ?></p>

        <?php $info = [['fas fa-phone','Phone',$viewPatient['phone'],'—'],['fas fa-birthday-cake','Birthday',formatDate($viewPatient['birthdate']),'Not set'],['fas fa-venus-mars','Gender',ucfirst($viewPatient['gender'] ?? ''),'Not set'],['fas fa-map-marker-alt','Address',$viewPatient['address'],'Not set']]; ?>
        <?php foreach ($info as [$icon,$label,$val,$def]): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border-light);text-align:left;">
          <span style="font-size:.75rem;color:var(--text-muted)"><i class="<?= $icon ?> me-1"></i><?= $label ?></span>
          <span style="font-size:.78rem;font-weight:500;color:var(--text-primary)"><?= sanitize($val ?: $def) ?></span>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:10px;margin-top:20px;">
          <div style="flex:1;text-align:center;background:rgba(37,99,235,.08);border-radius:10px;padding:12px;">
            <div style="font-weight:800;font-size:1.3rem;color:var(--clr-primary)"><?= count($patientAppts) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Visits</div>
          </div>
          <div style="flex:1;text-align:center;background:rgba(124,58,237,.08);border-radius:10px;padding:12px;">
            <div style="font-weight:800;font-size:1.3rem;color:var(--clr-secondary)"><?= count($patientRx) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Prescriptions</div>
          </div>
        </div>

        <a href="prescriptions.php?patient_id=<?= $viewPatient['id'] ?>" class="btn btn-primary w-100 mt-3">
          <i class="fas fa-plus"></i> Write Prescription
        </a>
      </div>
    </div>
  </div>
  <div class="col-8">
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header">
        <h6><i class="fas fa-glasses me-2" style="color:var(--clr-secondary)"></i>Prescriptions (<?= count($patientRx) ?>)</h6>
        <a href="prescriptions.php?patient_id=<?= $viewPatient['id'] ?>" class="btn btn-sm btn-primary">New Rx</a>
      </div>
      <?php if (empty($patientRx)): ?>
      <div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No prescriptions yet</h6></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>OD (Right)</th><th>OS (Left)</th><th>PD</th><th>Notes</th></tr></thead>
          <tbody>
          <?php foreach ($patientRx as $rx): ?>
          <tr>
            <td style="font-size:.8rem;font-weight:600"><?= formatDate($rx['created_at']) ?></td>
            <td style="font-size:.75rem">SPH: <?= $rx['od_sphere']??'—' ?> / CYL: <?= $rx['od_cylinder']??'—' ?> / AXIS: <?= $rx['od_axis']??'—' ?></td>
            <td style="font-size:.75rem">SPH: <?= $rx['os_sphere']??'—' ?> / CYL: <?= $rx['os_cylinder']??'—' ?> / AXIS: <?= $rx['os_axis']??'—' ?></td>
            <td style="font-size:.8rem"><?= $rx['pd']??'—' ?></td>
            <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($rx['notes']??'—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-calendar me-2" style="color:var(--clr-info)"></i>Visit History</h6></div>
      <?php if (empty($patientAppts)): ?>
      <div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h6>No visits recorded</h6></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($patientAppts as $appt): ?>
          <tr>
            <td style="font-size:.83rem;font-weight:600"><?= formatDate($appt['appointment_date']) ?></td>
            <td style="font-size:.8rem"><?= formatTime($appt['appointment_time']) ?></td>
            <td style="font-size:.8rem"><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
            <td><?= statusBadge($appt['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>
<!-- PATIENT LIST -->
<div class="section-header">
  <h5><i class="fas fa-user-injured me-2" style="color:var(--clr-primary)"></i>Patient Records (<?= $total ?>)</h5>
  <form method="GET" style="display:flex;gap:8px;">
    <input type="text" name="search" class="form-control" placeholder="Name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:240px;">
    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
    <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
  </form>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Gender</th><th>Visits</th><th>Last Visit</th><th>Prescriptions</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $pg['offset']+$i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;"><?= strtoupper(substr($p['full_name'],0,1)) ?></div>
              <div>
                <div style="font-weight:600;font-size:.88rem"><?= sanitize($p['full_name']) ?></div>
                <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['email']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($p['phone'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= $p['gender'] ? ucfirst($p['gender']) : '—' ?></td>
          <td><span class="badge bg-info"><?= $p['appt_count'] ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $p['last_visit'] ? formatDate($p['last_visit']) : 'Never' ?></td>
          <td><span class="badge bg-secondary"><?= $p['rx_count'] ?></span></td>
          <td>
            <a href="patients.php?view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon" title="View Record"><i class="fas fa-eye"></i></a>
            <a href="prescriptions.php?patient_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary btn-icon" title="Write Prescription"><i class="fas fa-glasses"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
  <div style="padding:14px 20px;border-top:1px solid var(--border-light);">
    <div class="pagination">
      <?php if ($pg['has_prev']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
