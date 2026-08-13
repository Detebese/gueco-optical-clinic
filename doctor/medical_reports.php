<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');
$pageTitle  = 'Medical Reports';
$breadcrumb = ['Doctor', 'Medical Reports'];
$db = getDB();

$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');

// Summary
$totalAppts = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status='completed'");
$totalAppts->execute([$filterFrom,$filterTo]); $totalAppts = $totalAppts->fetch()['c'];

$totalRx = $db->prepare("SELECT COUNT(*) as c FROM prescriptions rx JOIN users u ON u.id=rx.doctor_id WHERE u.role='doctor' AND DATE(rx.created_at) BETWEEN ? AND ?");
$totalRx->execute([$filterFrom,$filterTo]); $totalRx = $totalRx->fetch()['c'];

$totalPatients = $db->prepare("SELECT COUNT(DISTINCT patient_id) as c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status='completed'");
$totalPatients->execute([$filterFrom,$filterTo]); $totalPatients = $totalPatients->fetch()['c'];

// Appointment by purpose breakdown
$byPurpose = $db->prepare("SELECT purpose, COUNT(*) as cnt FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status='completed' GROUP BY purpose ORDER BY cnt DESC");
$byPurpose->execute([$filterFrom,$filterTo]); $byPurpose = $byPurpose->fetchAll();

// Recent completed appointments
$recentAppts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.gender,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=a.patient_id) as rx_count
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.appointment_date BETWEEN ? AND ? AND a.status='completed'
    ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 25
");
$recentAppts->execute([$filterFrom,$filterTo]); $recentAppts = $recentAppts->fetchAll();

// My prescriptions
$myRx = $db->prepare("
    SELECT rx.*, p.full_name as patient_name
    FROM prescriptions rx JOIN patients p ON p.id=rx.patient_id
    WHERE rx.doctor_id=? AND DATE(rx.created_at) BETWEEN ? AND ?
    ORDER BY rx.created_at DESC LIMIT 20
");
$myRx->execute([$_SESSION['user_id'],$filterFrom,$filterTo]); $myRx = $myRx->fetchAll();

$purposeLabels = array_map(fn($r) => ucwords(str_replace('_',' ',$r['purpose'])), $byPurpose);
$purposeData   = array_map(fn($r) => (int)$r['cnt'], $byPurpose);

include __DIR__ . '/../includes/header.php';
?>

<!-- Filter -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px">From</label><input type="date" name="from" class="form-control" value="<?= $filterFrom ?>"></div>
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px">To</label><input type="date" name="to" class="form-control" value="<?= $filterTo ?>"></div>
      <div style="display:flex;gap:6px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">This Month</a>
        <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">Today</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-primary)"><div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div><div class="stat-info"><div class="stat-value"><?= $totalAppts ?></div><div class="stat-label">Completed Appointments</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-secondary)"><div class="stat-icon purple"><i class="fas fa-glasses"></i></div><div class="stat-info"><div class="stat-value"><?= $totalRx ?></div><div class="stat-label">Prescriptions Written</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-success)"><div class="stat-icon green"><i class="fas fa-users"></i></div><div class="stat-info"><div class="stat-value"><?= $totalPatients ?></div><div class="stat-label">Unique Patients Seen</div></div></div></div>
</div>

<div class="row" style="margin-bottom:24px;">
  <!-- Purpose Chart -->
  <div class="col-5">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-chart-pie me-2" style="color:var(--clr-secondary)"></i>Visits by Purpose</h6></div>
      <div class="card-body" style="display:flex;align-items:center;justify-content:center;">
        <?php if (empty($byPurpose)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted)"><i class="fas fa-chart-pie" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>No data yet</div>
        <?php else: ?>
        <div class="chart-container" style="height:240px;width:240px;"><canvas id="purposeChart"></canvas></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($byPurpose)): ?>
      <div style="padding:0 16px 16px;">
        <?php foreach ($byPurpose as $p): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border-light);font-size:.8rem;">
          <span><?= ucwords(str_replace('_',' ',$p['purpose'])) ?></span>
          <span style="font-weight:700"><?= $p['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Appointments -->
  <div class="col-7">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-list me-2" style="color:var(--clr-primary)"></i>Completed Appointments (<?= count($recentAppts) ?>)</h6></div>
      <?php if (empty($recentAppts)): ?>
      <div class="empty-state" style="padding:40px"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h6>No completed appointments in this period</h6></div>
      <?php else: ?>
      <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
        <table class="table">
          <thead><tr><th>Patient</th><th>Date</th><th>Purpose</th><th>Gender</th><th>Prescriptions</th></tr></thead>
          <tbody>
            <?php foreach ($recentAppts as $a): ?>
            <tr>
              <td style="font-weight:600;font-size:.85rem"><?= sanitize($a['patient_name']) ?></td>
              <td style="font-size:.8rem"><?= formatDate($a['appointment_date']) ?></td>
              <td style="font-size:.8rem"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></td>
              <td style="font-size:.8rem"><?= $a['gender']?ucfirst($a['gender']):'—' ?></td>
              <td><span class="badge bg-<?= $a['rx_count']>0?'secondary':'warning' ?>"><?= $a['rx_count'] ?> Rx</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- My Prescriptions -->
<div class="card">
  <div class="card-header"><h6><i class="fas fa-glasses me-2" style="color:var(--clr-secondary)"></i>My Prescriptions Written (<?= count($myRx) ?>)</h6></div>
  <?php if (empty($myRx)): ?>
  <div class="empty-state" style="padding:40px"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No prescriptions in this period</h6></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Patient</th><th>OD (Right)</th><th>OS (Left)</th><th>PD</th><th>ADD</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($myRx as $rx): ?>
        <tr>
          <td style="font-weight:600;font-size:.85rem"><?= sanitize($rx['patient_name']) ?></td>
          <td style="font-size:.75rem">SPH <?= $rx['od_sphere']??'—' ?> / CYL <?= $rx['od_cylinder']??'—' ?> / AX <?= $rx['od_axis']??'—' ?></td>
          <td style="font-size:.75rem">SPH <?= $rx['os_sphere']??'—' ?> / CYL <?= $rx['os_cylinder']??'—' ?> / AX <?= $rx['os_axis']??'—' ?></td>
          <td style="font-size:.8rem"><?= $rx['pd']??'—' ?></td>
          <td style="font-size:.8rem"><?= $rx['add_power']??'—' ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDate($rx['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
$extraScripts = '';
if (!empty($byPurpose)):
$extraScripts = '<script>
const pCtx = document.getElementById("purposeChart");
if(pCtx){ new Chart(pCtx,{ type:"doughnut", data:{ labels:'.json_encode($purposeLabels).', datasets:[{ data:'.json_encode($purposeData).', backgroundColor:["#2563EB","#7C3AED","#059669","#D97706","#DC2626"], borderWidth:0, hoverOffset:6 }] }, options:{ responsive:true, maintainAspectRatio:false, cutout:"60%", plugins:{ legend:{ position:"bottom", labels:{ font:{family:"Poppins",size:11}, padding:8, boxWidth:10 } } } } }); }
</script>';
endif;
include __DIR__ . '/../includes/footer.php'; ?>
