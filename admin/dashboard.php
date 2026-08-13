<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Admin'];
$activeNav  = 'dashboard';

$stats = getDashboardStats();

// Sales chart data — last 7 days
$db    = getDB();
$sales7 = [];
$labels7 = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as total FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $stmt->execute([$date]);
    $sales7[]  = round($stmt->fetch()['total'], 2);
    $labels7[] = date('M d', strtotime($date));
}

// Category sales breakdown
$catSales = $db->query("
    SELECT c.name, COALESCE(SUM(si.total_price),0) as total
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    LEFT JOIN sale_items si ON si.product_id = p.id
    LEFT JOIN sales s ON s.id = si.sale_id AND s.status = 'completed'
    GROUP BY c.id
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// Today's appointments
$todayAppts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
    LIMIT 8
");
$todayAppts->execute();
$appointments = $todayAppts->fetchAll();

// Low stock products
$lowStockItems = $db->query("
    SELECT p.name, p.stock_quantity, p.low_stock_alert, c.name as category
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.stock_quantity <= p.low_stock_alert AND p.status = 'active'
    ORDER BY p.stock_quantity ASC
    LIMIT 5
")->fetchAll();

// Recent sales
$recentSales = $db->query("
    SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
    FROM sales s
    LEFT JOIN patients p ON p.id = s.patient_id
    JOIN users u ON u.id = s.cashier_id
    ORDER BY s.created_at DESC
    LIMIT 5
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- Stats Row -->
<div class="row" style="margin-bottom:24px;">
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-primary)">
      <div class="stat-icon blue"><i class="fas fa-users"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($stats['totalPatients']) ?></div>
        <div class="stat-label">Total Patients</div>
        <div class="stat-change up"><i class="fas fa-arrow-up"></i> Active accounts</div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-info)">
      <div class="stat-icon teal"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($stats['todayAppointments']) ?></div>
        <div class="stat-label">Today's Appointments</div>
        <div class="stat-change" style="color:var(--clr-info)">
          <i class="fas fa-clock"></i> <?= number_format($stats['pendingAppts']) ?> pending
        </div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:var(--clr-success)">
      <div class="stat-icon green"><i class="fas fa-peso-sign"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= formatCurrency($stats['todaySales']) ?></div>
        <div class="stat-label">Today's Sales</div>
        <div class="stat-change up">
          <i class="fas fa-chart-line"></i> <?= formatCurrency($stats['monthlySales']) ?> this month
        </div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="stat-card" style="--stat-color:<?= $stats['lowStock'] > 0 ? 'var(--clr-danger)' : 'var(--clr-success)' ?>">
      <div class="stat-icon <?= $stats['lowStock'] > 0 ? 'red' : 'green' ?>">
        <i class="fas fa-boxes"></i>
      </div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($stats['lowStock']) ?></div>
        <div class="stat-label">Low Stock Items</div>
        <div class="stat-change <?= $stats['lowStock'] > 0 ? 'down' : 'up' ?>">
          <i class="fas fa-<?= $stats['lowStock'] > 0 ? 'exclamation-triangle' : 'check-circle' ?>"></i>
          <?= $stats['lowStock'] > 0 ? 'Needs restocking' : 'Stock levels OK' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row" style="margin-bottom:24px;">
  <!-- Sales Line Chart -->
  <div class="col-8">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-chart-line me-2" style="color:var(--clr-primary)"></i>Sales — Last 7 Days</h6>
        <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:240px;">
          <canvas id="salesChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Category Donut -->
  <div class="col-4">
    <div class="card" style="height:100%;">
      <div class="card-header">
        <h6><i class="fas fa-chart-pie me-2" style="color:var(--clr-secondary)"></i>Sales by Category</h6>
      </div>
      <div class="card-body" style="display:flex;align-items:center;justify-content:center;">
        <div class="chart-container" style="height:200px;width:200px;">
          <canvas id="catChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Lower Row: Appointments + Low Stock -->
<div class="row" style="margin-bottom:24px;">
  <!-- Today's Appointments -->
  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-calendar-day me-2" style="color:var(--clr-info)"></i>Today's Appointments</h6>
        <a href="../admin/appointments.php" class="btn btn-sm btn-outline-primary">Manage</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Patient</th>
              <th>Time</th>
              <th>Purpose</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($appointments)): ?>
            <tr><td colspan="4">
              <div class="empty-state" style="padding:30px;">
                <div class="empty-icon" style="width:50px;height:50px;margin-bottom:12px;font-size:1.3rem;"><i class="fas fa-calendar"></i></div>
                <p style="margin:0;font-size:.82rem;">No appointments today</p>
              </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($appointments as $appt): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:.83rem"><?= sanitize($appt['patient_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= sanitize($appt['phone']) ?></div>
              </td>
              <td style="font-size:.83rem"><?= formatTime($appt['appointment_time']) ?></td>
              <td style="font-size:.78rem"><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
              <td><?= statusBadge($appt['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Low Stock -->
  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-exclamation-triangle me-2" style="color:var(--clr-warning)"></i>Low Stock Alerts</h6>
        <a href="../admin/inventory.php" class="btn btn-sm btn-outline-primary">View Inventory</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr><th>Product</th><th>Category</th><th>Stock</th><th>Alert At</th></tr>
          </thead>
          <tbody>
            <?php if (empty($lowStockItems)): ?>
            <tr><td colspan="4">
              <div class="empty-state" style="padding:30px;">
                <div class="empty-icon" style="width:50px;height:50px;margin-bottom:12px;font-size:1.3rem;color:var(--clr-success)"><i class="fas fa-check-circle"></i></div>
                <p style="margin:0;font-size:.82rem;">All stock levels are good!</p>
              </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($lowStockItems as $item): ?>
            <tr>
              <td style="font-weight:600;font-size:.83rem"><?= sanitize($item['name']) ?></td>
              <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($item['category']) ?></td>
              <td>
                <span style="font-weight:700;font-size:.9rem;color:<?= $item['stock_quantity'] == 0 ? 'var(--clr-danger)' : 'var(--clr-warning)' ?>">
                  <?= $item['stock_quantity'] ?>
                </span>
              </td>
              <td style="font-size:.8rem;color:var(--text-muted)"><?= $item['low_stock_alert'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Recent Sales -->
<div class="card">
  <div class="card-header">
    <h6><i class="fas fa-receipt me-2" style="color:var(--clr-success)"></i>Recent Transactions</h6>
    <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">All Sales</a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr><th>Invoice</th><th>Patient</th><th>Cashier</th><th>Total</th><th>Payment</th><th>Date</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if (empty($recentSales)): ?>
        <tr><td colspan="7">
          <div class="empty-state" style="padding:30px;">
            <div class="empty-icon" style="width:50px;height:50px;margin-bottom:12px;font-size:1.3rem"><i class="fas fa-receipt"></i></div>
            <p style="margin:0;font-size:.82rem;">No sales recorded yet</p>
          </div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($recentSales as $sale): ?>
        <tr>
          <td><span style="font-family:monospace;font-size:.78rem;font-weight:600;color:var(--clr-primary)"><?= sanitize($sale['invoice_no']) ?></span></td>
          <td style="font-size:.83rem"><?= sanitize($sale['patient_name'] ?? 'Walk-in') ?></td>
          <td style="font-size:.83rem"><?= sanitize($sale['cashier_name']) ?></td>
          <td style="font-weight:700;color:var(--clr-success)"><?= formatCurrency($sale['total']) ?></td>
          <td><span class="badge bg-info"><?= strtoupper($sale['payment_method']) ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDateTime($sale['created_at']) ?></td>
          <td><?= statusBadge($sale['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$extraScripts = '<script>
// Sales Line Chart
const salesCtx = document.getElementById("salesChart");
if(salesCtx){
  new Chart(salesCtx, {
    type: "line",
    data: {
      labels: ' . json_encode($labels7) . ',
      datasets: [{
        label: "Sales (₱)",
        data: ' . json_encode($sales7) . ',
        borderColor: "#2563EB",
        backgroundColor: "rgba(37,99,235,.08)",
        borderWidth: 2.5,
        pointBackgroundColor: "#2563EB",
        pointRadius: 5,
        fill: true,
        tension: 0.4
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { callback: v => "₱" + v.toLocaleString(), font: { family: "Poppins", size: 11 } },
          grid: { color: "rgba(0,0,0,.05)" }
        },
        x: { ticks: { font: { family: "Poppins", size: 11 } }, grid: { display: false } }
      }
    }
  });
}
// Category Donut
const catCtx = document.getElementById("catChart");
if(catCtx){
  new Chart(catCtx, {
    type: "doughnut",
    data: {
      labels: ' . json_encode(array_column($catSales, 'name')) . ',
      datasets: [{
        data: ' . json_encode(array_column($catSales, 'total')) . ',
        backgroundColor: ["#2563EB","#7C3AED","#0891B2","#059669","#D97706"],
        borderWidth: 0,
        hoverOffset: 6
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      cutout: "70%",
      plugins: {
        legend: {
          position: "bottom",
          labels: { font: { family: "Poppins", size: 10 }, padding: 10, boxWidth: 10 }
        }
      }
    }
  });
}
</script>';
include __DIR__ . '/../includes/footer.php';
