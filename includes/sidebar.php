<?php
// ============================================================
// SIDEBAR NAVIGATION — Role-Based Menu Items
// ============================================================
$role = $_SESSION['user_role'] ?? '';
$base = BASE_URL;
?>

<?php if ($role === 'admin'): ?>
  <p class="nav-section-title">Main</p>
  <li class="nav-item">
    <a href="<?= $base ?>admin/dashboard.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
      <span>Dashboard</span>
    </a>
  </li>

  <p class="nav-section-title">Operations</p>
  <li class="nav-item">
    <a href="<?= $base ?>admin/appointments.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-calendar-check"></i></div>
      <span>Appointments</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>admin/patients.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-users"></i></div>
      <span>Patients</span>
    </a>
  </li>

  <p class="nav-section-title">Products</p>
  <li class="nav-item">
    <a href="<?= $base ?>admin/categories.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-th-large"></i></div>
      <span>Categories</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>admin/suppliers.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-truck"></i></div>
      <span>Suppliers</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>admin/inventory.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-boxes"></i></div>
      <span>Inventory</span>
    </a>
  </li>

  <p class="nav-section-title">Reports & Admin</p>
  <li class="nav-item">
    <a href="<?= $base ?>admin/sales_reports.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-chart-line"></i></div>
      <span>Sales Reports</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>admin/staff.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-user-tie"></i></div>
      <span>Staff Management</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>admin/activity_logs.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-history"></i></div>
      <span>Activity Logs</span>
    </a>
  </li>

<?php elseif ($role === 'doctor'): ?>
  <p class="nav-section-title">Main</p>
  <li class="nav-item">
    <a href="<?= $base ?>doctor/dashboard.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
      <span>Dashboard</span>
    </a>
  </li>

  <p class="nav-section-title">Clinical</p>
  <li class="nav-item">
    <a href="<?= $base ?>doctor/appointments.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-calendar-check"></i></div>
      <span>Appointments</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>doctor/patients.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-user-injured"></i></div>
      <span>Patients</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>doctor/prescriptions.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-glasses"></i></div>
      <span>Prescriptions</span>
    </a>
  </li>

  <p class="nav-section-title">Reports</p>
  <li class="nav-item">
    <a href="<?= $base ?>doctor/medical_reports.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-file-medical"></i></div>
      <span>Medical Reports</span>
    </a>
  </li>

<?php elseif ($role === 'saleslady'): ?>
  <p class="nav-section-title">Main</p>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/dashboard.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
      <span>Dashboard</span>
    </a>
  </li>

  <p class="nav-section-title">Service</p>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/appointments.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-calendar-check"></i></div>
      <span>Appointments</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/patients.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-users"></i></div>
      <span>Patients</span>
    </a>
  </li>

  <p class="nav-section-title">Sales</p>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/pos.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-cash-register"></i></div>
      <span>Point of Sale</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/sales.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-receipt"></i></div>
      <span>Sales Records</span>
    </a>
  </li>

  <p class="nav-section-title">Inventory</p>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/products.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-glasses"></i></div>
      <span>Products</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/inventory.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-warehouse"></i></div>
      <span>Stock Management</span>
    </a>
  </li>
  <li class="nav-item">
    <a href="<?= $base ?>saleslady/inventory_reports.php" class="nav-link">
      <div class="nav-icon"><i class="fas fa-file-alt"></i></div>
      <span>Inventory Reports</span>
    </a>
  </li>

<?php endif; ?>
