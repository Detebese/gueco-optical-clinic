<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Inventory Reports';
$breadcrumb = ['Saleslady', 'Inventory Reports'];
$db = getDB();

$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');

// Totals
$totalIn  = $db->prepare("SELECT COALESCE(SUM(quantity),0) as t FROM inventory_logs WHERE type='stock_in'  AND DATE(created_at) BETWEEN ? AND ?");
$totalIn->execute([$filterFrom,$filterTo]); $totalIn = $totalIn->fetch()['t'];
$totalOut = $db->prepare("SELECT COALESCE(SUM(quantity),0) as t FROM inventory_logs WHERE type='stock_out' AND DATE(created_at) BETWEEN ? AND ?");
$totalOut->execute([$filterFrom,$filterTo]); $totalOut = $totalOut->fetch()['t'];

// Low stock products
$lowStock = $db->query("SELECT p.*, c.name as cat_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.stock_quantity <= p.low_stock_alert AND p.status='active' ORDER BY p.stock_quantity ASC")->fetchAll();

// Recent logs
$page = max(1,(int)($_GET['page']??1)); $perPage = 15;
$total = $db->prepare("SELECT COUNT(*) as c FROM inventory_logs WHERE DATE(created_at) BETWEEN ? AND ?");
$total->execute([$filterFrom,$filterTo]); $total = $total->fetch()['c'];
$pg = paginate($total,$perPage,$page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$logs = $db->prepare("
    SELECT il.*, p.name as product_name, u.full_name as user_name
    FROM inventory_logs il JOIN products p ON p.id=il.product_id JOIN users u ON u.id=il.user_id
    WHERE DATE(il.created_at) BETWEEN ? AND ?
    ORDER BY il.created_at DESC LIMIT $limit OFFSET $offset
");
$logs->execute([$filterFrom,$filterTo]); $logs = $logs->fetchAll() ?: [];

include __DIR__ . '/../includes/header.php';
?>

<!-- Filter -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px">From</label><input type="date" name="from" class="form-control" value="<?= $filterFrom ?>"></div>
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px">To</label><input type="date" name="to" class="form-control" value="<?= $filterTo ?>"></div>
      <div style="display:flex;gap:6px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">This Month</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-success)"><div class="stat-icon green"><i class="fas fa-arrow-up"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($totalIn) ?></div><div class="stat-label">Total Stock In</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-danger)"><div class="stat-icon red"><i class="fas fa-arrow-down"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($totalOut) ?></div><div class="stat-label">Total Stock Out</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-warning)"><div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?= count($lowStock) ?></div><div class="stat-label">Low/Out of Stock Items</div></div></div></div>
</div>

<div class="row" style="margin-bottom:24px;">
  <!-- Low Stock Alert -->
  <div class="col-4">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-exclamation-triangle me-2" style="color:var(--clr-warning)"></i>Stock Alerts</h6></div>
      <?php if (empty($lowStock)): ?>
      <div class="empty-state" style="padding:30px"><div class="empty-icon" style="color:var(--clr-success)"><i class="fas fa-check-circle"></i></div><h6 style="color:var(--clr-success)">All stocks OK!</h6></div>
      <?php else: ?>
      <div style="overflow-y:auto;max-height:400px;">
        <?php foreach ($lowStock as $p): ?>
        <div style="padding:12px 16px;border-bottom:1px solid var(--border-light);display:flex;align-items:center;gap:10px;">
          <div style="width:36px;height:36px;background:<?= $p['stock_quantity']==0?'rgba(220,38,38,.1)':'rgba(217,119,6,.1)' ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fas fa-<?= $p['stock_quantity']==0?'times-circle':'exclamation-triangle' ?>" style="color:<?= $p['stock_quantity']==0?'var(--clr-danger)':'var(--clr-warning)' ?>;font-size:.8rem;"></i>
          </div>
          <div style="flex:1;overflow:hidden;">
            <div style="font-weight:600;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($p['name']) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['cat_name']) ?></div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-weight:800;font-size:.95rem;color:<?= $p['stock_quantity']==0?'var(--clr-danger)':'var(--clr-warning)' ?>"><?= $p['stock_quantity'] ?></div>
            <div style="font-size:.65rem;color:var(--text-muted)">/ <?= $p['low_stock_alert'] ?> alert</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Movement Log -->
  <div class="col-8">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-history me-2" style="color:var(--clr-primary)"></i>Movement Log (<?= $total ?>)</h6></div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Type</th><th>Product</th><th>Qty</th><th>Before → After</th><th>Reason</th><th>By</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($logs)): ?>
            <tr><td colspan="7"><div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-history"></i></div><h6>No logs in this period</h6></div></td></tr>
            <?php else: ?>
            <?php foreach ($logs as $l): ?>
            <tr>
              <td>
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:.78rem;font-weight:700;color:<?= $l['type']==='stock_in'?'var(--clr-success)':'var(--clr-danger)' ?>">
                  <i class="fas fa-<?= $l['type']==='stock_in'?'arrow-up':'arrow-down' ?>"></i>
                  <?= $l['type']==='stock_in'?'IN':'OUT' ?>
                </span>
              </td>
              <td style="font-weight:600;font-size:.83rem"><?= sanitize($l['product_name']) ?></td>
              <td style="font-weight:800;font-size:.9rem;color:<?= $l['type']==='stock_in'?'var(--clr-success)':'var(--clr-danger)' ?>">
                <?= $l['type']==='stock_in'?'+':'-' ?><?= $l['quantity'] ?>
              </td>
              <td style="font-size:.78rem;color:var(--text-muted)"><?= $l['previous_stock'] ?> → <strong style="color:var(--text-primary)"><?= $l['new_stock'] ?></strong></td>
              <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($l['reason']??'—') ?></td>
              <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($l['user_name']) ?></td>
              <td style="font-size:.72rem;color:var(--text-muted)"><?= formatDateTime($l['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pg['total_pages'] > 1): ?>
      <div style="padding:14px 20px;border-top:1px solid var(--border-light);">
        <div class="pagination">
          <?php if ($pg['has_prev']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
          <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
          <?php if ($pg['has_next']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
