<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Appointments';
$breadcrumb = ['Saleslady', 'Appointment Queue & Calendar'];
$activeNav  = 'appointments';
$db = getDB();
$today = date('Y-m-d');

// Handle status updates / notes
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($apptId > 0) {
        if ($action === 'update_notes') {
            $notes = sanitize($_POST['notes'] ?? '');
            $db->prepare("UPDATE appointments SET notes=? WHERE id=?")->execute([$notes, $apptId]);
            $_SESSION['flash_msg'] = 'Appointment notes updated.';
            $_SESSION['flash_type'] = 'info';

            $ptStmt = $db->prepare("SELECT p.full_name FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
            $ptStmt->execute([$apptId]);
            $ptName = $ptStmt->fetch()['full_name'] ?? ('Appointment #' . $apptId);
            logActivity("Updated notes for appointment #$apptId ($ptName)", "Appointments", $_SESSION['user_id'], 'staff');
        }
    }
    
    $redirectDate = !empty($_POST['current_view_date']) ? sanitize($_POST['current_view_date']) : $today;
    header('Location: appointments.php?date=' . urlencode($redirectDate));
    exit;
}

// Fetch all appointments for the calendar
$apptsStmt = $db->query("
    SELECT a.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone, 
           p.email as patient_email, 
           p.gender as patient_gender,
           p.birthdate as patient_birthdate,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id = a.patient_id) as rx_count,
           (SELECT COUNT(*) FROM appointments a2 WHERE a2.patient_id = a.patient_id AND a2.status = 'completed') as completed_visits,
           COALESCE(
               (SELECT s.id FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.id FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as sale_id,
           COALESCE(
               (SELECT s.invoice_no FROM sales s WHERE s.appointment_id = a.id ORDER BY s.id DESC LIMIT 1),
               (SELECT s2.invoice_no FROM sales s2 WHERE s2.patient_id = a.patient_id AND DATE(s2.created_at) = a.appointment_date ORDER BY s2.id DESC LIMIT 1)
           ) as invoice_no
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
");
$allAppointments = $apptsStmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for stat cards
$todayCountStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no_show')");
$todayCountStmt->execute([$today]);
$todayActiveCount = $todayCountStmt->fetch()['c'];

$pendingCount = count(array_filter($allAppointments, fn($a) => $a['status'] === 'pending'));
$confirmedCount = count(array_filter($allAppointments, fn($a) => $a['status'] === 'confirmed'));
$totalCount = count($allAppointments);

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/calendar.css?v=' . time() . '">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Quick Stats Summary Header (Side by Side Colored Indicators) -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;" class="cal-stat-grid">
  <div>
    <div class="stat-card" style="--stat-color:var(--clr-primary); padding:16px 20px; height:100%;">
      <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($todayActiveCount) ?></div>
        <div class="stat-label">Today's Appointments</div>
      </div>
    </div>
  </div>
  <div>
    <div class="stat-card" style="--stat-color:var(--clr-warning); padding:16px 20px; height:100%;">
      <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($pendingCount) ?></div>
        <div class="stat-label">Pending Confirmation</div>
      </div>
    </div>
  </div>
  <div>
    <div class="stat-card" style="--stat-color:var(--clr-success); padding:16px 20px; height:100%;">
      <div class="stat-icon teal"><i class="fas fa-user-check"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($confirmedCount) ?></div>
        <div class="stat-label">Confirmed Upcoming</div>
      </div>
    </div>
  </div>
  <div>
    <div class="stat-card" style="--stat-color:var(--clr-secondary); padding:16px 20px; height:100%;">
      <div class="stat-icon purple"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= number_format($totalCount) ?></div>
        <div class="stat-label">Total Appointments</div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- CALENDAR COMPONENT CONTAINER                                 -->
<!-- ============================================================ -->
<div class="cal-wrapper">
  
  <!-- 1. Top Header Bar -->
  <div class="cal-header">
    <div class="cal-header-left">
      <button type="button" class="cal-btn-today" id="btnToday">Today</button>
      <button type="button" class="cal-nav-btn" id="btnPrev" title="Previous"><i class="fas fa-chevron-left"></i></button>
      <button type="button" class="cal-nav-btn" id="btnNext" title="Next"><i class="fas fa-chevron-right"></i></button>
      
      <div class="cal-title-wrap">
        <h4 class="cal-title-heading" id="calTitle">August 2026</h4>
        <div class="cal-title-sub" id="calSubtitle">Front Desk Queue & Patient Check-in</div>
      </div>
    </div>

    <!-- Quick Sale Button & View Switcher -->
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <a href="pos.php?mode=retail" class="btn btn-warning btn-sm d-flex align-items-center gap-1 fw-bold shadow-sm" style="background:linear-gradient(135deg,#f59e0b,#d97706);border:none;color:#fff;border-radius:8px;padding:6px 14px;font-size:0.8rem;text-decoration:none;">
        <i class="fas fa-bolt"></i> Quick Sale / Walk-in
      </a>
      <div class="cal-view-switcher">
        <button type="button" class="cal-view-btn" data-view="week" id="viewBtnWeek">
          <i class="fas fa-calendar-week"></i> Week
        </button>
        <button type="button" class="cal-view-btn active" data-view="month" id="viewBtnMonth">
          <i class="fas fa-calendar-alt"></i> Month
        </button>
        <button type="button" class="cal-view-btn" data-view="agenda" id="viewBtnAgenda">
          <i class="fas fa-list-ul"></i> Agenda
        </button>
        <button type="button" class="cal-view-btn" data-view="table" id="viewBtnTable">
          <i class="fas fa-users-cog"></i> Queue
        </button>
      </div>
    </div>
  </div>

  <!-- 2. Filter & Live Search Toolbar -->
  <div class="cal-toolbar">
    <div class="cal-status-filters" id="statusFilterContainer">
      <button type="button" class="cal-filter-pill active" data-status="all">
        <span class="cal-bullet bullet-all"></span> All (<span id="countAll">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="confirmed">
        <span class="cal-bullet bullet-confirmed"></span> Confirmed (<span id="countConfirmed">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="pending">
        <span class="cal-bullet bullet-pending"></span> Pending (<span id="countPending">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="completed">
        <span class="cal-bullet bullet-completed"></span> Done (<span id="countCompleted">0</span>)
      </button>
      <button type="button" class="cal-filter-pill" data-status="no_show">
        <span class="cal-bullet bullet-no_show"></span> No-Show (<span id="countNoShow">0</span>)
      </button>
    </div>

    <div class="cal-search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="calSearchInput" placeholder="Search patient name, phone...">
    </div>
  </div>

  <!-- 3. Month View -->
  <div id="monthViewContainer" class="cal-month-view">
    <div class="cal-weekdays-header">
      <div class="cal-weekday">Sun</div>
      <div class="cal-weekday">Mon</div>
      <div class="cal-weekday">Tue</div>
      <div class="cal-weekday">Wed</div>
      <div class="cal-weekday">Thu</div>
      <div class="cal-weekday">Fri</div>
      <div class="cal-weekday">Sat</div>
    </div>
    <div class="cal-grid" id="monthGrid">
      <!-- Generated dynamically by JavaScript -->
    </div>
  </div>

  <!-- 4. Week View -->
  <div id="weekViewContainer" class="cal-week-view" style="display:none;">
    <!-- Generated dynamically by JavaScript -->
  </div>

  <!-- 5. Agenda View -->
  <div id="agendaViewContainer" class="cal-agenda-view" style="display:none;">
    <!-- Generated dynamically by JavaScript -->
  </div>

  <!-- 6. Queue / Table View -->
  <div id="tableViewContainer" style="display:none; padding:16px 24px 24px;">
    <div class="table-responsive">
      <table class="table" id="masterApptTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Patient</th>
            <th>Contact</th>
            <th>Date</th>
            <th>Time</th>
            <th>Purpose</th>
            <th>Notes</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <!-- Injected dynamically -->
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ============================================================ -->
<!-- ============================================================ -->
<!-- APPOINTMENT DETAILS & SALESLADY ACTION MODAL                 -->
<!-- ============================================================ -->
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px; height:44px; background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:1.1rem;" id="modalAvatar">
            S
          </div>
          <div>
            <h5 class="modal-title fw-bold cal-modal-title mb-0" id="modalPatientName">Patient Name</h5>
            <small class="text-muted" id="modalPatientMeta">Patient ID: #0 &middot; 0900-000-0000</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span id="modalStatusBadge"></span>
          <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>

      <div class="modal-body p-4">
        <!-- Appointment Details Cards -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-calendar me-1"></i> Scheduled Date</div>
              <div class="cal-info-val" id="modalDate">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-clock me-1"></i> Time Slot</div>
              <div class="cal-info-val text-primary" id="modalTime">—</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="cal-info-card">
              <div class="cal-info-label"><i class="fas fa-tag me-1"></i> Purpose</div>
              <div class="cal-info-val" id="modalPurpose">—</div>
            </div>
          </div>
        </div>

        <!-- Patient Quick Info Banner -->
        <div class="cal-modal-banner mb-4">
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Contact:</span>
              <div class="cal-modal-banner-val small" id="modalPhone">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Email:</span>
              <div class="cal-modal-banner-val small text-truncate" id="modalEmail">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Gender:</span>
              <div class="cal-modal-banner-val small" id="modalGenderAge">—</div>
            </div>
            <div class="col-6 col-md-3">
              <span class="cal-info-label">Prescription Count:</span>
              <div class="cal-modal-banner-val small" id="modalRxCount">—</div>
            </div>
          </div>
        </div>

        <!-- Appointment Notes -->
        <div class="mb-4">
          <label class="form-label text-muted small fw-bold text-uppercase"><i class="fas fa-sticky-note me-1"></i> Notes & Customer Requests</label>
          <div class="cal-modal-notes" id="modalNotes">
            No special notes provided.
          </div>
        </div>

        <!-- Dynamic Lock/Status Alert for Consultation Flow -->
        <div id="modalConsultationLockAlert" class="alert alert-warning d-flex align-items-center gap-2 mb-3 py-2 px-3" style="display:none;font-size:0.82rem;border-radius:10px;border:1.5px solid #f59e0b;background:rgba(245,158,11,0.08);">
          <i class="fas fa-lock fa-lg text-warning flex-shrink-0"></i>
          <div>
            <strong>Awaiting Doctor Examination:</strong> POS checkout unlocks automatically once the Optometrist inputs the prescription and completes the medical consultation.
          </div>
        </div>

        <!-- Saleslady & Cashier Shortcuts -->
        <div class="cal-modal-shortcuts p-3">
          <h6 class="cal-modal-shortcuts-heading"><i class="fas fa-cash-register text-primary me-2"></i>Service & POS Shortcuts</h6>
          <div class="d-flex flex-wrap gap-2">
            <a href="#" id="modalBtnPos" class="btn btn-primary btn-sm flex-fill py-2">
              <i class="fas fa-shopping-cart me-1"></i> Proceed to POS Checkout
            </a>
            <a href="#" id="modalBtnPatient" class="btn btn-outline-primary btn-sm flex-fill py-2">
              <i class="fas fa-user me-1"></i> View Patient Profile
            </a>
          </div>
        </div>
      </div>

      <div class="modal-footer d-flex justify-content-end align-items-center">
        <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- DAY SCHEDULE DRAWER / MODAL                                  -->
<!-- ============================================================ -->
<div class="modal fade" id="dayQueueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold cal-modal-title mb-0" id="dayModalTitle">Appointments for Date</h5>
          <small class="text-muted" id="dayModalSubtitle">Day Queue Overview</small>
        </div>
        <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4" id="dayModalBody">
        <!-- Injected dynamically -->
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- CALENDAR JAVASCRIPT LOGIC ENGINE                             -->
<!-- ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const rawAppointments = <?= json_encode($allAppointments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const initialDateStr = '<?= htmlspecialchars($_GET['date'] ?? $today) ?>';
  
  // App State
  let currentDate = new Date(initialDateStr + 'T00:00:00');
  if (isNaN(currentDate.getTime())) currentDate = new Date();
  
  let currentView = 'month'; // 'month', 'week', 'agenda', 'table'
  let currentFilter = 'all';  // 'all', 'confirmed', 'pending', 'completed', 'no_show', 'cancelled'
  let searchQuery = '';
  let selectedDateStr = initialDateStr || formatDateIso(new Date());

  // DOM Elements
  const calTitle = document.getElementById('calTitle');
  const calSubtitle = document.getElementById('calSubtitle');
  const btnToday = document.getElementById('btnToday');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const viewBtnMonth = document.getElementById('viewBtnMonth');
  const viewBtnWeek = document.getElementById('viewBtnWeek');
  const viewBtnAgenda = document.getElementById('viewBtnAgenda');
  const viewBtnTable = document.getElementById('viewBtnTable');
  const monthViewContainer = document.getElementById('monthViewContainer');
  const weekViewContainer = document.getElementById('weekViewContainer');
  const agendaViewContainer = document.getElementById('agendaViewContainer');
  const tableViewContainer = document.getElementById('tableViewContainer');
  const monthGrid = document.getElementById('monthGrid');
  const tableBody = document.getElementById('tableBody');
  const searchInput = document.getElementById('calSearchInput');
  const filterPills = document.querySelectorAll('.cal-filter-pill');

  // Modals
  const appointmentModalEl = document.getElementById('appointmentModal');
  const appointmentModal = new bootstrap.Modal(appointmentModalEl);
  const dayQueueModal = new bootstrap.Modal(document.getElementById('dayQueueModal'));

  // Utility helpers
  function formatDateIso(d) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function formatTime12(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    let hours = parseInt(parts[0], 10);
    const mins = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${hours}:${mins} ${ampm}`;
  }

  function formatDisplayDate(dateObj) {
    return dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function getStatusBadgeHtml(status) {
    const map = {
      'confirmed': '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fas fa-check-circle me-1"></i>Confirmed</span>',
      'pending':   '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fas fa-clock me-1"></i>Pending</span>',
      'completed': '<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1"><i class="fas fa-check-double me-1"></i>Done</span>',
      'cancelled': '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fas fa-times-circle me-1"></i>Cancelled</span>',
      'no_show':   '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="fas fa-user-slash me-1"></i>No-Show</span>'
    };
    return map[status] || `<span class="badge bg-secondary">${status}</span>`;
  }

  // Filter Appointments
  function getFilteredAppointments() {
    return rawAppointments.filter(appt => {
      if (currentFilter !== 'all' && appt.status !== currentFilter) return false;
      if (searchQuery.trim() !== '') {
        const q = searchQuery.toLowerCase();
        const patientName = (appt.patient_name || '').toLowerCase();
        const phone = (appt.patient_phone || '').toLowerCase();
        const purpose = (appt.purpose || '').toLowerCase();
        const notes = (appt.notes || '').toLowerCase();
        if (!patientName.includes(q) && !phone.includes(q) && !purpose.includes(q) && !notes.includes(q)) {
          return false;
        }
      }
      return true;
    });
  }

  function updateCounts() {
    const total = rawAppointments.length;
    const confirmed = rawAppointments.filter(a => a.status === 'confirmed').length;
    const pending = rawAppointments.filter(a => a.status === 'pending').length;
    const completed = rawAppointments.filter(a => a.status === 'completed').length;
    const noShow = rawAppointments.filter(a => a.status === 'no_show').length;

    document.getElementById('countAll').textContent = total;
    document.getElementById('countConfirmed').textContent = confirmed;
    document.getElementById('countPending').textContent = pending;
    document.getElementById('countCompleted').textContent = completed;
    document.getElementById('countNoShow').textContent = noShow;
  }

  // ── 1. RENDER MONTH VIEW ───────────────────────────────────────
  function renderMonth() {
    monthGrid.innerHTML = '';
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    calTitle.textContent = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    calSubtitle.textContent = `Monthly View &middot; ${currentDate.toLocaleDateString('en-US', { month: 'long' })}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const lastDayOfMonth = new Date(year, month + 1, 0).getDate();
    const prevLastDay = new Date(year, month, 0).getDate();

    const todayIso = formatDateIso(new Date());
    const filteredAppts = getFilteredAppointments();

    const apptsByDate = {};
    filteredAppts.forEach(appt => {
      if (!apptsByDate[appt.appointment_date]) apptsByDate[appt.appointment_date] = [];
      apptsByDate[appt.appointment_date].push(appt);
    });

    // Previous month filler days
    for (let x = firstDayIndex; x > 0; x--) {
      const dayNum = prevLastDay - x + 1;
      const prevDate = new Date(year, month - 1, dayNum);
      const dateIso = formatDateIso(prevDate);
      const cell = createDayCell(dayNum, dateIso, true, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }

    // Current month days
    for (let i = 1; i <= lastDayOfMonth; i++) {
      const thisDate = new Date(year, month, i);
      const dateIso = formatDateIso(thisDate);
      const cell = createDayCell(i, dateIso, false, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }

    // Next month filler days
    const totalCellsSoFar = firstDayIndex + lastDayOfMonth;
    const remainingCells = totalCellsSoFar > 35 ? (42 - totalCellsSoFar) : (35 - totalCellsSoFar);

    for (let j = 1; j <= remainingCells; j++) {
      const nextDate = new Date(year, month + 1, j);
      const dateIso = formatDateIso(nextDate);
      const cell = createDayCell(j, dateIso, true, apptsByDate[dateIso] || [], todayIso);
      monthGrid.appendChild(cell);
    }
  }

  function createDayCell(dayNum, dateIso, isOtherMonth, appts, todayIso) {
    const cell = document.createElement('div');
    cell.className = 'cal-day-cell';
    if (isOtherMonth) cell.classList.add('other-month');
    if (dateIso === todayIso) cell.classList.add('is-today');
    if (dateIso === selectedDateStr) cell.classList.add('selected-day');

    cell.dataset.date = dateIso;

    // Top Header in Cell with side-by-side indicator dots
    const topWrap = document.createElement('div');
    topWrap.className = 'cal-day-cell-top';

    const numWrap = document.createElement('div');
    numWrap.className = 'cal-day-number-wrap';

    const numSpan = document.createElement('span');
    numSpan.className = 'cal-day-number';
    numSpan.textContent = dayNum;
    numWrap.appendChild(numSpan);

    if (appts.length > 0) {
      const dotsRow = document.createElement('span');
      dotsRow.className = 'cal-day-dots-row';
      appts.slice(0, 4).forEach(a => {
        const dot = document.createElement('span');
        dot.className = `cal-indicator-dot dot-${a.status}`;
        dotsRow.appendChild(dot);
      });
      numWrap.appendChild(dotsRow);
    }

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cal-add-btn';
    addBtn.title = 'View Appointments on ' + dateIso;
    addBtn.innerHTML = '<i class="fas fa-plus"></i>';
    addBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      openDayQueueModal(dateIso);
    });

    topWrap.appendChild(numWrap);
    topWrap.appendChild(addBtn);
    cell.appendChild(topWrap);

    // Events list
    const eventsList = document.createElement('div');
    eventsList.className = 'cal-events-list';

    const maxVisible = 3;
    const visibleAppts = appts.slice(0, maxVisible);
    const overflowCount = appts.length - maxVisible;

    visibleAppts.forEach(appt => {
      const card = document.createElement('div');
      card.className = `cal-event-card status-${appt.status}`;
      const timeStr = formatTime12(appt.appointment_time);

      card.innerHTML = `
        <div class="cal-card-chips-row">
          <span class="cal-event-time"><i class="far fa-clock"></i> ${timeStr}</span>
          <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
        </div>
        <div class="cal-event-title">${escapeHtml(appt.patient_name)}</div>
      `;

      card.addEventListener('click', (e) => {
        e.stopPropagation();
        openAppointmentModal(appt);
      });

      eventsList.appendChild(card);
    });

    if (overflowCount > 0) {
      const moreBadge = document.createElement('div');
      moreBadge.className = 'cal-more-badge';
      moreBadge.textContent = `+ ${overflowCount} more`;
      moreBadge.addEventListener('click', (e) => {
        e.stopPropagation();
        openDayQueueModal(dateIso);
      });
      eventsList.appendChild(moreBadge);
    }

    cell.appendChild(eventsList);

    cell.addEventListener('click', () => {
      document.querySelectorAll('.cal-day-cell').forEach(c => c.classList.remove('selected-day'));
      cell.classList.add('selected-day');
      selectedDateStr = dateIso;
    });

    cell.addEventListener('dblclick', () => {
      openDayQueueModal(dateIso);
    });

    return cell;
  }

  // ── 2. RENDER WEEK VIEW ────────────────────────────────────────
  function renderWeek() {
    weekViewContainer.innerHTML = '';
    const todayIso = formatDateIso(new Date());
    const filteredAppts = getFilteredAppointments();

    const curr = new Date(currentDate);
    const dayOfWeek = curr.getDay();
    const sunday = new Date(curr);
    sunday.setDate(curr.getDate() - dayOfWeek);

    const weekDays = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(sunday);
      d.setDate(sunday.getDate() + i);
      weekDays.push(d);
    }

    const startStr = weekDays[0].toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const endStr = weekDays[6].toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    calTitle.textContent = `${startStr} – ${endStr}`;
    calSubtitle.textContent = 'Weekly Front Desk Schedule Overview';

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    weekDays.forEach((dayObj, index) => {
      const dateIso = formatDateIso(dayObj);
      const isToday = (dateIso === todayIso);
      const dayAppts = filteredAppts.filter(a => a.appointment_date === dateIso);

      const col = document.createElement('div');
      col.className = 'cal-week-col';
      if (isToday) col.classList.add('is-today');

      col.innerHTML = `
        <div class="cal-week-col-header">
          <div class="cal-week-col-day">${dayNames[index]}</div>
          <div class="cal-week-col-num">${dayObj.getDate()}</div>
        </div>
      `;

      const eventsList = document.createElement('div');
      eventsList.className = 'cal-week-events-list';

      if (dayAppts.length === 0) {
        eventsList.innerHTML = `<div class="text-center text-muted small py-4" style="opacity:0.5;">No appts</div>`;
      } else {
        dayAppts.forEach(appt => {
          const card = document.createElement('div');
          card.className = `cal-week-card status-${appt.status}`;
          
          card.innerHTML = `
            <div class="cal-card-chips-row">
              <span class="cal-event-time"><i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}</span>
              <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
            </div>
            <div class="cal-week-card-name">${escapeHtml(appt.patient_name)}</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <span class="badge bg-dark-subtle text-info small" style="font-size:0.68rem;"><i class="fas fa-tag me-1"></i>${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</span>
              <span class="badge bg-dark-subtle text-muted small" style="font-size:0.68rem;">Rx: ${appt.rx_count || 0}</span>
            </div>
          `;

          card.addEventListener('click', () => openAppointmentModal(appt));
          eventsList.appendChild(card);
        });
      }

      col.appendChild(eventsList);
      weekViewContainer.appendChild(col);
    });
  }

  // ── 3. RENDER AGENDA VIEW ──────────────────────────────────────
  function renderAgenda() {
    agendaViewContainer.innerHTML = '';
    const filteredAppts = getFilteredAppointments();

    calTitle.textContent = currentDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    calSubtitle.textContent = 'Agenda Timeline &middot; Chronological Patient List';

    if (filteredAppts.length === 0) {
      agendaViewContainer.innerHTML = `
        <div class="text-center py-5">
          <i class="fas fa-calendar-times fa-3x text-muted mb-3" style="opacity:0.4;"></i>
          <h6 class="text-white">No Appointments Found</h6>
          <p class="text-muted small">Try switching filters or searching with different terms.</p>
        </div>
      `;
      return;
    }

    const grouped = {};
    filteredAppts.forEach(appt => {
      if (!grouped[appt.appointment_date]) grouped[appt.appointment_date] = [];
      grouped[appt.appointment_date].push(appt);
    });

    const dates = Object.keys(grouped).sort();

    dates.forEach(dateStr => {
      const appts = grouped[dateStr];
      const dateObj = new Date(dateStr + 'T00:00:00');
      const isToday = (dateStr === formatDateIso(new Date()));

      const groupDiv = document.createElement('div');
      groupDiv.className = 'cal-agenda-group';

      groupDiv.innerHTML = `
        <div class="cal-agenda-group-header">
          <h6 class="cal-agenda-date-title">
            <i class="fas fa-calendar-day text-primary"></i>
            ${formatDisplayDate(dateObj)}
            ${isToday ? '<span class="badge bg-primary text-white ms-2">Today</span>' : ''}
          </h6>
          <span class="badge bg-secondary-subtle border border-secondary-subtle text-secondary">${appts.length} patient${appts.length>1?'s':''}</span>
        </div>
      `;

      const itemsWrap = document.createElement('div');
      itemsWrap.className = 'cal-agenda-items';

      appts.forEach(appt => {
        const item = document.createElement('div');
        item.className = 'cal-agenda-item';

        item.innerHTML = `
          <div class="cal-agenda-left">
            <div class="cal-agenda-time-badge">
              <i class="far fa-clock me-1"></i>${formatTime12(appt.appointment_time)}
            </div>
            <div class="cal-agenda-patient-info">
              <h6>${escapeHtml(appt.patient_name)}</h6>
              <div class="d-flex align-items-center gap-2 mt-1">
                <span class="cal-side-chip chip-${appt.status}">${appt.status.replace('_', ' ')}</span>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><i class="fas fa-tag me-1"></i>${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</span>
                <span class="text-muted small"><i class="fas fa-phone-alt me-1"></i>${escapeHtml(appt.patient_phone || 'No phone')}</span>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${getStatusBadgeHtml(appt.status)}
            <button type="button" class="btn btn-outline-primary btn-sm px-3 py-1 btn-view-appt">
              <i class="fas fa-eye me-1"></i> Check-in
            </button>
          </div>
        `;

        item.querySelector('.btn-view-appt').addEventListener('click', (e) => {
          e.stopPropagation();
          openAppointmentModal(appt);
        });

        item.addEventListener('click', () => openAppointmentModal(appt));
        itemsWrap.appendChild(item);
      });

      groupDiv.appendChild(itemsWrap);
      agendaViewContainer.appendChild(groupDiv);
    });
  }

  // ── 4. RENDER QUEUE / TABLE VIEW ───────────────────────────────
  function renderTable() {
    tableBody.innerHTML = '';
    const filteredAppts = getFilteredAppointments();

    calTitle.textContent = 'Patient Appointment Queue';
    calSubtitle.textContent = `Queue List &middot; ${filteredAppts.length} record(s)`;

    if (filteredAppts.length === 0) {
      tableBody.innerHTML = `
        <tr>
          <td colspan="9" class="text-center py-4 text-muted">
            <i class="fas fa-search me-1"></i> No matching appointments in queue.
          </td>
        </tr>
      `;
      return;
    }

    filteredAppts.forEach((appt, idx) => {
      const tr = document.createElement('tr');
      const apptDateObj = new Date(appt.appointment_date + 'T00:00:00');

      tr.innerHTML = `
        <td class="text-muted fw-bold">${idx + 1}</td>
        <td>
          <div class="fw-bold cal-modal-title">${escapeHtml(appt.patient_name)}</div>
          <small class="text-muted">${escapeHtml(appt.patient_phone || '')}</small>
        </td>
        <td>${escapeHtml(appt.patient_phone || '—')}</td>
        <td>${formatDisplayDate(apptDateObj)}</td>
        <td class="fw-bold text-primary">${formatTime12(appt.appointment_time)}</td>
        <td>${escapeHtml((appt.purpose||'').replace(/_/g, ' '))}</td>
        <td class="text-muted small">${escapeHtml(appt.notes || '—')}</td>
        <td>${getStatusBadgeHtml(appt.status)}</td>
        <td>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-outline-primary btn-sm px-2 py-1 btn-open-table-modal">
              <i class="fas fa-eye"></i> Manage
            </button>
            <a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-primary btn-sm px-2 py-1" title="POS Checkout">
              <i class="fas fa-shopping-cart"></i>
            </a>
          </div>
        </td>
      `;

      tr.querySelector('.btn-open-table-modal').addEventListener('click', () => {
        openAppointmentModal(appt);
      });

      tableBody.appendChild(tr);
    });
  }

  // ── Master Render Trigger ──────────────────────────────────────
  function render() {
    updateCounts();
    monthViewContainer.style.display = (currentView === 'month') ? 'block' : 'none';
    weekViewContainer.style.display = (currentView === 'week') ? 'grid' : 'none';
    agendaViewContainer.style.display = (currentView === 'agenda') ? 'flex' : 'none';
    tableViewContainer.style.display = (currentView === 'table') ? 'block' : 'none';

    if (currentView === 'month') renderMonth();
    else if (currentView === 'week') renderWeek();
    else if (currentView === 'agenda') renderAgenda();
    else if (currentView === 'table') renderTable();
  }

  // ── Navigation Buttons ─────────────────────────────────────────
  btnToday.addEventListener('click', () => {
    currentDate = new Date();
    selectedDateStr = formatDateIso(new Date());
    render();
  });

  btnPrev.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda' || currentView === 'table') {
      currentDate.setMonth(currentDate.getMonth() - 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() - 7);
    }
    render();
  });

  btnNext.addEventListener('click', () => {
    if (currentView === 'month' || currentView === 'agenda' || currentView === 'table') {
      currentDate.setMonth(currentDate.getMonth() + 1);
    } else if (currentView === 'week') {
      currentDate.setDate(currentDate.getDate() + 7);
    }
    render();
  });

  // View Switchers
  const allViewBtns = [viewBtnMonth, viewBtnWeek, viewBtnAgenda, viewBtnTable];
  allViewBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      allViewBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentView = btn.dataset.view;
      render();
    });
  });

  // Filter Buttons
  filterPills.forEach(pill => {
    pill.addEventListener('click', () => {
      filterPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      currentFilter = pill.dataset.status;
      render();
    });
  });

  // Search Input
  searchInput.addEventListener('input', (e) => {
    searchQuery = e.target.value;
    render();
  });

  // ── Appointment Details Modal Logic ────────────────────────────
  function openAppointmentModal(appt) {
    document.getElementById('modalAvatar').textContent = (appt.patient_name || 'U').charAt(0).toUpperCase();
    document.getElementById('modalPatientName').textContent = appt.patient_name;
    document.getElementById('modalPatientMeta').textContent = `Patient ID: #${appt.patient_id} · ${appt.patient_phone || 'No phone recorded'}`;
    
    document.getElementById('modalStatusBadge').innerHTML = getStatusBadgeHtml(appt.status);

    const apptDateObj = new Date(appt.appointment_date + 'T00:00:00');
    document.getElementById('modalDate').textContent = formatDisplayDate(apptDateObj);
    document.getElementById('modalTime').textContent = formatTime12(appt.appointment_time);
    document.getElementById('modalPurpose').textContent = (appt.purpose || '').replace(/_/g, ' ').toUpperCase();

    document.getElementById('modalPhone').textContent = appt.patient_phone || '—';
    document.getElementById('modalEmail').textContent = appt.patient_email || '—';
    document.getElementById('modalGenderAge').textContent = (appt.patient_gender ? (appt.patient_gender.charAt(0).toUpperCase() + appt.patient_gender.slice(1)) : '—');
    document.getElementById('modalRxCount').textContent = `${appt.rx_count || 0} Prescription(s)`;

    document.getElementById('modalNotes').textContent = appt.notes && appt.notes.trim() !== '' ? appt.notes : 'No special notes entered for this appointment.';

    // Saleslady Shortcuts & Lock Logic
    const btnPos = document.getElementById('modalBtnPos');
    const lockAlert = document.getElementById('modalConsultationLockAlert');
    const purpose = (appt.purpose || 'consultation').toLowerCase();
    const isConsultation = purpose.includes('consultation') || purpose.includes('eye_exam') || purpose.includes('checkup');
    const isExamCompleted = appt.status === 'completed' || (parseInt(appt.rx_count, 10) > 0 && appt.status !== 'pending');
    const isCancelledOrNoShow = appt.status === 'cancelled' || appt.status === 'no_show';
    const hasSale = !!appt.sale_id;

    if (hasSale) {
      if (lockAlert) lockAlert.style.display = 'none';
      btnPos.href = `receipt.php?id=${appt.sale_id}`;
      btnPos.target = '_blank';
      btnPos.className = 'btn btn-success btn-sm flex-fill py-2 shadow-sm';
      btnPos.style.pointerEvents = '';
      btnPos.style.opacity = '1';
      btnPos.innerHTML = `<i class="fas fa-file-invoice me-1"></i> View Receipt (${escapeHtml(appt.invoice_no || '#' + appt.sale_id)})`;
    } else if (isCancelledOrNoShow) {
      if (lockAlert) lockAlert.style.display = 'none';
      btnPos.removeAttribute('href');
      btnPos.target = '_self';
      btnPos.className = 'btn btn-secondary btn-sm flex-fill py-2 disabled';
      btnPos.style.pointerEvents = 'none';
      btnPos.style.opacity = '0.65';
      btnPos.innerHTML = `<i class="fas fa-ban me-1"></i> ${appt.status === 'cancelled' ? 'Appointment Cancelled' : 'No-Show Recorded'}`;
    } else if (isConsultation && !isExamCompleted) {
      // Consultation pending Doctor Examination -> LOCK POS BUTTON
      if (lockAlert) lockAlert.style.display = 'flex';
      btnPos.removeAttribute('href');
      btnPos.target = '_self';
      btnPos.className = 'btn btn-secondary btn-sm flex-fill py-2 disabled';
      btnPos.style.pointerEvents = 'none';
      btnPos.style.opacity = '0.85';
      btnPos.innerHTML = `<i class="fas fa-lock me-1"></i> POS Locked (Awaiting Doctor Exam)`;
    } else {
      if (lockAlert) lockAlert.style.display = 'none';
      btnPos.href = `pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}`;
      btnPos.target = '_self';
      btnPos.className = 'btn btn-primary btn-sm flex-fill py-2 shadow-sm';
      btnPos.style.pointerEvents = '';
      btnPos.style.opacity = '1';
      btnPos.innerHTML = `<i class="fas fa-shopping-cart me-1"></i> Proceed to POS Checkout`;
    }

    document.getElementById('modalBtnPatient').href = `patients.php?view=${appt.patient_id}`;

    appointmentModal.show();
  }

  // ── Day Schedule Queue Modal Logic ─────────────────────────────
  function openDayQueueModal(dateIso) {
    const dateObj = new Date(dateIso + 'T00:00:00');
    document.getElementById('dayModalTitle').textContent = `Appointments on ${formatDisplayDate(dateObj)}`;
    
    const dayAppts = rawAppointments.filter(a => a.appointment_date === dateIso);
    document.getElementById('dayModalSubtitle').textContent = `${dayAppts.length} total scheduled patient(s)`;

    const body = document.getElementById('dayModalBody');
    if (dayAppts.length === 0) {
      body.innerHTML = `
        <div class="text-center py-5">
          <i class="fas fa-calendar-check fa-3x text-muted mb-3" style="opacity:0.4;"></i>
          <h6 class="cal-modal-title fw-bold mb-1">No Appointments Scheduled</h6>
          <p class="text-muted small">There are no patient bookings recorded for this date.</p>
        </div>
      `;
    } else {
      let html = '<div class="d-flex flex-column gap-3">';
      dayAppts.forEach(appt => {
        const p = (appt.purpose || 'consultation').toLowerCase();
        const isConsult = p.includes('consultation') || p.includes('eye_exam') || p.includes('checkup');
        const isDone = appt.status === 'completed' || (parseInt(appt.rx_count, 10) > 0 && appt.status !== 'pending');

        let actionBtn = '';
        if (appt.sale_id) {
          actionBtn = `<a href="receipt.php?id=${appt.sale_id}" target="_blank" class="btn btn-success btn-sm px-3 shadow-sm" title="View Receipt"><i class="fas fa-file-invoice me-1"></i> Receipt</a>`;
        } else if (appt.status === 'cancelled' || appt.status === 'no_show') {
          actionBtn = `<span class="badge bg-secondary py-2 px-3">${appt.status === 'cancelled' ? 'Cancelled' : 'No-Show'}</span>`;
        } else if (isConsult && !isDone) {
          actionBtn = `<button type="button" class="btn btn-secondary btn-sm px-3 disabled" style="opacity:0.75;cursor:not-allowed;" title="POS Locked: Awaiting Doctor's Exam & Prescription"><i class="fas fa-lock me-1"></i> Awaiting Exam</button>`;
        } else {
          actionBtn = `<a href="pos.php?patient_id=${appt.patient_id}&appt_id=${appt.id}" class="btn btn-primary btn-sm px-3 shadow-sm" title="Proceed to Checkout"><i class="fas fa-shopping-cart me-1"></i> Checkout</a>`;
        }

        html += `
          <div class="cal-info-card p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="cal-agenda-time-badge" style="min-width:85px;">
                ${formatTime12(appt.appointment_time)}
              </div>
              <div>
                <h6 class="cal-modal-title fw-bold mb-1">${escapeHtml(appt.patient_name)}</h6>
                <small class="text-muted">
                  ${escapeHtml((appt.purpose||'').replace(/_/g, ' '))} &middot; 
                  ${escapeHtml(appt.patient_phone || 'No phone')}
                </small>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              ${getStatusBadgeHtml(appt.status)}
              <button type="button" class="btn btn-outline-primary btn-sm px-3 btn-open-single" data-id="${appt.id}">
                <i class="fas fa-eye me-1"></i> Manage
              </button>
              ${actionBtn}
            </div>
          </div>
        `;
      });
      html += '</div>';
      body.innerHTML = html;

      body.querySelectorAll('.btn-open-single').forEach(btn => {
        btn.addEventListener('click', () => {
          const apptId = parseInt(btn.dataset.id, 10);
          const found = rawAppointments.find(a => a.id === apptId);
          if (found) {
            dayQueueModal.hide();
            setTimeout(() => openAppointmentModal(found), 250);
          }
        });
      });
    }

    dayQueueModal.show();
  }

  // Initial render
  render();

  // Check URL params for auto-open
  const urlParams = new URLSearchParams(window.location.search);
  const autoApptId = parseInt(urlParams.get('manage') || urlParams.get('appt_id') || '0', 10);
  if (autoApptId > 0) {
    const targetAppt = rawAppointments.find(a => a.id === autoApptId);
    if (targetAppt) {
      setTimeout(() => openAppointmentModal(targetAppt), 300);
    }
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
