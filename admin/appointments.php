<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Appointments';
$breadcrumb = ['Admin', 'Appointments'];
$db = getDB();
$today = date('Y-m-d');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $action = $_POST['action'];

    $ptStmt = $db->prepare("SELECT p.full_name FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
    $ptStmt->execute([$apptId]);
    $ptName = $ptStmt->fetch()['full_name'] ?? ('Appointment #' . $apptId);

    if ($action === 'confirm') {
        $db->prepare("UPDATE appointments SET status='confirmed', verified_by=? WHERE id=?")->execute([$_SESSION['user_id'], $apptId]);
        $_SESSION['flash_msg'] = 'Appointment confirmed.'; $_SESSION['flash_type'] = 'success';
        logActivity("Confirmed appointment #$apptId for patient $ptName", "Appointments", $_SESSION['user_id'], 'staff');
    } elseif ($action === 'complete') {
        $db->prepare("UPDATE appointments SET status='completed' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Appointment marked as completed.'; $_SESSION['flash_type'] = 'success';
        logActivity("Marked appointment #$apptId as completed for patient $ptName", "Appointments", $_SESSION['user_id'], 'staff');
    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Appointment cancelled.'; $_SESSION['flash_type'] = 'success';
        logActivity("Cancelled appointment #$apptId for patient $ptName", "Appointments", $_SESSION['user_id'], 'staff');
    } elseif ($action === 'no_show') {
        $db->prepare("UPDATE appointments SET status='no_show' WHERE id=?")->execute([$apptId]);
        $_SESSION['flash_msg'] = 'Marked as no-show.'; $_SESSION['flash_type'] = 'success';
        logActivity("Marked appointment #$apptId as No-Show for patient $ptName", "Appointments", $_SESSION['user_id'], 'staff');
    }
    header('Location: appointments.php'); exit;
}

// Filters
$filterDate   = $_GET['date']   ?? '';
$filterMonth  = $_GET['month']  ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = $_GET['search'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

// If visiting page with no query parameters at all (initial load), default to current month
if (empty($_GET)) {
    $filterMonth = date('Y-m');
} elseif (isset($_GET['all'])) {
    $filterDate = '';
    $filterMonth = '';
    $filterStatus = '';
    $search = '';
}

$where = ['1=1'];
$params = [];

if (!empty($filterDate)) {
    $where[] = 'a.appointment_date = ?';
    $params[] = $filterDate;
} elseif (!empty($filterMonth)) {
    $where[] = "DATE_FORMAT(a.appointment_date, '%Y-%m') = ?";
    $params[] = $filterMonth;
}

if (!empty($filterStatus)) {
    $where[] = 'a.status = ?';
    $params[] = $filterStatus;
}

if (!empty($search)) {
    $where[] = 'p.full_name LIKE ?';
    $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) as c FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE $whereStr");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];
$pagination = paginate($total, $perPage, $page);

$params[] = $perPage; $params[] = $pagination['offset'];
$appts = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone, p.email as patient_email
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE $whereStr
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT ? OFFSET ?
");
$appts->execute($params);
$appts = $appts->fetchAll();

// Overall Stats
$overallStats = [];
foreach (['pending','confirmed','completed','cancelled','no_show'] as $s) {
    $stmt = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE status=?");
    $stmt->execute([$s]); $overallStats[$s] = $stmt->fetch()['c'];
}

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/appointments.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Overall quick stats -->
<div class="appt-stats-container">
  <?php
  $statCfg = [
      'pending'   => ['type' => 'pending',   'color' => '#F59E0B', 'rgb' => '245, 158, 11',  'icon' => 'clock',        'label' => 'Pending'],
      'confirmed' => ['type' => 'confirmed', 'color' => '#0EA5E9', 'rgb' => '14, 165, 233',  'icon' => 'check-circle', 'label' => 'Confirmed'],
      'completed' => ['type' => 'completed', 'color' => '#10B981', 'rgb' => '16, 185, 129',  'icon' => 'check-double', 'label' => 'Completed'],
      'cancelled' => ['type' => 'cancelled', 'color' => '#EF4444', 'rgb' => '239, 68, 68',   'icon' => 'times-circle', 'label' => 'Cancelled'],
      'no_show'   => ['type' => 'no_show',   'color' => '#8B5CF6', 'rgb' => '139, 92, 246',  'icon' => 'user-slash',   'label' => 'No Show']
  ];

  $baseCardParams = [];
  if (!empty($filterDate)) $baseCardParams['date'] = $filterDate;
  elseif (!empty($filterMonth)) $baseCardParams['month'] = $filterMonth;
  if (!empty($search)) $baseCardParams['search'] = $search;

  foreach ($statCfg as $s => $cfg): 
    $isActive = ($filterStatus === $s);
    $cardParams = $baseCardParams;
    if (!$isActive) {
        $cardParams['status'] = $s;
    }
    $url = 'appointments.php' . (!empty($cardParams) ? '?' . http_build_query($cardParams) : '');
  ?>
  <a href="<?= $url ?>" class="appt-stat-link <?= $isActive ? 'is-active' : '' ?>" title="<?= $isActive ? 'Clear status filter' : 'Filter by ' . $cfg['label'] ?>">
    <div class="appt-stat-card appt-stat-card--<?= $cfg['type'] ?> <?= $isActive ? 'active' : '' ?>" style="--stat-color: <?= $cfg['color'] ?>; --stat-rgb: <?= $cfg['rgb'] ?>;">
      <div class="appt-stat-glow"></div>
      <div class="appt-stat-icon">
        <i class="fas fa-<?= $cfg['icon'] ?>"></i>
      </div>
      <div class="appt-stat-info">
        <div class="appt-stat-value"><?= number_format($overallStats[$s] ?? 0) ?></div>
        <div class="appt-stat-label"><?= $cfg['label'] ?></div>
      </div>
      <?php if ($isActive): ?>
        <div class="appt-stat-active-badge" title="Active Filter">
          <i class="fas fa-check"></i>
        </div>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Modern Filters Bar -->
<div class="card appt-filter-card" id="apptFilterCard">
  <div class="card-body appt-filter-body">
    <form method="GET" class="appt-filter-form" id="apptFilterForm">
      <!-- Month Filter -->
      <div class="appt-filter-item">
        <label class="form-label appt-filter-label">Month</label>
        <input type="month" id="filterMonthInput" name="month" class="form-control" value="<?= htmlspecialchars($filterMonth) ?>">
      </div>

      <!-- Specific Date Filter -->
      <div class="appt-filter-item">
        <label class="form-label appt-filter-label">Specific Date</label>
        <input type="date" id="filterDateInput" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>">
      </div>

      <!-- Status Filter -->
      <div class="appt-filter-item">
        <label class="form-label appt-filter-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach (['pending','confirmed','completed','cancelled','no_show'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Search Patient -->
      <div class="appt-filter-item appt-filter-search">
        <label class="form-label appt-filter-label">Search Patient</label>
        <input type="text" name="search" class="form-control" placeholder="Patient name..." value="<?= htmlspecialchars($search) ?>">
      </div>

      <!-- Action Buttons -->
      <div class="appt-filter-actions">
        <button type="submit" class="btn btn-primary" title="Apply filters"><i class="fas fa-search"></i> Filter</button>
        <a href="appointments.php?all=1" class="btn btn-secondary" title="Reset all filters"><i class="fas fa-undo"></i></a>
        <a href="appointments.php?date=<?= $today ?>" class="btn btn-outline-primary" title="Filter for Today"><i class="fas fa-calendar-day"></i> Today</a>
        <a href="appointments.php?month=<?= date('Y-m') ?>" class="btn btn-outline-primary" title="Filter for This Month"><i class="fas fa-calendar-alt"></i> This Month</a>
      </div>
    </form>
  </div>
</div>

<!-- View Toggles -->
<div class="appt-ef4f51">
  <div class="btn-group" role="group" aria-label="View Toggle">
    <button type="button" class="btn btn-outline-primary active" id="btnListView">
      <i class="fas fa-list me-1"></i> List View
    </button>
    <button type="button" class="btn btn-outline-primary" id="btnCalView">
      <i class="fas fa-calendar-alt me-1"></i> Calendar View
    </button>
  </div>
</div>

<!-- Calendar View -->
<div id="calendarView"  class="card appt-7eeafe">
  <div  class="card-body appt-32c16d">
    <div id="calendar"></div>
  </div>
</div>

<!-- Appointments Table -->
<div id="listView" class="table-wrapper">
  <div class="appt-823201">
    <span class="appt-9c46bd">
      <i  class="fas fa-calendar-check me-2 appt-b6b6a8"></i>
      <?= $total ?> Appointment<?= $total !== 1 ? 's' : '' ?> Found
    </span>
    <span class="appt-67fd48">
      <?php
      if (!empty($filterDate)) {
          echo '<i class="fas fa-calendar-day me-1"></i> Date: ' . formatDate($filterDate);
      } elseif (!empty($filterMonth)) {
          echo '<i class="fas fa-calendar-alt me-1"></i> Month: ' . date('F Y', strtotime($filterMonth . '-01'));
      } else {
          echo '<i class="fas fa-calendar me-1"></i> All dates';
      }
      ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table">
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
      <tbody>
        <?php if (empty($appts)): ?>
        <tr><td colspan="9">
          <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-calendar"></i></div>
            <h6>No appointments found</h6>
            <p>Try adjusting the filters above</p>
          </div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($appts as $i => $a): ?>
        <tr>
          <td class="appt-67fd48"><?= $pagination['offset'] + $i + 1 ?></td>
          <td>
            <div class="appt-736493"><?= sanitize($a['patient_name']) ?></div>
            <div class="appt-26a4f5"><?= sanitize($a['patient_email']) ?></div>
          </td>
          <td class="appt-0de4e7"><?= sanitize($a['phone'] ?? '—') ?></td>
          <td class="appt-86a1f6"><?= formatDate($a['appointment_date']) ?></td>
          <td class="appt-059a4a"><?= formatTime($a['appointment_time']) ?></td>
          <td class="appt-bb0425"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></td>
          <td class="appt-7a6eb0"><?= sanitize($a['notes'] ?? '—') ?></td>
          <td><?= statusBadge($a['status']) ?></td>
          <td>
            <div class="appt-152c49">
              <?php if ($a['status'] === 'pending'): ?>
              <form method="POST" class="appt-1386d5" onsubmit="return confirmAction(this, 'Confirm this appointment?');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="confirm">
                <button class="btn btn-sm btn-success btn-icon" title="Confirm"><i class="fas fa-check"></i></button>
              </form>
              <?php endif; ?>
              <?php if (in_array($a['status'],['pending','confirmed'])): ?>
              <form method="POST" class="appt-1386d5" onsubmit="return confirmAction(this, 'Mark this appointment as Complete?');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="complete">
                <button class="btn btn-sm btn-primary btn-icon" title="Mark Complete"><i class="fas fa-check-double"></i></button>
              </form>
              <form method="POST" class="appt-1386d5" onsubmit="return confirmAction(this, 'Mark patient as No Show?');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="no_show">
                <button class="btn btn-sm btn-secondary btn-icon" title="No Show"><i class="fas fa-user-times"></i></button>
              </form>
              <form method="POST" class="appt-1386d5" onsubmit="return confirmAction(this, 'Cancel this appointment?');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-sm btn-danger btn-icon" title="Cancel"><?php echo '<i class="fas fa-times"></i>'; ?></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php
  $paginationParams = [];
  if (!empty($filterDate)) $paginationParams['date'] = $filterDate;
  if (!empty($filterMonth)) $paginationParams['month'] = $filterMonth;
  if (!empty($filterStatus)) $paginationParams['status'] = $filterStatus;
  if (!empty($search)) $paginationParams['search'] = $search;

  $buildPageUrl = function($pageNum) use ($paginationParams) {
      $p = $paginationParams;
      $p['page'] = $pageNum;
      return '?' . http_build_query($p);
  };
  ?>
  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="appt-3eb868">
    <div class="pagination">
      <?php if ($pagination['has_prev']): ?>
      <a href="<?= $buildPageUrl($page - 1) ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
      <a href="<?= $buildPageUrl($p) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($pagination['has_next']): ?>
      <a href="<?= $buildPageUrl($page + 1) ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Appointment Details Modal -->
<div class="modal fade" id="apptDetailsModal" tabindex="-1" aria-hidden="true">
  <div  class="modal-dialog modal-dialog-centered appt-17d584">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-calendar-check me-2 text-primary"></i>Appointment Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="text-muted small">Patient Name</label>
          <div id="modalPatientName" class="fw-bold fs-5"></div>
        </div>
        <div class="row mb-3">
          <div class="col-6">
            <label class="text-muted small">Date & Time</label>
            <div id="modalDateTime" class="fw-semibold"></div>
          </div>
          <div class="col-6">
            <label class="text-muted small">Contact</label>
            <div id="modalContact" class="fw-semibold"></div>
          </div>
        </div>
        <div class="row mb-3">
          <div class="col-6">
            <label class="text-muted small">Purpose</label>
            <div id="modalPurpose" class="fw-semibold"></div>
          </div>
          <div class="col-6">
            <label class="text-muted small">Status</label>
            <div id="modalStatusContainer"></div>
          </div>
        </div>
        <div class="mb-3">
          <label class="text-muted small">Notes</label>
          <div id="modalNotes"  class="bg-light p-2 rounded text-muted appt-d4357b"></div>
        </div>

        <hr>
        <div id="modalActions" class="appt-d2254c">
          <!-- Action buttons will be injected here via JS -->
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- FullCalendar Library -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Custom confirmation popup using SweetAlert2
function confirmAction(form, message) {
  Swal.fire({
    title: 'Are you sure?',
    text: message,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: 'var(--clr-primary)',
    cancelButtonColor: '#475569',
    confirmButtonText: 'Yes, proceed',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)',
    customClass: {
      popup: 'rounded-4'
    }
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit();
    }
  });
  return false; // Prevent default form submission
}

