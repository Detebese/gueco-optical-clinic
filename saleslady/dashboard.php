<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Saleslady'];
$activeNav  = 'dashboard';

$db    = getDB();
$today = date('Y-m-d');

// Stats
$todayAppts    = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show')"); $todayAppts->execute([$today]); $todayAppts = $todayAppts->fetch()['c'];
$todaySales    = $db->prepare("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(created_at)=? AND status='completed'"); $todaySales->execute([$today]); $todaySales = $todaySales->fetch()['t'];
$todayTxCount  = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at)=? AND status='completed'"); $todayTxCount->execute([$today]); $todayTxCount = $todayTxCount->fetch()['c'];
$lowStock      = $db->query("SELECT COUNT(*) as c FROM products WHERE stock_quantity<=low_stock_alert AND status='active'")->fetch()['c'];

// Today's appointment list
$appts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.appointment_date=?
    ORDER BY a.appointment_time ASC LIMIT 10
");
$appts->execute([$today]);
$appts = $appts->fetchAll();

// Recent sales I processed
$recentSales = $db->prepare("
    SELECT s.*, p.full_name as patient_name
    FROM sales s
    LEFT JOIN patients p ON p.id=s.patient_id
    JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at)=?
    ORDER BY s.created_at DESC LIMIT 6
");
$recentSales->execute([$today]);
$recentSales = $recentSales->fetchAll();

// Hourly sales data for today
$hourlyLabels = []; $hourlyData = [];
for ($h = 9; $h <= 17; $h++) {
    $hourlyLabels[] = date('g A', mktime($h,0,0));
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(created_at)=? AND HOUR(created_at)=? AND status='completed'");
    $stmt->execute([$today, $h]);
    $hourlyData[] = round($stmt->fetch()['t'], 2);
}

// Low stock items
$lowItems = $db->query("
    SELECT p.name, p.stock_quantity, p.low_stock_alert, c.name as cat
    FROM products p JOIN categories c ON c.id=p.category_id
    WHERE p.stock_quantity<=p.low_stock_alert AND p.status='active'
    ORDER BY p.stock_quantity ASC LIMIT 5
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Quick Action Buttons -->
<div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;">
  <a href="../saleslady/pos.php" class="btn btn-primary">
    <i class="fas fa-cash-register"></i> New Sale / POS
  </a>
  <a href="../saleslady/appointments.php" class="btn btn-outline-primary">
    <i class="fas fa-calendar-check"></i> View Appointments
  </a>
  <a href="../saleslady/inventory.php" class="btn btn-outline-primary">
    <i class="fas fa-warehouse"></i> Stock Management
  </a>
  <a href="../saleslady/patients.php" class="btn btn-outline-primary">
    <i class="fas fa-users"></i> Patient Info
  </a>
</div>

<!-- Stats -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-success)">
      <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= formatCurrency($todaySales) ?></div>
        <div class="stat-label">Today's Revenue</div>
        <div class="stat-change up"><i class="fas fa-receipt"></i> <?= $todayTxCount ?> transactions</div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-primary)">
      <div class="stat-icon blue"><i class="fas fa-receipt"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($todayTxCount) ?></div>
        <div class="stat-label">Transactions Today</div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-info)">
      <div class="stat-icon teal"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($todayAppts) ?></div>
        <div class="stat-label">Appointments Today</div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:<?= $lowStock > 0 ? 'var(--clr-danger)' : 'var(--clr-success)' ?>">
      <div class="stat-icon <?= $lowStock > 0 ? 'red' : 'green' ?>"><i class="fas fa-boxes"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($lowStock) ?></div>
        <div class="stat-label">Low Stock Alerts</div>
        <div class="stat-change <?= $lowStock > 0 ? 'down' : 'up' ?>">
          <?= $lowStock > 0 ? '<i class="fas fa-exclamation-triangle"></i> Needs attention' : '<i class="fas fa-check"></i> All good' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts + Appointments -->
