<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');
$pageTitle  = 'Patient Accounts';
$breadcrumb = ['Admin', 'Patients'];
$db = getDB();

// Automatic removal of specified test patient accounts and empty unverified records
try {
    ensurePatientSchema($db);
    $cleanupEmails = [
        'sorianodaeshawne@gmail.com',
        'sorianoshawne@gmail.com',
        'shawnesoriano@gmail.com'
    ];
    $placeholders = implode(',', array_fill(0, count($cleanupEmails), '?'));
    $cleanupStmt = $db->prepare("SELECT id, avatar FROM patients WHERE email IN ($placeholders)");
    $cleanupStmt->execute($cleanupEmails);
    $cleanupRows = $cleanupStmt->fetchAll();
    if (!empty($cleanupRows)) {
        foreach ($cleanupRows as $cRow) {
            if (!empty($cRow['avatar']) && !str_starts_with($cRow['avatar'], 'http')) {
                $avatarFile = __DIR__ . '/../' . ltrim($cRow['avatar'], '/');
                if (file_exists($avatarFile)) {
                    @unlink($avatarFile);
                }
            }
        }
        $db->prepare("DELETE FROM patients WHERE email IN ($placeholders)")->execute($cleanupEmails);
    }

    // Clean up empty unverified test records that have no names, no phone, and no appointments
    $db->exec("DELETE FROM patients WHERE (full_name IS NULL OR full_name = '') AND (first_name IS NULL OR first_name = '') AND (phone IS NULL OR phone = '') AND email_verified = 0 AND id NOT IN (SELECT DISTINCT patient_id FROM appointments)");
} catch (Exception $e) {}

$search = sanitize($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page']??1)); $perPage = 15;
$where = ["(p.email_verified = 1 OR p.email_verified IS NULL)"]; $params = [];
if ($search) { 
    $where[] = "(p.full_name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; 
    $params = ["%$search%","%$search%","%$search%","%$search%","%$search%","%$search%"]; 
}
$whereStr = implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr"); $total->execute($params); $total = (int)($total->fetch()['c'] ?? 0);
$pg = paginate($total,$perPage,$page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$patients = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count FROM patients p WHERE $whereStr ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset");
$patients->execute($params); $patients = $patients->fetchAll() ?: [];

// Handle status toggle
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle') {
    requireCsrfToken();
    $id = (int)$_POST['id'];
    $cur = $_POST['cur'] ?? 'active';
    $db->prepare("UPDATE patients SET status=? WHERE id=?")->execute([$cur==='active'?'inactive':'active', $id]);
    header('Location: patients.php'); exit;
}

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/patients.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i  class="fas fa-users me-2 pat-b6b6a8"></i>Patient Accounts (<?= $total ?>)</h5>
  <form method="GET" class="pat-1952d6">
    <input type="text" name="search" class="form-control" placeholder="Search name, email, phone..." value="<?= htmlspecialchars($search) ?>" class="pat-a712ff">
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
  </form>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Address</th><th>Appointments</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients yet</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): 
            $patientName = getPatientDisplayName($p);
            $initial = strtoupper(substr($patientName, 0, 1)) ?: 'P';
            $hasAvatar = !empty($p['avatar']);
            $avatarSrc = '';
            if ($hasAvatar) {
                $avatarSrc = str_starts_with($p['avatar'], 'http') ? $p['avatar'] : (BASE_URL . ltrim($p['avatar'], '/'));
            }
        ?>
        <tr>
          <td class="pat-67fd48"><?= $pg['offset']+$i+1 ?></td>
          <td>
            <div class="pat-3b6fff">
              <?php if ($hasAvatar): ?>
                <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="<?= htmlspecialchars($patientName) ?>" class="pat-avatar-img" width="34" height="34" style="width:34px;height:34px;min-width:34px;min-height:34px;max-width:34px;max-height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;display:inline-block;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="pat-ec276b" style="display:none;"><?= $initial ?></div>
              <?php else: ?>
                <div class="pat-ec276b"><?= $initial ?></div>
              <?php endif; ?>
              <div>
                <div class="pat-bac3c9"><?= sanitize($patientName) ?></div>
                <div class="pat-26a4f5"><?= sanitize($p['email'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td class="pat-0de4e7"><?= sanitize($p['phone'] ?? '—') ?></td>
          <td class="pat-67fd48"><?= sanitize($p['address'] ?? '—') ?></td>
          <td><span class="badge bg-info"><?= (int)($p['appt_count'] ?? 0) ?> appts</span></td>
          <td><?= statusBadge($p['status'] ?? 'active') ?></td>
          <td class="pat-67fd48"><?= formatDate($p['created_at'] ?? '') ?></td>
          <td>
            <form method="POST" class="pat-5677b9">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="cur" value="<?= $p['status'] ?? 'active' ?>">
              <button class="btn btn-sm <?= ($p['status'] ?? 'active') === 'active' ? 'btn-warning' : 'btn-success' ?> btn-icon"
                      data-confirm="<?= ($p['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?> this patient account?"
                      title="<?= ($p['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?>">
                <i class="fas fa-<?= ($p['status'] ?? 'active') === 'active' ? 'ban' : 'check' ?>"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages']>1): ?>
  <div class="pat-4174a0">
    <div class="pagination">
      <?php if ($pg['has_prev']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
