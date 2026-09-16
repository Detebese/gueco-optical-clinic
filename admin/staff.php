<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Staff Management';
$breadcrumb = ['Admin', 'Staff'];
$db = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
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
                logActivity("Created staff account for \"$name\" ($email, Role: " . ucfirst($role) . ")", "Staff Management", $_SESSION['user_id'], 'staff');
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
        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = $role;
        }
        $msg = 'Staff account updated.';
        logActivity("Updated staff account for \"$name\" ($email, Role: " . ucfirst($role) . ", Status: " . strtoupper($stat) . ")", "Staff Management", $_SESSION['user_id'], 'staff');
    } elseif ($action === 'toggle_status') {
        $id  = (int)$_POST['id'];
        $cur = $_POST['current_status'] ?? 'active';
        $new = $cur === 'active' ? 'inactive' : 'active';
        // Prevent disabling yourself
        if ($id === (int)$_SESSION['user_id']) { $msg = 'You cannot disable your own account.'; $msgType = 'danger'; }
        else {
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new, $id]);
            $msg = "Account " . ($new === 'active' ? 'activated' : 'deactivated') . ".";
            logActivity(($new === 'active' ? "Activated" : "Deactivated") . " staff account #$id", "Staff Management", $_SESSION['user_id'], 'staff');
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

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/staff.css">';
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

<div class="section-header">
  <h5><i  class="fas fa-user-tie me-2 staff-b6b6a8"></i>Staff Management (<?= count($staff) ?>)</h5>
  <div class="staff-96b971">
    <form method="GET" class="staff-1952d6">
      <input type="text" name="search" class="form-control" placeholder="Search staff..." value="<?= htmlspecialchars($search) ?>" class="staff-a2ad19">
      <select name="role"  class="form-select staff-64fa8f">
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
<div class="staff-8d05f7">
  <?php
  $roleSummary = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
  $roleInfo = ['admin'=>['shield-alt','blue','Administrators'],'doctor'=>['user-md','teal','Optometrists'],'saleslady'=>['user-tie','purple','Saleslady']];
  foreach ($roleInfo as $r => $cfg): ?>
  <div class="staff-01cc59">
    <div class="stat-icon <?= $cfg[1] ?>" class="staff-233067"><i class="fas fa-<?= $cfg[0] ?>"></i></div>
    <div><div class="staff-f9ed85"><?= $roleSummary[$r] ?? 0 ?></div><div class="staff-46d9fd"><?= $cfg[2] ?></div></div>
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
          <td class="staff-67fd48"><?= $i+1 ?></td>
          <td>
            <div class="staff-3b6fff">
              <div class="staff-b9d0f2">
                <?= strtoupper(substr($s['full_name'],0,1)) ?>
              </div>
              <div>
                <div class="staff-bac3c9"><?= sanitize($s['full_name']) ?></div>
                <?php if ($s['id'] === (int)$_SESSION['user_id']): ?>
                <span class="staff-6498a1"><i class="fas fa-user me-1"></i>You</span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="staff-0de4e7"><?= sanitize($s['email']) ?></td>
          <td class="staff-0de4e7"><?= sanitize($s['phone'] ?? '—') ?></td>
          <td><?= roleBadge($s['role']) ?></td>
          <td><?= statusBadge($s['status']) ?></td>
          <td class="staff-67fd48"><?= formatDate($s['created_at']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
              onclick="openEditStaff(<?= htmlspecialchars(json_encode(['id'=>$s['id'],'full_name'=>$s['full_name'],'email'=>$s['email'],'role'=>$s['role'],'phone'=>$s['phone'],'status'=>$s['status']])) ?>)">
              <i class="fas fa-edit"></i>
            </button>
            <?php if ($s['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" class="staff-5677b9">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
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
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="staff-b1eb0f">
          <div  class="form-group staff-da5cd6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div  class="form-group staff-da5cd6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Role *</label>
          <select name="role" class="form-select" required>
            <option value="">Select role</option>
            <option value="admin">Administrator</option>
            <option value="doctor">Optometrist (Doctor)</option>
            <option value="saleslady">Saleslady / Cashier</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Password * <span class="staff-15640c">(min. 6 characters)</span></label>
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
    <form method="POST" onsubmit="return confirmEdit(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="esId">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="full_name" id="esName" class="form-control" required></div>
        <div class="staff-b1eb0f">
          <div  class="form-group staff-da5cd6"><label class="form-label">Email *</label><input type="email" name="email" id="esEmail" class="form-control" required></div>
          <div  class="form-group staff-da5cd6"><label class="form-label">Phone</label><input type="text" name="phone" id="esPhone" class="form-control"></div>
        </div>
        <div class="staff-b1eb0f">
          <div  class="form-group staff-da5cd6"><label class="form-label">Role *</label>
            <select name="role" id="esRole" class="form-select">
              <option value="admin">Administrator</option>
              <option value="doctor">Optometrist</option>
              <option value="saleslady">Saleslady</option>
            </select>
          </div>
          <div  class="form-group staff-da5cd6"><label class="form-label">Status</label>
            <select name="status" id="esStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">New Password <span class="staff-15640c">(leave blank to keep current)</span></label>
          <input type="password" name="password" class="form-control" placeholder="Enter new password or leave blank">
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editStaffModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div>
    </form>
  </div>
</div>

<script>
let currentEditStaff = null;

function confirmEdit(e, form) {
  e.preventDefault();
  
  const s = currentEditStaff;
  const newPass = form.password.value;
  if (s) {
      const name = document.getElementById('esName').value;
      const email = document.getElementById('esEmail').value;
      const phone = document.getElementById('esPhone').value;
      const role = document.getElementById('esRole').value;
      const stat = document.getElementById('esStatus').value;

      if (!newPass && name === s.full_name && email === s.email && phone === (s.phone || '') && role === s.role && stat === s.status) {
          Swal.fire({
              title: 'Notice',
              text: 'No changes were made. Account is already up to date!',
              icon: 'info',
              background: 'var(--bg-card)',
              color: 'var(--text-primary)',
              confirmButtonColor: 'var(--clr-primary)'
          });
          return;
      }
  }

  Swal.fire({
      title: 'Save Changes?',
      text: 'Are you sure you want to update this account?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: 'var(--clr-primary)',
      cancelButtonColor: 'var(--clr-danger)',
      confirmButtonText: 'Yes, update it!',
      background: 'var(--bg-card)',
      color: 'var(--text-primary)'
  }).then((result) => {
      if (result.isConfirmed) {
          form.submit();
      }
  });
}

function openEditStaff(s) {
  currentEditStaff = s;
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
