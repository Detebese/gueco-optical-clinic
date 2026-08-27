<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Category Management';
$breadcrumb = ['Admin', 'Categories'];
$db = getDB();
$msg = ''; $msgType = 'success';

// Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name = sanitize(trim($_POST['name'] ?? ''));
    $desc = sanitize(trim($_POST['description'] ?? ''));
    if ($name) {
        $db->prepare("INSERT INTO categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
        $msg = "Category \"$name\" added successfully.";
    } else { $msg = 'Category name is required.'; $msgType = 'danger'; }
}
// Edit
$reopenData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id   = (int)$_POST['id'];
    $name = sanitize(trim($_POST['name'] ?? ''));
    $desc = sanitize(trim($_POST['description'] ?? ''));
    $stat = $_POST['status'] ?? 'active';
    if ($name && $id) {
        $stmt = $db->prepare("SELECT name, description, status FROM categories WHERE id=?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();

        if ($old && $old['name'] === $name && $old['description'] === $desc && $old['status'] === $stat) {
            $msg = "No changes were made. The category is already " . strtoupper($stat) . "!";
            $msgType = "info";
            $reopenData = ['id' => $id, 'name' => $name, 'desc' => $desc, 'stat' => $stat];
        } else {
            $db->prepare("UPDATE categories SET name=?, description=?, status=? WHERE id=?")->execute([$name, $desc, $stat, $id]);
            $msg = "Category successfully updated. Status is now " . strtoupper($stat) . "!";
            $msgType = "success";
        }
    }
}
// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)$_POST['id'];
    // Check if category has products
    $cnt = $db->prepare("SELECT COUNT(*) as c FROM products WHERE category_id=? AND status='active'");
    $cnt->execute([$id]); $cnt = $cnt->fetch()['c'];
    if ($cnt > 0) {
        $msg = "Cannot delete: $cnt active product(s) are using this category."; $msgType = 'danger';
    } else {
        $db->prepare("UPDATE categories SET status='inactive' WHERE id=?")->execute([$id]);
        $msg = 'Category deactivated.';
    }
}

$categories = $db->query("
    SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active') as product_count
    FROM categories c ORDER BY c.name ASC
")->fetchAll();

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/categories.css">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : "Notice" ?>',
        text: '<?= addslashes($msg) ?>',
        icon: '<?= $msgType === "success" ? "success" : "error" ?>',
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
  <h5><i class="fas fa-th-large me-2" class="cat-header-icon"></i>Product Categories</h5>
  <button class="btn btn-primary" onclick="openModal('addModal')">
    <i class="fas fa-plus"></i> Add Category
  </button>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>#</th><th>Category Name</th><th>Description</th><th>Products</th><th>Status</th><th>Created</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
        <tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><i class="fas fa-th-large"></i></div><h6>No categories yet</h6><p>Add your first product category</p></div></td></tr>
        <?php else: ?>
        <?php foreach ($categories as $i => $cat): ?>
        <tr>
          <td class="cat-table-index"><?= $i+1 ?></td>
          <td><div class="cat-table-name"><?= sanitize($cat['name']) ?></div></td>
          <td class="cat-table-desc"><?= sanitize($cat['description'] ?: '—') ?></td>
          <td><span class="badge bg-info"><?= $cat['product_count'] ?> products</span></td>
          <td><?= statusBadge($cat['status']) ?></td>
          <td class="cat-table-created"><?= formatDate($cat['created_at']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit"
              onclick="openEdit(<?= $cat['id'] ?>, '<?= addslashes($cat['name']) ?>', '<?= addslashes($cat['description']) ?>', '<?= $cat['status'] ?>')">
              <i class="fas fa-edit"></i>
            </button>
            <?php if ($cat['product_count'] == 0): ?>
            <form method="POST" class="cat-delete-form">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $cat['id'] ?>">
              <button class="btn btn-sm btn-danger btn-icon" title="Deactivate" data-confirm="Deactivate this category?"><i class="fas fa-ban"></i></button>
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
    <div class="modal-header">
      <h5><i class="fas fa-plus me-2" class="cat-header-icon"></i>Add Category</h5>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
          <label class="form-label">Category Name <span class="cat-required-star">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Frames" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Brief description..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Category</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header">
      <h5><i class="fas fa-edit me-2" class="cat-header-icon"></i>Edit Category</h5>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editId">
        <div class="form-group">
          <label class="form-label">Category Name <span class="cat-required-star">*</span></label>
          <input type="text" name="name" id="editName" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" id="editDesc" class="form-control" rows="3"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" id="editStatus" class="form-select">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(id, name, desc, status) {
  document.getElementById('editId').value = id;
  document.getElementById('editName').value = name;
  document.getElementById('editDesc').value = desc;
  document.getElementById('editStatus').value = status;
  openModal('editModal');
}

<?php if ($reopenData): ?>
// Re-open the modal automatically if there were no changes
document.addEventListener("DOMContentLoaded", function() {
    openEdit(
        <?= $reopenData['id'] ?>, 
        '<?= addslashes($reopenData['name']) ?>', 
        '<?= addslashes($reopenData['desc']) ?>', 
        '<?= $reopenData['stat'] ?>'
    );
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

