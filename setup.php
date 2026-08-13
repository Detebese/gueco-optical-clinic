<?php
// ============================================================
// SETUP SCRIPT — Run this ONCE to initialize the database
// Access: http://localhost/gueco-optical/setup.php
// DELETE this file after running!
// ============================================================
define('BASE_URL', '');
require_once 'config/db.php';

$messages = [];
$errors   = [];

try {
    $db = getDB();

    // Read and execute the SQL schema
    $sql = file_get_contents(__DIR__ . '/database/gueco_optical.sql');
    // Split by semicolons but skip empty
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try {
                $db->exec($stmt);
            } catch (PDOException $e) {
                // Skip "already exists" errors
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'Duplicate entry') === false) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    $messages[] = '✅ Database tables created successfully.';

    // Seed default staff accounts
    $staff = [
        [
            'full_name' => 'Miss Gueco',
            'email'     => 'admin@gueco.com',
            'password'  => password_hash('admin123', PASSWORD_DEFAULT),
            'role'      => 'admin',
            'phone'     => '09171234567',
        ],
        [
            'full_name' => 'Dr. Gueco',
            'email'     => 'doctor@gueco.com',
            'password'  => password_hash('doctor123', PASSWORD_DEFAULT),
            'role'      => 'doctor',
            'phone'     => '09181234567',
        ],
        [
            'full_name' => 'Maria Santos',
            'email'     => 'saleslady@gueco.com',
            'password'  => password_hash('sales123', PASSWORD_DEFAULT),
            'role'      => 'saleslady',
            'phone'     => '09191234567',
        ],
    ];

    $insertUser = $db->prepare(
        "INSERT IGNORE INTO users (full_name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($staff as $s) {
        $insertUser->execute([$s['full_name'], $s['email'], $s['password'], $s['role'], $s['phone']]);
    }
    $messages[] = '✅ Default staff accounts created.';

    // Seed a sample patient
    $checkPatient = $db->prepare("SELECT id FROM patients WHERE email = ?");
    $checkPatient->execute(['patient@gueco.com']);
    if (!$checkPatient->fetch()) {
        $insertPatient = $db->prepare(
            "INSERT INTO patients (full_name, email, password, phone, address, birthdate, gender)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $insertPatient->execute([
            'Juan Dela Cruz',
            'patient@gueco.com',
            password_hash('patient123', PASSWORD_DEFAULT),
            '09201234567',
            'Capas, Tarlac',
            '1995-05-15',
            'male'
        ]);
        $messages[] = '✅ Sample patient account created.';
    }

} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Setup — Gueco Optical</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap');
    body { font-family: 'Poppins', sans-serif; background: #F1F5F9; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .setup-card { background: #fff; border-radius: 20px; padding: 40px; max-width: 560px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.1); }
    .setup-card h2 { font-size: 1.4rem; font-weight: 700; margin-bottom: 6px; }
    .setup-card p  { color: #64748B; font-size: .85rem; margin-bottom: 24px; }
    .msg { padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; font-size: .85rem; }
    .msg.success { background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; }
    .msg.error   { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
    .cred-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: .82rem; }
    .cred-table th { background: #F8FAFC; padding: 10px 14px; text-align: left; font-weight: 600; color: #475569; }
    .cred-table td { padding: 10px 14px; border-top: 1px solid #F1F5F9; }
    .badge-role { padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 600; }
    .badge-admin    { background: #DBEAFE; color: #1D4ED8; }
    .badge-doctor   { background: #CFFAFE; color: #0369A1; }
    .badge-saleslady{ background: #F3E8FF; color: #7C3AED; }
    .badge-patient  { background: #DCFCE7; color: #166534; }
    .btn-go { display: inline-block; margin-top: 24px; padding: 12px 28px; background: #2563EB; color: #fff; border-radius: 10px; text-decoration: none; font-weight: 600; font-size: .88rem; }
    .warn { background: #FEF3C7; color: #92400E; padding: 12px 16px; border-radius: 10px; font-size: .82rem; margin-top: 16px; border: 1px solid #FDE68A; }
  </style>
</head>
<body>
<div class="setup-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
    <div style="width:48px;height:48px;background:linear-gradient(135deg,#2563EB,#7C3AED);border-radius:14px;display:flex;align-items:center;justify-content:center;">
      <i class="fas fa-eye" style="color:#fff;font-size:1.2rem;"></i>
    </div>
    <div>
      <h2 style="margin:0">System Setup</h2>
      <p style="margin:0;font-size:.78rem;color:#64748B">Gueco Optical Clinic</p>
    </div>
  </div>

  <?php foreach ($messages as $msg): ?>
    <div class="msg success"><i class="fas fa-check-circle me-2"></i><?= $msg ?></div>
  <?php endforeach; ?>

  <?php foreach ($errors as $err): ?>
    <div class="msg error"><i class="fas fa-times-circle me-2"></i><?= htmlspecialchars($err) ?></div>
  <?php endforeach; ?>

  <?php if (empty($errors)): ?>
  <h6 style="font-size:.85rem;font-weight:700;margin-top:24px;margin-bottom:12px;color:#475569;">
    <i class="fas fa-key me-1"></i> Default Login Credentials
  </h6>

  <table class="cred-table">
    <thead>
      <tr>
        <th>Role</th>
        <th>Email</th>
        <th>Password</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><span class="badge-role badge-admin">Administrator</span></td>
        <td>admin@gueco.com</td>
        <td><code>admin123</code></td>
      </tr>
      <tr>
        <td><span class="badge-role badge-doctor">Optometrist</span></td>
        <td>doctor@gueco.com</td>
        <td><code>doctor123</code></td>
      </tr>
      <tr>
        <td><span class="badge-role badge-saleslady">Saleslady</span></td>
        <td>saleslady@gueco.com</td>
        <td><code>sales123</code></td>
      </tr>
      <tr>
        <td><span class="badge-role badge-patient">Patient</span></td>
        <td>patient@gueco.com</td>
        <td><code>patient123</code></td>
      </tr>
    </tbody>
  </table>

  <div class="warn">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Important:</strong> Delete or restrict access to <code>setup.php</code> after setup is complete for security.
  </div>

  <a href="login.php" class="btn-go"><i class="fas fa-arrow-right me-2"></i>Go to Login</a>
  <?php endif; ?>
</div>
</body>
</html>
