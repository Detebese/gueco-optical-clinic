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

// Handle Walk-in Patient Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_walkin') {
    $fullName = sanitize(trim($_POST['full_name'] ?? ''));
    $fullName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fullName);
    $fullName = ucwords(strtolower($fullName));
    $email    = trim($_POST['email'] ?? '');
    $phone    = sanitize(trim($_POST['phone'] ?? ''));
    $address  = sanitize(trim($_POST['address'] ?? ''));
    $gender   = $_POST['gender'] ?? '';
    $birthdate = $_POST['birthdate'] ?? '';

    // If no email provided, generate a dummy one for the unique constraint
    if (empty($email)) {
        $email = 'walkin_' . time() . '_' . rand(100, 999) . '@gueco.local';
    }

    try {
        $check = $db->prepare("SELECT id FROM patients WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $msg = 'Email is already registered.';
            $msgType = 'danger';
        } else {
            // Generate a random password since they are a walk-in
            $randomPass = bin2hex(random_bytes(4));
            $stmt = $db->prepare("INSERT INTO patients (full_name, email, password, phone, address, gender, birthdate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $fullName,
                $email,
                password_hash($randomPass, PASSWORD_DEFAULT),
                $phone,
                $address,
                $gender ?: null,
                $birthdate ?: null
            ]);
            $msg = 'Walk-in patient registered successfully.';
            $msgType = 'success';
        }
    } catch (Exception $e) {
        $msg = 'Error registering patient: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr");
$total->execute($params); $total = $total->fetch()['c'];
$pg = paginate($total, $perPage, $page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$patients = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=p.id) as rx_count,
           (SELECT MAX(appointment_date) FROM appointments a WHERE a.patient_id=p.id AND a.status='completed') as last_visit
    FROM patients p WHERE $whereStr ORDER BY p.full_name ASC LIMIT $limit OFFSET $offset
");
$patients->execute($params); $patients = $patients->fetchAll() ?: [];

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
<div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
  <h5><i class="fas fa-user-injured me-2" style="color:var(--clr-primary)"></i>Patient Records (<?= $total ?>)</h5>
  <div style="display:flex; gap:10px; align-items:center;">
    <form method="GET" style="display:flex;gap:8px;margin:0;">
      <input type="text" name="search" class="form-control" placeholder="Name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:240px;">
      <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
      <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
    </form>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWalkinModal">
      <i class="fas fa-user-plus me-1"></i> Register Walk-in
    </button>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ADD WALKIN MODAL -->
<div class="modal fade" id="addWalkinModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <input type="hidden" name="action" value="add_walkin">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2" style="color:var(--clr-primary)"></i>Register Walk-in Patient</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
        </div>
        <div class="mb-3">
          <label class="form-label">Email (Optional)</label>
          <input type="email" name="email" class="form-control" placeholder="Leave blank if unknown">
          <small class="text-muted">A dummy email will be generated if left blank.</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Birthdate</label>
            <input type="date" name="birthdate" class="form-control" max="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" placeholder="City, Province">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Patient</button>
      </div>
    </form>
  </div>
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