<div class="row" style="margin-bottom:24px;">
  <!-- Hourly Sales Chart -->
  <div class="col-8">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-chart-area me-2" style="color:var(--clr-success)"></i>Today's Sales by Hour</h6>
        <span style="font-size:.75rem;color:var(--text-muted)"><?= date('F d, Y') ?></span>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:220px;">
          <canvas id="hourlyChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Today's Appointment Mini List -->
  <div class="col-4">
    <div class="card" style="height:100%;">
      <div class="card-header">
        <h6><i class="fas fa-calendar-day me-2" style="color:var(--clr-info)"></i>Today's Queue</h6>
      </div>
      <div style="overflow-y:auto;max-height:260px;padding:12px;">
        <?php if (empty($appts)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:.82rem;">
          <i class="fas fa-calendar" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.4"></i>
          No appointments today
        </div>
        <?php else: ?>
        <?php foreach ($appts as $appt): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:10px;margin-bottom:6px;background:var(--bg-hover);">
          <div>
            <div style="font-weight:600;font-size:.82rem"><?= sanitize($appt['patient_name']) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)"><?= formatTime($appt['appointment_time']) ?> &mdash; <?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></div>
          </div>
          <?= statusBadge($appt['status']) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Today's Sales + Low Stock -->
<div class="row">
  <!-- Today's transactions -->
  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-receipt me-2" style="color:var(--clr-success)"></i>Today's Transactions</h6>
        <a href="../saleslady/sales.php" class="btn btn-sm btn-outline-primary">All Sales</a>
      </div>
      <?php if (empty($recentSales)): ?>
      <div class="empty-state" style="padding:40px">
        <div class="empty-icon"><i class="fas fa-receipt"></i></div>
        <h6>No sales yet today</h6>
        <p>Start by processing a sale at the POS</p>
        <a href="../saleslady/pos.php" class="btn btn-primary btn-sm mt-2"><i class="fas fa-plus"></i> New Sale</a>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Invoice</th><th>Patient</th><th>Total</th><th>Method</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentSales as $s): ?>
          <tr>
            <td><span style="font-family:monospace;font-size:.78rem;color:var(--clr-primary);font-weight:600"><?= sanitize($s['invoice_no']) ?></span></td>
            <td style="font-size:.83rem"><?= sanitize($s['patient_name'] ?? 'Walk-in') ?></td>
            <td style="font-weight:700;color:var(--clr-success)"><?= formatCurrency($s['total']) ?></td>
            <td><span class="badge bg-info"><?= strtoupper($s['payment_method']) ?></span></td>
            <td><?= statusBadge($s['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Low stock items -->
  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-exclamation-triangle me-2" style="color:var(--clr-warning)"></i>Low Stock Items</h6>
        <a href="../saleslady/inventory.php" class="btn btn-sm btn-outline-primary">Manage Stock</a>
      </div>
      <?php if (empty($lowItems)): ?>
      <div class="empty-state" style="padding:40px">
        <div class="empty-icon" style="color:var(--clr-success)"><i class="fas fa-check-circle"></i></div>
        <h6 style="color:var(--clr-success)">All stock levels are fine!</h6>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Alert</th></tr></thead>
          <tbody>
          <?php foreach ($lowItems as $item): ?>
          <tr>
            <td style="font-weight:600;font-size:.83rem"><?= sanitize($item['name']) ?></td>
            <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($item['cat']) ?></td>
            <td>
              <span style="font-weight:700;color:<?= $item['stock_quantity']==0?'var(--clr-danger)':'var(--clr-warning)' ?>">
                <?= $item['stock_quantity'] ?> left
              </span>
            </td>
            <td style="font-size:.8rem;color:var(--text-muted)">Alert at <?= $item['low_stock_alert'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>
const hCtx = document.getElementById("hourlyChart");
if(hCtx){
  new Chart(hCtx,{
    type:"bar",
    data:{
      labels:'.json_encode($hourlyLabels).',
      datasets:[{
        label:"Sales (₱)",
        data:'.json_encode($hourlyData).',
        backgroundColor:"rgba(5,150,105,.65)",
        borderRadius:8,borderSkipped:false
      }]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{
        y:{beginAtZero:true,ticks:{callback:v=>"₱"+v.toLocaleString(),font:{family:"Poppins",size:10}},grid:{color:"rgba(0,0,0,.04)"}},
        x:{ticks:{font:{family:"Poppins",size:10}},grid:{display:false}}
      }
    }
  });
}
</script>';
include __DIR__ . '/../includes/footer.php';
