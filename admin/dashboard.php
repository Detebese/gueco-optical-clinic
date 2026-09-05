<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Admin'];
$activeNav  = 'dashboard';

$stats = getDashboardStats();
$db    = getDB();

// Sales chart data — last 7 days
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
    HAVING total > 0
    ORDER BY total DESC
    LIMIT 6
")->fetchAll();

// If no categories have sales yet, fetch all categories for display
if (empty($catSales)) {
    $catSales = $db->query("SELECT name, 0 as total FROM categories WHERE status='active' LIMIT 5")->fetchAll();
}

// Today's appointments
$todayAppts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
    LIMIT 6
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

<!-- ─── Bento Top Metric Cards Row (4 Responsive Cards) ─── -->
<div class="row g-3 mb-4">
  <!-- Card 1: Sales this month -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat">
      <div class="bento-stat-left">
        <div class="bento-label">Sales this month</div>
        <div class="bento-value"><?= formatCurrency($stats['monthlySales']) ?></div>
        <div class="bento-change up">
          <i class="fas fa-arrow-trend-up"></i> <?= formatCurrency($stats['todaySales']) ?> today
        </div>
      </div>
      <div class="bento-stat-right">
        <!-- Mini Bar Chart (Reference Mockup style) -->
        <svg width="44" height="32" viewBox="0 0 44 32" fill="none">
          <rect x="4" y="16" width="5" height="16" rx="2.5" fill="#E09A67" opacity="0.35"/>
          <rect x="14" y="6" width="5" height="26" rx="2.5" fill="#E09A67" opacity="0.75"/>
          <rect x="24" y="12" width="5" height="20" rx="2.5" fill="#E09A67" opacity="0.5"/>
          <rect x="34" y="2" width="5" height="30" rx="2.5" fill="#E09A67"/>
        </svg>
      </div>
    </div>
  </div>

  <!-- Card 2: Total Patients -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat">
      <div class="bento-stat-left">
        <div class="bento-label">Total Patients</div>
        <div class="bento-value"><?= number_format($stats['totalPatients']) ?></div>
        <div class="bento-change neutral">
          <i class="fas fa-user-check"></i> Active records
        </div>
      </div>
      <div class="bento-stat-right">
        <!-- Mini Wave Sparkline (Reference Mockup style) -->
        <svg width="48" height="28" viewBox="0 0 48 28" fill="none">
          <path d="M2 20C10 20 12 6 24 14C34 22 36 4 46 4" stroke="#F59E0B" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>
    </div>
  </div>

  <!-- Card 3: Appointments Today -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat">
      <div class="bento-stat-left">
        <div class="bento-label">Today's Appointments</div>
        <div class="bento-value"><?= number_format($stats['todayAppointments']) ?></div>
        <div class="bento-change <?= $stats['pendingAppts'] > 0 ? 'neutral' : 'up' ?>">
          <i class="fas fa-clock"></i> <?= number_format($stats['pendingAppts']) ?> pending
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-icon-circle green">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 4: Low Stock Items -->
  <div class="col-lg-3 col-sm-6">
    <div class="bento-stat">
      <div class="bento-stat-left">
        <div class="bento-label">Low Stock Items</div>
        <div class="bento-value"><?= number_format($stats['lowStock']) ?></div>
        <div class="bento-change <?= $stats['lowStock'] > 0 ? 'down' : 'up' ?>">
          <i class="fas fa-<?= $stats['lowStock'] > 0 ? 'triangle-exclamation' : 'circle-check' ?>"></i>
          <?= $stats['lowStock'] > 0 ? 'Needs restocking' : 'Stock levels optimal' ?>
        </div>
      </div>
      <div class="bento-stat-right">
        <!-- Mini Status Wave -->
        <svg width="48" height="28" viewBox="0 0 48 28" fill="none">
          <path d="M2 14C10 6 16 22 24 12C32 2 40 18 46 8" stroke="<?= $stats['lowStock'] > 0 ? '#EF4444' : '#10B981' ?>" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>
    </div>
  </div>
</div>

