<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requirePatientLogin();

$db        = getDB();
$patientId = $_SESSION['patient_id'];
$today     = date('Y-m-d');

// Flash message
$flashMsg = $flashType = '';
if (isset($_SESSION['flash_msg'])) {
    $flashMsg  = $_SESSION['flash_msg'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$bookingError = $bookingSuccess = '';

// Book appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    $apptDate = $_POST['appointment_date'] ?? '';
    $apptTime = $_POST['appointment_time'] ?? '';
    $purpose  = $_POST['purpose'] ?? '';
    $notes    = sanitize($_POST['notes'] ?? '');

    if (empty($apptDate) || empty($apptTime) || empty($purpose)) {
        $bookingError = 'Please complete all required fields.';
    } elseif ($apptDate <= $today) {
        $bookingError = 'Please select a future date (tomorrow or later).';
    } else {
        $dayOfWeek = date('N', strtotime($apptDate)); // 6 = Saturday, 7 = Sunday
        if ($dayOfWeek >= 6) {
            $bookingError = 'We are closed on weekends (Saturday & Sunday). Please choose a weekday.';
        } else {
            $check = $db->prepare("SELECT id FROM appointments WHERE appointment_date=? AND appointment_time=? AND status NOT IN ('cancelled','no_show')");
            $check->execute([$apptDate, $apptTime]);
            if ($check->fetch()) {
                $bookingError = 'This time slot is already taken. Please choose another.';
            } else {
                $db->prepare("INSERT INTO appointments (patient_id,appointment_date,appointment_time,purpose,notes,status) VALUES (?,?,?,?,?,'pending')")->execute([$patientId,$apptDate,$apptTime,$purpose,$notes]);
                $bookingSuccess = 'Appointment booked successfully! Our staff will confirm it shortly.';
            }
        }
    }
}

// Cancel appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $apptId = (int)($_POST['appt_id'] ?? 0);
    $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=? AND patient_id=? AND status IN ('pending','confirmed')")->execute([$apptId, $patientId]);
    $bookingSuccess = 'Appointment cancelled.';
}

// Edit appointment — PENDING ONLY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $apptId   = (int)($_POST['appt_id'] ?? 0);
    $apptDate = $_POST['appointment_date'] ?? '';
    $apptTime = $_POST['appointment_time'] ?? '';
    $purpose  = $_POST['purpose'] ?? '';
    $notes    = sanitize($_POST['notes'] ?? '');

    // Verify appointment is still pending and belongs to this patient
    $chk = $db->prepare("SELECT id FROM appointments WHERE id=? AND patient_id=? AND status='pending'");
    $chk->execute([$apptId, $patientId]);
    if (!$chk->fetch()) {
        $bookingError = 'This appointment can no longer be edited. The clinic may have already confirmed it.';
    } elseif (empty($apptDate) || empty($apptTime) || empty($purpose)) {
        $bookingError = 'Please fill in all required fields.';
    } elseif ($apptDate <= $today) {
        $bookingError = 'Please select a future date.';
    } else {
        // Check slot (exclude this appointment's existing slot)
        $slotChk = $db->prepare("SELECT id FROM appointments WHERE appointment_date=? AND appointment_time=? AND id!=? AND status NOT IN ('cancelled','no_show')");
        $slotChk->execute([$apptDate, $apptTime, $apptId]);
        if ($slotChk->fetch()) {
            $bookingError = 'That time slot is already taken. Please pick another.';
        } else {
            $db->prepare("UPDATE appointments SET appointment_date=?,appointment_time=?,purpose=?,notes=? WHERE id=? AND patient_id=? AND status='pending'")
               ->execute([$apptDate, $apptTime, $purpose, $notes, $apptId, $patientId]);
            $bookingSuccess = 'Appointment updated successfully!';
        }
    }
    // Re-open history tab after edit
    $_SESSION['open_tab'] = 'history';
}

// AJAX: check taken slots
if (isset($_GET['check_date'])) {
    $check = $db->prepare("SELECT appointment_time FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show')");
    $check->execute([$_GET['check_date']]);
    header('Content-Type: application/json');
    echo json_encode($check->fetchAll(PDO::FETCH_COLUMN));
    exit;
}

// Data
$allSlots = explode(',', getSetting('appointment_slots') ?? '09:00,09:30,10:00,10:30,11:00,11:30,13:00,13:30,14:00,14:30,15:00,15:30,16:00,16:30');

$myAppts = $db->prepare("SELECT * FROM appointments WHERE patient_id=? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 30");
$myAppts->execute([$patientId]); $myAppts = $myAppts->fetchAll();

$upcoming = array_filter($myAppts, fn($a) => $a['appointment_date'] >= $today && !in_array($a['status'],['cancelled','no_show']));
$past     = array_filter($myAppts, fn($a) => $a['appointment_date'] < $today || in_array($a['status'],['cancelled','no_show']));

$nextAppt = reset($upcoming) ?: null;

