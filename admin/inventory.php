<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Inventory Management';
$breadcrumb = ['Admin', 'Inventory'];
$db = getDB();
$msg = ''; $msgType = 'success';
$reopenData = null;

// Add/Edit product
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name     = sanitize(trim($_POST['name'] ?? ''));
        $catId    = (int)($_POST['category_id'] ?? 0);
        $suppId   = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $price    = (float)($_POST['price'] ?? 0);
        $stock    = (int)($_POST['stock_quantity'] ?? 0);
        $alert    = (int)($_POST['low_stock_alert'] ?? 5);
        $desc     = sanitize(trim($_POST['description'] ?? ''));
        $stat     = $_POST['status'] ?? 'active';

        if (!$name || !$catId || $price < 0) { $msg = 'Name, category, and a valid price are required.'; $msgType = 'danger'; }
        else {
            try {
                // Image Upload Logic
                $imagePath = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $imagePath = uniqid('prod_') . '.' . $ext;
                    move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../assets/images/products/' . $imagePath);
                }

                if ($action === 'add') {
                    $db->prepare("INSERT INTO products (name,category_id,supplier_id,price,stock_quantity,low_stock_alert,description,status,image) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$name,$catId,$suppId,$price,$stock,$alert,$desc,$stat,$imagePath]);
                    // Log inventory
                    $newId = $db->lastInsertId();
                    if ($stock > 0) {
                        $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
                           ->execute([$newId,'stock_in',$stock,0,$stock,'Initial stock',$_SESSION['user_id']]);
                    }
                    $msg = "Product \"$name\" added.";
                } else {
                    $id = (int)$_POST['id'];
                    $stmt = $db->prepare("SELECT name, category_id, supplier_id, price, low_stock_alert, description, status FROM products WHERE id=?");
                    $stmt->execute([$id]);
                    $old = $stmt->fetch();
                    
                    if ($old && $old['name'] === $name && (int)$old['category_id'] === $catId && (int)$old['supplier_id'] === $suppId && (float)$old['price'] === $price && (int)$old['low_stock_alert'] === $alert && $old['description'] === $desc && $old['status'] === $stat && !$imagePath) {
                        $msg = "No changes were made. Product is already up to date!";
                        $msgType = "info";
                        $reopenData = ['id' => $id, 'name' => $name, 'category_id' => $catId, 'supplier_id' => $suppId, 'price' => $price, 'low_stock_alert' => $alert, 'description' => $desc, 'status' => $stat];
                    } else {
                        if ($imagePath) {
                            $db->prepare("UPDATE products SET name=?,category_id=?,supplier_id=?,price=?,low_stock_alert=?,description=?,status=?,image=? WHERE id=?")
                               ->execute([$name,$catId,$suppId,$price,$alert,$desc,$stat,$imagePath,$id]);
                        } else {
                            $db->prepare("UPDATE products SET name=?,category_id=?,supplier_id=?,price=?,low_stock_alert=?,description=?,status=? WHERE id=?")
                               ->execute([$name,$catId,$suppId,$price,$alert,$desc,$stat,$id]);
                        }
                        $msg = "Product updated successfully!";
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "Error: A product with this name already exists.";
                    $msgType = "danger";
                } else {
                    $msg = "An error occurred: " . $e->getMessage();
                    $msgType = "danger";
                }
            }
        }
    } elseif ($action === 'stock_in' || $action === 'stock_out') {
        $id  = (int)$_POST['id'];
        $qty = (int)$_POST['qty'];
        $reason = sanitize(trim($_POST['reason'] ?? ''));
        if ($qty <= 0) { $msg = 'Quantity must be greater than 0.'; $msgType = 'danger'; }
        else {
            $prod = $db->prepare("SELECT stock_quantity FROM products WHERE id=?"); $prod->execute([$id]); $prod = $prod->fetch();
            $prevStock = $prod['stock_quantity'];
            $newStock = $action === 'stock_in' ? $prevStock + $qty : max(0, $prevStock - $qty);
            $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$newStock, $id]);
            $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id,$action,$qty,$prevStock,$newStock,$reason,$_SESSION['user_id']]);
            $msg = "Stock " . ($action==='stock_in'?'added':'deducted') . " successfully. New stock: $newStock";
        }
    }
}

