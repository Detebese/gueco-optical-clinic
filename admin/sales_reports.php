<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Sales Reports';
$breadcrumb = ['Admin', 'Sales Reports'];
$db = getDB();

// Date filter
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');
$groupBy    = $_GET['group'] ?? 'day';

// Summary totals
$summary = $db->prepare("
    SELECT COUNT(*) as total_tx, COALESCE(SUM(total),0) as total_sales, COALESCE(SUM(discount),0) as total_discount
    FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed'
");
$summary->execute([$filterFrom, $filterTo]); $summary = $summary->fetch();

// Chart data by day
$chartLabels = []; $chartData = [];
$d = new DateTime($filterFrom);
$end = new DateTime($filterTo);
while ($d <= $end) {
    $date = $d->format('Y-m-d');
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $stmt->execute([$date]); $chartData[] = round($stmt->fetch()['t'],2);
    $chartLabels[] = $d->format('M d');
    $d->modify('+1 day');
}

// Payment method breakdown
$payBreak = $db->prepare("SELECT payment_method, COUNT(*) as cnt, SUM(total) as total FROM sales WHERE DATE(created_at) BETWEEN ? AND ? AND status='completed' GROUP BY payment_method");
$payBreak->execute([$filterFrom,$filterTo]); $payBreak = $payBreak->fetchAll();

// Top selling products
$topProds = $db->prepare("
    SELECT p.name, SUM(si.quantity) as units_sold, SUM(si.total_price) as revenue
    FROM sale_items si JOIN products p ON p.id=si.product_id
    JOIN sales s ON s.id=si.sale_id
    WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'
    GROUP BY p.id ORDER BY units_sold DESC LIMIT 5
");
$topProds->execute([$filterFrom,$filterTo]); $topProds = $topProds->fetchAll();

// All sales paginated
$page = max(1,(int)($_GET['page']??1)); $perPage = 15;
$total = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$total->execute([$filterFrom,$filterTo]); $total = $total->fetch()['c'];
$pg = paginate($total, $perPage, $page);

$sales = $db->prepare("
    SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
    FROM sales s LEFT JOIN patients p ON p.id=s.patient_id JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT ? OFFSET ?
");
$sales->execute([$filterFrom,$filterTo,$perPage,$pg['offset']]); $sales = $sales->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Filter -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px;">From</label><input type="date" name="from" class="form-control" value="<?= $filterFrom ?>"></div>
      <div style="flex:1;min-width:140px;"><label class="form-label" style="margin-bottom:5px;">To</label><input type="date" name="to" class="form-control" value="<?= $filterTo ?>"></div>
      <div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-secondary ms-1">This Month</a>
        <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary ms-1">Today</a>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-4">
    <div class="stat-card" style="--stat-color:var(--clr-success)">
      <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
      <div class="stat-info"><div class="stat-value"><?= formatCurrency($summary['total_sales']) ?></div><div class="stat-label">Total Revenue</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card" style="--stat-color:var(--clr-primary)">
      <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
      <div class="stat-info"><div class="stat-value"><?= number_format($summary['total_tx']) ?></div><div class="stat-label">Total Transactions</div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="stat-card" style="--stat-color:var(--clr-warning)">
      <div class="stat-icon orange"><i class="fas fa-tags"></i></div>
      <div class="stat-info"><div class="stat-value"><?= formatCurrency($summary['total_discount']) ?></div><div class="stat-label">Total Discounts Given</div></div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-8">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-chart-area me-2" style="color:var(--clr-primary)"></i>Daily Sales — <?= formatDate($filterFrom) ?> to <?= formatDate($filterTo) ?></h6></div>
      <div class="card-body"><div class="chart-container" style="height:240px;"><canvas id="salesChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-4">
    <div class="card" style="height:100%;">
      <div class="card-header"><h6><i class="fas fa-credit-card me-2" style="color:var(--clr-secondary)"></i>Payment Methods</h6></div>
      <div class="card-body" style="display:flex;align-items:center;justify-content:center;">
        <div class="chart-container" style="height:220px;width:220px;"><canvas id="payChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<!-- Top Products + Sales Table -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-4">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-fire me-2" style="color:var(--clr-warning)"></i>Top Selling Products</h6></div>
      <div class="card-body">
        <?php if (empty($topProds)): ?>
        <div style="text-align:center;color:var(--text-muted);padding:20px;font-size:.82rem;">No data for this period</div>
        <?php else: ?>
        <?php foreach ($topProds as $i => $tp): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-light);">
          <div style="width:28px;height:28px;background:var(--clr-<?= $i===0?'warning':($i===1?'secondary':'primary') ?>);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0;"><?= $i+1 ?></div>
          <div style="flex:1;overflow:hidden;">
            <div style="font-weight:600;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($tp['name']) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted)"><?= $tp['units_sold'] ?> units sold</div>
          </div>
          <div style="font-weight:700;color:var(--clr-success);font-size:.85rem;flex-shrink:0"><?= formatCurrency($tp['revenue']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-8">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-table me-2" style="color:var(--clr-primary)"></i>All Transactions (<?= $total ?>)</h6>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Invoice</th><th>Patient</th><th>Cashier</th><th>Total</th><th>Discount</th><th>Method</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($sales)): ?>
            <tr><td colspan="8"><div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-receipt"></i></div><h6>No transactions</h6></div></td></tr>
            <?php else: ?>
            <?php foreach ($sales as $s): ?>
            <tr>
              <td><span style="font-family:monospace;font-size:.78rem;color:var(--clr-primary);font-weight:600"><?= sanitize($s['invoice_no']) ?></span></td>
              <td style="font-size:.82rem"><?= sanitize($s['patient_name'] ?? 'Walk-in') ?></td>
              <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($s['cashier_name']) ?></td>
              <td style="font-weight:700;color:var(--clr-success)"><?= formatCurrency($s['total']) ?></td>
              <td style="font-size:.8rem;color:var(--clr-warning)"><?= $s['discount']>0?formatCurrency($s['discount']):'—' ?></td>
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
          <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?>
          <a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($pg['has_next']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$payLabels = array_map(fn($p) => strtoupper($p['payment_method']), $payBreak);
$payData   = array_map(fn($p) => (float)$p['total'], $payBreak);
$extraScripts = '<script>
const sCtx = document.getElementById("salesChart");
if(sCtx){ new Chart(sCtx,{ type:"line", data:{ labels:'.json_encode($chartLabels).', datasets:[{ label:"Sales (₱)", data:'.json_encode($chartData).', borderColor:"#2563EB", backgroundColor:"rgba(37,99,235,.08)", borderWidth:2.5, fill:true, tension:0.4, pointBackgroundColor:"#2563EB", pointRadius:4 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{callback:v=>"₱"+v.toLocaleString(),font:{family:"Poppins",size:11}},grid:{color:"rgba(0,0,0,.05)"}}, x:{ticks:{font:{family:"Poppins",size:11}},grid:{display:false}} } } }); }
const pCtx = document.getElementById("payChart");
if(pCtx){ new Chart(pCtx,{ type:"doughnut", data:{ labels:'.json_encode($payLabels).', datasets:[{ data:'.json_encode($payData).', backgroundColor:["#2563EB","#059669","#7C3AED","#D97706"], borderWidth:0, hoverOffset:6 }] }, options:{ responsive:true, maintainAspectRatio:false, cutout:"68%", plugins:{legend:{position:"bottom",labels:{font:{family:"Poppins",size:11},padding:10,boxWidth:10}}} } }); }
</script>';
include __DIR__ . '/../includes/footer.php';
?>
