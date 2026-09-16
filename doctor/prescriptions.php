<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Prescriptions';
$breadcrumb = ['Doctor', 'Prescriptions'];
$db = getDB();
$msg = ''; $msgType = 'success';

// Pre-fill patient
$prePatientId = (int)($_GET['patient_id'] ?? 0);
$prePatient = null;
if ($prePatientId) {
    $prePatient = $db->prepare("SELECT * FROM patients WHERE id=?"); $prePatient->execute([$prePatientId]); $prePatient = $prePatient->fetch();
}

// Save prescription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrfToken();
    $patId  = (int)$_POST['patient_id'];
    $data = [
        'patient_id' => $patId,
        'doctor_id'  => $_SESSION['user_id'],
        'od_sphere'  => sanitize($_POST['od_sphere'] ?? ''),
        'od_cylinder'=> sanitize($_POST['od_cylinder'] ?? ''),
        'od_axis'    => sanitize($_POST['od_axis'] ?? ''),
        'os_sphere'  => sanitize($_POST['os_sphere'] ?? ''),
        'os_cylinder'=> sanitize($_POST['os_cylinder'] ?? ''),
        'os_axis'    => sanitize($_POST['os_axis'] ?? ''),
        'pd'         => sanitize($_POST['pd'] ?? ''),
        'add_power'  => sanitize($_POST['add_power'] ?? ''),
        'notes'      => sanitize(trim($_POST['notes'] ?? '')),
    ];

    if (!$patId) { $msg = 'Please select a patient.'; $msgType = 'danger'; }
    else {
        $db->prepare("INSERT INTO prescriptions (patient_id,doctor_id,od_sphere,od_cylinder,od_axis,os_sphere,os_cylinder,os_axis,pd,add_power,notes) VALUES (:patient_id,:doctor_id,:od_sphere,:od_cylinder,:od_axis,:os_sphere,:os_cylinder,:os_axis,:pd,:add_power,:notes)")->execute($data);
        $msg = 'Prescription saved successfully.';
        $prePatientId = $patId;
        $prePatient = $db->prepare("SELECT * FROM patients WHERE id=?"); $prePatient->execute([$patId]); $prePatient = $prePatient->fetch();
        $patientName = $prePatient['full_name'] ?? ('Patient #' . $patId);
        logActivity("Created optical prescription record for patient: $patientName", "Prescriptions", $_SESSION['user_id'], 'staff');
    }
}

// Prescriptions list for selected patient or all recent
if ($prePatientId) {
    $rxList = $db->prepare("SELECT rx.*, p.full_name as patient_name FROM prescriptions rx JOIN patients p ON p.id=rx.patient_id WHERE rx.patient_id=? ORDER BY rx.created_at DESC");
    $rxList->execute([$prePatientId]);
} else {
    $rxList = $db->prepare("SELECT rx.*, p.full_name as patient_name FROM prescriptions rx JOIN patients p ON p.id=rx.patient_id WHERE rx.doctor_id=? ORDER BY rx.created_at DESC LIMIT 20");
    $rxList->execute([$_SESSION['user_id']]);
}
$rxList = $rxList->fetchAll();

