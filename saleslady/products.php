<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Products';
$breadcrumb = ['Saleslady', 'Products'];
$db = getDB();

$search    = sanitize($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$where = ["p.status='active'"]; $params = [];
if ($search)    { $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = 'p.category_id=?'; $params[] = $catFilter; }
$whereStr = implode(' AND ', $where);

$products = $db->prepare("SELECT p.*, c.name as cat_name FROM products p JOIN categories c ON c.id=p.category_id WHERE $whereStr ORDER BY c.name, p.name");
$products->execute($params); $products = $products->fetchAll();
$categories = $db->query("SELECT * FROM categories WHERE status='active' ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i class="fas fa-glasses me-2" style="color:var(--clr-primary)"></i>Products Catalog (<?= count($products) ?>)</h5>
  <form method="GET" style="display:flex;gap:8px;">
    <input type="text" name="search" class="form-control" placeholder="Search product..." value="<?= htmlspecialchars($search) ?>" style="width:200px;">
    <select name="cat" class="form-select" style="width:160px;">
      <option value="">All Categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
    <?php if ($search || $catFilter): ?><a href="products.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
  <?php if (empty($products)): ?>
  <div style="grid-column:1/-1"><div class="empty-state"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No products found</h6></div></div>
  <?php else: ?>
  <?php foreach ($products as $p): ?>
  <?php $isLow = $p['stock_quantity'] <= $p['low_stock_alert']; $isOut = $p['stock_quantity'] == 0; ?>
  <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;padding:18px;transition:all .2s;"
       onmouseover="this.style.boxShadow='var(--shadow-md)';this.style.borderColor='var(--clr-primary)'"
       onmouseout="this.style.boxShadow='none';this.style.borderColor='var(--border-color)'">
    <?php if($p['image']): ?>
      <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:40px;height:40px;object-fit:cover;border-radius:12px;margin-bottom:12px;">
    <?php else: ?>
      <div style="width:40px;height:40px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
        <i class="fas fa-glasses" style="color:#fff;font-size:.9rem;"></i>
      </div>
    <?php endif; ?>
    <div style="font-family:monospace; color:var(--text-primary); font-size:0.85rem; margin-bottom:2px; font-weight:600;"><?= sanitize($p['product_code'] ?: '') ?></div>
    <div style="font-weight:700;font-size:.88rem;margin-bottom:4px;line-height:1.3"><?= sanitize($p['name']) ?></div>
    <div style="margin-bottom:10px;"><span class="badge bg-secondary" style="font-size:.65rem"><?= sanitize($p['cat_name']) ?></span></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <span style="font-weight:800;font-size:1rem;color:var(--clr-primary)"><?= formatCurrency($p['price']) ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;">
      <div style="height:4px;flex:1;background:var(--bg-hover);border-radius:2px;overflow:hidden;">
        <?php $pct = min(100, ($p['stock_quantity'] / max(1,$p['low_stock_alert']*2)) * 100); ?>
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $isOut?'var(--clr-danger)':($isLow?'var(--clr-warning)':'var(--clr-success)') ?>;border-radius:2px;"></div>
      </div>
      <span style="font-size:.72rem;font-weight:700;color:<?= $isOut?'var(--clr-danger)':($isLow?'var(--clr-warning)':'var(--text-muted)') ?>;white-space:nowrap">
        <?= $isOut ? 'OUT' : ($isLow ? "LOW: {$p['stock_quantity']}" : "{$p['stock_quantity']} in stock") ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