// Patient info
$patient = $db->prepare("SELECT * FROM patients WHERE id=?"); $patient->execute([$patientId]); $patient = $patient->fetch();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Patient Portal — Gueco Optical Clinic</title>
  <meta name="description" content="Book and manage your appointments at Gueco Optical Clinic, Capas, Tarlac.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

    :root { --clr-primary:#2563EB; --clr-secondary:#7C3AED; --clr-success:#059669; --clr-danger:#DC2626; --clr-warning:#D97706; --clr-info:#0EA5E9; }

    [data-theme="dark"]{
      --bg-body:#0F172A; --bg-card:rgba(30,41,59,.8); --bg-hover:rgba(255,255,255,.04);
      --text-primary:#F1F5F9; --text-secondary:#CBD5E1; --text-muted:#64748B;
      --border-color:rgba(255,255,255,.08); --border-light:rgba(255,255,255,.05);
      --shadow-md:0 8px 30px rgba(0,0,0,.35);
    }
    [data-theme="light"]{
      --bg-body:#F0F4FF; --bg-card:rgba(255,255,255,.9); --bg-hover:rgba(0,0,0,.03);
      --text-primary:#0F172A; --text-secondary:#334155; --text-muted:#94A3B8;
      --border-color:rgba(0,0,0,.07); --border-light:rgba(0,0,0,.04);
      --shadow-md:0 8px 30px rgba(37,99,235,.08);
    }

    body { font-family:'Poppins',sans-serif; background:var(--bg-body); min-height:100vh; font-size:16px; }

    /* BG */
    .bg-mesh {
      position:fixed; inset:0; z-index:0; pointer-events:none;
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(37,99,235,.2) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(124,58,237,.15) 0%, transparent 50%);
    }
    [data-theme="light"] .bg-mesh {
      background:
        radial-gradient(ellipse 70% 60% at 5% 0%, rgba(37,99,235,.1) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 95% 100%, rgba(124,58,237,.08) 0%, transparent 50%);
    }

    /* TOPBAR */
    .topbar {
      position:sticky; top:0; z-index:200;
      background:rgba(15,23,42,.85); backdrop-filter:blur(20px);
      border-bottom:1px solid var(--border-color);
      display:flex; align-items:center; justify-content:space-between;
      padding:0 28px; height:64px;
    }
    [data-theme="light"] .topbar { background:rgba(240,244,255,.9); }
    .topbar-brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
    .topbar-logo {
      width:42px; height:42px; object-fit:contain; border-radius:8px;
    }
    .topbar-name { font-weight:700; font-size:1.05rem; color:var(--text-primary); }
    .topbar-sub  { font-size:.75rem; color:var(--text-muted); }
    .topbar-right { display:flex; align-items:center; gap:10px; }
    .theme-btn {
      width:36px; height:36px; border-radius:50%; border:1px solid var(--border-color);
      background:var(--bg-hover); color:var(--text-muted); cursor:pointer;
      display:flex; align-items:center; justify-content:center; font-size:.82rem;
      transition:all .2s;
    }
    .theme-btn:hover { border-color:var(--clr-primary); color:var(--clr-primary); }
    .user-chip {
      display:flex; align-items:center; gap:8px;
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:100px; padding:5px 14px 5px 5px;
    }
    .user-avatar {
      width:28px; height:28px; border-radius:50%;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      display:flex; align-items:center; justify-content:center;
      color:#fff; font-weight:700; font-size:.72rem;
    }
    .user-name { font-size:.88rem; font-weight:600; color:var(--text-primary); }
    .logout-btn {
      width:32px; height:32px; border-radius:8px; border:1px solid var(--border-color);
      background:var(--bg-hover); color:var(--text-muted); cursor:pointer;
      display:flex; align-items:center; justify-content:center; font-size:.75rem;
      text-decoration:none; transition:all .2s;
    }
    .logout-btn:hover { border-color:var(--clr-danger); color:var(--clr-danger); }
    .user-dropdown { position:relative; }
    .user-chip {
      cursor:pointer; transition:all .2s;
    }
    .user-chip:hover { border-color:var(--clr-primary); }
    .user-dropdown-menu {
      position:absolute; top:calc(100% + 10px); right:0; background:var(--bg-card);
      border:1px solid var(--border-color); border-radius:12px;
      box-shadow:0 12px 30px rgba(0,0,0,.25); width:210px; padding:8px;
      display:flex; flex-direction:column; gap:4px;
      opacity:0; visibility:hidden; transform:translateY(-10px);
      transition:all .2s; z-index:100;
    }
    .user-dropdown.open .user-dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
    .dropdown-item {
      padding:10px 14px; border-radius:8px; display:flex; align-items:center; gap:12px;
      color:var(--text-primary); text-decoration:none; font-size:.88rem; font-weight:500;
      background:none; border:none; width:100%; text-align:left; cursor:pointer; transition:background .2s;
    }
    .dropdown-item i { font-size:1.1rem; opacity:.7; width:20px; text-align:center; }
    .dropdown-item:hover { background:var(--bg-hover); color:var(--clr-primary); }
    .dropdown-item.danger:hover { background:rgba(220,38,38,.1); color:#F87171; }

    /* TABS */
    .tab-bar {
      display:flex; gap:4px;
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:14px; padding:4px;
      width:fit-content;
    }
    .tab-btn {
      padding:10px 24px; border-radius:11px; border:none;
      font-family:'Poppins',sans-serif; font-size:.92rem; font-weight:600;
      color:var(--text-muted); background:none; cursor:pointer; transition:all .2s;
      display:flex; align-items:center; gap:7px; white-space:nowrap;
    }
    .tab-btn.active {
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; box-shadow:0 4px 14px rgba(37,99,235,.35);
    }
    .tab-btn:hover:not(.active) { color:var(--text-primary); background:var(--bg-hover); }

    /* PANELS */
    .tab-panel { display:none; }
    .tab-panel.active { display:block; animation:panelIn .25s ease; }
    @keyframes panelIn { from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;} }

    /* MAIN WRAP */
    .page-wrap { position:relative; z-index:1; max-width:1100px; margin:0 auto; padding:28px 20px 60px; }

    /* NEXT APPT BANNER */
    .banner-card {
      background:linear-gradient(135deg,#1D4ED8,#7C3AED);
      border-radius:20px; padding:26px 30px;
      display:flex; align-items:center; justify-content:space-between;
      flex-wrap:wrap; gap:16px; margin-bottom:28px;
      position:relative; overflow:hidden;
    }
    .banner-card::before {
      content:''; position:absolute; inset:0;
      background:url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='80' cy='20' r='60' fill='none' stroke='rgba(255,255,255,.07)' stroke-width='40'/%3E%3C/svg%3E");
      background-size:cover; pointer-events:none;
    }
    .banner-icon {
      width:52px; height:52px; background:rgba(255,255,255,.15);
      border-radius:14px; display:flex; align-items:center; justify-content:center;
      font-size:1.3rem; color:#fff; flex-shrink:0; position:relative;
    }
    .banner-info { flex:1; position:relative; }
    .banner-label { font-size:.8rem; color:rgba(255,255,255,.7); text-transform:uppercase; letter-spacing:.08em; margin-bottom:4px; }
    .banner-date  { font-size:1.3rem; font-weight:800; color:#fff; margin-bottom:3px; }
    .banner-purpose { font-size:.92rem; color:rgba(255,255,255,.8); }
    .banner-status {
      background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3);
      border-radius:100px; padding:8px 20px; color:#fff;
      font-size:.8rem; font-weight:700; flex-shrink:0; position:relative;
    }

    /* STAT PILLS */
    .stats-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:28px; }
    .stat-pill {
      display:flex; align-items:center; gap:10px;
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:14px; padding:14px 18px; flex:1; min-width:130px;
      transition:all .2s;
    }
    .stat-pill:hover { border-color:rgba(37,99,235,.25); box-shadow:var(--shadow-md); }
    .stat-pill-icon {
      width:38px; height:38px; border-radius:10px;
      display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0;
    }
    .stat-pill-icon.blue   { background:rgba(37,99,235,.12); color:#60A5FA; }
    .stat-pill-icon.green  { background:rgba(5,150,105,.12);  color:#34D399; }
    .stat-pill-icon.orange { background:rgba(217,119,6,.12);  color:#FCD34D; }
    .stat-pill-icon.purple { background:rgba(124,58,237,.12); color:#A78BFA; }
    .stat-pill-val  { font-size:1.5rem; font-weight:800; color:var(--text-primary); line-height:1; }
    .stat-pill-lbl  { font-size:.78rem; color:var(--text-muted); margin-top:2px; }

    /* BOOKING FORM */
    .form-card {
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:20px; overflow:hidden;
    }
    .form-card-header {
      padding:20px 24px; border-bottom:1px solid var(--border-light);
      background:linear-gradient(135deg,rgba(37,99,235,.06),rgba(124,58,237,.04));
      display:flex; align-items:center; gap:12px;
    }
    .form-card-icon {
      width:42px; height:42px; border-radius:12px;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      display:flex; align-items:center; justify-content:center;
      color:#fff; font-size:.95rem;
      box-shadow:0 4px 14px rgba(37,99,235,.3);
    }
    .form-card-title { font-size:1.1rem; font-weight:700; color:var(--text-primary); }
    .form-card-sub   { font-size:.82rem; color:var(--text-muted); }
    .form-card-body  { padding:24px; }

    /* Steps indicator */
    .steps {
      display:flex; gap:0; margin-bottom:28px;
      background:var(--bg-hover); border-radius:12px; padding:4px;
    }
    .step {
      flex:1; text-align:center; padding:10px 8px; border-radius:9px;
      font-size:.82rem; font-weight:600; color:var(--text-muted);
      transition:all .2s; cursor:default;
      display:flex; align-items:center; justify-content:center; gap:6px;
    }
    .step.done    { color:var(--clr-success); }
    .step.active  { background:var(--bg-card); color:var(--clr-primary); box-shadow:0 2px 8px rgba(0,0,0,.12); }
    .step-num {
      width:20px; height:20px; border-radius:50%; font-size:.65rem; font-weight:800;
      display:flex; align-items:center; justify-content:center; flex-shrink:0;
      background:var(--bg-hover); color:var(--text-muted);
    }
    .step.active .step-num  { background:var(--clr-primary); color:#fff; }
    .step.done   .step-num  { background:var(--clr-success); color:#fff; }

    /* Form fields */
    .field-group { margin-bottom:20px; }
    .field-label {
      display:block; font-size:.82rem; font-weight:700;
      text-transform:uppercase; letter-spacing:.06em;
      color:var(--text-muted); margin-bottom:8px;
    }
    .field-control {
      width:100%; padding:14px 16px;
      background:var(--bg-hover) !important; border:1.5px solid var(--border-color);
      border-radius:12px; font-family:'Poppins',sans-serif; font-size:.95rem;
      color:var(--text-primary) !important; outline:none; transition:border-color .2s, box-shadow .2s;
    }
    .field-control:focus { border-color:var(--clr-primary); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
    .field-control::placeholder { color:var(--text-muted); }
    select.field-control { cursor:pointer; }
    textarea.field-control { resize:vertical; min-height:80px; }

    /* Date quick-picks */
    .quick-dates { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
    .qdate-btn {
      padding:7px 14px; border-radius:100px; border:1.5px solid var(--border-color);
      background:var(--bg-hover); color:var(--text-muted);
      font-family:'Poppins',sans-serif; font-size:.82rem; font-weight:600;
      cursor:pointer; transition:all .2s; white-space:nowrap;
    }
    .qdate-btn:hover { border-color:var(--clr-primary); color:var(--clr-primary); background:rgba(37,99,235,.06); }
    .qdate-btn.active { border-color:var(--clr-primary); background:rgba(37,99,235,.12); color:var(--clr-primary); }

    /* Time slot grid */
    .slot-section-title {
      font-size:.7rem; font-weight:700; text-transform:uppercase;
      letter-spacing:.08em; color:var(--text-muted); margin:16px 0 10px;
      display:flex; align-items:center; gap:6px;
    }
    .slot-section-title::after { content:''; flex:1; height:1px; background:var(--border-light); }
    .slot-grid {
      display:grid; grid-template-columns:repeat(auto-fill,minmax(86px,1fr)); gap:8px;
    }
    .slot-btn {
      padding:11px 6px; border-radius:11px;
      border:1.5px solid var(--border-color);
      background:var(--bg-hover); color:var(--text-secondary);
      font-family:'Poppins',sans-serif; font-size:.85rem; font-weight:600;
      cursor:pointer; transition:all .15s ease; text-align:center; line-height:1.2;
    }
    .slot-btn .slot-period { font-size:.6rem; color:var(--text-muted); display:block; margin-top:2px; }
    .slot-btn:hover:not(:disabled) { border-color:var(--clr-primary); color:var(--clr-primary); background:rgba(37,99,235,.07); transform:translateY(-1px); }
    .slot-btn.selected { background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); color:#fff; border-color:transparent; box-shadow:0 4px 14px rgba(37,99,235,.35); transform:translateY(-1px); }
    .slot-btn.selected .slot-period { color:rgba(255,255,255,.75); }
    .slot-btn:disabled { opacity:.35; cursor:not-allowed; text-decoration:line-through; }
    .slot-placeholder {
      grid-column:1/-1; text-align:center; padding:30px 20px;
      color:var(--text-muted); font-size:.8rem;
    }
    .slot-placeholder i { font-size:2rem; display:block; margin-bottom:10px; opacity:.3; }

    /* Clinic info */
    .info-box {
      background:rgba(37,99,235,.07); border:1px solid rgba(37,99,235,.15);
      border-radius:12px; padding:14px 16px; font-size:.77rem;
      color:var(--text-secondary); line-height:1.7; margin-bottom:20px;
      display:flex; gap:10px; align-items:flex-start;
    }
    .info-box i { color:var(--clr-primary); margin-top:2px; flex-shrink:0; }

    /* Submit */
    .btn-book {
      width:100%; padding:14px; border:none; border-radius:13px;
      background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));
      color:#fff; font-family:'Poppins',sans-serif; font-size:.9rem; font-weight:700;
      cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:8px;
      box-shadow:0 4px 18px rgba(37,99,235,.35);
    }
    .btn-book:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 8px 28px rgba(37,99,235,.45); }
    .btn-book:disabled { opacity:.5; cursor:not-allowed; transform:none; }

    /* Alert */
    .alert {
      border-radius:12px; padding:14px 18px; margin-bottom:18px;
      display:flex; align-items:center; gap:10px; font-size:.92rem; font-weight:500;
      animation:alertIn .3s ease;
    }
    @keyframes alertIn { from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:none;} }
    .alert-success { background:rgba(5,150,105,.1); border:1px solid rgba(5,150,105,.25); color:#34D399; }
    .alert-danger  { background:rgba(220,38,38,.1);  border:1px solid rgba(220,38,38,.25);  color:#F87171; }

    /* Appointment list */
    .appt-item {
      background:var(--bg-card); border:1px solid var(--border-color);
      border-radius:16px; padding:18px 20px; margin-bottom:10px;
      display:flex; align-items:center; gap:16px; transition:all .2s;
      position:relative; overflow:hidden;
    }
    .appt-item:hover { box-shadow:var(--shadow-md); border-color:rgba(37,99,235,.2); }
    .appt-item.upcoming { border-left:4px solid var(--clr-primary); }
    .appt-item.cancelled { opacity:.6; border-left:4px solid var(--text-muted); }
    .appt-date-box {
      text-align:center; min-width:52px; flex-shrink:0;
    }
    .appt-day   { font-size:1.7rem; font-weight:900; color:var(--text-primary); line-height:1; }
    .appt-month { font-size:.75rem; text-transform:uppercase; font-weight:700; color:var(--text-muted); letter-spacing:.05em; }
    .appt-divider { width:1px; height:44px; background:var(--border-color); flex-shrink:0; }
    .appt-info  { flex:1; overflow:hidden; }
    .appt-time  { font-size:.95rem; font-weight:700; color:var(--text-primary); margin-bottom:3px; }
    .appt-purpose { font-size:.88rem; color:var(--text-muted); }
    .appt-notes { font-size:.82rem; color:var(--text-muted); font-style:italic; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .status-badge {
      padding:5px 14px; border-radius:100px; font-size:.78rem; font-weight:700; flex-shrink:0;
      text-transform:capitalize;
    }
    .status-pending   { background:rgba(217,119,6,.12);  color:#FCD34D; }
    .status-confirmed { background:rgba(37,99,235,.12);  color:#60A5FA; }
    .status-completed { background:rgba(5,150,105,.12);  color:#34D399; }
    .status-cancelled { background:rgba(100,116,139,.12);color:#94A3B8; }
    .status-no_show   { background:rgba(220,38,38,.12);  color:#F87171; }

    .cancel-btn {
      background:none; border:1px solid rgba(220,38,38,.25); color:#F87171;
      border-radius:8px; padding:6px 14px; font-family:'Poppins',sans-serif;
      font-size:.8rem; font-weight:600; cursor:pointer; transition:all .2s; flex-shrink:0;
    }
    .cancel-btn:hover { background:rgba(220,38,38,.1); border-color:rgba(220,38,38,.5); }

    .empty-state {
      text-align:center; padding:50px 20px;
      background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px;
    }
    .empty-state i { font-size:2.5rem; opacity:.2; display:block; margin-bottom:12px; color:var(--text-primary); }
    .empty-state p { font-size:.95rem; color:var(--text-muted); margin:0; }

    /* Purpose cards */
    .purpose-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .purpose-card {
      padding:14px; border-radius:12px; border:1.5px solid var(--border-color);
      background:var(--bg-hover); cursor:pointer; transition:all .2s; text-align:center;
    }
    .purpose-card:hover { border-color:var(--clr-primary); background:rgba(37,99,235,.05); }
    .purpose-card.selected { border-color:var(--clr-primary); background:rgba(37,99,235,.1); }
    .purpose-card i { font-size:1.4rem; display:block; margin-bottom:6px; }
    .purpose-card.selected i { color:var(--clr-primary); }
    .purpose-card span { font-size:.82rem; font-weight:600; color:var(--text-secondary); }
    .purpose-card.selected span { color:var(--clr-primary); }
  </style>
</head>
<body>
<div class="bg-mesh"></div>

<!-- TOPBAR -->
<nav class="topbar">
  <a href="dashboard.php" class="topbar-brand">
    <img src="../assets/images/logo.png?v=2" alt="Logo" class="topbar-logo">
    <div>
      <div class="topbar-name">Gueco Optical Clinic</div>
      <div class="topbar-sub">Patient Portal · Capas, Tarlac</div>
    </div>
  </a>
  <div class="topbar-right">
    <button class="theme-btn" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-chip" onclick="toggleDropdown()">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['patient_name'],0,1)) ?></div>
        <span class="user-name"><?= sanitize(explode(' ',$_SESSION['patient_name'])[0]) ?></span>
        <i class="fas fa-chevron-down" style="font-size:.75rem;color:var(--text-muted);margin-left:4px;"></i>
      </div>
      <div class="user-dropdown-menu">
        <a href="settings.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Profile Settings</a>
        <a href="settings.php#password" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
        <div style="height:1px;background:var(--border-color);margin:4px 0;"></div>
        <a href="logout.php" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">

  <!-- Alerts -->
  <?php if ($flashMsg): ?>
  <div class="alert alert-<?= $flashType ?>"><i class="fas fa-check-circle"></i> <?= sanitize($flashMsg) ?></div>
  <?php endif; ?>
  <?php if ($bookingError): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($bookingError) ?></div>
  <?php endif; ?>
  <?php if ($bookingSuccess): ?>
  <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($bookingSuccess) ?></div>
  <?php endif; ?>

  <!-- Next Appointment Banner -->
  <?php if ($nextAppt): ?>
  <div class="banner-card" style="margin-bottom:24px;">
    <div class="banner-icon"><i class="fas fa-calendar-check"></i></div>
    <div class="banner-info" style="margin-left:16px;">
      <div class="banner-label">Your Next Appointment</div>
      <div class="banner-date"><?= formatDate($nextAppt['appointment_date']) ?> &nbsp;·&nbsp; <?= formatTime($nextAppt['appointment_time']) ?></div>
      <div class="banner-purpose"><?= ucwords(str_replace('_',' ',$nextAppt['purpose'])) ?></div>
    </div>
    <div class="banner-status"><?= ucfirst($nextAppt['status']) ?></div>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <?php
    $totalAppts     = count($myAppts);
    $upcomingCount  = count($upcoming);
    $completedCount = count(array_filter($myAppts, fn($a) => $a['status']==='completed'));
    $pendingCount   = count(array_filter($myAppts, fn($a) => $a['status']==='pending'));
    ?>
    <div class="stat-pill">
      <div class="stat-pill-icon blue"><i class="fas fa-calendar-alt"></i></div>
      <div><div class="stat-pill-val"><?= $totalAppts ?></div><div class="stat-pill-lbl">Total Bookings</div></div>
    </div>
    <div class="stat-pill">
      <div class="stat-pill-icon orange"><i class="fas fa-clock"></i></div>
      <div><div class="stat-pill-val"><?= $upcomingCount ?></div><div class="stat-pill-lbl">Upcoming</div></div>
    </div>
    <div class="stat-pill">
      <div class="stat-pill-icon green"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-pill-val"><?= $completedCount ?></div><div class="stat-pill-lbl">Completed</div></div>
    </div>
    <div class="stat-pill">
      <div class="stat-pill-icon purple"><i class="fas fa-hourglass-half"></i></div>
      <div><div class="stat-pill-val"><?= $pendingCount ?></div><div class="stat-pill-lbl">Pending</div></div>
    </div>
  </div>

  <!-- TABS -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('book',this)" id="tab-book">
        <i class="fas fa-calendar-plus"></i> Book Appointment
      </button>
      <button class="tab-btn" onclick="switchTab('history',this)" id="tab-history">
        <i class="fas fa-history"></i> My Appointments
        <?php if ($upcomingCount > 0): ?><span style="background:var(--clr-primary);color:#fff;border-radius:100px;padding:1px 7px;font-size:.65rem;margin-left:2px;"><?= $upcomingCount ?></span><?php endif; ?>
      </button>
    </div>
  </div>

  <!-- TAB: BOOK -->
  <div class="tab-panel active" id="panel-book">
    <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

      <!-- Booking Form -->
      <div class="form-card">
        <div class="form-card-header">
          <div class="form-card-icon"><i class="fas fa-calendar-plus"></i></div>
          <div>
            <div class="form-card-title">Book an Appointment</div>
            <div class="form-card-sub">Select date, time slot, and purpose of visit</div>
          </div>
        </div>
        <div class="form-card-body">

          <!-- Step indicator -->
          <div class="steps" id="stepsBar">
            <div class="step active" id="step1"><div class="step-num">1</div> Choose Date</div>
            <div class="step" id="step2"><div class="step-num">2</div> Pick Time</div>
            <div class="step" id="step3"><div class="step-num">3</div> Purpose</div>
            <div class="step" id="step4"><div class="step-num">4</div> Confirm</div>
          </div>

          <form method="POST" id="bookingForm">
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="appointment_time" id="selectedTime">

            <!-- Step 1: Date -->
            <div class="field-group">
              <label class="field-label"><i class="fas fa-calendar me-1"></i>Appointment Date <span style="color:var(--clr-danger)">*</span></label>
              <!-- Quick picks -->
              <div class="quick-dates" id="quickDates">
                <?php
                for ($d = 1; $d <= 10; $d++) {
                    $ts = strtotime("+$d day");
                    $dow = date('N', $ts);
                    if ($dow >= 6) continue; // skip saturday & sunday
                    echo '<button type="button" class="qdate-btn" data-date="'.date('Y-m-d',$ts).'">'.date('D, M j',$ts).'</button>';
                    if (count(array_filter(range(1,$d), fn($i) => date('N',strtotime("+$i day")) < 6)) >= 5) break;
                }
                ?>
              </div>
              <input type="date" id="apptDate" name="appointment_date"
                     class="field-control"
                     min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                     required>
              <div id="weekendWarning" style="display:none;"></div>
              <div style="font-size:.8rem;color:var(--text-muted);margin-top:6px;">
                <i class="fas fa-info-circle me-1"></i>Clinic is open Monday to Friday, 9:00 AM – 5:00 PM
              </div>
            </div>

            <!-- Step 2: Time -->
            <div class="field-group">
              <label class="field-label"><i class="fas fa-clock me-1"></i>Time Slot <span style="color:var(--clr-danger)">*</span></label>
              <div id="slotGrid">
                <div class="slot-placeholder">
                  <i class="fas fa-calendar-day"></i>
                  Select a date to see available time slots
                </div>
              </div>
            </div>

            <!-- Step 3: Purpose -->
            <div class="field-group">
              <label class="field-label"><i class="fas fa-clipboard-list me-1"></i>Purpose of Visit <span style="color:var(--clr-danger)">*</span></label>
              <input type="hidden" name="purpose" id="purposeInput" required>
              <div class="purpose-grid" id="purposeGrid">
                <?php
                $purposes = [
                    ['consultation','fa-eye','Eye Consultation / Check-up'],
                    ['eyeglass_claim','fa-glasses','Eyeglass Claim / Pickup'],
                    ['contact_lens_fitting','fa-circle-dot','Contact Lens Fitting'],
                    ['follow_up','fa-rotate-right','Follow-up Visit'],
                    ['prescription_check','fa-file-medical','Prescription Check'],
                    ['other','fa-ellipsis','Other / General'],
                ];
                foreach ($purposes as [$val,$icon,$label]):
                ?>
                <div class="purpose-card" data-val="<?= $val ?>" onclick="selectPurpose(this)">
                  <i class="fas <?= $icon ?>"></i>
                  <span><?= $label ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Step 4: Notes -->
            <div class="field-group">
              <label class="field-label"><i class="fas fa-sticky-note me-1"></i>Additional Notes <span style="color:var(--text-muted);font-weight:400;text-transform:none;font-size:.7rem;">(optional)</span></label>
              <textarea name="notes" class="field-control" rows="3" placeholder="Any specific concerns, symptoms, or information for the clinic..."></textarea>
            </div>

            <div class="info-box">
              <i class="fas fa-shield-alt"></i>
              <div>Your booking will be <strong>reviewed and confirmed</strong> by our staff. You'll see the status update in <em>My Appointments</em>. Please arrive <strong>10 minutes early</strong> on the day of your visit.</div>
            </div>

            <!-- Preview button → opens confirmation modal -->
            <button type="button" class="btn-book" id="bookBtn" disabled onclick="openConfirmModal()">
              <i class="fas fa-eye"></i> Review &amp; Confirm Booking
            </button>

            <!-- Hidden real submit -->
            <button type="submit" id="realSubmitBtn" style="display:none"></button>
          </form>
        </div>
      </div>

      <!-- Sidebar Info -->
      <div>
        <!-- Clinic Info -->
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:18px;padding:22px;margin-bottom:16px;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
            <div style="width:38px;height:38px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;"><i class="fas fa-clinic-medical"></i></div>
            <div>
              <div style="font-weight:700;font-size:.9rem;color:var(--text-primary)">Gueco Optical Clinic</div>
              <div style="font-size:.7rem;color:var(--text-muted)">Capas, Tarlac</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-light);">
            <div style="width:28px;height:28px;border-radius:8px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;color:var(--clr-primary);font-size:.7rem;flex-shrink:0;"><i class="fas fa-clock"></i></div>
            <div>
              <div style="font-size:.7rem;color:var(--text-muted);font-weight:600">Clinic Hours</div>
              <div style="font-size:.8rem;color:var(--text-secondary);font-weight:500">Mon–Fri: 9:00 AM – 5:00 PM</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-light);">
            <div style="width:28px;height:28px;border-radius:8px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;color:var(--clr-primary);font-size:.7rem;flex-shrink:0;"><i class="fas fa-calendar-times"></i></div>
            <div>
              <div style="font-size:.7rem;color:var(--text-muted);font-weight:600">Rest Days</div>
              <div style="font-size:.8rem;color:var(--text-secondary);font-weight:500">Closed Saturday & Sunday</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-light);">
            <div style="width:28px;height:28px;border-radius:8px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;color:var(--clr-primary);font-size:.7rem;flex-shrink:0;"><i class="fas fa-map-marker-alt"></i></div>
            <div>
              <div style="font-size:.7rem;color:var(--text-muted);font-weight:600">Location</div>
              <div style="font-size:.8rem;color:var(--text-secondary);font-weight:500">Capas, Tarlac, Philippines</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-light);">
            <div style="width:28px;height:28px;border-radius:8px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;color:var(--clr-primary);font-size:.7rem;flex-shrink:0;"><i class="fas fa-phone"></i></div>
            <div>
              <div style="font-size:.7rem;color:var(--text-muted);font-weight:600">Contact</div>
              <div style="font-size:.8rem;color:var(--text-secondary);font-weight:500">Available at the clinic</div>
            </div>
          </div>
        </div>

        <!-- Reminders -->
        <div style="background:rgba(5,150,105,.07);border:1px solid rgba(5,150,105,.2);border-radius:16px;padding:18px;">
          <div style="font-size:.78rem;font-weight:700;color:#34D399;margin-bottom:12px;"><i class="fas fa-lightbulb me-2"></i>Reminders</div>
          <?php $tips = ['Arrive 10 minutes before your scheduled time','Bring any previous prescriptions or eyeglass frames','Appointments are subject to staff confirmation','Sunday walk-ins are not available']; foreach ($tips as $tip): ?>
          <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;font-size:.75rem;color:var(--text-muted);">
            <i class="fas fa-check" style="color:#34D399;margin-top:3px;flex-shrink:0;"></i><?= $tip ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB: HISTORY -->
  <div class="tab-panel" id="panel-history">
    <div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
      <h6 style="font-weight:700;color:var(--text-primary);margin:0"><i class="fas fa-history me-2" style="color:var(--clr-primary)"></i>All Appointments (<?= count($myAppts) ?>)</h6>
    </div>

    <?php if (empty($myAppts)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-plus"></i>
      <p>No appointments yet.<br>Book your first appointment using the <strong>Book Appointment</strong> tab.</p>
    </div>
    <?php else: ?>

    <!-- Upcoming -->
    <?php if (!empty($upcoming)): ?>
    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--clr-primary);margin-bottom:10px;padding:0 4px;">
      <i class="fas fa-clock me-1"></i>Upcoming
    </div>
    <?php foreach ($upcoming as $a):
      $d = new DateTime($a['appointment_date']);
      $isPending = $a['status'] === 'pending';
    ?>
    <div class="appt-item upcoming">
      <div class="appt-date-box">
        <div class="appt-day"><?= $d->format('d') ?></div>
        <div class="appt-month"><?= $d->format('M') ?></div>
      </div>
      <div class="appt-divider"></div>
      <div class="appt-info">
        <div class="appt-time"><i class="fas fa-clock me-1" style="font-size:.7rem;opacity:.6"></i><?= formatTime($a['appointment_time']) ?> &nbsp;·&nbsp; <?= $d->format('l') ?></div>
        <div class="appt-purpose"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></div>
        <?php if ($a['notes']): ?><div class="appt-notes"><i class="fas fa-sticky-note me-1"></i><?= sanitize($a['notes']) ?></div><?php endif; ?>
        <?php if (!$isPending): ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:5px;font-size:.68rem;color:var(--clr-success);font-weight:600;">
          <i class="fas fa-lock"></i> Locked — Approved by clinic staff
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;flex-shrink:0;">
        <span class="status-badge status-<?= $a['status'] ?>"><?= str_replace('_',' ',ucfirst($a['status'])) ?></span>
        <div style="display:flex;gap:6px;">
          <?php if ($isPending): ?>
          <!-- Edit: only available while pending -->
          <button type="button" class="edit-btn js-edit-btn"
            data-id="<?= $a['id'] ?>"
            data-date="<?= $a['appointment_date'] ?>"
            data-time="<?= substr($a['appointment_time'],0,5) ?>"
            data-purpose="<?= $a['purpose'] ?>"
            data-notes="<?= htmlspecialchars((string)($a['notes'] ?? ''), ENT_QUOTES) ?>">
            <i class="fas fa-pen me-1"></i>Edit
          </button>
          <?php else: ?>
          <!-- Edit locked once confirmed -->
          <button type="button" class="edit-btn" disabled title="Cannot edit — appointment has been confirmed by staff">
            <i class="fas fa-lock me-1"></i>Locked
          </button>
          <?php endif; ?>
          <?php if (in_array($a['status'],['pending','confirmed'])): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="appt_id" value="<?= $a['id'] ?>">
            <button type="submit" class="cancel-btn" onclick="return confirm('Are you sure you want to cancel this appointment?')">
              <i class="fas fa-times me-1"></i>Cancel
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Past -->
    <?php if (!empty($past)): ?>
    <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin:18px 0 10px;padding:0 4px;">
      <i class="fas fa-history me-1"></i>Past / Cancelled
    </div>
    <?php foreach ($past as $a):
      $d = new DateTime($a['appointment_date']); ?>
    <div class="appt-item <?= in_array($a['status'],['cancelled','no_show'])?'cancelled':'' ?>">
      <div class="appt-date-box">
        <div class="appt-day" style="color:var(--text-muted)"><?= $d->format('d') ?></div>
        <div class="appt-month"><?= $d->format('M') ?></div>
      </div>
      <div class="appt-divider"></div>
      <div class="appt-info">
        <div class="appt-time"><?= formatTime($a['appointment_time']) ?> &nbsp;·&nbsp; <?= $d->format('D, M j Y') ?></div>
        <div class="appt-purpose"><?= ucwords(str_replace('_',' ',$a['purpose'])) ?></div>
      </div>
      <span class="status-badge status-<?= $a['status'] ?>"><?= str_replace('_',' ',ucfirst($a['status'])) ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
  </div>

</div><!-- /page-wrap -->

<!-- ============================================================
     BOOKING CONFIRMATION MODAL
     ============================================================ -->
<div id="confirmModal" style="position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;padding:20px;" class="modal-overlay">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(6px);" onclick="closeConfirmModal()"></div>
  <div style="position:relative;width:100%;max-width:480px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:22px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.4);animation:modalIn .25s ease;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1D4ED8,#7C3AED);padding:22px 24px;display:flex;align-items:center;gap:14px;">
      <div style="width:46px;height:46px;background:rgba(255,255,255,.2);border-radius:13px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;flex-shrink:0;"><i class="fas fa-calendar-check"></i></div>
      <div>
        <div style="font-weight:800;font-size:1.05rem;color:#fff;">Review Your Booking</div>
        <div style="font-size:.75rem;color:rgba(255,255,255,.75);">Please confirm all details before submitting</div>
      </div>
      <button onclick="closeConfirmModal()" style="margin-left:auto;background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:.85rem;"><i class="fas fa-times"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:24px;">
      <!-- Detail rows -->
      <?php
      $confirmRows = [
        ['fas fa-calendar','Date',        'confirm-date'],
        ['fas fa-clock',   'Time',        'confirm-time'],
        ['fas fa-tag',     'Purpose',     'confirm-purpose'],
        ['fas fa-sticky-note','Notes',    'confirm-notes'],
      ];
      foreach ($confirmRows as [$icon,$label,$id]):
      ?>
      <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--border-light);">
        <div style="width:34px;height:34px;border-radius:9px;background:rgba(37,99,235,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--clr-primary);font-size:.8rem;"><i class="<?= $icon ?>"></i></div>
        <div style="flex:1;">
          <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--text-muted);margin-bottom:2px;"><?= $label ?></div>
          <div id="<?= $id ?>" style="font-size:.88rem;font-weight:600;color:var(--text-primary);">—</div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Warning -->
      <div style="background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.2);border-radius:11px;padding:12px 14px;margin-top:18px;margin-bottom:18px;font-size:.77rem;color:var(--clr-warning);display:flex;gap:9px;align-items:flex-start;">
        <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;"></i>
        <span>Your appointment will be <strong>pending confirmation</strong> by our staff. You may still edit or cancel it while pending.</span>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;">
        <button onclick="closeConfirmModal()" style="flex:1;padding:13px;border-radius:12px;border:1.5px solid var(--border-color);background:none;font-family:'Poppins',sans-serif;font-size:.85rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all .2s;" onmouseover="this.style.borderColor='var(--clr-primary)';this.style.color='var(--clr-primary)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
          <i class="fas fa-arrow-left me-1"></i> Go Back
        </button>
        <button onclick="submitBooking()" style="flex:2;padding:13px;border-radius:12px;border:none;background:linear-gradient(135deg,#2563EB,#7C3AED);color:#fff;font-family:'Poppins',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;box-shadow:0 4px 18px rgba(37,99,235,.35);transition:all .2s;" id="finalSubmitBtn">
          <i class="fas fa-calendar-check me-1"></i> Yes, Book Appointment
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     EDIT APPOINTMENT MODAL
     ============================================================ -->
<div id="editModal" style="position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;padding:20px;" class="modal-overlay">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(6px);" onclick="closeEditModal()"></div>
  <div style="position:relative;width:100%;max-width:520px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:22px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.4);animation:modalIn .25s ease;max-height:90vh;overflow-y:auto;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#D97706,#DC2626);padding:20px 24px;display:flex;align-items:center;gap:14px;position:sticky;top:0;z-index:1;">
      <div style="width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;"><i class="fas fa-pen"></i></div>
      <div>
        <div style="font-weight:800;font-size:1rem;color:#fff;">Edit Appointment</div>
        <div style="font-size:.72rem;color:rgba(255,255,255,.8);">Only available while status is <strong>Pending</strong></div>
      </div>
      <button onclick="closeEditModal()" style="margin-left:auto;background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <!-- Body -->
    <div style="padding:24px;">
      <div style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);border-radius:12px;padding:16px 18px;margin-bottom:22px;color:#34D399;display:flex;gap:12px;align-items:flex-start;">
        <i class="fas fa-info-circle" style="font-size:1.1rem;margin-top:2px;flex-shrink:0;"></i>
        <div style="font-size:.9rem;line-height:1.6;">You can only edit this appointment while it is <strong>Pending</strong>. Once approved by clinic staff, edits will be <strong>locked</strong>.</div>
      </div>
      <form method="POST" id="editForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="appt_id" id="editApptId">
        <input type="hidden" name="appointment_time" id="editSelTime">

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-calendar me-1"></i>New Date</label>
          <input type="date" name="appointment_date" id="editDate" class="field-control"
                 min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
          <div style="font-size:.78rem;color:var(--text-muted);margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Weekdays only (Mon–Fri)</div>
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-clock me-1"></i>New Time Slot</label>
          <div id="editSlotGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(86px,1fr));gap:8px;">
            <div style="grid-column:1/-1;text-align:center;padding:20px;font-size:.8rem;color:var(--text-muted);"><i class="fas fa-calendar-day" style="display:block;font-size:1.5rem;opacity:.3;margin-bottom:8px;"></i>Select a date first</div>
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-clipboard-list me-1"></i>Purpose</label>
          <select name="purpose" id="editPurpose" class="field-control" required>
            <option value="">Choose purpose...</option>
            <option value="consultation">Eye Consultation / Check-up</option>
            <option value="eyeglass_claim">Eyeglass Claim / Pickup</option>
            <option value="contact_lens_fitting">Contact Lens Fitting</option>
            <option value="follow_up">Follow-up Visit</option>
            <option value="prescription_check">Prescription Check</option>
            <option value="other">Other / General</option>
          </select>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:block;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px;"><i class="fas fa-sticky-note me-1"></i>Notes <span style="font-weight:400;text-transform:none;font-size:.78rem;">(optional)</span></label>
          <textarea name="notes" id="editNotes" class="field-control" rows="3" placeholder="Any additional information..."></textarea>
        </div>

        <div style="display:flex;gap:10px;">
          <button type="button" onclick="closeEditModal()" style="flex:1;padding:12px;border-radius:12px;border:1.5px solid var(--border-color);background:none;font-family:'Poppins',sans-serif;font-size:.85rem;font-weight:600;color:var(--text-muted);cursor:pointer;">
            <i class="fas fa-times me-1"></i> Cancel
          </button>
          <button type="submit" id="editSubmitBtn" disabled style="flex:2;padding:12px;border-radius:12px;border:none;background:linear-gradient(135deg,#D97706,#DC2626);color:#fff;font-family:'Poppins',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;box-shadow:0 4px 14px rgba(217,119,6,.3);opacity:.5;transition:all .2s;">
            <i class="fas fa-save me-1"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- End Modals -->

<style>
@keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);} }
.modal-overlay { display:none; }
.modal-overlay.open { display:flex !important; }
.edit-btn {
  background:none; border:1px solid rgba(217,119,6,.3); color:#FCD34D;
  border-radius:8px; padding:5px 12px; font-family:'Poppins',sans-serif;
  font-size:.7rem; font-weight:600; cursor:pointer; transition:all .2s; flex-shrink:0;
  white-space:nowrap;
}
.edit-btn:hover:not(:disabled) { background:rgba(217,119,6,.1); border-color:rgba(217,119,6,.6); }
.edit-btn:disabled { opacity:.4; cursor:not-allowed; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Theme
const html = document.documentElement;
const themeBtn  = document.getElementById('themeToggle');
const themeIcon = document.getElementById('themeIcon');
const saved = localStorage.getItem('gueco-theme') || 'dark';
html.setAttribute('data-theme', saved);
themeIcon.className = saved === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
themeBtn.addEventListener('click', () => {
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('gueco-theme', next);
  themeIcon.className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
});

// Tab switch
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + id).classList.add('active');
  btn.classList.add('active');
}

// Quick date buttons
const quickBtns = document.querySelectorAll('.qdate-btn');
const dateInput = document.getElementById('apptDate');
quickBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    quickBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    dateInput.value = btn.dataset.date;
    dateInput.dispatchEvent(new Event('change'));
  });
});
dateInput.addEventListener('change', () => {
  const val = dateInput.value;
  const warning = document.getElementById('weekendWarning');

  // Check if selected date is a weekend
  if (val) {
    const dt = new Date(val + 'T00:00:00');
    const dow = dt.getDay(); // 0=Sun, 6=Sat
    if (dow === 0 || dow === 6) {
      showWeekendToast();
      dateInput.value = '';
      slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-calendar-day"></i>Select a weekday to see available time slots</div>';
      selTime.value = '';
      bookBtn.disabled = true;
      updateSteps();
      return;
    }
  }

  quickBtns.forEach(b => {
    b.classList.toggle('active', b.dataset.date === val);
  });
  loadSlots(val);
  updateSteps();
});