<!-- ─── Middle Charts Row (Bento Grid) ────────────────────── -->
<div class="row g-3 mb-4">
  <!-- Main Sales Trend Area Chart -->
  <div class="col-xl-8 col-lg-7">
    <div class="card h-100">
      <div class="card-header">
        <div>
          <h6><i class="fas fa-chart-line me-2" style="color:var(--clr-bronze)"></i>Sales Overview — Last 7 Days</h6>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">
            <span>View Report</span> <i class="fas fa-arrow-right fa-xs"></i>
          </a>
        </div>
      </div>
      <div class="card-body">
        <div id="dashSalesApex" style="min-height: 280px; width: 100%;"></div>
      </div>
    </div>
  </div>

  <!-- Category Breakdown Donut Chart -->
  <div class="col-xl-4 col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-chart-pie me-2" style="color:var(--clr-gold)"></i>Sales by Category</h6>
      </div>
      <div class="card-body d-flex flex-column align-items-center justify-content-center p-3">
        <div id="dashCatApex" style="width: 100%; min-height: 280px;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Lower Row: Appointments & Low Stock ───────────────── -->
<div class="row g-3 mb-4">
  <!-- Today's Appointments -->
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-calendar-day me-2" style="color:var(--clr-success)"></i>Today's Appointments</h6>
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
            <tr><td colspan="4" class="text-center py-4 text-muted">
              <i class="fas fa-calendar-check fa-2x mb-2 d-block opacity-50"></i>
              No appointments scheduled for today
            </td></tr>
            <?php else: ?>
            <?php foreach ($appointments as $appt): ?>
            <tr>
              <td>
                <div class="fw-bold"><?= sanitize($appt['patient_name']) ?></div>
                <small class="text-muted"><?= sanitize($appt['phone']) ?></small>
              </td>
              <td><span class="badge bg-secondary"><?= formatTime($appt['appointment_time']) ?></span></td>
              <td><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
              <td><?= statusBadge($appt['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Low Stock Alerts -->
  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-triangle-exclamation me-2" style="color:var(--clr-warning)"></i>Low Stock Alerts</h6>
        <a href="../admin/inventory.php" class="btn btn-sm btn-outline-primary">View Inventory</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Stock</th>
              <th>Alert At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($lowStockItems)): ?>
            <tr><td colspan="4" class="text-center py-4 text-muted">
              <i class="fas fa-circle-check fa-2x mb-2 d-block text-success opacity-75"></i>
              All product stock levels are optimal!
            </td></tr>
            <?php else: ?>
            <?php foreach ($lowStockItems as $item): ?>
            <tr>
              <td class="fw-bold"><?= sanitize($item['name']) ?></td>
              <td><span class="badge bg-secondary"><?= sanitize($item['category']) ?></span></td>
              <td>
                <span class="badge <?= $item['stock_quantity'] == 0 ? 'bg-danger' : 'bg-warning' ?>">
                  <?= $item['stock_quantity'] ?> left
                </span>
              </td>
              <td class="text-muted"><?= $item['low_stock_alert'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ─── Recent Transactions ───────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h6><i class="fas fa-receipt me-2" style="color:var(--clr-bronze)"></i>Recent Transactions</h6>
    <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">All Sales</a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Patient</th>
          <th>Cashier</th>
          <th>Total</th>
          <th>Payment</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentSales)): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">
          <i class="fas fa-receipt fa-2x mb-2 d-block opacity-50"></i>
          No sales transactions recorded yet
        </td></tr>
        <?php else: ?>
        <?php foreach ($recentSales as $sale): ?>
        <tr>
          <td><span class="fw-bold" style="color:var(--clr-bronze)"><?= sanitize($sale['invoice_no']) ?></span></td>
          <td><?= sanitize($sale['patient_name'] ?? 'Walk-in') ?></td>
          <td class="text-muted"><?= sanitize($sale['cashier_name']) ?></td>
          <td class="fw-bold text-success"><?= formatCurrency($sale['total']) ?></td>
          <td><span class="badge bg-info"><?= strtoupper($sale['payment_method']) ?></span></td>
          <td class="text-muted"><?= formatDateTime($sale['created_at']) ?></td>
          <td><?= statusBadge($sale['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$catNames  = array_column($catSales, 'name');
$catTotals = array_map('floatval', array_column($catSales, 'total'));
$sales7Json = json_encode($sales7);
$labels7Json = json_encode($labels7);
$catNamesJson = json_encode($catNames);
$catTotalsJson = json_encode($catTotals);

$extraScripts = <<<HTML
<script>
document.addEventListener("DOMContentLoaded", function() {
  function getThemeColors() {
    const isDark = document.documentElement.getAttribute("data-theme") === "dark";
    return {
      isDark: isDark,
      mode: isDark ? "dark" : "light",
      textPrimary: isDark ? "#F9FAFB" : "#18181B",
      textSecondary: isDark ? "#E5E7EB" : "#374151",
      textMuted: isDark ? "#9CA3AF" : "#52525B",
      borderColor: isDark ? "rgba(255, 255, 255, 0.08)" : "rgba(0, 0, 0, 0.06)",
      valColor: isDark ? "#FDBA74" : "#B86B35"
    };
  }

  let tc = getThemeColors();

  // 1. Sales Line / Area Chart
  const salesOptions = {
    series: [{
      name: "Sales (₱)",
      data: {$sales7Json}
    }],
    chart: {
      type: "area",
      height: 280,
      toolbar: { show: false },
      fontFamily: "Plus Jakarta Sans, Poppins, sans-serif",
      background: "transparent",
      dropShadow: { enabled: true, top: 3, left: 0, blur: 5, color: "#E09A67", opacity: 0.25 }
    },
    theme: { mode: tc.mode },
    colors: ["#E09A67"],
    fill: {
      type: "gradient",
      gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] }
    },
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 3 },
    xaxis: {
      categories: {$labels7Json},
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: tc.textMuted, fontSize: "12px", fontWeight: 500 } }
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      labels: {
        formatter: (val) => "₱" + Math.round(val).toLocaleString(),
        style: { colors: tc.textMuted, fontSize: "12px", fontWeight: 500 }
      }
    },
    grid: {
      borderColor: tc.borderColor,
      strokeDashArray: 4,
      padding: { left: 10, right: 10, top: 0, bottom: 0 }
    },
    tooltip: {
      theme: tc.mode,
      style: { fontSize: "12px" },
      y: { formatter: (val) => "₱" + val.toLocaleString() }
    }
  };

  const salesEl = document.querySelector("#dashSalesApex");
  let salesChart = null;
  if (salesEl) {
    salesChart = new ApexCharts(salesEl, salesOptions);
    salesChart.render();
  }

  // 2. Category Donut Chart
  const rawNames = {$catNamesJson};
  const rawTotals = {$catTotalsJson};
  const hasData = rawTotals.some(t => t > 0);

  const catOptions = {
    series: hasData ? rawTotals : [1],
    labels: hasData ? rawNames : ["No Sales Recorded"],
    chart: {
      type: "donut",
      height: 280,
      fontFamily: "Plus Jakarta Sans, Poppins, sans-serif",
      background: "transparent",
      toolbar: { show: false }
    },
    theme: { mode: tc.mode },
    colors: ["#E09A67", "#F59E0B", "#10B981", "#06B6D4", "#B86B35", "#8B5CF6"],
    plotOptions: {
      pie: {
        donut: {
          size: "72%",
          labels: {
            show: true,
            name: {
              show: true,
              fontSize: "13px",
              fontWeight: 600,
              color: tc.textPrimary
            },
            value: {
              show: true,
              fontSize: "18px",
              fontWeight: 800,
              color: tc.valColor,
              formatter: (val) => hasData ? "₱" + parseFloat(val).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : "₱0.00"
            },
            total: {
              show: true,
              label: "Category Total",
              fontSize: "12px",
              fontWeight: 600,
              color: tc.textMuted,
              formatter: (w) => {
                if (!hasData) return "₱0.00";
                const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                return "₱" + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
              }
            }
          }
        }
      }
    },
    dataLabels: { enabled: false },
    stroke: { show: false },
    legend: {
      position: "bottom",
      fontSize: "12px",
      fontWeight: 500,
      labels: { colors: tc.textSecondary }
    },
    tooltip: {
      theme: tc.mode,
      style: { fontSize: "12px" },
      y: { formatter: (val) => hasData ? "₱" + parseFloat(val).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : "₱0.00" }
    }
  };

  const catEl = document.querySelector("#dashCatApex");
  let catChart = null;
  if (catEl) {
    catChart = new ApexCharts(catEl, catOptions);
    catChart.render();
  }

    // Dynamic Theme Switcher synchronization for charts
  window.addEventListener("themeChanged", (e) => {
    tc = getThemeColors();

    if (salesChart) {
      salesChart.updateOptions({
        theme: { mode: tc.mode },
        xaxis: { labels: { style: { colors: tc.textMuted } } },
        yaxis: { labels: { style: { colors: tc.textMuted } } },
        grid: { borderColor: tc.borderColor },
        tooltip: { theme: tc.mode }
      });
    }

    if (catChart) {
      catChart.updateOptions({
        theme: { mode: tc.mode },
        legend: { labels: { colors: tc.textSecondary } },
        tooltip: { theme: tc.mode },
        plotOptions: {
          pie: {
            donut: {
              labels: {
                name: { color: tc.textPrimary },
                value: { color: tc.valColor },
                total: { color: tc.textMuted }
              }
            }
          }
        }
      });
    }
  });
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';