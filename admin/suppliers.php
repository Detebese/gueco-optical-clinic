<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Supplier Management';
$breadcrumb = ['Admin', 'Suppliers'];
$db = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $data = [
            sanitize(trim($_POST['company_name'] ?? '')),
            sanitize(trim($_POST['contact_person'] ?? '')),
            sanitize(trim($_POST['phone'] ?? '')),
            sanitize(trim($_POST['email'] ?? '')),
            sanitize(trim($_POST['address'] ?? '')),
        ];
        if (!$data[0]) { $msg = 'Company name is required.'; $msgType = 'danger'; }
        elseif ($action === 'add') {
            $db->prepare("INSERT INTO suppliers (company_name,contact_person,phone,email,address) VALUES (?,?,?,?,?)")->execute($data);
            $msg = 'Supplier added successfully.';
            logActivity("Added new supplier \"{$data[0]}\"", "Suppliers", $_SESSION['user_id'], 'staff');
        } else {
            $id = (int)$_POST['id'];
            $stat = $_POST['status'] ?? 'active';
            $curr = $db->prepare("SELECT company_name,contact_person,phone,email,address,status FROM suppliers WHERE id=?");
            $curr->execute([$id]);
            $old = $curr->fetch();
            if ($old && $old['company_name'] === $data[0] && ($old['contact_person'] ?? '') === $data[1] && ($old['phone'] ?? '') === $data[2] && ($old['email'] ?? '') === $data[3] && ($old['address'] ?? '') === $data[4] && $old['status'] === $stat) {
                $msg = 'No changes were made. The supplier details are already up to date!';
                $msgType = 'info';
            } else {
                $data[] = $stat; $data[] = $id;
                $db->prepare("UPDATE suppliers SET company_name=?,contact_person=?,phone=?,email=?,address=?,status=? WHERE id=?")->execute($data);
                $msg = 'Supplier updated.';
                logActivity("Updated supplier \"{$data[0]}\" (Status: " . strtoupper($stat) . ")", "Suppliers", $_SESSION['user_id'], 'staff');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $cnt = $db->prepare("SELECT COUNT(*) as c FROM products WHERE supplier_id=? AND status='active'"); $cnt->execute([$id]); $cnt = $cnt->fetch()['c'];
        if ($cnt > 0) { $msg = "Cannot delete: $cnt product(s) linked to this supplier."; $msgType = 'danger'; }
        else { 
            $db->prepare("UPDATE suppliers SET status='inactive' WHERE id=?")->execute([$id]); 
            $msg = 'Supplier deactivated.'; 
            logActivity("Deactivated supplier #$id", "Suppliers", $_SESSION['user_id'], 'staff');
        }
    }
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id AND p.status='active') as product_count FROM suppliers s WHERE company_name LIKE ? OR contact_person LIKE ? ORDER BY s.company_name ASC");
    $term = "%$search%";
    $stmt->execute([$term, $term]);
    $suppliers = $stmt->fetchAll();
} else {
    $suppliers = $db->query("SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id AND p.status='active') as product_count FROM suppliers s ORDER BY s.company_name ASC")->fetchAll();
}

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/suppliers.css">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : "Notice" ?>',
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
  <h5><i  class="fas fa-truck me-2 sup-b6b6a8"></i>Suppliers (<?= count($suppliers) ?>)</h5>
  <div class="sup-1952d6">
    <form method="GET" class="sup-1952d6">
      <input type="text" name="search" class="form-control" placeholder="Search supplier..." value="<?= htmlspecialchars($search) ?>" class="sup-c4c12b">
      <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
    </form>
    <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Supplier</button>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Company</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Products</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($suppliers)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-truck"></i></div><h6>No suppliers found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($suppliers as $i => $sup): ?>
        <tr>
          <td class="sup-67fd48"><?= $i+1 ?></td>
          <td><div class="sup-bac3c9"><?= sanitize($sup['company_name']) ?></div>
              <div class="sup-46d9fd"><?= sanitize($sup['address'] ?: '—') ?></div></td>
          <td class="sup-9ee0bb"><?= sanitize($sup['contact_person'] ?: '—') ?></td>
          <td class="sup-0de4e7"><?= sanitize($sup['phone'] ?: '—') ?></td>
          <td class="sup-bb0425"><?= sanitize($sup['email'] ?: '—') ?></td>
          <td><span class="badge bg-info"><?= $sup['product_count'] ?></span></td>
          <td><?= statusBadge($sup['status']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
              onclick="openEditSupplier(<?= htmlspecialchars(json_encode($sup)) ?>)">
              <i class="fas fa-edit"></i>
            </button>
            <?php if ($sup['product_count'] == 0): ?>
            <form method="POST" class="sup-5677b9">
              <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $sup['id'] ?>">
              <button class="btn btn-sm btn-danger btn-icon" data-confirm="Deactivate this supplier?"><i class="fas fa-ban"></i></button>
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

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <div class="modal-header"><h5><i  class="fas fa-plus me-2 sup-b6b6a8"></i>Add Supplier</h5>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group"><label class="form-label">Company Name *</label><input type="text" name="company_name" class="form-control" required></div>
        <div class="sup-b1eb0f">
          <div  class="form-group sup-da5cd6"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
          <div  class="form-group sup-da5cd6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
        <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header"><h5><i  class="fas fa-edit me-2 sup-b6b6a8"></i>Edit Supplier</h5>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" onsubmit="return confirmEditSupplier(event, this)">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
        <div class="form-group"><label class="form-label">Company Name *</label><input type="text" name="company_name" id="eName" class="form-control" required></div>
        <div class="sup-b1eb0f">
          <div  class="form-group sup-da5cd6"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="eContact" class="form-control"></div>
          <div  class="form-group sup-da5cd6"><label class="form-label">Phone</label><input type="text" name="phone" id="ePhone" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="eEmail" class="form-control"></div>
        <div class="form-group"><label class="form-label">Address</label><textarea name="address" id="eAddress" class="form-control" rows="2"></textarea></div>
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" id="eStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div>
    </form>
  </div>
</div>

<script>
let currentEditSupplier = null;

function openEditSupplier(s) {
  currentEditSupplier = s;
  document.getElementById('eId').value = s.id;
  document.getElementById('eName').value = s.company_name;
  document.getElementById('eContact').value = s.contact_person || '';
  document.getElementById('ePhone').value = s.phone || '';
  document.getElementById('eEmail').value = s.email || '';
  document.getElementById('eAddress').value = s.address || '';
  document.getElementById('eStatus').value = s.status;
  openModal('editModal');
}

function confirmEditSupplier(e, form) {
  e.preventDefault();
  const s = currentEditSupplier;
  if (s) {
    const name = document.getElementById('eName').value.trim();
    const contact = document.getElementById('eContact').value.trim();
    const phone = document.getElementById('ePhone').value.trim();
    const email = document.getElementById('eEmail').value.trim();
    const address = document.getElementById('eAddress').value.trim();
    const stat = document.getElementById('eStatus').value;

    if (name === s.company_name && contact === (s.contact_person || '') && phone === (s.phone || '') && email === (s.email || '') && address === (s.address || '') && stat === s.status) {
      Swal.fire({
        title: 'Notice',
        text: 'No changes were made. The supplier details are already up to date!',
        icon: 'info',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        confirmButtonColor: 'var(--clr-primary)'
      });
      return false;
    }
  }

  Swal.fire({
    title: 'Save Changes?',
    text: 'Are you sure you want to update this supplier?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: 'var(--clr-primary)',
    cancelButtonColor: 'var(--clr-danger)',
    confirmButtonText: 'Yes, update it!',
    cancelButtonText: 'Cancel',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)'
  }).then((result) => {
    if (result.isConfirmed) {
      form._isSubmitting = true;
      form.submit();
    }
  });
  return false;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