// Filters
$search = sanitize($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$stockFilter = $_GET['stock'] ?? '';
$where = ['1=1']; $params = [];
if ($search) { $where[] = 'p.name LIKE ?'; $params[] = "%$search%"; }
if ($catFilter) { $where[] = 'p.category_id=?'; $params[] = $catFilter; }
if ($stockFilter === 'low') { $where[] = 'p.stock_quantity <= p.low_stock_alert'; }
if ($stockFilter === 'out') { $where[] = 'p.stock_quantity = 0'; }
$whereStr = implode(' AND ', $where);

$products = $db->prepare("
    SELECT p.*, c.name as cat_name, s.company_name as supplier_name
    FROM products p
    JOIN categories c ON c.id=p.category_id
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE $whereStr ORDER BY c.name, p.name ASC
");
$products->execute($params); $products = $products->fetchAll();

$categories = $db->query("SELECT * FROM categories WHERE status='active' ORDER BY name")->fetchAll();
$suppliers  = $db->query("SELECT * FROM suppliers WHERE status='active' ORDER BY company_name")->fetchAll();

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/inventory.css">';
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
  <h5><i  class="fas fa-boxes me-2 inv-b6b6a8"></i>Inventory — <?= count($products) ?> Products</h5>
  <div class="inv-96b971">
    <button id="viewToggleBtn" class="btn btn-secondary btn-sm" onclick="toggleView()"><i class="fas fa-th-large"></i> Grid View</button>
    <a href="inventory_logs.php" class="btn btn-info btn-sm text-white"><i class="fas fa-history"></i> Stock History</a>
    <a href="?stock=low" class="btn btn-warning btn-sm"><i class="fas fa-exclamation-triangle"></i> Low Stock</a>
    <a href="?stock=out" class="btn btn-danger btn-sm"><i class="fas fa-times-circle"></i> Out of Stock</a>
    <button class="btn btn-primary" onclick="openModal('addProductModal')"><i class="fas fa-plus"></i> Add Product</button>
  </div>
</div>

<!-- Filter bar -->
<div  class="card inv-684111">
  <div  class="card-body inv-140fb6">
    <form method="GET" class="inv-7cdce4">
      <div class="inv-ce6b9e"><label  class="form-label inv-7c8fee">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Product name..." value="<?= htmlspecialchars($search) ?>"></div>
      <div class="inv-398dad"><label  class="form-label inv-7c8fee">Category</label>
        <select name="cat" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i> Search</button></div>
      <div><a href="inventory.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a></div>
    </form>
  </div>
</div>

<div class="table-wrapper" id="tableView">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Product</th><th>Category</th><th>Supplier</th><th>Price</th><th>Stock</th><th>Alert</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($products)): ?>
        <tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><i class="fas fa-boxes"></i></div><h6>No products found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($products as $i => $p): ?>
        <?php $isLow = $p['stock_quantity'] <= $p['low_stock_alert']; $isOut = $p['stock_quantity'] == 0; ?>
        <tr>
          <td class="inv-67fd48"><?= $i+1 ?></td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <?php if($p['image']): ?>
                <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:40px; height:40px; object-fit:cover; border-radius:5px;">
              <?php else: ?>
                <div style="width:40px; height:40px; background:var(--bg-card); border-radius:5px; display:flex; align-items:center; justify-content:center; color:var(--text-muted);"><i class="fas fa-image"></i></div>
              <?php endif; ?>
              <div>
                <div class="inv-bac3c9"><?= sanitize($p['name']) ?></div>
                <div class="inv-26a4f5"><?= sanitize($p['description'] ?: '—') ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge bg-secondary"><?= sanitize($p['cat_name']) ?></span></td>
          <td class="inv-67fd48"><?= sanitize($p['supplier_name'] ?? '—') ?></td>
          <td class="inv-c0f652"><?= formatCurrency($p['price']) ?></td>
          <td>
            <span style="font-weight:700;font-size:.95rem;color:<?= $isOut?'var(--clr-danger)':($isLow?'var(--clr-warning)':'var(--text-primary)') ?>">
              <?= $p['stock_quantity'] ?>
            </span>
            <?php if ($isOut): ?><span  class="badge bg-danger inv-c1ae5c">OUT</span>
            <?php elseif ($isLow): ?><span  class="badge bg-warning inv-c1ae5c">LOW</span><?php endif; ?>
          </td>
          <td class="inv-00a7ed"><?= $p['low_stock_alert'] ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td>
            <div class="inv-152c49">
              <button class="btn btn-sm btn-success btn-icon" title="Stock In" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_in')"><i class="fas fa-plus"></i></button>
              <button class="btn btn-sm btn-warning btn-icon" title="Stock Out" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_out')"><i class="fas fa-minus"></i></button>
              <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit" onclick="openEditProduct(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="fas fa-edit"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="gridView" style="display:none; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:20px; margin-bottom:20px;">
  <?php foreach ($products as $p): ?>
    <?php 
    $isLow = $p['stock_quantity'] <= $p['low_stock_alert'];
    $isOut = $p['stock_quantity'] == 0; 
    ?>
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:15px; position:relative; display:flex; flex-direction:column;">
      <div style="text-align:center; margin-bottom:12px; flex-grow:0;">
        <?php if($p['image']): ?>
          <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:100%; height:160px; object-fit:cover; border-radius:8px;">
        <?php else: ?>
          <div style="width:100%; height:160px; background:var(--bg-hover); border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:2rem;">
            <i class="fas fa-image"></i>
          </div>
        <?php endif; ?>
      </div>
      
      <div style="flex-grow:1;">
        <div style="font-weight:700; font-size:1.05rem; line-height:1.2; margin-bottom:5px; color:var(--text-primary);"><?= sanitize($p['name']) ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:12px;"><?= sanitize($p['cat_name']) ?></div>
      </div>
      
      <div style="display:flex; justify-content:space-between; align-items:end; margin-bottom:15px; flex-grow:0;">
        <div style="font-weight:800; font-size:1.1rem; color:var(--clr-primary);">₱<?= number_format($p['price'], 2) ?></div>
        
        <div style="text-align:right;">
          <?php if($isOut): ?>
            <span class="badge bg-danger">Out of Stock</span>
          <?php elseif($isLow): ?>
            <span class="badge bg-warning text-dark">Low: <?= $p['stock_quantity'] ?></span>
          <?php else: ?>
            <span class="badge bg-success"><?= $p['stock_quantity'] ?> in stock</span>
          <?php endif; ?>
        </div>
      </div>
      
      <div style="display:flex; gap:5px; flex-grow:0;">
        <button class="btn btn-sm btn-outline-success" style="flex:1;" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>', 'stock_in')" title="Stock In"><i class="fas fa-plus"></i></button>
        <button class="btn btn-sm btn-outline-warning" style="flex:1;" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>', 'stock_out')" title="Stock Out"><i class="fas fa-minus"></i></button>
        <button class="btn btn-sm btn-outline-primary" style="flex:1;" onclick='openEditProduct(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>)' title="Edit"><i class="fas fa-edit"></i></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Add Product Modal -->
<div class="modal-overlay" id="addProductModal">
  <div  class="modal-box inv-c9726f">
    <div class="modal-header"><h5><i class="fas fa-plus me-2"></i>Add New Product</h5><button class="modal-close" onclick="closeModal('addProductModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group"><label class="form-label">Product Name *</label><input type="text" name="name" class="form-control" required></div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Category *</label>
            <select name="category_id" class="form-select" required><option value="">Select</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select"><option value="">None</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['company_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Price (₱) *</label><input type="number" name="price" class="form-control" step="0.01" min="0" required></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Initial Stock</label><input type="number" name="stock_quantity" class="form-control" value="0" min="0"></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" class="form-control" value="5" min="1"></div>
        </div>
        <div class="form-group"><label class="form-label">Product Image</label><input type="file" name="image" class="form-control" accept="image/*"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Product</button></div>
    </form>
  </div>
</div>

<!-- Edit Product Modal -->
<div class="modal-overlay" id="editProductModal">
  <div  class="modal-box inv-c9726f">
    <div class="modal-header"><h5><i class="fas fa-edit me-2"></i>Edit Product</h5><button class="modal-close" onclick="closeModal('editProductModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return confirmEdit(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="epId">
        <div class="form-group"><label class="form-label">Product Name *</label><input type="text" name="name" id="epName" class="form-control" required></div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Category *</label>
            <select name="category_id" id="epCat" class="form-select" required><option value="">Select</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Supplier</label>
            <select name="supplier_id" id="epSupp" class="form-select"><option value="">None</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['company_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Price (₱) *</label><input type="number" name="price" id="epPrice" class="form-control" step="0.01" min="0" required></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" id="epAlert" class="form-control" min="1"></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Status</label>
            <select name="status" id="epStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
        <div class="form-group"><label class="form-label">Product Image (Leave empty to keep current)</label><input type="file" name="image" id="epImage" class="form-control" accept="image/*"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="epDesc" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editProductModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div>
    </form>
  </div>
</div>

<!-- Stock In/Out Modal -->
<div class="modal-overlay" id="stockModal">
  <div  class="modal-box inv-a833a4">
    <div class="modal-header"><h5 id="stockModalTitle">Stock In</h5><button class="modal-close" onclick="closeModal('stockModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="id" id="stockProdId">
        <input type="hidden" name="action" id="stockAction">
        <p class="inv-8c7aac">Product: <strong id="stockProdName"></strong></p>
        <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="qty" class="form-control" min="1" required></div>
        <div class="form-group"><label class="form-label">Reason / Note</label><input type="text" name="reason" class="form-control" placeholder="e.g. Supplier delivery, Sold..."></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">Cancel</button><button type="submit" class="btn btn-primary" id="stockSubmitBtn">Confirm</button></div>
    </form>
  </div>
</div>

<script>
function openStockModal(id, name, action) {
  document.getElementById('stockProdId').value = id;
  document.getElementById('stockAction').value = action;
  document.getElementById('stockProdName').textContent = name;
  const isIn = action === 'stock_in';
  document.getElementById('stockModalTitle').innerHTML = `<i class="fas fa-${isIn?'plus':'minus'} me-2" style="color:var(--clr-${isIn?'success':'warning'})"></i>${isIn?'Stock In':'Stock Out'}`;
  document.getElementById('stockSubmitBtn').className = `btn btn-${isIn?'success':'warning'}`;
  document.getElementById('stockSubmitBtn').innerHTML = `<i class="fas fa-${isIn?'plus':'minus'}"></i> ${isIn?'Add Stock':'Deduct Stock'}`;
  openModal('stockModal');
}
let currentEditProduct = null;

function confirmEdit(e, form) {
  e.preventDefault();

  // Check if anything actually changed
  const p = currentEditProduct;
  if (p) {
      const name = document.getElementById('epName').value;
      const cat = document.getElementById('epCat').value;
      const supp = document.getElementById('epSupp').value || null;
      const price = parseFloat(document.getElementById('epPrice').value);
      const alert = parseInt(document.getElementById('epAlert').value);
      const desc = document.getElementById('epDesc').value || null;
      const stat = document.getElementById('epStatus').value;
      const hasImage = document.getElementById('epImage').files.length > 0;

      const oldSupp = p.supplier_id ? String(p.supplier_id) : null;
      const oldDesc = p.description ? p.description : null;

      if (
          !hasImage &&
          name === p.name &&
          cat === String(p.category_id) &&
          supp === oldSupp &&
          price === parseFloat(p.price) &&
          alert === parseInt(p.low_stock_alert) &&
          desc === oldDesc &&
          stat === p.status
      ) {
          Swal.fire({
              title: 'Notice',
              text: 'No changes were made. Product is already up to date!',
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
      text: 'Are you sure you want to update this product?',
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

function openEditProduct(p) {
  currentEditProduct = p;
  document.getElementById('epId').value = p.id;
  document.getElementById('epName').value = p.name;
  document.getElementById('epCat').value = p.category_id;
  document.getElementById('epSupp').value = p.supplier_id || '';
  document.getElementById('epPrice').value = p.price;
  document.getElementById('epAlert').value = p.low_stock_alert;
  document.getElementById('epDesc').value = p.description || '';
  document.getElementById('epStatus').value = p.status;
  openModal('editProductModal');
}

<?php if ($reopenData): ?>
// Re-open the modal automatically if there were no changes
document.addEventListener("DOMContentLoaded", function() {
    openEditProduct(<?= json_encode($reopenData) ?>);
});
<?php endif; ?>

function toggleView() {
    const isGrid = document.getElementById('gridView').style.display !== 'none';
    if (isGrid) {
        document.getElementById('gridView').style.display = 'none';
        document.getElementById('tableView').style.display = 'block';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-th-large"></i> Grid View';
        localStorage.setItem('inventoryViewPref', 'list');
    } else {
        document.getElementById('gridView').style.display = 'grid';
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-list"></i> List View';
        localStorage.setItem('inventoryViewPref', 'grid');
    }
}

document.addEventListener("DOMContentLoaded", function() {
    if (localStorage.getItem('inventoryViewPref') === 'grid') {
        // execute toggle to switch to grid initially without toggling the preference
        document.getElementById('gridView').style.display = 'grid';
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-list"></i> List View';
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

