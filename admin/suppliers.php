<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Supplier Management';
$breadcrumb = ['Admin', 'Suppliers'];
$db = getDB();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        } else {
            $id = (int)$_POST['id'];
            $stat = $_POST['status'] ?? 'active';
            $data[] = $stat; $data[] = $id;
            $db->prepare("UPDATE suppliers SET company_name=?,contact_person=?,phone=?,email=?,address=?,status=? WHERE id=?")->execute($data);
            $msg = 'Supplier updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $cnt = $db->prepare("SELECT COUNT(*) as c FROM products WHERE supplier_id=? AND status='active'"); $cnt->execute([$id]); $cnt = $cnt->fetch()['c'];
        if ($cnt > 0) { $msg = "Cannot delete: $cnt product(s) linked to this supplier."; $msgType = 'danger'; }
        else { $db->prepare("UPDATE suppliers SET status='inactive' WHERE id=?")->execute([$id]); $msg = 'Supplier deactivated.'; }
    }
}

$search = sanitize($_GET['search'] ?? '');
$whereStr = $search ? "WHERE company_name LIKE '%$search%' OR contact_person LIKE '%$search%'" : '';
$suppliers = $db->query("SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id AND p.status='active') as product_count FROM suppliers s $whereStr ORDER BY s.company_name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>" data-auto-dismiss="4000">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= $msg ?>
</div>
<?php endif; ?>

<div class="section-header">
  <h5><i class="fas fa-truck me-2" style="color:var(--clr-primary)"></i>Suppliers (<?= count($suppliers) ?>)</h5>
  <div style="display:flex;gap:8px;">
    <form method="GET" style="display:flex;gap:8px;">
      <input type="text" name="search" class="form-control" placeholder="Search supplier..." value="<?= htmlspecialchars($search) ?>" style="width:220px;">
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
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $i+1 ?></td>
          <td><div style="font-weight:600;font-size:.88rem"><?= sanitize($sup['company_name']) ?></div>
              <div style="font-size:.72rem;color:var(--text-muted)"><?= sanitize($sup['address'] ?: '—') ?></div></td>
          <td style="font-size:.83rem"><?= sanitize($sup['contact_person'] ?: '—') ?></td>
          <td style="font-size:.82rem"><?= sanitize($sup['phone'] ?: '—') ?></td>
          <td style="font-size:.78rem"><?= sanitize($sup['email'] ?: '—') ?></td>
          <td><span class="badge bg-info"><?= $sup['product_count'] ?></span></td>
          <td><?= statusBadge($sup['status']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
              onclick="openEditSupplier(<?= htmlspecialchars(json_encode($sup)) ?>)">
              <i class="fas fa-edit"></i>
            </button>
            <?php if ($sup['product_count'] == 0): ?>
            <form method="POST" style="display:inline">
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
    <div class="modal-header"><h5><i class="fas fa-plus me-2" style="color:var(--clr-primary)"></i>Add Supplier</h5>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group"><label class="form-label">Company Name *</label><input type="text" name="company_name" class="form-control" required></div>
        <div style="display:flex;gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
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
    <div class="modal-header"><h5><i class="fas fa-edit me-2" style="color:var(--clr-primary)"></i>Edit Supplier</h5>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
        <div class="form-group"><label class="form-label">Company Name *</label><input type="text" name="company_name" id="eName" class="form-control" required></div>
        <div style="display:flex;gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="eContact" class="form-control"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Phone</label><input type="text" name="phone" id="ePhone" class="form-control"></div>
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
function openEditSupplier(s) {
  document.getElementById('eId').value = s.id;
  document.getElementById('eName').value = s.company_name;
  document.getElementById('eContact').value = s.contact_person || '';
  document.getElementById('ePhone').value = s.phone || '';
  document.getElementById('eEmail').value = s.email || '';
  document.getElementById('eAddress').value = s.address || '';
  document.getElementById('eStatus').value = s.status;
  openModal('editModal');
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
