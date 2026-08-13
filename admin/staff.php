<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Staff Management';
$breadcrumb = ['Admin', 'Staff'];
$db = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name   = sanitize(trim($_POST['full_name'] ?? ''));
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $role   = $_POST['role'] ?? '';
        $phone  = sanitize(trim($_POST['phone'] ?? ''));

        if (!$name || !$email || !$pass || !$role) {
            $msg = 'All fields marked * are required.'; $msgType = 'danger';
        } elseif (strlen($pass) < 6) {
            $msg = 'Password must be at least 6 characters.'; $msgType = 'danger';
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE email=?"); $check->execute([$email]);
            if ($check->fetch()) { $msg = 'Email already exists.'; $msgType = 'danger'; }
            else {
                $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES (?,?,?,?,?)")
                   ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $phone]);
                $msg = "Staff account for \"$name\" created.";
            }
        }
    } elseif ($action === 'edit') {
        $id    = (int)$_POST['id'];
        $name  = sanitize(trim($_POST['full_name'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? '';
        $phone = sanitize(trim($_POST['phone'] ?? ''));
        $stat  = $_POST['status'] ?? 'active';
        $pass  = $_POST['password'] ?? '';

        if ($pass) {
            $db->prepare("UPDATE users SET full_name=?,email=?,password=?,role=?,phone=?,status=? WHERE id=?")
               ->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$phone,$stat,$id]);
        } else {
            $db->prepare("UPDATE users SET full_name=?,email=?,role=?,phone=?,status=? WHERE id=?")
               ->execute([$name,$email,$role,$phone,$stat,$id]);
        }
        $msg = 'Staff account updated.';
    } elseif ($action === 'toggle_status') {
        $id  = (int)$_POST['id'];
        $cur = $_POST['current_status'] ?? 'active';
        $new = $cur === 'active' ? 'inactive' : 'active';
        // Prevent disabling yourself
        if ($id === (int)$_SESSION['user_id']) { $msg = 'You cannot disable your own account.'; $msgType = 'danger'; }
        else {
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $id]);
            $msg = "Account " . ($new === 'active' ? 'activated' : 'deactivated') . ".";
        }
    }
}