// Load slots
const allSlots = <?= json_encode($allSlots) ?>;
const slotGrid = document.getElementById('slotGrid');
const selTime  = document.getElementById('selectedTime');
const bookBtn  = document.getElementById('bookBtn');

async function loadSlots(date) {
  if (!date) return;
  slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-spinner fa-spin" style="font-size:1.2rem;opacity:.5;"></i><span>Loading available slots...</span></div>';
  selTime.value = '';
  bookBtn.disabled = true;

  try {
    const res = await fetch('dashboard.php?check_date=' + date);
    const taken = await res.json();
    slotGrid.innerHTML = '';

    const amSlots = allSlots.filter(s => parseInt(s) < 12);
    const pmSlots = allSlots.filter(s => parseInt(s) >= 12);

    const renderSection = (label, slots) => {
      if (!slots.length) return;
      const title = document.createElement('div');
      title.className = 'slot-section-title';
      title.innerHTML = `<i class="fas fa-${label==='Morning'?'sun':'cloud-moon'}"></i> ${label}`;
      slotGrid.appendChild(title);
      const grid = document.createElement('div');
      grid.className = 'slot-grid';
      slots.forEach(slot => {
        const isTaken = taken.includes(slot);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn';
        btn.disabled = isTaken;
        btn.title = isTaken ? 'Already booked' : '';
        const [h,m] = slot.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12  = h % 12 || 12;
        btn.innerHTML = `${h12}:${String(m).padStart(2,'0')}<span class="slot-period">${ampm}${isTaken?' · Full':''}</span>`;
        if (!isTaken) {
          btn.addEventListener('click', () => {
            grid.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            selTime.value = slot;
            checkReady();
            updateSteps();
          });
        }
        grid.appendChild(btn);
      });
      slotGrid.appendChild(grid);
    };

    renderSection('Morning', amSlots);
    renderSection('Afternoon', pmSlots);
  } catch(e) {
    slotGrid.innerHTML = '<div class="slot-placeholder"><i class="fas fa-exclamation-circle" style="color:var(--clr-danger)"></i>Could not load slots. Please try again.</div>';
  }
}

