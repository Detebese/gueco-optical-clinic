<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Sales History';
$breadcrumb = ['Saleslady', 'Sales'];
$db = getDB();

$filterFrom = $_GET['from'] ?? date('Y-m-d');
$filterTo   = $_GET['to']   ?? date('Y-m-d');
$page       = max(1,(int)($_GET['page']??1)); $perPage = 15;

$total = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$total->execute([$filterFrom,$filterTo]); $total = $total->fetch()['c'];
$pg = paginate($total,$perPage,$page);

$todaySales = $db->prepare("SELECT COALESCE(SUM(total),0) as t, COUNT(*) as c FROM sales WHERE DATE(created_at)=? AND status='completed'");
$todaySales->execute([date('Y-m-d')]); $todaySales = $todaySales->fetch();

$sales = $db->prepare("
    SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
    FROM sales s LEFT JOIN patients p ON p.id=s.patient_id JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT ? OFFSET ?
");
$sales->execute([$filterFrom,$filterTo,$perPage,$pg['offset']]); $sales = $sales->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<!-- Summary -->
<div class="row" style="margin-bottom:20px;">
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-success)"><div class="stat-icon green"><i class="fas fa-peso-sign"></i></div><div class="stat-info"><div class="stat-value"><?= formatCurrency($todaySales['t']) ?></div><div class="stat-label">Today's Revenue</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-primary)"><div class="stat-icon blue"><i class="fas fa-receipt"></i></div><div class="stat-info"><div class="stat-value"><?= $todaySales['c'] ?></div><div class="stat-label">Transactions Today</div></div></div></div>
  <div class="col-4"><div class="stat-card" style="--stat-color:var(--clr-info)"><div class="stat-icon teal"><i class="fas fa-cash-register"></i></div><div class="stat-info"><div class="stat-value"><?= $total ?></div><div class="stat-label">In Selected Range</div></div></div></div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div style="flex:1;min-width:130px;"><label class="form-label" style="margin-bottom:5px">From</label><input type="date" name="from" class="form-control" value="<?= $filterFrom ?>"></div>
      <div style="flex:1;min-width:130px;"><label class="form-label" style="margin-bottom:5px">To</label><input type="date" name="to" class="form-control" value="<?= $filterTo ?>"></div>
      <div style="display:flex;gap:6px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">Today</a>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">This Month</a>
      </div>
    </form>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Invoice</th><th>Patient</th><th>Cashier</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Method</th><th>Date/Time</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (empty($sales)): ?>
        <tr><td colspan="9"><div class="empty-state"><div class="empty-icon"><i class="fas fa-receipt"></i></div><h6>No sales in this period</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($sales as $s): ?>
        <tr>
          <td><span style="font-family:monospace;font-size:.78rem;color:var(--clr-primary);font-weight:700"><?= sanitize($s['invoice_no']) ?></span></td>
          <td style="font-size:.82rem"><?= sanitize($s['patient_name']??'Walk-in') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($s['cashier_name']) ?></td>
          <td style="font-size:.83rem"><?= formatCurrency($s['subtotal']) ?></td>
          <td style="font-size:.8rem;color:var(--clr-warning)"><?= $s['discount']>0?formatCurrency($s['discount']):'—' ?></td>
          <td style="font-weight:800;color:var(--clr-success)"><?= formatCurrency($s['total']) ?></td>
          <td><span class="badge bg-info"><?= strtoupper($s['payment_method']) ?></span></td>
          <td style="font-size:.75rem;color:var(--text-muted)"><?= formatDateTime($s['created_at']) ?></td>
          <td><?= statusBadge($s['status']) ?></td>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