document.addEventListener('DOMContentLoaded', function() {
  // Auto-sync Month & Specific Date inputs (choosing one clears the other)
  const monthInput = document.getElementById('filterMonthInput');
  const dateInput = document.getElementById('filterDateInput');
  if (monthInput && dateInput) {
    monthInput.addEventListener('change', function() {
      if (this.value) dateInput.value = '';
    });
    dateInput.addEventListener('change', function() {
      if (this.value) monthInput.value = '';
    });
  }

  const btnListView = document.getElementById('btnListView');
  const btnCalView = document.getElementById('btnCalView');
  const listView = document.getElementById('listView');
  const calendarView = document.getElementById('calendarView');
  const filterCard = document.getElementById('apptFilterCard');
  let calendar = null;

  // Restore preferred view from localStorage
  const activeView = localStorage.getItem('appointments_view') || 'list';
  if (activeView === 'calendar') {
    btnCalView.classList.add('active');
    btnListView.classList.remove('active');
    listView.style.display = 'none';
    calendarView.style.display = 'block';
    if (filterCard) filterCard.style.display = 'none';
    setTimeout(() => { initCalendar(); }, 100);
  } else {
    btnListView.classList.add('active');
    btnCalView.classList.remove('active');
    listView.style.display = 'block';
    calendarView.style.display = 'none';
    if (filterCard) filterCard.style.display = 'block';
  }

  const csrfToken = <?= json_encode(generateCsrfToken()) ?>;

  function buildModalActions(apptId, status) {
    let html = '';
    
    if (status === 'pending') {
      html += `<form method="POST" class="m-0" onsubmit="return confirmAction(this, 'Confirm this appointment?');"><input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="appt_id" value="${apptId}"><input type="hidden" name="action" value="confirm"><button class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i> Confirm</button></form>`;
    }
    if (status === 'pending' || status === 'confirmed') {
      html += `<form method="POST" class="m-0" onsubmit="return confirmAction(this, 'Mark this appointment as Complete?');"><input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="appt_id" value="${apptId}"><input type="hidden" name="action" value="complete"><button class="btn btn-sm btn-primary"><i class="fas fa-check-double me-1"></i> Complete</button></form>`;
      html += `<form method="POST" class="m-0" onsubmit="return confirmAction(this, 'Mark patient as No Show?');"><input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="appt_id" value="${apptId}"><input type="hidden" name="action" value="no_show"><button class="btn btn-sm btn-secondary"><i class="fas fa-user-times me-1"></i> No Show</button></form>`;
      html += `<form method="POST" class="m-0" onsubmit="return confirmAction(this, 'Cancel this appointment?');"><input type="hidden" name="csrf_token" value="${csrfToken}"><input type="hidden" name="appt_id" value="${apptId}"><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i> Cancel</button></form>`;
    }
    
    return html;
  }

  function initCalendar() {
    const calendarEl = document.getElementById('calendar');
    calendar = new FullCalendar.Calendar(calendarEl, {
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay'
      },
      eventDisplay: 'block', // Force all events to be colored blocks (pills)
      events: '../api/get_calendar_events.php',
      
      // Custom HTML Rendering for Events
      eventContent: function(arg) {
        const props = arg.event.extendedProps;
        // In some views (like month), time might not be fully formatted by FullCalendar if all-day, 
        // but we already have our formatted time from the API!
        const timeStr = props.time_formatted || '';
        const name = props.patient_name;
        const statusRaw = props.status || '';
        const statusStr = statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1).replace('_', ' ');
        
        // FullCalendar sets this to our #HEX color from the DB
        const color = arg.event.backgroundColor;

        let html = `
          <div class="custom-event-card" style="border-left-color: ${color}; border-left-width: 4px; border-left-style: solid;">
            <div class="custom-event-top">
                <div class="appt-745460">
                    <i class="far fa-clock"></i> ${timeStr}
                </div>
                <div style="background-color: ${color}20; color: ${color}; font-size: 0.65rem; font-weight: 700; padding: 2px 6px; border-radius: 10px; line-height: 1;">
                    ${statusStr}
                </div>
            </div>
            <div class="appt-2760e6">
                ${name}
            </div>
          </div>
        `;
        return { html: html };
      },

      eventClick: function(info) {
        const props = info.event.extendedProps;
        const apptId = info.event.id;
        
        // Populate Modal
        document.getElementById('modalPatientName').textContent = props.patient_name;
        document.getElementById('modalDateTime').textContent = props.date_formatted + ' at ' + props.time_formatted;
        document.getElementById('modalContact').textContent = props.phone || '—';
        
        // Format purpose
        const purpose = props.purpose.replace(/_/g, ' ');
        document.getElementById('modalPurpose').textContent = purpose.charAt(0).toUpperCase() + purpose.slice(1);
        
        // Set Status Badge manually since we don't have the PHP function in JS
        let badgeClass = 'secondary';
        let icon = 'question-circle';
        if(props.status==='pending'){ badgeClass='warning'; icon='clock'; }
        else if(props.status==='confirmed'){ badgeClass='info'; icon='check-circle'; }
        else if(props.status==='completed'){ badgeClass='success'; icon='check-double'; }
        else if(props.status==='cancelled'){ badgeClass='danger'; icon='times-circle'; }
        else if(props.status==='no_show'){ badgeClass='secondary'; icon='user-times'; }
        
        const statusLabel = props.status.replace(/_/g, ' ');
        document.getElementById('modalStatusContainer').innerHTML = `<span class="badge bg-${badgeClass}"><i class="fas fa-${icon} me-1"></i>${statusLabel.charAt(0).toUpperCase() + statusLabel.slice(1)}</span>`;
        
        document.getElementById('modalNotes').textContent = props.notes || 'No notes provided.';
        
        // Inject Actions
        document.getElementById('modalActions').innerHTML = buildModalActions(apptId, props.status);
        
        // Show Modal
        const myModal = new bootstrap.Modal(document.getElementById('apptDetailsModal'));
        myModal.show();
      }
    });
    calendar.render();
  }

  btnListView.addEventListener('click', () => {
    localStorage.setItem('appointments_view', 'list');
    btnListView.classList.add('active');
    btnCalView.classList.remove('active');
    listView.style.display = 'block';
    calendarView.style.display = 'none';
    if (filterCard) filterCard.style.display = 'block';
  });

  btnCalView.addEventListener('click', () => {
    localStorage.setItem('appointments_view', 'calendar');
    btnCalView.classList.add('active');
    btnListView.classList.remove('active');
    listView.style.display = 'none';
    calendarView.style.display = 'block';
    if (filterCard) filterCard.style.display = 'none';
    
    if (!calendar) {
      initCalendar();
    } else {
      calendar.render(); // Ensure it resizes correctly when unhidden
    }
  });

});
</script>

