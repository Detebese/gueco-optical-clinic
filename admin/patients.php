<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');
$pageTitle  = 'Patient Accounts';
$breadcrumb = ['Admin', 'Patients'];
$db = getDB();

$search = sanitize($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page']??1)); $perPage = 15;
$where = ['1=1']; $params = [];
if ($search) { $where[] = "(p.full_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
$whereStr = implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr"); $total->execute($params); $total = $total->fetch()['c'];
$pg = paginate($total,$perPage,$page);

$params2 = array_merge($params,[$perPage,$pg['offset']]);
$patients = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count FROM patients p WHERE $whereStr ORDER BY p.created_at DESC LIMIT ? OFFSET ?");
$patients->execute($params2); $patients = $patients->fetchAll();

// Handle status toggle
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle') {
    $id = (int)$_POST['id'];
    $cur = $_POST['cur'] ?? 'active';
    $db->prepare("UPDATE patients SET status=? WHERE id=?")->execute([$cur==='active'?'inactive':'active', $id]);
    header('Location: patients.php'); exit;
}

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i class="fas fa-users me-2" style="color:var(--clr-primary)"></i>Patient Accounts (<?= $total ?>)</h5>
  <form method="GET" style="display:flex;gap:8px;">
    <input type="text" name="search" class="form-control" placeholder="Search name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:260px;">
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
        <?php foreach ($patients as $i => $p): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $pg['offset']+$i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;"><?= strtoupper(substr($p['full_name'],0,1)) ?></div>
              <div><div style="font-weight:600;font-size:.88rem"><?= sanitize($p['full_name']) ?></div><div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['email']) ?></div></div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($p['phone']??'—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($p['address']??'—') ?></td>
          <td><span class="badge bg-info"><?= $p['appt_count'] ?> appts</span></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDate($p['created_at']) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="cur" value="<?= $p['status'] ?>">
              <button class="btn btn-sm <?= $p['status']==='active'?'btn-warning':'btn-success' ?> btn-icon"
                      data-confirm="<?= $p['status']==='active'?'Deactivate':'Activate' ?> this patient account?"
                      title="<?= $p['status']==='active'?'Deactivate':'Activate' ?>">
                <i class="fas fa-<?= $p['status']==='active'?'ban':'check' ?>"></i>
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
  <div style="padding:14px 20px;border-top:1px solid var(--border-light);">
    <div class="pagination">
      <?php if ($pg['has_prev']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