// Purpose cards
function selectPurpose(el) {
  document.querySelectorAll('.purpose-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('purposeInput').value = el.dataset.val;
  checkReady(); updateSteps();
}

function checkReady() {
  const d = dateInput.value;
  const t = selTime.value;
  const p = document.getElementById('purposeInput').value;
  bookBtn.disabled = !(d && t && p);
}

// Steps update
function updateSteps() {
  const d = dateInput.value;
  const t = selTime.value;
  const p = document.getElementById('purposeInput').value;
  const s1 = document.getElementById('step1');
  const s2 = document.getElementById('step2');
  const s3 = document.getElementById('step3');
  const s4 = document.getElementById('step4');

  s1.className = 'step ' + (d ? 'done' : 'active');
  if (d) s1.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.55rem"></i>';

  s2.className = 'step ' + (!d ? '' : t ? 'done' : 'active');
  if (t) s2.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.55rem"></i>';
  else if (d && !t) s2.querySelector('.step-num').textContent = '2';

  s3.className = 'step ' + (!t ? '' : p ? 'done' : 'active');
  if (p) s3.querySelector('.step-num').innerHTML = '<i class="fas fa-check" style="font-size:.55rem"></i>';
  else s3.querySelector('.step-num').textContent = '3';

  s4.className = 'step ' + (d && t && p ? 'active' : '');
  s4.querySelector('.step-num').textContent = '4';
}

// ── CONFIRMATION MODAL ──────────────────────────────────────
const purposeLabels = {
  consultation:         'Eye Consultation / Check-up',
  eyeglass_claim:       'Eyeglass Claim / Pickup',
  contact_lens_fitting: 'Contact Lens Fitting',
  follow_up:            'Follow-up Visit',
  prescription_check:   'Prescription Check',
  other:                'Other / General',
};

function fmtTime(slot) {
  const [h,m] = slot.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  return (h % 12 || 12) + ':' + String(m).padStart(2,'0') + ' ' + ampm;
}
function fmtDate(ymd) {
  const dt = new Date(ymd + 'T00:00:00');
  return dt.toLocaleDateString('en-PH',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
}

function openConfirmModal() {
  const d = dateInput.value;
  const t = selTime.value;
  const p = document.getElementById('purposeInput').value;
  const n = document.querySelector('textarea[name=notes]').value.trim();
  if (!d || !t || !p) return;

  document.getElementById('confirm-date').textContent    = fmtDate(d);
  document.getElementById('confirm-time').textContent    = fmtTime(t);
  document.getElementById('confirm-purpose').textContent = purposeLabels[p] || p;
  document.getElementById('confirm-notes').textContent   = n || 'None';
  document.getElementById('confirmModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeConfirmModal() {
  document.getElementById('confirmModal').classList.remove('open');
  document.body.style.overflow = '';
}
function submitBooking() {
  document.getElementById('finalSubmitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Submitting...';
  document.getElementById('finalSubmitBtn').disabled = true;
  document.getElementById('realSubmitBtn').click();
}

// ── EDIT MODAL ───────────────────────────────────────────────
function openEditModal(id, date, time, purpose, notes) {
  // Trim time to HH:MM in case DB returns HH:MM:SS
  const cleanTime = time ? time.substring(0, 5) : '';

  // Populate form fields
  document.getElementById('editApptId').value  = id;
  document.getElementById('editDate').value    = date;
  document.getElementById('editNotes').value   = notes || '';
  document.getElementById('editSelTime').value = '';

  // Pre-select purpose in dropdown
  const purposeSel = document.getElementById('editPurpose');
  purposeSel.value = purpose;

  // Disable Save until everything is confirmed loaded
  document.getElementById('editSubmitBtn').disabled = true;
  document.getElementById('editSubmitBtn').style.opacity = '.5';

  // Open modal first so user sees loading state
  document.getElementById('editModal').classList.add('open');
  document.body.style.overflow = 'hidden';

  // Load slots for the current date, pre-selecting current time
  loadEditSlots(date, cleanTime);

  // Re-load slots whenever date changes — block weekends
  document.getElementById('editDate').onchange = () => {
    const editDateVal = document.getElementById('editDate').value;
    if (editDateVal) {
      const dt = new Date(editDateVal + 'T00:00:00');
      const dow = dt.getDay(); // 0=Sun, 6=Sat
      if (dow === 0 || dow === 6) {
        showWeekendToast();
        document.getElementById('editDate').value = '';
        document.getElementById('editSelTime').value = '';
        document.getElementById('editSlotGrid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;font-size:.85rem;color:var(--text-muted);"><i class="fas fa-calendar-day" style="display:block;font-size:1.5rem;opacity:.3;margin-bottom:8px;"></i>Select a weekday to see available slots</div>';
        checkEditReady();
        return;
      }
    }
    document.getElementById('editSelTime').value = '';
    checkEditReady();
    loadEditSlots(editDateVal, null);
  };
}
function closeEditModal() {
  document.getElementById('editModal').classList.remove('open');
  document.body.style.overflow = '';
}

async function loadEditSlots(date, preselect) {
  const grid = document.getElementById('editSlotGrid');
  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;font-size:.8rem;color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="display:block;font-size:1.2rem;opacity:.5;margin-bottom:8px;"></i>Loading slots...</div>';
  if (!date) return;

  try {
    const res = await fetch('dashboard.php?check_date=' + date);
    const taken = await res.json();
    grid.innerHTML = '';

    // Separate AM / PM
    const amSlots = allSlots.filter(s => parseInt(s) < 12);
    const pmSlots = allSlots.filter(s => parseInt(s) >= 12);

    const renderSection = (label, slots) => {
      if (!slots.length) return;
      const title = document.createElement('div');
      title.className = 'slot-section-title';
      title.innerHTML = `<i class="fas fa-${label==='Morning'?'sun':'cloud-moon'}"></i> ${label}`;
      grid.appendChild(title);

      const sGrid = document.createElement('div');
      sGrid.className = 'slot-grid';

      slots.forEach(slot => {
        // A slot is taken if the server says so AND it's not the currently-booked slot we're editing
        const isTaken = taken.includes(slot) && slot !== preselect;
        const [h, m] = slot.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12  = h % 12 || 12;
        const isPreselect = slot === preselect;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn' + (isPreselect ? ' selected' : '');
        btn.disabled = isTaken;
        btn.title = isTaken ? 'Already booked' : (isPreselect ? 'Current time slot' : '');
        btn.innerHTML = `${h12}:${String(m).padStart(2,'0')}<span class="slot-period">${ampm}${isTaken?' · Full':''}</span>`;

        if (isPreselect) {
          // Pre-select this slot automatically
          document.getElementById('editSelTime').value = slot;
        }

        if (!isTaken) {
          btn.addEventListener('click', () => {
            grid.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            document.getElementById('editSelTime').value = slot;
            checkEditReady();
          });
        }

        sGrid.appendChild(btn);
      });
      grid.appendChild(sGrid);
    };

    renderSection('Morning', amSlots);
    renderSection('Afternoon', pmSlots);

    // Now that slots are rendered and editSelTime may be set, check if we can enable Save
    checkEditReady();

  } catch(e) {
    grid.innerHTML = '<div style="color:var(--clr-danger);font-size:.8rem;padding:12px;text-align:center;">Failed to load slots. Try again.</div>';
  }
}

function checkEditReady() {
  const d = document.getElementById('editDate').value;
  const t = document.getElementById('editSelTime').value;
  const p = document.getElementById('editPurpose').value;
  const ok = d && t && p;
  document.getElementById('editSubmitBtn').disabled = !ok;
  document.getElementById('editSubmitBtn').style.opacity = ok ? '1' : '.5';
}
document.getElementById('editPurpose').addEventListener('change', checkEditReady);

// Attach edit button listeners via event delegation
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.js-edit-btn');
  if (!btn) return;
  openEditModal(
    parseInt(btn.dataset.id),
    btn.dataset.date,
    btn.dataset.time,
    btn.dataset.purpose,
    btn.dataset.notes || ''
  );
});

// Auto open history tab if we came back from an edit
<?php if (($_SESSION['open_tab'] ?? '') === 'history'): unset($_SESSION['open_tab']); ?>
window.addEventListener('DOMContentLoaded', () => {
  switchTab('history', document.getElementById('tab-history'));
});
<?php endif; ?>

// ── WEEKEND POPUP TOAST ──────────────────────────────────────
function showWeekendToast() {
  // Remove existing toast if any
  const old = document.getElementById('weekendToast');
  if (old) old.remove();

  const toast = document.createElement('div');
  toast.id = 'weekendToast';
  toast.innerHTML = `
    <div style="display:flex;align-items:center;gap:14px;">
      <div style="width:48px;height:48px;background:rgba(220,38,38,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-calendar-times" style="font-size:1.2rem;color:#F87171;"></i>
      </div>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:.95rem;color:var(--text-primary);margin-bottom:3px;">Weekend Not Available</div>
        <div style="font-size:.85rem;color:var(--text-muted);line-height:1.4;">Our clinic is closed on <strong>Saturday & Sunday</strong>. Please select a weekday <strong>(Monday – Friday)</strong>.</div>
      </div>
      <button onclick="this.closest('#weekendToast').remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1rem;padding:4px;flex-shrink:0;"><i class="fas fa-times"></i></button>
    </div>
    <div style="margin-top:12px;height:3px;border-radius:3px;background:rgba(220,38,38,.15);overflow:hidden;">
      <div id="toastProgress" style="height:100%;background:linear-gradient(90deg,#DC2626,#F87171);border-radius:3px;width:100%;transition:width 4s linear;"></div>
    </div>
  `;
  Object.assign(toast.style, {
    position: 'fixed',
    top: '80px',
    left: '50%',
    transform: 'translateX(-50%) translateY(-20px)',
    zIndex: '99999',
    background: 'var(--bg-card)',
    border: '1px solid rgba(220,38,38,.3)',
    borderRadius: '18px',
    padding: '18px 22px',
    width: '90%',
    maxWidth: '480px',
    boxShadow: '0 20px 60px rgba(220,38,38,.2), 0 8px 24px rgba(0,0,0,.3)',
    opacity: '0',
    transition: 'opacity .3s ease, transform .3s ease',
    backdropFilter: 'blur(16px)',
  });
  document.body.appendChild(toast);

  // Animate in
  requestAnimationFrame(() => {
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    // Start progress bar countdown
    requestAnimationFrame(() => {
      document.getElementById('toastProgress').style.width = '0%';
    });
  });

  // Auto-dismiss after 4 seconds
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(-50%) translateY(-20px)';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

// User Dropdown Toggle
function toggleDropdown() {
  document.getElementById('userDropdown').classList.toggle('open');
}
// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  if (!e.target.closest('.user-dropdown')) {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) dropdown.classList.remove('open');
  }
});

// Dropdown logic kept in dashboard
</script>
</body>
</html>