// All patients for dropdown
$allPatients = $db->query("SELECT id, full_name FROM patients WHERE status='active' ORDER BY full_name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        text: '<?= addslashes($msg) ?>',
        icon: '<?= $msgType === "success" ? "success" : ($msgType === "info" ? "info" : "error") ?>',
        confirmButtonColor: 'var(--clr-primary)',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        timer: 3000,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<div class="row">
  <!-- Write Rx Form -->
  <div class="col-5">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-glasses me-2" style="color:var(--clr-secondary)"></i>Write Prescription</h6>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="action" value="save">

          <div class="form-group">
            <label class="form-label">Patient *</label>
            <select name="patient_id" class="form-select" required>
              <option value="">Select patient...</option>
              <?php foreach ($allPatients as $pt): ?>
              <option value="<?= $pt['id'] ?>" <?= $prePatientId==$pt['id']?'selected':'' ?>><?= sanitize($pt['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Prescription grid -->
          <div style="background:var(--bg-hover);border-radius:12px;padding:16px;margin-bottom:16px;">
            <div style="font-size:.8rem;font-weight:700;margin-bottom:12px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em">Prescription Values</div>
            <div style="display:grid;grid-template-columns:auto 1fr 1fr 1fr;gap:8px;align-items:center;font-size:.78rem;">
              <div></div>
              <div style="text-align:center;font-weight:700;color:var(--text-muted)">SPH</div>
              <div style="text-align:center;font-weight:700;color:var(--text-muted)">CYL</div>
              <div style="text-align:center;font-weight:700;color:var(--text-muted)">AXIS</div>

              <div style="font-weight:700;color:var(--clr-primary);padding-right:6px;">OD <span style="font-weight:400;font-size:.7rem">(Right)</span></div>
              <input type="text" name="od_sphere"   class="form-control" placeholder="0.00">
              <input type="text" name="od_cylinder" class="form-control" placeholder="0.00">
              <input type="text" name="od_axis"     class="form-control" placeholder="0°">

              <div style="font-weight:700;color:var(--clr-secondary);padding-right:6px;">OS <span style="font-weight:400;font-size:.7rem">(Left)</span></div>
              <input type="text" name="os_sphere"   class="form-control" placeholder="0.00">
              <input type="text" name="os_cylinder" class="form-control" placeholder="0.00">
              <input type="text" name="os_axis"     class="form-control" placeholder="0°">
            </div>
          </div>

          <div style="display:flex;gap:12px;">
            <div class="form-group" style="flex:1;"><label class="form-label">PD (Pupillary Distance)</label><input type="text" name="pd" class="form-control" placeholder="e.g. 62 or 31/31"></div>
            <div class="form-group" style="flex:1;"><label class="form-label">ADD Power</label><input type="text" name="add_power" class="form-control" placeholder="e.g. +1.50"></div>
          </div>

          <div class="form-group"><label class="form-label">Clinical Notes / Remarks</label><textarea name="notes" class="form-control" rows="3" placeholder="Recommended lens type, follow-up instructions..."></textarea></div>

          <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save Prescription</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Rx History -->
  <div class="col-7">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-history me-2" style="color:var(--clr-info)"></i>
          <?= $prePatient ? 'Prescriptions for ' . sanitize($prePatient['full_name']) : 'My Recent Prescriptions' ?>
        </h6>
        <?php if ($prePatient): ?>
        <a href="patients.php?view=<?= $prePatientId ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-user"></i> Patient</a>
        <?php endif; ?>
      </div>
      <?php if (empty($rxList)): ?>
      <div class="empty-state" style="padding:40px"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No prescriptions found</h6><p>Write a new prescription using the form</p></div>
      <?php else: ?>
      <div style="overflow-y:auto;max-height:600px;">
        <?php foreach ($rxList as $rx): ?>
        <div style="padding:16px 20px;border-bottom:1px solid var(--border-light);">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
            <div>
              <div style="font-weight:700;font-size:.88rem"><?= sanitize($rx['patient_name']) ?></div>
              <div style="font-size:.72rem;color:var(--text-muted)"><?= formatDateTime($rx['created_at']) ?></div>
            </div>
            <span class="badge bg-secondary">Rx #<?= $rx['id'] ?></span>
          </div>
          <!-- Rx Table mini -->
          <div style="background:var(--bg-hover);border-radius:8px;padding:10px;">
            <table style="width:100%;font-size:.74rem;border-collapse:collapse;">
              <thead><tr>
                <th style="padding:4px 8px;color:var(--text-muted);font-weight:600;text-align:left;"></th>
                <th style="padding:4px 8px;text-align:center;color:var(--text-muted)">SPH</th>
                <th style="padding:4px 8px;text-align:center;color:var(--text-muted)">CYL</th>
                <th style="padding:4px 8px;text-align:center;color:var(--text-muted)">AXIS</th>
              </tr></thead>
              <tbody>
                <tr><td style="padding:4px 8px;font-weight:700;color:var(--clr-primary)">OD</td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['od_sphere']??'—' ?></td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['od_cylinder']??'—' ?></td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['od_axis']??'—' ?></td></tr>
                <tr><td style="padding:4px 8px;font-weight:700;color:var(--clr-secondary)">OS</td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['os_sphere']??'—' ?></td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['os_cylinder']??'—' ?></td>
                    <td style="text-align:center;padding:4px 8px"><?= $rx['os_axis']??'—' ?></td></tr>
              </tbody>
            </table>
            <div style="margin-top:6px;font-size:.72rem;color:var(--text-muted)">PD: <strong><?= $rx['pd']??'—' ?></strong> &nbsp;|&nbsp; ADD: <strong><?= $rx['add_power']??'—' ?></strong></div>
            <?php if ($rx['notes']): ?><div style="margin-top:6px;font-size:.72rem;color:var(--text-secondary);font-style:italic"><?= sanitize($rx['notes']) ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