$search = sanitize($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$where = ['1=1']; $params = [];
if ($search) { $where[] = 'full_name LIKE ?'; $params[] = "%$search%"; }
if ($roleFilter) { $where[] = 'role=?'; $params[] = $roleFilter; }
$whereStr = implode(' AND ', $where);
$staff = $db->prepare("SELECT * FROM users WHERE $whereStr ORDER BY role, full_name ASC");
$staff->execute($params); $staff = $staff->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>" data-auto-dismiss="4000">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= $msg ?>
</div>
<?php endif; ?>

<div class="section-header">
  <h5><i class="fas fa-user-tie me-2" style="color:var(--clr-primary)"></i>Staff Management (<?= count($staff) ?>)</h5>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:8px;">
      <input type="text" name="search" class="form-control" placeholder="Search staff..." value="<?= htmlspecialchars($search) ?>" style="width:180px;">
      <select name="role" class="form-select" style="width:160px;">
        <option value="">All Roles</option>
        <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Administrator</option>
        <option value="doctor" <?= $roleFilter==='doctor'?'selected':'' ?>>Optometrist</option>
        <option value="saleslady" <?= $roleFilter==='saleslady'?'selected':'' ?>>Saleslady</option>
      </select>
      <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
    </form>
    <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-user-plus"></i> Add Staff</button>
  </div>
</div>

<!-- Role summary cards -->
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <?php
  $roleSummary = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
  $roleInfo = ['admin'=>['shield-alt','blue','Administrators'],'doctor'=>['user-md','teal','Optometrists'],'saleslady'=>['user-tie','purple','Saleslady']];
  foreach ($roleInfo as $r => $cfg): ?>
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:12px;flex:1;min-width:150px;">
    <div class="stat-icon <?= $cfg[1] ?>" style="width:40px;height:40px"><i class="fas fa-<?= $cfg[0] ?>"></i></div>
    <div><div style="font-size:1.4rem;font-weight:800"><?= $roleSummary[$r] ?? 0 ?></div><div style="font-size:.72rem;color:var(--text-muted)"><?= $cfg[2] ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Staff Member</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Since</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($staff)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No staff found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($staff as $i => $s): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0;">
                <?= strtoupper(substr($s['full_name'],0,1)) ?>
              </div>
              <div>
                <div style="font-weight:600;font-size:.88rem"><?= sanitize($s['full_name']) ?></div>
                <?php if ($s['id'] === (int)$_SESSION['user_id']): ?>
                <span style="font-size:.65rem;color:var(--clr-primary);font-weight:600;"><i class="fas fa-user me-1"></i>You</span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($s['email']) ?></td>
          <td style="font-size:.82rem"><?= sanitize($s['phone'] ?? '—') ?></td>
          <td><?= roleBadge($s['role']) ?></td>
          <td><?= statusBadge($s['status']) ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDate($s['created_at']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
              onclick="openEditStaff(<?= htmlspecialchars(json_encode(['id'=>$s['id'],'full_name'=>$s['full_name'],'email'=>$s['email'],'role'=>$s['role'],'phone'=>$s['phone'],'status'=>$s['status']])) ?>)">
              <i class="fas fa-edit"></i>
            </button>
            <?php if ($s['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <input type="hidden" name="current_status" value="<?= $s['status'] ?>">
              <button class="btn btn-sm <?= $s['status']==='active'?'btn-warning':'btn-success' ?> btn-icon"
                      title="<?= $s['status']==='active'?'Deactivate':'Activate' ?>"
                      data-confirm="<?= $s['status']==='active'?'Deactivate':'Activate' ?> this account?">
                <i class="fas fa-<?= $s['status']==='active'?'ban':'check' ?>"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Staff Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <div class="modal-header"><h5><i class="fas fa-user-plus me-2"></i>Add Staff Account</h5><button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
        <div style="display:flex;gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div class="form-group" style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Role *</label>
          <select name="role" class="form-select" required>
            <option value="">Select role</option>
            <option value="admin">Administrator</option>
            <option value="doctor">Optometrist (Doctor)</option>
            <option value="saleslady">Saleslady / Cashier</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Password * <span style="color:var(--text-muted);font-weight:400;font-size:.75rem">(min. 6 characters)</span></label>
          <input type="password" name="password" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Account</button></div>
    </form>
  </div>
</div>

<!-- Edit Staff Modal -->
<div class="modal-overlay" id="editStaffModal">
  <div class="modal-box">
    <div class="modal-header"><h5><i class="fas fa-edit me-2"></i>Edit Staff Account</h5><button class="modal-close" onclick="closeModal('editStaffModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="esId">
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" id="esName" class="form-control" required></div>
        <div style="display:flex;gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label">Email *</label><input type="email" name="email" id="esEmail" class="form-control" required></div>
          <div class="form-group" style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" id="esPhone" class="form-control"></div>
        </div>
        <div style="display:flex;gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label">Role *</label>
            <select name="role" id="esRole" class="form-select">
              <option value="admin">Administrator</option>
              <option value="doctor">Optometrist</option>
              <option value="saleslady">Saleslady</option>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" id="esStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">New Password <span style="color:var(--text-muted);font-weight:400;font-size:.75rem">(leave blank to keep current)</span></label>
          <input type="password" name="password" class="form-control" placeholder="Enter new password or leave blank">
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editStaffModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div>
    </form>
  </div>
</div>

<script>
function openEditStaff(s) {
  document.getElementById('esId').value    = s.id;
  document.getElementById('esName').value  = s.full_name;
  document.getElementById('esEmail').value = s.email;
  document.getElementById('esPhone').value = s.phone || '';
  document.getElementById('esRole').value  = s.role;
  document.getElementById('esStatus').value= s.status;
  openModal('editStaffModal');
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
