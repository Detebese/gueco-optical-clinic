<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Patient Lookup';
$breadcrumb = ['Saleslady', 'Patients'];
$db = getDB();

$search = sanitize($_GET['search'] ?? '');
$where = ["status='active'"]; $params = [];
if ($search) { $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }

$msg = ''; $msgType = '';
// Handle Walk-in Patient Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_walkin') {
    $fullName = sanitize(trim($_POST['full_name'] ?? ''));
    $fullName = preg_replace('/([a-z])([A-Z])/', '$1 $2', $fullName);
    $fullName = ucwords(strtolower($fullName));
    $email    = trim($_POST['email'] ?? '');
    $phone    = sanitize(trim($_POST['phone'] ?? ''));
    $address  = sanitize(trim($_POST['address'] ?? ''));
    $gender   = $_POST['gender'] ?? '';
    $birthdate = $_POST['birthdate'] ?? '';

    // If no email provided, generate a dummy one for the unique constraint
    if (empty($email)) {
        $email = 'walkin_' . time() . '_' . rand(100, 999) . '@gueco.local';
    }

    try {
        $check = $db->prepare("SELECT id FROM patients WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $msg = 'Email is already registered.';
            $msgType = 'danger';
        } else {
            // Generate a random password since they are a walk-in
            $randomPass = bin2hex(random_bytes(4));
            $stmt = $db->prepare("INSERT INTO patients (full_name, email, password, phone, address, gender, birthdate) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $fullName,
                $email,
                password_hash($randomPass, PASSWORD_DEFAULT),
                $phone,
                $address,
                $gender ?: null,
                $birthdate ?: null
            ]);
            $msg = 'Walk-in patient registered successfully.';
            $msgType = 'success';
        }
    } catch (Exception $e) {
        $msg = 'Error registering patient: ' . $e->getMessage();
        $msgType = 'danger';
    }
}
$patients = $db->prepare("SELECT * FROM patients WHERE " . implode(' AND ',$where) . " ORDER BY full_name LIMIT 30");
$patients->execute($params); $patients = $patients->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
  <h5><i class="fas fa-users me-2" style="color:var(--clr-primary)"></i>Patient Lookup</h5>
  <div style="display:flex; gap:10px; align-items:center;">
    <form method="GET" style="display:flex;gap:8px;margin:0;">
      <input type="text" name="search" class="form-control" placeholder="Name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:260px;">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
      <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
    </form>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWalkinModal">
      <i class="fas fa-user-plus me-1"></i> Register Walk-in
    </button>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ADD WALKIN MODAL -->
<div class="modal fade" id="addWalkinModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <input type="hidden" name="action" value="add_walkin">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2" style="color:var(--clr-primary)"></i>Register Walk-in Patient</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="full_name" class="form-control" required placeholder="e.g. Juan Dela Cruz">
        </div>
        <div class="mb-3">
          <label class="form-label">Email (Optional)</label>
          <input type="email" name="email" class="form-control" placeholder="Leave blank if unknown">
          <small class="text-muted">A dummy email will be generated if left blank.</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" placeholder="09XX-XXX-XXXX">
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Gender</label>
            <select name="gender" class="form-select">
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Birthdate</label>
            <input type="date" name="birthdate" class="form-control" max="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" placeholder="City, Province">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Patient</button>
      </div>
    </form>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Address</th><th>Gender</th><th>Registered</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;"><?= strtoupper(substr($p['full_name'],0,1)) ?></div>
              <div>
                <div style="font-weight:600;font-size:.88rem"><?= sanitize($p['full_name']) ?></div>
                <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['email']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($p['phone']??'—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($p['address']??'—') ?></td>
          <td style="font-size:.82rem"><?= $p['gender']?ucfirst($p['gender']):'—' ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDate($p['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
