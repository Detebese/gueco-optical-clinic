<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Inventory Logs';
$breadcrumb = ['Admin', 'Inventory', 'Logs'];
$db = getDB();

// Filters
$search = sanitize($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ['1=1']; $params = [];

if ($search) { 
    $where[] = '(p.name LIKE ? OR l.reason LIKE ?)'; 
    $params[] = "%$search%"; 
    $params[] = "%$search%"; 
}
if ($typeFilter) { 
    $where[] = 'l.type = ?'; 
    $params[] = $typeFilter; 
}

$whereStr = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) as c 
    FROM inventory_logs l 
    JOIN products p ON p.id = l.product_id 
    WHERE $whereStr
");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];
$pagination = paginate($total, $perPage, $page);

// Fetch logs
$params[] = $perPage; $params[] = $pagination['offset'];
$logsStmt = $db->prepare("
    SELECT l.*, p.name as product_name, u.full_name as user_name
    FROM inventory_logs l
    JOIN products p ON p.id = l.product_id
    LEFT JOIN users u ON u.id = l.user_id
    WHERE $whereStr
    ORDER BY l.created_at DESC
    LIMIT ? OFFSET ?
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/inventory_logs.css">';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-header">
  <h5><i class="fas fa-history me-2" style="color:var(--clr-info)"></i>Inventory Stock History</h5>
  <div>
    <a href="inventory.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
  </div>
</div>

<!-- Filter bar -->
<div class="card" style="background:var(--bg-card); border-color:var(--border-color); margin-bottom:1.5rem;">
  <div class="card-body" style="padding:1rem;">
    <form method="GET" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
      <div style="flex:1; min-width:200px;">
        <label class="form-label" style="color:var(--text-muted); font-size:0.85rem;">Search Product / Reason</label>
        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div style="flex:1; min-width:150px;">
        <label class="form-label" style="color:var(--text-muted); font-size:0.85rem;">Type</label>
        <select name="type" class="form-select">
          <option value="">All Types</option>
          <option value="stock_in" <?= $typeFilter==='stock_in'?'selected':'' ?>>Stock In</option>
          <option value="stock_out" <?= $typeFilter==='stock_out'?'selected':'' ?>>Stock Out</option>
        </select>
      </div>
      <div>
        <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i> Search</button>
        <a href="inventory_logs.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
      </div>
    </form>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Product</th>
          <th>Type</th>
          <th>Quantity</th>
          <th>Prev Stock</th>
          <th>New Stock</th>
          <th>Reason / Note</th>
          <th>User</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr>
          <td colspan="8">
            <div class="empty-state" style="text-align:center; padding:3rem 1rem; color:var(--text-muted);">
              <div class="empty-icon" style="font-size:2.5rem; margin-bottom:1rem; opacity:0.5;"><i class="fas fa-clipboard-list"></i></div>
              <h6>No stock history found</h6>
            </div>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td style="white-space:nowrap; color:var(--text-muted); font-size:0.9rem;">
            <?= date('M d, Y', strtotime($l['created_at'])) ?><br>
            <small><?= date('h:i A', strtotime($l['created_at'])) ?></small>
          </td>
          <td><strong style="color:var(--text-primary)"><?= sanitize($l['product_name']) ?></strong></td>
          <td>
            <?php if ($l['type'] === 'stock_in'): ?>
              <span class="badge bg-success"><i class="fas fa-arrow-down me-1"></i>Stock In</span>
            <?php elseif ($l['type'] === 'stock_out'): ?>
              <span class="badge bg-warning text-dark"><i class="fas fa-arrow-up me-1"></i>Stock Out</span>
            <?php else: ?>
              <span class="badge bg-secondary"><?= sanitize($l['type']) ?></span>
            <?php endif; ?>
          </td>
          <td style="font-weight:bold; color: <?= $l['type']==='stock_in'?'var(--clr-success)':'var(--clr-warning)' ?>">
            <?= $l['type']==='stock_in'?'+':'-' ?><?= $l['quantity'] ?>
          </td>
          <td style="color:var(--text-muted)"><?= $l['previous_stock'] ?></td>
          <td style="font-weight:bold; color:var(--text-primary)"><?= $l['new_stock'] ?></td>
          <td style="color:var(--text-muted); font-size:0.9rem; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= sanitize($l['reason']) ?>">
            <?= sanitize($l['reason']) ?: '?' ?>
          </td>
          <td><?= sanitize($l['user_name'] ?? 'System') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($total > $perPage): ?>
<div class="d-flex justify-content-between align-items-center mt-3">
  <div class="text-muted" style="font-size:0.9rem;">
    Showing <?= $pagination['offset'] + 1 ?> to <?= min($total, $pagination['offset'] + $perPage) ?> of <?= $total ?> entries
  </div>
  <nav>
    <ul class="pagination pagination-sm mb-0">
      <?php for($i = 1; $i <= $pagination['total_pages']; $i++): ?>
      <li class="page-item <?= $i == $page ? 'active' : '' ?>">
        <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($typeFilter) ?>"><?= $i ?></a>
      </li>
      <?php endfor; ?>
    </ul>
  </nav>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
